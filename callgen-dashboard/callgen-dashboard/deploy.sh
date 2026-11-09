#!/bin/bash
# ==============================================================================
# M5-Traffic Controller - Production Deployment Script
# VoIP Smart Switch Installation for Rocky Linux / AlmaLinux / CentOS / Debian / Ubuntu
# Manages Cuba, Senegal, and Mali Call Generation Routes
# ==============================================================================

set -euo pipefail

if [ "$EUID" -ne 0 ]; then
  echo "Error: Must be run as root (e.g. sudo bash deploy.sh)" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "=== Installing M5-Traffic Controller from ${SCRIPT_DIR} ==="

# 0. Ensure Web Server and PHP are installed
if command -v dnf >/dev/null 2>&1; then
    if ! id -u apache >/dev/null 2>&1; then
        echo "Installing Apache and PHP..."
        dnf install -y httpd php php-json
        systemctl enable --now httpd
    fi
fi

# 1. Detect Web User (apache or www-data)
if id -u apache >/dev/null 2>&1; then
    WEB_USER="apache"
elif id -u www-data >/dev/null 2>&1; then
    WEB_USER="www-data"
elif id -u nginx >/dev/null 2>&1; then
    WEB_USER="nginx"
else
    WEB_USER="apache"
    echo "Notice: Could not automatically detect web user; defaulting to '${WEB_USER}'."
fi
echo "Detected web user: ${WEB_USER}"

# 2. Install Binaries and Database
echo "Installing route generation scripts and database module..."
mkdir -p /var/lib/callgen
chown -R asterisk:${WEB_USER} /var/lib/callgen 2>/dev/null || true
chmod 2775 /var/lib/callgen 2>/dev/null || true

install -m 750 "${SCRIPT_DIR}/bin/callgen_db.py" /usr/local/sbin/callgen_db.py
mkdir -p /usr/local/lib/python3.9/site-packages 2>/dev/null || true
ln -sfn /usr/local/sbin/callgen_db.py /usr/local/lib/python3.9/site-packages/callgen_db.py 2>/dev/null || true

# Initialize SQLite schema and CDR symlink
python3 /usr/local/sbin/callgen_db.py init 2>/dev/null || true
chmod 666 /var/lib/callgen/callgen.db* 2>/dev/null || true

if [ -d /var/log/asterisk ]; then
    ln -sfn /var/lib/callgen/callgen.db /var/log/asterisk/master.db 2>/dev/null || true
    chown -h asterisk:asterisk /var/log/asterisk/master.db 2>/dev/null || true
fi

# Ensure Asterisk base configs exist
if [ -d /etc/asterisk ]; then
    if [ ! -f /etc/asterisk/pjsip.conf ] && [ -f "${SCRIPT_DIR}/asterisk/pjsip.conf" ]; then
        cp "${SCRIPT_DIR}/asterisk/pjsip.conf" /etc/asterisk/pjsip.conf
    fi
    if [ ! -f /etc/asterisk/extensions.conf ] && [ -f "${SCRIPT_DIR}/asterisk/extensions.conf" ]; then
        cp "${SCRIPT_DIR}/asterisk/extensions.conf" /etc/asterisk/extensions.conf
    fi
    if [ -f "${SCRIPT_DIR}/asterisk/cdr.conf" ]; then
        cp "${SCRIPT_DIR}/asterisk/cdr.conf" /etc/asterisk/cdr.conf 2>/dev/null || true
    fi
    if [ -f "${SCRIPT_DIR}/asterisk/cdr_sqlite3_custom.conf" ]; then
        cp "${SCRIPT_DIR}/asterisk/cdr_sqlite3_custom.conf" /etc/asterisk/cdr_sqlite3_custom.conf 2>/dev/null || true
    elif [ -f "${SCRIPT_DIR}/cdr_sqlite3_custom.conf" ]; then
        cp "${SCRIPT_DIR}/cdr_sqlite3_custom.conf" /etc/asterisk/cdr_sqlite3_custom.conf 2>/dev/null || true
    fi
    chown -R asterisk:asterisk /etc/asterisk 2>/dev/null || true
fi

# Spool and temporary call directories
mkdir -p /var/spool/asterisk/outgoing /tmp/asterisk_calls_cuba /tmp/asterisk_calls_senegal /tmp/asterisk_calls_mali /var/log/asterisk/cdr-csv
chmod 777 /var/spool/asterisk/outgoing /tmp/asterisk_calls_* 2>/dev/null || true
chown -R asterisk:asterisk /var/spool/asterisk /var/log/asterisk 2>/dev/null || true

install -m 750 "${SCRIPT_DIR}/bin/asterisk_callgen.sh" /usr/local/bin/asterisk_callgen.sh
install -m 750 "${SCRIPT_DIR}/bin/asterisk_callgen2.sh" /usr/local/bin/asterisk_callgen2.sh
install -m 750 "${SCRIPT_DIR}/bin/asterisk_callgen3.sh" /usr/local/bin/asterisk_callgen3.sh
install -m 750 "${SCRIPT_DIR}/bin/callgen-control" /usr/local/sbin/callgen-control
if [ -f "${SCRIPT_DIR}/bin/callgen-sync-inbound.py" ]; then
    install -m 750 "${SCRIPT_DIR}/bin/callgen-sync-inbound.py" /usr/local/sbin/callgen-sync-inbound.py
