<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('nachricht');
require_once __DIR__ . '/notify.php';

$erfolg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nachricht = trim($_POST['nachricht'] ?? '');
    if ($nachricht !== '') {
        $user = currentUser();
        $absender = $user['username'] === 'qaf' ? 'Artis' : ($user['username'] === 'qns' ? 'Nour' : $user['username']);
        $gesendet = telegram_senden("💬 <b>{$absender}:</b>\n" . $nachricht);
        $erfolg = $gesendet;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Nachricht senden — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-mid: #1e3a5f;
    --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71; --red: #e74c3c;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:480px; margin:0 auto; padding:28px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:6px; }
  .page-sub { font-family:var(--mono); font-size:10px; color:var(--text-dim); letter-spacing:1px; margin-bottom:24px; }

  .panel { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:20px; }
  .field-input {
    width:100%; background:rgba(15,25,35,0.8); border:1px solid rgba(45,106,173,0.25);
    color:var(--text); font-family:var(--sans); font-size:14px; padding:14px; outline:none;
    min-height:140px; resize:vertical; margin-bottom:16px;
  }
  .field-input:focus { border-color:var(--blue-bright); }
  .btn-send { width:100%; background:none; border:1px solid var(--blue-bright); color:var(--blue-bright); padding:14px; font-family:var(--mono); font-size:13px; letter-spacing:2px; text-transform:uppercase; cursor:pointer; }
  .btn-send:hover { background:rgba(74,158,221,0.1); }

  .status-box { padding:14px 16px; margin-bottom:16px; font-family:var(--mono); font-size:12px; }
  .status-box.ok { background:rgba(46,204,113,0.1); border:1px solid rgba(46,204,113,0.4); color:var(--green); }
  .status-box.fehler { background:rgba(231,76,60,0.1); border:1px solid rgba(231,76,60,0.4); color:var(--red); }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Telegram-Nachricht senden</div>
  <div class="page-sub">// GEHT AN ALLE, DIE MIT DEM BOT VERBUNDEN SIND</div>

  <?php if ($erfolg === true): ?>
    <div class="status-box ok">✓ Nachricht wurde gesendet.</div>
  <?php elseif ($erfolg === false): ?>
    <div class="status-box fehler">✕ Konnte nicht gesendet werden — Bot-Token/Chat-ID in telegram/config.php prüfen.</div>
  <?php endif; ?>

  <div class="panel">
    <form method="POST">
      <textarea class="field-input" name="nachricht" placeholder="Nachricht eingeben..." required></textarea>
      <button type="submit" class="btn-send">Senden</button>
    </form>
  </div>
</div>

</div>
</body>
</html>
