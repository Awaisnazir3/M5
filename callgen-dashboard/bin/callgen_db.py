#!/usr/bin/env python3
"""
M5-Traffic Controller: Central Database Module (SQLite)
Manages configuration for trunks, inbound gateways, duration limits, and CDR accounting.
Database path: /var/lib/callgen/callgen.db
"""

import sqlite3
import os
import sys
import json
import csv
import re
from datetime import datetime

DB_PATH = os.environ.get('CALLGEN_DB', '/var/lib/callgen/callgen.db')
CSV_PATH = '/var/log/asterisk/cdr-csv/Master.csv'

def get_connection():
    """Returns a SQLite connection configured with WAL mode and row factory."""
    db_dir = os.path.dirname(DB_PATH)
    if db_dir and not os.path.exists(db_dir):
        try:
            os.makedirs(db_dir, exist_ok=True)
            os.chmod(db_dir, 0o775)
        except Exception:
            pass

    conn = sqlite3.connect(DB_PATH, timeout=10.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode = WAL;")
    conn.execute("PRAGMA busy_timeout = 5000;")
    conn.execute("PRAGMA synchronous = NORMAL;")

    for ext in ['', '-wal', '-shm']:
        f = DB_PATH + ext
        if os.path.exists(f):
            try:
                os.chmod(f, 0o666)
            except Exception:
                pass

    ensure_schema_migrations(conn)
    return conn

def ensure_schema_migrations(conn):
    """Ensures newly required columns exist in SQLite tables for ports and quota reset."""
    try:
        cur = conn.cursor()
        t_cols = [r[1] for r in cur.execute("PRAGMA table_info(trunks)").fetchall()]
        if 'sip_port' not in t_cols:
            conn.execute("ALTER TABLE trunks ADD COLUMN sip_port INTEGER DEFAULT 5060")
        if 'quota_reset_at' not in t_cols:
            conn.execute("ALTER TABLE trunks ADD COLUMN quota_reset_at DATETIME DEFAULT NULL")
        if 'used_minutes' not in t_cols:
            conn.execute("ALTER TABLE trunks ADD COLUMN used_minutes REAL DEFAULT 0.0")
        if 'limit_reached' not in t_cols:
            conn.execute("ALTER TABLE trunks ADD COLUMN limit_reached INTEGER DEFAULT 0")

        g_cols = [r[1] for r in cur.execute("PRAGMA table_info(gateways)").fetchall()]
        if 'port' not in g_cols:
            conn.execute("ALTER TABLE gateways ADD COLUMN port INTEGER DEFAULT 5060")

        # Auto-create Customer, Vendor, and Mapping tables if not present
        existing_tables = [r[0] for r in cur.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()]
        if 'customers' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS customers (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                allocated_channels INTEGER NOT NULL DEFAULT 50,
                status TEXT NOT NULL DEFAULT 'active',
                description TEXT DEFAULT '',
                blocked_by TEXT DEFAULT '',
                blocked_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            """)
        if 'customer_ips' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS customer_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                port INTEGER NOT NULL DEFAULT 5060,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )
            """)
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cust_ips_cust ON customer_ips(customer_id)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cust_ips_ip ON customer_ips(ip_address)")
        if 'customer_prefixes' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS customer_prefixes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id TEXT NOT NULL,
                prefix TEXT NOT NULL,
                country_code TEXT DEFAULT '',
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )
            """)
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cust_pfx_cust ON customer_prefixes(customer_id)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cust_pfx_val ON customer_prefixes(prefix)")
        if 'vendors' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS vendors (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                max_channels INTEGER NOT NULL DEFAULT 100,
                caller_id_format TEXT DEFAULT '',
                status TEXT NOT NULL DEFAULT 'active',
                description TEXT DEFAULT '',
                blocked_by TEXT DEFAULT '',
                blocked_at DATETIME DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
            """)
        if 'vendor_ips' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS vendor_ips (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id TEXT NOT NULL,
                ip_address TEXT NOT NULL,
                port INTEGER NOT NULL DEFAULT 5060,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            )
            """)
            conn.execute("CREATE INDEX IF NOT EXISTS idx_vend_ips_vend ON vendor_ips(vendor_id)")
        if 'vendor_prefixes' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS vendor_prefixes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                vendor_id TEXT NOT NULL,
                prefix TEXT NOT NULL,
                country_code TEXT DEFAULT '',
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            )
            """)
        if 'customer_vendor_mappings' not in existing_tables:
            conn.execute("""
            CREATE TABLE IF NOT EXISTS customer_vendor_mappings (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id TEXT NOT NULL,
                vendor_id TEXT NOT NULL,
                customer_prefix TEXT NOT NULL DEFAULT '',
                vendor_prefix TEXT NOT NULL DEFAULT '',
                country_code TEXT NOT NULL DEFAULT '',
                priority INTEGER NOT NULL DEFAULT 1,
                status TEXT NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
            )
            """)
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cvm_cust ON customer_vendor_mappings(customer_id)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cvm_vend ON customer_vendor_mappings(vendor_id)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cvm_pfx ON customer_vendor_mappings(customer_prefix)")
            conn.execute("CREATE INDEX IF NOT EXISTS idx_cvm_prio ON customer_vendor_mappings(priority)")
    except Exception:
        pass