fi

# 3. Install Systemd Services
echo "Installing systemd services for all 3 routes..."
install -m 644 "${SCRIPT_DIR}/systemd/asterisk-callgen-cuba.service" /etc/systemd/system/
install -m 644 "${SCRIPT_DIR}/systemd/asterisk-callgen-senegal.service" /etc/systemd/system/
install -m 644 "${SCRIPT_DIR}/systemd/asterisk-callgen-mali.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable asterisk-callgen-cuba.service
systemctl enable asterisk-callgen-senegal.service
systemctl enable asterisk-callgen-mali.service

# 4. Initialize Config Files
TRUNKS=("mali" "cuba" "senegal")
declare -A DEFAULT_IPS=( ["mali"]="202.125.153.97" ["cuba"]="206.251.255.21" ["senegal"]="178.22.15.201" )
declare -A DEFAULT_PREFIX=( ["mali"]="" ["cuba"]="4444" ["senegal"]="619334" )
declare -A DEFAULT_CC=( ["mali"]="2237" ["cuba"]="53" ["senegal"]="221" )
declare -A DEFAULT_PREFIX_CID=( ["mali"]="2237" ["cuba"]="444453" ["senegal"]="619334221" )
declare -A DEFAULT_LIMITS=( ["mali"]="100000" ["cuba"]="250000" ["senegal"]="150000" )
declare -A DEFAULT_MAX=( ["mali"]="150" ["cuba"]="400" ["senegal"]="220" )
declare -A DEFAULT_CTX=( ["mali"]="testcall2" ["cuba"]="testcall" ["senegal"]="testcall" )

for t in "${TRUNKS[@]}"; do
    CONF="/etc/callgen-${t}.json"
    if [ ! -f "$CONF" ]; then
        echo "Creating initial config for ${t} at ${CONF}..."
        cat > "$CONF" <<EOF
{
  "id": "${t}",
  "name": "${t^}",
  "target_ip": "${DEFAULT_IPS[$t]}",
  "prefix": "${DEFAULT_PREFIX[$t]}",
  "country_code": "${DEFAULT_CC[$t]}",
  "prefix_cid": "${DEFAULT_PREFIX_CID[$t]}",
  "monthly_limit": ${DEFAULT_LIMITS[$t]},
  "max_channels": ${DEFAULT_MAX[$t]},
  "call_duration": 600,
  "context": "${DEFAULT_CTX[$t]}"
}
EOF
    fi
    if id -u asterisk >/dev/null 2>&1; then
        chown root:asterisk "$CONF"
        chmod 640 "$CONF"
    else
        chmod 644 "$CONF"
    fi
done

# Initialize Inbound Configuration if not present
INBOUND_CONF="/etc/callgen-inbound.json"
if [ ! -f "$INBOUND_CONF" ]; then
    echo "Creating initial inbound configuration at ${INBOUND_CONF}..."
    cat > "$INBOUND_CONF" <<EOF
{
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
EOF
    chmod 664 "$INBOUND_CONF"
    if id -g "${WEB_USER}" >/dev/null 2>&1; then
        chown root:"${WEB_USER}" "$INBOUND_CONF"
    fi
fi
if [ -x /usr/local/sbin/callgen-sync-inbound.py ]; then
    /usr/local/sbin/callgen-sync-inbound.py || true
    if command -v asterisk >/dev/null 2>&1 && systemctl is-active --quiet asterisk 2>/dev/null; then
        asterisk -rx "core reload" 2>/dev/null || true
    fi
fi

# 5. Deploy Web Dashboard
WEB_ROOT="/var/www/html/callgen"
echo "Deploying M5-Traffic Controller Web UI to ${WEB_ROOT}..."
mkdir -p "$WEB_ROOT"
cp -r "${SCRIPT_DIR}/public/"* "$WEB_ROOT/"
chown -R "${WEB_USER}:${WEB_USER}" "$WEB_ROOT"

# 6. Sudoers Configuration
SUDOERS_FILE="/etc/sudoers.d/callgen-dashboard"
echo "Configuring sudoers permissions..."
cat > "$SUDOERS_FILE" <<EOF
${WEB_USER} ALL=(root) NOPASSWD: /usr/local/sbin/callgen-control, /usr/local/sbin/callgen-sync-inbound.py, /usr/local/sbin/callgen_db.py
EOF
chmod 440 "$SUDOERS_FILE"

if command -v visudo >/dev/null 2>&1; then
    visudo -cf "$SUDOERS_FILE"
fi

echo ""
echo "=================================================================="
echo " M5-Traffic Controller Installation Complete!"
echo "=================================================================="
echo " Credentials:"
echo "   Username: awais"
echo "   Password: dancom"
echo ""
echo " Management CLI:"
echo "   /usr/local/sbin/callgen-control status"
echo "   /usr/local/sbin/callgen-control start all"
echo "   /usr/local/sbin/callgen-control stop cuba"
echo ""
echo " Web UI:"
echo "   https://<your-server-ip>/callgen/"
echo "=================================================================="
