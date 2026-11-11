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

const DEFAULT_CUSTOMERS = [
  {
    id: "ptcl",
    name: "PTCL",
    status: "active",
    max_channels: 100,
    country_code: "92",
    description: "Pakistan Telecommunication Company Limited",
    ips: [{ ip: "192.168.1.10", port: 5060 }],
    prefixes: ["92300"]
  },
  {
    id: "ufone",
    name: "Ufone",
    status: "active",
    max_channels: 50,
    country_code: "92",
    description: "Pak Telecom Mobile Limited",
    ips: [{ ip: "192.168.1.20", port: 5060 }],
    prefixes: ["92301"]
  }
];

const DEFAULT_VENDORS = [
  {
    id: "boc",
    name: "BOC",
    status: "active",
    max_channels: 200,
    country_code: "1",
    caller_id: "+1",
    description: "Tier-1 Carrier BOC (USA)",
    ips: [{ ip: "10.10.10.10", port: 5060 }],
    prefixes: ["1"]
  },
  {
    id: "vendor_2",
    name: "Vendor 2",
    status: "active",
    max_channels: 100,
    country_code: "44",
    caller_id: "+44",
    description: "UK Wholesale Carrier",
    ips: [{ ip: "10.10.10.20", port: 5060 }],
    prefixes: ["44"]
  },
  {
    id: "vendor_3",
    name: "Vendor 3",
    status: "active",
    max_channels: 150,
    country_code: "33",
    caller_id: "+33",
    description: "EU International Carrier",
    ips: [{ ip: "10.10.10.30", port: 5060 }],
    prefixes: ["33"]
  }
];

const DEFAULT_MAPPINGS = [
  {
    id: 1,
    customer_id: "ptcl",
    vendor_id: "boc",
    customer_prefix: "92300",
    vendor_prefix: "1",
    country_code: "92",
    priority: 1,
    max_channels: 60,
    status: "active"
  },
  {
    id: 2,
    customer_id: "ptcl",
    vendor_id: "vendor_2",
    customer_prefix: "92300",
    vendor_prefix: "44",
    country_code: "92",
    priority: 2,
    max_channels: 40,
    status: "active"
  },
  {
    id: 3,
    customer_id: "ptcl",
    vendor_id: "vendor_3",
    customer_prefix: "92300",
    vendor_prefix: "33",
    country_code: "92",
    priority: 3,
    max_channels: 30,
    status: "active"
  },
  {
    id: 4,
    customer_id: "ufone",
    vendor_id: "boc",
    customer_prefix: "92301",
    vendor_prefix: "1",
    country_code: "92",
    priority: 1,
    max_channels: 50,
    status: "active"
  }
];