def init_schema():
    """Initializes tables and indexes in callgen.db."""
    conn = get_connection()
    with conn:
        # 1. Trunks configuration table
        conn.execute("""
        CREATE TABLE IF NOT EXISTS trunks (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            target_ip TEXT NOT NULL,
            sip_port INTEGER NOT NULL DEFAULT 5060,
            prefix TEXT DEFAULT '',
            country_code TEXT NOT NULL,
            prefix_cid TEXT NOT NULL,
            monthly_limit INTEGER NOT NULL DEFAULT 100000,
            max_channels INTEGER NOT NULL DEFAULT 150,
            call_duration INTEGER NOT NULL DEFAULT 600,
            context TEXT NOT NULL DEFAULT 'testcall',
            is_running INTEGER DEFAULT 0,
            used_minutes REAL DEFAULT 0.0,
            limit_reached INTEGER DEFAULT 0,
            quota_reset_at DATETIME DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        """)

        # 2. Inbound Gateways (Authorized Source IPs)
        conn.execute("""
        CREATE TABLE IF NOT EXISTS gateways (
            id TEXT PRIMARY KEY,
            ip TEXT NOT NULL UNIQUE,
            port INTEGER NOT NULL DEFAULT 5060,
            label TEXT NOT NULL DEFAULT '',
            prefix TEXT DEFAULT '',
            country_code TEXT DEFAULT '',
            context TEXT DEFAULT 'auto',
            is_active INTEGER DEFAULT 1,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        """)

        # 3. Global Inbound Breakout Settings
        conn.execute("""
        CREATE TABLE IF NOT EXISTS inbound_config (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        """)

        # 4. CDR Records table
        conn.execute("""
        CREATE TABLE IF NOT EXISTS cdr (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            calldate DATETIME NOT NULL,
            clid TEXT DEFAULT '',
            src TEXT DEFAULT '',
            dst TEXT DEFAULT '',
            dcontext TEXT DEFAULT '',
            channel TEXT DEFAULT '',
            dstchannel TEXT DEFAULT '',
            lastapp TEXT DEFAULT '',
            lastdata TEXT DEFAULT '',
            duration INTEGER DEFAULT 0,
            billsec INTEGER DEFAULT 0,
            disposition TEXT DEFAULT '',
            amaflags INTEGER DEFAULT 3,
            accountcode TEXT DEFAULT '',
            uniqueid TEXT DEFAULT '',
            userfield TEXT DEFAULT ''
        );
        """)

        conn.execute("CREATE INDEX IF NOT EXISTS idx_cdr_calldate ON cdr(calldate);")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_cdr_disposition ON cdr(disposition);")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_cdr_dst ON cdr(dst);")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_cdr_channel ON cdr(channel);")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_cdr_dstchannel ON cdr(dstchannel);")
        conn.execute("CREATE INDEX IF NOT EXISTS idx_cdr_userfield ON cdr(userfield);")

    # Set proper permissions for web & asterisk access
    for ext in ['', '-wal', '-shm']:
        f = DB_PATH + ext
        if os.path.exists(f):
            try:
                os.chmod(f, 0o666)
            except Exception:
                pass
    try:
        import grp
        for gname in ['asterisk', 'apache', 'www-data', 'nginx']:
            try:
                gid = grp.getgrnam(gname).gr_gid
                os.chown(DB_PATH, -1, gid)
                break
            except Exception:
                continue
    except Exception:
        pass
    conn.close()

