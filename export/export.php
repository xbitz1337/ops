<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('export');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Export — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #14161F; --navy2: #1D2030; --blue-accent: #7C7CFF; --blue-bright: #9494FF;
    --text: #E4E6F0; --text-dim: #8B8FA8;
    --mono: 'Inter', -apple-system, sans-serif; --sans: 'Inter', -apple-system, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(20,22,31,0.97); border-bottom:1px solid rgba(124,124,255,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; }
  .topbar-title { font-size:14px; font-weight:700; color:#F5F6FA; }
  .main { max-width:520px; margin:0 auto; padding:28px 20px 60px; }
  .page-title { font-size:20px; font-weight:700; color:#F5F6FA; margin-bottom:24px; }
  .panel { background:rgba(29,32,48,0.8); border:1px solid rgba(124,124,255,0.2); padding:20px; margin-bottom:16px; }
  .panel-title { font-family:var(--mono); font-size:11px; color:var(--blue-bright); margin-bottom:14px; }
  .panel-desc { font-size:13px; color:var(--text-dim); margin-bottom:16px; line-height:1.5; }
  .field-label { font-family:var(--mono); font-size:9px; color:var(--text-dim); margin-bottom:6px; display:block; }
  .field-input { width:100%; background:rgba(20,22,31,0.8); border:1px solid rgba(124,124,255,0.25); color:var(--text); font-family:var(--mono); font-size:13px; padding:10px 12px; outline:none; margin-bottom:12px; }
  .row-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
  .btn-download { display:block; width:100%; text-align:center; background:none; border:1px solid var(--blue-bright); color:var(--blue-bright); padding:12px; font-family:var(--mono); font-size:12px; text-decoration:none; cursor:pointer; }
  .btn-download:hover { background:rgba(148,148,255,0.1); }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

<div class="main">
  <div class="page-title">Daten exportieren (CSV)</div>

  <div class="panel">
    <div class="panel-title">// LAGERBESTAND</div>
    <div class="panel-desc">Aktueller Bestand, Ø-EK und EK-Wert je Produkt — als Excel-lesbare CSV-Datei.</div>
    <a class="btn-download" href="lager_csv.php">↓ Lagerbestand herunterladen</a>
  </div>

  <div class="panel">
    <div class="panel-title">// BESTELLUNGEN</div>
    <div class="panel-desc">Alle Bestellungen im gewählten Zeitraum, über alle Kanäle.</div>
    <form action="bestellungen_csv.php" method="GET">
      <div class="row-2">
        <div>
          <label class="field-label">Von</label>
          <input class="field-input" type="date" name="von" value="<?= date('Y-m-01') ?>">
        </div>
        <div>
          <label class="field-label">Bis</label>
          <input class="field-input" type="date" name="bis" value="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <button type="submit" class="btn-download">↓ Bestellungen herunterladen</button>
    </form>
  </div>
</div>

</div>
</body>
</html>
