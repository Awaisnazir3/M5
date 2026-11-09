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

# 0. SELinux handling (prevent Apache/sudo permission blocks on RHEL/Rocky)
if command -v setenforce >/dev/null 2>&1; then
    setenforce 0 2>/dev/null || true
    if [ -f /etc/selinux/config ]; then
        sed -i 's/^SELINUX=enforcing/SELINUX=permissive/' /etc/selinux/config 2>/dev/null || true
    fi
fi

# Ensure Web Server, PHP, and FastCGI Process Manager are installed
if command -v dnf >/dev/null 2>&1; then
    echo "Installing Apache, PHP-FPM, SQLite, and Python3..."
    dnf install -y httpd php php-fpm php-pdo php-json python3 sqlite 2>/dev/null || true
    systemctl enable --now httpd php-fpm 2>/dev/null || true
elif command -v apt-get >/dev/null 2>&1; then
    echo "Installing Apache, PHP-FPM, SQLite, and Python3..."
    apt-get update -y 2>/dev/null || true
    apt-get install -y apache2 php php-fpm php-sqlite3 php-json python3 sqlite3 2>/dev/null || true
    systemctl enable --now apache2 2>/dev/null || true
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
mkdir -p /usr/local/lib/python3.9/site-packages /usr/local/lib/python3.6/site-packages /usr/local/lib/python3.11/site-packages 2>/dev/null || true
for pydir in /usr/local/lib/python3.*/site-packages; do
    if [ -d "$pydir" ]; then
        ln -sfn /usr/local/sbin/callgen_db.py "$pydir/callgen_db.py" 2>/dev/null || true
    fi
done

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

install -m 755 "${SCRIPT_DIR}/bin/asterisk_callgen.sh" /usr/local/bin/asterisk_callgen.sh
install -m 755 "${SCRIPT_DIR}/bin/asterisk_callgen2.sh" /usr/local/bin/asterisk_callgen2.sh
install -m 755 "${SCRIPT_DIR}/bin/asterisk_callgen3.sh" /usr/local/bin/asterisk_callgen3.sh
install -m 755 "${SCRIPT_DIR}/bin/callgen-control" /usr/local/sbin/callgen-control
if [ -f "${SCRIPT_DIR}/bin/callgen-sync-inbound.py" ]; then
    install -m 755 "${SCRIPT_DIR}/bin/callgen-sync-inbound.py" /usr/local/sbin/callgen-sync-inbound.py
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

# 4. Initialize Config Files matching production working server
TRUNKS=("mali" "cuba" "senegal")
declare -A DEFAULT_IPS=( ["mali"]="192.168.1.210" ["cuba"]="192.168.1.210" ["senegal"]="178.22.15.201" )
declare -A DEFAULT_PREFIX=( ["mali"]="" ["cuba"]="4444" ["senegal"]="619334" )
declare -A DEFAULT_CC=( ["mali"]="2237" ["cuba"]="53" ["senegal"]="221" )
declare -A DEFAULT_PREFIX_CID=( ["mali"]="2237" ["cuba"]="444453" ["senegal"]="619334221" )
declare -A DEFAULT_LIMITS=( ["mali"]="100000" ["cuba"]="3800" ["senegal"]="150000" )
declare -A DEFAULT_MAX=( ["mali"]="6" ["cuba"]="100" ["senegal"]="220" )
declare -A DEFAULT_CTX=( ["mali"]="testcall2" ["cuba"]="testcall" ["senegal"]="testcall" )
declare -A DEFAULT_DUR=( ["mali"]="700" ["cuba"]="600" ["senegal"]="600" )

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
  "call_duration": ${DEFAULT_DUR[$t]},
  "context": "${DEFAULT_CTX[$t]}",
  "source_ip": "192.168.1.13"
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
EOF
    chmod 664 "$INBOUND_CONF"
    if id -g "${WEB_USER}" >/dev/null 2>&1; then
        chown root:"${WEB_USER}" "$INBOUND_CONF"
    fi
fi

# Sync configs into SQLite database
python3 /usr/local/sbin/callgen_db.py seed 2>/dev/null || true

if [ -x /usr/local/sbin/callgen-sync-inbound.py ]; then
    /usr/local/sbin/callgen-sync-inbound.py || true
    if command -v asterisk >/dev/null 2>&1 && systemctl is-active --quiet asterisk 2>/dev/null; then
        asterisk -rx "core reload" 2>/dev/null || true
    fi
fi

# 5. Deploy Web Dashboard (STRICTLY index.php, remove any index.html)
WEB_ROOT="/var/www/html/callgen"
echo "Deploying M5-Traffic Controller Web UI to ${WEB_ROOT}..."
mkdir -p "$WEB_ROOT"
# Remove legacy index.html so Apache serves index.php
rm -f "$WEB_ROOT/index.html"
cp -r "${SCRIPT_DIR}/public/"* "$WEB_ROOT/"
rm -f "$WEB_ROOT/index.html"
# Ensure Apache DirectoryIndex prioritizes index.php
cat > "$WEB_ROOT/.htaccess" <<EOF
DirectoryIndex index.php
EOF
chown -R "${WEB_USER}:${WEB_USER}" "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"

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
echo "   /usr/local/sbin/callgen-control hangup all"
echo ""
echo " Web UI:"
echo "   http://<your-server-ip>/callgen/"
echo "=================================================================="