def seed_defaults():
    """Migrates existing JSON configs or seeds defaults into SQLite."""
    conn = get_connection()
    default_trunks = [
        ('mali', 'Mali', '192.168.88.76:5080', 5080, '', '2237', '2237', 1000000, 2000, 600, 'testcall2'),
        ('cuba', 'Cuba', '192.168.88.76:5080', 5080, '4444', '53', '444453', 5000, 600, 600, 'testcall'),
        ('senegal', 'Senegal', '178.22.15.201:5060', 5060, '619334', '221', '619334221', 150000, 600, 600, 'testcall')
    ]

    with conn:
        for t_id, name, ip, port, pfx, cc, cid, lim, max_c, dur, ctx in default_trunks:
            # Check if json config exists to migrate current values
            conf_file = f"/etc/callgen-{t_id}.json"
            if os.path.exists(conf_file):
                try:
                    with open(conf_file) as f:
                        jd = json.load(f)
                        name = jd.get('name', name)
                        ip = jd.get('target_ip', ip)
                        port = int(jd.get('sip_port') or port)
                        pfx = jd.get('prefix', pfx)
                        cc = jd.get('country_code', cc)
                        cid = jd.get('prefix_cid', cid)
                        lim = int(jd.get('monthly_limit', lim))
                        max_c = int(jd.get('max_channels', max_c))
                        dur = int(jd.get('call_duration', dur))
                        ctx = jd.get('context', ctx)
                except Exception:
                    pass

            # Ensure host and port are formatted correctly
            if ':' in ip:
                parts = ip.split(':', 1)
                host = parts[0]
                if not port and parts[1].isdigit():
                    port = int(parts[1])
            else:
                host = ip
            if not port:
                port = 5060
            full_ip = f"{host}:{port}"

            conn.execute("""
            INSERT INTO trunks (id, name, target_ip, sip_port, prefix, country_code, prefix_cid, monthly_limit, max_channels, call_duration, context)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                name=excluded.name,
                target_ip=excluded.target_ip,
                sip_port=excluded.sip_port,
                prefix=excluded.prefix,
                country_code=excluded.country_code,
                prefix_cid=excluded.prefix_cid,
                monthly_limit=excluded.monthly_limit,
                max_channels=excluded.max_channels,
                call_duration=excluded.call_duration,
                context=excluded.context,
                updated_at=CURRENT_TIMESTAMP;
            """, (t_id, name, full_ip, port, pfx, cc, cid, lim, max_c, dur, ctx))

        # Migrate Inbound Configuration
        inbound_conf_file = '/etc/callgen-inbound.json'
        inbound_data = {
            'source_ips': [
                {
                    'id': 'src-1',
                    'ip': '192.168.1.13',
                    'port': 5060,
                    'label': 'Node-13 PBX',
                    'prefix': '444453, 619334221',
                    'country_code': '',
                    'context': 'testcall'
                },
                {
                    'id': 'src-2',
                    'ip': '192.168.1.14',
                    'port': 5060,
                    'label': 'Mali Forwarder',
                    'prefix': '2237',
                    'country_code': '223',
                    'context': 'testcall2'
                }
            ],
            'testcall_duration': 60,
            'testcall2_duration': 50,
            'testcall_prefixes': '444453, 619334221',
            'testcall2_prefixes': '2237'
        }
        if os.path.exists(inbound_conf_file):
            try:
                with open(inbound_conf_file) as f:
                    inbound_data.update(json.load(f))
            except Exception:
                pass

        for s in inbound_data.get('source_ips', []):
            sip = s.get('ip', '').strip()
            if not sip:
                continue
            port = int(s.get('port') or 5060)
            if ':' in sip:
                sip, pstr = sip.split(':', 1)
                if pstr.isdigit(): port = int(pstr)
            conn.execute("""
            INSERT INTO gateways (id, ip, port, label, prefix, country_code, context)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(ip) DO UPDATE SET
                port=excluded.port,
                label=excluded.label,
                prefix=excluded.prefix,
                country_code=excluded.country_code,
                context=excluded.context,
                updated_at=CURRENT_TIMESTAMP;
            """, (
                s.get('id', f'src-{sip.replace(".", "-")}'),
                sip,
                port,
                s.get('label', 'Gateway'),
                s.get('prefix', ''),
                s.get('country_code', ''),
                s.get('context', 'auto')
            ))

        conn.execute("INSERT OR REPLACE INTO inbound_config (key, value) VALUES ('testcall_duration', ?)", (str(inbound_data.get('testcall_duration', 60)),))
        conn.execute("INSERT OR REPLACE INTO inbound_config (key, value) VALUES ('testcall2_duration', ?)", (str(inbound_data.get('testcall2_duration', 50)),))
        conn.execute("INSERT OR REPLACE INTO inbound_config (key, value) VALUES ('testcall_prefixes', ?)", (str(inbound_data.get('testcall_prefixes', '444453, 619334221')),))
        conn.execute("INSERT OR REPLACE INTO inbound_config (key, value) VALUES ('testcall2_prefixes', ?)", (str(inbound_data.get('testcall2_prefixes', '2237')),))

    conn.close()
    try:
        seed_customer_vendor_defaults()
    except Exception:
        pass

