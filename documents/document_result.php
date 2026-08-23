<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM dokumente WHERE id = :id");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    die('Dokument nicht gefunden.');
}

$verfasser_label = ['artis' => 'Artis', 'nour' => 'Nour', 'beide' => 'Artis & Nour'];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dokument erstellt — NA Ops Hub</title>
<style>
  :root {
    --ink: #1D1D1F; --ink-dim: #86868B; --ink-mid: #4B4B4D;
    --line: #E5E5E7; --bg: #F5F5F7; --accent: #0071E3; --green: #1a7f37;
    --font: -apple-system, 'Helvetica Neue', Helvetica, Arial, sans-serif;
  }
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:var(--font); background:var(--bg); color:var(--ink); min-height:100vh; }

  .topbar {
    height:56px; display:flex; align-items:center; justify-content:space-between;
    padding:0 28px; background:rgba(255,255,255,0.9); backdrop-filter:blur(12px);
    border-bottom:1px solid var(--line);
  }
  .back-link { font-size:13px; color:var(--accent); text-decoration:none; }
  .back-link:hover { text-decoration:underline; }
  .topbar-title { font-size:15px; font-weight:600; letter-spacing:-0.2px; }

  .container { max-width:560px; margin:60px auto; padding:0 24px; text-align:center; }

  .check-circle {
    width:64px; height:64px; border-radius:50%; background:#E8F5E9;
    display:flex; align-items:center; justify-content:center; margin:0 auto 22px;
    font-size:30px; color:var(--green);
  }
  h1 { font-size:22px; font-weight:700; letter-spacing:-0.3px; margin-bottom:8px; }
  .subtitle { font-size:14px; color:var(--ink-dim); margin-bottom:32px; }

  .doc-summary {
    background:#fff; border:1px solid var(--line); border-radius:12px;
    padding:20px 22px; margin-bottom:28px; text-align:left;
  }
  .doc-summary .betreff { font-size:15px; font-weight:600; margin-bottom:8px; }
  .doc-summary .meta { font-size:12px; color:var(--ink-dim); display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .badge { display:inline-block; padding:2px 8px; border-radius:9px; font-size:10px; font-weight:600; background:#F0F0F2; color:var(--ink-mid); }
  .badge.signiert { background:#E8F5E9; color:var(--green); }

  .action-row { display:flex; gap:12px; margin-bottom:20px; }
  .action-btn {
    flex:1; padding:15px; border-radius:10px; font-size:14.5px; font-weight:600;
    text-decoration:none; text-align:center; border:1px solid var(--line);
  }
  .action-btn.ansehen { background:#fff; color:var(--ink); }
  .action-btn.ansehen:hover { background:#F5F5F7; }
  .action-btn.download { background:var(--accent); color:#fff; border-color:var(--accent); }
  .action-btn.download:hover { background:#0077ED; }

  .secondary-links { display:flex; justify-content:center; gap:20px; font-size:13px; }
  .secondary-links a { color:var(--accent); text-decoration:none; }
  .secondary-links a:hover { text-decoration:underline; }
</style>
</head>
<body>

<div class="topbar">
  <a href="documents.php" class="back-link">← Neues Dokument</a>
  <div class="topbar-title">Dokument erstellt</div>
  <a href="dashboard.php" class="back-link">Dashboard →</a>
</div>

<div class="container">
  <div class="check-circle">✓</div>
  <h1>Dokument wurde erstellt</h1>
  <div class="subtitle">Was möchtest du jetzt tun?</div>

  <div class="doc-summary">
    <div class="betreff"><?= htmlspecialchars($doc['betreff'] ?: 'Formloser Brief') ?></div>
    <div class="meta">
      <span class="badge"><?= $verfasser_label[$doc['verfasser']] ?? $doc['verfasser'] ?></span>
      <?php if ($doc['hat_unterschrift']): ?><span class="badge signiert">✓ Signiert</span><?php endif; ?>
      <span><?= $doc['ort'] ? htmlspecialchars($doc['ort']) . ', ' : '' ?><?= $doc['dokument_datum'] ? date('d.m.Y', strtotime($doc['dokument_datum'])) : '' ?></span>
    </div>
  </div>

  <div class="action-row">
    <a class="action-btn ansehen" href="document_view.php?id=<?= $doc['id'] ?>" target="_blank">👁 Ansehen</a>
    <a class="action-btn download" href="document_download.php?id=<?= $doc['id'] ?>">↓ Download</a>
  </div>

  <div class="secondary-links">
    <a href="documents.php">Weiteres Dokument erstellen</a>
    <a href="documents_history.php">Zur Historie</a>
  </div>
</div>

</body>
</html>