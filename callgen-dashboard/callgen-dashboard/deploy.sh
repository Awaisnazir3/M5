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

# 2. Install Binaries
echo "Installing route generation scripts..."
install -m 750 "${SCRIPT_DIR}/bin/asterisk_callgen.sh" /usr/local/bin/asterisk_callgen.sh
install -m 750 "${SCRIPT_DIR}/bin/asterisk_callgen2.sh" /usr/local/bin/asterisk_callgen2.sh
install -m 750 "${SCRIPT_DIR}/bin/asterisk_callgen3.sh" /usr/local/bin/asterisk_callgen3.sh
install -m 750 "${SCRIPT_DIR}/bin/callgen-control" /usr/local/sbin/callgen-control

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
TRUNKS=("cuba" "senegal" "mali")
declare -A DEFAULT_IPS=( ["cuba"]="202.125.153.97" ["senegal"]="206.251.255.21" ["mali"]="178.22.15.201" )
declare -A DEFAULT_PREFIX=( ["cuba"]="2237" ["senegal"]="444453" ["mali"]="619334221" )
declare -A DEFAULT_LIMITS=( ["cuba"]="100000" ["senegal"]="250000" ["mali"]="150000" )
declare -A DEFAULT_MAX=( ["cuba"]="150" ["senegal"]="400" ["mali"]="220" )
declare -A DEFAULT_CTX=( ["cuba"]="testcall2" ["senegal"]="testcall" ["mali"]="testcall" )

for t in "${TRUNKS[@]}"; do
    CONF="/etc/callgen-${t}.json"
    if [ ! -f "$CONF" ]; then
        echo "Creating initial config for ${t} at ${CONF}..."
        cat > "$CONF" <<EOF
{
  "id": "${t}",
  "target_ip": "${DEFAULT_IPS[$t]}",
  "prefix_cid": "${DEFAULT_PREFIX[$t]}",
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
${WEB_USER} ALL=(root) NOPASSWD: /usr/local/sbin/callgen-control
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
