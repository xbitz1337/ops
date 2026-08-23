<?php
require_once 'config.php';

// Bereits eingeloggt → direkt zum Dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NA Ops Hub — Access</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy:        #0f1923;
    --navy2:       #152236;
    --blue-mid:    #1e3a5f;
    --blue-accent: #2d6aad;
    --blue-bright: #4a9edd;
    --text:        #c8dff0;
    --text-dim:    #5a7a9a;
    --green:       #2ecc71;
    --red:         #e74c3c;
    --mono:        'Share Tech Mono', monospace;
    --sans:        'Exo 2', sans-serif;
  }

  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    background: var(--navy);
    color: var(--text);
    font-family: var(--sans);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  body::before {
    content:'';
    position:fixed; inset:0;
    background-image:
      linear-gradient(rgba(45,106,173,0.07) 1px, transparent 1px),
      linear-gradient(90deg, rgba(45,106,173,0.07) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events:none;
  }

  body::after {
    content:'';
    position:fixed;
    top:50%; left:50%;
    transform:translate(-50%,-50%);
    width:700px; height:700px;
    background:radial-gradient(circle, rgba(45,106,173,0.12) 0%, transparent 70%);
    pointer-events:none;
  }

  .scanlines {
    position:fixed; inset:0;
    background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,0,0,0.03) 2px,rgba(0,0,0,0.03) 4px);
    pointer-events:none; z-index:10;
  }

  .corner { position:fixed; width:60px; height:60px; opacity:0.4; }
  .corner-tl { top:20px; left:20px; border-top:1px solid var(--blue-accent); border-left:1px solid var(--blue-accent); }
  .corner-tr { top:20px; right:20px; border-top:1px solid var(--blue-accent); border-right:1px solid var(--blue-accent); }
  .corner-bl { bottom:20px; left:20px; border-bottom:1px solid var(--blue-accent); border-left:1px solid var(--blue-accent); }
  .corner-br { bottom:20px; right:20px; border-bottom:1px solid var(--blue-accent); border-right:1px solid var(--blue-accent); }

  .status-bar {
    position:fixed; top:0; left:0; right:0; height:32px;
    display:flex; align-items:center; justify-content:space-between;
    padding:0 24px;
    font-family:var(--mono); font-size:10px; color:var(--text-dim);
    border-bottom:1px solid rgba(45,106,173,0.15);
    background:rgba(15,25,35,0.8);
    z-index:5;
  }

  .status-dot {
    display:inline-block; width:6px; height:6px;
    border-radius:50%; background:var(--green);
    margin-right:6px; animation:pulse 2s infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.3} }

  .container {
    position:relative; z-index:2;
    width:100%; max-width:420px;
    padding:20px;
    animation:fadeIn 0.6s ease;
  }
  @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }

  .logo-area { text-align:center; margin-bottom:36px; }

  .logo-icon {
    display:inline-flex; align-items:center; justify-content:center;
    width:42px; height:42px;
    border:1px solid var(--blue-accent);
    clip-path:polygon(10% 0%,90% 0%,100% 10%,100% 90%,90% 100%,10% 100%,0% 90%,0% 10%);
    background:var(--blue-mid);
    margin-bottom:12px;
  }
  .logo-icon span { font-family:var(--mono); font-size:13px; color:var(--blue-bright); font-weight:bold; }

  .logo-title { font-size:22px; font-weight:700; letter-spacing:3px; color:#e8f4ff; text-transform:uppercase; }
  .logo-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:4px; text-transform:uppercase; margin-top:4px; }

  .divider { display:flex; align-items:center; gap:10px; margin-bottom:28px; }
  .divider-line { flex:1; height:1px; background:linear-gradient(90deg,transparent,var(--blue-accent),transparent); }
  .divider-text { font-family:var(--mono); font-size:9px; color:var(--text-dim); letter-spacing:3px; }

  .card {
    background:rgba(21,34,54,0.85);
    border:1px solid rgba(45,106,173,0.3);
    padding:32px;
    position:relative;
    backdrop-filter:blur(10px);
  }
  .card::before {
    content:'';
    position:absolute; top:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,transparent,var(--blue-accent),var(--blue-bright),var(--blue-accent),transparent);
  }

  .field { margin-bottom:20px; }
  .field-label {
    font-family:var(--mono); font-size:10px;
    color:var(--text-dim); letter-spacing:3px;
    text-transform:uppercase; margin-bottom:8px;
    display:flex; align-items:center; gap:6px;
  }
  .field-label::before { content:'//'; color:var(--blue-accent); }

  .field-input {
    width:100%;
    background:rgba(15,25,35,0.8);
    border:1px solid rgba(45,106,173,0.25);
    color:var(--text); font-family:var(--mono);
    font-size:14px; padding:12px 14px;
    outline:none; transition:border-color 0.2s, box-shadow 0.2s;
    letter-spacing:1px;
  }
  .field-input:focus {
    border-color:var(--blue-bright);
    box-shadow:0 0 0 1px rgba(74,158,221,0.2), inset 0 0 20px rgba(74,158,221,0.03);
  }
  .field-input::placeholder { color:rgba(90,122,154,0.5); }

  .login-btn {
    width:100%; padding:14px;
    background:var(--blue-mid);
    border:1px solid var(--blue-accent);
    color:#e8f4ff; font-family:var(--sans);
    font-size:13px; font-weight:600;
    letter-spacing:4px; text-transform:uppercase;
    cursor:pointer; transition:all 0.2s;
    position:relative; overflow:hidden;
    margin-top:8px;
  }
  .login-btn::before {
    content:''; position:absolute;
    top:0; left:-100%; width:100%; height:100%;
    background:linear-gradient(90deg,transparent,rgba(74,158,221,0.1),transparent);
    transition:left 0.4s;
  }
  .login-btn:hover { background:rgba(45,106,173,0.5); border-color:var(--blue-bright); box-shadow:0 0 20px rgba(74,158,221,0.15); }
  .login-btn:hover::before { left:100%; }
  .login-btn:active { transform:scale(0.99); }

  .error-msg {
    font-family:var(--mono); font-size:10px;
    color:var(--red); letter-spacing:2px;
    margin-top:12px; text-align:center;
  }

  .bottom-info {
    margin-top:24px;
    display:flex; justify-content:space-between;
    font-family:var(--mono); font-size:9px;
    color:var(--text-dim); letter-spacing:1px;
  }

  .version {
    position:fixed; bottom:20px; left:24px;
    font-family:var(--mono); font-size:9px;
    color:var(--text-dim); letter-spacing:2px; z-index:5;
  }
