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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy:        #14161F;
    --navy2:       #1D2030;
    --blue-mid:    #262A3D;
    --blue-accent: #7C7CFF;
    --blue-bright: #9494FF;
    --text:        #E4E6F0;
    --text-dim:    #8B8FA8;
    --green:       #4ADE80;
    --red:         #F87171;
    --mono:        'Inter', -apple-system, sans-serif;
    --sans:        'Inter', -apple-system, sans-serif;
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

  body::after {
    content:'';
    position:fixed;
    top:50%; left:50%;
    transform:translate(-50%,-50%);
    width:700px; height:700px;
    background:radial-gradient(circle, rgba(124,124,255,0.10) 0%, transparent 70%);
    pointer-events:none;
  }

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
    width:44px; height:44px;
    border-radius:14px;
    background:rgba(124,124,255,0.15);
    margin-bottom:12px;
  }
  .logo-icon span { font-size:14px; color:var(--blue-bright); font-weight:700; }

  .logo-title { font-size:22px; font-weight:700; color:#F5F6FA; }
  .logo-sub { font-size:13px; color:var(--text-dim); margin-top:4px; }

  .divider { display:flex; align-items:center; gap:10px; margin-bottom:28px; }
  .divider-line { flex:1; height:1px; background:linear-gradient(90deg,transparent,var(--blue-accent),transparent); }
  .divider-text { font-size:12px; color:var(--text-dim); }

  .card {
    background:rgba(29,32,48,0.85);
    border:1px solid rgba(124,124,255,0.3);
    border-radius:20px;
    padding:32px;
    position:relative;
    backdrop-filter:blur(10px);
  }

  .field { margin-bottom:20px; }
  .field-label {
    font-family:var(--mono); font-size:10px;
    color:var(--text-dim); margin-bottom:8px;
    display:flex; align-items:center; gap:6px;
  }
  .field-input {
    width:100%;
    background:rgba(20,22,31,0.8);
    border:1px solid rgba(124,124,255,0.25);
    color:var(--text); font-family:var(--mono);
    font-size:14px; padding:12px 14px;
    outline:none; transition:border-color 0.2s, box-shadow 0.2s; border-radius:12px; }
  .field-input:focus {
    border-color:var(--blue-bright);
    box-shadow:0 0 0 1px rgba(148,148,255,0.2), inset 0 0 20px rgba(148,148,255,0.03);
  }
  .field-input::placeholder { color:rgba(139,143,168,0.5); }

  .login-btn {
    width:100%; padding:14px;
    background:var(--blue-mid);
    border:1px solid var(--blue-accent);
    border-radius:14px;
    color:#F5F6FA; font-family:var(--sans);
    font-size:14px; font-weight:600;
    cursor:pointer; transition:all 0.2s;
    position:relative; overflow:hidden;
    margin-top:8px;
  }
  .login-btn::before {
    content:''; position:absolute;
    top:0; left:-100%; width:100%; height:100%;
    background:linear-gradient(90deg,transparent,rgba(148,148,255,0.1),transparent);
    transition:left 0.4s;
  }
  .login-btn:hover { background:rgba(124,124,255,0.5); border-color:var(--blue-bright); box-shadow:0 0 20px rgba(148,148,255,0.15); }
  .login-btn:hover::before { left:100%; }
  .login-btn:active { transform:scale(0.99); }

  .error-msg {
    font-size:13px;
    color:var(--red); margin-top:12px; text-align:center;
  }

  .bottom-info {
    margin-top:24px;
    display:flex; justify-content:space-between;
    font-size:11px;
    color:var(--text-dim); }

  .version {
    position:fixed; bottom:20px; left:24px;
    font-size:11px;
    color:var(--text-dim); z-index:5;
  }
</style>
</head>
<body>

<div class="container">
  <div class="logo-area">
    <div class="logo-icon"><span>NA</span></div>
    <div class="logo-title">NA Ops Hub</div>
    <div class="logo-sub">NA Commerce Solutions GmbH</div>
  </div>

  <div class="divider">
    <div class="divider-line"></div>
    <div class="divider-text">Sicherer Zugang</div>
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
        <div class="error-msg"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <button type="submit" class="login-btn" id="login-btn">Zugang anfordern</button>

      <div id="error-msg" class="error-msg" style="display:none;"></div>
    </form>
  </div>

  <div class="bottom-info">
    <span>Nur für autorisierte Mitarbeiter</span>
    <span id="date-display"></span>
  </div>
</div>

<div class="version">v1.0.0</div>

<script>
  // Datum unten rechts
  function updateClock() {
    const now = new Date();
    document.getElementById('date-display').textContent =
      now.toLocaleDateString('de-DE',{day:'2-digit',month:'2-digit',year:'numeric'});
  }
  updateClock(); setInterval(updateClock, 60000);

  // AJAX Login (kein Page-Reload)
  document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('login-btn');
    const err = document.getElementById('error-msg');
    btn.textContent = 'Wird geprüft…';
    btn.disabled = true;

    const fd = new FormData(this);
    const res = await fetch('auth.php', { method:'POST', body:fd });
    const data = await res.json();

    if (data.error) {
      err.style.display = 'block';
      err.textContent = data.error;
      document.getElementById('pin').value = '';
      document.getElementById('pin').style.borderColor = '#F87171';
      setTimeout(() => document.getElementById('pin').style.borderColor = '', 1500);
      btn.textContent = 'Zugang anfordern';
      btn.disabled = false;
    } else {
      btn.textContent = 'Willkommen, ' + data.name;
      btn.style.borderColor = '#4ADE80';
      btn.style.color = '#4ADE80';
      setTimeout(() => window.location.href = 'dashboard.php', 800);
    }
  });
</script>
</body>
</html>
