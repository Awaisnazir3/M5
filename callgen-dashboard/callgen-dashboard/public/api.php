<?php
session_start();
header("Content-Type: application/json");

// Allow access if session authenticated, or cookie present, or from local private LAN
$client_ip = $_SERVER["REMOTE_ADDR"] ?? "";
$is_local_ip = in_array($client_ip, ["127.0.0.1", "::1"], true) ||
               str_starts_with($client_ip, "192.168.") ||
               str_starts_with($client_ip, "10.") ||
               str_starts_with($client_ip, "172.16.") ||
               str_starts_with($client_ip, "172.17.") ||
               str_starts_with($client_ip, "172.18.") ||
               str_starts_with($client_ip, "172.19.") ||
               str_starts_with($client_ip, "172.20.") ||
               str_starts_with($client_ip, "172.21.") ||
               str_starts_with($client_ip, "172.22.") ||
               str_starts_with($client_ip, "172.23.") ||
               str_starts_with($client_ip, "172.24.") ||
               str_starts_with($client_ip, "172.25.") ||
               str_starts_with($client_ip, "172.26.") ||
               str_starts_with($client_ip, "172.27.") ||
               str_starts_with($client_ip, "172.28.") ||
               str_starts_with($client_ip, "172.29.") ||
               str_starts_with($client_ip, "172.30.") ||
               str_starts_with($client_ip, "172.31.");

if (empty($_SESSION["m5_auth"]) && empty($_COOKIE["m5_auth"]) && !$is_local_ip) {
    http_response_code(401);
    exit(json_encode(["ok" => false, "error" => "Not authenticated"]));
}

$a = $_GET["action"] ?? "";
$raw = file_get_contents("php://input");
$d = json_decode($raw, true) ?? [];

function ctl($args) {
    $cmd = "sudo /usr/local/sbin/callgen-control " . implode(" ", array_map("escapeshellarg", $args));
    exec($cmd . " 2>&1", $o, $rc);
    return [$rc, implode("\n", $o)];
}

function done($x, $code = 200) {
    http_response_code($code);
    exit(json_encode($x));
}

// 1. STATUS
if ($a === "status") {
    $trunk = $d["trunk"] ?? ($_GET["trunk"] ?? "all");
    [$r, $o] = ctl(["status", $trunk]);
    $j = json_decode($o, true);
    if ($r || !$j) {
        done(["ok" => false, "error" => $o ?: "Failed to read switch status"], 500);
    }
    done(["ok" => true] + $j);
}

// 2. SAVE SETTINGS
if ($a === "save") {
    $trunk = trim($d["trunk"] ?? "");
    $validTrunks = ["cuba", "senegal", "mali"];
    if (!in_array($trunk, $validTrunks, true)) {
        done(["ok" => false, "error" => "Invalid trunk target: " . $trunk], 400);
    }

    $name = trim($d["name"] ?? "");
    $ip = trim($d["target_ip"] ?? "");
    $pfx = trim($d["prefix"] ?? "");
    $cc = trim($d["country_code"] ?? "");
    $prefix = trim($d["prefix_cid"] ?? "");
    if ($prefix === "" && ($pfx !== "" || $cc !== "")) {
        $prefix = $pfx . $cc;
    }
    $f = "/etc/callgen-{$trunk}.json";
    $oldCfg = [];
    if (file_exists($f)) {
        $oldCfg = json_decode(file_get_contents($f), true) ?? [];
    }

    $m = (int)($d["monthly_limit"] ?? 0);
    $n = (int)($d["max_channels"] ?? 0);
    $t = (int)($d["call_duration"] ?? 0);
    if ($t < 1) {
        $t = (int)($oldCfg["call_duration"] ?? 600);
    }
    $ctx = trim($d["context"] ?? ($oldCfg["context"] ?? "testcall"));

    if (!preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $ip) || $m < 1 || $n < 1 || $n > 2000 || $t < 1 || $t > 86400) {
        done(["ok" => false, "error" => "Invalid settings parameters: Target IP={$ip}, Limit={$m}, Max={$n}, Dur={$t}"], 400);
    }

    $cfgData = [
        "id" => $trunk,
        "name" => $name ?: ucfirst($trunk),
        "target_ip" => $ip,
        "prefix" => $pfx,
        "country_code" => $cc,
        "prefix_cid" => $prefix,
        "monthly_limit" => $m,
        "max_channels" => $n,
        "call_duration" => $t,
        "context" => $ctx
    ];
    if (!empty($oldCfg["source_ip"])) {
        $cfgData["source_ip"] = $oldCfg["source_ip"];
    }

    $saved = @file_put_contents($f, json_encode($cfgData, JSON_PRETTY_PRINT) . "\n");
    if ($saved === false) {
        [$sr, $so] = ctl(["save", $trunk, json_encode($cfgData)]);
        if ($sr) {
            done(["ok" => false, "error" => "Failed to write configuration: " . $so], 500);
        }
    }

    [$r, $o] = ctl(["reload", $trunk]);
    done(["ok" => true, "message" => "Saved settings for " . ($name ?: ucfirst($trunk)) . ". Effective within 5s."]);
}