def sync_cdrs_from_csv():
    """Syncs unimported CDR records from /var/log/asterisk/cdr-csv/Master.csv into SQLite."""
    if not os.path.exists(CSV_PATH):
        return 0

    conn = get_connection()
    cur = conn.cursor()

    # Get the latest uniqueid or timestamp in database
    cur.execute("SELECT MAX(calldate) as max_date, COUNT(*) as cnt FROM cdr")
    row = cur.fetchone()
    max_date = row['max_date'] if row and row['max_date'] else '1970-01-01'

    # Read CSV
    inserted = 0
    batch = []
    try:
        with open(CSV_PATH, 'r', encoding='utf-8', errors='ignore') as f:
            reader = csv.reader(f)
            for r in reader:
                if len(r) < 15:
                    continue
                # r[9] is calldate format: '2026-11-09 10:04:34'
                calldate = r[9].strip()
                if calldate <= max_date:
                    continue

                accountcode = r[0].strip() if len(r) > 0 else ''
                src = r[1].strip() if len(r) > 1 else ''
                dst = r[2].strip() if len(r) > 2 else ''
                dcontext = r[3].strip() if len(r) > 3 else ''
                clid = r[4].strip() if len(r) > 4 else ''
                channel = r[5].strip() if len(r) > 5 else ''
                dstchannel = r[6].strip() if len(r) > 6 else ''
                lastapp = r[7].strip() if len(r) > 7 else ''
                lastdata = r[8].strip() if len(r) > 8 else ''
                duration = int(r[12]) if len(r) > 12 and r[12].isdigit() else 0
                billsec = int(r[13]) if len(r) > 13 and r[13].isdigit() else 0
                disposition = r[14].strip() if len(r) > 14 else ''
                amaflags = int(r[15]) if len(r) > 15 and r[15].isdigit() else 3
                uniqueid = r[16].strip() if len(r) > 16 else ''
                userfield = r[17].strip() if len(r) > 17 else ''

                batch.append((
                    calldate, clid, src, dst, dcontext, channel, dstchannel,
                    lastapp, lastdata, duration, billsec, disposition,
                    amaflags, accountcode, uniqueid, userfield
                ))
                inserted += 1

                if len(batch) >= 500:
                    cur.executemany("""
                    INSERT INTO cdr (
                        calldate, clid, src, dst, dcontext, channel, dstchannel,
                        lastapp, lastdata, duration, billsec, disposition,
                        amaflags, accountcode, uniqueid, userfield
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    """, batch)
                    batch = []

            if batch:
                cur.executemany("""
                INSERT INTO cdr (
                    calldate, clid, src, dst, dcontext, channel, dstchannel,
                    lastapp, lastdata, duration, billsec, disposition,
                    amaflags, accountcode, uniqueid, userfield
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """, batch)
            conn.commit()
    except Exception as e:
        print(f"Error syncing CDR from CSV: {e}", file=sys.stderr)
    finally:
        conn.close()

    return inserted

def get_month_consumed_seconds(target_ip, trunk_id):
    """Calculates consumed seconds for the current month for a given trunk from SQLite."""
    conn = get_connection()
    month_start = datetime.now().strftime('%Y-%m-01 00:00:00')
    cur = conn.cursor()

    effective_start = month_start
    try:
        cur.execute("SELECT quota_reset_at FROM trunks WHERE id = ?", (trunk_id,))
        row = cur.fetchone()
        if row and row['quota_reset_at']:
            rst = str(row['quota_reset_at']).strip()
            if rst and rst > month_start:
                effective_start = rst
    except Exception:
        pass

    target_host = target_ip.split(':')[0] if ':' in target_ip else target_ip

    cur.execute("""
    SELECT COALESCE(SUM(billsec), 0) as sec
    FROM cdr
    WHERE calldate >= ?
      AND disposition = 'ANSWERED'
      AND (
          channel LIKE ? OR dstchannel LIKE ? OR
          channel LIKE ? OR dstchannel LIKE ? OR
          channel LIKE ? OR dstchannel LIKE ?
      )
    """, (
        effective_start,
        f'%{target_ip}%', f'%{target_ip}%',
        f'%{target_host}%', f'%{target_host}%',
        f'%{trunk_id}-trunk%', f'%{trunk_id}-trunk%'
    ))
    row = cur.fetchone()
    sec = row['sec'] if row else 0
    conn.close()
    return int(sec)

def reset_trunk_quota(trunk_id):
    """Resets quota for a single trunk or all trunks by updating quota_reset_at in SQLite."""
    conn = get_connection()
    now_str = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    with conn:
        if trunk_id == 'all':
            conn.execute("UPDATE trunks SET quota_reset_at = ?, used_minutes = 0.0, limit_reached = 0;", (now_str,))
        else:
            conn.execute("UPDATE trunks SET quota_reset_at = ?, used_minutes = 0.0, limit_reached = 0 WHERE id = ?;", (now_str, trunk_id))
    conn.close()
    return now_str

