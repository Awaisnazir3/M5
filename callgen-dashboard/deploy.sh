#!/bin/bash
# ==============================================================================
# M5-Traffic Controller - Production Deployment Script
# VoIP Smart Switch Installation for Rocky Linux / AlmaLinux / CentOS / Debian / Ubuntu
# Manages Cuba, Senegal, and Mali Call Generation Routes & Asterisk PJSIP Engine
# ==============================================================================

set -euo pipefail

if [ "$EUID" -ne 0 ]; then
  echo "Error: Must be run as root (e.g. sudo bash deploy.sh)" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "=== Installing / Updating M5-Traffic Controller from ${SCRIPT_DIR} ==="

# 0. SELinux handling (prevent Apache/sudo permission blocks on RHEL/Rocky)
if command -v setenforce >/dev/null 2>&1; then
    setenforce 0 2>/dev/null || true
    if [ -f /etc/selinux/config ]; then
        sed -i 's/^SELINUX=enforcing/SELINUX=permissive/' /etc/selinux/config 2>/dev/null || true
    fi
fi

# 1. Asterisk Binary & User Detection / Configuration
echo "--- Checking Asterisk Installation ---"
AST_BIN="$(command -v asterisk 2>/dev/null || echo '')"
if [ -z "$AST_BIN" ]; then
    for candidate in /usr/sbin/asterisk /usr/local/sbin/asterisk /usr/bin/asterisk; do
        if [ -x "$candidate" ]; then
            AST_BIN="$candidate"
            break
        fi
    done
fi

if [ -n "$AST_BIN" ]; then
    echo "Found Asterisk at: ${AST_BIN} ($($AST_BIN -V 2>/dev/null || echo 'installed'))"
    # Ensure standard binary symlinks so all scripts and systemd find asterisk
    if [ "$AST_BIN" != "/usr/sbin/asterisk" ] && [ ! -f /usr/sbin/asterisk ]; then
        ln -sfn "$AST_BIN" /usr/sbin/asterisk 2>/dev/null || true
    fi
    if [ "$AST_BIN" != "/usr/local/sbin/asterisk" ] && [ ! -f /usr/local/sbin/asterisk ]; then
        ln -sfn "$AST_BIN" /usr/local/sbin/asterisk 2>/dev/null || true
    fi
else
    echo "Notice: Asterisk binary not yet found in PATH. Expected in /usr/sbin/asterisk or /usr/local/sbin/asterisk."
fi

# Ensure asterisk system user and group exist
if ! id -u asterisk >/dev/null 2>&1; then
    echo "Creating asterisk system user and group..."
    groupadd -r asterisk 2>/dev/null || true
    useradd -r -g asterisk -d /var/lib/asterisk -s /sbin/nologin -c "Asterisk PBX" asterisk 2>/dev/null || \
    useradd -m -d /var/lib/asterisk -s /sbin/nologin asterisk 2>/dev/null || true
fi

# Ensure Asterisk systemd unit file is present
if [ -f "${SCRIPT_DIR}/systemd/asterisk.service" ]; then
    if [ ! -f /etc/systemd/system/asterisk.service ] && [ ! -f /lib/systemd/system/asterisk.service ] && [ ! -f /usr/lib/systemd/system/asterisk.service ]; then
        echo "Installing asterisk.service unit..."
        install -m 644 "${SCRIPT_DIR}/systemd/asterisk.service" /etc/systemd/system/asterisk.service
        systemctl daemon-reload
    fi
fi

# 2. Ensure Web Server, PHP, SQLite, and Python3 are installed
echo "--- Installing Apache, PHP, SQLite, and Python3 dependencies ---"
if command -v dnf >/dev/null 2>&1; then
    dnf install -y httpd php php-fpm php-pdo php-sqlite3 php-json php-cli python3 sqlite 2>/dev/null || \
    dnf install -y httpd php php-fpm php-pdo php-json python3 sqlite 2>/dev/null || true
    systemctl enable --now httpd php-fpm 2>/dev/null || true
elif command -v apt-get >/dev/null 2>&1; then
    apt-get update -y 2>/dev/null || true
    apt-get install -y apache2 libapache2-mod-php php php-fpm php-sqlite3 php-json php-cli python3 sqlite3 2>/dev/null || true
    systemctl enable --now apache2 2>/dev/null || true
fi

# Detect Web User (apache or www-data)
if id -u apache >/dev/null 2>&1; then
    WEB_USER="apache"
elif id -u www-data >/dev/null 2>&1; then
    WEB_USER="www-data"
elif id -u nginx >/dev/null 2>&1; then
    WEB_USER="nginx"
else
    WEB_USER="apache"
fi
echo "Detected web user: ${WEB_USER}"

