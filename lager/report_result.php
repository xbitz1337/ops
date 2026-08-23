<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM lager_reports WHERE id = :id");
$stmt->execute([':id' => $id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    die('Report nicht gefunden.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report erstellt — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --navy: #0f1923; --navy2: #152236; --blue-mid: #1e3a5f;
    --blue-accent: #2d6aad; --blue-bright: #4a9edd;
    --text: #c8dff0; --text-dim: #5a7a9a; --green: #2ecc71;
    --mono: 'Share Tech Mono', monospace; --sans: 'Exo 2', sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { background:var(--navy); color:var(--text); font-family:var(--sans); min-height:100vh; }
  .topbar { position:sticky; top:0; height:48px; display:flex; align-items:center; justify-content:space-between; padding:0 20px; background:rgba(15,25,35,0.97); border-bottom:1px solid rgba(45,106,173,0.2); }
  .back-link { font-family:var(--mono); font-size:11px; color:var(--blue-bright); text-decoration:none; letter-spacing:1px; }
  .back-link:hover { text-decoration:underline; }
  .topbar-title { font-size:14px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#e8f4ff; }

  .main { max-width:520px; margin:60px auto; padding:0 20px; text-align:center; }
  .check-circle {
    width:64px; height:64px; border-radius:50%; background:rgba(46,204,113,0.12); border:2px solid var(--green);
    display:flex; align-items:center; justify-content:center; margin:0 auto 22px; font-size:28px; color:var(--green);
  }
  h1 { font-size:20px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#e8f4ff; margin-bottom:8px; }
  .subtitle { font-family:var(--mono); font-size:11px; color:var(--text-dim); margin-bottom:28px; letter-spacing:1px; }

  .report-summary { background:rgba(21,34,54,0.8); border:1px solid rgba(45,106,173,0.2); padding:18px 20px; margin-bottom:24px; text-align:left; }
  .report-summary .zeile { display:flex; justify-content:space-between; padding:5px 0; font-family:var(--mono); font-size:11px; }
  .report-summary .label { color:var(--text-dim); }
  .report-summary .wert { color:var(--text); font-weight:600; }

  .action-row { display:flex; gap:12px; margin-bottom:20px; }
  .action-btn {
    flex:1; padding:14px; font-family:var(--mono); font-size:12px; letter-spacing:1px; text-transform:uppercase;
    text-decoration:none; text-align:center; border:1px solid;
  }
  .action-btn.ansehen { color:var(--text); border-color:rgba(90,122,154,0.3); }
  .action-btn.ansehen:hover { background:rgba(90,122,154,0.1); }
  .action-btn.download { color:var(--blue-bright); border-color:var(--blue-bright); }
  .action-btn.download:hover { background:rgba(74,158,221,0.1); }

  .secondary-links { display:flex; justify-content:center; gap:20px; font-family:var(--mono); font-size:11px; }
  .secondary-links a { color:var(--blue-bright); text-decoration:none; }
  .secondary-links a:hover { text-decoration:underline; }
</style>
</head>
<body>

<div class="topbar">
  <a href="report_new.php" class="back-link">← Neuer Report</a>
  <div class="topbar-title">Report erstellt</div>
  <a href="../dashboard.php#lager" class="back-link">Lager →</a>
</div>

<div class="main">
  <div class="check-circle">✓</div>
  <h1>Report wurde erstellt</h1>
  <div class="subtitle">// WAS MÖCHTEST DU JETZT TUN?</div>

  <div class="report-summary">
    <div class="zeile"><span class="label">Zeitraum</span><span class="wert"><?= date('d.m.Y', strtotime($report['zeitraum_von'])) ?> – <?= date('d.m.Y', strtotime($report['zeitraum_bis'])) ?></span></div>
    <div class="zeile"><span class="label">Kategorie</span><span class="wert"><?= $report['kategorie_filter'] ? htmlspecialchars($report['kategorie_filter']) : 'Alle' ?></span></div>
    <div class="zeile"><span class="label">EK-Wert Bestand</span><span class="wert" style="color:var(--green);">€<?= number_format($report['gesamt_ek_wert'], 2, ',', '.') ?></span></div>
  </div>

  <div class="action-row">
    <a class="action-btn ansehen" href="report_view.php?id=<?= $report['id'] ?>" target="_blank">👁 ANSEHEN</a>
    <a class="action-btn download" href="download_report.php?id=<?= $report['id'] ?>">↓ DOWNLOAD</a>
  </div>

  <div class="secondary-links">
    <a href="report_new.php">Weiteren Report erstellen</a>
    <a href="history.php">Zur Historie</a>
  </div>
</div>

</body>
</html>
