const http = require('http');
const fs = require('fs');
const path = require('path');
const url = require('url');

const PORT = process.env.PORT || 3000;
let currentUsername = process.env.CALLGEN_USERNAME || 'awais';
let currentPassword = process.env.CALLGEN_PASSWORD || 'dancom';
const CONFIG_FILE = path.join(__dirname, 'callgen-config.json');

// Default initial multi-trunk configuration matching screenshot
const DEFAULT_CONFIG = {
  cuba: {
    id: "cuba",
    name: "Cuba",
    country_code: "53",
    flag_code: "CU",
    prefix: "444453",
    prefix_cid: "444453",
    target_ip: "192.168.88.76:5080",
    sip_port: 5080,
    monthly_limit: 5000,
    max_channels: 600,
    call_duration: 600,
    context: "testcall",
    ani_prefix: "96655",
    running: false,
    used_minutes: 0.0,
    active_calls: 0,
    limit_reached: false,
    last_action: "System configured"
  },
  mali: {
    id: "mali",
    name: "Mali",
    country_code: "2237",
    flag_code: "ML",
    prefix: "2237",
    prefix_cid: "2237",
    target_ip: "192.168.88.76:5080",
    sip_port: 5080,
    monthly_limit: 1000000,
    max_channels: 2000,
    call_duration: 600,
    context: "testcall2",
    ani_prefix: "442",
    running: false,
    used_minutes: 691999.2,
    active_calls: 0,
    limit_reached: false,
    last_action: "System configured"
  },
  senegal: {
    id: "senegal",
    name: "Senegal",
    country_code: "221",
    flag_code: "SN",
    prefix: "619334",
    prefix_cid: "619334221",
    target_ip: "178.22.15.201:5060",
    sip_port: 5060,
    monthly_limit: 150000,
    max_channels: 600,
    call_duration: 600,
    context: "testcall",
    ani_prefix: "+442",
    running: false,
    used_minutes: 0.0,
    active_calls: 0,
    limit_reached: false,
    last_action: "System configured"
  }
};

const DEFAULT_INBOUND = {
  source_ips: [
    { id: "src-1", ip: "192.168.1.13", port: 5060, label: "Default Gateway", prefix: "444453, 619334221", country_code: "", context: "auto" }
  ],
  testcall_prefixes: "444453, 619334221",
  testcall2_prefixes: "2237",
  testcall_duration: 60,
  testcall2_duration: 50,
  active_channels: 0,
  active_calls: [],
  country_records: {
    cuba: { name: "Cuba", total_calls: 0, answered_calls: 0, billed_seconds: 0, billed_minutes: 0 },
    senegal: { name: "Senegal", total_calls: 0, answered_calls: 0, billed_seconds: 0, billed_minutes: 0 },
    mali: { name: "Mali", total_calls: 0, answered_calls: 0, billed_seconds: 0, billed_minutes: 0 }
  }
};

let trunks = JSON.parse(JSON.stringify(DEFAULT_CONFIG));
let inbound = JSON.parse(JSON.stringify(DEFAULT_INBOUND));

