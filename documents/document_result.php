<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/theme.css">
<style>
  .container { max-width:560px; margin:0 auto; padding:20px; text-align:center; }

  .check-circle {
    width:64px; height:64px; border-radius:50%; background:var(--green-soft);
    display:flex; align-items:center; justify-content:center; margin:0 auto 22px;
    font-size:30px; color:var(--green);
  }
  .subtitle { font-size:14px; color:var(--text-dim); margin-bottom:32px; }

  .doc-summary {
    background:var(--card); border:1px solid var(--line); border-radius:18px;
    padding:20px 22px; margin-bottom:28px; text-align:left;
  }
  .doc-summary .betreff { font-size:15px; font-weight:600; margin-bottom:8px; }
  .doc-summary .meta { font-size:12px; color:var(--text-dim); display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .doc-summary .badge.signiert { background:var(--green-soft); color:var(--green); }

  .action-row { display:flex; gap:12px; margin-bottom:20px; }
  .action-btn {
    flex:1; padding:14px; border-radius:12px; font-size:14.5px; font-weight:600;
    text-decoration:none; text-align:center; border:1px solid var(--line);
  }
  .action-btn.ansehen { background:var(--card2); color:var(--text); }
  .action-btn.ansehen:hover { border-color:var(--text-dim); }
  .action-btn.download { background:var(--accent); color:#fff; border-color:var(--accent); }
  .action-btn.download:hover { opacity:0.9; }

  .secondary-links { display:flex; justify-content:center; gap:20px; font-size:13px; }
  .secondary-links a { color:var(--accent); text-decoration:none; }
  .secondary-links a:hover { text-decoration:underline; }
</style>
</head>
<body>
<?php require_once __DIR__ . '/../_sidebar.php'; ?>
<div class="page-content-wrapper">

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
</div>

</body>
</html>