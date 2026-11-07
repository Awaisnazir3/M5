#!/bin/bash
# ==============================================================================
# M5-Traffic Controller: Cuba Destination Engine (asterisk_callgen2.sh) - PJSIP
# Dynamic config from /etc/callgen-cuba.json
# Destination: Cuba | Prefix: 4444 | CC: 53 | Target IP: 206.251.255.21
# ==============================================================================

set -u
exec 200>/var/run/callgen_cuba.lock
flock -n 200 || exit 0

AST="/usr/sbin/asterisk"
SPOOL="/var/spool/asterisk/outgoing"
TMP_DIR="/tmp/asterisk_calls_cuba"
CONF="/etc/callgen-cuba.json"
TRUNK="cuba"

mkdir -p "$TMP_DIR"

while true; do
    # 1. Read dynamic parameters from JSON config or fallback to defaults
    if [ -f "$CONF" ]; then
        readarray -t C < <(python3 - "$CONF" <<'PY'
import json, sys
try:
    c = json.load(open(sys.argv[1]))
    pfx = c.get('prefix_cid') or ((c.get('prefix') or '') + (c.get('country_code') or '')) or '444453'
    print(c.get('target_ip', '206.251.255.21'))
    print(c.get('monthly_limit', 250000))
    print(c.get('max_channels', 400))
    print(c.get('call_duration', 600))
    print(c.get('context', 'testcall'))
    print(pfx)
except Exception:
    print("206.251.255.21\n250000\n400\n600\ntestcall\n444453")
PY
        )
        TARGET_IP="${C[0]}"
        LIMIT="${C[1]}"
        MAXCALLS="${C[2]}"
        DUR="${C[3]}"
        CONTEXT="${C[4]}"
        PREFIX_CID="${C[5]}"
    else
        TARGET_IP="206.251.255.21"
        LIMIT=250000
        MAXCALLS=400
        DUR=600
        CONTEXT="testcall"
        PREFIX_CID="444453"
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

    PENDING=$(find "$SPOOL" -maxdepth 1 -type f -name 'cuba_*.call' 2>/dev/null | wc -l)
    case "$PENDING" in ''|*[!0-9]*) PENDING=0 ;; esac

    # Enforce strict monthly minute cutoff
    if [ "$USED" -ge "$LIMITSEC" ]; then
        echo "[$(date '+%Y-%m-%d %H:%M:%S')] Monthly limit reached ($USED sec >= $LIMITSEC sec). No new calls."
        sleep 5
        continue
    fi

    REM=$((LIMITSEC - USED - (ACTIVE + PENDING) * DUR))

    # 4. Dispatch calls if within limits
    if ((REM >= DUR)); then
        NEED=$((MAXCALLS - ACTIVE - PENDING))
        BUDGET=$((REM / DUR))
        ((NEED > BUDGET)) && NEED=$BUDGET

        if [ "$NEED" -gt 0 ]; then
            for ((i = 0; i < NEED; i++)); do
                CURR=$("$AST" -rx "core show channels" 2>/dev/null | grep -c -E "(${TRUNK}-trunk|$TARGET_IP)" || true)
                case "$CURR" in ''|*[!0-9]*) CURR=0 ;; esac
                PEND=$(find "$SPOOL" -maxdepth 1 -type f -name 'cuba_*.call' 2>/dev/null | wc -l)
                case "$PEND" in ''|*[!0-9]*) PEND=0 ;; esac

                [ "$((CURR + PEND))" -ge "$MAXCALLS" ] && break

                # Exact callerid and ANI pattern for Cuba (Prefix 4444 + CC 53)
                CALLERID="${PREFIX_CID}$(/usr/bin/tr -dc '0-9' < /dev/urandom | /usr/bin/head -c 8)"
                TARGET="PJSIP/${TRUNK}-trunk/sip:$CALLERID@$TARGET_IP:5060"
                ANI="96655$((RANDOM % 9000000 + 1000000))"

                CALLFILE="cuba_$(date +%s%N).call"
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
Set: CDR(userfield)=callgen-cuba
EOF
                touch -t $(date +%Y%m%d%H%M.%S) "$TMP_FILE"
                mv "$TMP_FILE" "$SPOOL/"
            done
        fi
    fi

    sleep 3
done
