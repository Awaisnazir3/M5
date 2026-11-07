const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = process.env.PORT || 3000;
const USERNAME = process.env.CALLGEN_USERNAME || 'awais';
const PASSWORD = process.env.CALLGEN_PASSWORD || 'dancom';
const CONFIG_FILE = path.join(__dirname, 'callgen-config.json');

// Default initial multi-trunk configuration
const DEFAULT_CONFIG = {
  cuba: {
    id: "cuba",
    name: "Cuba",
    country_code: "CU",
    target_ip: "202.125.153.97",
    monthly_limit: 100000,
    max_channels: 150,
    call_duration: 600,
    context: "testcall2",
    prefix_cid: "2237",
    ani_prefix: "96655",
    running: false,
    used_minutes: 1245.5,
    active_calls: 0,
    limit_reached: false,
    last_action: "System initialized"
  },
  senegal: {
    id: "senegal",
    name: "Senegal",
    country_code: "SN",
    target_ip: "206.251.255.21",
    monthly_limit: 250000,
    max_channels: 400,
    call_duration: 600,
    context: "testcall",
    prefix_cid: "444453",
    ani_prefix: "+442",
    running: false,
    used_minutes: 3840.2,
    active_calls: 0,
    limit_reached: false,
    last_action: "System initialized"
  },
  mali: {
    id: "mali",
    name: "Mali",
    country_code: "ML",
    target_ip: "178.22.15.201",
    monthly_limit: 150000,
    max_channels: 220,
    call_duration: 600,
    context: "testcall",
    prefix_cid: "619334221",
    ani_prefix: "442",
    running: false,
    used_minutes: 890.0,
    active_calls: 0,
    limit_reached: false,
    last_action: "System initialized"
  }
};

let trunks = JSON.parse(JSON.stringify(DEFAULT_CONFIG));

// Load saved config if exists
if (fs.existsSync(CONFIG_FILE)) {
  try {
    const saved = JSON.parse(fs.readFileSync(CONFIG_FILE, 'utf8'));
    for (const key of Object.keys(DEFAULT_CONFIG)) {
      if (saved[key]) {
        trunks[key] = { ...DEFAULT_CONFIG[key], ...saved[key] };
        // Enforce limit check on load
        if (trunks[key].used_minutes >= trunks[key].monthly_limit) {
          trunks[key].running = false;
          trunks[key].active_calls = 0;
          trunks[key].limit_reached = true;
        }
      }
    }
  } catch (e) {
    console.error("Could not load config file, using defaults:", e.message);
  }
}

function saveConfig() {
  try {
    fs.writeFileSync(CONFIG_FILE, JSON.stringify(trunks, null, 2), 'utf8');
  } catch (e) {
    console.error("Failed to save config:", e.message);
  }
}

// Log history buffer
let logs = [
  `[${new Date().toLocaleTimeString()}] M5-Traffic Controller Core initialized.`,
  `[${new Date().toLocaleTimeString()}] VoIP Smart Switch ready on port ${PORT}.`
];

function addLog(msg) {
  const line = `[${new Date().toLocaleTimeString()}] ${msg}`;
  logs.unshift(line);
  if (logs.length > 50) logs.pop();
}

// STRICT Quota Enforcer & Simulation Loop
setInterval(() => {
  let changed = false;
  for (const id of Object.keys(trunks)) {
    const t = trunks[id];

    // Check if usage already reached or exceeded monthly quota
    if (t.used_minutes >= t.monthly_limit) {
      if (t.running || t.active_calls > 0 || !t.limit_reached) {
        t.running = false;
        t.active_calls = 0;
        t.limit_reached = true;
        addLog(`⛔ [${t.name}] Monthly limit reached (${t.used_minutes}m >= ${t.monthly_limit}m). Route auto-stopped.`);
        changed = true;
      }
      continue;
    }

    t.limit_reached = false;

    if (t.running) {
      // Calculate remaining minutes
      const remMinutes = t.monthly_limit - t.used_minutes;
      const callDurMin = Math.max(1, (t.call_duration || 600) / 60);

      // Maximum calls we can place without overshooting monthly cap
      const maxCallsAllowed = Math.floor(remMinutes / callDurMin);

      if (maxCallsAllowed <= 0) {
        // Quota cannot sustain even 1 more call duration
        t.running = false;
        t.active_calls = 0;
        t.limit_reached = true;
        addLog(`⛔ [${t.name}] Remaining quota (${remMinutes.toFixed(1)}m) insufficient for call duration (${callDurMin}m). Route auto-stopped.`);
        changed = true;
      } else {
        const effectiveCap = Math.min(t.max_channels, maxCallsAllowed);
        const maxRand = Math.max(1, Math.min(effectiveCap, Math.floor(effectiveCap * 0.35)));
        const minRand = Math.max(1, Math.floor(maxRand * 0.4));
        t.active_calls = Math.floor(Math.random() * (maxRand - minRand + 1)) + minRand;

        // Increment minutes strictly capped at monthly_limit
        const added = Math.round((t.active_calls * 0.03) * 10) / 10;
        const newUsed = Math.round((t.used_minutes + added) * 10) / 10;

        if (newUsed >= t.monthly_limit) {
          t.used_minutes = t.monthly_limit;
          t.running = false;
          t.active_calls = 0;
          t.limit_reached = true;
          addLog(`⛔ [${t.name}] Monthly limit reached exactly (${t.monthly_limit}m). Route auto-stopped.`);
        } else {
          t.used_minutes = newUsed;
        }
        changed = true;
      }
    } else {
      if (t.active_calls !== 0) {
        t.active_calls = 0;
        changed = true;
      }
    }
  }
  if (changed) saveConfig();
}, 3000);

function parseCookies(req) {
  const list = {};
  const rc = req.headers.cookie;
  if (rc) {
    rc.split(';').forEach(cookie => {
      const parts = cookie.split('=');
      list[parts.shift().trim()] = decodeURI(parts.join('='));
    });
  }
  return list;
}