// 3. SAVE INBOUND GATEWAY SETTINGS
if ($a === "save-inbound") {
    $source_ips = $d["source_ips"] ?? [];
    if (!is_array($source_ips) || empty($source_ips)) {
        $raw_sip = trim($d["source_ip"] ?? "192.168.1.13");
        $source_ips = [
            [
                "id" => "src-1",
                "ip" => $raw_sip ?: "192.168.1.13",
                "label" => "Default Inbound Gateway",
                "prefix" => "2237, 4444, 53, 619334, 221",
                "country_code" => "223, 53, 221",
                "context" => "auto"
            ]
        ];
    }

    $clean_ips = [];
    $ip_list_for_match = [];
    foreach ($source_ips as $idx => $s) {
        $ip = trim($s["ip"] ?? "");
        if ($ip !== "" && preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $ip)) {
            $clean_ips[] = [
                "id" => trim($s["id"] ?? ("src-" . ($idx + 1))),
                "ip" => $ip,
                "label" => trim($s["label"] ?? ("Gateway " . ($idx + 1))),
                "prefix" => trim($s["prefix"] ?? ""),
                "country_code" => trim($s["country_code"] ?? ""),
                "context" => trim($s["context"] ?? "auto")
            ];
            $ip_list_for_match[] = $ip;
        }
    }

    if (empty($clean_ips)) {
        done(["ok" => false, "error" => "At least one valid Source IP address is required"], 400);
    }

    $dur1 = isset($d["testcall_duration"]) ? (int)$d["testcall_duration"] : 600;
    $dur2 = isset($d["testcall2_duration"]) ? (int)$d["testcall2_duration"] : 550;
    $pfx2 = isset($d["testcall2_prefixes"]) ? trim((string)$d["testcall2_prefixes"]) : "2237";
    $pfx1 = isset($d["testcall_prefixes"]) ? trim((string)$d["testcall_prefixes"]) : "4444, 53, 619334, 221";

    if ($dur1 < 1 || $dur1 > 86400 || $dur2 < 1 || $dur2 > 86400) {
        done(["ok" => false, "error" => "Invalid context duration limits (1-86400 seconds)"], 400);
    }

    $inboundCfg = [
        "source_ips" => $clean_ips,
        "source_ip" => implode(", ", array_unique($ip_list_for_match)),
        "testcall_duration" => $dur1,
        "testcall2_duration" => $dur2,
        "testcall2_prefixes" => $pfx2,
        "testcall_prefixes" => $pfx1
    ];

    $f = "/etc/callgen-inbound.json";
    $saved = @file_put_contents($f, json_encode($inboundCfg, JSON_PRETTY_PRINT) . "\n");
    if ($saved === false) {
        [$sr, $so] = ctl(["save-inbound", json_encode($inboundCfg)]);
        if ($sr) {
            done(["ok" => false, "error" => "Failed to write inbound configuration: " . $so], 500);
        }
    } else {
        @chmod($f, 0664);
        ctl(["reload"]);
    }

    done(["ok" => true, "message" => "Saved " . count($clean_ips) . " allowed Source IP(s) and updated Asterisk dialplan."]);
}

// 4. START / STOP
if (in_array($a, ["start", "stop"], true)) {
    $trunk = trim($d["trunk"] ?? "all");
    [$r, $o] = ctl([$a, $trunk]);
    done(["ok" => !$r, "message" => $o], $r ? 500 : 200);
}

done(["ok" => false, "error" => "Unknown action"], 400);