# Add asterisk and web user to each other's groups for SQLite & spool sharing
usermod -a -G asterisk "${WEB_USER}" 2>/dev/null || true
usermod -a -G "${WEB_USER}" asterisk 2>/dev/null || true

# 3. Install Binaries and Database
echo "--- Installing route generation scripts and database module ---"
mkdir -p /var/lib/callgen
chown -R asterisk:"${WEB_USER}" /var/lib/callgen 2>/dev/null || true
chmod 2775 /var/lib/callgen 2>/dev/null || true

# Copy initial callgen-config.json if not present
if [ ! -f /var/lib/callgen/callgen-config.json ]; then
    if [ -f "${SCRIPT_DIR}/callgen-config.json" ]; then
        cp "${SCRIPT_DIR}/callgen-config.json" /var/lib/callgen/callgen-config.json 2>/dev/null || true
    elif [ -f "${SCRIPT_DIR}/../callgen-config.json" ]; then
        cp "${SCRIPT_DIR}/../callgen-config.json" /var/lib/callgen/callgen-config.json 2>/dev/null || true
    fi
fi
chown -R asterisk:"${WEB_USER}" /var/lib/callgen 2>/dev/null || true
chmod 666 /var/lib/callgen/callgen-config.json 2>/dev/null || true

install -m 755 "${SCRIPT_DIR}/bin/callgen_db.py" /usr/local/sbin/callgen_db.py
for pydir in /usr/local/lib/python3.*/site-packages /usr/lib/python3.*/site-packages; do
    if [ -d "$pydir" ]; then
        ln -sfn /usr/local/sbin/callgen_db.py "$pydir/callgen_db.py" 2>/dev/null || true
    fi
done

# Initialize SQLite schema and CDR symlink
python3 /usr/local/sbin/callgen_db.py init 2>/dev/null || true
python3 /usr/local/sbin/callgen_db.py migrate 2>/dev/null || true
chmod 666 /var/lib/callgen/callgen.db* 2>/dev/null || true
chown -R asterisk:"${WEB_USER}" /var/lib/callgen 2>/dev/null || true

mkdir -p /var/log/asterisk /var/log/asterisk/cdr-csv
ln -sfn /var/lib/callgen/callgen.db /var/log/asterisk/master.db 2>/dev/null || true
chown -h asterisk:asterisk /var/log/asterisk/master.db 2>/dev/null || true
chown -R asterisk:asterisk /var/log/asterisk 2>/dev/null || true

# Spool and temporary call directories
mkdir -p /var/spool/asterisk/outgoing /tmp/asterisk_calls_cuba /tmp/asterisk_calls_senegal /tmp/asterisk_calls_mali
chmod 777 /var/spool/asterisk/outgoing /tmp/asterisk_calls_* 2>/dev/null || true
chown -R asterisk:asterisk /var/spool/asterisk 2>/dev/null || true

install -m 755 "${SCRIPT_DIR}/bin/asterisk_callgen.sh" /usr/local/bin/asterisk_callgen.sh
install -m 755 "${SCRIPT_DIR}/bin/asterisk_callgen2.sh" /usr/local/bin/asterisk_callgen2.sh
install -m 755 "${SCRIPT_DIR}/bin/asterisk_callgen3.sh" /usr/local/bin/asterisk_callgen3.sh
install -m 755 "${SCRIPT_DIR}/bin/callgen-control" /usr/local/sbin/callgen-control
ln -sfn /usr/local/sbin/callgen-control /usr/local/bin/callgen-control 2>/dev/null || true
ln -sfn /usr/local/sbin/callgen-control /usr/bin/callgen-control 2>/dev/null || true
if [ -f "${SCRIPT_DIR}/bin/callgen-sync-inbound.py" ]; then
    install -m 755 "${SCRIPT_DIR}/bin/callgen-sync-inbound.py" /usr/local/sbin/callgen-sync-inbound.py
fi

# 4. Deploy Asterisk Configurations (Forced copy + backup)
echo "--- Deploying Asterisk PJSIP, Dialplan, and CDR configurations ---"
mkdir -p /etc/asterisk

# Backup existing configs once if not backed up yet
for cfg in pjsip.conf extensions.conf cdr.conf cdr_sqlite3_custom.conf; do
    if [ -f "/etc/asterisk/$cfg" ] && [ ! -f "/etc/asterisk/${cfg}.orig.bak" ]; then
        cp "/etc/asterisk/$cfg" "/etc/asterisk/${cfg}.orig.bak" 2>/dev/null || true
    fi
done

if [ -f "${SCRIPT_DIR}/asterisk/pjsip.conf" ]; then
    echo "  -> Deploying /etc/asterisk/pjsip.conf"
    cp -f "${SCRIPT_DIR}/asterisk/pjsip.conf" /etc/asterisk/pjsip.conf
