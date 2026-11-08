#!/bin/bash
# ==============================================================================
# M5-Traffic Controller: Mali Destination Engine (asterisk_callgen.sh) - PJSIP
# Dynamic config from /etc/callgen-mali.json
# Destination: Mali | CC: 2237 | Target IP: 202.125.153.97
# ==============================================================================

set -u
exec 200>/var/run/callgen_mali.lock
flock -n 200 || exit 0

AST="/usr/sbin/asterisk"
SPOOL="/var/spool/asterisk/outgoing"
TMP_DIR="/tmp/asterisk_calls_mali"
CONF="/etc/callgen-mali.json"
TRUNK="mali"

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
    pfx = c.get('prefix_cid') or ((c.get('prefix') or '') + (c.get('country_code') or '')) or '2237'
    print(c.get('target_ip', '202.125.153.97'))
    print(c.get('monthly_limit', 100000))
    print(c.get('max_channels', 150))
    print(c.get('call_duration', 600))
    print(c.get('context', 'testcall2'))
    print(pfx)
except Exception:
    print("202.125.153.97\n100000\n150\n600\ntestcall2\n2237")
PY
        )
        TARGET_IP="${C[0]}"
        LIMIT="${C[1]}"
        MAXCALLS="${C[2]}"
        DUR="${C[3]}"
        CONTEXT="${C[4]}"
        PREFIX_CID="${C[5]}"
    else
        TARGET_IP="202.125.153.97"
        LIMIT=100000
        MAXCALLS=150
        DUR=600
        CONTEXT="testcall2"
        PREFIX_CID="2237"
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

    PENDING=$(find "$SPOOL" -maxdepth 1 -type f -name 'mali_*.call' 2>/dev/null | wc -l)
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
        CALLERID="${PREFIX_CID}$(/usr/bin/tr -dc '0-9' < /dev/urandom 2>/dev/null | /usr/bin/head -c 8)"
        TARGET="PJSIP/${TRUNK}-trunk/sip:$CALLERID@$TARGET_IP:5060"
        ANI="442$(tr -dc '1-9' < /dev/urandom 2>/dev/null | head -c 9)"

        CALLFILE="mali_$(date +%s%N).call"
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
Set: CDR(userfield)=callgen-mali
EOF
        touch -t $(date +%Y%m%d%H%M.%S) "$TMP_FILE"
        chown -f asterisk:asterisk "$TMP_FILE" 2>/dev/null || true
        chmod 664 "$TMP_FILE" 2>/dev/null || true
        mv "$TMP_FILE" "$SPOOL/"
    fi

    sleep 2
done