def get_country_records():
    """Calculates inbound consumed minutes and calls for Cuba, Mali, and Senegal from SQLite."""
    conn = get_connection()
    month_start = datetime.now().strftime('%Y-%m-01 00:00:00')
    cur = conn.cursor()

    records = {
        'mali': {'name': 'Mali', 'code': '223', 'prefix': '2237', 'flag': 'ML', 'context': 'testcall2', 'calls': 0, 'answered': 0, 'minutes': 0.0, 'active': 0},
        'cuba': {'name': 'Cuba', 'code': '53', 'prefix': '4444, 53', 'flag': 'CU', 'context': 'testcall', 'calls': 0, 'answered': 0, 'minutes': 0.0, 'active': 0},
        'senegal': {'name': 'Senegal', 'code': '221', 'prefix': '619334, 221', 'flag': 'SN', 'context': 'testcall', 'calls': 0, 'answered': 0, 'minutes': 0.0, 'active': 0}
    }

    # Fetch quota reset timestamps if any
    trunk_resets = {}
    try:
        cur.execute("SELECT id, quota_reset_at FROM trunks")
        for tr in cur.fetchall():
            if tr['quota_reset_at']:
                rst = str(tr['quota_reset_at']).strip()
                if rst and rst > month_start:
                    trunk_resets[tr['id']] = rst
    except Exception:
        pass

    cur.execute("""
    SELECT 
        calldate, dst, channel, userfield, disposition, billsec
    FROM cdr
    WHERE calldate >= ?
      AND (
          channel LIKE '%trunk-inbound%' OR channel LIKE '%trunk-13%' OR userfield LIKE 'inbound:%'
      )
    """, (month_start,))

    for r in cur.fetchall():
        calldate = str(r['calldate'])
        userfield = r['userfield'] or ''
        did = ''
        if userfield.startswith('inbound:'):
            u_parts = userfield.split(':')
            if len(u_parts) >= 2:
                did = u_parts[1]
        if not did:
            did = r['dst']

        clean = re.sub(r'[^0-9]', '', str(did or ''))
        ck = None
        if clean.startswith('2237') or clean.startswith('223'):
            ck = 'mali'
        elif clean.startswith('444453') or clean.startswith('4444') or clean.startswith('53'):
            ck = 'cuba'
        elif clean.startswith('619334221') or clean.startswith('619334') or clean.startswith('221'):
            ck = 'senegal'

        if ck:
            # If trunk had quota reset after month_start, ignore calls prior to reset
            if ck in trunk_resets and calldate < trunk_resets[ck]:
                continue
            records[ck]['calls'] += 1
            if r['disposition'] == 'ANSWERED':
                records[ck]['answered'] += 1
                records[ck]['minutes'] = round(records[ck]['minutes'] + (r['billsec'] or 0) / 60.0, 2)

    conn.close()
    return records

def get_all_trunks():
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM trunks")
    rows = []
    for r in cur.fetchall():
        d = dict(r)
        host = d.get('target_ip', '')
        port = int(d.get('sip_port') or 5060)
        if ':' in host:
            parts = host.split(':', 1)
            host = parts[0]
            if not d.get('sip_port') and parts[1].isdigit():
                port = int(parts[1])
        d['sip_port'] = port
        d['target_ip'] = f"{host}:{port}" if host else ""
        rows.append(d)
    conn.close()
    return rows

def get_all_gateways():
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM gateways WHERE is_active = 1")
    rows = []
    for r in cur.fetchall():
        d = dict(r)
        d['port'] = int(d.get('port') or 5060)
        rows.append(d)
    conn.close()
    return rows

def get_inbound_config_dict():
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT key, value FROM inbound_config")
    d = {r['key']: r['value'] for r in cur.fetchall()}
    conn.close()
    return d

# ==============================================================================
# CUSTOMER & VENDOR TRAFFIC MANAGEMENT MODULE (ASTERISK 23 PJSIP)
# ==============================================================================

def get_customers():
    """Returns all customers with aggregated IP addresses and prefixes."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM customers ORDER BY name ASC")
    customers = []
    for r in cur.fetchall():
        c = dict(r)
        cid = c['id']
        cur.execute("SELECT ip_address, port FROM customer_ips WHERE customer_id = ?", (cid,))
        ips = [dict(ip_row) for ip_row in cur.fetchall()]
        cur.execute("SELECT prefix, country_code FROM customer_prefixes WHERE customer_id = ?", (cid,))
        pfxs = [dict(pfx_row) for pfx_row in cur.fetchall()]

        c['ips'] = ips
        c['ip'] = ips[0]['ip_address'] if ips else ''
        c['port'] = ips[0]['port'] if ips else 5060
        c['prefixes'] = pfxs
        c['prefix'] = ', '.join([p['prefix'] for p in pfxs if p.get('prefix')])
        c['country_code'] = pfxs[0]['country_code'] if pfxs else ''
        customers.append(c)
    conn.close()
    return customers

def get_customer(cid):
    """Returns single customer by id with ips and prefixes."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM customers WHERE id = ?", (cid,))
    row = cur.fetchone()
    if not row:
        conn.close()
        return None
    c = dict(row)
    cur.execute("SELECT ip_address, port FROM customer_ips WHERE customer_id = ?", (cid,))
    c['ips'] = [dict(r) for r in cur.fetchall()]
    cur.execute("SELECT prefix, country_code FROM customer_prefixes WHERE customer_id = ?", (cid,))
    c['prefixes'] = [dict(r) for r in cur.fetchall()]
    conn.close()
    return c

