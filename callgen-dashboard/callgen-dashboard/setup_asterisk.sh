#!/bin/bash
# ==============================================================================
# M5-Traffic Controller: Complete Asterisk 18 LTS Installer & Configurator
# Compatible with: Rocky Linux 9 / AlmaLinux 9 / CentOS Stream 9
# ==============================================================================
set -euo pipefail

if [ "$EUID" -ne 0 ]; then
  echo "Error: Must be run as root (e.g. sudo bash setup_asterisk.sh)" >&2
  exit 1
fi

echo "=================================================================="
echo " Starting M5 Asterisk VoIP Engine Installation on Rocky Linux 9"
echo "=================================================================="

# 1. Optimize DNF package manager (Disable slow mirror probing)
echo "[1/7] Optimizing DNF and resolving fast mirrors..."
sed -i 's/fastestmirror=True/fastestmirror=False/g' /etc/dnf/dnf.conf 2>/dev/null || true
grep -q "fastestmirror=False" /etc/dnf/dnf.conf 2>/dev/null || echo "fastestmirror=False" >> /etc/dnf/dnf.conf
grep -q "ip_resolve=4" /etc/dnf/dnf.conf 2>/dev/null || echo "ip_resolve=4" >> /etc/dnf/dnf.conf
dnf clean all

# 2. Enable CRB & EPEL repositories and install Web server
echo "[2/7] Enabling CRB (CodeReady Builder) & EPEL repos..."
dnf config-manager --set-enabled crb 2>/dev/null || true
dnf install -y epel-release httpd php php-json
systemctl enable --now httpd

# 3. Install build tools and Asterisk dependencies
echo "[3/7] Installing build dependencies..."
dnf install -y \
  gcc gcc-c++ make wget bzip2 patch tar \
  libedit-devel sqlite-devel libuuid-devel jansson-devel \
  libxml2-devel openssl-devel subversion speex-devel \
  libogg-devel libvorbis-devel alsa-lib-devel

# 4. Download and Compile Asterisk 18 LTS
echo "[4/7] Downloading and Compiling Asterisk 18 LTS..."
cd /usr/src
ASTERISK_VER="18.20.2"
if [ ! -d "asterisk-${ASTERISK_VER}" ]; then
  echo "Downloading Asterisk source tarball..."
  wget --no-check-certificate -q --show-progress "http://downloads.asterisk.org/pub/telephony/asterisk/releases/asterisk-${ASTERISK_VER}.tar.gz" -O "asterisk-${ASTERISK_VER}.tar.gz" || \
  wget --no-check-certificate -q --show-progress "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-18-current.tar.gz" -O "asterisk-${ASTERISK_VER}.tar.gz"
  tar -zxf "asterisk-${ASTERISK_VER}.tar.gz"
fi

cd "asterisk-${ASTERISK_VER}"*
./configure --with-jansson-bundled --without-pjproject-bundled --libdir=/usr/lib64
make -j"$(nproc)"
make install
make samples
make config
ldconfig

# 5. Create Asterisk User & Configure File Permissions
echo "[5/7] Configuring Asterisk system user and directories..."
id -u asterisk >/dev/null 2>&1 || useradd -m -d /var/lib/asterisk -s /sbin/nologin asterisk
mkdir -p /var/spool/asterisk/outgoing /tmp/asterisk_calls_cuba /tmp/asterisk_calls_senegal /tmp/asterisk_calls_mali /var/log/asterisk/cdr-csv
chmod 777 /var/spool/asterisk/outgoing /tmp/asterisk_calls_*
chown -R asterisk:asterisk /var/spool/asterisk /var/log/asterisk /var/lib/asterisk /var/run/asterisk /etc/asterisk

# Configure asterisk.conf user
sed -i 's/^;runuser = asterisk/runuser = asterisk/' /etc/asterisk/asterisk.conf 2>/dev/null || true
sed -i 's/^;rungroup = asterisk/rungroup = asterisk/' /etc/asterisk/asterisk.conf 2>/dev/null || true

# 6. Configure Portal-Specific VoIP Dialplan & SIP Engine
echo "[6/7] Writing M5-Traffic Controller Asterisk configurations..."

# A. /etc/asterisk/sip.conf
cat <<'EOF' > /etc/asterisk/sip.conf
[general]
context=default
allowguest=yes
srvlookup=no
udpbindaddr=0.0.0.0:5060
tcpenable=no
transport=udp
disallow=all
allow=ulaw
allow=alaw
allow=g729
qualify=no
canreinvite=no
nat=force_rport,comedia
externrefresh=15
localnet=192.168.0.0/255.255.0.0
localnet=10.0.0.0/255.0.0.0
localnet=172.16.0.0/255.240.0.0

[cuba-trunk]
type=peer
host=202.125.153.97
context=testcall2
insecure=port,invite

[senegal-trunk]
type=peer
host=206.251.255.21
context=testcall
insecure=port,invite

[mali-trunk]
type=peer
host=178.22.15.201
context=testcall
insecure=port,invite
EOF

# B. /etc/asterisk/extensions.conf
cat <<'EOF' > /etc/asterisk/extensions.conf
[general]
static=yes
writeprotect=no
clearglobalvars=no

[default]
exten => s,1,Hangup()

; Senegal & Mali route context
[testcall]
exten => s,1,NoOp(M5-Traffic Controller: Connected testcall route)
same => n,Answer()
same => n,Set(TIMEOUT(absolute)=600)
same => n,Playback(silence/1)
same => n,Wait(600)
same => n,Hangup()

; Cuba route context
[testcall2]
exten => s,1,NoOp(M5-Traffic Controller: Connected testcall2 Cuba route)
same => n,Answer()
same => n,Set(TIMEOUT(absolute)=550)
same => n,Playback(silence/1)
same => n,Wait(550)
same => n,Hangup()
EOF

# C. /etc/asterisk/cdr.conf & cdr_csv.conf (Required for live monthly quota calculation)
cat <<'EOF' > /etc/asterisk/cdr.conf
[general]
enable=yes
unanswered=yes
congestion=yes

[csv]
usegmtime=no
loguniqueid=yes
loguserfield=yes
accountlogs=yes
EOF

cat <<'EOF' > /etc/asterisk/cdr_csv.conf
[csv]
usegmtime=no
loguniqueid=yes
loguserfield=yes
EOF

touch /var/log/asterisk/cdr-csv/Master.csv
chown -R asterisk:asterisk /etc/asterisk /var/log/asterisk

# 7. Start and Enable Asterisk & Web Portal
echo "[7/7] Starting Asterisk & Web services..."
systemctl daemon-reload
systemctl enable --now asterisk
systemctl enable --now httpd

# Run Portal deployment
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/deploy.sh" ]; then
    bash "${SCRIPT_DIR}/deploy.sh"
fi

echo ""
echo "=================================================================="
echo " Asterisk & M5-Traffic Controller Installed Successfully!"
echo "=================================================================="
echo "Asterisk Version : $(asterisk -V 2>/dev/null || echo 'Installed')"
echo "SIP Status       : asterisk -rx 'sip show peers'"
echo "Channels Status  : asterisk -rx 'core show channels'"
echo "Web Portal       : http://$(hostname -I 2>/dev/null | awk '{print $1}')/callgen/"
echo "Portal User      : awais"
echo "Portal Pass      : dancom"
echo "=================================================================="

