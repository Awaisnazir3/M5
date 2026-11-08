#!/usr/bin/env python3
# ==============================================================================
# M5-Traffic Controller: Inbound PJSIP & Dialplan Synchronizer
# Enforces strict prefix routing to [testcall] / [testcall2]
# Rejecting any call that does not match configured allowed prefixes.
# ==============================================================================

import json, os, subprocess, re

INBOUND_CONF = '/etc/callgen-inbound.json'

default_inbound = {
    "source_ips": [
        {
            "id": "src-1",
            "ip": "192.168.1.13",
            "label": "Node-13 Forwarder",
            "prefix": "2237, 4444, 53, 619334, 221",
            "country_code": "223, 53, 221",
            "context": "auto"
        }
    ],
    "source_ip": "192.168.1.13",
    "testcall_duration": 600,
    "testcall2_duration": 550,
    "testcall2_prefixes": "2237",
    "testcall_prefixes": "4444, 53, 619334, 221"
}

cfg = dict(default_inbound)
if os.path.exists(INBOUND_CONF):
    try:
        with open(INBOUND_CONF, 'r') as f:
            loaded = json.load(f)
            if isinstance(loaded, dict):
                cfg.update(loaded)
    except Exception as e:
        print(f"Error loading {INBOUND_CONF}: {e}")

# 1. Collect all authorized source IPs
all_ips = []
source_ips_list = cfg.get('source_ips') or []
if isinstance(source_ips_list, list):
    for item in source_ips_list:
        ip = str(item.get('ip', '')).strip()
        if ip and ip not in all_ips:
            all_ips.append(ip)

if not all_ips and cfg.get('source_ip'):
    for x in str(cfg.get('source_ip')).replace(';', ',').split(','):
        x = x.strip()
        if x and x not in all_ips:
            all_ips.append(x)

if not all_ips:
    all_ips = ['192.168.1.13']

match_str = ','.join(all_ips)
primary_ip = all_ips[0]

# 2. Extract Duration limits
try:
    testcall_dur = int(cfg.get('testcall_duration', 600))
    if testcall_dur < 1: testcall_dur = 600
except Exception:
    testcall_dur = 600

try:
    testcall2_dur = int(cfg.get('testcall2_duration', 550))
    if testcall2_dur < 1: testcall2_dur = 550
except Exception:
    testcall2_dur = 550

# 3. Clean and parse Prefixes
def parse_pfx_list(raw):
    res = []
    for item in str(raw or '').replace(';', ',').split(','):
        clean = re.sub(r'[^0-9]', '', item.strip())
        if clean and clean not in res:
            res.append(clean)
    return res

testcall2_pfx = parse_pfx_list(cfg.get('testcall2_prefixes', '2237'))
testcall_pfx = parse_pfx_list(cfg.get('testcall_prefixes', '4444, 53, 619334, 221'))

# Also check source_ips for any assigned prefixes
for s in cfg.get('source_ips') or []:
    if isinstance(s, dict):
        ctx = s.get('context', 'auto')
        pfxs = parse_pfx_list(s.get('prefix', ''))
        if ctx == 'testcall2':
            for p in pfxs:
                if p not in testcall2_pfx:
                    testcall2_pfx.append(p)
        elif ctx == 'testcall':
            for p in pfxs:
                if p not in testcall_pfx:
                    testcall_pfx.append(p)

# Sort prefixes by length descending so longer/more specific prefixes match first
testcall2_pfx.sort(key=len, reverse=True)
testcall_pfx.sort(key=len, reverse=True)

# 4. Generate /etc/asterisk/pjsip_inbound.conf
pjsip_content = f"""; ==============================================================================
; Dynamic Inbound PJSIP Configuration (Managed by callgen-sync-inbound.py)
; Authorized Source IPs: {match_str}
; ==============================================================================
[trunk-inbound]
type=endpoint
transport=transport-udp
context=from-inbound
disallow=all
allow=ulaw,alaw,g729,gsm,g722
aors=trunk-inbound
direct_media=no
force_rport=yes
rewrite_contact=yes
rtp_symmetric=yes

[trunk-inbound]
type=aor
contact=sip:{primary_ip}:5060

[trunk-inbound]
type=identify
endpoint=trunk-inbound
match={match_str}
"""

with open('/etc/asterisk/pjsip_inbound.conf', 'w') as f:
    f.write(pjsip_content)
os.chmod('/etc/asterisk/pjsip_inbound.conf', 0o644)

# 5. Generate /etc/asterisk/extensions_inbound.conf
ext_lines = [
    "; ==============================================================================",
    "; Dynamic Inbound Dialplan & Contexts (Managed by callgen-sync-inbound.py)",
    f"; testcall duration: {testcall_dur}s | testcall2 duration: {testcall2_dur}s",
    f"; testcall2 prefixes (Mali): {', '.join(testcall2_pfx) or 'None'}",
    f"; testcall prefixes (Cuba & Senegal): {', '.join(testcall_pfx) or 'None'}",
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
    "[from-inbound]"
]