// Load saved config if exists
if (fs.existsSync(CONFIG_FILE)) {
  try {
    let raw = fs.readFileSync(CONFIG_FILE, 'utf8');
    if (raw.charCodeAt(0) === 0xFEFF) {
      raw = raw.slice(1);
    }
    const saved = JSON.parse(raw);
    for (const key of Object.keys(DEFAULT_CONFIG)) {
      if (saved[key]) {
        trunks[key] = { ...DEFAULT_CONFIG[key], ...saved[key] };
        // Ensure sip_port and target_ip formatting with port
        let host = trunks[key].target_ip || '';
        let port = parseInt(trunks[key].sip_port, 10);
        if (host.includes(':')) {
          const parts = host.split(':');
          host = parts[0];
          if (!port || isNaN(port)) port = parseInt(parts[1], 10);
        }
        if (!port || isNaN(port)) port = 5060;
        trunks[key].target_ip = host ? `${host}:${port}` : '';
        trunks[key].sip_port = port;

        // Enforce limit check on load
        if (trunks[key].used_minutes >= trunks[key].monthly_limit) {
          trunks[key].running = false;
          trunks[key].active_calls = 0;
          trunks[key].limit_reached = true;
        }
      }
    }
    if (saved._inbound) {
      inbound = { ...DEFAULT_INBOUND, ...saved._inbound };
      if (Array.isArray(inbound.source_ips)) {
        inbound.source_ips = inbound.source_ips.map(s => {
          let sHost = (s.ip || '').trim();
          let sPort = parseInt(s.port, 10);
          if (sHost.includes(':')) {
            const parts = sHost.split(':');
            sHost = parts[0];
            if (!sPort || isNaN(sPort)) sPort = parseInt(parts[1], 10);
          }
          if (!sPort || isNaN(sPort)) sPort = 5060;
          return { ...s, ip: sHost, port: sPort };
        });
      }
    }
    if (saved._auth) {
      if (saved._auth.username) currentUsername = saved._auth.username;
      if (saved._auth.password) currentPassword = saved._auth.password;
    }
  } catch (e) {
    console.error("Could not load config file, using defaults:", e.message);
  }
}

