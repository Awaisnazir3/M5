# M5-Traffic Controller (VoIP Smart Switch)

A multi-trunk Asterisk Call Generation & Traffic Management Dashboard designed for carrier-grade VoIP environments.

## Features

- **Multi-Route Control**: Real-time management for Cuba, Senegal, and Mali routes (or customizable destinations).
- **Custom CallerID & Dial Prefix**: Easily assign dynamic prefixes per trunk (e.g. `444453`, `619334221`, `2237`) without touching shell scripts.
- **Strict Monthly Quota Enforcement**: Real-time CDR tracking that automatically cuts off call generation when limits are reached (`⛔ LIMIT REACHED`).
- **Live Concurrent Channel Throttling**: Asterisk 1.8 & 16+ compatible active channel tracking with burst headroom safeguards.
- **Dual Runtime Support**:
  - **Linux Asterisk Server**: Native bash generators, systemd unit files, PHP/Apache API, and `callgen-control` daemon CLI.
  - **Local Windows Controller**: Node.js standalone runtime with local app window for testing and monitoring.
- **Enterprise Design**: Sage Green / Clean White / Deep Espresso Brown interface with auto-sync and persistent inputs.

---

## Project Structure

```text
├── server.js                        # Node.js local dashboard server
├── index.html                       # Standalone local frontend
├── callgen-config.json              # Local configuration store
├── start-dashboard.bat              # One-click Windows runner
├── stop-dashboard.bat               # Windows stop helper
├── .gitignore                       # Git ignore rules
└── callgen-dashboard/               # Linux Asterisk production package
    └── callgen-dashboard/
        ├── bin/
        │   ├── asterisk_callgen.sh  # Cuba generator engine
        │   ├── asterisk_callgen2.sh # Senegal generator engine
        │   ├── asterisk_callgen3.sh # Mali generator engine
        │   └── callgen-control      # Root CLI control utility
        ├── public/
        │   ├── index.php            # Production web UI
        │   ├── api.php              # REST API endpoint
        │   └── index.html           # HTML fallback
        ├── systemd/                 # Systemd service unit files
        ├── sudoers/                 # Non-root sudo permission config
        └── deploy.sh                # Automated Linux installation script
```

---

## Getting Started

### Local Development (Windows)
1. Install [Node.js](https://nodejs.org/).
2. Run `start-dashboard.bat` or execute:
   ```cmd
   node server.js
   ```
3. Open `http://localhost:3000/` in your browser.
4. Default credentials:
   - **User**: `awais`
   - **Password**: `dancom`

### Production Server (Linux / Asterisk)
1. Copy the deployment package to your Asterisk server:
   ```bash
   scp -r callgen-dashboard/ root@<server-ip>:/tmp/
   ```
2. Run the deployment script:
   ```bash
   cd /tmp/callgen-dashboard/callgen-dashboard
   sudo bash deploy.sh
   ```
3. Manage routes from CLI:
   ```bash
   callgen-control status all
   callgen-control start cuba
   callgen-control stop senegal
   ```