def save_customer(d):
    """Creates or updates a customer and associates their IP addresses and prefixes."""
    conn = get_connection()
    cur = conn.cursor()
    cid = str(d.get('id', '')).strip()
    name = str(d.get('name', '')).strip()
    channels = int(d.get('allocated_channels', 50) or 50)
    status = str(d.get('status', 'active')).strip().lower()
    desc = str(d.get('description', '')).strip()

    if not name:
        raise ValueError("Customer name is required")
    if not cid:
        cid = 'cust-' + re.sub(r'[^a-z0-9]', '', name.lower()) + '-' + str(int(datetime.now().timestamp()))

    with conn:
        conn.execute("""
        INSERT INTO customers (id, name, allocated_channels, status, description, updated_at)
        VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(id) DO UPDATE SET
            name = excluded.name,
            allocated_channels = excluded.allocated_channels,
            status = excluded.status,
            description = excluded.description,
            updated_at = CURRENT_TIMESTAMP
        """, (cid, name, channels, status, desc))

        # Replace IPs
        conn.execute("DELETE FROM customer_ips WHERE customer_id = ?", (cid,))
        ips_raw = d.get('ips', [])
        if not ips_raw and d.get('ip'):
            ips_raw = [{'ip_address': d.get('ip'), 'port': int(d.get('port') or 5060)}]
        for item in ips_raw:
            ip_val = str(item.get('ip_address') or item.get('ip') or '').strip()
            port_val = int(item.get('port') or 5060)
            if ':' in ip_val:
                ip_parts = ip_val.split(':', 1)
                ip_val = ip_parts[0]
                if ip_parts[1].isdigit():
                    port_val = int(ip_parts[1])
            if ip_val:
                conn.execute("INSERT INTO customer_ips (customer_id, ip_address, port) VALUES (?, ?, ?)",
                             (cid, ip_val, port_val))

        # Replace Prefixes
        conn.execute("DELETE FROM customer_prefixes WHERE customer_id = ?", (cid,))
        pfx_raw = d.get('prefixes', [])
        if not pfx_raw and (d.get('prefix') or d.get('country_code')):
            raw_pfx = str(d.get('prefix', '')).replace(';', ',').split(',')
            cc_val = str(d.get('country_code', '')).strip()
            for p in raw_pfx:
                clean_p = re.sub(r'[^0-9]', '', p.strip())
                if clean_p:
                    conn.execute("INSERT INTO customer_prefixes (customer_id, prefix, country_code) VALUES (?, ?, ?)",
                                 (cid, clean_p, cc_val))
        else:
            for item in pfx_raw:
                p_val = re.sub(r'[^0-9]', '', str(item.get('prefix') or '').strip())
                cc_val = re.sub(r'[^0-9]', '', str(item.get('country_code') or '').strip())
                if p_val:
                    conn.execute("INSERT INTO customer_prefixes (customer_id, prefix, country_code) VALUES (?, ?, ?)",
                                 (cid, p_val, cc_val))

    conn.close()
    return get_customer(cid)

def delete_customer(cid):
    """Deletes customer, IPs, prefixes, and mappings."""
    conn = get_connection()
    with conn:
        conn.execute("DELETE FROM customers WHERE id = ?", (cid,))
        conn.execute("DELETE FROM customer_ips WHERE customer_id = ?", (cid,))
        conn.execute("DELETE FROM customer_prefixes WHERE customer_id = ?", (cid,))
        conn.execute("DELETE FROM customer_vendor_mappings WHERE customer_id = ?", (cid,))
    conn.close()
    return True

def set_customer_status(cid, status, operator='admin'):
    """Sets customer status to active or blocked immediately."""
    status = 'blocked' if status == 'blocked' else 'active'
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S') if status == 'blocked' else None
    blocked_by = operator if status == 'blocked' else ''
    conn = get_connection()
    with conn:
        conn.execute("""
        UPDATE customers
        SET status = ?, blocked_by = ?, blocked_at = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        """, (status, blocked_by, now, cid))
    conn.close()
    return True

def get_vendors():
    """Returns all vendors with aggregated IP addresses and prefixes."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM vendors ORDER BY name ASC")
    vendors = []
    for r in cur.fetchall():
        v = dict(r)
        vid = v['id']
        cur.execute("SELECT ip_address, port FROM vendor_ips WHERE vendor_id = ?", (vid,))
        ips = [dict(ip_row) for ip_row in cur.fetchall()]
        cur.execute("SELECT prefix, country_code FROM vendor_prefixes WHERE vendor_id = ?", (vid,))
        pfxs = [dict(pfx_row) for pfx_row in cur.fetchall()]

        v['ips'] = ips
        v['ip'] = ips[0]['ip_address'] if ips else ''
        v['port'] = ips[0]['port'] if ips else 5060
        v['prefixes'] = pfxs
        v['prefix'] = pfxs[0]['prefix'] if pfxs else ''
        v['country_code'] = pfxs[0]['country_code'] if pfxs else ''
        vendors.append(v)
    conn.close()
    return vendors

def get_vendor(vid):
    """Returns single vendor by id."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM vendors WHERE id = ?", (vid,))
    row = cur.fetchone()
    if not row:
        conn.close()
        return None
    v = dict(row)
    cur.execute("SELECT ip_address, port FROM vendor_ips WHERE vendor_id = ?", (vid,))
    v['ips'] = [dict(r) for r in cur.fetchall()]
    cur.execute("SELECT prefix, country_code FROM vendor_prefixes WHERE vendor_id = ?", (vid,))
    v['prefixes'] = [dict(r) for r in cur.fetchall()]
    conn.close()
    return v

