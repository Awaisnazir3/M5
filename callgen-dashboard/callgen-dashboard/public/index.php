<?php
session_start();
$user_req = getenv("CALLGEN_USERNAME") ?: "awais";
$pass_req = getenv("CALLGEN_PASSWORD") ?: "dancom";

if (isset($_POST["login"])) {
    $u = trim($_POST["username"] ?? "");
    $p = trim($_POST["password"] ?? "");
    if ($u === $user_req && $p === $pass_req) {
        $_SESSION["m5_auth"] = true;
        $_SESSION["operator"] = $u;
        header("Location: index.php");
        exit;
    } else {
        $err = "Invalid username or password";
    }
}

if (isset($_GET["logout"])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (empty($_SESSION["m5_auth"])): ?>
<!doctype html>
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
      <?php if (!empty($err)): ?>
        <div class="error-msg"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="field">
          <label for="username">Username</label>
          <input id="username" type="text" name="username" value="awais" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" type="password" name="password" value="dancom" required autocomplete="current-password">
        </div>
        <button type="submit" name="login" value="1">Access Controller</button>
      </form>
    </div>
  </div>
</body>
</html>
<?php exit; endif; ?>
<!doctype html>
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
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      font-size: 14px;
      line-height: 1.5;
    }
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

    .container {
      max-width: 1320px;
      margin: 24px auto;
      padding: 0 20px 40px;
    }

    .master-bar {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 24px;
      box-shadow: 0 2px 6px rgba(0,0,0,0.02);
      display: grid;
      grid-template-columns: repeat(4, 1fr) 1.5fr;
      gap: 18px;
      align-items: center;
    }
    .stat-box {
      border-right: 1px solid var(--border);
      padding-right: 15px;
    }
    .stat-box:last-of-type {
      border-right: none;
    }
    .stat-label {
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      color: var(--muted);
      letter-spacing: 0.5px;
    }
    .stat-val {
      font-size: 26px;
      font-weight: 800;
      color: var(--brown);
      margin-top: 4px;
    }
    .stat-sub {
      font-size: 12px;
      color: var(--muted);
      margin-top: 2px;
    }
    .master-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      flex-wrap: wrap;
    }

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

    .card-actions {
      display: flex;
      gap: 8px;
      margin-top: auto;
      padding-top: 14px;
      border-top: 1px solid #EBEFEA;
    }

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

    .console-card {
      background: #1E1916;
      border-radius: 12px;
      border: 1px solid #382D28;
      padding: 16px 20px;
      color: #D6DDD3;
    }
    .console-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }
    .console-title {
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      color: var(--sage);
    }
    .console-logs {
      font-family: "Courier New", Courier, monospace;
      font-size: 12px;
      height: 120px;
      overflow-y: auto;
      line-height: 1.6;
      color: #92DD96;
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
  <header class="navbar">
    <div class="brand">
      <div class="brand-icon">M5</div>
      <div class="brand-text">
        <h1>M5-Traffic Controller</h1>
        <span>VoIP Smart Switch &bull; Multi-Trunk Engine</span>
      </div>
    </div>
    <div class="nav-actions">
      <div class="user-pill">
        <div class="user-dot"></div>
        <span>Operator: <b><?= htmlspecialchars($_SESSION["operator"] ?? "awais") ?></b></span>
      </div>
      <button class="btn btn-outline" style="color:white;border-color:rgba(255,255,255,0.3);background:transparent;" onclick="refreshAll(true)">Sync Config</button>
      <a href="?logout=1" class="btn-logout">Sign Out</a>
    </div>
  </header>

  <main class="container">
    <div class="master-bar">
      <div class="stat-box">
        <div class="stat-label">Active Channels</div>
        <div class="stat-val" id="total-active">0</div>
        <div class="stat-sub">Across 3 active routes</div>
      </div>
      <div class="stat-box">
        <div class="stat-label">Total Minutes Used</div>
        <div class="stat-val" id="total-minutes">0</div>
        <div class="stat-sub">Current month total</div>
      </div>
      <div class="stat-box">
        <div class="stat-label">Total Monthly Cap</div>
        <div class="stat-val" id="total-cap">0</div>
        <div class="stat-sub">Cumulative allocation</div>
      </div>
      <div class="stat-box">
        <div class="stat-label">Running Trunks</div>
        <div class="stat-val" id="running-count">0 / 3</div>
        <div class="stat-sub" id="switch-state">Standby</div>
      </div>
      <div class="master-actions">
        <button class="btn btn-sage" onclick="startAll()">▶ Start All Trunks</button>
        <button class="btn btn-red" onclick="stopAll()">⏹ Stop All Trunks</button>
      </div>
    </div>

    <div class="section-header">
      <div class="section-title">
        <span>Destination Traffic Routes</span>
      </div>
      <div style="font-size:12px; color:var(--muted);">
        Auto-refreshes metrics without resetting user inputs
      </div>
    </div>

    <div class="trunk-grid" id="trunk-container"></div>

    <div class="console-card">
      <div class="console-header">
        <span class="console-title">Live Switch Activity Log</span>
        <span id="log-time" style="font-size:12px;color:#A8B0A5;">Ready</span>
      </div>
      <div class="console-logs" id="console-logs">
        <div>Switch daemon connected.</div>
      </div>
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

    function handleNameChange(id, newName) {
      const displayName = newName.trim() || id;

      const titleInput = document.getElementById('title-name-' + id);
      if (titleInput) {
        titleInput.dataset.modified = 'true';
        if (titleInput.value !== newName) titleInput.value = newName;
      }

      const fieldInput = document.getElementById('input-name-' + id);
      if (fieldInput) {
        fieldInput.dataset.modified = 'true';
        if (fieldInput.value !== newName) fieldInput.value = newName;
      }

      const flagEl = document.getElementById('flag-' + id);
      if (flagEl) {
        flagEl.textContent = getRouteFlag(displayName, id);
      }

      const saveBtn = document.getElementById('btn-save-' + id);
      if (saveBtn && !saveBtn.disabled) {
        saveBtn.innerHTML = `💾 Save ${displayName} Settings`;
      }

      if (trunksData[id]) {
        trunksData[id].name = displayName;
      }
    }

    let trunksData = {};
    let isInitialized = false;

    async function api(action, payload = {}) {
      const res = await fetch('api.php?action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!res.ok || !data.ok) throw new Error(data.error || 'API call failed');
      return data;
    }

    function addLog(msg) {
      const el = document.getElementById('console-logs');
      const time = new Date().toLocaleTimeString();
      const div = document.createElement('div');
      div.textContent = `[${time}] ${msg}`;
      el.insertBefore(div, el.firstChild);
      document.getElementById('log-time').textContent = 'Last event: ' + time;
    }

    function buildCardSkeleton(id, t) {
      const routeName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
      const flagCode = getRouteFlag(routeName, id);
      const scriptName = (TRUNK_META[id] && TRUNK_META[id].script) || `${id}_callgen.sh`;
      const card = document.createElement('div');
      card.className = 'trunk-card';
      card.id = 'card-' + id;
      card.innerHTML = `
        <div class="trunk-header">
          <div class="trunk-identity">
            <span class="flag-badge" id="flag-${id}">${flagCode}</span>
            <div>
              <div class="trunk-title-wrap">
                <input type="text" class="trunk-title-input" id="title-name-${id}" value="${routeName}" title="Click to edit destination name" oninput="handleNameChange('${id}', this.value)">
                <span class="title-edit-icon" onclick="document.getElementById('title-name-${id}').focus()" title="Edit name">✏️</span>
              </div>
              <div style="font-size:11px;color:var(--muted);font-family:monospace;padding-left:6px;">${scriptName}</div>
            </div>
          </div>
          <span id="badge-${id}" class="trunk-status ${t.running ? 'status-running' : 'status-stopped'}">
            ${t.running ? '● RUNNING' : '○ STOPPED'}
          </span>
        </div>

        <div class="trunk-body">
          <div class="metric-row">
            <div>
              <div class="metric-item-lbl">Active Calls</div>
              <div class="metric-item-val" id="active-txt-${id}">
                ${t.active_calls} <span style="font-size:13px;font-weight:500;color:var(--muted);">/ ${t.max_channels}</span>
              </div>
            </div>
            <div>
              <div class="metric-item-lbl">Month Usage</div>
              <div class="metric-item-val" id="used-txt-${id}">
                ${Number(t.used_minutes).toLocaleString()} <span style="font-size:12px;font-weight:500;color:var(--muted);">min</span>
              </div>
            </div>
          </div>

          <div class="progress-wrap">
            <div class="progress-label-row">
              <span id="limit-lbl-${id}">Monthly Limit: ${Number(t.monthly_limit).toLocaleString()} min</span>
              <span id="pct-lbl-${id}">0% used</span>
            </div>
            <div class="progress-bar-bg">
              <div class="progress-bar-fill" id="progress-fill-${id}"></div>
            </div>
          </div>

          <form id="form-${id}" onsubmit="saveTrunk(event, '${id}')">
            <div class="fields-grid">
              <div class="field-group full">
                <label>Route / Destination Name</label>
                <input type="text" id="input-name-${id}" name="name" value="${routeName}" placeholder="e.g. China, Cuba, Senegal, UK" oninput="handleNameChange('${id}', this.value)" required>
              </div>
              <div class="field-group">
                <label>Target IP / Host</label>
                <input type="text" id="input-ip-${id}" name="target_ip" value="${t.target_ip}" required pattern="[A-Za-z0-9.:-]+">
              </div>
              <div class="field-group">
                <label>Prefix</label>
                <input type="text" id="input-prefix-${id}" name="prefix_cid" value="${t.prefix_cid || ''}" placeholder="e.g. 444453">
              </div>
              <div class="field-group">
                <label>Month Limit (Min)</label>
                <input type="number" id="input-limit-${id}" name="monthly_limit" value="${t.monthly_limit}" min="1" required>
              </div>
              <div class="field-group">
                <label>Max Channels</label>
                <input type="number" id="input-max-${id}" name="max_channels" value="${t.max_channels}" min="1" max="2000" required>
              </div>
              <div class="field-group">
                <label>Duration Cap (Sec)</label>
                <input type="number" id="input-dur-${id}" name="call_duration" value="${t.call_duration}" min="1" max="86400" required>
              </div>
              <div class="field-group">
                <label>Dialplan Context</label>
                <input type="text" id="input-ctx-${id}" name="context" value="${t.context || 'testcall'}" required>
              </div>
            </div>

            <div style="margin-top:12px;">
              <button type="submit" id="btn-save-${id}" class="btn btn-outline" style="width:100%;padding:7px;font-size:12px;">
                💾 Save ${routeName} Settings
              </button>
            </div>
          </form>

          <div class="card-actions" id="actions-${id}"></div>
        </div>
      `;
      return card;
    }

    function updateMetricsOnly(trunks, forceSyncInputs = false) {
      let totalActive = 0;
      let totalMinutes = 0;
      let totalCap = 0;
      let runningCount = 0;

      for (const [id, t] of Object.entries(trunks)) {
        totalActive += (t.active_calls || 0);
        totalMinutes += (t.used_minutes || 0);
        totalCap += (t.monthly_limit || 0);
        if (t.running) runningCount++;

        const isLimitReached = (t.used_minutes >= t.monthly_limit);
        const percent = t.monthly_limit > 0 ? Math.min(100, Math.round((t.used_minutes / t.monthly_limit) * 100)) : 0;
        const curName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));

        const titleInput = document.getElementById('title-name-' + id);
        const nameInput = document.getElementById('input-name-' + id);

        if (titleInput && document.activeElement !== titleInput && (forceSyncInputs || !titleInput.dataset.modified)) {
          titleInput.value = curName;
        }
        if (nameInput && document.activeElement !== nameInput && (forceSyncInputs || !nameInput.dataset.modified)) {
          nameInput.value = curName;
        }
        const flagEl = document.getElementById('flag-' + id);
        if (flagEl && (forceSyncInputs || (!titleInput?.dataset.modified && !nameInput?.dataset.modified))) {
          flagEl.textContent = getRouteFlag(curName, id);
        }
        const saveBtn = document.getElementById('btn-save-' + id);
        if (saveBtn && !saveBtn.disabled && document.activeElement !== titleInput && document.activeElement !== nameInput && !titleInput?.dataset.modified) {
          saveBtn.innerHTML = `💾 Save ${curName} Settings`;
        }

        const badge = document.getElementById('badge-' + id);
        if (badge) {
          if (isLimitReached) {
            badge.className = 'trunk-status status-limit';
            badge.textContent = '⛔ LIMIT REACHED';
          } else if (t.running) {
            badge.className = 'trunk-status status-running';
            badge.textContent = '● RUNNING';
          } else {
            badge.className = 'trunk-status status-stopped';
            badge.textContent = '○ STOPPED';
          }
        }

        const activeTxt = document.getElementById('active-txt-' + id);
        if (activeTxt) {
          const maxChan = document.getElementById('input-max-' + id)?.value || t.max_channels;
          activeTxt.innerHTML = `${t.active_calls} <span style="font-size:13px;font-weight:500;color:var(--muted);">/ ${maxChan}</span>`;
          activeTxt.style.color = (t.running && !isLimitReached) ? 'var(--green)' : 'var(--muted)';
        }

        const usedTxt = document.getElementById('used-txt-' + id);
        if (usedTxt) {
          usedTxt.innerHTML = `${Number(t.used_minutes).toLocaleString()} <span style="font-size:12px;font-weight:500;color:var(--muted);">min</span>`;
        }

        const limitLbl = document.getElementById('limit-lbl-' + id);
        if (limitLbl) {
          limitLbl.innerHTML = isLimitReached 
            ? `Monthly Limit: <b>${Number(t.monthly_limit).toLocaleString()} min</b> <span style="color:#B42318;font-weight:700;">(Quota Maxed)</span>`
            : `Monthly Limit: ${Number(t.monthly_limit).toLocaleString()} min`;
        }

        const pctLbl = document.getElementById('pct-lbl-' + id);
        if (pctLbl) pctLbl.textContent = `${percent}% used`;

        const progressFill = document.getElementById('progress-fill-' + id);
        if (progressFill) {
          progressFill.style.width = percent + '%';
          progressFill.style.background = isLimitReached ? '#B42318' : (percent > 85 ? 'var(--amber)' : 'var(--sage)');
        }

        const actionsDiv = document.getElementById('actions-' + id);
        if (actionsDiv) {
          if (isLimitReached) {
            actionsDiv.innerHTML = `
              <button class="btn btn-outline flex-1" style="color:#B42318;border-color:#FECDCA;background:#FEF3F2;" onclick="alert('Monthly quota reached for ${curName}. Please increase Monthly Limit (Min) and click Save to restart.')">
                ⛔ Limit Reached (Increase Limit)
              </button>
              <button class="btn btn-outline" title="Refresh" onclick="refreshAll()">🔄</button>
            `;
          } else if (t.running) {
            actionsDiv.innerHTML = `
              <button class="btn btn-red flex-1" onclick="toggleTrunk('${id}', 'stop')">⏹ Stop Route</button>
              <button class="btn btn-outline" title="Refresh" onclick="refreshAll()">🔄</button>
            `;
          } else {
            actionsDiv.innerHTML = `
              <button class="btn btn-sage flex-1" onclick="toggleTrunk('${id}', 'start')">▶ Start Route</button>
              <button class="btn btn-outline" title="Refresh" onclick="refreshAll()">🔄</button>
            `;
          }
        }

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

      document.getElementById('total-active').textContent = totalActive;
      document.getElementById('total-minutes').textContent = Number(totalMinutes.toFixed(1)).toLocaleString();
      document.getElementById('total-cap').textContent = Number(totalCap).toLocaleString();
      document.getElementById('running-count').textContent = `${runningCount} / ${Object.keys(trunks).length}`;
      document.getElementById('switch-state').textContent = runningCount > 0 ? `Switch Active (${runningCount} Online)` : 'All Routes Standby';
    }

    async function refreshAll(forceSync = false) {
      try {
        const res = await api('status');
        trunksData = res.trunks;

        if (!isInitialized) {
          const container = document.getElementById('trunk-container');
          container.innerHTML = '';
          for (const [id, t] of Object.entries(trunksData)) {
            container.appendChild(buildCardSkeleton(id, t));
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
        addLog(`[${id.toUpperCase()}] ${res.message}`);
        refreshAll(false);
      } catch (e) {
        addLog(`[${id.toUpperCase()}] Error: ${e.message}`);
      }
    }

    async function saveTrunk(e, id) {
      e.preventDefault();
      const saveBtn = document.getElementById('btn-save-' + id);
      const originalText = saveBtn ? saveBtn.innerHTML : '';

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
        addLog(`[${(d.name || id).toUpperCase()}] ${res.message}`);

        const titleInput = document.getElementById('title-name-' + id);
        if (titleInput) delete titleInput.dataset.modified;
        const nameInput = document.getElementById('input-name-' + id);
        if (nameInput) delete nameInput.dataset.modified;

        if (saveBtn) {
          saveBtn.innerHTML = '✓ Settings Saved!';
          saveBtn.style.background = 'var(--green)';
          saveBtn.style.color = '#FFFFFF';
          saveBtn.style.borderColor = 'var(--green)';
        }

        if (trunksData[id]) {
          trunksData[id] = { ...trunksData[id], ...d };
        }
        updateMetricsOnly(trunksData, false);

        setTimeout(() => {
          if (saveBtn) {
            saveBtn.innerHTML = originalText || `💾 Save ${trunksData[id]?.name || id} Settings`;
            saveBtn.style.background = '';
            saveBtn.style.color = '';
            saveBtn.style.borderColor = '';
            saveBtn.disabled = false;
          }
        }, 2000);

      } catch (err) {
        addLog(`[${id.toUpperCase()}] Save error: ${err.message}`);
        if (saveBtn) {
          saveBtn.innerHTML = '❌ Save Failed';
          saveBtn.style.background = 'var(--red)';
          saveBtn.style.color = '#FFFFFF';
          setTimeout(() => {
            saveBtn.innerHTML = originalText;
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
</html>