const loginHtml = (err) => `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>M5-Traffic Controller | Sign In</title>
  <style>
    :root {
      --sage: #7C8E76;
      --sage-dark: #65755F;
      --brown: #4B3830;
      --brown-dark: #372822;
      --bg: #F5F6F4;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      color: #2E231E;
    }
    .login-container {
      background: #FFFFFF;
      border-radius: 14px;
      box-shadow: 0 10px 30px rgba(75, 56, 48, 0.08);
      border: 1px solid #E2E5DF;
      width: 380px;
      overflow: hidden;
    }
    .header {
      background: var(--brown);
      color: #FFFFFF;
      padding: 28px 24px 24px;
      text-align: center;
      border-bottom: 3px solid var(--sage);
    }
    .header .badge {
      display: inline-block;
      background: var(--sage);
      color: #FFFFFF;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 4px 10px;
      border-radius: 4px;
      margin-bottom: 10px;
    }
    .header h1 {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: -0.3px;
    }
    .header p {
      font-size: 13px;
      opacity: 0.85;
      margin-top: 4px;
      color: #EADCD6;
    }
    .form-wrap {
      padding: 28px 24px;
    }
    .field {
      margin-bottom: 18px;
    }
    .field label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: var(--brown);
      margin-bottom: 6px;
    }
    .field input {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid #CCD2C8;
      border-radius: 8px;
      font-size: 14px;
      transition: border-color 0.15s, box-shadow 0.15s;
      outline: none;
    }
    .field input:focus {
      border-color: var(--sage);
      box-shadow: 0 0 0 3px rgba(124, 142, 118, 0.25);
    }
    button {
      width: 100%;
      background: var(--sage);
      color: #FFFFFF;
      border: 0;
      padding: 13px;
      border-radius: 8px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s;
    }
    button:hover {
      background: var(--sage-dark);
    }
    .error-msg {
      background: #FDF0F0;
      border: 1px solid #F6D3D3;
      color: #A62B2B;
      font-size: 13px;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 16px;
      text-align: center;
    }
    .auth-hint {
      margin-top: 20px;
      background: #F6F8F5;
      border: 1px dashed #CCD2C8;
      border-radius: 6px;
      padding: 10px;
      font-size: 12px;
      color: #555E52;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="header">
      <span class="badge">VoIP Smart Switch</span>
      <h1>M5-Traffic Controller</h1>
      <p>Trunk Routing & Call Flow Administration</p>
    </div>
    <div class="form-wrap">
      ${err ? `<div class="error-msg">${err}</div>` : ''}
      <form method="post" action="/login">
        <div class="field">
          <label for="username">Username</label>
          <input id="username" type="text" name="username" value="awais" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" type="password" name="password" value="dancom" required autocomplete="current-password">
        </div>
        <button type="submit">Access Controller</button>
        <div class="auth-hint">
          User: <b>awais</b> &bull; Password: <b>dancom</b>
        </div>
      </form>
    </div>
  </div>
</body>
</html>`;

