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
chown -R asterisk:asterisk "$TMP_DIR" 2>/dev/null || true
chmod 775 "$TMP_DIR" 2>/dev/null || true

while true; do
    # 1. Read dynamic parameters from SQLite DB (with fallback)
    readarray -t C < <(python3 - "$TRUNK" <<'PY'
import sys, json, os
try:
    import callgen_db
    conn = callgen_db.get_connection()
    row = conn.execute("SELECT target_ip, monthly_limit, max_channels, call_duration, context, prefix_cid FROM trunks WHERE id=?", (sys.argv[1],)).fetchone()
    conn.close()
    if row:
        print(row['target_ip'])
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
        pfx = c.get('prefix_cid') or ((c.get('prefix') or '') + (c.get('country_code') or '')) or '444453'
        print(c.get('target_ip', '206.251.255.21'))
        print(c.get('monthly_limit', 250000))
        print(c.get('max_channels', 400))
        print(c.get('call_duration', 600))
        print(c.get('context', 'testcall'))
        print(pfx)
        sys.exit(0)
    except Exception:
        pass

print("206.251.255.21\n250000\n400\n600\ntestcall\n444453")
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

    # 4. Dispatch 1 call every 2 seconds if under capacity limits
    if ((REM >= DUR)) && [ "$((ACTIVE + PENDING))" -lt "$MAXCALLS" ]; then
        # Exact callerid and ANI pattern for Cuba (Prefix 4444 + CC 53)
        CALLERID="${PREFIX_CID}$(/usr/bin/tr -dc '0-9' < /dev/urandom 2>/dev/null | /usr/bin/head -c 8)"
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
        chown -f asterisk:asterisk "$TMP_FILE" 2>/dev/null || true
        chmod 664 "$TMP_FILE" 2>/dev/null || true
        mv "$TMP_FILE" "$SPOOL/"
    fi

    sleep 2
done
