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

    return conn

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
            prefix TEXT DEFAULT '',
            country_code TEXT NOT NULL,
            prefix_cid TEXT NOT NULL,
            monthly_limit INTEGER NOT NULL DEFAULT 100000,
            max_channels INTEGER NOT NULL DEFAULT 150,
            call_duration INTEGER NOT NULL DEFAULT 600,
            context TEXT NOT NULL DEFAULT 'testcall',
            is_running INTEGER DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        """)

        # 2. Inbound Gateways (Authorized Source IPs)
        conn.execute("""
        CREATE TABLE IF NOT EXISTS gateways (
            id TEXT PRIMARY KEY,
            ip TEXT NOT NULL UNIQUE,
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
    try:
        import grp
        gid = grp.getgrnam("apache").gr_gid
        os.chown(DB_PATH, 0, gid)
        os.chmod(DB_PATH, 0o664)
    except Exception:
        pass
    conn.close()

def seed_defaults():
    """Migrates existing JSON configs or seeds defaults into SQLite."""
    conn = get_connection()
    default_trunks = [
        ('mali', 'Mali', '192.168.1.210', '', '2237', '2237', 100000, 6, 700, 'testcall2'),
        ('cuba', 'Cuba', '192.168.1.210', '4444', '53', '444453', 3800, 100, 600, 'testcall'),
        ('senegal', 'Senegal', '178.22.15.201', '619334', '221', '619334221', 150000, 220, 600, 'testcall')
    ]

    with conn:
        for t_id, name, ip, pfx, cc, cid, lim, max_c, dur, ctx in default_trunks:
            # Check if json config exists to migrate current values
            conf_file = f"/etc/callgen-{t_id}.json"
            if os.path.exists(conf_file):
                try:
                    with open(conf_file) as f:
                        jd = json.load(f)
                        name = jd.get('name', name)
                        ip = jd.get('target_ip', ip)
                        pfx = jd.get('prefix', pfx)
                        cc = jd.get('country_code', cc)
                        cid = jd.get('prefix_cid', cid)
                        lim = int(jd.get('monthly_limit', lim))
                        max_c = int(jd.get('max_channels', max_c))
                        dur = int(jd.get('call_duration', dur))
                        ctx = jd.get('context', ctx)
                except Exception:
                    pass

            conn.execute("""
            INSERT INTO trunks (id, name, target_ip, prefix, country_code, prefix_cid, monthly_limit, max_channels, call_duration, context)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(id) DO UPDATE SET
                name=excluded.name,
                target_ip=excluded.target_ip,
                prefix=excluded.prefix,
                country_code=excluded.country_code,
                prefix_cid=excluded.prefix_cid,
                monthly_limit=excluded.monthly_limit,
                max_channels=excluded.max_channels,
                call_duration=excluded.call_duration,
                context=excluded.context,
                updated_at=CURRENT_TIMESTAMP;
            """, (t_id, name, ip, pfx, cc, cid, lim, max_c, dur, ctx))

        # Migrate Inbound Configuration
        inbound_conf_file = '/etc/callgen-inbound.json'
        inbound_data = {
            'source_ips': [
                {
                    'id': 'src-1',
                    'ip': '192.168.1.13',
                    'label': 'Node-13 Forwarder',
                    'prefix': '444453, 619334221, 2237',
                    'country_code': '223, 53, 221',
                    'context': 'auto'
                }
            ],
            'testcall_duration': 120,
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
            conn.execute("""
            INSERT INTO gateways (id, ip, label, prefix, country_code, context)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(ip) DO UPDATE SET
                label=excluded.label,
                prefix=excluded.prefix,
                country_code=excluded.country_code,
                context=excluded.context,
                updated_at=CURRENT_TIMESTAMP;
            """, (
                s.get('id', f'src-{sip.replace(".", "-")}'),
                sip,
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
    cur.execute("""
    SELECT COALESCE(SUM(billsec), 0) as sec
    FROM cdr
    WHERE calldate >= ?
      AND disposition = 'ANSWERED'
      AND (
          channel LIKE ? OR dstchannel LIKE ? OR
          channel LIKE ? OR dstchannel LIKE ?
      )
    """, (
        month_start,
        f'%{target_ip}%', f'%{target_ip}%',
        f'%{trunk_id}-trunk%', f'%{trunk_id}-trunk%'
    ))
    row = cur.fetchone()
    sec = row['sec'] if row else 0
    conn.close()
    return int(sec)

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

    cur.execute("""
    SELECT 
        dst, channel, userfield, disposition, billsec
    FROM cdr
    WHERE calldate >= ?
      AND (
          channel LIKE '%trunk-inbound%' OR channel LIKE '%trunk-13%' OR userfield LIKE 'inbound:%'
      )
    """, (month_start,))

    for r in cur.fetchall():
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
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def get_all_gateways():
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT * FROM gateways WHERE is_active = 1")
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def get_inbound_config_dict():
    conn = get_connection()
    cur = conn.cursor()
    cur.execute("SELECT key, value FROM inbound_config")
    d = {r['key']: r['value'] for r in cur.fetchall()}
    conn.close()
    return d

if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'init'
    if cmd == 'init':
        init_schema()
        seed_defaults()
        synced = sync_cdrs_from_csv()
        print(f"Database initialized at {DB_PATH}. Synced {synced} historical CDR rows.")
    elif cmd == 'sync':
        synced = sync_cdrs_from_csv()
        print(f"Synced {synced} new CDR rows from CSV.")
    elif cmd == 'dump':
        print(json.dumps({
            'trunks': get_all_trunks(),
            'gateways': get_all_gateways(),
            'inbound_config': get_inbound_config_dict()
        }, indent=2))

