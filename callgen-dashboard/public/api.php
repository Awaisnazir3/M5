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
if (empty($d) && !empty($_POST)) {
    $d = $_POST;
}

function ctl($args) {
    $candidates = [
        "/usr/local/sbin/callgen-control",
        "/usr/local/bin/callgen-control",
        "/usr/bin/callgen-control",
        dirname(__DIR__) . "/bin/callgen-control",
        dirname(__DIR__, 2) . "/callgen-dashboard/bin/callgen-control"
    ];
    $bin = null;
    foreach ($candidates as $c) {
        if (file_exists($c)) {
            $bin = $c;
            break;
        }
    }
    if (!$bin) return [1, "callgen-control utility not found"];

    $argStr = implode(" ", array_map("escapeshellarg", $args));
    exec("sudo " . escapeshellarg($bin) . " " . $argStr . " 2>&1", $o, $rc);
    if ($rc !== 0) {
        exec(escapeshellarg($bin) . " " . $argStr . " 2>&1", $o2, $rc2);
        if ($rc2 === 0) return [0, implode("\n", $o2)];
    }
    return [$rc, implode("\n", $o)];
}

function done($x, $code = 200) {
    http_response_code($code);
    exit(json_encode($x));
}

function get_config_path() {
    $candidates = [
        dirname(__DIR__, 2) . "/callgen-config.json",
        dirname(__DIR__) . "/callgen-config.json",
        __DIR__ . "/callgen-config.json",
        "/var/lib/callgen/callgen-config.json",
        "/etc/callgen-config.json"
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) return $c;
    }
    return dirname(__DIR__) . "/callgen-config.json";
}

function load_config() {
    $p = get_config_path();
    if (file_exists($p)) {
        $data = json_decode(file_get_contents($p), true);
        if (is_array($data)) return $data;
    }
    return [];
}

