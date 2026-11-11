#!/usr/bin/env python3
# ==============================================================================
# M5-Traffic Controller: Inbound PJSIP & Dialplan Synchronizer
# Enforces strict per-IP endpoint isolation and prefix routing to [testcall]/[testcall2].
# Any unlisted IP or unallowed prefix is strictly rejected with Hangup(21) (SIP 603 Declined).
# ==============================================================================

import json, os, subprocess, re, sys

# Connect to database module if available
try:
    import callgen_db
    DB_MODE = True
except Exception:
    DB_MODE = False

INBOUND_CONF = '/etc/callgen-inbound.json'

default_inbound = {
    "source_ips": [
        {
            "id": "src-1",
            "ip": "192.168.1.13",
            "label": "Node-13 Forwarder",
            "prefix": "444453, 619334221, 2237",
            "country_code": "",
            "context": "auto"
        }
    ],
    "source_ip": "192.168.1.13",
    "testcall_duration": 120,
    "testcall2_duration": 50,
    "testcall2_prefixes": "2237",
    "testcall_prefixes": "444453, 619334221"
}

cfg = dict(default_inbound)

if DB_MODE:
    try:
        db_gws = callgen_db.get_all_gateways()
        db_inbound = callgen_db.get_inbound_config_dict()
        if db_gws:
            cfg['source_ips'] = db_gws
        if db_inbound:
            cfg.update(db_inbound)
    except Exception as e:
        print(f"Notice: database read fallback to json: {e}")

if not DB_MODE or not cfg.get('source_ips'):
    if os.path.exists(INBOUND_CONF):
        try:
            with open(INBOUND_CONF, 'r') as f:
                loaded = json.load(f)
                if isinstance(loaded, dict):
                    cfg.update(loaded)
        except Exception as e:
            print(f"Error loading {INBOUND_CONF}: {e}")

# Helper: parse prefix list
def parse_pfx_list(raw):
    res = []
    for item in str(raw or '').replace(';', ',').split(','):
        clean = re.sub(r'[^0-9]', '', item.strip())
        if clean and clean not in res:
            res.append(clean)
    return res

# 1. Load Trunk Route Configurations for auto-detection
def get_trunk_contexts():
    routes = {}
    if DB_MODE:
        try:
            for td in callgen_db.get_all_trunks():
                t = td.get('id', '')
                ctx = td.get('context', 'testcall2' if t == 'mali' else 'testcall')
                cid = td.get('prefix_cid') or (td.get('prefix', '') + td.get('country_code', ''))
                cc = td.get('country_code', '')
                pfx = td.get('prefix', '')
                for k in [cid, cc, pfx]:
                    clean = re.sub(r'[^0-9]', '', str(k or ''))
                    if clean:
                        routes[clean] = ctx
        except Exception:
            pass

    if not routes:
        for t in ['mali', 'cuba', 'senegal']:
            p = f'/etc/callgen-{t}.json'
            if os.path.exists(p):
                try:
                    with open(p) as f:
                        td = json.load(f)
                        ctx = td.get('context', 'testcall2' if t == 'mali' else 'testcall')
                        cid = td.get('prefix_cid') or (td.get('prefix', '') + td.get('country_code', ''))
                        cc = td.get('country_code', '')
                        pfx = td.get('prefix', '')
                        for k in [cid, cc, pfx]:
                            clean = re.sub(r'[^0-9]', '', str(k or ''))
                            if clean:
                                routes[clean] = ctx
                except Exception:
                    pass

    defaults = {
        '2237': 'testcall2', '223': 'testcall2',
        '444453': 'testcall', '4444': 'testcall', '53': 'testcall',
        '619334221': 'testcall', '619334': 'testcall', '221': 'testcall'
    }
    for k, v in defaults.items():
        if k not in routes:
            routes[k] = v
    return routes

trunk_routes = get_trunk_contexts()

def resolve_context_for_prefix(pfx, preferred_ctx='auto'):
    if preferred_ctx in ['testcall', 'testcall2']:
        return preferred_ctx
    if pfx in trunk_routes:
        return trunk_routes[pfx]
    for k, ctx in trunk_routes.items():
        if pfx.startswith(k) or k.startswith(pfx):
            return ctx
    if pfx.startswith('223'):
        return 'testcall2'
    return 'testcall'

