# M5-Traffic Controller (VoIP Smart Switch)

A multi-trunk Asterisk Call Generation & Carrier-Grade Inbound Traffic Management Gateway.
Designed for high-throughput VoIP switches running on **Rocky Linux 9 / AlmaLinux 9 / CentOS Stream 9 / RHEL 9**.

---

## What is in this Git Repository?

This Git repository contains the complete production-grade system:
1. **VoIP Core & PJSIP Templates** (`asterisk/`):
   - `pjsip.conf`: Production PJSIP configuration with transport, trunk definitions, and strict anonymous blocking.
   - `extensions.conf`: Base security dialplan rejecting unauthorized calls with SIP 603 (`Hangup(21)`).
   - `cdr.conf` & `cdr_sqlite3_custom.conf`: High-performance SQLite3 CDR logging for real-time quota tracking.
2. **Automated Installers**:
   - `setup_asterisk.sh`: One-click script that installs all dependencies, downloads & compiles Asterisk with bundled PJSIP, configures Asterisk system services, and deploys the system.
   - `deploy.sh`: Deploys or updates the M5 dashboard, binaries, database schema, systemd services, and sudoers permissions.
3. **Core Binaries & Daemons** (`bin/`):
   - `callgen_db.py`: SQLite central configuration & CDR tracking engine (`/var/lib/callgen/callgen.db`).
   - `callgen-sync-inbound.py`: Dynamically generates `pjsip_inbound.conf` & `extensions_inbound.conf` to strictly enforce authorized source IPs and allowed breakout prefixes.
   - `callgen-control`: CLI utility to start, stop, hang up, and monitor all generation trunks.
   - `asterisk_callgen.sh`, `asterisk_callgen2.sh`, `asterisk_callgen3.sh`: Multi-threaded call generators for Cuba, Senegal, and Mali.
4. **Web Portal UI & REST API** (`public/`):
   - `index.php`: Enterprise SaaS web portal for real-time channel utilization, monthly limits, trunk configurations, and inbound IP security.
   - `api.php`: Authenticated REST API endpoint.
5. **Systemd Services** (`systemd/`):
   - `asterisk.service`: Systemd telephony service unit.
   - `asterisk-callgen-cuba.service`, `asterisk-callgen-senegal.service`, `asterisk-callgen-mali.service`: Auto-restarting background route services.

---

## How to Configure on a Brand New Server (Step-by-Step)

### Step 1: Server Requirements & Initial Setup
- Recommended OS: **Rocky Linux 9.x** (or AlmaLinux 9 / CentOS Stream 9) 64-bit.
- Minimum specs: 2 vCPU, 2-4 GB RAM, 20 GB Disk.
- Sign in as `root`:
  ```bash
  ssh root@<new-server-ip>
  ```

### Step 2: Install Git & Clone Repository
```bash
dnf install -y git
git clone https://github.com/Awaisnazir3/M5.git /root/M5
cd /root/M5
```

### Step 3: Run the Automated Installer

#### Case A: If Asterisk is NOT installed yet (Fresh Server)
Run the complete automated Asterisk & M5 installer:
```bash
chmod +x setup_asterisk.sh deploy.sh callgen-dashboard/callgen-dashboard/deploy.sh callgen-dashboard/callgen-dashboard/setup_asterisk.sh callgen-dashboard/callgen-dashboard/bin/*
sudo ./setup_asterisk.sh
```
*This will automatically:*
- Configure SELinux into permissive mode (preventing Apache sudo execution blocks).
- Enable CRB & EPEL repositories.
- Install compilation dependencies, Apache (`httpd`), PHP-FPM, PHP-PDO, SQLite, and Python3.
- Download and compile Asterisk LTS with bundled pjproject.
- Install `asterisk.service` and configure `/var/spool/asterisk` permissions.
- Deploy Asterisk configs (`pjsip.conf`, `extensions.conf`, `cdr_sqlite3_custom.conf`).
- Initialize `/var/lib/callgen/callgen.db` with permissions and symlink `/var/log/asterisk/master.db`.
- Install systemd services and configure sudoers.
- Deploy the Web Portal (`index.php`) to `/var/www/html/callgen`.
- Generate inbound security rules and reload Asterisk.

#### Case B: If Asterisk is ALREADY installed on the server
If Asterisk is already present on your server, simply run:
```bash
chmod +x deploy.sh callgen-dashboard/callgen-dashboard/deploy.sh callgen-dashboard/callgen-dashboard/bin/*
sudo ./deploy.sh
```

---

### Step 4: Open Firewall Ports
Open the required VoIP SIP, RTP media, and Web ports:
```bash
# Web Dashboard
firewall-cmd --permanent --add-service=http
firewall-cmd --permanent --add-service=https

# Asterisk SIP Signaling (UDP 5060)
firewall-cmd --permanent --add-port=5060/udp

# Asterisk RTP Audio Media Range (UDP 10000-20000)
firewall-cmd --permanent --add-port=10000-20000/udp

# Reload Firewall
firewall-cmd --reload
```

---

### Step 5: Access the Web Dashboard
1. Open your browser and navigate to:
   ```
   http://<new-server-ip>/callgen/
   ```
2. Login with default credentials:
   - **Username**: `awais`
   - **Password**: `dancom`

---

### Step 6: Configure Your Network & Routes

From the Web Dashboard:
1. **Destination Traffic Routes**:
   - Click **⚙️ Config** on any route (Cuba, Mali, Senegal).
   - Enter your target remote switch IP (e.g. `192.168.1.210`), dial prefix, country code, maximum channels, and monthly limit.
   - Click **Save Changes**.
2. **Authorized Inbound Source IPs (Remote PBX / Forwarders)**:
   - Click **+ Add Allowed Source IP**.
   - Enter the allowed IP (e.g. `192.168.1.13`), a note label, and comma-separated allowed breakout prefixes (e.g. `444453, 619334221, 2237`).
   - Click **Save / Authorize Gateway**.
   *(Any source IP or prefix NOT listed here will be instantly rejected with SIP 603 Declined).*
3. **Inbound Breakout & Context Duration Limits**:
   - Set maximum duration timeouts for `[testcall]` (Cuba/Senegal) and `[testcall2]` (Mali).
   - Click **💾 Save Breakout & Duration Settings**.

---

### Step 7: Useful Verification & CLI Commands

Verify Asterisk & PJSIP endpoints:
```bash
# Check loaded PJSIP endpoints & trunks
asterisk -rx "pjsip show endpoints"

# Check active channels & live calls
asterisk -rx "core show channels"

# Check CDR SQLite status
asterisk -rx "cdr show status"
```

Manage route engines via CLI:
```bash
# Check status of all trunks
callgen-control status all

# Start or stop specific routes
callgen-control start cuba
callgen-control stop senegal

# Emergency terminate live calls on a route
callgen-control hangup cuba
```