function save_config($cfg) {
    $p = get_config_path();
    $dir = dirname($p);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($p, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $alt = dirname(__DIR__) . "/callgen-config.json";
    if ($alt !== $p && (file_exists($alt) || is_writable(dirname(__DIR__)))) {
        @file_put_contents($alt, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

function get_db() {
    $candidates = [
        "/var/lib/callgen/callgen.db",
        dirname(__DIR__) . "/callgen.db",
        __DIR__ . "/callgen.db"
    ];

    $pdo = null;
    $db_path = null;

    foreach ($candidates as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (file_exists($path) || is_writable($dir)) {
            try {
                $pdo = new PDO("sqlite:" . $path);
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec("PRAGMA journal_mode = WAL;");
                $pdo->exec("PRAGMA busy_timeout = 5000;");
                $db_path = $path;
                break;
            } catch (Exception $e) {
                $pdo = null;
            }
        }
    }

    if (!$pdo) return null;

    // Auto-create and auto-migrate all database tables
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS trunks (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                target_ip TEXT NOT NULL,
                sip_port INTEGER DEFAULT 5060,
                prefix TEXT DEFAULT '',
                country_code TEXT DEFAULT '',
                prefix_cid TEXT DEFAULT '',
                monthly_limit INTEGER DEFAULT 100000,
                max_channels INTEGER DEFAULT 100,
                call_duration INTEGER DEFAULT 600,
                context TEXT DEFAULT 'testcall',
                used_minutes REAL DEFAULT 0.0,
                limit_reached INTEGER DEFAULT 0,
                quota_reset_at DATETIME DEFAULT NULL,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS gateways (
                id TEXT PRIMARY KEY,
                ip TEXT NOT NULL,
                port INTEGER DEFAULT 5060,
                label TEXT DEFAULT '',
                prefix TEXT DEFAULT '',
                country_code TEXT DEFAULT '',
                context TEXT DEFAULT 'auto',
                is_active INTEGER DEFAULT 1,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS inbound_config (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS customers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                max_channels INTEGER NOT NULL DEFAULT 100,
                allocated_channels INTEGER NOT NULL DEFAULT 100,
                country_code TEXT DEFAULT '',
                status TEXT NOT NULL DEFAULT 'active',
                description TEXT DEFAULT '',
                blocked_by TEXT DEFAULT '',
                blocked_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS customer_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id TEXT NOT NULL,
                ip TEXT NOT NULL,
                ip_address TEXT DEFAULT '',
                port INTEGER NOT NULL DEFAULT 5060,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS customer_prefixes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id TEXT NOT NULL,
                prefix TEXT NOT NULL,
                country_code TEXT DEFAULT '',
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS vendors (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                max_channels INTEGER NOT NULL DEFAULT 200,
                country_code TEXT DEFAULT '',
                caller_id TEXT DEFAULT '',
                caller_id_format TEXT DEFAULT '',
                status TEXT NOT NULL DEFAULT 'active',
                description TEXT DEFAULT '',
                blocked_by TEXT DEFAULT '',
                blocked_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE TABLE IF NOT EXISTS vendor_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id TEXT NOT NULL,
                ip TEXT NOT NULL,
                ip_address TEXT DEFAULT '',
                port INTEGER NOT NULL DEFAULT 5060,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS vendor_prefixes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id TEXT NOT NULL,
                prefix TEXT NOT NULL,
                country_code TEXT DEFAULT '',
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS customer_vendor_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id TEXT NOT NULL,
                vendor_id TEXT NOT NULL,
                customer_prefix TEXT NOT NULL DEFAULT '',
                vendor_prefix TEXT NOT NULL DEFAULT '',
                country_code TEXT NOT NULL DEFAULT '',
                priority INTEGER NOT NULL DEFAULT 1,
                max_channels INTEGER NOT NULL DEFAULT 60,
                status TEXT NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            );
        ");

        // Auto-migrate newly required columns if not yet present in existing tables
        $cols = $pdo->query("PRAGMA table_info(trunks)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("sip_port", $cols, true)) $pdo->exec("ALTER TABLE trunks ADD COLUMN sip_port INTEGER DEFAULT 5060");
        if (!in_array("quota_reset_at", $cols, true)) $pdo->exec("ALTER TABLE trunks ADD COLUMN quota_reset_at DATETIME DEFAULT NULL");
        if (!in_array("used_minutes", $cols, true)) $pdo->exec("ALTER TABLE trunks ADD COLUMN used_minutes REAL DEFAULT 0.0");
        if (!in_array("limit_reached", $cols, true)) $pdo->exec("ALTER TABLE trunks ADD COLUMN limit_reached INTEGER DEFAULT 0");

        $gcols = $pdo->query("PRAGMA table_info(gateways)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("port", $gcols, true)) $pdo->exec("ALTER TABLE gateways ADD COLUMN port INTEGER DEFAULT 5060");

        $cust_cols = $pdo->query("PRAGMA table_info(customers)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("max_channels", $cust_cols, true)) $pdo->exec("ALTER TABLE customers ADD COLUMN max_channels INTEGER DEFAULT 100");
        if (!in_array("allocated_channels", $cust_cols, true)) $pdo->exec("ALTER TABLE customers ADD COLUMN allocated_channels INTEGER DEFAULT 100");
        if (!in_array("country_code", $cust_cols, true)) $pdo->exec("ALTER TABLE customers ADD COLUMN country_code TEXT DEFAULT ''");

        $cip_cols = $pdo->query("PRAGMA table_info(customer_ips)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("ip", $cip_cols, true)) $pdo->exec("ALTER TABLE customer_ips ADD COLUMN ip TEXT DEFAULT ''");
        if (!in_array("ip_address", $cip_cols, true)) $pdo->exec("ALTER TABLE customer_ips ADD COLUMN ip_address TEXT DEFAULT ''");

        $vend_cols = $pdo->query("PRAGMA table_info(vendors)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("max_channels", $vend_cols, true)) $pdo->exec("ALTER TABLE vendors ADD COLUMN max_channels INTEGER DEFAULT 200");
        if (!in_array("country_code", $vend_cols, true)) $pdo->exec("ALTER TABLE vendors ADD COLUMN country_code TEXT DEFAULT ''");
        if (!in_array("caller_id", $vend_cols, true)) $pdo->exec("ALTER TABLE vendors ADD COLUMN caller_id TEXT DEFAULT ''");
        if (!in_array("caller_id_format", $vend_cols, true)) $pdo->exec("ALTER TABLE vendors ADD COLUMN caller_id_format TEXT DEFAULT ''");

        $vip_cols = $pdo->query("PRAGMA table_info(vendor_ips)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("ip", $vip_cols, true)) $pdo->exec("ALTER TABLE vendor_ips ADD COLUMN ip TEXT DEFAULT ''");
        if (!in_array("ip_address", $vip_cols, true)) $pdo->exec("ALTER TABLE vendor_ips ADD COLUMN ip_address TEXT DEFAULT ''");

        $map_cols = $pdo->query("PRAGMA table_info(customer_vendor_mappings)")->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array("max_channels", $map_cols, true)) $pdo->exec("ALTER TABLE customer_vendor_mappings ADD COLUMN max_channels INTEGER DEFAULT 60");
        if (!in_array("country_code", $map_cols, true)) $pdo->exec("ALTER TABLE customer_vendor_mappings ADD COLUMN country_code TEXT DEFAULT ''");
        if (!in_array("status", $map_cols, true)) $pdo->exec("ALTER TABLE customer_vendor_mappings ADD COLUMN status TEXT DEFAULT 'active'");

        // AUTO-SEED FROM callgen-config.json IF DATABASE HAS NO CUSTOMERS OR VENDORS
        $cust_count = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        if ($cust_count === 0) {
            $cfg = load_config();
            $seed_custs = $cfg["_customers"] ?? [];
            foreach ($seed_custs as $c) {
                $cid = $c["id"] ?? "";
                if (!$cid) continue;
                $cname = $c["name"] ?? $cid;
                $cst = $c["status"] ?? "active";
                $cch = (int)($c["max_channels"] ?? 100);
                $ccc = $c["country_code"] ?? "";
                $cdesc = $c["description"] ?? "";
                $pdo->prepare("INSERT OR REPLACE INTO customers (id, name, status, max_channels, allocated_channels, country_code, description) VALUES (:id, :n, :st, :ch, :ach, :cc, :d)")
                    ->execute([":id" => $cid, ":n" => $cname, ":st" => $cst, ":ch" => $cch, ":ach" => $cch, ":cc" => $ccc, ":d" => $cdesc]);
                foreach ($c["ips"] ?? [] as $ipObj) {
                    $ip = is_array($ipObj) ? ($ipObj["ip"] ?? "") : (string)$ipObj;
                    $port = is_array($ipObj) ? ($ipObj["port"] ?? 5060) : 5060;
                    if ($ip) $pdo->prepare("INSERT INTO customer_ips (customer_id, ip, ip_address, port) VALUES (:cid, :ip, :ip2, :port)")
                        ->execute([":cid" => $cid, ":ip" => $ip, ":ip2" => $ip, ":port" => $port]);
                }
                foreach ($c["prefixes"] ?? [] as $pfx) {
                    $pfx = (string)$pfx;
                    if ($pfx) $pdo->prepare("INSERT INTO customer_prefixes (customer_id, prefix, country_code) VALUES (:cid, :pfx, :cc)")
                        ->execute([":cid" => $cid, ":pfx" => $pfx, ":cc" => $ccc]);
                }
            }

            $seed_vends = $cfg["_vendors"] ?? [];
            foreach ($seed_vends as $v) {
                $vid = $v["id"] ?? "";
                if (!$vid) continue;
                $vname = $v["name"] ?? $vid;
                $vst = $v["status"] ?? "active";
                $vch = (int)($v["max_channels"] ?? 200);
                $vcc = $v["country_code"] ?? "";
                $vcli = $v["caller_id"] ?? "";
                $vdesc = $v["description"] ?? "";
                $pdo->prepare("INSERT OR REPLACE INTO vendors (id, name, status, max_channels, country_code, caller_id, caller_id_format, description) VALUES (:id, :n, :st, :ch, :cc, :cli, :cli2, :d)")
                    ->execute([":id" => $vid, ":n" => $vname, ":st" => $vst, ":ch" => $vch, ":cc" => $vcc, ":cli" => $vcli, ":cli2" => $vcli, ":d" => $vdesc]);
                foreach ($v["ips"] ?? [] as $ipObj) {
                    $ip = is_array($ipObj) ? ($ipObj["ip"] ?? "") : (string)$ipObj;
                    $port = is_array($ipObj) ? ($ipObj["port"] ?? 5060) : 5060;
                    if ($ip) $pdo->prepare("INSERT INTO vendor_ips (vendor_id, ip, ip_address, port) VALUES (:vid, :ip, :ip2, :port)")
                        ->execute([":vid" => $vid, ":ip" => $ip, ":ip2" => $ip, ":port" => $port]);
                }
                foreach ($v["prefixes"] ?? [] as $pfx) {
                    $pfx = (string)$pfx;
                    if ($pfx) $pdo->prepare("INSERT INTO vendor_prefixes (vendor_id, prefix, country_code) VALUES (:vid, :pfx, :cc)")
                        ->execute([":vid" => $vid, ":pfx" => $pfx, ":cc" => $vcc]);
                }
            }

            $seed_maps = $cfg["_mappings"] ?? [];
            foreach ($seed_maps as $m) {
                $pdo->prepare("INSERT INTO customer_vendor_mappings (id, customer_id, vendor_id, customer_prefix, vendor_prefix, country_code, priority, max_channels, status) VALUES (:id, :cid, :vid, :cp, :vp, :cc, :prio, :ch, :st)")
                    ->execute([
                        ":id" => $m["id"] ?? null,
                        ":cid" => $m["customer_id"],
                        ":vid" => $m["vendor_id"],
                        ":cp" => $m["customer_prefix"] ?? "",
                        ":vp" => $m["vendor_prefix"] ?? "",
                        ":cc" => $m["country_code"] ?? "",
                        ":prio" => $m["priority"] ?? 1,
                        ":ch" => $m["max_channels"] ?? 60,
                        ":st" => $m["status"] ?? "active"
                    ]);
            }
        }
    } catch (Exception $e) {}

    return $pdo;
}

// 1. STATUS
if ($a === "status") {
    $trunk = $d["trunk"] ?? ($_GET["trunk"] ?? "all");
    $j = null;

    // 1. Try ctl CLI first
    [$r, $o] = ctl(["status", $trunk]);
    if (!$r && $o) {
        $j = json_decode($o, true);
    }

    // 2. If ctl failed or returned non-JSON, gracefully load from SQLite & config (never return 500)
    if (!$j || empty($j["trunks"])) {
        $pdo = get_db();
        $dbTrunks = [];
        $gateways = [];
        $inbound_cfg = [];

        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT * FROM trunks");
                while ($row = $stmt->fetch()) {
                    $tid = $row["id"];
                    $port = (int)($row["sip_port"] ?? 5060);
                    $tip = trim($row["target_ip"] ?? "");
                    if (strpos($tip, ":") !== false) {
                        $parts = explode(":", $tip, 2);
                        $host = $parts[0];
                        if (!$port && !empty($parts[1])) $port = (int)$parts[1];
                    } else {
                        $host = $tip;
                    }
                    if (!$port) $port = 5060;
                    $dbTrunks[$tid] = [
                        "id" => $tid,
                        "name" => $row["name"] ?? ucfirst($tid),
                        "target_ip" => $host ? "{$host}:{$port}" : "",
                        "sip_port" => $port,
                        "prefix" => $row["prefix"] ?? "",
                        "country_code" => $row["country_code"] ?? "",
                        "prefix_cid" => $row["prefix_cid"] ?? "",
                        "monthly_limit" => (int)($row["monthly_limit"] ?? 100000),
                        "max_channels" => (int)($row["max_channels"] ?? 100),
                        "call_duration" => (int)($row["call_duration"] ?? 60),
                        "context" => $row["context"] ?? ($tid === "mali" ? "testcall2" : "testcall"),
                        "running" => false,
                        "limit_reached" => false,
                        "active_calls" => 0,
                        "used_minutes" => (float)($row["used_minutes"] ?? 0)
                    ];
                }

                $stmt = $pdo->query("SELECT * FROM gateways WHERE is_active = 1");
                $gateways = $stmt->fetchAll() ?: [];

                $stmt = $pdo->query("SELECT key, value FROM inbound_config");
                while ($row = $stmt->fetch()) {
                    $inbound_cfg[$row["key"]] = $row["value"];
                }
            } catch (Exception $e) {}
        }

        // Fallback to callgen-config.json if SQLite had no trunks
        if (empty($dbTrunks)) {
            $cfg = load_config();
            $trunksCfg = $cfg["trunks"] ?? [];
            if (empty($trunksCfg)) {
                $trunksCfg = [];
                foreach (["cuba", "mali", "senegal"] as $tid) {
                    if (!empty($cfg[$tid]) && is_array($cfg[$tid])) {
                        $trunksCfg[$tid] = $cfg[$tid];
                    }
                }
            }
            if (!empty($trunksCfg)) {
                foreach ($trunksCfg as $tid => $tr) {
                    $port = (int)($tr["sip_port"] ?? 5060);
                    $tip = trim($tr["target_ip"] ?? "");
                    if (strpos($tip, ":") !== false) {
                        $parts = explode(":", $tip, 2);
                        $host = $parts[0];
                        if (!$port && !empty($parts[1])) $port = (int)$parts[1];
                    } else {
                        $host = $tip;
                    }
                    if (!$port) $port = 5060;
                    $dbTrunks[$tid] = [
                        "id" => $tid,
                        "name" => $tr["name"] ?? ucfirst($tid),
                        "target_ip" => $host ? "{$host}:{$port}" : "",
                        "sip_port" => $port,
                        "prefix" => $tr["prefix"] ?? "",
                        "country_code" => $tr["country_code"] ?? "",
                        "prefix_cid" => $tr["prefix_cid"] ?? "",
                        "monthly_limit" => (int)($tr["monthly_limit"] ?? 100000),
                        "max_channels" => (int)($tr["max_channels"] ?? 100),
                        "call_duration" => (int)($tr["call_duration"] ?? 60),
                        "context" => $tr["context"] ?? ($tid === "mali" ? "testcall2" : "testcall"),
                        "running" => false,
                        "limit_reached" => false,
                        "active_calls" => 0,
                        "used_minutes" => (float)($tr["used_minutes"] ?? 0)
                    ];
                }
            }
            $inboundRaw = $cfg["_inbound"] ?? ($cfg["inbound"] ?? []);
            if (empty($gateways) && !empty($inboundRaw["source_ips"])) {
                $gateways = $inboundRaw["source_ips"];
            }
            if (empty($inbound_cfg) && !empty($inboundRaw)) {
                $inbound_cfg = [
                    "testcall_duration" => $inboundRaw["testcall_duration"] ?? 60,
                    "testcall2_duration" => $inboundRaw["testcall2_duration"] ?? 50,
                    "testcall_prefixes" => $inboundRaw["testcall_prefixes"] ?? "444453, 619334221",
                    "testcall2_prefixes" => $inboundRaw["testcall2_prefixes"] ?? "2237"
                ];
            }
        }

        // Fallback default trunks if still empty
        if (empty($dbTrunks)) {
            $dbTrunks = [
                "cuba" => [
                    "id" => "cuba", "name" => "Cuba", "target_ip" => "192.168.88.76:5060", "sip_port" => 5060,
                    "prefix" => "4444", "country_code" => "53", "prefix_cid" => "444453",
                    "monthly_limit" => 5000, "max_channels" => 600, "call_duration" => 60,
                    "context" => "testcall", "running" => false, "limit_reached" => false, "active_calls" => 0, "used_minutes" => 0
                ],
                "mali" => [
                    "id" => "mali", "name" => "Mali", "target_ip" => "192.168.88.76:5060", "sip_port" => 5060,
                    "prefix" => "", "country_code" => "2237", "prefix_cid" => "2237",
                    "monthly_limit" => 1000000, "max_channels" => 2000, "call_duration" => 50,
                    "context" => "testcall2", "running" => false, "limit_reached" => false, "active_calls" => 0, "used_minutes" => 0
                ],
                "senegal" => [
                    "id" => "senegal", "name" => "Senegal", "target_ip" => "178.22.15.201:5060", "sip_port" => 5060,
                    "prefix" => "619334", "country_code" => "221", "prefix_cid" => "619334221",
                    "monthly_limit" => 150000, "max_channels" => 600, "call_duration" => 60,
                    "context" => "testcall", "running" => false, "limit_reached" => false, "active_calls" => 0, "used_minutes" => 0
                ]
            ];
        }

        // Check active channels and systemd service status
        $ast_bin = trim(shell_exec("which asterisk 2>/dev/null") ?: "/usr/sbin/asterisk");
        $chan_output = file_exists($ast_bin) ? (@shell_exec("sudo {$ast_bin} -rx \"core show channels\" 2>/dev/null") ?: @shell_exec("{$ast_bin} -rx \"core show channels\" 2>/dev/null")) : "";

        foreach ($dbTrunks as $tid => &$trData) {
            $ip_clean = explode(":", $trData["target_ip"])[0] ?? "";
            $act = 0;
            if ($chan_output) {
                foreach (explode("\n", $chan_output) as $line) {
                    if (($ip_clean && strpos($line, $ip_clean) !== false) || strpos($line, "{$tid}-trunk") !== false) {
                        if (strpos($line, "SIP/") !== false || strpos($line, "PJSIP/") !== false) $act++;
                    }
                }
            }
            $trData["active_calls"] = $act;
            $srv_out = @shell_exec("systemctl is-active asterisk-callgen-{$tid}.service 2>/dev/null");
            $trData["running"] = (trim((string)$srv_out) === "active");
        }
        unset($trData);

        // Normalize gateways
        $clean_gws = [];
        foreach ($gateways as $idx => $gw) {
            $gw_ip = trim($gw["ip"] ?? "");
            if (!$gw_ip) continue;
            $gw_port = (int)($gw["port"] ?? 5060);
            if (strpos($gw_ip, ":") !== false) {
                $p = explode(":", $gw_ip, 2);
                $gw_ip = $p[0];
                if (!$gw_port && !empty($p[1])) $gw_port = (int)$p[1];
            }
            if (!$gw_port) $gw_port = 5060;
            $clean_gws[] = [
                "id" => $gw["id"] ?? "src-" . ($idx + 1),
                "ip" => $gw_ip,
                "port" => $gw_port,
                "label" => $gw["label"] ?? "Gateway " . ($idx + 1),
                "prefix" => $gw["prefix"] ?? "",
                "country_code" => $gw["country_code"] ?? "",
                "context" => $gw["context"] ?? "auto",
                "is_active" => (int)($gw["is_active"] ?? 1)
            ];
        }

        $j = [
            "trunks" => $dbTrunks,
            "inbound" => [
                "source_ips" => $clean_gws,
                "source_ip" => implode(", ", array_filter(array_column($clean_gws, "ip"))),
                "testcall_duration" => (int)($inbound_cfg["testcall_duration"] ?? 60),
                "testcall2_duration" => (int)($inbound_cfg["testcall2_duration"] ?? 50),
                "testcall_prefixes" => $inbound_cfg["testcall_prefixes"] ?? "444453, 619334221",
                "testcall2_prefixes" => $inbound_cfg["testcall2_prefixes"] ?? "2237",
                "active_channels" => 0,
                "active_calls" => [],
                "recent_calls" => []
            ]
        ];
    }

    // Resilience layer: Ensure sip_port and target_ip format are fully consistent
    $pdo = get_db();
    if ($pdo && !empty($j["trunks"]) && is_array($j["trunks"])) {
        try {
            $stmt = $pdo->query("SELECT id, target_ip, sip_port, monthly_limit, quota_reset_at FROM trunks");
            $dbTrunks = [];
            while ($row = $stmt->fetch()) {
                $dbTrunks[$row["id"]] = $row;
            }
            foreach ($j["trunks"] as $tid => &$trData) {
                if (isset($dbTrunks[$tid])) {
                    $dbt = $dbTrunks[$tid];
                    $port = (int)($trData["sip_port"] ?? ($dbt["sip_port"] ?? 5060));
                    $tip = trim($trData["target_ip"] ?? ($dbt["target_ip"] ?? ""));
                    if (strpos($tip, ":") !== false) {
                        $parts = explode(":", $tip, 2);
                        $host = $parts[0];
                        if (empty($trData["sip_port"]) && !empty($parts[1])) $port = (int)$parts[1];
                    } else {
                        $host = $tip;
                    }
                    if (!$port) $port = 5060;
                    $trData["sip_port"] = $port;
                    $trData["target_ip"] = $host !== "" ? "{$host}:{$port}" : "";
                }
            }
            unset($trData);
        } catch (Exception $e) {}
    }

    // Resilience layer: Ensure inbound source_ips have port
    if (!empty($j["inbound"]["source_ips"]) && is_array($j["inbound"]["source_ips"])) {
        foreach ($j["inbound"]["source_ips"] as &$gw) {
            $port = (int)($gw["port"] ?? 0);
            $ip = trim($gw["ip"] ?? "");
            if (strpos($ip, ":") !== false) {
                $parts = explode(":", $ip, 2);
                $gw["ip"] = $parts[0];
                if (!$port && !empty($parts[1])) $port = (int)$parts[1];
            }
            if (!$port) $port = 5060;
            $gw["port"] = $port;
        }
        unset($gw);
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
    $port = (int)($d["sip_port"] ?? 0);
    if ($ip !== "" && strpos($ip, ":") !== false) {
        $parts = explode(":", $ip, 2);
        $ip = $parts[0];
        if (!$port) $port = (int)$parts[1];
    }
    if (!$port || $port < 1 || $port > 65535) $port = 5060;
    $fullTarget = $ip !== "" ? "{$ip}:{$port}" : "";

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
        "target_ip" => $fullTarget,
        "sip_port" => $port,
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

    // 1. Direct SQLite database update
    $pdo = get_db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                UPDATE trunks SET
                    name = :name,
                    target_ip = :target_ip,
                    sip_port = :sip_port,
                    prefix = :prefix,
                    country_code = :country_code,
                    prefix_cid = :prefix_cid,
                    monthly_limit = :monthly_limit,
                    max_channels = :max_channels,
                    call_duration = :call_duration,
                    context = :context,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([
                ":name" => $name ?: ucfirst($trunk),
                ":target_ip" => $fullTarget,
                ":sip_port" => $port,
                ":prefix" => $pfx,
                ":country_code" => $cc,
                ":prefix_cid" => $prefix,
                ":monthly_limit" => $m,
                ":max_channels" => $n,
                ":call_duration" => $t,
                ":context" => $ctx,
                ":id" => $trunk
            ]);
        } catch (Exception $e) {
            // Log notice, continue to file write
        }
    }

    $saved = @file_put_contents($f, json_encode($cfgData, JSON_PRETTY_PRINT) . "\n");
    if ($saved === false) {
        [$sr, $so] = ctl(["save", $trunk, json_encode($cfgData)]);
    }

    // Always update callgen-config.json as persistence fallback
    $cfg = load_config();
    if (!isset($cfg["trunks"])) $cfg["trunks"] = [];
    $cfg["trunks"][$trunk] = $cfgData;
    save_config($cfg);

    [$r, $o] = ctl(["reload", $trunk]);
    done(["ok" => true, "message" => "Saved settings for " . ($name ?: ucfirst($trunk)) . " to database. Effective within 5s."]);
}

// 3. SAVE INBOUND GATEWAY SETTINGS
if ($a === "save-inbound") {
    $pdo = get_db();
    $source_ips = $d["source_ips"] ?? [];
    if (!is_array($source_ips) || empty($source_ips)) {
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT * FROM gateways WHERE is_active=1");
                $source_ips = $stmt->fetchAll();
            } catch (Exception $e) {}
        }
        if (empty($source_ips)) {
            $raw_sip = trim($d["source_ip"] ?? "192.168.1.13");
            $source_ips = [
                [
                    "id" => "src-1",
                    "ip" => $raw_sip ?: "192.168.1.13",
                    "label" => "Default Inbound Gateway",
                    "prefix" => "444453, 619334221",
                    "country_code" => "",
                    "context" => "auto"
                ]
            ];
        }
    }

    $clean_ips = [];
    $ip_list_for_match = [];
    $seen_ips = [];
    foreach ($source_ips as $idx => $s) {
        $ip = trim($s["ip"] ?? "");
        $port = (int)($s["port"] ?? 0);
        if ($ip !== "" && strpos($ip, ":") !== false) {
            $parts = explode(":", $ip, 2);
            $ip = $parts[0];
            if (!$port) $port = (int)$parts[1];
        }
        if (!$port || $port < 1 || $port > 65535) $port = 5060;

        if ($ip !== "" && preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $ip)) {
            if (in_array("{$ip}:{$port}", $seen_ips, true)) {
                continue;
            }
            $seen_ips[] = "{$ip}:{$port}";
            $clean_ips[] = [
                "id" => trim($s["id"] ?? ("src-" . ($idx + 1))),
                "ip" => $ip,
                "port" => $port,
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

    // Read existing duration settings from database if not explicitly provided
    $pdo = get_db();
    $existingCfg = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT key, value FROM inbound_config");
            while ($row = $stmt->fetch()) {
                $existingCfg[$row["key"]] = $row["value"];
            }
        } catch (Exception $e) {}
    }

    $dur1 = isset($d["testcall_duration"]) ? (int)$d["testcall_duration"] : (int)($existingCfg["testcall_duration"] ?? 60);
    $dur2 = isset($d["testcall2_duration"]) ? (int)$d["testcall2_duration"] : (int)($existingCfg["testcall2_duration"] ?? 50);
    $pfx2 = isset($d["testcall2_prefixes"]) ? trim((string)$d["testcall2_prefixes"]) : "";
    $pfx1 = isset($d["testcall_prefixes"]) ? trim((string)$d["testcall_prefixes"]) : "";

    // If prefix strings are empty, auto-derive from source_ips
    if ($pfx1 === "" && $pfx2 === "") {
        $t1 = [];
        $t2 = [];
        foreach ($clean_ips as $s) {
            $raw_combined = trim(($s["prefix"] ?? "") . ", " . ($s["country_code"] ?? ""));
            $raw_p = str_replace([";", " "], ",", $raw_combined);
            foreach (explode(",", $raw_p) as $p) {
                $p = preg_replace("/[^0-9]/", "", trim($p));
                if (!$p) continue;
                $ctx = strtolower(trim($s["context"] ?? "auto"));
                if ($ctx === "testcall2" || str_starts_with($p, "223")) {
                    if (!in_array($p, $t2, true)) $t2[] = $p;
                } else {
                    if (!in_array($p, $t1, true)) $t1[] = $p;
                }
            }
        }
        $pfx1 = implode(", ", $t1);
        $pfx2 = implode(", ", $t2);
    }

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

    // 1. Direct SQLite database transaction
    $pdo = get_db();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM gateways;");
            $ins = $pdo->prepare("
                INSERT INTO gateways (id, ip, port, label, prefix, country_code, context, is_active, updated_at)
                VALUES (:id, :ip, :port, :label, :prefix, :country_code, :context, 1, CURRENT_TIMESTAMP)
            ");
            foreach ($clean_ips as $gw) {
                $ins->execute([
                    ":id" => $gw["id"],
                    ":ip" => $gw["ip"],
                    ":port" => (int)($gw["port"] ?? 5060),
                    ":label" => $gw["label"],
                    ":prefix" => $gw["prefix"],
                    ":country_code" => $gw["country_code"],
                    ":context" => $gw["context"]
                ]);
            }
            $cfgUp = $pdo->prepare("INSERT OR REPLACE INTO inbound_config (key, value) VALUES (:k, :v)");
            $cfgUp->execute([":k" => "testcall_duration", ":v" => (string)$dur1]);
            $cfgUp->execute([":k" => "testcall2_duration", ":v" => (string)$dur2]);
            $cfgUp->execute([":k" => "testcall_prefixes", ":v" => (string)$pfx1]);
            $cfgUp->execute([":k" => "testcall2_prefixes", ":v" => (string)$pfx2]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    $f = "/etc/callgen-inbound.json";
    $jsonPayload = json_encode($inboundCfg, JSON_PRETTY_PRINT) . "\n";
    $saved = @file_put_contents($f, $jsonPayload);
    if ($saved !== false) {
        @chmod($f, 0664);
        [$sr, $so] = ctl(["sync-inbound"]);
    } else {
        [$sr, $so] = ctl(["save-inbound", $jsonPayload]);
    }
    // Always update callgen-config.json as persistence fallback
    $cfg = load_config();
    $cfg["inbound"] = $inboundCfg;
    save_config($cfg);

    done(["ok" => true, "message" => "Saved " . count($clean_ips) . " Source IP(s) & duration limits (testcall: {$dur1}s, testcall2: {$dur2}s) to database and updated Asterisk dialplan."]);
}

// 4. START / STOP / HANGUP / RESET-QUOTA
if (in_array($a, ["start", "stop", "hangup", "reset-quota", "reset"], true)) {
    $trunk = trim($d["trunk"] ?? ($_GET["trunk"] ?? "all"));
    if ($a === "reset-quota" || $a === "reset") {
        $validTrunks = ["cuba", "senegal", "mali", "all"];
        if (!in_array($trunk, $validTrunks, true)) {
            done(["ok" => false, "error" => "Invalid trunk: " . $trunk], 400);
        }
        $now_str = date("Y-m-d H:i:s");
        $pdo = get_db();
        if ($pdo) {
            try {
                if ($trunk === "all") {
                    $pdo->prepare("UPDATE trunks SET quota_reset_at = :now, used_minutes = 0.0, limit_reached = 0")->execute([":now" => $now_str]);
                } else {
                    $pdo->prepare("UPDATE trunks SET quota_reset_at = :now, used_minutes = 0.0, limit_reached = 0 WHERE id = :id")->execute([":now" => $now_str, ":id" => $trunk]);
                }
            } catch (Exception $e) {}
        }

        // Also update JSON configs on disk
        $trunksToReset = ($trunk === "all") ? ["cuba", "senegal", "mali"] : [$trunk];
        foreach ($trunksToReset as $tName) {
            $jf = "/etc/callgen-{$tName}.json";
            if (file_exists($jf)) {
                $cfg = json_decode(file_get_contents($jf), true) ?? [];
                $cfg["quota_reset_at"] = $now_str;
                $cfg["used_minutes"] = 0.0;
                $cfg["limit_reached"] = false;
                @file_put_contents($jf, json_encode($cfg, JSON_PRETTY_PRINT));
            }
        }

        // Invoke callgen-control reset-quota
        [$r, $o] = ctl(["reset-quota", $trunk]);
        done(["ok" => true, "message" => "Quota reset to 0.0 min for " . ($trunk === "all" ? "all routes" : ucfirst($trunk))]);
    }
    [$r, $o] = ctl([$a, $trunk]);
    done(["ok" => !$r, "message" => $o], $r ? 500 : 200);
}

// 5. CHANGE PASSWORD
if ($a === "change-password") {
    $curPass = trim($d["current_password"] ?? "");
    $newPass = trim($d["new_password"] ?? "");
    $auth_file = "/etc/callgen/auth.json";
    $pass_req = getenv("CALLGEN_PASSWORD") ?: "dancom";
    if (file_exists($auth_file)) {
        $j = json_decode(file_get_contents($auth_file), true);
        if (!empty($j["password"])) $pass_req = $j["password"];
    }
    if ($curPass !== $pass_req) {
        done(["ok" => false, "error" => "Current password is incorrect"], 400);
    }
    if (strlen($newPass) < 4) {
        done(["ok" => false, "error" => "New password must be at least 4 characters"], 400);
    }
    @mkdir(dirname($auth_file), 0755, true);
    file_put_contents($auth_file, json_encode([
        "username" => $_SESSION["operator"] ?? "awais",
        "password" => $newPass
    ], JSON_PRETTY_PRINT));
    $_SESSION["m5_auth"] = true;
    done(["ok" => true, "message" => "Password changed successfully"]);
}

// ==============================================================================
// CUSTOMER & VENDOR TRAFFIC MANAGEMENT MODULE (Asterisk 23 PJSIP)
// ==============================================================================

function sync_asterisk_routing() {
    $sync_scripts = [
        "/usr/local/sbin/callgen-sync-inbound.py",
        dirname(__DIR__) . "/bin/callgen-sync-inbound.py",
        "/opt/callgen/bin/callgen-sync-inbound.py"
    ];
    foreach ($sync_scripts as $script) {
        if (file_exists($script)) {
            @exec("sudo python3 " . escapeshellarg($script) . " 2>&1", $o, $rc);
            if ($rc === 0) break;
            @exec("python3 " . escapeshellarg($script) . " 2>&1", $o2, $rc2);
            if ($rc2 === 0) break;
        }
    }
    $ast_bin = trim(shell_exec("which asterisk 2>/dev/null") ?: "/usr/sbin/asterisk");
    if (file_exists($ast_bin)) {
        @shell_exec("sudo {$ast_bin} -rx \"pjsip reload\" 2>&1");
        @shell_exec("sudo {$ast_bin} -rx \"dialplan reload\" 2>&1");
    }
}

function put_astdb($family, $key, $val) {
    $ast_bin = trim(shell_exec("which asterisk 2>/dev/null") ?: "/usr/sbin/asterisk");
    if (file_exists($ast_bin)) {
        $safe_f = escapeshellarg($family);
        $safe_k = escapeshellarg($key);
        $safe_v = escapeshellarg($val);
        shell_exec("sudo {$ast_bin} -rx \"database put {$safe_f} {$safe_k} {$safe_v}\" 2>&1");
    }
}

function get_asterisk_active_channels($type = 'cust') {
    $ast_bin = trim(shell_exec("which asterisk 2>/dev/null") ?: "/usr/sbin/asterisk");
    $active = [];
    if (file_exists($ast_bin)) {
        $group_output = shell_exec("sudo {$ast_bin} -rx \"group show channels\" 2>/dev/null") ?: "";
        $pattern = ($type === 'cust') ? '/cust_([a-zA-Z0-9_-]+)/' : '/vend_([a-zA-Z0-9_-]+)/';
        foreach (explode("\n", $group_output) as $line) {
            if (preg_match($pattern, $line, $m)) {
                $id = $m[1];
                $active[$id] = ($active[$id] ?? 0) + 1;
            }
        }
    }
    return $active;
}

// 6. CUSTOMERS CRUD
if ($a === "customers" || $a === "customers-list") {
    $pdo = get_db();
    $customers = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM customers ORDER BY name ASC");
            $customers = $stmt->fetchAll();
            foreach ($customers as &$c) {
                $cid = $c["id"];
                try {
                    $stmt_ips = $pdo->prepare("SELECT ip, ip_address, port FROM customer_ips WHERE customer_id = :cid");
                    $stmt_ips->execute([":cid" => $cid]);
                    $raw_ips = $stmt_ips->fetchAll();
                    $c["ips"] = array_map(function($r) {
                        return ["ip" => $r["ip"] ?: ($r["ip_address"] ?: ""), "port" => (int)($r["port"] ?? 5060)];
                    }, $raw_ips);
                } catch (Exception $e) { $c["ips"] = []; }

                try {
                    $stmt_pfx = $pdo->prepare("SELECT prefix FROM customer_prefixes WHERE customer_id = :cid");
                    $stmt_pfx->execute([":cid" => $cid]);
                    $c["prefixes"] = $stmt_pfx->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (Exception $e) { $c["prefixes"] = []; }
            }
            unset($c);
        } catch (Exception $e) {
            $customers = [];
        }
    }

    // Fallback to callgen-config.json if database has no customers or SQLite is unavailable
    if (empty($customers)) {
        $cfg = load_config();
        $customers = $cfg["_customers"] ?? [];
    }

    $cust_active = get_asterisk_active_channels('cust');
    foreach ($customers as &$c) {
        $cid = $c["id"] ?? "";
        if (!isset($c["ips"]) || !is_array($c["ips"])) $c["ips"] = [];
        if (!isset($c["prefixes"]) || !is_array($c["prefixes"])) $c["prefixes"] = [];
        $c["primary_ip"] = !empty($c["ips"]) ? ($c["ips"][0]["ip"] . ":" . ($c["ips"][0]["port"] ?? 5060)) : ($c["primary_ip"] ?? "");
        $c["prefix_str"] = !empty($c["prefixes"]) ? implode(", ", $c["prefixes"]) : ($c["prefix_str"] ?? "");
        $c["active_calls"] = (int)($cust_active[$cid] ?? 0);
        $c["active"] = (int)($cust_active[$cid] ?? 0);
        if (!isset($c["max_channels"])) $c["max_channels"] = (int)($c["allocated_channels"] ?? 100);
        if (!isset($c["status"])) $c["status"] = "active";
    }
    unset($c);

    done(["ok" => true, "customers" => $customers]);
}

if ($a === "customer-save") {
    $name = trim($d["name"] ?? "");
    if (!$name) done(["ok" => false, "error" => "Customer name is required"], 400);

    $id = trim($d["id"] ?? "");
    if (!$id) {
        $id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name)));
        if (!$id) $id = "cust_" . time();
    }

    $max_ch = max(1, (int)($d["max_channels"] ?? 100));
    $status = in_array($d["status"] ?? "active", ["active", "blocked"], true) ? $d["status"] : "active";
    $cc = trim($d["country_code"] ?? "");
    $desc = trim($d["description"] ?? "");

    // Raw IPs & Prefixes parsing
    $raw_ips = $d["ips"] ?? $d["ip"] ?? "";
    $ip_list = [];
    if (is_array($raw_ips)) {
        foreach ($raw_ips as $entry) {
            if (is_array($entry)) {
                $host = trim($entry["ip"] ?? "");
                $port = (int)($entry["port"] ?? 5060);
            } else {
                $parts = explode(":", trim((string)$entry));
                $host = $parts[0];
                $port = isset($parts[1]) ? (int)$parts[1] : 5060;
            }
            if ($host) $ip_list[] = ["ip" => $host, "port" => ($port > 0 && $port <= 65535) ? $port : 5060];
        }
    } else {
        foreach (explode(",", (string)$raw_ips) as $piece) {
            $piece = trim($piece);
            if (!$piece) continue;
            $parts = explode(":", $piece);
            $host = $parts[0];
            $port = isset($parts[1]) ? (int)$parts[1] : (int)($d["port"] ?? 5060);
            if ($host) $ip_list[] = ["ip" => $host, "port" => ($port > 0 && $port <= 65535) ? $port : 5060];
        }
    }

    $raw_pfx = $d["prefixes"] ?? $d["prefix"] ?? "";
    $pfx_list = [];
    if (is_array($raw_pfx)) {
        foreach ($raw_pfx as $p) {
            $clean = preg_replace('/[^0-9]/', '', (string)$p);
            if ($clean && !in_array($clean, $pfx_list, true)) $pfx_list[] = $clean;
        }
    } else {
        foreach (explode(",", (string)$raw_pfx) as $p) {
            $clean = preg_replace('/[^0-9]/', '', $p);
            if ($clean && !in_array($clean, $pfx_list, true)) $pfx_list[] = $clean;
        }
    }

    // 1. Save to SQLite if database is available
    $pdo = get_db();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO customers (id, name, status, max_channels, allocated_channels, country_code, description, updated_at)
                VALUES (:id, :name, :status, :max_ch, :alloc_ch, :cc, :desc, CURRENT_TIMESTAMP)
                ON CONFLICT(id) DO UPDATE SET
                    name = excluded.name,
                    status = excluded.status,
                    max_channels = excluded.max_channels,
                    allocated_channels = excluded.allocated_channels,
                    country_code = excluded.country_code,
                    description = excluded.description,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ":id" => $id,
                ":name" => $name,
                ":status" => $status,
                ":max_ch" => $max_ch,
                ":alloc_ch" => $max_ch,
                ":cc" => $cc,
                ":desc" => $desc
            ]);

            $pdo->prepare("DELETE FROM customer_ips WHERE customer_id = :cid")->execute([":cid" => $id]);
            $ins_ip = $pdo->prepare("INSERT INTO customer_ips (customer_id, ip, ip_address, port) VALUES (:cid, :ip, :ip2, :port)");
            foreach ($ip_list as $item) {
                $ins_ip->execute([":cid" => $id, ":ip" => $item["ip"], ":ip2" => $item["ip"], ":port" => $item["port"]]);
            }

            $pdo->prepare("DELETE FROM customer_prefixes WHERE customer_id = :cid")->execute([":cid" => $id]);
            $ins_pfx = $pdo->prepare("INSERT INTO customer_prefixes (customer_id, prefix, country_code) VALUES (:cid, :pfx, :cc)");
            foreach ($pfx_list as $pfx) {
                $ins_pfx->execute([":cid" => $id, ":pfx" => $pfx, ":cc" => $cc]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    // 2. ALWAYS Save to callgen-config.json
    $cfg = load_config();
    if (!isset($cfg["_customers"]) || !is_array($cfg["_customers"])) $cfg["_customers"] = [];
    $found = false;
    $custObj = [
        "id" => $id,
        "name" => $name,
        "status" => $status,
        "max_channels" => $max_ch,
        "country_code" => $cc,
        "description" => $desc,
        "ips" => $ip_list,
        "prefixes" => $pfx_list
    ];
    foreach ($cfg["_customers"] as &$item) {
        if ($item["id"] === $id) {
            $item = $custObj;
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        $cfg["_customers"][] = $custObj;
    }
    save_config($cfg);

    put_astdb("customer", "cust_{$id}", "status", $status);
    sync_asterisk_routing();

    done(["ok" => true, "customer" => $custObj, "message" => "Customer saved successfully."]);
}

if ($a === "customer-delete") {
    $id = trim($d["id"] ?? "");
    if (!$id) done(["ok" => false, "error" => "Customer ID is required"], 400);

    $pdo = get_db();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM customer_vendor_mappings WHERE customer_id = :cid")->execute([":cid" => $id]);
            $pdo->prepare("DELETE FROM customer_prefixes WHERE customer_id = :cid")->execute([":cid" => $id]);
            $pdo->prepare("DELETE FROM customer_ips WHERE customer_id = :cid")->execute([":cid" => $id]);
            $pdo->prepare("DELETE FROM customers WHERE id = :cid")->execute([":cid" => $id]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    $cfg = load_config();
    if (!empty($cfg["_customers"])) {
        $cfg["_customers"] = array_values(array_filter($cfg["_customers"], function($c) use ($id) { return $c["id"] !== $id; }));
    }
    if (!empty($cfg["_mappings"])) {
        $cfg["_mappings"] = array_values(array_filter($cfg["_mappings"], function($m) use ($id) { return $m["customer_id"] !== $id; }));
    }
    save_config($cfg);

    put_astdb("customer", "cust_{$id}", "status", "deleted");
    sync_asterisk_routing();

    done(["ok" => true, "message" => "Customer deleted successfully."]);
}

if ($a === "customer-block" || $a === "customer-unblock") {
    $id = trim($d["id"] ?? "");
    if (!$id) done(["ok" => false, "error" => "Customer ID is required"], 400);

    $new_status = ($a === "customer-block") ? "blocked" : "active";

    $pdo = get_db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE customers SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([":st" => $new_status, ":id" => $id]);
        } catch (Exception $e) {}
    }

    $cfg = load_config();
    if (!empty($cfg["_customers"])) {
        foreach ($cfg["_customers"] as &$c) {
            if ($c["id"] === $id) {
                $c["status"] = $new_status;
                break;
            }
        }
        unset($c);
        save_config($cfg);
    }

    put_astdb("customer", "cust_{$id}", "status", $new_status);
    sync_asterisk_routing();

    done(["ok" => true, "status" => $new_status, "message" => "Customer is now {$new_status}."]);
}

// 7. VENDORS CRUD
if ($a === "vendors" || $a === "vendors-list") {
    $pdo = get_db();
    $vendors = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM vendors ORDER BY name ASC");
            $vendors = $stmt->fetchAll();
            foreach ($vendors as &$v) {
                $vid = $v["id"];
                try {
                    $stmt_ips = $pdo->prepare("SELECT ip, ip_address, port FROM vendor_ips WHERE vendor_id = :vid");
                    $stmt_ips->execute([":vid" => $vid]);
                    $raw_ips = $stmt_ips->fetchAll();
                    $v["ips"] = array_map(function($r) {
                        return ["ip" => $r["ip"] ?: ($r["ip_address"] ?: ""), "port" => (int)($r["port"] ?? 5060)];
                    }, $raw_ips);
                } catch (Exception $e) { $v["ips"] = []; }

                try {
                    $stmt_pfx = $pdo->prepare("SELECT prefix FROM vendor_prefixes WHERE vendor_id = :vid");
                    $stmt_pfx->execute([":vid" => $vid]);
                    $v["prefixes"] = $stmt_pfx->fetchAll(PDO::FETCH_COLUMN, 0);
                } catch (Exception $e) { $v["prefixes"] = []; }
            }
            unset($v);
        } catch (Exception $e) {
            $vendors = [];
        }
    }

    if (empty($vendors)) {
        $cfg = load_config();
        $vendors = $cfg["_vendors"] ?? [];
    }

    $vend_active = get_asterisk_active_channels('vend');
    foreach ($vendors as &$v) {
        $vid = $v["id"] ?? "";
        if (!isset($v["ips"]) || !is_array($v["ips"])) $v["ips"] = [];
        if (!isset($v["prefixes"]) || !is_array($v["prefixes"])) $v["prefixes"] = [];
        $v["primary_ip"] = !empty($v["ips"]) ? ($v["ips"][0]["ip"] . ":" . ($v["ips"][0]["port"] ?? 5060)) : ($v["primary_ip"] ?? "");
        $v["prefix_str"] = !empty($v["prefixes"]) ? implode(", ", $v["prefixes"]) : ($v["prefix_str"] ?? "");
        $v["active_calls"] = (int)($vend_active[$vid] ?? 0);
        $v["active"] = (int)($vend_active[$vid] ?? 0);
        if (!isset($v["max_channels"])) $v["max_channels"] = 200;
        if (!isset($v["caller_id"])) $v["caller_id"] = $v["caller_id_format"] ?? "";
        if (!isset($v["status"])) $v["status"] = "active";
    }
    unset($v);

    done(["ok" => true, "vendors" => $vendors]);
}

if ($a === "vendor-save") {
    $name = trim($d["name"] ?? "");
    if (!$name) done(["ok" => false, "error" => "Vendor name is required"], 400);

    $id = trim($d["id"] ?? "");
    if (!$id) {
        $id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $name)));
        if (!$id) $id = "vend_" . time();
    }

    $max_ch = max(1, (int)($d["max_channels"] ?? 200));
    $status = in_array($d["status"] ?? "active", ["active", "blocked"], true) ? $d["status"] : "active";
    $cc = trim($d["country_code"] ?? "");
    $cli = trim($d["caller_id"] ?? ($d["caller_id_format"] ?? ""));
    $desc = trim($d["description"] ?? "");

    // Raw IPs & Prefixes parsing
    $raw_ips = $d["ips"] ?? $d["ip"] ?? "";
    $ip_list = [];
    if (is_array($raw_ips)) {
        foreach ($raw_ips as $entry) {
            if (is_array($entry)) {
                $host = trim($entry["ip"] ?? "");
                $port = (int)($entry["port"] ?? 5060);
            } else {
                $parts = explode(":", trim((string)$entry));
                $host = $parts[0];
                $port = isset($parts[1]) ? (int)$parts[1] : 5060;
            }
            if ($host) $ip_list[] = ["ip" => $host, "port" => ($port > 0 && $port <= 65535) ? $port : 5060];
        }
    } else {
        foreach (explode(",", (string)$raw_ips) as $piece) {
            $piece = trim($piece);
            if (!$piece) continue;
            $parts = explode(":", $piece);
            $host = $parts[0];
            $port = isset($parts[1]) ? (int)$parts[1] : (int)($d["port"] ?? 5060);
            if ($host) $ip_list[] = ["ip" => $host, "port" => ($port > 0 && $port <= 65535) ? $port : 5060];
        }
    }

    $raw_pfx = $d["prefixes"] ?? $d["prefix"] ?? "";
    $pfx_list = [];
    if (is_array($raw_pfx)) {
        foreach ($raw_pfx as $p) {
            $clean = preg_replace('/[^0-9]/', '', (string)$p);
            if ($clean && !in_array($clean, $pfx_list, true)) $pfx_list[] = $clean;
        }
    } else {
        foreach (explode(",", (string)$raw_pfx) as $p) {
            $clean = preg_replace('/[^0-9]/', '', $p);
            if ($clean && !in_array($clean, $pfx_list, true)) $pfx_list[] = $clean;
        }
    }

    $pdo = get_db();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("
                INSERT INTO vendors (id, name, status, max_channels, country_code, caller_id, caller_id_format, description, updated_at)
                VALUES (:id, :name, :status, :max_ch, :cc, :cli, :cli2, :desc, CURRENT_TIMESTAMP)
                ON CONFLICT(id) DO UPDATE SET
                    name = excluded.name,
                    status = excluded.status,
                    max_channels = excluded.max_channels,
                    country_code = excluded.country_code,
                    caller_id = excluded.caller_id,
                    caller_id_format = excluded.caller_id_format,
                    description = excluded.description,
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ":id" => $id,
                ":name" => $name,
                ":status" => $status,
                ":max_ch" => $max_ch,
                ":cc" => $cc,
                ":cli" => $cli,
                ":cli2" => $cli,
                ":desc" => $desc
            ]);

            $pdo->prepare("DELETE FROM vendor_ips WHERE vendor_id = :vid")->execute([":vid" => $id]);
            $ins_ip = $pdo->prepare("INSERT INTO vendor_ips (vendor_id, ip, ip_address, port) VALUES (:vid, :ip, :ip2, :port)");
            foreach ($ip_list as $item) {
                $ins_ip->execute([":vid" => $id, ":ip" => $item["ip"], ":ip2" => $item["ip"], ":port" => $item["port"]]);
            }

            $pdo->prepare("DELETE FROM vendor_prefixes WHERE vendor_id = :vid")->execute([":vid" => $id]);
            $ins_pfx = $pdo->prepare("INSERT INTO vendor_prefixes (vendor_id, prefix, country_code) VALUES (:vid, :pfx, :cc)");
            foreach ($pfx_list as $pfx) {
                $ins_pfx->execute([":vid" => $id, ":pfx" => $pfx, ":cc" => $cc]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    $cfg = load_config();
    if (!isset($cfg["_vendors"]) || !is_array($cfg["_vendors"])) $cfg["_vendors"] = [];
    $found = false;
    $vendObj = [
        "id" => $id,
        "name" => $name,
        "status" => $status,
        "max_channels" => $max_ch,
        "country_code" => $cc,
        "caller_id" => $cli,
        "description" => $desc,
        "ips" => $ip_list,
        "prefixes" => $pfx_list
    ];
    foreach ($cfg["_vendors"] as &$item) {
        if ($item["id"] === $id) {
            $item = $vendObj;
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        $cfg["_vendors"][] = $vendObj;
    }
    save_config($cfg);

    put_astdb("vendor", "vend_{$id}", "status", $status);
    sync_asterisk_routing();

    done(["ok" => true, "vendor" => $vendObj, "message" => "Vendor saved successfully."]);
}

if ($a === "vendor-delete") {
    $id = trim($d["id"] ?? "");
    if (!$id) done(["ok" => false, "error" => "Vendor ID is required"], 400);

    $pdo = get_db();
    if ($pdo) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM customer_vendor_mappings WHERE vendor_id = :vid")->execute([":vid" => $id]);
            $pdo->prepare("DELETE FROM vendor_prefixes WHERE vendor_id = :vid")->execute([":vid" => $id]);
            $pdo->prepare("DELETE FROM vendor_ips WHERE vendor_id = :vid")->execute([":vid" => $id]);
            $pdo->prepare("DELETE FROM vendors WHERE id = :vid")->execute([":vid" => $id]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }

    $cfg = load_config();
    if (!empty($cfg["_vendors"])) {
        $cfg["_vendors"] = array_values(array_filter($cfg["_vendors"], function($v) use ($id) { return $v["id"] !== $id; }));
    }
    if (!empty($cfg["_mappings"])) {
        $cfg["_mappings"] = array_values(array_filter($cfg["_mappings"], function($m) use ($id) { return $m["vendor_id"] !== $id; }));
    }
    save_config($cfg);

    put_astdb("vendor", "vend_{$id}", "status", "deleted");
    sync_asterisk_routing();

    done(["ok" => true, "message" => "Vendor deleted successfully."]);
}

if ($a === "vendor-block" || $a === "vendor-unblock") {
    $id = trim($d["id"] ?? "");
    if (!$id) done(["ok" => false, "error" => "Vendor ID is required"], 400);

    $new_status = ($a === "vendor-block") ? "blocked" : "active";

    $pdo = get_db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("UPDATE vendors SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([":st" => $new_status, ":id" => $id]);
        } catch (Exception $e) {}
    }

    $cfg = load_config();
    if (!empty($cfg["_vendors"])) {
        foreach ($cfg["_vendors"] as &$v) {
            if ($v["id"] === $id) {
                $v["status"] = $new_status;
                break;
            }
        }
        unset($v);
        save_config($cfg);
    }

    put_astdb("vendor", "vend_{$id}", "status", $new_status);
    sync_asterisk_routing();

    done(["ok" => true, "status" => $new_status, "message" => "Vendor is now {$new_status}."]);
}

// 8. CUSTOMER-VENDOR ROUTING MAPPINGS CRUD
if ($a === "mappings" || $a === "mappings-list") {
    $pdo = get_db();
    $mappings = [];
    if ($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT m.*, c.name AS customer_name, c.status AS customer_status, v.name AS vendor_name, v.status AS vendor_status
                FROM customer_vendor_mappings m
                LEFT JOIN customers c ON m.customer_id = c.id
                LEFT JOIN vendors v ON m.vendor_id = v.id
                ORDER BY m.customer_id ASC, m.priority ASC, m.id ASC
            ");
            $mappings = $stmt->fetchAll();
        } catch (Exception $e) {
            $mappings = [];
        }
    }

    if (empty($mappings)) {
        $cfg = load_config();
        $mappings = $cfg["_mappings"] ?? [];
        $custs = $cfg["_customers"] ?? [];
        $vends = $cfg["_vendors"] ?? [];
        $custMap = [];
        foreach ($custs as $c) $custMap[$c["id"]] = $c;
        $vendMap = [];
        foreach ($vends as $v) $vendMap[$v["id"]] = $v;
        foreach ($mappings as &$m) {
            $cid = $m["customer_id"] ?? "";
            $vid = $m["vendor_id"] ?? "";
            $m["customer_name"] = $custMap[$cid]["name"] ?? $cid;
            $m["customer_status"] = $custMap[$cid]["status"] ?? "active";
            $m["vendor_name"] = $vendMap[$vid]["name"] ?? $vid;
            $m["vendor_status"] = $vendMap[$vid]["status"] ?? "active";
        }
        unset($m);
    }

    done(["ok" => true, "mappings" => $mappings]);
}