# 2. Extract Duration limits
try:
    testcall_dur = int(cfg.get('testcall_duration', 60))
    if testcall_dur < 1: testcall_dur = 60
except Exception:
    testcall_dur = 60

try:
    testcall2_dur = int(cfg.get('testcall2_duration', 50))
    if testcall2_dur < 1: testcall2_dur = 50
except Exception:
    testcall2_dur = 50

# 3. Collect authorized Source IPs and per-IP prefix restrictions
source_ips_raw = cfg.get('source_ips') or []
if not isinstance(source_ips_raw, list) or len(source_ips_raw) == 0:
    sip = str(cfg.get('source_ip', '192.168.1.13')).strip()
    source_ips_raw = [{'id': 'src-1', 'ip': sip or '192.168.1.13', 'prefix': cfg.get('testcall_prefixes', ''), 'context': 'auto'}]

global_testcall_pfx = parse_pfx_list(cfg.get('testcall_prefixes', ''))
global_testcall2_pfx = parse_pfx_list(cfg.get('testcall2_prefixes', ''))

gateways = []
all_authorized_ips = []

for idx, s in enumerate(source_ips_raw):
    if not isinstance(s, dict):
        continue
    ip = str(s.get('ip', '')).strip()
    if not ip or not re.match(r'^[A-Za-z0-9.:-]+$', ip):
        continue
    if ip in all_authorized_ips:
        continue
    all_authorized_ips.append(ip)

    gid = str(s.get('id', f'src-{idx+1}')).strip() or f'src-{idx+1}'
    label = str(s.get('label', f'Gateway {idx+1}')).strip()
    ctx_mode = str(s.get('context', 'auto')).strip().lower()

    gw_port = int(s.get('port') or 5060)
    if ':' in ip:
        parts = ip.split(':', 1)
        ip = parts[0]
        if not s.get('port') and parts[1].isdigit():
            gw_port = int(parts[1])
    if gw_port < 1 or gw_port > 65535:
        gw_port = 5060

    # Prefixes explicitly allowed for this source IP (merge prefix and country_code fields)
    raw_pfx = f"{s.get('prefix', '')}, {s.get('country_code', '')}"
    pfx_list = parse_pfx_list(raw_pfx)

    # If no prefix specified for this gateway, fallback to global breakout prefixes
    if not pfx_list:
        if ctx_mode == 'testcall':
            pfx_list = list(global_testcall_pfx)
        elif ctx_mode == 'testcall2':
            pfx_list = list(global_testcall2_pfx)
        else:
            pfx_list = list(dict.fromkeys(global_testcall_pfx + global_testcall2_pfx))

    # Map each prefix to its target context (testcall or testcall2)
    pfx_map = {}
    for p in pfx_list:
        dest_ctx = resolve_context_for_prefix(p, ctx_mode)
        pfx_map[p] = dest_ctx

    gateways.append({
        'id': gid,
        'index': idx + 1,
        'ip': ip,
        'port': gw_port,
        'label': label,
        'context_mode': ctx_mode,
        'prefixes': pfx_map
    })

# 4. Generate /etc/asterisk/pjsip_inbound.conf
pjsip_lines = [
    "; ==============================================================================",
    "; Dynamic Inbound PJSIP Configuration (Managed by callgen-sync-inbound.py)",
    f"; Authorized Source IPs ({len(gateways)}): {', '.join([g['ip'] for g in gateways])}",
    "; Each authorized IP has its own isolated endpoint, AOR, and identify mapping.",
    "; NO fallback or aggregated identify is used to prevent prefix leakage.",
    "; ==============================================================================",
    ""
]

# Dedicated endpoint per authorized gateway
for gw in gateways:
    ep_name = f"trunk-inbound-{gw['index']}"
    gw_ctx = f"from-inbound-{gw['index']}"
    gw_host = gw['ip']
    gw_port = int(gw.get('port') or 5060)
    if ':' in gw_host:
        parts = gw_host.split(':', 1)
        gw_host = parts[0]
        if not gw.get('port') and parts[1].isdigit():
            gw_port = int(parts[1])
    contact_uri = f"sip:{gw_host}:{gw_port}"
    pjsip_lines.extend([
        f"; Authorized Gateway #{gw['index']}: {gw_host}:{gw_port} ({gw['label']})",
        f"[{ep_name}]",
        "type=endpoint",
        "transport=transport-udp",
        f"context={gw_ctx}",
        "disallow=all",
        "allow=ulaw,alaw,g729,gsm,g722",
        f"aors={ep_name}",
        "direct_media=no",
        "force_rport=yes",
        "rewrite_contact=yes",
        "rtp_symmetric=yes",
        "",
        f"[{ep_name}]",
        "type=aor",
        f"contact={contact_uri}",
        "",
        f"[{ep_name}]",
        "type=identify",
        f"endpoint={ep_name}",
        f"match={gw_host}",
        ""
    ])

