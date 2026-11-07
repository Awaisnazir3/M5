const fs = require('fs');

// 1. Update server.js saveTrunk error handling
let serverCode = fs.readFileSync('server.js', 'utf8');

const oldSaveCatch = `      } catch (err) {
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
      }`;

const newSaveCatch = `      } catch (err) {
        console.error('Save error:', err);
        addLog(\`[\${id.toUpperCase()}] Save error: \${err.message}\`);
        if (saveBtn) {
          saveBtn.innerHTML = '❌ ' + (err.message || 'Save Failed');
          saveBtn.style.background = 'var(--red)';
          saveBtn.style.color = '#FFFFFF';
          setTimeout(() => {
            saveBtn.innerHTML = '💾 Save Settings';
            saveBtn.style.background = '';
            saveBtn.style.color = '';
            saveBtn.disabled = false;
          }, 4000);
        }
        alert('Save Error: ' + err.message);
      }`;

if (serverCode.includes(oldSaveCatch)) {
  serverCode = serverCode.replace(oldSaveCatch, newSaveCatch);
  console.log('1. Updated saveTrunk error handling in server.js');
} else {
  console.warn('oldSaveCatch not found in server.js');
}

fs.writeFileSync('server.js', serverCode, 'utf8');

// 2. Sync to all other files
const startIdx = serverCode.indexOf('const dashboardHtml = `');
const endIdx = serverCode.indexOf('`;\n\nconst server = http.createServer');
const templateContent = serverCode.substring(startIdx + 'const dashboardHtml = `'.length, endIdx);
const evaledHtml = new Function('return `' + templateContent + '`')();

// Validate syntax
const scriptMatch = evaledHtml.match(/<script>([\s\S]*?)<\/script>/);
new Function(scriptMatch[1]);
console.log('2. Client script validated');

fs.writeFileSync('index.html', evaledHtml, 'utf8');
fs.writeFileSync('callgen-dashboard/callgen-dashboard/public/index.html', evaledHtml, 'utf8');

// Update index.php
const phpOrig = fs.readFileSync('callgen-dashboard/callgen-dashboard/public/index.php', 'utf8');
let phpHeader = `<?php
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
`;

// Extract login form from phpOrig
const loginFormStart = '<!doctype html>\n<html lang="en">\n<head>\n  <meta charset="utf-8">\n  <meta name="viewport" content="width=device-width, initial-scale=1">\n  <title>M5-Traffic Controller | Sign In</title>';
const loginFormEnd = '<?php exit; endif; ?>\n';
const lStart = phpOrig.indexOf(loginFormStart);
const lEnd = phpOrig.indexOf(loginFormEnd);

let fullLoginSection = phpOrig.substring(lStart, lEnd + loginFormEnd.length);
let phpDashboard = evaledHtml.replace(
  '<span>Operator: <b>awais</b></span>',
  '<span>Operator: <b><?= htmlspecialchars($_SESSION["operator"] ?? "awais") ?></b></span>'
);

fs.writeFileSync('callgen-dashboard/callgen-dashboard/public/index.php', phpHeader + fullLoginSection + phpDashboard, 'utf8');
fs.copyFileSync('server.js', 'callgen-dashboard/callgen-dashboard/server.js');

console.log('3. All local files updated successfully!');