if ($a === "mapping-save") {
    $id = (int)($d["id"] ?? 0);
    $cid = trim($d["customer_id"] ?? "");
    $vid = trim($d["vendor_id"] ?? "");
    $cust_pfx = trim($d["customer_prefix"] ?? "");
    $vend_pfx = trim($d["vendor_prefix"] ?? "");
    $cc = trim($d["country_code"] ?? "");
    $prio = max(1, (int)($d["priority"] ?? 1));
    $max_ch = max(1, (int)($d["max_channels"] ?? 60));
    $status = in_array($d["status"] ?? "active", ["active", "disabled"], true) ? $d["status"] : "active";

    if (!$cid || !$vid) {
        done(["ok" => false, "error" => "Customer and Vendor selections are required"], 400);
    }

    $pdo = get_db();
    if ($pdo) {
        try {
            if ($id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE customer_vendor_mappings SET
                        customer_id = :cid,
                        vendor_id = :vid,
                        customer_prefix = :cp,
                        vendor_prefix = :vp,
                        country_code = :cc,
                        priority = :prio,
                        max_channels = :ch,
                        status = :st,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute([
                    ":cid" => $cid,
                    ":vid" => $vid,
                    ":cp" => $cust_pfx,
                    ":vp" => $vend_pfx,
                    ":cc" => $cc,
                    ":prio" => $prio,
                    ":ch" => $max_ch,
                    ":st" => $status,
                    ":id" => $id
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO customer_vendor_mappings
                        (customer_id, vendor_id, customer_prefix, vendor_prefix, country_code, priority, max_channels, status)
                    VALUES
                        (:cid, :vid, :cp, :vp, :cc, :prio, :ch, :st)
                ");
                $stmt->execute([
                    ":cid" => $cid,
                    ":vid" => $vid,
                    ":cp" => $cust_pfx,
                    ":vp" => $vend_pfx,
                    ":cc" => $cc,
                    ":prio" => $prio,
                    ":ch" => $max_ch,
                    ":st" => $status
                ]);
                $id = (int)$pdo->lastInsertId();
            }
        } catch (Exception $e) {}
    }

    // Save to callgen-config.json
    $cfg = load_config();
    if (!isset($cfg["_mappings"]) || !is_array($cfg["_mappings"])) $cfg["_mappings"] = [];
    if (!$id) {
        $existingIds = array_column($cfg["_mappings"], 'id');
        $id = (!empty($existingIds)) ? (max($existingIds) + 1) : 1;
    }
    $mapObj = [
        "id" => $id,
        "customer_id" => $cid,
        "vendor_id" => $vid,
        "customer_prefix" => $cust_pfx,
        "vendor_prefix" => $vend_pfx,
        "country_code" => $cc,
        "priority" => $prio,
        "max_channels" => $max_ch,
        "status" => $status
    ];
    $found = false;
    foreach ($cfg["_mappings"] as &$item) {
        if ($item["id"] === $id) {
            $item = $mapObj;
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        $cfg["_mappings"][] = $mapObj;
    }
    save_config($cfg);

    sync_asterisk_routing();
    done(["ok" => true, "mapping" => $mapObj, "message" => "Customer-Vendor routing mapping saved successfully."]);
}

if ($a === "mapping-delete") {
    $id = (int)($d["id"] ?? 0);
    if (!$id) done(["ok" => false, "error" => "Invalid mapping ID"], 400);

    $pdo = get_db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("DELETE FROM customer_vendor_mappings WHERE id = :id");
            $stmt->execute([":id" => $id]);
        } catch (Exception $e) {}
    }

    $cfg = load_config();
    if (!empty($cfg["_mappings"])) {
        $cfg["_mappings"] = array_values(array_filter($cfg["_mappings"], function($m) use ($id) { return $m["id"] !== $id; }));
        save_config($cfg);
    }

    sync_asterisk_routing();
    done(["ok" => true, "message" => "Mapping deleted successfully."]);
}

if ($a === "mapping-toggle") {
    $id = (int)($d["id"] ?? 0);
    if (!$id) done(["ok" => false, "error" => "Invalid mapping ID"], 400);

    $cur_status = "active";
    $pdo = get_db();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT status FROM customer_vendor_mappings WHERE id = :id");
            $stmt->execute([":id" => $id]);
            $cur = $stmt->fetchColumn();
            if ($cur) $cur_status = $cur;
        } catch (Exception $e) {}
    }

    $cfg = load_config();
    if (!empty($cfg["_mappings"])) {
        foreach ($cfg["_mappings"] as $m) {
            if ($m["id"] === $id) {
                $cur_status = $m["status"] ?? "active";
                break;
            }
        }
    }

    $new = ($cur_status === "active") ? "disabled" : "active";

    if ($pdo) {
        try {
            $pdo->prepare("UPDATE customer_vendor_mappings SET status = :st, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([":st" => $new, ":id" => $id]);
        } catch (Exception $e) {}
    }

    if (!empty($cfg["_mappings"])) {
        foreach ($cfg["_mappings"] as &$m) {
            if ($m["id"] === $id) {
                $m["status"] = $new;
                break;
            }
        }
        unset($m);
        save_config($cfg);
    }

    sync_asterisk_routing();
    done(["ok" => true, "status" => $new, "message" => "Mapping status updated to {$new}."]);
}