# Prefixes for testcall2 (e.g. Mali: 2237)
for p in testcall2_pfx:
    ext_lines.append(f"; Route prefix {p} to [testcall2]")
    ext_lines.append(f"exten => _{p}.,1,NoOp(==============================================================)")
    ext_lines.append(f"same => n,NoOp(>>> INCOMING CALL MATCHING PREFIX {p} -> ROUTING TO testcall2 <<<)")
    ext_lines.append(f"same => n,NoOp(CallerID: ${{CALLERID(all)}} | UniqueID: ${{UNIQUEID}})")
    ext_lines.append(f"same => n,NoOp(==============================================================)")
    ext_lines.append(f"same => n,Set(__INBOUND_DID=${{EXTEN}})")
    ext_lines.append(f"same => n,Set(CHANNEL(accountcode)=${{EXTEN}})")
    ext_lines.append(f"same => n,Set(CDR(userfield)=inbound:${{EXTEN}}:testcall2)")
    ext_lines.append(f"same => n,Goto(testcall2,s,1)")
    # Exact match if dialed without extra digits
    ext_lines.append(f"exten => {p},1,Goto(testcall2,s,1)")
    # Support leading + by stripping it and re-entering from-inbound
    ext_lines.append(f"exten => _+{p}.,1,Goto(from-inbound,${{EXTEN:1}},1)")
    ext_lines.append(f"exten => +{p},1,Goto(from-inbound,${{EXTEN:1}},1)")

# Prefixes for testcall (e.g. Cuba: 4444, 53; Senegal: 619334, 221)
for p in testcall_pfx:
    ext_lines.append(f"; Route prefix {p} to [testcall]")
    ext_lines.append(f"exten => _{p}.,1,NoOp(==============================================================)")
    ext_lines.append(f"same => n,NoOp(>>> INCOMING CALL MATCHING PREFIX {p} -> ROUTING TO testcall <<<)")
    ext_lines.append(f"same => n,NoOp(CallerID: ${{CALLERID(all)}} | UniqueID: ${{UNIQUEID}})")
    ext_lines.append(f"same => n,NoOp(==============================================================)")
    ext_lines.append(f"same => n,Set(__INBOUND_DID=${{EXTEN}})")
    ext_lines.append(f"same => n,Set(CHANNEL(accountcode)=${{EXTEN}})")
    ext_lines.append(f"same => n,Set(CDR(userfield)=inbound:${{EXTEN}}:testcall)")
    ext_lines.append(f"same => n,Goto(testcall,s,1)")
    # Exact match if dialed without extra digits
    ext_lines.append(f"exten => {p},1,Goto(testcall,s,1)")
    # Support leading + by stripping it and re-entering from-inbound
    ext_lines.append(f"exten => _+{p}.,1,Goto(from-inbound,${{EXTEN:1}},1)")
    ext_lines.append(f"exten => +{p},1,Goto(from-inbound,${{EXTEN:1}},1)")

# STRICT REJECTION: Reject any extension not matching allowed prefixes
ext_lines.extend([
    "; ==============================================================================",
    "; STRICT SECURITY & PREFIX CONTROL:",
    "; Only allowed prefixes are accepted. Any other number is REJECTED immediately.",
    "; ==============================================================================",
    "exten => _X.,1,NoOp(==============================================================)",
    "same => n,NoOp(>>> REJECTED: INCOMING NUMBER ${EXTEN} NOT IN ALLOWED PREFIX LIST <<<)",
    "same => n,NoOp(CallerID: ${CALLERID(all)} | Remote IP: ${CHANNEL(pjsip,remote_addr)})",
    "same => n,NoOp(==============================================================)",
    "same => n,Set(CDR(userfield)=inbound_rejected:${EXTEN})",
    "same => n,Hangup(21)",
    "",
    "exten => _+X.,1,NoOp(==============================================================)",
    "same => n,NoOp(>>> REJECTED: INCOMING NUMBER ${EXTEN} NOT IN ALLOWED PREFIX LIST <<<)",
    "same => n,NoOp(CallerID: ${CALLERID(all)} | Remote IP: ${CHANNEL(pjsip,remote_addr)})",
    "same => n,NoOp(==============================================================)",
    "same => n,Set(CDR(userfield)=inbound_rejected:${EXTEN})",
    "same => n,Hangup(21)",
    "",
    "exten => s,1,NoOp(>>> REJECTED: INCOMING CALL ON 's' WITHOUT ALLOWED PREFIX <<<)",
    "same => n,Set(CDR(userfield)=inbound_rejected:s)",
    "same => n,Hangup(21)",
    "",
    "exten => T,1,Hangup()",
    "exten => t,1,Hangup()",
    "exten => h,1,Hangup()"
])

with open('/etc/asterisk/extensions_inbound.conf', 'w') as f:
    f.write('\n'.join(ext_lines) + '\n')
os.chmod('/etc/asterisk/extensions_inbound.conf', 0o644)

# 6. Apply to Asterisk
subprocess.run(['/usr/sbin/asterisk', '-rx', 'pjsip reload'], capture_output=True)
subprocess.run(['/usr/sbin/asterisk', '-rx', 'dialplan reload'], capture_output=True)

print(f"Synced inbound: Source IPs={match_str}, testcall={testcall_dur}s, testcall2={testcall2_dur}s")
print(f"Allowed testcall2 prefixes: {testcall2_pfx}")
print(f"Allowed testcall prefixes: {testcall_pfx}")
