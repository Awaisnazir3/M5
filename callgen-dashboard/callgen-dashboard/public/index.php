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
        setcookie("m5_auth", "1", time() + 86400 * 30, "/");
        header("Location: index.php");
        exit;
    } else {
        $err = "Invalid username or password";
    }
}

if (isset($_GET["logout"])) {
    session_destroy();
    setcookie("m5_auth", "", time() - 3600, "/");
    header("Location: index.php");
    exit;
}

if (empty($_SESSION["m5_auth"]) && empty($_COOKIE["m5_auth"])): ?>
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
    .preset-pill {
      background: #F2F4F7;
      color: #344054;
      border: 1px solid #D0D5DD;
      border-radius: 12px;
      padding: 2px 8px;
      font-size: 11px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .preset-pill:hover {
      background: #E4E7EC;
      border-color: #98A2B3;
      color: #1D2939;
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
      grid-template-columns: repeat(2, 1fr);
      gap: 12px 16px;
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

    /* Destination Routes Row Table */
    .routes-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      text-align: left;
    }
    .routes-table th {
      background: #F8F9FA;
      color: #667085;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 12px 16px;
      border-bottom: 1px solid #EAECF0;
      white-space: nowrap;
      vertical-align: middle;
    }
    .routes-table td {
      padding: 14px 16px;
      border-bottom: 1px solid #EAECF0;
      vertical-align: middle;
      color: var(--brown);
    }
    .routes-table tbody tr {
      transition: background 0.15s ease;
    }
    .routes-table tbody tr:hover {
      background: #F9FAFB;
    }
    .routes-table tbody tr:last-child td {
      border-bottom: none;
    }
    .btn-action-group {
      display: inline-flex;
      align-items: center;
      justify-content: flex-end;
      gap: 6px;
      white-space: nowrap;
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

    /* Live Channel Status Box */
    .metric-single-box {
      background: #F8F9FA;
      padding: 14px 18px;
      border-radius: 8px;
      border: 1px solid #EAECF0;
      display: flex;
      flex-direction: column;
      gap: 3px;
    }
    .metric-item-val {
      font-size: 22px;
      font-weight: 800;
      color: var(--brown);
      letter-spacing: -0.3px;
    }
    .metric-item-lbl {
      font-size: 11px;
      font-weight: 700;
      color: #667085;
      text-transform: uppercase;
      letter-spacing: 0.5px;
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

    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(30, 25, 22, 0.52);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      opacity: 1;
      visibility: visible;
      transition: opacity 0.2s ease, visibility 0.2s ease;
      padding: 16px;
    }
    .modal-overlay.hidden {
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
    }
    .modal-dialog {
      background: #FFFFFF;
      border: 1px solid var(--border);
      border-radius: 14px;
      box-shadow: 0 20px 48px rgba(0, 0, 0, 0.22);
      width: 100%;
      max-width: 600px;
      max-height: 90vh;
      display: flex;
      flex-direction: column;
      animation: modalFadeIn 0.2s ease-out;
      overflow: hidden;
    }
    @keyframes modalFadeIn {
      from { transform: scale(0.96) translateY(8px); opacity: 0; }
      to { transform: scale(1) translateY(0); opacity: 1; }
    }
    .modal-header {
      background: #FAFBF9;
      border-bottom: 1px solid var(--border);
      padding: 16px 22px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .modal-close {
      background: transparent;
      border: none;
      font-size: 24px;
      line-height: 1;
      color: var(--muted);
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 6px;
      transition: background 0.15s, color 0.15s;
    }
    .modal-close:hover {
      background: #F0ECE8;
      color: var(--brown);
    }
    .modal-body {
      padding: 22px 24px;
      overflow-y: auto;
    }
    .btn-gear {
      background: transparent;
      border: 1px solid transparent;
      font-size: 14px;
      cursor: pointer;
      padding: 2px 6px;
      border-radius: 6px;
      color: var(--muted);
      transition: all 0.15s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }
    .btn-gear:hover {
      background: var(--brown-light);
      border-color: #DBCFC9;
      color: var(--brown);
      transform: rotate(30deg);
    }
    .btn-link-gear {
      background: transparent;
      border: none;
      font-size: 11px;
      font-weight: 700;
      color: var(--sage);
      cursor: pointer;
      padding: 2px 4px;
      border-radius: 4px;
      transition: background 0.15s, color 0.15s;
    }
    .btn-link-gear:hover {
      background: #E8EFEA;
      color: var(--sage-hover);
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
        <span>Operator: <b><?= htmlspecialchars($_SESSION["operator"] ?? "awais") ?></b></span>
      </div>
      <a href="?logout=1" class="btn-logout">Sign Out</a>
    </div>
  </header>

  <main class="container">
    <div class="section-header">
      <div>
        <div class="section-title">
          <span>Destination Traffic Routes</span>
        </div>
        <div style="font-size:12px; color:var(--muted); margin-top:2px;">
          Live Asterisk channels & quota monitoring
        </div>
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <button class="btn btn-sage btn-sm" onclick="startAll()">▶ Start All Trunks</button>
        <button class="btn btn-red btn-sm" onclick="stopAll()">⏹ Stop All Trunks</button>
        <button class="btn btn-outline btn-sm" onclick="hangupAll()" style="color:var(--red);border-color:#FECDCA;font-weight:700;" title="Hangup active calls across all routes">🛑 Hangup All Calls</button>
      </div>
    </div>

    <!-- Destination Traffic Routes Row Table -->
    <div style="background:#FFFFFF;border-radius:12px;border:1px solid #E4E7EC;box-shadow:0 1px 3px rgba(16,24,40,0.06);overflow:hidden;margin-bottom:24px;">
      <div style="overflow-x:auto;">
        <table class="routes-table">
          <thead>
            <tr>
              <th style="min-width:180px;">Destination Route</th>
              <th style="min-width:130px;">Status & Context</th>
              <th style="min-width:145px;">Channels (Live / Max)</th>
              <th style="min-width:220px;">Monthly Minutes Quota</th>
              <th style="min-width:160px;">Target & Routing</th>
              <th style="min-width:240px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody id="routes-table-tbody">
            <!-- Populated via buildRouteTableRow() -->
          </tbody>
        </table>
      </div>
    </div>

    <!-- Dedicated Inbound Call Gateway & Prefix Routing -->
    <section class="inbound-section" style="margin-top:28px;">
      <div style="background:#FFFFFF;border-radius:12px;border:1px solid #E4E7EC;padding:22px;box-shadow:0 1px 3px rgba(16,24,40,0.06);">
        <!-- Header -->
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:12px;">
          <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:24px;">📥</span>
            <div>
              <h2 style="font-size:16px;font-weight:800;color:var(--brown);margin:0;">Inbound Call Gateway & Allowed Source IPs</h2>
              <div style="font-size:12px;color:var(--muted);margin-top:2px;">
                Manage authorized inbound source IPs and allowed breakout prefixes
              </div>
            </div>
          </div>
          <div style="display:flex;align-items:center;gap:10px;">
            <span id="inbound-active-badge" style="background:#F2F4F7;color:var(--muted);padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;">
              ○ 0 Active Inbound Calls
            </span>
            <button type="button" class="btn btn-sage btn-sm" onclick="openAddSourceIpModal()">
              + Add Allowed Source IP
            </button>
          </div>
        </div>

        <!-- Inbound Breakout Prefix Routing & Context Duration Limits -->
        <div style="margin-bottom:18px;background:#F9FAFB;border-radius:8px;border:1px solid #EAECF0;padding:16px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
            <div>
              <span style="font-size:12px;font-weight:800;color:var(--brown);text-transform:uppercase;letter-spacing:0.5px;">
                ⏱ Inbound Breakout Prefix Routing & Context Duration Limits
              </span>
              <div style="font-size:11px;color:var(--muted);margin-top:2px;">
                Configure allowed breakout prefixes and maximum call timeouts in Asterisk dialplan
              </div>
            </div>
            <button type="button" class="btn btn-sage btn-sm" id="btn-save-breakout" onclick="saveBreakoutSettings()" style="padding:6px 14px;font-size:12px;font-weight:700;">
              💾 Save Breakout & Duration Settings
            </button>
          </div>

          <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:14px;">
            <!-- [testcall] Cuba & Senegal Breakout Card -->
            <div style="background:#FFFFFF;border-radius:10px;border:1px solid #EAECF0;padding:16px;box-shadow:0 1px 3px rgba(16,24,40,0.04);display:flex;flex-direction:column;gap:12px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div style="display:flex;align-items:center;gap:10px;">
                  <span style="font-weight:800;font-size:12px;color:var(--brown);background:#F2F4F7;padding:4px 8px;border-radius:6px;letter-spacing:0.5px;">CU SN</span>
                  <div>
                    <div style="font-size:14px;font-weight:800;color:var(--brown);">Cuba & Senegal Breakout</div>
                    <div style="font-size:11px;color:var(--muted);margin-top:1px;">Cuba (+53) & Senegal (+221) Route Settings</div>
                  </div>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                  <span style="background:#EBF5EA;color:#2D6A28;padding:2px 8px;border-radius:6px;font-family:monospace;font-weight:800;font-size:11px;">[testcall]</span>
                  <span style="background:#EBF5EA;color:#2D6A28;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">Strict Routing</span>
                </div>
              </div>

              <div>
                <label style="font-size:11px;font-weight:800;color:var(--brown);text-transform:uppercase;letter-spacing:0.4px;display:flex;justify-content:space-between;margin-bottom:5px;">
                  <span>ALLOWED BREAKOUT PREFIXES / CC</span>
                  <span style="font-size:10.5px;font-weight:600;color:var(--muted);text-transform:none;">Comma separated</span>
                </label>
                <input type="text" id="inbound-testcall-pfx" name="testcall_prefixes" value="444453, 619334221" placeholder="444453, 619334221" style="width:100%;padding:9px 12px;font-family:monospace;font-size:13px;font-weight:700;color:var(--brown);border:1px solid #D0D5DD;border-radius:6px;background:#FAFAFA;">
                <div style="font-size:11px;color:var(--muted);margin-top:4px;">
                  Allowed Cuba & Senegal breakout prefixes. Unlisted prefixes are rejected.
                </div>
              </div>

              <div style="border-top:1px solid #F2F4F7;padding-top:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                  <label style="font-size:11px;font-weight:800;color:var(--brown);text-transform:uppercase;letter-spacing:0.4px;">
                    MAX CALL DURATION LIMIT
                  </label>
                  <span id="testcall-human-dur" style="font-size:11.5px;color:var(--sage-dark);font-weight:700;">60s (1m)</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                  <input type="number" id="input-testcall-dur" min="1" max="86400" value="60" oninput="updateDurHuman('testcall')" style="flex:1;padding:7px 12px;font-size:13px;font-weight:700;color:var(--brown);border:1px solid #D0D5DD;border-radius:6px;">
                  <span style="font-size:11.5px;font-weight:700;color:var(--muted);">seconds</span>
                </div>
                <div style="display:flex;gap:5px;margin-top:8px;flex-wrap:wrap;">
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 30)">30s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 45)">45s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 60)">60s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 90)">90s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 120)">2m</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 300)">5m</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall', 600)">10m</button>
                </div>
              </div>
            </div>

            <!-- [testcall2] Mali Breakout Card -->
            <div style="background:#FFFFFF;border-radius:10px;border:1px solid #EAECF0;padding:16px;box-shadow:0 1px 3px rgba(16,24,40,0.04);display:flex;flex-direction:column;gap:12px;">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div style="display:flex;align-items:center;gap:10px;">
                  <span style="font-weight:800;font-size:12px;color:var(--brown);background:#F2F4F7;padding:4px 8px;border-radius:6px;letter-spacing:0.5px;">ML</span>
                  <div>
                    <div style="font-size:14px;font-weight:800;color:var(--brown);">Mali Breakout</div>
                    <div style="font-size:11px;color:var(--muted);margin-top:1px;">Mali (+223) Route Settings</div>
                  </div>
                </div>
                <div style="display:flex;gap:6px;align-items:center;">
                  <span style="background:#EBF3FB;color:#1D5A8C;padding:2px 8px;border-radius:6px;font-family:monospace;font-weight:800;font-size:11px;">[testcall2]</span>
                  <span style="background:#EBF3FB;color:#1D5A8C;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700;">Strict Routing</span>
                </div>
              </div>

              <div>
                <label style="font-size:11px;font-weight:800;color:var(--brown);text-transform:uppercase;letter-spacing:0.4px;display:flex;justify-content:space-between;margin-bottom:5px;">
                  <span>ALLOWED BREAKOUT PREFIXES / CC</span>
                  <span style="font-size:10.5px;font-weight:600;color:var(--muted);text-transform:none;">Comma separated</span>
                </label>
                <input type="text" id="inbound-testcall2-pfx" name="testcall2_prefixes" value="2237" placeholder="2237" style="width:100%;padding:9px 12px;font-family:monospace;font-size:13px;font-weight:700;color:var(--brown);border:1px solid #D0D5DD;border-radius:6px;background:#FAFAFA;">
                <div style="font-size:11px;color:var(--muted);margin-top:4px;">
                  Allowed Mali breakout prefixes. Unlisted prefixes are rejected.
                </div>
              </div>

              <div style="border-top:1px solid #F2F4F7;padding-top:10px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                  <label style="font-size:11px;font-weight:800;color:var(--brown);text-transform:uppercase;letter-spacing:0.4px;">
                    MAX CALL DURATION LIMIT
                  </label>
                  <span id="testcall2-human-dur" style="font-size:11.5px;color:var(--sage-dark);font-weight:700;">50s</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                  <input type="number" id="input-testcall2-dur" min="1" max="86400" value="50" oninput="updateDurHuman('testcall2')" style="flex:1;padding:7px 12px;font-size:13px;font-weight:700;color:var(--brown);border:1px solid #D0D5DD;border-radius:6px;">
                  <span style="font-size:11.5px;font-weight:700;color:var(--muted);">seconds</span>
                </div>
                <div style="display:flex;gap:5px;margin-top:8px;flex-wrap:wrap;">
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 30)">30s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 45)">45s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 50)">50s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 60)">60s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 90)">90s</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 120)">2m</button>
                  <button type="button" class="preset-pill" onclick="setDurPreset('testcall2', 300)">5m</button>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 1. Allowed Inbound Source IPs Table (Multiple IPs) -->
        <div style="margin-bottom:18px;background:#F9FAFB;border-radius:8px;border:1px solid #EAECF0;overflow:hidden;">
          <div style="padding:10px 14px;background:#F2F4F7;font-size:11px;font-weight:700;color:var(--brown);text-transform:uppercase;display:flex;justify-content:space-between;align-items:center;">
            <span>Authorized Inbound Source IPs (Remote PBX / Forwarders)</span>
            <span id="source-ips-count" style="font-weight:600;color:var(--muted);text-transform:none;">1 IP active</span>
          </div>
          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;text-align:left;">
              <thead>
                <tr style="border-bottom:1px solid #EAECF0;color:var(--muted);font-size:10px;text-transform:uppercase;">
                  <th style="padding:10px 14px;width:25%;">Source IP Address</th>
                  <th style="padding:10px 14px;width:25%;">Gateway Label / Note</th>
                  <th style="padding:10px 14px;width:35%;">Allowed CC & Prefixes</th>
                  <th style="padding:10px 14px;width:15%;text-align:right;">Actions</th>
                </tr>
              </thead>
              <tbody id="source-ips-tbody">
                <!-- Populated via renderSourceIpsTable() -->
              </tbody>
            </table>
          </div>
        </div>

        <!-- 2. Monthly Inbound Consumed Minutes Table (Cuba, Mali, Senegal Only) -->
        <div style="margin-bottom:0;background:#FFFFFF;border-radius:8px;border:1px solid #EAECF0;overflow:hidden;box-shadow:0 1px 2px rgba(16,24,40,0.04);">
          <div style="padding:12px 16px;background:#F8F9FA;border-bottom:1px solid #EAECF0;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <div>
              <span style="font-size:13px;font-weight:800;color:var(--brown);text-transform:uppercase;letter-spacing:0.5px;">
                📊 Monthly Inbound Consumed Minutes (Cuba, Mali, Senegal)
              </span>
              <div style="font-size:11px;color:var(--muted);margin-top:2px;">
                Live minutes accrued from SQLite Database (<code>/var/lib/callgen/callgen.db</code>) for current calendar month
              </div>
            </div>
            <span style="font-size:11px;color:var(--sage-dark);font-weight:700;background:#F5F8F4;padding:4px 10px;border-radius:6px;border:1px solid #D8E2D5;">
              ● Monitored Destinations
            </span>
          </div>

          <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:12.5px;text-align:left;">
              <thead>
                <tr style="border-bottom:1px solid #EAECF0;color:var(--muted);font-size:10.5px;text-transform:uppercase;background:#FAFAFA;">
                  <th style="padding:10px 14px;">Destination Country</th>
                  <th style="padding:10px 14px;">Country Code & Prefix</th>
                  <th style="padding:10px 14px;">Total Inbound Calls</th>
                  <th style="padding:10px 14px;">Answered Calls</th>
                  <th style="padding:10px 14px;">Total Consumed Minutes</th>
                  <th style="padding:10px 14px;">Active Channels</th>
                </tr>
              </thead>
              <tbody id="country-records-tbody">
                <!-- Populated via renderCountryRecordsTable() -->
              </tbody>
              <tfoot id="country-records-tfoot">
                <!-- Total Row -->
              </tfoot>
            </table>
          </div>
        </div>

      </div>
    </section>
  </main>

  <!-- Add / Edit Allowed Inbound Source IP Modal Dialog -->
  <div id="source-ip-modal-overlay" class="modal-overlay hidden" onclick="handleSourceIpOverlayClick(event)">
    <div class="modal-dialog" style="max-width:480px;" role="dialog" aria-modal="true">
      <div class="modal-header">
        <div style="display:flex;align-items:center;gap:10px;">
          <span style="font-size:20px;">🌐</span>
          <div>
            <div style="font-weight:800;font-size:16px;color:var(--brown);" id="source-ip-modal-title">Allowed Source IP</div>
            <div style="font-size:11px;color:var(--muted);">Configure remote authorized gateway & prefixes</div>
          </div>
        </div>
        <button type="button" class="modal-close" onclick="closeSourceIpModal()" title="Close">&times;</button>
      </div>

      <div class="modal-body">
        <form id="source-ip-form" onsubmit="saveSourceIpItem(event)">
          <div style="display:flex;flex-direction:column;gap:12px;">
            <div class="field-group">
              <label>Source IP Address / Host</label>
              <input type="text" id="sip-input-ip" required pattern="[A-Za-z0-9.:-]+" placeholder="e.g. 192.168.1.13">
              <span style="font-size:10px;color:var(--muted);">Remote PBX address sending incoming calls to 198</span>
            </div>

            <div class="field-group">
              <label>Gateway Label / Name</label>
              <input type="text" id="sip-input-label" placeholder="e.g. Node-13 Forwarder">
            </div>

            <div class="field-group">
              <label>Allowed CC & Prefixes (Comma Separated)</label>
              <input type="text" id="sip-input-prefix" placeholder="e.g. 444453, 619334221, 2237">
              <span style="font-size:10px;color:var(--muted);">Prefixes/CC associated with this gateway</span>
              <div style="display:flex;gap:5px;margin-top:6px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:10.5px;color:var(--muted);font-weight:600;">Quick Insert:</span>
                <button type="button" class="preset-pill" onclick="insertSipPfx('444453, 619334221, 2237')">All Breakouts</button>
                <button type="button" class="preset-pill" onclick="insertSipPfx('444453')">Cuba (444453)</button>
                <button type="button" class="preset-pill" onclick="insertSipPfx('619334221')">Senegal (619334221)</button>
                <button type="button" class="preset-pill" onclick="insertSipPfx('2237')">Mali (2237)</button>
              </div>
            </div>

            <div class="field-group">
              <label>Country Code (Optional)</label>
              <input type="text" id="sip-input-cc" placeholder="e.g. 223, 53, 221">
            </div>

            <input type="hidden" id="sip-input-context" value="auto">
          </div>

          <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end;">
            <button type="button" class="btn btn-outline" style="padding:10px 18px;font-size:13px;" onclick="closeSourceIpModal()">
              Cancel
            </button>
            <button type="submit" id="btn-save-sip-item" class="btn btn-sage" style="padding:10px 20px;font-size:13px;font-weight:700;">
              💾 Save Source IP
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Configuration Modal Dialog -->
  <div id="config-modal-overlay" class="modal-overlay hidden" onclick="handleModalOverlayClick(event)">
    <div class="modal-dialog" role="dialog" aria-modal="true">
      <div class="modal-header">
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="flag-badge" id="modal-flag">XX</span>
          <div>
            <div style="font-weight:800;font-size:16px;color:var(--brown);" id="modal-title">Configure Trunk</div>
            <div style="font-size:11px;color:var(--muted);font-family:monospace;" id="modal-script">Engine Script</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <span id="modal-status" class="trunk-status"></span>
          <button type="button" class="modal-close" onclick="closeConfigModal()" title="Close">&times;</button>
        </div>
      </div>

      <div class="modal-body">
        <form id="modal-form" onsubmit="saveTrunkModal(event)">
          <input type="hidden" id="modal-trunk-id" name="trunk">
          
          <div class="fields-grid">
            <div class="field-group full">
              <label>Route / Destination Name</label>
              <input type="text" id="modal-name" name="name" placeholder="e.g. Mali, Cuba, Senegal, China" oninput="handleModalNameChange(this.value)" required>
            </div>
            <div class="field-group">
              <label>Destination IP (Target Host)</label>
              <input type="text" id="modal-ip" name="target_ip" required pattern="[A-Za-z0-9.:-]+">
            </div>
            <div class="field-group">
              <label>Country Code (CC)</label>
              <input type="text" id="modal-cc" name="country_code" placeholder="e.g. 53, 221, 2237" oninput="handleModalPrefixCCChange()">
            </div>
            <div class="field-group">
              <label>Prefix</label>
              <input type="text" id="modal-prefix" name="prefix" placeholder="e.g. 4444, 619334" oninput="handleModalPrefixCCChange()">
            </div>
            <div class="field-group">
              <label>Full Dialed Prefix (Prefix + CC)</label>
              <input type="text" id="modal-prefix-cid" name="prefix_cid" placeholder="e.g. 444453">
            </div>
            <div class="field-group">
              <label>Max Concurrency (Channels)</label>
              <input type="number" id="modal-max" name="max_channels" min="1" max="2000" required>
            </div>
            <div class="field-group">
              <label>Monthly Quota Limit (Minutes)</label>
              <input type="number" id="modal-limit" name="monthly_limit" min="1" max="10000000" required>
            </div>
            <div class="field-group">
              <label>Assigned Dialplan Context</label>
              <select id="modal-ctx" name="context" style="width:100%;padding:10px 14px;border:1px solid #D0D5DD;border-radius:8px;font-weight:600;color:var(--brown);">
                <option value="testcall">[testcall] - Cuba & Senegal (Default)</option>
                <option value="testcall2">[testcall2] - Mali Route</option>
              </select>
            </div>
            <input type="hidden" id="modal-dur" name="call_duration" value="600">
          </div>

          <div style="margin-top:22px;display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="btn btn-outline" style="padding:11px 20px;font-size:13px;" onclick="closeConfigModal()">
              Cancel
            </button>
            <button type="submit" id="modal-save-btn" class="btn btn-sage" style="padding:11px 24px;font-size:14px;font-weight:700;">
              💾 Save Settings
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script>
    const TRUNK_META = {
      mali: { script: "asterisk_callgen.sh" },
      cuba: { script: "asterisk_callgen2.sh" },
      senegal: { script: "asterisk_callgen3.sh" }
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
      const words = name.trim().split(/s+/);
      if (words.length >= 2) return (words[0][0] + words[1][0]).toUpperCase();
      return name.trim().slice(0, 2).toUpperCase();
    }

    let currentModalTrunkId = null;

    function openConfigModal(id) {
      const t = trunksData[id];
      if (!t) return;
      currentModalTrunkId = id;

      const routeName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
      const flagCode = getRouteFlag(routeName, id);
      const scriptName = (TRUNK_META[id] && TRUNK_META[id].script) || `${id}_callgen.sh`;

      const idEl = document.getElementById('modal-trunk-id');
      if (idEl) idEl.value = id;
      const titleEl = document.getElementById('modal-title');
      if (titleEl) titleEl.textContent = `${routeName} Configuration`;
      const scriptEl = document.getElementById('modal-script');
      if (scriptEl) scriptEl.textContent = `Engine Script: ${scriptName}`;
      const flagEl = document.getElementById('modal-flag');
      if (flagEl) flagEl.textContent = flagCode;

      const modalStatus = document.getElementById('modal-status');
      if (modalStatus) {
        if (t.running) {
          modalStatus.className = 'trunk-status status-running';
          modalStatus.textContent = '● ACTIVE';
        } else {
          modalStatus.className = 'trunk-status status-stopped';
          modalStatus.textContent = '○ STANDBY';
        }
      }

      const nameEl = document.getElementById('modal-name');
      if (nameEl) nameEl.value = routeName;
      const ipEl = document.getElementById('modal-ip');
      if (ipEl) ipEl.value = t.target_ip || '';
      const ccEl = document.getElementById('modal-cc');
      if (ccEl) ccEl.value = t.country_code || '';
      const pfxEl = document.getElementById('modal-prefix');
      if (pfxEl) pfxEl.value = t.prefix || '';
      const cidEl = document.getElementById('modal-prefix-cid');
      if (cidEl) cidEl.value = t.prefix_cid || '';
      const limEl = document.getElementById('modal-limit');
      if (limEl) limEl.value = t.monthly_limit || 100000;
      const maxEl = document.getElementById('modal-max');
      if (maxEl) maxEl.value = t.max_channels || '';
      const durEl = document.getElementById('modal-dur');
      if (durEl) durEl.value = t.call_duration || 600;
      const ctxEl = document.getElementById('modal-ctx');
      if (ctxEl) ctxEl.value = t.context || 'testcall';

      const saveBtn = document.getElementById('modal-save-btn');
      if (saveBtn) {
        saveBtn.innerHTML = '💾 Save Settings';
        saveBtn.style.background = '';
        saveBtn.style.color = '';
        saveBtn.disabled = false;
      }

      const overlay = document.getElementById('config-modal-overlay');
      if (overlay) overlay.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
    }

    function closeConfigModal() {
      const overlay = document.getElementById('config-modal-overlay');
      if (overlay) overlay.classList.add('hidden');
      document.body.style.overflow = '';
      currentModalTrunkId = null;
    }

    function handleModalOverlayClick(e) {
      if (e.target && e.target.id === 'config-modal-overlay') {
        closeConfigModal();
      }
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeConfigModal();
    });

    function handleModalPrefixCCChange() {
      const pfx = (document.getElementById('modal-prefix').value || '').trim();
      const cc = (document.getElementById('modal-cc').value || '').trim();
      const cid = document.getElementById('modal-prefix-cid');
      if (cid) cid.value = pfx + cc;
    }

    function handleModalNameChange(val) {
      const displayName = val.trim() || 'Trunk';
      const flagCode = getRouteFlag(displayName, currentModalTrunkId);
      const titleEl = document.getElementById('modal-title');
      if (titleEl) titleEl.textContent = `${displayName} Configuration`;
      const flagEl = document.getElementById('modal-flag');
      if (flagEl) flagEl.textContent = flagCode;
    }

    async function saveTrunkModal(e) {
      e.preventDefault();
      const id = currentModalTrunkId;
      if (!id) return;

      const saveBtn = document.getElementById('modal-save-btn');
      try {
        if (saveBtn) {
          saveBtn.innerHTML = '⏳ Saving...';
          saveBtn.disabled = true;
        }

        const form = document.getElementById('modal-form');
        const d = Object.fromEntries(new FormData(form));
        d.trunk = id;
        d.monthly_limit = Number(d.monthly_limit);
        d.max_channels = Number(d.max_channels);
        d.call_duration = Number(d.call_duration || (trunksData[id] && trunksData[id].call_duration) || 600);

        const res = await api('save', d);
        addLog(`[${(d.name || id).toUpperCase()}] ${res.message}`);

        if (saveBtn) {
          saveBtn.innerHTML = '✓ Settings Saved!';
          saveBtn.style.background = 'var(--green)';
          saveBtn.style.color = '#FFFFFF';
        }

        // Instant local sync
        if (trunksData[id]) {
          trunksData[id] = { ...trunksData[id], ...d };
        }
        updateMetricsOnly(trunksData, false);

        setTimeout(() => {
          closeConfigModal();
          refreshAll(true);
        }, 600);

      } catch (err) {
        console.error("Save error:", err);
        addLog(`[${id.toUpperCase()}] Save error: ${err.message}`);
        if (saveBtn) {
          saveBtn.innerHTML = "❌ " + (err.message || "Save Failed");
          saveBtn.style.background = "var(--red)";
          saveBtn.style.color = "#FFFFFF";
          setTimeout(() => {
            saveBtn.innerHTML = "💾 Save Settings";
            saveBtn.style.background = "";
            saveBtn.style.color = "";
            saveBtn.disabled = false;
          }, 3000);
        }
        alert("Save Error: " + err.message);
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
        div.textContent = `[${time}] ${msg}`;
        el.insertBefore(div, el.firstChild);
        const logTime = document.getElementById('log-time');
        if (logTime) logTime.textContent = 'Last event: ' + time;
      }
    }

    function buildRouteTableRow(id, t) {
      const routeName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
      const flagCode = getRouteFlag(routeName, id);
      const scriptName = (TRUNK_META[id] && TRUNK_META[id].script) || `${id}_callgen.sh`;
      const maxChan = Number(t.max_channels) || 1;
      const activeCalls = Number(t.active_calls) || 0;
      const chanPct = Math.min(100, Math.round((activeCalls / maxChan) * 100)) || 0;
      const limitMin = Number(t.monthly_limit) || 100000;
      const usedMin = Number(t.used_minutes) || 0;
      const pct = Math.min(100, Math.round((usedMin / limitMin) * 100)) || 0;
      const ctxName = t.context || (id === 'mali' ? 'testcall2' : 'testcall');

      const tr = document.createElement('tr');
      tr.id = 'route-row-' + id;
      tr.innerHTML = `
        <!-- 1. Destination Route -->
        <td>
          <div style="display:flex;align-items:center;gap:10px;">
            <span class="flag-badge" id="flag-${id}" style="font-size:11px;padding:3px 7px;">${flagCode}</span>
            <div>
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-weight:800;font-size:14px;color:var(--brown);" id="tbl-name-${id}">${routeName}</span>
                <button type="button" class="btn-gear" title="Configure ${routeName}" onclick="openConfigModal('${id}')" style="font-size:12px;opacity:0.85;">⚙️</button>
              </div>
              <div style="font-size:11px;color:var(--muted);font-family:monospace;margin-top:2px;" id="tbl-script-${id}">
                ${scriptName}
              </div>
            </div>
          </div>
        </td>

        <!-- 2. Status & Context -->
        <td>
          <div style="display:flex;flex-direction:column;gap:5px;align-items:flex-start;">
            <span id="badge-${id}" class="trunk-status ${t.running ? 'status-running' : 'status-stopped'}" style="font-size:11px;padding:3px 8px;">
              ${t.running ? '● ACTIVE' : '○ STANDBY'}
            </span>
            <span style="font-size:10.5px;font-weight:700;color:#2D6A28;background:#EBF5EA;padding:1px 6px;border-radius:4px;font-family:monospace;" id="tbl-ctx-${id}">
              [${ctxName}]
            </span>
          </div>
        </td>

        <!-- 3. Channels (Live / Max) -->
        <td>
          <div style="display:flex;flex-direction:column;gap:4px;">
            <div style="font-weight:800;font-size:13.5px;color:${(t.running && activeCalls > 0) ? 'var(--green)' : 'var(--brown)'};" id="active-txt-${id}">
              ${activeCalls} <span style="font-size:11.5px;font-weight:600;color:var(--muted);">/ ${maxChan} ch</span>
            </div>
            <div style="width:110px;height:5px;background:#EAECF0;border-radius:3px;overflow:hidden;">
              <div id="chan-bar-${id}" style="width:${chanPct}%;height:100%;background:${chanPct > 80 ? 'var(--red)' : 'var(--sage)'};border-radius:3px;transition:width 0.3s;"></div>
            </div>
          </div>
        </td>

        <!-- 4. Monthly Minutes Quota -->
        <td>
          <div style="display:flex;flex-direction:column;gap:3px;max-width:240px;">
            <div style="display:flex;justify-content:space-between;align-items:baseline;">
              <span style="font-weight:800;font-size:13px;color:var(--brown);" id="used-min-${id}">
                ${usedMin.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1})} <span style="font-size:10.5px;font-weight:600;color:var(--muted);">min</span>
              </span>
              <span style="font-size:11px;font-weight:700;color:${pct > 90 ? 'var(--red)' : 'var(--sage-dark)'};" id="limit-pct-${id}">
                ${pct}% used
              </span>
            </div>
            <div style="width:100%;height:5px;background:#EAECF0;border-radius:3px;overflow:hidden;">
              <div id="prog-bar-${id}" style="width:${pct}%;height:100%;background:${pct > 90 ? 'var(--red)' : 'var(--sage)'};border-radius:3px;transition:width 0.3s;"></div>
            </div>
            <div style="font-size:10.5px;color:var(--muted);margin-top:1px;" id="limit-txt-${id}">
              Monthly Limit: <b>${limitMin.toLocaleString()}</b> min
            </div>
          </div>
        </td>

        <!-- 5. Target & Routing -->
        <td>
          <div style="display:flex;flex-direction:column;gap:2px;">
            <div style="font-family:monospace;font-size:12px;font-weight:700;color:var(--brown);" id="disp-ip-${id}">
              ${t.target_ip || '-'}
            </div>
            <div style="font-size:11px;color:var(--muted);" id="disp-routing-${id}">
              <span>+${t.country_code || '-'}</span> 
              <span style="font-family:monospace;font-weight:700;color:var(--sage-dark);" id="disp-dial-${id}">(${t.prefix_cid || t.prefix || '-'})</span>
            </div>
          </div>
        </td>

        <!-- 6. Actions (Config Gear, Start/Stop Route, Hangup Route) -->
        <td style="text-align:right;">
          <div class="btn-action-group">
            <button type="button" class="btn btn-outline btn-sm" onclick="openConfigModal('${id}')" title="Configure ${routeName} Settings" style="padding:6px 11px;font-size:12px;font-weight:600;display:inline-flex;align-items:center;gap:4px;">
              ⚙️ Config
            </button>
            <span id="actions-${id}">
              <button type="button" class="btn ${t.running ? 'btn-red' : 'btn-sage'} btn-sm" id="btn-toggle-${id}" onclick="toggleTrunk('${id}', '${t.running ? 'stop' : 'start'}')" style="padding:6px 12px;font-size:12px;font-weight:700;min-width:78px;">
                ${t.running ? '⏹ Stop' : '▶ Start'}
              </button>
            </span>
            <button type="button" class="btn btn-outline btn-sm" id="btn-hangup-${id}" onclick="hangupTrunk('${id}')" title="Terminate all live calls on ${routeName} route" style="padding:6px 11px;font-size:12px;font-weight:700;color:var(--red);border-color:#FECDCA;display:inline-flex;align-items:center;gap:4px;">
              🛑 Hangup
            </button>
          </div>
        </td>
      `;
      return tr;
    }

    function updateMetricsOnly(trunks, forceSyncInputs = false) {
      let totalActive = 0;
      let runningCount = 0;

      for (const [id, t] of Object.entries(trunks)) {
        totalActive += (t.active_calls || 0);
        if (t.running) runningCount++;

        const curName = t.name || (id.charAt(0).toUpperCase() + id.slice(1));
        const flagCode = getRouteFlag(curName, id);
        const maxChan = Number(t.max_channels) || 1;
        const activeCalls = Number(t.active_calls) || 0;
        const ctxName = t.context || (id === 'mali' ? 'testcall2' : 'testcall');
        const scriptName = (TRUNK_META[id] && TRUNK_META[id].script) || `${id}_callgen.sh`;

        // 1. Route Name & Script
        const tblName = document.getElementById('tbl-name-' + id);
        if (tblName) tblName.textContent = curName;
        const flagEl = document.getElementById('flag-' + id);
        if (flagEl) flagEl.textContent = flagCode;
        const tblScript = document.getElementById('tbl-script-' + id);
        if (tblScript) tblScript.textContent = scriptName;

        // 2. Status badge & context badge
        const badgeEl = document.getElementById('badge-' + id);
        if (badgeEl) {
          if (t.running) {
            badgeEl.className = 'trunk-status status-running';
            badgeEl.textContent = '● ACTIVE';
          } else {
            badgeEl.className = 'trunk-status status-stopped';
            badgeEl.textContent = '○ STANDBY';
          }
        }
        const tblCtx = document.getElementById('tbl-ctx-' + id);
        if (tblCtx) tblCtx.textContent = `[${ctxName}]`;

        // 3. Channels count & utilization bar
        const activeTxt = document.getElementById('active-txt-' + id);
        if (activeTxt) {
          activeTxt.innerHTML = `${activeCalls} <span style="font-size:11.5px;font-weight:600;color:var(--muted);">/ ${maxChan} ch</span>`;
          activeTxt.style.color = (t.running && activeCalls > 0) ? 'var(--green)' : 'var(--brown)';
        }
        const chanPct = Math.min(100, Math.round((activeCalls / maxChan) * 100)) || 0;
        const chanBar = document.getElementById('chan-bar-' + id);
        if (chanBar) {
          chanBar.style.width = chanPct + '%';
          chanBar.style.background = chanPct > 80 ? 'var(--red)' : 'var(--sage)';
        }

        // 4. Monthly Minutes Quota update
        const limitMin = Number(t.monthly_limit) || 100000;
        const usedMin = Number(t.used_minutes) || 0;
        const pct = Math.min(100, Math.round((usedMin / limitMin) * 100)) || 0;

        const usedMinEl = document.getElementById('used-min-' + id);
        if (usedMinEl) usedMinEl.innerHTML = `${usedMin.toLocaleString(undefined, {minimumFractionDigits: 1, maximumFractionDigits: 1})} <span style="font-size:10.5px;font-weight:600;color:var(--muted);">min</span>`;
        const limitTxtEl = document.getElementById('limit-txt-' + id);
        if (limitTxtEl) limitTxtEl.innerHTML = `Monthly Limit: <b>${limitMin.toLocaleString()}</b> min`;
        const limitPctEl = document.getElementById('limit-pct-' + id);
        if (limitPctEl) {
          limitPctEl.textContent = `${pct}% used`;
          limitPctEl.style.color = pct > 90 ? 'var(--red)' : 'var(--sage-dark)';
        }
        const progBarEl = document.getElementById('prog-bar-' + id);
        if (progBarEl) {
          progBarEl.style.width = pct + '%';
          progBarEl.style.background = pct > 90 ? 'var(--red)' : 'var(--sage)';
        }

        // 5. Target IP & Routing prefix
        const dispIp = document.getElementById('disp-ip-' + id);
        if (dispIp) dispIp.textContent = t.target_ip || '-';
        const dispDial = document.getElementById('disp-dial-' + id);
        if (dispDial) dispDial.textContent = `(${t.prefix_cid || t.prefix || '-'})`;

        // 6. Start/Stop Action button
        const actionsDiv = document.getElementById('actions-' + id);
        if (actionsDiv) {
          actionsDiv.innerHTML = `
            <button type="button" class="btn ${t.running ? 'btn-red' : 'btn-sage'} btn-sm" id="btn-toggle-${id}" onclick="toggleTrunk('${id}', '${t.running ? 'stop' : 'start'}')" style="padding:6px 12px;font-size:11.5px;font-weight:700;min-width:78px;">
              ${t.running ? '⏹ Stop' : '▶ Start'}
            </button>
          `;
        }
      }

      // Master bar update (if elements exist)
      const totalActiveEl = document.getElementById('total-active');
      if (totalActiveEl) totalActiveEl.textContent = totalActive;
      const runningCountEl = document.getElementById('running-count');
      if (runningCountEl) runningCountEl.textContent = `${runningCount} / ${Object.keys(trunks).length}`;
      const switchStateEl = document.getElementById('switch-state');
      if (switchStateEl) switchStateEl.textContent = runningCount > 0 ? `${runningCount} Routes Online` : 'All Routes Standby';

      const modeBadge = document.getElementById('system-mode-badge');
      if (modeBadge) {
        if (runningCount === Object.keys(trunks).length && runningCount > 0) {
          modeBadge.className = 'stat-val-status active';
          modeBadge.innerHTML = '<span class="badge-dot"></span> All Trunks Live';
        } else if (runningCount > 0) {
          modeBadge.className = 'stat-val-status active';
          modeBadge.innerHTML = `<span class="badge-dot"></span> Partial (${runningCount}/${Object.keys(trunks).length})`;
        } else {
          modeBadge.className = 'stat-val-status';
          modeBadge.innerHTML = '<span class="badge-dot"></span> All Standby';
        }
      }
    }

    // ==========================================
    // INBOUND CALL GATEWAY & SOURCE IP MANAGEMENT
    // ==========================================
    let currentSourceIps = [];
    let editingSourceIpIndex = -1;

    function openAddSourceIpModal(index = -1) {
      editingSourceIpIndex = index;
      const overlay = document.getElementById('source-ip-modal-overlay');
      const title = document.getElementById('source-ip-modal-title');
      const ipIn = document.getElementById('sip-input-ip');
      const lblIn = document.getElementById('sip-input-label');
      const pfxIn = document.getElementById('sip-input-prefix');
      const ccIn = document.getElementById('sip-input-cc');
      const ctxIn = document.getElementById('sip-input-context');

      if (index >= 0 && currentSourceIps[index]) {
        const item = currentSourceIps[index];
        title.textContent = 'Edit Allowed Source IP';
        ipIn.value = item.ip || '';
        lblIn.value = item.label || '';
        pfxIn.value = item.prefix || '';
        ccIn.value = item.country_code || item.cc || '';
        ctxIn.value = item.context || 'auto';
      } else {
        title.textContent = 'Add Allowed Source IP';
        ipIn.value = '';
        lblIn.value = '';
        pfxIn.value = '';
        ccIn.value = '';
        ctxIn.value = 'auto';
      }

      overlay.classList.remove('hidden');
    }

    function closeSourceIpModal() {
      const overlay = document.getElementById('source-ip-modal-overlay');
      if (overlay) overlay.classList.add('hidden');
      editingSourceIpIndex = -1;
    }

    function insertSipPfx(val) {
      const el = document.getElementById('sip-input-prefix');
      if (!el) return;
      if (!el.value.trim() || val.includes(',')) {
        el.value = val;
      } else {
        const existing = el.value.split(',').map(s => s.trim()).filter(Boolean);
        if (!existing.includes(val)) existing.push(val);
        el.value = existing.join(', ');
      }
    }

    function handleSourceIpOverlayClick(e) {
      if (e.target.id === 'source-ip-modal-overlay') {
        closeSourceIpModal();
      }
    }

    async function saveSourceIpItem(e) {
      e.preventDefault();
      const ip = document.getElementById('sip-input-ip').value.trim();
      const label = document.getElementById('sip-input-label').value.trim();
      const prefix = document.getElementById('sip-input-prefix').value.trim();
      const cc = document.getElementById('sip-input-cc').value.trim();
      const context = document.getElementById('sip-input-context').value;

      if (!ip) {
        alert('Please enter a valid IP address or hostname');
        return;
      }

      const item = {
        id: editingSourceIpIndex >= 0 ? (currentSourceIps[editingSourceIpIndex].id || ('src-' + (editingSourceIpIndex + 1))) : ('src-' + Date.now()),
        ip: ip,
        label: label || ('Gateway ' + (currentSourceIps.length + 1)),
        prefix: prefix,
        country_code: cc,
        context: context
      };

      if (editingSourceIpIndex >= 0) {
        currentSourceIps[editingSourceIpIndex] = item;
      } else {
        const exists = currentSourceIps.some(s => s.ip === item.ip);
        if (exists) {
          alert('IP address ' + item.ip + ' is already registered in the allowed list.');
          return;
        }
        currentSourceIps.push(item);
      }

      closeSourceIpModal();
      renderSourceIpsTable();
      await persistInboundSettings(true);
    }

    async function deleteSourceIp(index) {
      if (index < 0 || index >= currentSourceIps.length) return;
      const item = currentSourceIps[index];
      if (currentSourceIps.length <= 1) {
        if (!confirm('Warning: This is the only allowed source IP (' + item.ip + '). If removed, no remote PBX will be allowed to send calls. Remove anyway?')) {
          return;
        }
      } else {
        if (!confirm('Remove ' + item.ip + ' (' + (item.label || 'Gateway') + ') from allowed inbound source IPs?')) {
          return;
        }
      }

      currentSourceIps.splice(index, 1);
      renderSourceIpsTable();
      await persistInboundSettings(true);
    }

    function renderSourceIpsTable() {
      const tbody = document.getElementById('source-ips-tbody');
      const countEl = document.getElementById('source-ips-count');
      if (countEl) {
        countEl.textContent = currentSourceIps.length + ' IP' + (currentSourceIps.length === 1 ? '' : 's') + ' authorized';
      }
      if (!tbody) return;

      if (currentSourceIps.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="padding:16px;text-align:center;color:var(--muted);font-size:12px;">No allowed source IPs configured. Click <b>+ Add Allowed Source IP</b> to authorize a gateway.</td></tr>';
        return;
      }

      tbody.innerHTML = currentSourceIps.map((s, idx) => {
        const pfxParts = [];
        if (s.prefix) pfxParts.push(s.prefix);
        if (s.country_code && !String(s.prefix || '').includes(s.country_code)) pfxParts.push('+' + s.country_code);
        const pfxDisplay = pfxParts.join(', ');
        return `
          <tr style="border-bottom:1px solid #EAECF0;">
            <td style="padding:9px 12px;font-family:monospace;font-weight:700;color:var(--brown);">
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--sage);margin-right:6px;"></span>
              ${s.ip}
            </td>
            <td style="padding:9px 12px;font-weight:600;color:#344054;">
              ${s.label || '<span style="color:var(--muted);font-style:italic;">None</span>'}
            </td>
            <td style="padding:9px 12px;font-family:monospace;font-size:11.5px;color:#475467;">
              ${pfxDisplay || '<span style="color:var(--muted);">All (Auto)</span>'}
            </td>
            <td style="padding:9px 12px;text-align:right;">
              <button type="button" class="btn btn-outline btn-sm" onclick="openAddSourceIpModal(${idx})" style="padding:4px 8px;font-size:11px;margin-right:4px;">
                ✏ Edit
              </button>
              <button type="button" class="btn btn-outline btn-sm" onclick="deleteSourceIp(${idx})" style="padding:4px 8px;font-size:11px;color:var(--red);border-color:#FECDCA;">
                🗑 Delete
              </button>
            </td>
          </tr>
        `;
      }).join('');
    }

    function renderCountryRecordsTable(records) {
      const tbody = document.getElementById('country-records-tbody');
      const tfoot = document.getElementById('country-records-tfoot');
      if (!tbody) return;

      if (!records) {
        tbody.innerHTML = '<tr><td colspan="6" style="padding:16px;text-align:center;color:var(--muted);font-size:12px;">No monthly records available for Cuba, Mali, and Senegal.</td></tr>';
        if (tfoot) tfoot.innerHTML = '';
        return;
      }

      // Pre-ordered strictly for Cuba, Mali, Senegal
      const targets = ['cuba', 'mali', 'senegal'];
      let totalCalls = 0;
      let totalAnswered = 0;
      let totalMinutes = 0;
      let totalActive = 0;

      const rowsHtml = targets.map(key => {
        const c = records[key] || {
          name: key.charAt(0).toUpperCase() + key.slice(1),
          code: key === 'cuba' ? '53' : (key === 'mali' ? '223' : '221'),
          prefix: key === 'cuba' ? '4444, 53' : (key === 'mali' ? '2237' : '619334, 221'),
          flag: key === 'cuba' ? 'CU' : (key === 'mali' ? 'ML' : 'SN'),
          context: key === 'mali' ? 'testcall2' : 'testcall',
          calls: 0,
          answered: 0,
          minutes: 0,
          active: 0
        };

        totalCalls += (c.calls || 0);
        totalAnswered += (c.answered || 0);
        totalMinutes += (c.minutes || 0);
        totalActive += (c.active || 0);

        return `
          <tr style="border-bottom:1px solid #EAECF0;">
            <td style="padding:10px 14px;font-weight:700;color:var(--brown);">
              <span class="flag-badge" style="font-size:10px;padding:2px 6px;margin-right:6px;">${c.flag || 'XX'}</span>
              ${c.name}
            </td>
            <td style="padding:10px 14px;font-family:monospace;font-size:12px;color:#475467;">
              <b>+${c.code}</b> <span style="color:var(--muted);font-size:11px;">(${c.prefix})</span>
            </td>
            <td style="padding:10px 14px;font-weight:600;color:#344054;">
              ${c.calls}
            </td>
            <td style="padding:10px 14px;font-weight:600;color:#344054;">
              ${c.answered}
            </td>
            <td style="padding:10px 14px;font-size:14px;font-weight:800;color:var(--sage-dark);">
              ${Number(c.minutes).toFixed(2)} <span style="font-size:11px;font-weight:600;color:var(--muted);">min</span>
            </td>
            <td style="padding:10px 14px;">
              ${c.active > 0 
                ? '<span style="background:#EBF5EA;color:#2D6A28;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">🟢 ' + c.active + ' Live</span>' 
                : '<span style="color:var(--muted);font-size:11px;">0 active</span>'}
            </td>
          </tr>
        `;
      }).join('');

      tbody.innerHTML = rowsHtml;

      if (tfoot) {
        tfoot.innerHTML = `
          <tr style="background:#F8F9FA;font-weight:800;border-top:2px solid #EAECF0;color:var(--brown);">
            <td style="padding:10px 14px;" colspan="2">Monthly Monitored Total</td>
            <td style="padding:10px 14px;">${totalCalls}</td>
            <td style="padding:10px 14px;">${totalAnswered}</td>
            <td style="padding:10px 14px;font-size:14px;color:var(--sage-dark);">${totalMinutes.toFixed(2)} <span style="font-size:11px;color:var(--muted);font-weight:600;">min</span></td>
            <td style="padding:10px 14px;">${totalActive > 0 ? '<span style="color:#2D6A28;">🟢 ' + totalActive + ' Live</span>' : '0 active'}</td>
          </tr>
        `;
      }
    }

    function formatSecondsHuman(sec) {
      sec = parseInt(sec, 10);
      if (isNaN(sec) || sec < 0) return '0s';
      if (sec < 60) return sec + 's';
      const m = Math.floor(sec / 60);
      const s = sec % 60;
      return s > 0 ? (m + 'm ' + s + 's') : (m + 'm');
    }

    function updateDurHuman(ctx) {
      const input = document.getElementById('input-' + ctx + '-dur');
      const el = document.getElementById(ctx + '-human-dur');
      if (input && el) {
        el.textContent = formatSecondsHuman(input.value);
      }
    }

    function setDurPreset(ctx, seconds) {
      const input = document.getElementById('input-' + ctx + '-dur');
      if (input) {
        input.value = seconds;
        updateDurHuman(ctx);
      }
    }

    async function saveBreakoutSettings() {
      const dur1 = parseInt(document.getElementById('input-testcall-dur')?.value || '60', 10);
      const dur2 = parseInt(document.getElementById('input-testcall2-dur')?.value || '50', 10);
      const pfx1 = (document.getElementById('inbound-testcall-pfx')?.value || '').trim();
      const pfx2 = (document.getElementById('inbound-testcall2-pfx')?.value || '').trim();

      if (isNaN(dur1) || dur1 < 1 || isNaN(dur2) || dur2 < 1) {
        alert('Please enter valid positive duration limits in seconds (e.g. 60)');
        return;
      }

      const btn = document.getElementById('btn-save-breakout') || document.getElementById('btn-save-durations');
      const originalText = btn ? btn.textContent : '💾 Save Breakout & Duration Settings';
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving...';
      }

      try {
        const payload = {
          testcall_duration: dur1,
          testcall2_duration: dur2,
          testcall_prefixes: pfx1,
          testcall2_prefixes: pfx2,
          source_ips: currentSourceIps
        };
        const res = await api('save-inbound', payload);
        addLog('[BREAKOUT] ' + res.message);
        if (btn) {
          btn.textContent = '✓ Saved!';
          setTimeout(() => {
            btn.textContent = originalText;
            btn.disabled = false;
          }, 2000);
        }
        await refreshAll(true);
      } catch (err) {
        console.error('Failed to save breakout settings:', err);
        addLog('[BREAKOUT] Error: ' + err.message);
        alert('Failed to save breakout settings: ' + err.message);
        if (btn) {
          btn.disabled = false;
          btn.textContent = originalText;
        }
      }
    }
    const saveContextDurations = saveBreakoutSettings;

    async function persistInboundSettings(notify = false) {
      const dur1 = parseInt(document.getElementById('input-testcall-dur')?.value || '60', 10);
      const dur2 = parseInt(document.getElementById('input-testcall2-dur')?.value || '50', 10);
      const pfx1 = (document.getElementById('inbound-testcall-pfx')?.value || '').trim();
      const pfx2 = (document.getElementById('inbound-testcall2-pfx')?.value || '').trim();
      const d = {
        source_ips: currentSourceIps,
        testcall_duration: dur1,
        testcall2_duration: dur2,
        testcall_prefixes: pfx1,
        testcall2_prefixes: pfx2
      };

      try {
        const res = await api('save-inbound', d);
        if (notify) {
          addLog('[INBOUND] ' + res.message);
        }
        await refreshAll(true);
        return res;
      } catch (err) {
        console.error('Failed to persist inbound settings:', err);
        if (notify) {
          addLog('[INBOUND] Error: ' + err.message);
          alert('Inbound Save Error: ' + err.message);
        }
        throw err;
      }
    }

    function renderInboundSection(inbound, forceSync = false) {
      if (!inbound) return;

      // 1. Active calls badge
      const activeCount = inbound.active_channels || (inbound.active_calls ? inbound.active_calls.length : 0);
      const activeBadge = document.getElementById('inbound-active-badge');
      if (activeBadge) {
        if (activeCount > 0) {
          activeBadge.style.background = '#EBF5EA';
          activeBadge.style.color = '#2D6A28';
          activeBadge.innerHTML = '🟢 <b>' + activeCount + '</b> Active Inbound Call' + (activeCount > 1 ? 's' : '');
        } else {
          activeBadge.style.background = '#F2F4F7';
          activeBadge.style.color = 'var(--muted)';
          activeBadge.innerHTML = '○ 0 Active Inbound Calls';
        }
      }

      // 2. Breakout Prefixes & Context Call Duration Limits ([testcall] and [testcall2])
      const pfx1Input = document.getElementById('inbound-testcall-pfx');
      const pfx2Input = document.getElementById('inbound-testcall2-pfx');
      if (pfx1Input && (document.activeElement !== pfx1Input || forceSync)) {
        if (inbound.testcall_prefixes !== undefined && inbound.testcall_prefixes !== null) {
          pfx1Input.value = inbound.testcall_prefixes;
        }
      }
      if (pfx2Input && (document.activeElement !== pfx2Input || forceSync)) {
        if (inbound.testcall2_prefixes !== undefined && inbound.testcall2_prefixes !== null) {
          pfx2Input.value = inbound.testcall2_prefixes;
        }
      }

      const dur1Input = document.getElementById('input-testcall-dur');
      const dur2Input = document.getElementById('input-testcall2-dur');
      if (dur1Input && (document.activeElement !== dur1Input || forceSync)) {
        if (inbound.testcall_duration !== undefined && inbound.testcall_duration !== null) {
          dur1Input.value = inbound.testcall_duration;
          updateDurHuman('testcall');
        }
      }
      if (dur2Input && (document.activeElement !== dur2Input || forceSync)) {
        if (inbound.testcall2_duration !== undefined && inbound.testcall2_duration !== null) {
          dur2Input.value = inbound.testcall2_duration;
          updateDurHuman('testcall2');
        }
      }

      // 3. Source IPs Table
      if (inbound.source_ips && (currentSourceIps.length === 0 || forceSync)) {
        currentSourceIps = inbound.source_ips;
        renderSourceIpsTable();
      } else if (currentSourceIps.length === 0) {
        currentSourceIps = [{
          id: 'src-1',
          ip: inbound.source_ip || '192.168.1.13',
          label: 'Default Gateway',
          prefix: '444453, 619334221, 2237',
          country_code: '223, 53, 221',
          context: 'auto'
        }];
        renderSourceIpsTable();
      }

      // 3. Monthly Inbound Consumed Minutes Table (Strictly Cuba, Mali, Senegal)
      renderCountryRecordsTable(inbound.country_records);
    }

        async function refreshAll(forceSync = false) {
      try {
        const res = await api('status');
        trunksData = res.trunks;

        if (!isInitialized) {
          const tableBody = document.getElementById('routes-table-tbody');
          if (tableBody) {
            tableBody.innerHTML = '';
            for (const id of ['cuba', 'mali', 'senegal']) {
              if (trunksData[id]) {
                tableBody.appendChild(buildRouteTableRow(id, trunksData[id]));
              }
            }
          }
          isInitialized = true;
          updateMetricsOnly(trunksData, true);
        } else {
          updateMetricsOnly(trunksData, forceSync);
        }

        if (res.inbound) {
          renderInboundSection(res.inbound, forceSync);
        }
      } catch (e) {
        addLog('Error fetching status: ' + e.message);
      }
    }

    async function toggleTrunk(id, action) {
      const btn = document.getElementById('btn-toggle-' + id);
      if (btn) {
        btn.disabled = true;
        btn.textContent = action === 'start' ? 'Starting...' : 'Stopping...';
      }
      try {
        const res = await api(action, { trunk: id });
        addLog(`[${id.toUpperCase()}] ${res.message}`);
        setTimeout(() => refreshAll(false), 500);
      } catch (e) {
        addLog(`[${id.toUpperCase()}] Error: ${e.message}`);
        alert(e.message);
        refreshAll(false);
      }
    }

    async function hangupTrunk(id) {
      const t = trunksData[id];
      const name = t ? (t.name || id) : id;
      const btn = document.getElementById('btn-hangup-' + id);
      const origHtml = btn ? btn.innerHTML : '🛑 Hangup';
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Hanging up...';
      }
      try {
        const res = await api('hangup', { trunk: id });
        addLog(`[${id.toUpperCase()}] Hangup: ${res.message || 'Calls terminated'}`);
        if (btn) {
          btn.innerHTML = '✓ Terminated';
          btn.style.color = 'var(--green)';
          btn.style.borderColor = 'var(--green)';
          setTimeout(() => {
            btn.innerHTML = origHtml;
            btn.style.color = '';
            btn.style.borderColor = '';
            btn.disabled = false;
          }, 1800);
        }
        setTimeout(() => refreshAll(false), 600);
      } catch (err) {
        console.error('Hangup error:', err);
        addLog(`[${id.toUpperCase()}] Hangup error: ${err.message}`);
        alert(`Failed to hangup calls on ${name}: ${err.message}`);
        if (btn) {
          btn.innerHTML = origHtml;
          btn.disabled = false;
        }
      }
    }

    async function hangupAll() {
      if (!confirm('Are you sure you want to hangup ALL active calls across all routes?')) return;
      try {
        const res = await api('hangup', { trunk: 'all' });
        addLog('[SYSTEM] All calls hangup requested: ' + (res.message || 'OK'));
        refreshAll(false);
      } catch (err) {
        alert('Hangup All failed: ' + err.message);
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
</html>