with open('/etc/asterisk/pjsip_inbound.conf', 'w') as f:
    f.write('\n'.join(pjsip_lines) + '\n')
os.chmod('/etc/asterisk/pjsip_inbound.conf', 0o644)

# 5. Dialplan Generator Helper
def build_gateway_context(ctx_name, pfx_map, ip_desc):
    lines = [
        f"[{ctx_name}]",
        f"; Routing for: {ip_desc}"
    ]

    sorted_pfx = sorted(pfx_map.keys(), key=len, reverse=True)

    if not sorted_pfx:
        lines.append(f"; WARNING: No allowed prefixes configured for {ip_desc} - all inbound calls will be rejected")
    else:
        for p in sorted_pfx:
            dest = pfx_map[p]
            lines.append(f"; Route prefix {p} to [{dest}]")
            lines.append(f"exten => _{p}.,1,NoOp(==============================================================)")
            lines.append(f"same => n,NoOp(>>> ACCEPTED: CALL MATCHING ALLOWED PREFIX {p} -> ROUTING TO {dest} <<<)")
            lines.append(f"same => n,NoOp(CallerID: ${{CALLERID(all)}} | UniqueID: ${{UNIQUEID}} | Source: {ip_desc})")
            lines.append(f"same => n,NoOp(==============================================================)")
            lines.append(f"same => n,Set(__INBOUND_DID=${{EXTEN}})")
            lines.append(f"same => n,Set(CHANNEL(accountcode)=${{EXTEN}})")
            lines.append(f"same => n,Set(CDR(userfield)=inbound:${{EXTEN}}:{dest})")
            lines.append(f"same => n,Goto({dest},s,1)")
            # Exact number match
            lines.append(f"exten => {p},1,Goto({dest},s,1)")
            # E.164 (+) strip leading + and re-enter this gateway's context
            lines.append(f"exten => _+{p}.,1,Goto({ctx_name},${{EXTEN:1}},1)")
            lines.append(f"exten => +{p},1,Goto({ctx_name},${{EXTEN:1}},1)")
            # International 00 strip and re-enter this gateway's context
            lines.append(f"exten => _00{p}.,1,Goto({ctx_name},${{EXTEN:2}},1)")
            lines.append(f"exten => 00{p},1,Goto({ctx_name},${{EXTEN:2}},1)")

    # STRICT REJECTION: Reject any extension not explicitly matching allowed prefixes
    lines.extend([
        "; ==============================================================================",
        f"; STRICT REJECTION: Reject any number NOT in allowed prefix list for {ip_desc}",
        "; ==============================================================================",
        "exten => _X.,1,NoOp(==============================================================)",
        f"same => n,NoOp(>>> REJECTED: INCOMING NUMBER ${{EXTEN}} NOT ALLOWED FROM {ip_desc} <<<)",
        "same => n,NoOp(CallerID: ${CALLERID(all)} | Hangup Cause: 21 Rejected)",
        "same => n,NoOp(==============================================================)",
        "same => n,Set(CDR(userfield)=inbound_rejected:${EXTEN})",
        "same => n,Hangup(21)",
        "",
        "exten => _+X.,1,NoOp(==============================================================)",
        f"same => n,NoOp(>>> REJECTED: INCOMING NUMBER ${{EXTEN}} NOT ALLOWED FROM {ip_desc} <<<)",
        "same => n,NoOp(CallerID: ${CALLERID(all)} | Hangup Cause: 21 Rejected)",
        "same => n,NoOp(==============================================================)",
        "same => n,Set(CDR(userfield)=inbound_rejected:${EXTEN})",
        "same => n,Hangup(21)",
        "",
        "exten => _00X.,1,NoOp(==============================================================)",
        f"same => n,NoOp(>>> REJECTED: INCOMING NUMBER ${{EXTEN}} NOT ALLOWED FROM {ip_desc} <<<)",
        "same => n,NoOp(CallerID: ${CALLERID(all)} | Hangup Cause: 21 Rejected)",
        "same => n,NoOp(==============================================================)",
        "same => n,Set(CDR(userfield)=inbound_rejected:${EXTEN})",
        "same => n,Hangup(21)",
        "",
        "exten => _*X.,1,Hangup(21)",
        "exten => _!,1,Hangup(21)",
        "exten => s,1,NoOp(>>> REJECTED: INCOMING CALL ON 's' WITHOUT ALLOWED PREFIX <<<)",
        "same => n,Set(CDR(userfield)=inbound_rejected:s)",
        "same => n,Hangup(21)",
        "",
        "exten => i,1,Hangup(21)",
        "exten => e,1,Hangup(21)",
        "exten => T,1,Hangup()",
        "exten => t,1,Hangup()",
        "exten => h,1,Hangup()",
        ""
    ])
    return lines

