<?php
session_start();
header("Content-Type: application/json");

if (empty($_SESSION["m5_auth"])) {
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
        done(["ok" => false, "error" => "Invalid trunk target"], 400);
    }

    $name = trim($d["name"] ?? "");
    $ip = trim($d["target_ip"] ?? "");
    $prefix = trim($d["prefix_cid"] ?? "");
    $m = (int)($d["monthly_limit"] ?? 0);
    $n = (int)($d["max_channels"] ?? 0);
    $t = (int)($d["call_duration"] ?? 0);
    $ctx = trim($d["context"] ?? "testcall");

    if (!preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $ip) || $m < 1 || $n < 1 || $n > 2000 || $t < 1 || $t > 86400) {
        done(["ok" => false, "error" => "Invalid settings parameters"], 400);
    }

    $f = "/etc/callgen-{$trunk}.json";
    $cfgData = [
        "id" => $trunk,
        "name" => $name ?: ucfirst($trunk),
        "target_ip" => $ip,
        "prefix_cid" => $prefix,
        "monthly_limit" => $m,
        "max_channels" => $n,
        "call_duration" => $t,
        "context" => $ctx
    ];

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

// 3. START / STOP
if (in_array($a, ["start", "stop"], true)) {
    $trunk = trim($d["trunk"] ?? "all");
    [$r, $o] = ctl([$a, $trunk]);
    done(["ok" => !$r, "message" => $o], $r ? 500 : 200);
}

done(["ok" => false, "error" => "Unknown action"], 400);