const dashboardHtml = `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>M5-Traffic Controller | VoIP Smart Switch</title>
  <style>
    :root {
      --sage: #7C8E76;
      --sage-dark: #65755F;
      --sage-light: #EDF2EC;
      --brown: #4B3830;
      --brown-dark: #372822;
      --brown-light: #F4EFEB;
      --bg: #F5F6F4;
      --card-bg: #FFFFFF;
      --border: #DFE3DD;
      --text: #2D231E;
      --muted: #6C726A;
      --red: #A62B2B;
      --red-bg: #FDF0F0;
      --green: #24753D;
      --green-bg: #EDF7F0;
      --amber: #B54708;
      --amber-bg: #FFFAEB;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      font-size: 14px;
      line-height: 1.5;
    }
    /* Top Navbar */
    .navbar {
      background: var(--brown);
      color: #FFFFFF;
      padding: 0 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 64px;
      border-bottom: 3px solid var(--sage);
      box-shadow: 0 2px 10px rgba(0,0,0,0.08);
    }
    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .brand-icon {
      background: var(--sage);
      width: 38px;
      height: 38px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
      font-size: 18px;
      color: #FFFFFF;
    }
    .brand-text h1 {
      font-size: 18px;
      font-weight: 750;
      letter-spacing: -0.3px;
      line-height: 1.2;
    }
    .brand-text span {
      font-size: 11px;
      color: #EADCD6;
      text-transform: uppercase;
      letter-spacing: 0.8px;
    }
    .nav-actions {
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .user-pill {
      background: rgba(255,255,255,0.1);
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
      border: 1px solid rgba(255,255,255,0.15);
    }
    .user-dot {
      width: 8px;
      height: 8px;
      background: #4ADE80;
      border-radius: 50%;
    }
    .btn-logout {
      background: var(--sage);
      color: #FFFFFF;
      text-decoration: none;
      padding: 7px 15px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 13px;
      transition: background 0.15s;
    }
    .btn-logout:hover {
      background: var(--sage-dark);
    }

    /* Container */
    .container {
      max-width: 1320px;
      margin: 24px auto;
      padding: 0 20px 40px;
    }

    /* Master Summary Bar */
    .master-bar {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 16px 20px;
      margin-bottom: 22px;
      box-shadow: 0 2px 8px rgba(75, 56, 48, 0.04);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 20px;
      flex-wrap: wrap;
    }
    .master-stats {
      display: flex;
      align-items: center;
      gap: 14px;
      flex-wrap: wrap;
      flex: 1;
    }
    .stat-card {
      background: #F8FAF7;
      border: 1px solid #E4E8E2;
      border-radius: 10px;
      padding: 10px 16px;
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 185px;
      transition: all 0.15s ease;
    }
    .stat-card:hover {
      border-color: #CFD7CC;
      background: #F3F7F1;
    }
    .stat-icon-wrap {
      width: 38px;
      height: 38px;
      border-radius: 8px;
      background: #EAF0E8;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }
    .stat-details {
      display: flex;
      flex-direction: column;
    }
    .stat-label {
      font-size: 10.5px;
      font-weight: 750;
      text-transform: uppercase;
      color: var(--muted);
      letter-spacing: 0.6px;
    }
    .stat-val {
      font-size: 21px;
      font-weight: 800;
      color: var(--brown);
      line-height: 1.2;
      margin-top: 2px;
    }
    .stat-val-status {
      font-size: 13.5px;
      font-weight: 750;
      color: var(--muted);
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 3px;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #9AA096;
      display: inline-block;
    }
    .stat-val-status.active {
      color: var(--green);
    }
    .stat-val-status.active .badge-dot {
      background: #16B364;
      box-shadow: 0 0 0 3px rgba(22, 179, 100, 0.2);
    }
    .stat-sub {
      font-size: 11px;
      color: var(--muted);
      margin-top: 2px;
    }
    .master-actions {
      display: flex;
      gap: 10px;
      align-items: center;
    }
    .btn-lg {
      padding: 11px 18px;
      font-size: 13.5px;
      font-weight: 700;
      border-radius: 8px;
      box-shadow: 0 2px 5px rgba(0,0,0,0.06);
    }

    /* Tabs Navigation - Small Tab Buttons */
    .tabs-nav {
      display: flex;
      gap: 6px;
      margin-bottom: 18px;
      border-bottom: 2px solid #DFE3DD;
      overflow-x: auto;
    }
    .tab-btn {
      background: #F4F6F3;
      border: 1px solid #DFE3DD;
      border-bottom: none;
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 650;
      color: var(--muted);
      cursor: pointer;
      border-radius: 6px 6px 0 0;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      position: relative;
      transition: all 0.15s ease;
      white-space: nowrap;
    }
    .tab-btn:hover {
      color: var(--brown);
      background: #EAEFE8;
    }
    .tab-btn.active {
      color: var(--brown);
      background: #FFFFFF;
      font-weight: 700;
      box-shadow: 0 -2px 6px rgba(75, 56, 48, 0.04);
      border-color: #DFE3DD;
      border-top: 2px solid var(--sage);
      border-bottom: 2px solid #FFFFFF;
      margin-bottom: -2px;
    }
    .tab-view {
      display: block;
    }
    .tab-view.hidden {
      display: none;
    }
    .tab-flag {
      background: var(--brown-light);
      color: var(--brown);
      font-weight: 800;
      font-size: 10px;
      padding: 1px 5px;
      border-radius: 4px;
      border: 1px solid #DBCFC9;
      display: inline-block;
      line-height: 1.3;
    }
    .btn-sm {
      padding: 6px 12px;
      font-size: 12px;
      font-weight: 650;
      border-radius: 6px;
    }
    .config-single-wrap {
      max-width: 680px;
      margin: 0 auto 24px auto;
    }
    .config-card-solo {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 4px 14px rgba(0,0,0,0.04);
    }

    /* Config Summary Grid on Dashboard Cards */
    .config-summary-box {
      background: #F8F9F7;
      border: 1px solid #E6EAE3;
      border-radius: 8px;
      padding: 10px 12px;
    }
    .config-summary-header {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--muted);
      margin-bottom: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .config-summary-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 8px;
    }
    .summary-item {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .summary-lbl {
      font-size: 10px;
      font-weight: 600;
      text-transform: uppercase;
      color: var(--muted);
    }
    .summary-val {
      font-size: 12px;
      font-weight: 700;
      color: var(--brown);
      font-family: inherit;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    /* Section Title */
    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
    }
    .section-title {
      font-size: 17px;
      font-weight: 750;
      color: var(--brown);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    /* 3-Column Trunk Grid */
    .trunk-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
      margin-bottom: 24px;
    }
    .trunk-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      box-shadow: 0 3px 10px rgba(0,0,0,0.03);
      display: flex;
      flex-direction: column;
      transition: transform 0.15s, box-shadow 0.15s;
    }
    .trunk-card:hover {
      box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .trunk-header {
      background: #FAFBF9;
      border-bottom: 1px solid var(--border);
      padding: 16px 18px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .trunk-identity {
      display: flex;
      align-items: center;
      gap: 10px;
      flex: 1;
    }
    .flag-badge {
      background: var(--brown-light);
      color: var(--brown);
      font-weight: 800;
      font-size: 13px;
      padding: 4px 8px;
      border-radius: 6px;
      letter-spacing: 0.5px;
      border: 1px solid #DBCFC9;
      min-width: 32px;
      text-align: center;
      transition: all 0.2s ease;
    }
    .trunk-title-wrap {
      display: inline-flex;
      align-items: center;
      gap: 4px;
    }
    .trunk-title-input {
      font-size: 17px;
      font-weight: 750;
      color: var(--brown);
      background: transparent;
      border: 1px solid transparent;
      border-radius: 6px;
      padding: 2px 6px;
      width: 130px;
      font-family: inherit;
      transition: all 0.2s ease;
    }
    .trunk-title-input:hover {
      background: #FFFFFF;
      border-color: #D2D7CF;
    }
    .trunk-title-input:focus {
      background: #FFFFFF;
      border-color: var(--sage);
      box-shadow: 0 0 0 2px rgba(124, 142, 118, 0.25);
      outline: none;
      width: 150px;
    }
    .title-edit-icon {
      font-size: 11px;
      color: var(--muted);
      opacity: 0.35;
      cursor: pointer;
      user-select: none;
      transition: opacity 0.15s;
    }
    .trunk-title-wrap:hover .title-edit-icon {
      opacity: 0.9;
    }
    .trunk-name {
      font-size: 17px;
      font-weight: 750;
      color: var(--brown);
    }
    .trunk-status {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.5px;
      padding: 4px 10px;
      border-radius: 20px;
      text-transform: uppercase;
      display: inline-block;
    }
    .status-running {
      background: var(--green-bg);
      color: var(--green);
      border: 1px solid #C4E7D0;
    }
    .status-stopped {
      background: var(--red-bg);
      color: var(--red);
      border: 1px solid #F8CCCC;
    }
    .status-limit {
      background: #FEF3F2;
      color: #B42318;
      border: 1px solid #FECDCA;
      font-weight: 800;
    }

    .trunk-body {
      padding: 18px;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 16px;
    }

    /* Live Channel Gauge Box */
    .metric-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      background: #F8F9F7;
      padding: 12px;
      border-radius: 8px;
      border: 1px solid #E8EBE6;
    }
    .metric-item-val {
      font-size: 20px;
      font-weight: 800;
      color: var(--brown);
    }
    .metric-item-lbl {
      font-size: 11px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
    }

    /* Quota Bar */
    .progress-wrap {
      margin-top: 4px;
    }
    .progress-label-row {
      display: flex;
      justify-content: space-between;
      font-size: 12px;
      margin-bottom: 5px;
      font-weight: 500;
      color: var(--muted);
    }
    .progress-bar-bg {
      height: 8px;
      background: #EAECE8;
      border-radius: 4px;
      overflow: hidden;
    }
    .progress-bar-fill {
      height: 100%;
      background: var(--sage);
      border-radius: 4px;
      transition: width 0.3s;
    }

    /* Form Fields */
    .fields-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
    }
    .field-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .field-group.full {
      grid-column: span 2;
    }
    .field-group label {
      font-size: 11px;
      font-weight: 600;
      color: var(--muted);
      text-transform: uppercase;
    }
    .field-group input, .field-group select {
      padding: 8px 10px;
      border: 1px solid #CCD2C8;
      border-radius: 6px;
      font-size: 13px;
      font-family: inherit;
      color: var(--text);
      background: #FFFFFF;
      outline: none;
    }
    .field-group input:focus {
      border-color: var(--sage);
      box-shadow: 0 0 0 2px rgba(124, 142, 118, 0.2);
    }

    /* Card Actions */
    .card-actions {
      display: flex;
      gap: 8px;
      margin-top: auto;
      padding-top: 14px;
      border-top: 1px solid #EBEFEA;
    }

    /* Buttons */
    .btn {
      border: none;
      border-radius: 6px;
      padding: 9px 14px;
      font-size: 13px;
      font-weight: 650;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: background 0.15s, opacity 0.15s;
    }
    .btn-sage {
      background: var(--sage);
      color: #FFFFFF;
    }
    .btn-sage:hover {
      background: var(--sage-dark);
    }
    .btn-brown {
      background: var(--brown);
      color: #FFFFFF;
    }
    .btn-brown:hover {
      background: var(--brown-dark);
    }
    .btn-red {
      background: var(--red);
      color: #FFFFFF;
    }
    .btn-red:hover {
      background: #8D1F1F;
    }
    .btn-outline {
      background: #FFFFFF;
      color: var(--brown);
      border: 1px solid #CCD2C8;
    }
    .btn-outline:hover {
      background: #F4F6F3;
    }
    .flex-1 { flex: 1; }

    /* Bottom Console */
    .console-card {
      background: #1B1815;
      border-radius: 10px;
      border: 1px solid #2F2A25;
      padding: 12px 18px;
      color: #D6DDD3;
      box-shadow: 0 2px 6px rgba(0,0,0,0.04);
    }
    .console-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
      padding-bottom: 6px;
      border-bottom: 1px solid rgba(255,255,255,0.07);
    }
    .console-title {
      font-size: 11px;
      font-weight: 750;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: #9AB094;
    }
    .console-logs {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
      font-size: 11.5px;
      height: 80px;
      overflow-y: auto;
      line-height: 1.55;
      color: #A3E6A1;
    }
    .console-logs div {
      white-space: pre-wrap;
      word-break: break-all;
    }

    @media(max-width: 1080px) {
      .trunk-grid { grid-template-columns: 1fr; }
      .master-bar { grid-template-columns: 1fr 1fr; }
    }
  </style>
</head>
<body>
  <!-- Top Navigation -->
  <header class="navbar">
    <div class="brand">
      <div class="brand-icon">M5</div>
      <div class="brand-text">
        <h1>M5-Traffic Controller</h1>
        <span>VoIP Smart Switch</span>
      </div>
    </div>
    <div class="nav-actions">
      <div class="user-pill">
        <div class="user-dot"></div>
        <span>Operator: <b>awais</b></span>
      </div>
      <a href="?logout=1" class="btn-logout">Sign Out</a>
    </div>
  </header>

  <main class="container">
    <!-- Tabs Navigation -->
    <div class="tabs-nav">
      <button class="tab-btn active" id="tab-btn-dashboard" onclick="switchTab('dashboard')">
        <span>📊</span> Live Traffic Dashboard
      </button>
      <button class="tab-btn" id="tab-btn-cuba" onclick="switchTab('cuba')">
        <span class="tab-flag" id="tab-flag-cuba">CU</span><span id="tab-label-cuba">Cuba Configuration</span>
      </button>
      <button class="tab-btn" id="tab-btn-senegal" onclick="switchTab('senegal')">
        <span class="tab-flag" id="tab-flag-senegal">SN</span><span id="tab-label-senegal">Senegal Configuration</span>
      </button>
      <button class="tab-btn" id="tab-btn-mali" onclick="switchTab('mali')">
        <span class="tab-flag" id="tab-flag-mali">ML</span><span id="tab-label-mali">Mali Configuration</span>
      </button>
    </div>

    <!-- TAB 1: Live Dashboard View -->
    <div id="view-dashboard" class="tab-view">
      <div class="section-header">
        <div>
          <div class="section-title">
            <span>Destination Traffic Routes</span>
          </div>
          <div style="font-size:12px; color:var(--muted); margin-top:2px;">
            Live Asterisk channels & quota monitoring
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
          <button class="btn btn-sage btn-sm" onclick="startAll()">▶ Start All Trunks</button>
          <button class="btn btn-red btn-sm" onclick="stopAll()">⏹ Stop All Trunks</button>
        </div>
      </div>
      <div class="trunk-grid" id="trunk-container"></div>
    </div>

    <!-- TAB 2: Cuba Configuration View -->
    <div id="view-config-cuba" class="tab-view hidden">
      <div class="section-header">
        <div class="section-title">
          <span class="flag-badge" id="cfg-hdr-flag-cuba">CU</span>
          <span id="cfg-hdr-title-cuba">Cuba Configuration</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <span id="cfg-hdr-status-cuba" class="trunk-status"></span>
          <button class="btn btn-outline" style="font-size:12px;padding:6px 14px;" onclick="switchTab('dashboard')">← Back to Dashboard</button>
        </div>
      </div>
      <div class="config-single-wrap" id="config-box-cuba"></div>
    </div>

    <!-- TAB 3: Senegal Configuration View -->
    <div id="view-config-senegal" class="tab-view hidden">
      <div class="section-header">
        <div class="section-title">
          <span class="flag-badge" id="cfg-hdr-flag-senegal">SN</span>
          <span id="cfg-hdr-title-senegal">Senegal Configuration</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <span id="cfg-hdr-status-senegal" class="trunk-status"></span>
          <button class="btn btn-outline" style="font-size:12px;padding:6px 14px;" onclick="switchTab('dashboard')">← Back to Dashboard</button>
        </div>
      </div>
      <div class="config-single-wrap" id="config-box-senegal"></div>
    </div>

    <!-- TAB 4: Mali Configuration View -->
    <div id="view-config-mali" class="tab-view hidden">
      <div class="section-header">
        <div class="section-title">
          <span class="flag-badge" id="cfg-hdr-flag-mali">ML</span>
          <span id="cfg-hdr-title-mali">Mali Configuration</span>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <span id="cfg-hdr-status-mali" class="trunk-status"></span>
          <button class="btn btn-outline" style="font-size:12px;padding:6px 14px;" onclick="switchTab('dashboard')">← Back to Dashboard</button>
        </div>
      </div>
      <div class="config-single-wrap" id="config-box-mali"></div>
    </div>

    </main>

  <script>
    const TRUNK_META = {
      cuba: { script: "asterisk_callgen.sh" },
      senegal: { script: "asterisk_callgen2.sh" },
      mali: { script: "asterisk_callgen3.sh" }
    };

    const COUNTRY_CODES = {
      'cuba': 'CU',
      'senegal': 'SN',
      'mali': 'ML',
      'china': 'CN',
      'united states': 'US',
      'usa': 'US',
      'united kingdom': 'GB',
      'uk': 'UK',
      'germany': 'DE',
      'france': 'FR',
      'canada': 'CA',
      'india': 'IN',
      'pakistan': 'PK',
      'brazil': 'BR',
      'spain': 'ES',
      'italy': 'IT',
      'russia': 'RU',
      'japan': 'JP',
      'turkey': 'TR',
      'australia': 'AU',
      'singapore': 'SG',
      'uae': 'AE',
      'dubai': 'AE',
      'egypt': 'EG',
      'south africa': 'ZA',
      'nigeria': 'NG',
      'bangladesh': 'BD'
    };

    function getRouteFlag(name, id) {
      if (!name || !name.trim()) return (id || 'XX').slice(0, 2).toUpperCase();
      const lower = name.trim().toLowerCase();
      if (COUNTRY_CODES[lower]) return COUNTRY_CODES[lower];
      const words = name.trim().split(/\s+/);
      if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
      return name.trim().slice(0, 2).toUpperCase();
    }

    function switchTab(tabId) {
      const allTabs = ['dashboard', 'cuba', 'senegal', 'mali'];
      allTabs.forEach(t => {
        const btn = document.getElementById('tab-btn-' + t);
        const view = (t === 'dashboard')
          ? document.getElementById('view-dashboard')
          : document.getElementById('view-config-' + t);

        if (btn) {
          if (t === tabId) {
            btn.classList.add('active');
          } else {
            btn.classList.remove('active');
          }
        }
        if (view) {
          if (t === tabId) {
            view.classList.remove('hidden');
          } else {
            view.classList.add('hidden');
          }
        }
      });
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function handleNameChange(id, newName) {
      const displayName = newName.trim() || (id.charAt(0).toUpperCase() + id.slice(1));
      const flagCode = getRouteFlag(displayName, id);

      const fieldInput = document.getElementById('input-name-' + id);
      if (fieldInput) {
        fieldInput.dataset.modified = 'true';
        if (fieldInput.value !== newName) fieldInput.value = newName;
      }

      // Tab bar
      const tabLabel = document.getElementById('tab-label-' + id);
      if (tabLabel) tabLabel.textContent = displayName + ' Configuration';
      const tabFlag = document.getElementById('tab-flag-' + id);
      if (tabFlag) tabFlag.textContent = flagCode;

      // Config view header & card
      const cfgHdrTitle = document.getElementById('cfg-hdr-title-' + id);
      if (cfgHdrTitle) cfgHdrTitle.textContent = displayName + ' Configuration';
      const cfgHdrFlag = document.getElementById('cfg-hdr-flag-' + id);
      if (cfgHdrFlag) cfgHdrFlag.textContent = flagCode;
      const cfgCardFlag = document.getElementById('cfg-card-flag-' + id);
      if (cfgCardFlag) cfgCardFlag.textContent = flagCode;
      const cfgCardName = document.getElementById('cfg-card-name-' + id);
      if (cfgCardName) cfgCardName.textContent = displayName + ' Parameters';

      // Dashboard card
      const dashName = document.getElementById('dash-name-' + id);
      if (dashName) dashName.textContent = displayName;
      const dashFlag = document.getElementById('flag-' + id);
      if (dashFlag) dashFlag.textContent = flagCode;
      const dashCfgBtn = document.getElementById('dash-cfg-btn-' + id);
      if (dashCfgBtn) dashCfgBtn.textContent = '⚙️ Configure ' + displayName + ' Trunk';

      if (trunksData[id]) {
        trunksData[id].name = displayName;
      }
    }

    let trunksData = {};
    let isInitialized = false;

    async function api(action, payload = {}) {
      const apiEndpoint = window.location.pathname.includes('/callgen') ? '/callgen/api.php' : 'api.php';
      const res = await fetch(apiEndpoint + '?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || 'API call failed');
      return data;
    }

    function addLog(msg) {
      console.log(msg);
      const el = document.getElementById('console-logs');
      if (el) {
        const time = new Date().toLocaleTimeString();
        const div = document.createElement('div');
        div.textContent = \`[\${time}] \${msg}\`;
        el.insertBefore(div, el.firstChild);
        const logTime = document.getElementById('log-time');
        if (logTime) logTime.textContent = 'Last event: ' + time;
      }
    }

    function buildDashboardCard(id, t) {
      const routeName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
      const flagCode = getRouteFlag(routeName, id);
      const scriptName = (TRUNK_META[id] && TRUNK_META[id].script) || \`\${id}_callgen.sh\`;
      const card = document.createElement('div');
      card.className = 'trunk-card';
      card.id = 'dash-card-' + id;
      card.innerHTML = \`
        <div class="trunk-header">
          <div class="trunk-identity">
            <span class="flag-badge" id="flag-\${id}">\${flagCode}</span>
            <div>
              <div class="trunk-name" id="dash-name-\${id}">\${routeName}</div>
              <div style="font-size:11px;color:var(--muted);font-family:monospace;">\${scriptName}</div>
            </div>
          </div>
          <span id="badge-\${id}" class="trunk-status"></span>
        </div>

        <div class="trunk-body">
          <div class="metric-row">
            <div>
              <div class="metric-item-lbl">Active Calls</div>
              <div class="metric-item-val" id="active-txt-\${id}">
                \${t.active_calls} <span style="font-size:13px;font-weight:500;color:var(--muted);">/ \${t.max_channels}</span>
              </div>
            </div>
            <div>
              <div class="metric-item-lbl">Minutes Usage</div>
              <div class="metric-item-val" id="used-txt-\${id}">
                \${Number(t.used_minutes).toLocaleString()} <span style="font-size:12px;font-weight:500;color:var(--muted);">min</span>
              </div>
            </div>
          </div>

          <div class="progress-wrap">
            <div class="progress-label-row">
              <span id="limit-lbl-\${id}">Monthly Limit: \${Number(t.monthly_limit).toLocaleString()} min</span>
              <span id="pct-lbl-\${id}">0% used</span>
            </div>
            <div class="progress-bar-bg">
              <div class="progress-bar-fill" id="progress-fill-\${id}"></div>
            </div>
          </div>

          <div class="config-summary-box">
            <div class="config-summary-header">
              <span>Overall Configuration</span>
              <span style="font-size:11px;cursor:pointer;color:var(--sage);font-weight:700;" onclick="switchTab('\${id}')">Configure ⚙️</span>
            </div>
            <div class="config-summary-grid">
              <div class="summary-item">
                <span class="summary-lbl">Target IP</span>
                <span class="summary-val" id="disp-ip-\${id}">\${t.target_ip || '-'}</span>
              </div>
              <div class="summary-item">
                <span class="summary-lbl">Prefix</span>
                <span class="summary-val" id="disp-prefix-\${id}">\${t.prefix_cid || 'None'}</span>
              </div>
              <div class="summary-item">
                <span class="summary-lbl">Month Limit</span>
                <span class="summary-val" id="disp-limit-\${id}">\${Number(t.monthly_limit).toLocaleString()}m</span>
              </div>
              <div class="summary-item">
                <span class="summary-lbl">Max Calls</span>
                <span class="summary-val" id="disp-max-\${id}">\${t.max_channels}ch</span>
              </div>
              <div class="summary-item">
                <span class="summary-lbl">Duration</span>
                <span class="summary-val" id="disp-dur-\${id}">\${t.call_duration}s</span>
              </div>
              <div class="summary-item">
                <span class="summary-lbl">Context</span>
                <span class="summary-val" id="disp-ctx-\${id}">\${t.context || 'testcall'}</span>
              </div>
            </div>
          </div>

          <div class="card-actions" id="actions-\${id}"></div>
          <button class="btn btn-outline" id="dash-cfg-btn-\${id}" style="width:100%;margin-top:8px;font-size:12px;padding:8px;font-weight:600;" onclick="switchTab('\${id}')">
            ⚙️ Configure \${routeName} Trunk
          </button>
        </div>
      \`;
      return card;
    }

    function buildConfigCard(id, t) {
      const routeName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
      const flagCode = getRouteFlag(routeName, id);
      const scriptName = (TRUNK_META[id] && TRUNK_META[id].script) || \`\${id}_callgen.sh\`;
      const card = document.createElement('div');
      card.className = 'config-card-solo';
      card.id = 'config-card-' + id;
      card.innerHTML = \`
        <div class="trunk-header">
          <div class="trunk-identity">
            <span class="flag-badge" id="cfg-card-flag-\${id}">\${flagCode}</span>
            <div>
              <div style="font-weight:800;font-size:16px;color:var(--brown);" id="cfg-card-name-\${id}">\${routeName} Parameters</div>
              <div style="font-size:11px;color:var(--muted);font-family:monospace;">Engine Script: \${scriptName}</div>
            </div>
          </div>
          <span id="config-badge-\${id}" class="trunk-status"></span>
        </div>

        <div class="trunk-body">
          <form id="form-\${id}" onsubmit="saveTrunk(event, '\${id}')">
            <div class="fields-grid">
              <div class="field-group full">
                <label>Route / Destination Name</label>
                <input type="text" id="input-name-\${id}" name="name" value="\${routeName}" placeholder="e.g. China, Cuba, Senegal, Mali" oninput="handleNameChange('\${id}', this.value)" required>
              </div>
              <div class="field-group">
                <label>Target IP / Host</label>
                <input type="text" id="input-ip-\${id}" name="target_ip" value="\${t.target_ip}" required pattern="[A-Za-z0-9.:-]+">
              </div>
              <div class="field-group">
                <label>Prefix</label>
                <input type="text" id="input-prefix-\${id}" name="prefix_cid" value="\${t.prefix_cid || ''}" placeholder="e.g. 444453">
              </div>
              <div class="field-group">
                <label>Month Limit (Min)</label>
                <input type="number" id="input-limit-\${id}" name="monthly_limit" value="\${t.monthly_limit}" min="1" required>
              </div>
              <div class="field-group">
                <label>Max Channels</label>
                <input type="number" id="input-max-\${id}" name="max_channels" value="\${t.max_channels}" min="1" max="2000" required>
              </div>
              <div class="field-group">
                <label>Duration Cap (Sec)</label>
                <input type="number" id="input-dur-\${id}" name="call_duration" value="\${t.call_duration}" min="1" max="86400" required>
              </div>
              <div class="field-group">
                <label>Dialplan Context</label>
                <input type="text" id="input-ctx-\${id}" name="context" value="\${t.context || 'testcall'}" required>
              </div>
            </div>

            <div style="margin-top:20px;display:flex;gap:12px;">
              <button type="submit" id="btn-save-\${id}" class="btn btn-sage flex-1" style="padding:12px;font-size:14px;font-weight:700;">
                💾 Save Settings
              </button>
              <button type="button" class="btn btn-outline" style="padding:12px 20px;font-size:13px;" onclick="switchTab('dashboard')">
                Cancel
              </button>
            </div>
          </form>
        </div>
      \`;
      return card;
    }

    function updateMetricsOnly(trunks, forceSyncInputs = false) {
      let totalActive = 0;
      let runningCount = 0;

      for (const [id, t] of Object.entries(trunks)) {
        totalActive += (t.active_calls || 0);
        if (t.running) runningCount++;

        const isLimitReached = (t.used_minutes >= t.monthly_limit);
        const percent = t.monthly_limit > 0 ? Math.min(100, Math.round((t.used_minutes / t.monthly_limit) * 100)) : 0;
        const curName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
        const flagCode = getRouteFlag(curName, id);

        // Tab and Heading updates
        const tabLabel = document.getElementById('tab-label-' + id);
        if (tabLabel) tabLabel.textContent = curName + ' Configuration';
        const tabFlag = document.getElementById('tab-flag-' + id);
        if (tabFlag) tabFlag.textContent = flagCode;

        const cfgHdr = document.getElementById('cfg-hdr-title-' + id);
        if (cfgHdr) cfgHdr.textContent = curName + ' Configuration';
        const cfgFlag = document.getElementById('cfg-hdr-flag-' + id);
        if (cfgFlag) cfgFlag.textContent = flagCode;

        const cfgCardFlag = document.getElementById('cfg-card-flag-' + id);
        if (cfgCardFlag) cfgCardFlag.textContent = flagCode;
        const cfgCardName = document.getElementById('cfg-card-name-' + id);
        if (cfgCardName) cfgCardName.textContent = curName + ' Parameters';

        // Dashboard Card updates
        const dashName = document.getElementById('dash-name-' + id);
        if (dashName) dashName.textContent = curName;
        const flagEl = document.getElementById('flag-' + id);
        if (flagEl) flagEl.textContent = flagCode;
        const dashCfgBtn = document.getElementById('dash-cfg-btn-' + id);
        if (dashCfgBtn) dashCfgBtn.textContent = '⚙️ Configure ' + curName + ' Trunk';

        // Config Card field input update
        const nameInput = document.getElementById('input-name-' + id);
        if (nameInput && document.activeElement !== nameInput && (forceSyncInputs || !nameInput.dataset.modified)) {
          nameInput.value = curName;
        }

        // 1. Status badges
        const updateBadge = (badgeEl) => {
          if (!badgeEl) return;
          if (isLimitReached) {
            badgeEl.className = 'trunk-status status-limit';
            badgeEl.textContent = '⛔ LIMIT REACHED';
          } else if (t.running) {
            badgeEl.className = 'trunk-status status-running';
            badgeEl.textContent = '● RUNNING';
          } else {
            badgeEl.className = 'trunk-status status-stopped';
            badgeEl.textContent = '○ STOPPED';
          }
        };
        updateBadge(document.getElementById('badge-' + id));
        updateBadge(document.getElementById('config-badge-' + id));
        updateBadge(document.getElementById('cfg-hdr-status-' + id));

        // 2. Active calls count & minutes usage (strictly MINUTES USAGE)
        const activeTxt = document.getElementById('active-txt-' + id);
        if (activeTxt) {
          const maxChan = t.max_channels;
          activeTxt.innerHTML = \`\${t.active_calls} <span style="font-size:13px;font-weight:500;color:var(--muted);">/ \${maxChan}</span>\`;
          activeTxt.style.color = (t.running && !isLimitReached) ? 'var(--green)' : 'var(--muted)';
        }

        const usedTxt = document.getElementById('used-txt-' + id);
        if (usedTxt) {
          usedTxt.innerHTML = \`\${Number(t.used_minutes).toLocaleString()} <span style="font-size:12px;font-weight:500;color:var(--muted);">min</span>\`;
        }

        // 3. Progress bar
        const limitLbl = document.getElementById('limit-lbl-' + id);
        if (limitLbl) {
          limitLbl.innerHTML = isLimitReached 
            ? \`Monthly Limit: <b>\${Number(t.monthly_limit).toLocaleString()} min</b> <span style="color:#B42318;font-weight:700;">(Quota Maxed)</span>\`
            : \`Monthly Limit: \${Number(t.monthly_limit).toLocaleString()} min\`;
        }

        const pctLbl = document.getElementById('pct-lbl-' + id);
        if (pctLbl) pctLbl.textContent = \`\${percent}% used\`;

        const progressFill = document.getElementById('progress-fill-' + id);
        if (progressFill) {
          progressFill.style.width = percent + '%';
          progressFill.style.background = isLimitReached ? '#B42318' : (percent > 85 ? 'var(--amber)' : 'var(--sage)');
        }

        // 4. Overall configuration display on Dashboard card
        const dispIp = document.getElementById('disp-ip-' + id);
        if (dispIp) dispIp.textContent = t.target_ip || '-';
        const dispPrefix = document.getElementById('disp-prefix-' + id);
        if (dispPrefix) dispPrefix.textContent = t.prefix_cid || 'None';
        const dispLimit = document.getElementById('disp-limit-' + id);
        if (dispLimit) dispLimit.textContent = \`\${Number(t.monthly_limit).toLocaleString()}m\`;
        const dispMax = document.getElementById('disp-max-' + id);
        if (dispMax) dispMax.textContent = \`\${t.max_channels}ch\`;
        const dispDur = document.getElementById('disp-dur-' + id);
        if (dispDur) dispDur.textContent = \`\${t.call_duration}s\`;
        const dispCtx = document.getElementById('disp-ctx-' + id);
        if (dispCtx) dispCtx.textContent = t.context || 'testcall';

        // 5. Start/Stop Action buttons
        const actionsDiv = document.getElementById('actions-' + id);
        if (actionsDiv) {
          if (isLimitReached) {
            actionsDiv.innerHTML = \`
              <button class="btn btn-outline flex-1" style="color:#B42318;border-color:#FECDCA;background:#FEF3F2;" onclick="alert('Monthly quota reached for \${curName}. Please increase Monthly Limit (Min) on \${curName} Configuration tab to restart.')">
                ⛔ Limit Reached (Increase Limit)
              </button>
              <button class="btn btn-outline" title="Refresh" onclick="refreshAll()">🔄</button>
            \`;
          } else if (t.running) {
            actionsDiv.innerHTML = \`
              <button class="btn btn-red flex-1" onclick="toggleTrunk('\${id}', 'stop')">⏹ Stop Route</button>
              <button class="btn btn-outline" title="Refresh" onclick="refreshAll()">🔄</button>
            \`;
          } else {
            actionsDiv.innerHTML = \`
              <button class="btn btn-sage flex-1" onclick="toggleTrunk('\${id}', 'start')">▶ Start Route</button>
              <button class="btn btn-outline" title="Refresh" onclick="refreshAll()">🔄</button>
            \`;
          }
        }

        // 6. Input sync only if explicitly requested
        if (forceSyncInputs) {
          const inputIp = document.getElementById('input-ip-' + id);
          if (inputIp && document.activeElement !== inputIp) inputIp.value = t.target_ip;

          const inputPrefix = document.getElementById('input-prefix-' + id);
          if (inputPrefix && document.activeElement !== inputPrefix) inputPrefix.value = t.prefix_cid || '';

          const inputLimit = document.getElementById('input-limit-' + id);
          if (inputLimit && document.activeElement !== inputLimit) inputLimit.value = t.monthly_limit;

          const inputMax = document.getElementById('input-max-' + id);
          if (inputMax && document.activeElement !== inputMax) inputMax.value = t.max_channels;

          const inputDur = document.getElementById('input-dur-' + id);
          if (inputDur && document.activeElement !== inputDur) inputDur.value = t.call_duration;

          const inputCtx = document.getElementById('input-ctx-' + id);
          if (inputCtx && document.activeElement !== inputCtx) inputCtx.value = t.context || 'testcall';
        }
      }

      // Master bar update
      document.getElementById('total-active').textContent = totalActive;
      document.getElementById('running-count').textContent = \`\${runningCount} / \${Object.keys(trunks).length}\`;
      document.getElementById('switch-state').textContent = runningCount > 0 ? \`\${runningCount} Routes Online\` : 'All Routes Standby';

      const modeBadge = document.getElementById('system-mode-badge');
      if (modeBadge) {
        if (runningCount === Object.keys(trunks).length && runningCount > 0) {
          modeBadge.className = 'stat-val-status active';
          modeBadge.innerHTML = '<span class="badge-dot"></span> All Trunks Live';
        } else if (runningCount > 0) {
          modeBadge.className = 'stat-val-status active';
          modeBadge.innerHTML = \`<span class="badge-dot"></span> Partial (\${runningCount}/\${Object.keys(trunks).length})\`;
        } else {
          modeBadge.className = 'stat-val-status';
          modeBadge.innerHTML = '<span class="badge-dot"></span> All Standby';
        }
      }
    }

    async function refreshAll(forceSync = false) {
      try {
        const res = await api('status');
        trunksData = res.trunks;

        if (!isInitialized) {
          const dashContainer = document.getElementById('trunk-container');
          dashContainer.innerHTML = '';

          for (const [id, t] of Object.entries(trunksData)) {
            dashContainer.appendChild(buildDashboardCard(id, t));
            const cfgBox = document.getElementById('config-box-' + id);
            if (cfgBox) {
              cfgBox.innerHTML = '';
              cfgBox.appendChild(buildConfigCard(id, t));
            }
          }
          isInitialized = true;
          updateMetricsOnly(trunksData, true);
        } else {
          updateMetricsOnly(trunksData, forceSync);
        }
      } catch (e) {
        addLog('Error fetching status: ' + e.message);
      }
    }

    async function toggleTrunk(id, action) {
      try {
        const res = await api(action, { trunk: id });
        addLog(\`[\${id.toUpperCase()}] \${res.message}\`);
        refreshAll(false);
      } catch (e) {
        addLog(\`[\${id.toUpperCase()}] Error: \${e.message}\`);
        alert(e.message);
      }
    }

    async function saveTrunk(e, id) {
      e.preventDefault();
      const saveBtn = document.getElementById('btn-save-' + id);

      try {
        if (saveBtn) {
          saveBtn.innerHTML = '⏳ Saving...';
          saveBtn.disabled = true;
        }

        const form = e.target;
        const d = Object.fromEntries(new FormData(form));
        d.trunk = id;
        d.monthly_limit = Number(d.monthly_limit);
        d.max_channels = Number(d.max_channels);
        d.call_duration = Number(d.call_duration);

        const res = await api('save', d);
        addLog(\`[\${(d.name || id).toUpperCase()}] \${res.message}\`);

        const nameInput = document.getElementById('input-name-' + id);
        if (nameInput) delete nameInput.dataset.modified;

        if (saveBtn) {
          saveBtn.innerHTML = '✓ Settings Saved!';
          saveBtn.style.background = 'var(--green)';
          saveBtn.style.color = '#FFFFFF';
        }

        // Instant local sync
        if (trunksData[id]) {
          trunksData[id] = { ...trunksData[id], ...d };
          if (trunksData[id].used_minutes >= trunksData[id].monthly_limit) {
            trunksData[id].running = false;
            trunksData[id].active_calls = 0;
            trunksData[id].limit_reached = true;
          } else {
            trunksData[id].limit_reached = false;
          }
        }
        updateMetricsOnly(trunksData, false);

        setTimeout(() => {
          if (saveBtn) {
            saveBtn.innerHTML = '💾 Save Settings';
            saveBtn.style.background = '';
            saveBtn.style.color = '';
            saveBtn.disabled = false;
          }
        }, 2000);

      } catch (err) {
        addLog(\`[\${id.toUpperCase()}] Save error: \${err.message}\`);
        if (saveBtn) {
          saveBtn.innerHTML = '❌ Save Failed';
          saveBtn.style.background = 'var(--red)';
          saveBtn.style.color = '#FFFFFF';
          setTimeout(() => {
            saveBtn.innerHTML = '💾 Save Settings';
            saveBtn.style.background = '';
            saveBtn.style.color = '';
            saveBtn.disabled = false;
          }, 2500);
        }
      }
    }

    async function startAll() {
      try {
        const res = await api('start', { trunk: 'all' });
        addLog(res.message);
        refreshAll(false);
      } catch (e) {
        addLog('Error starting all trunks: ' + e.message);
        alert(e.message);
      }
    }

    async function stopAll() {
      if (!confirm('Are you sure you want to stop ALL active call routes?')) return;
      try {
        const res = await api('stop', { trunk: 'all' });
        addLog(res.message);
        refreshAll(false);
      } catch (e) {
        addLog('Error stopping all trunks: ' + e.message);
      }
    }

    refreshAll(true);
    setInterval(() => refreshAll(false), 3000);
  </script>
</body>
</html>`;