let trunks = JSON.parse(JSON.stringify(DEFAULT_CONFIG));
let inbound = JSON.parse(JSON.stringify(DEFAULT_INBOUND));
let customers = JSON.parse(JSON.stringify(DEFAULT_CUSTOMERS));
let vendors = JSON.parse(JSON.stringify(DEFAULT_VENDORS));
let mappings = JSON.parse(JSON.stringify(DEFAULT_MAPPINGS));

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
    if (saved._customers && Array.isArray(saved._customers)) {
      customers = saved._customers;
    }
    if (saved._vendors && Array.isArray(saved._vendors)) {
      vendors = saved._vendors;
    }
    if (saved._mappings && Array.isArray(saved._mappings)) {
      mappings = saved._mappings;
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
    const dataToSave = { 
      ...trunks, 
      _inbound: inbound, 
      _auth: { username: currentUsername, password: currentPassword },
      _customers: customers,
      _vendors: vendors,
      _mappings: mappings
    };
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

      // ==============================================================
      // CUSTOMER & VENDOR TRAFFIC MANAGEMENT ENDPOINTS
      // ==============================================================
      // CUSTOMER & VENDOR LIVE TRAFFIC HELPER
      // Dynamic calculation: connected calls are distributed across active
      // customers and vendors; when no calls are active (standby/idle), 
      // channel count is strictly 0.
      // ==============================================================
      function getLiveCustomerVendorStats() {
        let totalConnectedCalls = 0;
        for (const id of Object.keys(trunks)) {
          if (trunks[id].running) {
            totalConnectedCalls += (Number(trunks[id].active_calls) || 0);
          }
        }

        const custStats = customers.map(c => {
          const alloc = Number(c.max_channels) || 100;
          return {
            id: c.id,
            name: c.name,
            status: c.status,
            allocated: alloc,
            active: 0,
            available: alloc
          };
        });

        const vendStats = vendors.map(v => {
          const alloc = Number(v.max_channels) || 200;
          return {
            id: v.id,
            name: v.name,
            status: v.status,
            allocated: alloc,
            active: 0,
            available: alloc
          };
        });

        if (totalConnectedCalls > 0) {
          const activeEligibleCusts = custStats.filter(c => c.status === 'active');
          const totalCustAlloc = activeEligibleCusts.reduce((acc, c) => acc + c.allocated, 0) || 1;
          let remCust = totalConnectedCalls;
          for (let i = 0; i < activeEligibleCusts.length; i++) {
            const c = activeEligibleCusts[i];
            if (i === activeEligibleCusts.length - 1) {
              c.active = Math.min(c.allocated, remCust);
            } else {
              const share = Math.round((c.allocated / totalCustAlloc) * totalConnectedCalls);
              c.active = Math.min(c.allocated, Math.min(remCust, share));
            }
            c.available = Math.max(0, c.allocated - c.active);
            remCust -= c.active;
          }

          const activeEligibleVends = vendStats.filter(v => v.status === 'active');
          const totalVendAlloc = activeEligibleVends.reduce((acc, v) => acc + v.allocated, 0) || 1;
          let remVend = totalConnectedCalls;
          for (let i = 0; i < activeEligibleVends.length; i++) {
            const v = activeEligibleVends[i];
            if (i === activeEligibleVends.length - 1) {
              v.active = Math.min(v.allocated, remVend);
            } else {
              const share = Math.round((v.allocated / totalVendAlloc) * totalConnectedCalls);
              v.active = Math.min(v.allocated, Math.min(remVend, share));
            }
            v.available = Math.max(0, v.allocated - v.active);
            remVend -= v.active;
          }
        }

        const custAllocTot = custStats.reduce((acc, c) => acc + c.allocated, 0);
        const custActTot = custStats.reduce((acc, c) => acc + c.active, 0);
        const vendAllocTot = vendStats.reduce((acc, v) => acc + v.allocated, 0);
        const vendActTot = vendStats.reduce((acc, v) => acc + v.active, 0);

        const activeCustCount = customers.filter(c => c.status === 'active').length;
        const blockedCustCount = customers.filter(c => c.status === 'blocked').length;
        const activeVendCount = vendors.filter(v => v.status === 'active').length;
        const blockedVendCount = vendors.filter(v => v.status === 'blocked').length;

        return {
          total_connected_calls: totalConnectedCalls,
          cust_stats: custStats,
          vend_stats: vendStats,
          cust_act_map: Object.fromEntries(custStats.map(c => [c.id, c.active])),
          vend_act_map: Object.fromEntries(vendStats.map(v => [v.id, v.active])),
          summary: {
            total_customers: customers.length,
            active_customers: activeCustCount,
            blocked_customers: blockedCustCount,
            customer_allocated_channels: custAllocTot,
            customer_active_channels: custActTot,
            total_vendors: vendors.length,
            active_vendors: activeVendCount,
            blocked_vendors: blockedVendCount,
            vendor_allocated_channels: vendAllocTot,
            vendor_active_channels: vendActTot,
            total_active_calls: totalConnectedCalls,
            asr: totalConnectedCalls > 0 ? 87.2 : 0,
            acd: totalConnectedCalls > 0 ? 2.8 : 0,
            successful_calls: 15420,
            failed_calls: 1840
          }
        };
      }

      // 1. CUSTOMERS
      if (action === 'customers' || action === 'customers-list') {
        const cvStats = getLiveCustomerVendorStats();
        const resList = customers.map(c => {
          const ips = c.ips || [{ ip: c.ip || '192.168.1.10', port: c.port || 5060 }];
          const prefixes = c.prefixes || (c.prefix ? [c.prefix] : ['92300']);
          const act = cvStats.cust_act_map[c.id] || 0;
          return {
            ...c,
            ips,
            prefixes,
            primary_ip: ips[0] ? `${ips[0].ip}:${ips[0].port || 5060}` : '',
            prefix_str: prefixes.join(', '),
            active_calls: act,
            active: act
          };
        });
        return res.end(JSON.stringify({ ok: true, customers: resList }));
      }

      if (action === 'customer-save') {
        const name = (data.name || '').trim();
        if (!name) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Customer name is required' }));
        }

        let id = (data.id || '').trim();
        let isNew = false;
        if (!id) {
          isNew = true;
          id = name.toLowerCase().replace(/[^a-z0-9_-]/g, '_');
          if (!id || customers.some(c => c.id === id)) id = 'cust_' + Date.now();
        }

        const max_ch = Math.max(1, parseInt(data.max_channels, 10) || 100);
        const status = (data.status === 'blocked') ? 'blocked' : 'active';
        const cc = (data.country_code || '').trim();
        const desc = (data.description || '').trim();

        // Process IPs
        let ips = [];
        if (Array.isArray(data.ips)) {
          ips = data.ips.map(item => {
            if (typeof item === 'object') return { ip: (item.ip || '').trim(), port: parseInt(item.port, 10) || 5060 };
            const p = String(item).split(':');
            return { ip: p[0].trim(), port: parseInt(p[1], 10) || 5060 };
          }).filter(x => x.ip);
        } else if (data.ips || data.ip) {
          const str = String(data.ips || data.ip);
          ips = str.split(',').map(s => {
            const p = s.trim().split(':');
            return { ip: p[0].trim(), port: parseInt(p[1], 10) || parseInt(data.port, 10) || 5060 };
          }).filter(x => x.ip);
        }
        if (ips.length === 0) {
          ips = [{ ip: '192.168.1.10', port: 5060 }];
        }

        // Process Prefixes
        let prefixes = [];
        if (Array.isArray(data.prefixes)) {
          prefixes = data.prefixes.map(p => String(p).replace(/[^0-9]/g, '')).filter(Boolean);
        } else if (data.prefixes || data.prefix) {
          prefixes = String(data.prefixes || data.prefix).split(',').map(p => p.replace(/[^0-9]/g, '')).filter(Boolean);
        }
        if (prefixes.length === 0) prefixes = ['92300'];

        const custObj = {
          id,
          name,
          status,
          max_channels: max_ch,
          country_code: cc,
          description: desc,
          ips,
          prefixes
        };

        const existingIdx = customers.findIndex(c => c.id === id);
        if (existingIdx >= 0) {
          customers[existingIdx] = custObj;
          addLog(`Updated Customer [${name}] (Max: ${max_ch} ch, Status: ${status}).`);
        } else {
          customers.push(custObj);
          addLog(`Created new Customer [${name}] (Max: ${max_ch} ch).`);
        }

        saveConfig();
        return res.end(JSON.stringify({ ok: true, id: custObj.id, customer: custObj, message: `Customer ${name} saved successfully.` }));
      }

      if (action === 'customer-delete') {
        const id = (data.id || '').trim();
        const idx = customers.findIndex(c => c.id === id);
        if (idx < 0) {
          res.statusCode = 404;
          return res.end(JSON.stringify({ ok: false, error: 'Customer not found' }));
        }
        const name = customers[idx].name;
        customers.splice(idx, 1);
        mappings = mappings.filter(m => m.customer_id !== id);
        saveConfig();
        addLog(`Deleted Customer [${name}] and its routing mappings.`);
        return res.end(JSON.stringify({ ok: true, message: `Customer ${name} deleted.` }));
      }

      if (action === 'customer-block' || action === 'customer-unblock') {
        const id = (data.id || '').trim();
        const cust = customers.find(c => c.id === id);
        if (!cust) {
          res.statusCode = 404;
          return res.end(JSON.stringify({ ok: false, error: 'Customer not found' }));
        }
        cust.status = (action === 'customer-block') ? 'blocked' : 'active';
        saveConfig();
        addLog(`Customer [${cust.name}] traffic ${cust.status.toUpperCase()} immediately.`);
        return res.end(JSON.stringify({ ok: true, status: cust.status, message: `Customer ${cust.name} is now ${cust.status}.` }));
      }

      // 2. VENDORS
      if (action === 'vendors' || action === 'vendors-list') {
        const cvStats = getLiveCustomerVendorStats();
        const resList = vendors.map(v => {
          const ips = v.ips || [{ ip: v.ip || '10.10.10.10', port: v.port || 5060 }];
          const prefixes = v.prefixes || (v.prefix ? [v.prefix] : ['1']);
          const act = cvStats.vend_act_map[v.id] || 0;
          return {
            ...v,
            ips,
            prefixes,
            primary_ip: ips[0] ? `${ips[0].ip}:${ips[0].port || 5060}` : '',
            prefix_str: prefixes.join(', '),
            active_calls: act,
            active: act
          };
        });
        return res.end(JSON.stringify({ ok: true, vendors: resList }));
      }

      if (action === 'vendor-save') {
        const name = (data.name || '').trim();
        if (!name) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Vendor name is required' }));
        }

        let id = (data.id || '').trim();
        if (!id) {
          id = name.toLowerCase().replace(/[^a-z0-9_-]/g, '_');
          if (!id || vendors.some(v => v.id === id)) id = 'vend_' + Date.now();
        }

        const max_ch = Math.max(1, parseInt(data.max_channels, 10) || 200);
        const status = (data.status === 'blocked') ? 'blocked' : 'active';
        const cc = (data.country_code || '').trim();
        const cid = (data.caller_id || '').trim();
        const desc = (data.description || '').trim();

        let ips = [];
        if (Array.isArray(data.ips)) {
          ips = data.ips.map(item => {
            if (typeof item === 'object') return { ip: (item.ip || '').trim(), port: parseInt(item.port, 10) || 5060 };
            const p = String(item).split(':');
            return { ip: p[0].trim(), port: parseInt(p[1], 10) || 5060 };
          }).filter(x => x.ip);
        } else if (data.ips || data.ip) {
          const str = String(data.ips || data.ip);
          ips = str.split(',').map(s => {
            const p = s.trim().split(':');
            return { ip: p[0].trim(), port: parseInt(p[1], 10) || parseInt(data.port, 10) || 5060 };
          }).filter(x => x.ip);
        }
        if (ips.length === 0) ips = [{ ip: '10.10.10.10', port: 5060 }];

        let prefixes = [];
        if (Array.isArray(data.prefixes)) {
          prefixes = data.prefixes.map(p => String(p).replace(/[^0-9]/g, '')).filter(Boolean);
        } else if (data.prefixes || data.prefix) {
          prefixes = String(data.prefixes || data.prefix).split(',').map(p => p.replace(/[^0-9]/g, '')).filter(Boolean);
        }
        if (prefixes.length === 0) prefixes = ['1'];

        const vendObj = {
          id,
          name,
          status,
          max_channels: max_ch,
          country_code: cc,
          caller_id: cid,
          description: desc,
          ips,
          prefixes
        };

        const existingIdx = vendors.findIndex(v => v.id === id);
        if (existingIdx >= 0) {
          vendors[existingIdx] = vendObj;
          addLog(`Updated Vendor [${name}] (Max: ${max_ch} ch, Status: ${status}).`);
        } else {
          vendors.push(vendObj);
          addLog(`Created new Vendor [${name}] (Max: ${max_ch} ch).`);
        }

        saveConfig();
        return res.end(JSON.stringify({ ok: true, id: vendObj.id, vendor: vendObj, message: `Vendor ${name} saved successfully.` }));
      }

      if (action === 'vendor-delete') {
        const id = (data.id || '').trim();
        const idx = vendors.findIndex(v => v.id === id);
        if (idx < 0) {
          res.statusCode = 404;
          return res.end(JSON.stringify({ ok: false, error: 'Vendor not found' }));
        }
        const name = vendors[idx].name;
        vendors.splice(idx, 1);
        mappings = mappings.filter(m => m.vendor_id !== id);
        saveConfig();
        addLog(`Deleted Vendor [${name}] and associated mappings.`);
        return res.end(JSON.stringify({ ok: true, message: `Vendor ${name} deleted.` }));
      }

      if (action === 'vendor-block' || action === 'vendor-unblock') {
        const id = (data.id || '').trim();
        const vend = vendors.find(v => v.id === id);
        if (!vend) {
          res.statusCode = 404;
          return res.end(JSON.stringify({ ok: false, error: 'Vendor not found' }));
        }
        vend.status = (action === 'vendor-block') ? 'blocked' : 'active';
        saveConfig();
        addLog(`Vendor [${vend.name}] traffic ${vend.status.toUpperCase()} immediately.`);
        return res.end(JSON.stringify({ ok: true, status: vend.status, message: `Vendor ${vend.name} is now ${vend.status}.` }));
      }

      // 3. CUSTOMER-VENDOR ROUTING MAPPINGS
      if (action === 'mappings' || action === 'mappings-list') {
        const populated = mappings.map(m => {
          const cust = customers.find(c => c.id === m.customer_id);
          const vend = vendors.find(v => v.id === m.vendor_id);
          return {
            ...m,
            customer_name: cust ? cust.name : m.customer_id,
            customer_status: cust ? cust.status : 'active',
            vendor_name: vend ? vend.name : m.vendor_id,
            vendor_status: vend ? vend.status : 'active'
          };
        }).sort((a, b) => {
          if (a.customer_id !== b.customer_id) return a.customer_id.localeCompare(b.customer_id);
          return (a.priority || 1) - (b.priority || 1);
        });
        return res.end(JSON.stringify({ ok: true, mappings: populated }));
      }

      if (action === 'mapping-save') {
        const cid = (data.customer_id || '').trim();
        const vid = (data.vendor_id || '').trim();
        if (!cid || !vid) {
          res.statusCode = 400;
          return res.end(JSON.stringify({ ok: false, error: 'Customer and Vendor are required' }));
        }

        const id = data.id ? parseInt(data.id, 10) : (mappings.length > 0 ? Math.max(...mappings.map(m => m.id)) + 1 : 1);
        const mapObj = {
          id,
          customer_id: cid,
          vendor_id: vid,
          customer_prefix: (data.customer_prefix || '').trim(),
          vendor_prefix: (data.vendor_prefix || '').trim(),
          country_code: (data.country_code || '').trim(),
          priority: Math.max(1, parseInt(data.priority, 10) || 1),
          max_channels: Math.max(1, parseInt(data.max_channels, 10) || 100),
          status: (data.status === 'disabled') ? 'disabled' : 'active'
        };

        const existingIdx = mappings.findIndex(m => m.id === id);
        if (existingIdx >= 0) {
          mappings[existingIdx] = mapObj;
          addLog(`Updated routing mapping #${id}: ${cid} (strip "${mapObj.customer_prefix}") -> ${vid} (prepend "${mapObj.vendor_prefix}") (Priority ${mapObj.priority}).`);
        } else {
          mappings.push(mapObj);
          addLog(`Created routing mapping #${id}: ${cid} (strip "${mapObj.customer_prefix}") -> ${vid} (prepend "${mapObj.vendor_prefix}") (Priority ${mapObj.priority}).`);
        }
        saveConfig();
        return res.end(JSON.stringify({ ok: true, mapping: mapObj, message: 'Routing mapping saved.' }));
      }

      if (action === 'mapping-delete') {
        const id = parseInt(data.id, 10);
        mappings = mappings.filter(m => m.id !== id);
        saveConfig();
        addLog(`Deleted routing mapping #${id}.`);
        return res.end(JSON.stringify({ ok: true, message: 'Routing mapping deleted.' }));
      }

      if (action === 'mapping-toggle') {
        const id = parseInt(data.id, 10);
        const mapItem = mappings.find(m => m.id === id);
        if (!mapItem) {
          res.statusCode = 404;
          return res.end(JSON.stringify({ ok: false, error: 'Mapping not found' }));
        }
        mapItem.status = (mapItem.status === 'active') ? 'disabled' : 'active';
        saveConfig();
        addLog(`Toggled routing mapping #${id} to ${mapItem.status}.`);
        return res.end(JSON.stringify({ ok: true, status: mapItem.status, message: `Mapping is now ${mapItem.status}.` }));
      }

      // 4. LIVE CHANNELS & TRAFFIC STATS
      if (action === 'live-channels' || action === 'traffic-stats') {
        const cvStats = getLiveCustomerVendorStats();
        return res.end(JSON.stringify({
          ok: true,
          customers: cvStats.cust_stats,
          vendors: cvStats.vend_stats,
          summary: cvStats.summary
        }));
      }

      // 5. CDR REPORTS
      if (action === 'cdr-list') {
        const filterCust = (data.customer || parsedUrl.query.customer || '').trim();
        const filterVend = (data.vendor || parsedUrl.query.vendor || '').trim();
        const filterStatus = (data.status || parsedUrl.query.status || '').trim();
        const search = (data.search || parsedUrl.query.search || '').trim();

        // Generate rich, realistic CDR records for testing
        const sampleNumbers = [
          '923001234567', '923005544332', '923019876543', '923014433221',
          '923008899001', '923007766554', '923016677889', '923012233445'
        ];
        const causes = [
          { sip: 'SIP 200 OK', disp: 'ANSWERED', durMin: 45, durMax: 420 },
          { sip: 'SIP 200 OK', disp: 'ANSWERED', durMin: 30, durMax: 240 },
          { sip: 'SIP 486 Busy Here', disp: 'BUSY', durMin: 0, durMax: 0 },
          { sip: 'SIP 200 OK', disp: 'ANSWERED', durMin: 60, durMax: 300 },
          { sip: 'SIP 503 Channel Limit Exceeded', disp: 'FAILED', durMin: 0, durMax: 0 },
          { sip: 'SIP 487 Request Terminated', disp: 'NO ANSWER', durMin: 0, durMax: 0 },
          { sip: 'SIP 200 OK', disp: 'ANSWERED', durMin: 120, durMax: 600 }
        ];

        const mockCdrs = [];
        const now = Date.now();
        for (let i = 0; i < 40; i++) {
          const tOffset = i * (45 * 1000 + Math.floor(Math.random() * 60000));
          const callTime = new Date(now - tOffset).toISOString().replace('T', ' ').slice(0, 19);
          const cIdx = i % customers.length;
          const cust = customers[cIdx] || { id: 'ptcl', name: 'PTCL' };
          const vIdx = (i + 1) % vendors.length;
          const vend = vendors[vIdx] || { id: 'boc', name: 'BOC' };
          const cSpec = causes[i % causes.length];
          const dur = cSpec.durMin > 0 ? (Math.floor(Math.random() * (cSpec.durMax - cSpec.durMin)) + cSpec.durMin) : 0;
          const cleanNum = sampleNumbers[i % sampleNumbers.length];
          const map = mappings.find(m => m.customer_id === cust.id && m.vendor_id === vend.id) || mappings[0];
          const cPfx = map ? (map.customer_prefix || '') : (cust.prefixes ? cust.prefixes[0] : '76666');
          const vPfx = map ? (map.vendor_prefix || '') : (vend.prefixes ? vend.prefixes[0] : '88555');
          const inboundDnis = cPfx ? (cPfx + cleanNum) : cleanNum;
          const dialNum = vPfx ? (vPfx + cleanNum) : cleanNum;

          const rec = {
            id: 10000 + i,
            uniqueid: `173132.${10000 + i}.m5`,
            calldate: callTime,
            customer_id: cust.id,
            customer_name: cust.name,
            vendor_id: vend.id,
            vendor_name: vend.name,
            src: '96655' + Math.floor(1000000 + Math.random() * 9000000),
            dst: dialNum,
            clean_dst: cleanNum,
            inbound_dst: inboundDnis,
            original_dst: inboundDnis,
            duration: dur,
            billsec: dur > 0 ? dur - 2 : 0,
            disposition: cSpec.disp,
            sip_code: cSpec.sip
          };

          // Apply filters
          if (filterCust && rec.customer_id !== filterCust && rec.customer_name !== filterCust) continue;
          if (filterVend && rec.vendor_id !== filterVend && rec.vendor_name !== filterVend) continue;
          if (filterStatus && rec.disposition !== filterStatus) continue;
          if (search && !rec.dst.includes(search) && !rec.src.includes(search) && !rec.inbound_dst.includes(search) && !rec.uniqueid.includes(search)) continue;

          mockCdrs.push(rec);
        }

        return res.end(JSON.stringify({ ok: true, cdrs: mockCdrs }));
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