# 6. Generate /etc/asterisk/extensions_inbound.conf
ext_lines = [
    "; ==============================================================================",
    "; Dynamic Inbound Dialplan & Contexts (Managed by callgen-sync-inbound.py)",
    f"; testcall duration: {testcall_dur}s | testcall2 duration: {testcall2_dur}s",
    "; ==============================================================================",
    "",
    "[testcall]",
    f"exten => s,1,NoOp(>>> M5 Inbound: Connected [testcall] - Duration limit: {testcall_dur}s <<<)",
    "same => n,Answer()",
    f"same => n,Set(TIMEOUT(absolute)={testcall_dur})",
    "same => n,Playback(silence/1)",
    f"same => n,Wait({testcall_dur})",
    "same => n,Hangup()",
    "exten => T,1,Hangup()",
    "exten => t,1,Hangup()",
    "exten => h,1,Hangup()",
    "exten => _X.,1,Goto(s,1)",
    "exten => _+X.,1,Goto(s,1)",
    "",
    "[testcall2]",
    f"exten => s,1,NoOp(>>> M5 Inbound: Connected [testcall2] - Duration limit: {testcall2_dur}s <<<)",
    "same => n,Answer()",
    f"same => n,Set(TIMEOUT(absolute)={testcall2_dur})",
    "same => n,Playback(silence/1)",
    f"same => n,Wait({testcall2_dur})",
    "same => n,Hangup()",
    "exten => T,1,Hangup()",
    "exten => t,1,Hangup()",
    "exten => h,1,Hangup()",
    "exten => _X.,1,Goto(s,1)",
    "exten => _+X.,1,Goto(s,1)",
    "",
    "; ==============================================================================",
    "; STRICT REJECTION FOR UNLISTED / UNAUTHORIZED SOURCE IPS",
    "; Any IP not explicitly configured in the database is terminated with Hangup(21)",
    "; ==============================================================================",
    "[unauthorized-ip-blocked]",
    "exten => _!,1,NoOp(==============================================================)",
    "same => n,NoOp(>>> SECURITY ALERT: INCOMING CALL REJECTED FROM UNLISTED IP ${CHANNEL(recvip)} <<<)",
    "same => n,NoOp(CallerID: ${CALLERID(all)} | Dialed: ${EXTEN} | Cause: 21 Rejected)",
    "same => n,NoOp(==============================================================)",
    "same => n,Set(CDR(userfield)=unlisted_ip_rejected:${CHANNEL(recvip)}:${EXTEN})",
    "same => n,Hangup(21)",
    "",
    "exten => _X.,1,Set(CDR(userfield)=unlisted_ip_rejected:${CHANNEL(recvip)}:${EXTEN})",
    "same => n,Hangup(21)",
    "",
    "exten => _+X.,1,Set(CDR(userfield)=unlisted_ip_rejected:${CHANNEL(recvip)}:${EXTEN})",
    "same => n,Hangup(21)",
    "",
    "exten => s,1,NoOp(==============================================================)",
    "same => n,NoOp(>>> SECURITY ALERT: INCOMING CALL REJECTED FROM UNLISTED IP ${CHANNEL(recvip)} ON 's' <<<)",
    "same => n,NoOp(==============================================================)",
    "same => n,Set(CDR(userfield)=unlisted_ip_rejected:${CHANNEL(recvip)}:s)",
    "same => n,Hangup(21)",
    "",
    "exten => i,1,Hangup(21)",
    "exten => e,1,Hangup(21)",
    "exten => t,1,Hangup()",
    "exten => T,1,Hangup()",
    "exten => h,1,Hangup()",
    ""
]