def save_vendor(d):
    """Creates or updates a vendor."""
    conn = get_connection()
    vid = str(d.get('id', '')).strip()
    name = str(d.get('name', '')).strip()
    channels = int(d.get('max_channels', 100) or 100)
    cli_format = str(d.get('caller_id_format', '')).strip()
    status = str(d.get('status', 'active')).strip().lower()
    desc = str(d.get('description', '')).strip()

    if not name:
        raise ValueError("Vendor name is required")
    if not vid:
        vid = 'vend-' + re.sub(r'[^a-z0-9]', '', name.lower()) + '-' + str(int(datetime.now().timestamp()))

    with conn:
        conn.execute("""
        INSERT INTO vendors (id, name, max_channels, caller_id_format, status, description, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(id) DO UPDATE SET
            name = excluded.name,
            max_channels = excluded.max_channels,
            caller_id_format = excluded.caller_id_format,
            status = excluded.status,
            description = excluded.description,
            updated_at = CURRENT_TIMESTAMP
        """, (vid, name, channels, cli_format, status, desc))

        conn.execute("DELETE FROM vendor_ips WHERE vendor_id = ?", (vid,))
        ips_raw = d.get('ips', [])
        if not ips_raw and d.get('ip'):
            ips_raw = [{'ip_address': d.get('ip'), 'port': int(d.get('port') or 5060)}]
        for item in ips_raw:
            ip_val = str(item.get('ip_address') or item.get('ip') or '').strip()
            port_val = int(item.get('port') or 5060)
            if ':' in ip_val:
                ip_parts = ip_val.split(':', 1)
                ip_val = ip_parts[0]
                if ip_parts[1].isdigit():
                    port_val = int(ip_parts[1])
            if ip_val:
                conn.execute("INSERT INTO vendor_ips (vendor_id, ip_address, port) VALUES (?, ?, ?)",
                             (vid, ip_val, port_val))

        conn.execute("DELETE FROM vendor_prefixes WHERE vendor_id = ?", (vid,))
        p_val = re.sub(r'[^0-9]', '', str(d.get('prefix') or '').strip())
        cc_val = re.sub(r'[^0-9]', '', str(d.get('country_code') or '').strip())
        if p_val or cc_val:
            conn.execute("INSERT INTO vendor_prefixes (vendor_id, prefix, country_code) VALUES (?, ?, ?)",
                         (vid, p_val, cc_val))

    conn.close()
    return get_vendor(vid)

def delete_vendor(vid):
    """Deletes vendor, IPs, prefixes, and mappings."""
    conn = get_connection()
    with conn:
        conn.execute("DELETE FROM vendors WHERE id = ?", (vid,))
        conn.execute("DELETE FROM vendor_ips WHERE vendor_id = ?", (vid,))
        conn.execute("DELETE FROM vendor_prefixes WHERE vendor_id = ?", (vid,))
        conn.execute("DELETE FROM customer_vendor_mappings WHERE vendor_id = ?", (vid,))
    conn.close()
    return True

def set_vendor_status(vid, status, operator='admin'):
    """Sets vendor status to active or blocked immediately."""
    status = 'blocked' if status == 'blocked' else 'active'
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S') if status == 'blocked' else None
    blocked_by = operator if status == 'blocked' else ''
    conn = get_connection()
    with conn:
        conn.execute("""
        UPDATE vendors
        SET status = ?, blocked_by = ?, blocked_at = ?, updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
        """, (status, blocked_by, now, vid))
    conn.close()
    return True