// 9. LIVE CHANNELS & TRAFFIC STATS
if ($a === "live-channels" || $a === "traffic-stats") {
    $pdo = get_db();
    $customers = [];
    $vendors = [];
    if ($pdo) {
        try {
            $customers = $pdo->query("SELECT id, name, max_channels, status FROM customers ORDER BY name ASC")->fetchAll();
            $vendors = $pdo->query("SELECT id, name, max_channels, status FROM vendors ORDER BY name ASC")->fetchAll();
        } catch (Exception $e) {}
    }

    if (empty($customers) || empty($vendors)) {
        $cfg = load_config();
        if (empty($customers)) $customers = $cfg["_customers"] ?? [];
        if (empty($vendors)) $vendors = $cfg["_vendors"] ?? [];
    }

    $cust_active = get_asterisk_active_channels('cust');
    $vend_active = get_asterisk_active_channels('vend');

    $cust_stats = [];
    $tot_cust_alloc = 0;
    $tot_cust_act = 0;
    $cust_active_count = 0;
    $cust_blocked_count = 0;

    foreach ($customers as $c) {
        $cid = $c["id"];
        $alloc = (int)($c["max_channels"] ?? ($c["allocated_channels"] ?? 100));
        $act = (int)($cust_active[$cid] ?? 0);
        $avail = max(0, $alloc - $act);
        $st = $c["status"] ?? "active";
        $cust_stats[] = [
            "id" => $cid,
            "name" => $c["name"] ?? $cid,
            "status" => $st,
            "allocated" => $alloc,
            "active" => $act,
            "available" => $avail,
            "utilization" => $alloc > 0 ? round(($act / $alloc) * 100, 1) : 0
        ];
        $tot_cust_alloc += $alloc;
        $tot_cust_act += $act;
        if ($st === "blocked") $cust_blocked_count++;
        else $cust_active_count++;
    }

    $vend_stats = [];
    $tot_vend_alloc = 0;
    $tot_vend_act = 0;
    $vend_active_count = 0;
    $vend_blocked_count = 0;

    foreach ($vendors as $v) {
        $vid = $v["id"];
        $alloc = (int)($v["max_channels"] ?? 200);
        $act = (int)($vend_active[$vid] ?? 0);
        $avail = max(0, $alloc - $act);
        $st = $v["status"] ?? "active";
        $vend_stats[] = [
            "id" => $vid,
            "name" => $v["name"] ?? $vid,
            "status" => $st,
            "allocated" => $alloc,
            "active" => $act,
            "available" => $avail,
            "utilization" => $alloc > 0 ? round(($act / $alloc) * 100, 1) : 0
        ];
        $tot_vend_alloc += $alloc;
        $tot_vend_act += $act;
        if ($st === "blocked") $vend_blocked_count++;
        else $vend_active_count++;
    }

    done([
        "ok" => true,
        "customers" => $cust_stats,
        "vendors" => $vend_stats,
        "summary" => [
            "total_customers" => count($customers),
            "active_customers" => $cust_active_count,
            "blocked_customers" => $cust_blocked_count,
            "customer_allocated_channels" => $tot_cust_alloc,
            "customer_active_channels" => $tot_cust_act,
            "total_vendors" => count($vendors),
            "active_vendors" => $vend_active_count,
            "blocked_vendors" => $vend_blocked_count,
            "vendor_allocated_channels" => $tot_vend_alloc,
            "vendor_active_channels" => $tot_vend_act,
            "total_active_calls" => $tot_cust_act,
            "asr" => 99.8,
            "acd" => 3.4
        ]
    ]);
}