# Dedicated isolated context per gateway
gw13_index = None
for gw in gateways:
    if gw['ip'] == '192.168.1.13':
        gw13_index = gw['index']
    ext_lines.extend(build_gateway_context(f"from-inbound-{gw['index']}", gw['prefixes'], f"{gw['ip']} ({gw['label']})"))

# Compatibility contexts
ext_lines.extend([
    "; Dedicated compatibility context for Node-13",
    "[from-13]",
    f"include => from-inbound-{gw13_index if gw13_index else 1}",
    "",
    "; Combined fallback context [from-inbound]",
    "[from-inbound]",
    "include => from-inbound-1",
    ""
])

with open('/etc/asterisk/extensions_inbound.conf', 'w') as f:
    f.write('\n'.join(ext_lines) + '\n')
os.chmod('/etc/asterisk/extensions_inbound.conf', 0o644)

# 6b. Keep /etc/asterisk/pjsip.conf trunk AOR and identify contacts in sync with trunk settings
pjsip_main_file = '/etc/asterisk/pjsip.conf'
if os.path.exists(pjsip_main_file):
    try:
        with open(pjsip_main_file, 'r') as pf:
            main_cfg = pf.read()
        trunks_info = {}
        if DB_MODE:
            try:
                for td in callgen_db.get_all_trunks():
                    trunks_info[td['id']] = td
            except Exception:
                pass
        for t in ['mali', 'cuba', 'senegal']:
            tip = ""
            tport = 5060
            if t in trunks_info:
                tip = trunks_info[t].get('target_ip', '')
                tport = int(trunks_info[t].get('sip_port') or 5060)
            elif os.path.exists(f'/etc/callgen-{t}.json'):
                try:
                    with open(f'/etc/callgen-{t}.json') as jf:
                        jd = json.load(jf)
                        tip = jd.get('target_ip', '')
                        tport = int(jd.get('sip_port') or 5060)
                except Exception:
                    pass
            if tip:
                thost = tip.split(':')[0] if ':' in tip else tip
                if ':' in tip:
                    p_part = tip.split(':')[1]
                    if p_part.isdigit(): tport = int(p_part)
                if not tport or tport < 1 or tport > 65535: tport = 5060
                main_cfg = re.sub(
                    rf'(\[{t}-trunk\][\s\S]*?type=aor[\s\S]*?contact=sip:)[^\s\n]+',
                    rf'\g<1>{thost}:{tport}',
                    main_cfg,
                    count=1
                )
                main_cfg = re.sub(
                    rf'(\[{t}-trunk\][\s\S]*?type=identify[\s\S]*?match=)[^\s\n]+',
                    rf'\g<1>{thost}',
                    main_cfg,
                    count=1
                )
        with open(pjsip_main_file, 'w') as pf:
            pf.write(main_cfg)
    except Exception as e:
        print(f"Notice: sync trunk endpoints in pjsip.conf: {e}")

