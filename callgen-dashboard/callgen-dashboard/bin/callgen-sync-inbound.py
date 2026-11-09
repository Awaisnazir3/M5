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
            "prefix": "444453, 619334221",
            "country_code": "",
            "context": "auto"
        }
    ],
    "source_ip": "192.168.1.13",
    "testcall_duration": 60,
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
    pjsip_lines.extend([
        f"; Authorized Gateway #{gw['index']}: {gw['ip']} ({gw['label']})",
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
        f"contact=sip:{gw['ip']}:5060",
        "",
        f"[{ep_name}]",
        "type=identify",
        f"endpoint={ep_name}",
        f"match={gw['ip']}",
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

# 7. Apply to Asterisk
subprocess.run(['/usr/sbin/asterisk', '-rx', 'pjsip reload'], capture_output=True)
subprocess.run(['/usr/sbin/asterisk', '-rx', 'dialplan reload'], capture_output=True)

print(f"Synced inbound: Gateways={len(gateways)}, Authorized IPs={[g['ip'] for g in gateways]}, testcall={testcall_dur}s, testcall2={testcall2_dur}s")
for gw in gateways:
    print(f"  Gateway #{gw['index']}: {gw['ip']} ({gw['label']}) -> Allowed: {list(gw['prefixes'].keys())} -> Contexts: {gw['prefixes']}")
