#!/bin/bash
# ==============================================================================
# M5-Traffic Controller: Senegal Destination Engine (asterisk_callgen3.sh) - PJSIP
# Dynamic config from /etc/callgen-senegal.json
# Destination: Senegal | Prefix: 619334 | CC: 221 | Target IP: 178.22.15.201
# ==============================================================================

set -u
exec 200>/var/run/callgen_senegal.lock
flock -n 200 || exit 0

AST="/usr/sbin/asterisk"
SPOOL="/var/spool/asterisk/outgoing"
TMP_DIR="/tmp/asterisk_calls_senegal"
CONF="/etc/callgen-senegal.json"
TRUNK="senegal"

mkdir -p "$TMP_DIR"
chown -R asterisk:asterisk "$TMP_DIR" 2>/dev/null || true
chmod 775 "$TMP_DIR" 2>/dev/null || true

while true; do
    # 1. Read dynamic parameters from JSON config or fallback to defaults
    if [ -f "$CONF" ]; then
        readarray -t C < <(python3 - "$CONF" <<'PY'
import json, sys
try:
    c = json.load(open(sys.argv[1]))
    pfx = c.get('prefix_cid') or ((c.get('prefix') or '') + (c.get('country_code') or '')) or '619334221'
    print(c.get('target_ip', '178.22.15.201'))
    print(c.get('monthly_limit', 150000))
    print(c.get('max_channels', 220))
    print(c.get('call_duration', 600))
    print(c.get('context', 'testcall'))
    print(pfx)
except Exception:
    print("178.22.15.201\n150000\n220\n600\ntestcall\n619334221")
PY
        )
        TARGET_IP="${C[0]}"
        LIMIT="${C[1]}"
        MAXCALLS="${C[2]}"
        DUR="${C[3]}"
        CONTEXT="${C[4]}"
        PREFIX_CID="${C[5]}"
    else
        TARGET_IP="178.22.15.201"
        LIMIT=150000
        MAXCALLS=220
        DUR=600
        CONTEXT="testcall"
        PREFIX_CID="619334221"
    fi

    LIMITSEC=$((LIMIT * 60))

    # 2. Check answered CDR usage for this month
    USED=$(python3 - "$TARGET_IP" "$TRUNK" <<'PY'
import csv, sys, os
from datetime import datetime
ip = sys.argv[1]
t = sys.argv[2]
f = '/var/log/asterisk/cdr-csv/Master.csv'
m = datetime.now().strftime('%Y-%m')
sec = 0
if os.path.exists(f):
    with open(f, newline='') as h:
        for r in csv.reader(h):
            if len(r) < 15: continue
            matched = (ip in r[5] or ip in r[6] or f'{t}-trunk' in r[5] or f'{t}-trunk' in r[6])
            if matched and r[14] == 'ANSWERED' and r[9].startswith(m):
                try: sec += int(r[13])
                except: pass
print(sec)
PY
    )

    # 3. Channel counting
    ACTIVE=$("$AST" -rx "core show channels" 2>/dev/null | grep -c -E "(${TRUNK}-trunk|$TARGET_IP)" || true)
    case "$ACTIVE" in ''|*[!0-9]*) ACTIVE=0 ;; esac

    PENDING=$(find "$SPOOL" -maxdepth 1 -type f -name 'senegal_*.call' 2>/dev/null | wc -l)
    case "$PENDING" in ''|*[!0-9]*) PENDING=0 ;; esac

    # Enforce strict monthly minute cutoff
    if [ "$USED" -ge "$LIMITSEC" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Monthly limit reached ($USED sec >= $LIMITSEC sec). No new calls."
        sleep 5
        continue
    fi

    REM=$((LIMITSEC - USED - (ACTIVE + PENDING) * DUR))

    # 4. Dispatch 1 call every 2 seconds if under capacity limits
    if ((REM >= DUR)) && [ "$((ACTIVE + PENDING))" -lt "$MAXCALLS" ]; then
        # Exact callerid and ANI pattern for Senegal (Prefix 619334 + CC 221)
        CALLERID="${PREFIX_CID}$(/usr/bin/tr -dc '0-9' < /dev/urandom 2>/dev/null | /usr/bin/head -c 9)"
        TARGET="PJSIP/${TRUNK}-trunk/sip:$CALLERID@$TARGET_IP:5060"
        ANI="+442$((RANDOM % 900000000 + 100000000))"

        CALLFILE="senegal_$(date +%s%N).call"
        TMP_FILE="$TMP_DIR/$CALLFILE"

        cat <<EOF > "$TMP_FILE"
Channel: $TARGET
Context: $CONTEXT
Extension: s
CallerID: $ANI
Priority: 1
MaxRetries: 0
RetryTime: 60
WaitTime: 45
Archive: yes
Set: CDR(userfield)=callgen-senegal
EOF
        touch -t $(date +%Y%m%d%H%M.%S) "$TMP_FILE"
        chown -f asterisk:asterisk "$TMP_FILE" 2>/dev/null || true
        chmod 664 "$TMP_FILE" 2>/dev/null || true
        mv "$TMP_FILE" "$SPOOL/"
    fi

    sleep 2
done