def get_mappings():
    """Returns all customer-vendor mappings with customer & vendor names."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("""
    SELECT 
        m.*,
        c.name AS customer_name,
        c.status AS customer_status,
        v.name AS vendor_name,
        v.status AS vendor_status
    FROM customer_vendor_mappings m
    LEFT JOIN customers c ON m.customer_id = c.id
    LEFT JOIN vendors v ON m.vendor_id = v.id
    ORDER BY c.name ASC, m.priority ASC
    """)
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def save_mapping(d):
    """Saves or updates a customer-vendor mapping."""
    conn = get_connection()
    mid = d.get('id')
    cid = str(d.get('customer_id', '')).strip()
    vid = str(d.get('vendor_id', '')).strip()
    c_pfx = re.sub(r'[^0-9]', '', str(d.get('customer_prefix', '')).strip())
    v_pfx = re.sub(r'[^0-9]', '', str(d.get('vendor_prefix', '')).strip())
    cc = re.sub(r'[^0-9]', '', str(d.get('country_code', '')).strip())
    prio = int(d.get('priority', 1) or 1)
    status = str(d.get('status', 'active')).strip().lower()

    if not cid or not vid:
        raise ValueError("Customer ID and Vendor ID are required")

    with conn:
        if mid:
            conn.execute("""
            UPDATE customer_vendor_mappings
            SET customer_id = ?, vendor_id = ?, customer_prefix = ?, vendor_prefix = ?,
                country_code = ?, priority = ?, status = ?
            WHERE id = ?
            """, (cid, vid, c_pfx, v_pfx, cc, prio, status, mid))
        else:
            conn.execute("""
            INSERT INTO customer_vendor_mappings
            (customer_id, vendor_id, customer_prefix, vendor_prefix, country_code, priority, status)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """, (cid, vid, c_pfx, v_pfx, cc, prio, status))
    conn.close()
    return True

def delete_mapping(mid):
    """Deletes a customer-vendor mapping by ID."""
    conn = get_connection()
    with conn:
        conn.execute("DELETE FROM customer_vendor_mappings WHERE id = ?", (mid,))
    conn.close()
    return True

def toggle_mapping(mid, status):
    """Toggles mapping status active or disabled."""
    status = 'active' if status == 'active' else 'disabled'
    conn = get_connection()
    with conn:
        conn.execute("UPDATE customer_vendor_mappings SET status = ? WHERE id = ?", (status, mid))
    conn.close()
    return True

def seed_customer_vendor_defaults():
    """Seeds default demo customers, vendors, and mappings if empty."""
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*) AS cnt FROM customers")
    cust_count = cur.fetchone()['cnt']
    if cust_count == 0:
        # Seed Customers: PTCL & Ufone
        save_customer({
            'id': 'cust-ptcl',
            'name': 'PTCL',
            'ip': '192.168.1.10',
            'port': 5060,
            'prefix': '92300',
            'country_code': '92',
            'allocated_channels': 100,
            'status': 'active',
            'description': 'Primary Pakistan Telecom Customer'
        })
        save_customer({
            'id': 'cust-ufone',
            'name': 'Ufone',
            'ip': '192.168.1.20',
            'port': 5060,
            'prefix': '92301',
            'country_code': '92',
            'allocated_channels': 50,
            'status': 'active',
            'description': 'Cellular Mobile Carrier Customer'
        })

    cur.execute("SELECT COUNT(*) AS cnt FROM vendors")
    vend_count = cur.fetchone()['cnt']
    if vend_count == 0:
        # Seed Vendors: BOC, Vendor 2, Vendor 3
        save_vendor({
            'id': 'vend-boc',
            'name': 'BOC',
            'ip': '10.10.10.10',
            'port': 5060,
            'prefix': '1',
            'country_code': '1',
            'max_channels': 200,
            'status': 'active',
            'description': 'Tier 1 Wholesale Carrier'
        })
        save_vendor({
            'id': 'vend-vendor2',
            'name': 'Vendor 2',
            'ip': '10.10.10.20',
            'port': 5060,
            'prefix': '44',
            'country_code': '44',
            'max_channels': 100,
            'status': 'active',
            'description': 'Secondary Backup Voice Termination'
        })
        save_vendor({
            'id': 'vend-vendor3',
            'name': 'Vendor 3',
            'ip': '10.10.10.30',
            'port': 5060,
            'prefix': '33',
            'country_code': '33',
            'max_channels': 100,
            'status': 'active',
            'description': 'Tertiary Failover Vendor'
        })

    cur.execute("SELECT COUNT(*) AS cnt FROM customer_vendor_mappings")
    map_count = cur.fetchone()['cnt']
    if map_count == 0:
        save_mapping({
            'customer_id': 'cust-ptcl',
            'vendor_id': 'vend-boc',
            'customer_prefix': '92300',
            'vendor_prefix': '1',
            'country_code': '92',
            'priority': 1,
            'status': 'active'
        })
        save_mapping({
            'customer_id': 'cust-ptcl',
            'vendor_id': 'vend-vendor2',
            'customer_prefix': '92300',
            'vendor_prefix': '44',
            'country_code': '92',
            'priority': 2,
            'status': 'active'
        })
        save_mapping({
            'customer_id': 'cust-ufone',
            'vendor_id': 'vend-boc',
            'customer_prefix': '92301',
            'vendor_prefix': '1',
            'country_code': '92',
            'priority': 1,
            'status': 'active'
        })
    conn.close()

if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'init'
    if cmd == 'init':
        init_schema()
        seed_defaults()
        synced = sync_cdrs_from_csv()
        print(f"Database initialized at {DB_PATH}. Synced {synced} historical CDR rows.")
    elif cmd == 'migrate':
        conn = get_connection()
        ensure_schema_migrations(conn)
        conn.close()
        print(f"Database schema migrations verified at {DB_PATH}.")
    elif cmd == 'reset':
        t = sys.argv[2] if len(sys.argv) > 2 else 'all'
        reset_trunk_quota(t)
        print(f"Reset quota to 0.0 min for {t}.")
    elif cmd == 'sync':
        synced = sync_cdrs_from_csv()
        print(f"Synced {synced} new CDR rows from CSV.")
    elif cmd == 'dump':
        print(json.dumps({
            'trunks': get_all_trunks(),
            'gateways': get_all_gateways(),
            'inbound_config': get_inbound_config_dict()
        }, indent=2))