fi
if [ -f "${SCRIPT_DIR}/asterisk/extensions.conf" ]; then
    echo "  -> Deploying /etc/asterisk/extensions.conf"
    cp -f "${SCRIPT_DIR}/asterisk/extensions.conf" /etc/asterisk/extensions.conf
fi
if [ -f "${SCRIPT_DIR}/asterisk/cdr.conf" ]; then
    echo "  -> Deploying /etc/asterisk/cdr.conf"
    cp -f "${SCRIPT_DIR}/asterisk/cdr.conf" /etc/asterisk/cdr.conf
fi
if [ -f "${SCRIPT_DIR}/asterisk/cdr_sqlite3_custom.conf" ]; then
    echo "  -> Deploying /etc/asterisk/cdr_sqlite3_custom.conf"
    cp -f "${SCRIPT_DIR}/asterisk/cdr_sqlite3_custom.conf" /etc/asterisk/cdr_sqlite3_custom.conf
elif [ -f "${SCRIPT_DIR}/cdr_sqlite3_custom.conf" ]; then
    echo "  -> Deploying /etc/asterisk/cdr_sqlite3_custom.conf"
    cp -f "${SCRIPT_DIR}/cdr_sqlite3_custom.conf" /etc/asterisk/cdr_sqlite3_custom.conf
fi

# Ensure include files exist with proper permissions before Asterisk reads them
touch /etc/asterisk/pjsip_inbound.conf /etc/asterisk/extensions_inbound.conf /etc/asterisk/pjsip_customer_vendor.conf /etc/asterisk/extensions_customer_vendor.conf
if [ -f "${SCRIPT_DIR}/asterisk/pjsip_customer_vendor.conf" ] && [ ! -s /etc/asterisk/pjsip_customer_vendor.conf ]; then
    cp -f "${SCRIPT_DIR}/asterisk/pjsip_customer_vendor.conf" /etc/asterisk/pjsip_customer_vendor.conf 2>/dev/null || true
fi
if [ -f "${SCRIPT_DIR}/asterisk/extensions_customer_vendor.conf" ] && [ ! -s /etc/asterisk/extensions_customer_vendor.conf ]; then
    cp -f "${SCRIPT_DIR}/asterisk/extensions_customer_vendor.conf" /etc/asterisk/extensions_customer_vendor.conf 2>/dev/null || true
