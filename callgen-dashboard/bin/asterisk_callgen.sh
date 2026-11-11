#!/bin/bash
# ==============================================================================
# M5-Traffic Controller: Mali Destination Engine (asterisk_callgen.sh) - PJSIP
# Dynamic config from /etc/callgen-mali.json
# Destination: Mali | CC: 2237 | Target IP: 202.125.153.97
# ==============================================================================

set -u
exec 200>/var/run/callgen_mali.lock
flock -n 200 || exit 0

AST="$(command -v asterisk 2>/dev/null || echo '/usr/sbin/asterisk')"
SPOOL="/var/spool/asterisk/outgoing"
TMP_DIR="/tmp/asterisk_calls_mali"
CONF="/etc/callgen-mali.json"
TRUNK="mali"

mkdir -p "$TMP_DIR"
chown -R asterisk:asterisk "$TMP_DIR" 2>/dev/null || true
chmod 775 "$TMP_DIR" 2>/dev/null || true

while true; do
    # 1. Read dynamic parameters from SQLite DB (with fallback)
    readarray -t C < <(python3 - "$TRUNK" <<'PY'
import sys, json, os
try:
    import callgen_db
    conn = callgen_db.get_connection()
    row = conn.execute("SELECT target_ip, sip_port, monthly_limit, max_channels, call_duration, context, prefix_cid FROM trunks WHERE id=?", (sys.argv[1],)).fetchone()
    conn.close()
    if row:
        tip = row['target_ip']
        port = row['sip_port'] if ('sip_port' in row.keys() and row['sip_port']) else 5080
        if ':' not in str(tip) and port:
            tip = f"{tip}:{port}"
        print(tip)
        print(row['monthly_limit'])
        print(row['max_channels'])
        print(row['call_duration'])
        print(row['context'])
        print(row['prefix_cid'])
        sys.exit(0)
except Exception:
    pass

conf = f"/etc/callgen-{sys.argv[1]}.json"
if os.path.exists(conf):
    try:
        c = json.load(open(conf))
        pfx = c.get('prefix_cid') or ((c.get('prefix') or '') + (c.get('country_code') or '')) or '2237'
        tip = c.get('target_ip', '192.168.88.76:5080')
        port = c.get('sip_port', 5080)
        if ':' not in str(tip) and port:
            tip = f"{tip}:{port}"
        print(tip)
        print(c.get('monthly_limit', 1000000))
        print(c.get('max_channels', 2000))
        print(c.get('call_duration', 600))
        print(c.get('context', 'testcall2'))
        print(pfx)
        sys.exit(0)
    except Exception:
        pass

print("192.168.88.76:5080\n1000000\n2000\n600\ntestcall2\n2237")
PY
    )
    TARGET_IP="${C[0]}"
    LIMIT="${C[1]}"
    MAXCALLS="${C[2]}"
    DUR="${C[3]}"
    CONTEXT="${C[4]}"
    PREFIX_CID="${C[5]}"

    LIMITSEC=$((LIMIT * 60))

    # 2. Check answered CDR usage for this month from SQLite
    USED=$(python3 - "$TARGET_IP" "$TRUNK" <<'PY'
import sys
try:
    import callgen_db
    sec = callgen_db.get_month_consumed_seconds(sys.argv[1], sys.argv[2])
    print(sec)
except Exception:
    print(0)
PY
    )

    # 3. Channel counting
    TARGET_HOST="${TARGET_IP%:*}"
    ACTIVE=$("$AST" -rx "core show channels" 2>/dev/null | grep -c -E "(${TRUNK}-trunk|$TARGET_HOST)" || true)
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
        # Ensure endpoint is loaded in Asterisk before dispatching call
        if ! "$AST" -rx "pjsip show endpoint ${TRUNK}-trunk" 2>/dev/null | grep -q "Endpoint:"; then
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] Endpoint ${TRUNK}-trunk not found in Asterisk memory. Reloading PJSIP..."
            if [ -x /usr/local/sbin/callgen-sync-inbound.py ]; then
                python3 /usr/local/sbin/callgen-sync-inbound.py >/dev/null 2>&1 || true
            fi
            "$AST" -rx "pjsip reload" >/dev/null 2>&1 || true
            sleep 1
            if ! "$AST" -rx "pjsip show endpoint ${TRUNK}-trunk" 2>/dev/null | grep -q "Endpoint:"; then
                echo "[$(date '+%Y-%m-%d %H:%M:%S')] Waiting for Asterisk endpoint ${TRUNK}-trunk to load..."
                sleep 2
                continue
            fi
        fi

        CALLERID="${PREFIX_CID}$(/usr/bin/tr -dc '0-9' < /dev/urandom 2>/dev/null | /usr/bin/head -c 8)"
        SIP_DEST="$TARGET_IP"
        [[ "$SIP_DEST" != *:* ]] && SIP_DEST="${SIP_DEST}:5060"
        TARGET="PJSIP/${TRUNK}-trunk/sip:$CALLERID@$SIP_DEST"
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
