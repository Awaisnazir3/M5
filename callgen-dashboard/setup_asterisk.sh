#!/bin/bash
# ==============================================================================
# M5-Traffic Controller: Complete Automated Asterisk VoIP Installer & Configurator
# Compatible with: Rocky Linux 9 / AlmaLinux 9 / CentOS Stream 9 / RHEL 9
# Features: Asterisk 23/20 PJSIP VoIP engine, SQLite3 CDR, Multi-Trunk Engine & Web Portal
# ==============================================================================
set -euo pipefail

if [ "$EUID" -ne 0 ]; then
  echo "Error: Must be run as root (e.g. sudo bash setup_asterisk.sh)" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "=================================================================="
echo " Starting M5 Asterisk VoIP Engine Installation"
echo "=================================================================="

# 0. SELinux handling (prevent permission blocks on RHEL/Rocky)
if command -v setenforce >/dev/null 2>&1; then
    setenforce 0 2>/dev/null || true
    if [ -f /etc/selinux/config ]; then
        sed -i 's/^SELINUX=enforcing/SELINUX=permissive/' /etc/selinux/config 2>/dev/null || true
    fi
fi

# 1. Optimize DNF package manager (Fast mirrors & IPv4 preferred)
if command -v dnf >/dev/null 2>&1; then
    echo "[1/7] Configuring DNF package manager..."
    sed -i 's/fastestmirror=True/fastestmirror=False/g' /etc/dnf/dnf.conf 2>/dev/null || true
    grep -q "fastestmirror=False" /etc/dnf/dnf.conf 2>/dev/null || echo "fastestmirror=False" >> /etc/dnf/dnf.conf
    grep -q "ip_resolve=4" /etc/dnf/dnf.conf 2>/dev/null || echo "ip_resolve=4" >> /etc/dnf/dnf.conf
    dnf clean all 2>/dev/null || true

    # 2. Enable CRB (CodeReady Builder) & EPEL repos and install Web server & PHP
    echo "[2/7] Enabling CRB & EPEL repositories..."
    dnf config-manager --set-enabled crb 2>/dev/null || true
    dnf install -y epel-release httpd php php-fpm php-pdo php-sqlite3 php-json php-cli python3 sqlite 2>/dev/null || \
    dnf install -y epel-release httpd php php-fpm php-pdo php-json python3 sqlite
    systemctl enable --now httpd php-fpm

    # 3. Install build tools and Asterisk dependencies
    echo "[3/7] Installing build dependencies..."
    dnf install -y \
      gcc gcc-c++ make wget bzip2 patch tar \
      libedit-devel sqlite-devel libuuid-devel jansson-devel \
      libxml2-devel openssl-devel subversion speex-devel \
      libogg-devel libvorbis-devel alsa-lib-devel
fi

# 4. Download and Compile Asterisk (with bundled pjproject for PJSIP)
echo "[4/7] Checking Asterisk installation..."
if ! command -v asterisk >/dev/null 2>&1; then
    echo "Asterisk not found. Downloading and compiling Asterisk..."
    cd /usr/src
    ASTERISK_VER="23.6.0"
    if [ ! -d "asterisk-${ASTERISK_VER}" ]; then
        echo "Downloading Asterisk source..."
        wget --no-check-certificate -q --show-progress "https://downloads.asterisk.org/pub/telephony/asterisk/releases/asterisk-${ASTERISK_VER}.tar.gz" -O "asterisk-${ASTERISK_VER}.tar.gz" || \
        wget --no-check-certificate -q --show-progress "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-23-current.tar.gz" -O "asterisk-${ASTERISK_VER}.tar.gz" || \
        wget --no-check-certificate -q --show-progress "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-20-current.tar.gz" -O "asterisk-${ASTERISK_VER}.tar.gz"
        tar -zxf "asterisk-${ASTERISK_VER}.tar.gz"
    fi

    cd "asterisk-"*
    echo "Configuring Asterisk with bundled PJSIP..."
    ./configure --with-pjproject-bundled --prefix=/usr --sysconfdir=/etc --localstatedir=/var --with-jansson-bundled
    echo "Compiling Asterisk (using $(nproc) cores)..."
    make -j"$(nproc)"
    make install
    make samples 2>/dev/null || true
    ldconfig
else
    echo "Asterisk is already installed: $(asterisk -V 2>/dev/null)"
fi

# 5. Create Asterisk User & Configure Directories
echo "[5/7] Configuring Asterisk system user and directories..."
id -u asterisk >/dev/null 2>&1 || useradd -m -d /var/lib/asterisk -s /sbin/nologin asterisk
mkdir -p /var/spool/asterisk/outgoing /tmp/asterisk_calls_cuba /tmp/asterisk_calls_senegal /tmp/asterisk_calls_mali /var/log/asterisk/cdr-csv /etc/asterisk
chmod 777 /var/spool/asterisk/outgoing /tmp/asterisk_calls_*
chown -R asterisk:asterisk /var/spool/asterisk /var/log/asterisk /var/lib/asterisk /etc/asterisk

# Install Asterisk systemd unit file
if [ -f "${SCRIPT_DIR}/systemd/asterisk.service" ]; then
    install -m 644 "${SCRIPT_DIR}/systemd/asterisk.service" /etc/systemd/system/asterisk.service
    systemctl daemon-reload
fi

# 6. Install Base Asterisk Configurations
echo "[6/7] Deploying Asterisk PJSIP, Dialplan, and CDR configurations..."
if [ -f "${SCRIPT_DIR}/asterisk/pjsip.conf" ]; then
    cp "${SCRIPT_DIR}/asterisk/pjsip.conf" /etc/asterisk/pjsip.conf
fi
if [ -f "${SCRIPT_DIR}/asterisk/extensions.conf" ]; then
    cp "${SCRIPT_DIR}/asterisk/extensions.conf" /etc/asterisk/extensions.conf
fi
if [ -f "${SCRIPT_DIR}/asterisk/cdr.conf" ]; then
    cp "${SCRIPT_DIR}/asterisk/cdr.conf" /etc/asterisk/cdr.conf
fi
if [ -f "${SCRIPT_DIR}/asterisk/cdr_sqlite3_custom.conf" ]; then
    cp "${SCRIPT_DIR}/asterisk/cdr_sqlite3_custom.conf" /etc/asterisk/cdr_sqlite3_custom.conf
fi
chown -R asterisk:asterisk /etc/asterisk

# 7. Run M5 Portal and Call Generator Deployment
echo "[7/7] Running M5-Traffic Controller deployment..."
if [ -f "${SCRIPT_DIR}/deploy.sh" ]; then
    bash "${SCRIPT_DIR}/deploy.sh"
fi

# Start Asterisk service
systemctl enable --now asterisk || true
systemctl restart asterisk || true

echo ""
echo "=================================================================="
echo " M5 Asterisk Engine & Web Portal Successfully Installed!"
echo "=================================================================="
echo " Asterisk Version : $(asterisk -V 2>/dev/null || echo 'Installed')"
echo " PJSIP Endpoints  : asterisk -rx 'pjsip show endpoints'"
echo " Channels Live    : asterisk -rx 'core show channels'"
echo " Web Portal       : http://$(hostname -I 2>/dev/null | awk '{print $1}')/callgen/"
echo " Login User       : awais"
echo " Login Password   : dancom"
echo "=================================================================="