fi
chown -R asterisk:asterisk /etc/asterisk 2>/dev/null || true
chmod 644 /etc/asterisk/*.conf 2>/dev/null || true

# 5. Install Systemd Services for Call Generators
echo "--- Installing systemd services for Cuba, Senegal, and Mali routes ---"
install -m 644 "${SCRIPT_DIR}/systemd/asterisk-callgen-cuba.service" /etc/systemd/system/
install -m 644 "${SCRIPT_DIR}/systemd/asterisk-callgen-senegal.service" /etc/systemd/system/
install -m 644 "${SCRIPT_DIR}/systemd/asterisk-callgen-mali.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable asterisk-callgen-cuba.service 2>/dev/null || true
systemctl enable asterisk-callgen-senegal.service 2>/dev/null || true
systemctl enable asterisk-callgen-mali.service 2>/dev/null || true

# 6. Initialize Trunk Config Files
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
    chown root:asterisk "$CONF" 2>/dev/null || true
    chmod 664 "$CONF" 2>/dev/null || true
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
    chown root:"${WEB_USER}" "$INBOUND_CONF" 2>/dev/null || true
fi

# Sync configs into SQLite database
python3 /usr/local/sbin/callgen_db.py seed 2>/dev/null || true

# Generate inbound PJSIP endpoints and dialplan rules
if [ -x /usr/local/sbin/callgen-sync-inbound.py ]; then
    /usr/local/sbin/callgen-sync-inbound.py || true
fi

# 7. Start / Restart Asterisk & Apply All Configurations
echo "--- Starting and Applying Asterisk Configurations ---"
if systemctl list-unit-files 2>/dev/null | grep -q "^asterisk\.service" || [ -f /etc/systemd/system/asterisk.service ]; then
    systemctl daemon-reload
    systemctl enable asterisk 2>/dev/null || true
    systemctl restart asterisk 2>/dev/null || true
elif command -v asterisk >/dev/null 2>&1; then
    asterisk -rx "core reload" 2>/dev/null || true
fi

# Wait briefly for Asterisk socket
sleep 2

if command -v asterisk >/dev/null 2>&1; then
    echo "Applying live reload to Asterisk..."
    asterisk -rx "core reload" 2>/dev/null || true
    asterisk -rx "module reload cdr_sqlite3_custom.so" 2>/dev/null || true
    asterisk -rx "pjsip reload" 2>/dev/null || true
    asterisk -rx "dialplan reload" 2>/dev/null || true
    asterisk -rx "cdr reload" 2>/dev/null || true
fi

# 8. Deploy Web Dashboard (STRICTLY index.php, remove any index.html)
WEB_ROOT="/var/www/html/callgen"
echo "--- Deploying Web UI to ${WEB_ROOT} ---"
mkdir -p "$WEB_ROOT"
rm -f "$WEB_ROOT/index.html"
cp -r "${SCRIPT_DIR}/public/"* "$WEB_ROOT/"
rm -f "$WEB_ROOT/index.html"
cat > "$WEB_ROOT/.htaccess" <<EOF
DirectoryIndex index.php
EOF
chown -R "${WEB_USER}:${WEB_USER}" "$WEB_ROOT"
chmod -R 755 "$WEB_ROOT"

# Ensure Apache DirectoryIndex and permissions are configured globally
if [ -d /etc/httpd/conf.d ]; then
    cat > /etc/httpd/conf.d/callgen.conf <<EOF
<Directory "$WEB_ROOT">
    Options -Indexes +FollowSymLinks
    DirectoryIndex index.php
    AllowOverride All
    Require all granted
</Directory>
EOF
    systemctl restart httpd php-fpm 2>/dev/null || true
elif [ -d /etc/apache2/conf-available ]; then
    cat > /etc/apache2/conf-available/callgen.conf <<EOF
<Directory "$WEB_ROOT">
    Options -Indexes +FollowSymLinks
    DirectoryIndex index.php
    AllowOverride All
    Require all granted
</Directory>
EOF
    a2enconf callgen 2>/dev/null || true
    a2enmod rewrite 2>/dev/null || true
    systemctl restart apache2 2>/dev/null || true
fi

# 9. Sudoers Configuration (Allow Web User to manage switch)
SUDOERS_FILE="/etc/sudoers.d/callgen-dashboard"
echo "--- Configuring sudoers permissions ---"
cat > "$SUDOERS_FILE" <<EOF
Defaults:apache !requiretty
Defaults:www-data !requiretty
Defaults:nginx !requiretty
apache ALL=(root) NOPASSWD: /usr/local/sbin/callgen-control, /usr/local/sbin/callgen-sync-inbound.py, /usr/local/sbin/callgen_db.py, /usr/sbin/asterisk, /usr/local/sbin/asterisk, /usr/bin/asterisk, /usr/bin/systemctl, /bin/systemctl
www-data ALL=(root) NOPASSWD: /usr/local/sbin/callgen-control, /usr/local/sbin/callgen-sync-inbound.py, /usr/local/sbin/callgen_db.py, /usr/sbin/asterisk, /usr/local/sbin/asterisk, /usr/bin/asterisk, /usr/bin/systemctl, /bin/systemctl
nginx ALL=(root) NOPASSWD: /usr/local/sbin/callgen-control, /usr/local/sbin/callgen-sync-inbound.py, /usr/local/sbin/callgen_db.py, /usr/sbin/asterisk, /usr/local/sbin/asterisk, /usr/bin/asterisk, /usr/bin/systemctl, /bin/systemctl
EOF
chmod 440 "$SUDOERS_FILE"
if command -v visudo >/dev/null 2>&1; then
    visudo -cf "$SUDOERS_FILE"
fi

# 10. Firewall Configuration
if command -v firewall-cmd >/dev/null 2>&1 && systemctl is-active --quiet firewalld 2>/dev/null; then
    echo "--- Configuring firewall (firewalld) ---"
    firewall-cmd --permanent --add-service=http 2>/dev/null || true
    firewall-cmd --permanent --add-service=https 2>/dev/null || true
    firewall-cmd --permanent --add-port=5060/udp 2>/dev/null || true
    firewall-cmd --permanent --add-port=10000-20000/udp 2>/dev/null || true
    firewall-cmd --reload 2>/dev/null || true
elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    echo "--- Configuring firewall (ufw) ---"
    ufw allow http 2>/dev/null || true
    ufw allow https 2>/dev/null || true
    ufw allow 5060/udp 2>/dev/null || true
    ufw allow 10000:20000/udp 2>/dev/null || true
fi

echo ""
echo "=================================================================="
echo " M5-Traffic Controller Installation Complete!"
echo "=================================================================="
echo " Asterisk Version : $(asterisk -V 2>/dev/null || echo 'Running')"
echo " Credentials:"
echo "   Username: awais"
echo "   Password: dancom"
echo ""
echo " Management CLI:"
echo "   callgen-control status all"
echo "   callgen-control start all"
echo "   callgen-control stop all"
echo "   callgen-control hangup all"
echo ""
echo " Web UI:"
echo "   http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo '<your-server-ip>')/callgen/"
echo "=================================================================="