// 10. CDR REPORTS
if ($a === "cdr-list") {
    $pdo = get_db();
    $filter_cust = trim($_GET["customer"] ?? ($d["customer"] ?? ""));
    $filter_vend = trim($_GET["vendor"] ?? ($d["vendor"] ?? ""));
    $filter_status = trim($_GET["status"] ?? ($d["status"] ?? ""));
    $search = trim($_GET["search"] ?? ($d["search"] ?? ""));
    $limit = min(500, max(10, (int)($_GET["limit"] ?? ($d["limit"] ?? 50))));

    $cdrs = [];
    if ($pdo) {
        try {
            $sql = "SELECT * FROM cdr WHERE 1=1";
            $params = [];
            if ($filter_cust) {
                $sql .= " AND (accountcode LIKE :fcust OR userfield LIKE :fcust2)";
                $params[":fcust"] = "%{$filter_cust}%";
                $params[":fcust2"] = "%cust_{$filter_cust}%";
            }
            if ($filter_vend) {
                $sql .= " AND userfield LIKE :fvend";
                $params[":fvend"] = "%vend_{$filter_vend}%";
            }
            if ($filter_status) {
                $sql .= " AND disposition = :fdisp";
                $params[":fdisp"] = $filter_status;
            }
            if ($search) {
                $sql .= " AND (src LIKE :q OR dst LIKE :q2 OR uniqueid LIKE :q3)";
                $params[":q"] = "%{$search}%";
                $params[":q2"] = "%{$search}%";
                $params[":q3"] = "%{$search}%";
            }
            $sql .= " ORDER BY calldate DESC LIMIT " . $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $cdrs = $stmt->fetchAll();
        } catch (Exception $e) {}
    }

    done(["ok" => true, "cdrs" => $cdrs]);
}

done(["ok" => false, "error" => "Unknown action"], 400);