const server = http.createServer((req, res) => {
  const parsedUrl = url.parse(req.url, true);
  const pathname = parsedUrl.pathname;
  const cookies = parseCookies(req);
  const isAuthenticated = cookies.m5_auth === '1';

  // Logout handler
  if (parsedUrl.query.logout === '1') {
    res.writeHead(302, {
      'Set-Cookie': 'm5_auth=0; Max-Age=0; Path=/',
      'Location': '/'
    });
    return res.end();
  }

  // Login POST handler
  if (req.method === 'POST' && pathname === '/login') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      const params = new URLSearchParams(body);
      const u = (params.get('username') || '').trim();
      const p = (params.get('password') || '').trim();

      if (u === USERNAME && p === PASSWORD) {
        addLog(`Operator [${u}] authenticated successfully.`);
        res.writeHead(302, {
          'Set-Cookie': 'm5_auth=1; Path=/; HttpOnly',
          'Location': '/'
        });
        res.end();
      } else {
        addLog(`Failed login attempt for username "${u}".`);
        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(loginHtml('Invalid username or password. Check credentials.'));
      }
    });
    return;
  }

  // API endpoint: api.php
  if (pathname === '/api.php' || pathname === '/public/api.php') {
    const action = parsedUrl.query.action || '';
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      let data = {};
      if (body) {
        try { data = JSON.parse(body); } catch (_) {}
      }

      res.setHeader('Content-Type', 'application/json');

      if (action === 'status') {
        const trunkId = data.trunk || parsedUrl.query.trunk;
        if (trunkId && trunks[trunkId]) {
          return res.end(JSON.stringify({ ok: true, trunk: trunks[trunkId] }));
        }
        return res.end(JSON.stringify({ ok: true, trunks }));
      }

      if (action === 'save') {
        const trunkId = data.trunk;
        if (!trunkId || !trunks[trunkId]) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid or missing trunk identifier' }));
        }

        const name = (data.name || '').trim();
        const ip = (data.target_ip || '').trim();
        const prefix = (data.prefix_cid || '').trim();
        const m = parseInt(data.monthly_limit, 10);
        const n = parseInt(data.max_channels, 10);
        const t = parseInt(data.call_duration, 10);
        const ctx = (data.context || 'testcall').trim();

        if (!/^[A-Za-z0-9.:-]{1,253}$/.test(ip) || m < 1 || n < 1 || t < 1) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid settings parameters' }));
        }

        if (name) {
          trunks[trunkId].name = name;
        }
        trunks[trunkId].target_ip = ip;
        trunks[trunkId].prefix_cid = prefix;
        trunks[trunkId].monthly_limit = m;
        trunks[trunkId].max_channels = n;
        trunks[trunkId].call_duration = t;
        trunks[trunkId].context = ctx;
        trunks[trunkId].last_action = `Settings updated at ${new Date().toLocaleTimeString()}`;

        // If new limit is <= used_minutes, halt immediately!
        if (trunks[trunkId].used_minutes >= m) {
          trunks[trunkId].running = false;
          trunks[trunkId].active_calls = 0;
          trunks[trunkId].limit_reached = true;
          addLog(`⛔ [${trunks[trunkId].name}] Limit updated to ${m}m (Usage is ${trunks[trunkId].used_minutes}m). Route auto-stopped.`);
        } else {
          trunks[trunkId].limit_reached = false;
        }

        saveConfig();
        addLog(`Updated configuration for ${trunks[trunkId].name} (${ip}, Max: ${n}ch, Limit: ${m}m)`);

        return res.end(JSON.stringify({
          ok: true,
          message: `Saved settings for ${trunks[trunkId].name} successfully.`
        }));
      }

      if (action === 'start') {
        const trunkId = data.trunk;
        if (trunkId === 'all') {
          let anyBlocked = false;
          for (const k of Object.keys(trunks)) {
            if (trunks[k].used_minutes < trunks[k].monthly_limit) {
              trunks[k].running = true;
              trunks[k].limit_reached = false;
            } else {
              trunks[k].running = false;
              trunks[k].active_calls = 0;
              trunks[k].limit_reached = true;
              anyBlocked = true;
            }
          }
          saveConfig();
          addLog('Command executed: Started available trunks (Quota-exceeded trunks remained stopped).');
          return res.end(JSON.stringify({ 
            ok: true, 
            message: anyBlocked 
              ? 'Started available routes. Some routes remained stopped due to monthly limits.' 
              : 'All 3 trunks started successfully.' 
          }));
        }

        if (!trunkId || !trunks[trunkId]) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid trunk identifier' }));
        }

        // STRICT CHECK: Reject start if limit is exceeded
        if (trunks[trunkId].used_minutes >= trunks[trunkId].monthly_limit) {
          res.statusCode = 400;
          return res.end(JSON.stringify({
            ok: false,
            error: `Cannot start ${trunks[trunkId].name}: Monthly limit reached (${trunks[trunkId].used_minutes}m / ${trunks[trunkId].monthly_limit}m). Increase monthly limit first.`
          }));
        }

        trunks[trunkId].running = true;
        trunks[trunkId].limit_reached = false;
        saveConfig();
        addLog(`Route started: ${trunks[trunkId].name} [${trunks[trunkId].target_ip}]`);
        return res.end(JSON.stringify({ ok: true, message: `Started ${trunks[trunkId].name} call generator` }));
      }

      if (action === 'stop') {
        const trunkId = data.trunk;
        if (trunkId === 'all') {
          for (const k of Object.keys(trunks)) {
            trunks[k].running = false;
            trunks[k].active_calls = 0;
          }
          saveConfig();
          addLog('Command executed: Stopped all 3 call generation trunks.');
          return res.end(JSON.stringify({ ok: true, message: 'All 3 trunks stopped successfully.' }));
        }

        if (!trunkId || !trunks[trunkId]) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid trunk identifier' }));
        }

        trunks[trunkId].running = false;
        trunks[trunkId].active_calls = 0;
        saveConfig();
        addLog(`Route stopped: ${trunks[trunkId].name}`);
        return res.end(JSON.stringify({ ok: true, message: `Stopped ${trunks[trunkId].name} call generator` }));
      }

      res.statusCode = 400;
      return res.end(JSON.stringify({ ok: false, error: 'Unknown action' }));
    });
    return;
  }

  // Dashboard page
  if (pathname === '/' || pathname === '/index.php' || pathname === '/index.html' || pathname === '/public/' || pathname === '/public/index.php') {
    if (!isAuthenticated) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end(loginHtml());
    }
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    return res.end(dashboardHtml);
  }

  // 404
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not Found');
});

server.listen(PORT, () => {
  console.log(`M5-Traffic Controller running at http://localhost:${PORT}/`);
});