function saveConfig() {
  try {
    const dataToSave = { ...trunks, _inbound: inbound, _auth: { username: currentUsername, password: currentPassword } };
    fs.writeFileSync(CONFIG_FILE, JSON.stringify(dataToSave, null, 2), 'utf8');
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
      // In standby mode, when traffic is not running, ensure active calls is 0
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
  <title>M5 Traffic Controller | Sign In</title>
  <style>
    :root {
      --bg: #F4F6F9;
      --card-bg: #FFFFFF;
      --border: #E2E8F0;
      --cyan: #0284C7;
      --text: #0F172A;
      --text-muted: #64748B;
      --red: #DC2626;
      --red-bg: #FEF2F2;
      --red-border: #FECACA;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Inter", "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      color: var(--text);
      -webkit-font-smoothing: antialiased;
    }
    .login-container {
      background: var(--card-bg);
      border-radius: 14px;
      box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
      border: 1px solid var(--border);
      width: 400px;
      overflow: hidden;
    }
    .header {
      background: #0F172A;
      color: #FFFFFF;
      padding: 28px 24px 22px;
      text-align: center;
      border-bottom: 3px solid #0284C7;
    }
    .header .badge {
      display: inline-block;
      background: rgba(2, 132, 199, 0.2);
      color: #38BDF8;
      border: 1px solid rgba(56, 189, 248, 0.35);
      font-size: 10.5px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 3px 10px;
      border-radius: 4px;
      margin-bottom: 10px;
    }
    .header h1 {
      font-size: 20px;
      font-weight: 850;
      letter-spacing: -0.3px;
    }
    .header p {
      font-size: 12.5px;
      color: #94A3B8;
      margin-top: 4px;
    }
    .form-wrap {
      padding: 28px 24px;
    }
    .field {
      margin-bottom: 18px;
    }
    .field label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .field input {
      width: 100%;
      padding: 11px 14px;
      background: #FFFFFF;
      border: 1px solid #CBD5E1;
      border-radius: 8px;
      font-size: 14px;
      color: #0F172A;
      transition: all 0.15s ease;
      outline: none;
    }
    .field input:focus {
      border-color: #0284C7;
      box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.2);
    }
    button {
      width: 100%;
      background: linear-gradient(135deg, #0284C7 0%, #0369A1 100%);
      color: #FFFFFF;
      border: 0;
      padding: 12px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 750;
      cursor: pointer;
      transition: all 0.15s ease;
      box-shadow: 0 1px 3px rgba(16, 24, 40, 0.1);
    }
    button:hover {
      background: #0369A1;
      transform: translateY(-1px);
    }
    .error-msg {
      background: var(--red-bg);
      border: 1px solid var(--red-border);
      color: var(--red);
      font-size: 12.5px;
      padding: 10px;
      border-radius: 6px;
      margin-bottom: 16px;
      text-align: center;
      font-weight: 600;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="header">
      <span class="badge">Carrier VoIP Portal</span>
      <h1>M5 Traffic Controller</h1>
      <p>Trunk Routing & Call Flow Administration</p>
    </div>
    <div class="form-wrap">
      ${err ? `<div class="error-msg">${err}</div>` : ''}
      <form method="post" action="/login">
        <div class="field">
          <label for="username">Operator ID</label>
          <input id="username" type="text" name="username" value="" placeholder="Enter operator ID" required autofocus autocomplete="username">
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" type="password" name="password" value="" placeholder="Enter password" required autocomplete="current-password">
        </div>
        <button type="submit">Sign In to Controller</button>
      </form>
    </div>
  </div>
</body>
</html>`;


function getDashboardHtml() {
  const htmlPath = path.join(__dirname, 'index.html');
  if (fs.existsSync(htmlPath)) {
    return fs.readFileSync(htmlPath, 'utf8');
  }
  return '<!doctype html><html><body><h1>Error: index.html not found</h1></body></html>';
}

const server = http.createServer((req, res) => {
  const parsedUrl = url.parse(req.url, true);
  const pathname = parsedUrl.pathname;
  const cookies = parseCookies(req);
  const isAuthenticated = cookies.m5_auth === '1';

  // Global CORS & Preflight handling
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS, PUT, DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');

  if (req.method === 'OPTIONS') {
    res.writeHead(204);
    return res.end();
  }

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

      if (u === currentUsername && p === currentPassword) {
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

  // Universal API Endpoint: /api.php, /public/api.php, /api, /api/*
  const isApi = pathname === '/api.php' ||
                pathname === '/public/api.php' ||
                pathname === '/api' ||
                pathname.startsWith('/api/');

  if (isApi) {
    let action = (parsedUrl.query.action || '').trim();
    if (!action && pathname.startsWith('/api/')) {
      action = pathname.replace(/^\/api\/?/, '').trim();
    }

    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', () => {
      let data = {};
      if (body) {
        try { data = JSON.parse(body); } catch (_) {}
      }

      if (!action && data.action) {
        action = data.action;
      }

      console.log(`[${new Date().toLocaleTimeString()}] ${req.method} ${pathname} action=${action || '(status)'}`);
      res.setHeader('Content-Type', 'application/json');

      if (!action || action === 'status') {
        const trunkId = data.trunk || parsedUrl.query.trunk;
        if (trunkId && trunkId !== 'all' && trunks[trunkId]) {
          return res.end(JSON.stringify({ ok: true, trunk: trunks[trunkId] }));
        }
        return res.end(JSON.stringify({ ok: true, trunks, inbound }));
      }

      if (action === 'save') {
        const trunkId = data.trunk;
        if (!trunkId || !trunks[trunkId]) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid or missing trunk identifier' }));
        }

        const name = (data.name || '').trim();
        let ip = (data.target_ip || '').trim();
        let port = parseInt(data.sip_port, 10);
        if (ip.includes(':')) {
          const parts = ip.split(':');
          ip = parts[0];
          if (!port || isNaN(port)) port = parseInt(parts[1], 10);
        }
        if (!port || isNaN(port) || port < 1 || port > 65535) port = 5060;
        const fullTarget = ip ? `${ip}:${port}` : '';

        const pfx = (data.prefix || '').trim();
        const cc = (data.country_code || '').trim();
        let prefix = (data.prefix_cid || '').trim();
        if (!prefix && (pfx || cc)) {
          prefix = pfx + cc;
        }
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
        trunks[trunkId].target_ip = fullTarget;
        trunks[trunkId].sip_port = port;
        trunks[trunkId].prefix = pfx;
        trunks[trunkId].country_code = cc;
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
        addLog(`Updated configuration for ${trunks[trunkId].name} (${fullTarget}, Max: ${n}ch, Limit: ${m}m)`);

        return res.end(JSON.stringify({
          ok: true,
          message: `Saved settings for ${trunks[trunkId].name} successfully.`
        }));
      }

      if (action === 'save-inbound') {
        if (Array.isArray(data.source_ips)) {
          inbound.source_ips = data.source_ips.map((s, idx) => {
            let sHost = (s.ip || '').trim();
            let sPort = parseInt(s.port, 10);
            if (sHost.includes(':')) {
              const parts = sHost.split(':');
              sHost = parts[0];
              if (!sPort || isNaN(sPort)) sPort = parseInt(parts[1], 10);
            }
            if (!sPort || isNaN(sPort) || sPort < 1 || sPort > 65535) sPort = 5060;
            return {
              id: s.id || ('src-' + (idx + 1)),
              ip: sHost,
              port: sPort,
              label: s.label || '',
              prefix: s.prefix || '',
              country_code: s.country_code || '',
              context: s.context || 'auto'
            };
          });
        }
        if (data.testcall_prefixes !== undefined) inbound.testcall_prefixes = String(data.testcall_prefixes);
        if (data.testcall2_prefixes !== undefined) inbound.testcall2_prefixes = String(data.testcall2_prefixes);
        if (data.testcall_duration !== undefined) inbound.testcall_duration = parseInt(data.testcall_duration, 10) || 60;
        if (data.testcall2_duration !== undefined) inbound.testcall2_duration = parseInt(data.testcall2_duration, 10) || 50;
        addLog('Updated Inbound Gateway & Breakout Prefix configuration.');
        saveConfig();
        return res.end(JSON.stringify({ ok: true, message: 'Saved inbound gateway settings successfully.' }));
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

      if (action === 'hangup') {
        const trunkId = data.trunk;
        if (trunkId === 'all') {
          for (const k of Object.keys(trunks)) {
            trunks[k].active_calls = 0;
          }
          saveConfig();
          addLog('Command executed: Hangup all active calls across all routes.');
          return res.end(JSON.stringify({ ok: true, message: 'All active calls terminated successfully.' }));
        }

        if (!trunkId || !trunks[trunkId]) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid trunk identifier' }));
        }

        trunks[trunkId].active_calls = 0;
        saveConfig();
        addLog(`Calls terminated on route: ${trunks[trunkId].name}`);
        return res.end(JSON.stringify({ ok: true, message: `Terminated active calls on ${trunks[trunkId].name}` }));
      }

      if (action === 'reset-quota' || action === 'reset') {
        const trunkId = data.trunk;
        if (trunkId === 'all') {
          for (const k of Object.keys(trunks)) {
            trunks[k].used_minutes = 0.0;
            trunks[k].limit_reached = false;
          }
          saveConfig();
          addLog('Command executed: Reset consumed minutes to 0 for ALL routes.');
          return res.end(JSON.stringify({ ok: true, message: 'All trunk quotas reset to 0.0 min.' }));
        }

        if (!trunkId || !trunks[trunkId]) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Invalid trunk identifier' }));
        }

        trunks[trunkId].used_minutes = 0.0;
        trunks[trunkId].limit_reached = false;
        saveConfig();
        addLog(`Reset consumed quota for ${trunks[trunkId].name} to 0.0 min.`);
        return res.end(JSON.stringify({ ok: true, message: `Reset consumed quota for ${trunks[trunkId].name} to 0.0 min.` }));
      }

      if (action === 'change-password') {
        const curPass = (data.current_password || '').trim();
        const newPass = (data.new_password || '').trim();
        if (curPass !== currentPassword) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Current password is incorrect' }));
        }
        if (!newPass || newPass.length < 4) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'New password must be at least 4 characters' }));
        }
        currentPassword = newPass;
        saveConfig();
        addLog('Operator password updated successfully.');
        return res.end(JSON.stringify({ ok: true, message: 'Password updated successfully.' }));
      }

      res.statusCode = 400;
      return res.end(JSON.stringify({ ok: false, error: `Unknown action: ${action}` }));
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
    return res.end(getDashboardHtml());
  }

  // 404
  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not Found');
});

server.listen(PORT, () => {
  console.log(`M5-Traffic Controller running at http://localhost:${PORT}/`);
});