</style>
</head>
<body>

<div class="scanlines"></div>
<div class="corner corner-tl"></div>
<div class="corner corner-tr"></div>
<div class="corner corner-bl"></div>
<div class="corner corner-br"></div>

<div class="status-bar">
  <span><span class="status-dot"></span>SYSTEM ONLINE</span>
  <span id="clock"></span>
  <span>OPS.NACOMMERCESOLUTIONS.COM</span>
</div>

<div class="container">
  <div class="logo-area">
    <div class="logo-icon"><span>NA</span></div>
    <div class="logo-title">NA Ops Hub</div>
    <div class="logo-sub">NA Commerce Solutions GmbH</div>
  </div>

  <div class="divider">
    <div class="divider-line"></div>
    <div class="divider-text">SECURE ACCESS</div>
    <div class="divider-line"></div>
  </div>

  <div class="card">
    <form method="POST" action="auth.php" id="login-form">
      <input type="hidden" name="action" value="login">

      <div class="field">
        <div class="field-label">Benutzername</div>
        <input class="field-input" type="text" name="username" id="username"
               placeholder="Benutzername eingeben"
               autocomplete="off" maxlength="10"
               required>
      </div>

      <div class="field">
        <div class="field-label">PIN</div>
        <input class="field-input" type="password" name="pin" id="pin"
               maxlength="9" inputmode="numeric"
               required>
      </div>

      <?php if($error): ?>
        <div class="error-msg">// <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <button type="submit" class="login-btn" id="login-btn">Zugang anfordern</button>

      <div id="error-msg" class="error-msg" style="display:none;"></div>
    </form>
  </div>

  <div class="bottom-info">
    <span>AUTHORIZED PERSONNEL ONLY</span>
    <span id="date-display"></span>
  </div>
</div>

<div class="version">v1.0.0 — BETA</div>

<script>
  // Clock
  function updateClock() {
    const now = new Date();
    document.getElementById('clock').textContent = now.toTimeString().slice(0,8);
    document.getElementById('date-display').textContent =
      now.toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit',year:'numeric'});
  }
  updateClock(); setInterval(updateClock, 1000);

  // AJAX Login (kein Page-Reload)
  document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    const err = document.getElementById('error-msg');
    btn.textContent = 'WIRD GEPRÜFT...';
    btn.disabled = true;

    const fd = new FormData(this);
    const res = await fetch('auth.php', { method:'POST', body:fd });
    const data = await res.json();

    if (data.error) {
      err.style.display = 'block';
      err.textContent = '// ' + data.error.toUpperCase();
      document.getElementById('pin').value = '';
      document.getElementById('pin').style.borderColor = '#e74c3c';
      setTimeout(() => document.getElementById('pin').style.borderColor = '', 1500);
      btn.textContent = 'Zugang anfordern';
      btn.disabled = false;
    } else {
      btn.textContent = 'WILLKOMMEN, ' + data.name.toUpperCase();
      btn.style.borderColor = '#2ecc71';
      btn.style.color = '#2ecc71';
      setTimeout(() => window.location.href = 'dashboard.php', 800);
    }
  });
</script>
</body>
</html>