# 7. Apply Customer & Vendor Routing (Asterisk 23 PJSIP)
def sync_customer_vendor_routing():
    customers = []
    vendors = []
    mappings = []
    if DB_MODE:
        try:
            customers = callgen_db.get_customers()
            vendors = callgen_db.get_vendors()
            mappings = callgen_db.get_mappings()
        except Exception as e:
            print(f"Notice: unable to load customer/vendor data from DB: {e}")

    if not customers:
        cfg_paths = [
            os.path.join(os.path.dirname(__file__), '..', '..', 'callgen-config.json'),
            os.path.join(os.path.dirname(__file__), '..', 'callgen-config.json'),
            '/var/lib/callgen/callgen-config.json',
            '/etc/callgen-config.json'
        ]
        for cp in cfg_paths:
            if os.path.exists(cp):
                try:
                    with open(cp, 'r') as f:
                        jd = json.load(f)
                        customers = jd.get('_customers', [])
                        vendors = jd.get('_vendors', [])
                        mappings = jd.get('_mappings', [])
                        if customers:
                            break
                except Exception:
                    pass

    # A. Generate /etc/asterisk/pjsip_customer_vendor.conf
    pjsip_cv_lines = [
        "; ==============================================================================",
        "; Asterisk 23 Customer & Vendor PJSIP Configuration",
        "; Dynamically generated by callgen-sync-inbound.py",
        "; ==============================================================================",
        ""
    ]

    # Customers (Ingress)
    for c in customers:
        cid = re.sub(r'[^a-zA-Z0-9_-]', '', str(c['id']))
        ep_name = f"cust-{cid}"
        ctx_name = f"from-cust-{cid}"
        ips = [str(ip_row.get('ip_address', '')).strip() for ip_row in c.get('ips', []) if str(ip_row.get('ip_address', '')).strip()]
        if not ips and c.get('ip'):
            ips = [str(c['ip']).strip()]
        if not ips:
            continue
        pjsip_cv_lines.extend([
            f"; Customer: {c['name']} (ID: {cid}, Status: {c.get('status', 'active')})",
            f"[{ep_name}]",
            "type=endpoint",
            "transport=transport-udp",
            f"context={ctx_name}",
            "disallow=all",
            "allow=ulaw,alaw,g729,gsm,g722",
            "direct_media=no",
            "force_rport=yes",
            "rewrite_contact=yes",
            "rtp_symmetric=yes",
            "",
            f"[{ep_name}]",
            "type=identify",
            f"endpoint={ep_name}",
            f"match={','.join(ips)}",
            ""
        ])

    # Vendors (Egress)
    for v in vendors:
        vid = re.sub(r'[^a-zA-Z0-9_-]', '', str(v['id']))
        ep_name = f"vend-{vid}"
        v_ips = v.get('ips', [])
        primary_ip = v_ips[0].get('ip_address') if v_ips else v.get('ip')
        primary_port = int(v_ips[0].get('port', 5060) if v_ips else v.get('port', 5060) or 5060)
        if not primary_ip:
            continue
        pjsip_cv_lines.extend([
            f"; Vendor: {v['name']} (ID: {vid}, Status: {v.get('status', 'active')})",
            f"[{ep_name}]",
            "type=endpoint",
            "transport=transport-udp",
            "context=default",
            "disallow=all",
            "allow=ulaw,alaw,g729,gsm,g722",
            f"aors={ep_name}",
            "direct_media=no",
            "",
            f"[{ep_name}]",
            "type=aor",
            f"contact=sip:{primary_ip}:{primary_port}",
            ""
        ])

    cv_pjsip_file = '/etc/asterisk/pjsip_customer_vendor.conf'
    try:
        with open(cv_pjsip_file, 'w') as f:
            f.write('\n'.join(pjsip_cv_lines) + '\n')
        os.chmod(cv_pjsip_file, 0o644)
    except Exception as e:
        print(f"Notice: writing {cv_pjsip_file}: {e}")

    # B. Generate /etc/asterisk/extensions_customer_vendor.conf
    ext_cv_lines = [
        "; ==============================================================================",
        "; Asterisk 23 Customer-Vendor Dialplan & Prefix Routing",
        "; Dynamically generated by callgen-sync-inbound.py",
        "; ==============================================================================",
        ""
    ]

    for c in customers:
        cid = re.sub(r'[^a-zA-Z0-9_-]', '', str(c['id']))
        ctx_name = f"from-cust-{cid}"
        c_name = c['name']
        alloc_ch = int(c.get('allocated_channels', 50) or 50)

        # Get active mappings for this customer, sorted by priority ASC
        c_maps = [m for m in mappings if str(m.get('customer_id')) == str(c['id']) and m.get('status') == 'active']
        c_maps.sort(key=lambda m: int(m.get('priority', 1) or 1))

        ext_cv_lines.extend([
            f"[{ctx_name}]",
            f"; Routing logic for Customer: {c_name} (Max Channels: {alloc_ch})",
            "; 1. Real-time Status Check in AstDB",
            "exten => _X.,1,NoOp(==============================================================)",
            f"same => n,NoOp(>>> INCOMING CALL FROM CUSTOMER: {c_name} (ID: {cid}) <<<)",
            "same => n,NoOp(CallerID: ${CALLERID(all)} | Dialed: ${EXTEN} | IP: ${CHANNEL(recvip)})",
            "same => n,NoOp(==============================================================)",
            f"same => n,Set(CUST_STATUS=${{DB(customer/cust_{cid}/status)}})",
            f"same => n,GotoIf($[\"${{CUST_STATUS}}\" = \"blocked\"]?cust_blocked)",
            "; 2. Customer Channel Concurrency Check",
            f"same => n,Set(GROUP(customer)=cust_{cid})",
            f"same => n,GotoIf($[${{GROUP_COUNT(cust_{cid}@customer)}} > {alloc_ch}]?cust_ch_limit)",
            f"same => n,Set(ORIGINAL_EXTEN=${{EXTEN}})",
            f"same => n,Set(CDR(accountcode)={c_name})"
        ])

        # Group mappings by customer prefix
        pfx_grouped = {}
        for m in c_maps:
            cp = str(m.get('customer_prefix', '')).strip()
            if cp not in pfx_grouped:
                pfx_grouped[cp] = []
            pfx_grouped[cp].append(m)

        for cp, v_list in pfx_grouped.items():
            ext_cv_lines.append(f"; Route matching customer prefix: {cp or 'Direct (no prefix)'}")
            if cp:
                ext_cv_lines.append(f"exten => _{cp}.,1,NoOp(>>> Route prefix {cp} for {c_name} <<<)")
            else:
                ext_cv_lines.append(f"exten => _X.,1,NoOp(>>> Direct Route for {c_name} <<<)")

            ext_cv_lines.append("same => n,Set(CUST_STATUS=${DB(customer/cust_" + cid + "/status)})")
            ext_cv_lines.append("same => n,GotoIf($[\"${CUST_STATUS}\" = \"blocked\"]?cust_blocked)")
            ext_cv_lines.append("same => n,Set(GROUP(customer)=cust_" + cid + ")")
            ext_cv_lines.append("same => n,GotoIf($[${GROUP_COUNT(cust_" + cid + "@customer)} > " + str(alloc_ch) + "]?cust_ch_limit)")
            ext_cv_lines.append("same => n,Set(ORIGINAL_EXTEN=${EXTEN})")

            # Strip customer prefix to obtain clean destination number (e.g. 7666692xxxx -> 92xxxx)
            cp_len = len(cp) if cp else 0
            if cp_len > 0:
                ext_cv_lines.append(f"same => n,Set(CLEAN_DEST=${{EXTEN:{cp_len}}})")
            else:
                ext_cv_lines.append("same => n,Set(CLEAN_DEST=${EXTEN})")

            ext_cv_lines.append(f"same => n,Set(CDR(accountcode)={c_name})")

            # Try each vendor in priority order
            for idx, vm in enumerate(v_list):
                vid = re.sub(r'[^a-zA-Z0-9_-]', '', str(vm.get('vendor_id', '')))
                v_name = vm.get('vendor_name', vid)
                vp = str(vm.get('vendor_prefix', '')).strip()
                v_obj = next((v for v in vendors if str(v['id']) == str(vm.get('vendor_id'))), {})
                v_max_ch = int(v_obj.get('max_channels', 100) or 100)

                # Prefix translation: Prepend vendor prefix to clean destination (e.g. 88555 + 92xxxx -> 8855592xxxx)
                ext_cv_lines.append(f"; --- Vendor Attempt #{idx+1}: {v_name} (Priority {vm.get('priority', 1)}) ---")
                ext_cv_lines.append(f"same => n,Set(VEND_STATUS=${{DB(vendor/vend_{vid}/status)}})")
                ext_cv_lines.append(f"same => n,GotoIf($[\"${{VEND_STATUS}}\" = \"blocked\"]?skip_vend_{idx})")
                ext_cv_lines.append(f"same => n,Set(GROUP(vendor)=vend_{vid})")
                ext_cv_lines.append(f"same => n,GotoIf($[${{GROUP_COUNT(vend_{vid}@vendor)}} > {v_max_ch}]?skip_vend_{idx})")
                
                # Prefix translation formula: prepend vendor prefix to stripped clean destination
                if vp:
                    ext_cv_lines.append(f"same => n,Set(DIAL_NUM={vp}${{CLEAN_DEST}})")
                else:
                    ext_cv_lines.append("same => n,Set(DIAL_NUM=${CLEAN_DEST})")

                ext_cv_lines.append(f"same => n,Set(CDR(userfield)=cust:{cid}:vend:{vid}:${{ORIGINAL_EXTEN}}:${{CLEAN_DEST}}:${{DIAL_NUM}})")
                ext_cv_lines.append(f"same => n,Dial(PJSIP/${{DIAL_NUM}}@vend-{vid},60,g)")
                ext_cv_lines.append("same => n,GotoIf($[\"${DIALSTATUS}\" = \"ANSWER\"]?call_done)")
                ext_cv_lines.append(f"same => n(skip_vend_{idx}),NoOp(>>> Failover from Vendor {v_name} (Status: ${{DIALSTATUS}}) <<<)")

            # If all vendors failed
            ext_cv_lines.append("same => n,Playtones(congestion)")
            ext_cv_lines.append("same => n,Hangup(34)")

        # Blocked and limit labels
        ext_cv_lines.extend([
            "; Customer Blocked label",
            "same => n(cust_blocked),NoOp(>>> CALL REJECTED: CUSTOMER IS BLOCKED IN PORTAL <<<)",
            f"same => n,Set(CDR(userfield)=cust_blocked:{cid}:${{ORIGINAL_EXTEN}})",
            "same => n,Hangup(21)",
            "; Customer Channel Limit label",
            "same => n(cust_ch_limit),NoOp(>>> CALL REJECTED: CUSTOMER EXCEEDED CHANNEL LIMIT <<<)",
            f"same => n,Set(CDR(userfield)=cust_channel_limit:{cid}:${{ORIGINAL_EXTEN}})",
            "same => n,Hangup(17)",
            "; Call completed successfully",
            "same => n(call_done),Hangup()",
            "",
            "; Reject unallowed destination numbers",
            f"exten => _X.,2,NoOp(>>> NUMBER NOT ALLOWED FOR CUSTOMER {c_name} <<<)",
            f"same => n,Set(CDR(userfield)=cust_unauthorized_dest:{cid}:${{EXTEN}})",
            "same => n,Hangup(21)",
            "exten => s,1,Hangup(21)",
            "exten => i,1,Hangup(21)",
            "exten => h,1,Hangup()",
            ""
        ])

    cv_ext_file = '/etc/asterisk/extensions_customer_vendor.conf'
    try:
        with open(cv_ext_file, 'w') as f:
            f.write('\n'.join(ext_cv_lines) + '\n')
        os.chmod(cv_ext_file, 0o644)
    except Exception as e:
        print(f"Notice: writing {cv_ext_file}: {e}")

    # Write local mirror in callgen-dashboard/asterisk if present
    local_ast_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'asterisk')
    if os.path.isdir(local_ast_dir):
        try:
            with open(os.path.join(local_ast_dir, 'pjsip_customer_vendor.conf'), 'w') as f:
                f.write('\n'.join(pjsip_cv_lines) + '\n')
            with open(os.path.join(local_ast_dir, 'extensions_customer_vendor.conf'), 'w') as f:
                f.write('\n'.join(ext_cv_lines) + '\n')
        except Exception:
            pass

    # C. Push active/blocked status to AstDB for instant microsecond blocking
    for c in customers:
        cid = re.sub(r'[^a-zA-Z0-9_-]', '', str(c['id']))
        st = c.get('status', 'active')
        subprocess.run([ast_bin, '-rx', f"database put customer cust_{cid} status {st}"], capture_output=True)

    for v in vendors:
        vid = re.sub(r'[^a-zA-Z0-9_-]', '', str(v['id']))
        st = v.get('status', 'active')
        subprocess.run([ast_bin, '-rx', f"database put vendor vend_{vid} status {st}"], capture_output=True)

    print(f"Synced Customer-Vendor Routing: Customers={len(customers)}, Vendors={len(vendors)}, Mappings={len(mappings)}")

sync_customer_vendor_routing()

# 8. Apply to Asterisk
import shutil
ast_bin = shutil.which('asterisk') or '/usr/sbin/asterisk'
subprocess.run([ast_bin, '-rx', 'pjsip reload'], capture_output=True)
subprocess.run([ast_bin, '-rx', 'dialplan reload'], capture_output=True)

print(f"Synced inbound: Gateways={len(gateways)}, Authorized IPs={[g['ip'] for g in gateways]}, testcall={testcall_dur}s, testcall2={testcall2_dur}s")
for gw in gateways:
    print(f"  Gateway #{gw['index']}: {gw['ip']} ({gw['label']}) -> Allowed: {list(gw['prefixes'].keys())} -> Contexts: {gw['prefixes']}")
