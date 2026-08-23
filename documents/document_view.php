<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Dokument ansehen, mit immer sichtbarem Download-Button
 * (wichtig fürs Handy: manche Browser/PWA-Modi zeigen bei reinen PDFs
 * keine eigene Speichern-Option in der Werkzeugleiste an)
 */
require_once __DIR__ . '/../config.php';
$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT dateiname, dateipfad FROM dokumente WHERE id = :id");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    die('Dokument nicht gefunden.');
}
$pfad = __DIR__ . '/' . $doc['dateipfad'];
if (!file_exists($pfad)) {
    http_response_code(404);
    die('Datei nicht mehr auf dem Server vorhanden.');
}

// ── ROH-MODUS: liefert die eigentlichen PDF-Bytes fürs <embed> ─────────────
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $doc['dateiname'] . '"');
    header('Content-Length: ' . filesize($pfad));
    readfile($pfad);
    exit;
}

// ── ANSICHT: eigene Seite mit PDF + immer sichtbarem Download-Button ───────
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($doc['dateiname']) ?> — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter', -apple-system, sans-serif; background:#14161F; height:100vh; display:flex; flex-direction:column; }
  .topbar {
    height:52px; flex-shrink:0; display:flex; align-items:center; justify-content:space-between;
    padding:0 16px; background:#1D2030; border-bottom:1px solid #2E3248;
  }
  .back-link { font-size:13px; color:#9494FF; text-decoration:none; white-space:nowrap; }
  .doc-titel { font-size:12.5px; font-weight:600; color:#E4E6F0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin:0 10px; flex:1; text-align:center; }
  .download-btn {
    background:#7C7CFF; color:#fff; text-decoration:none; font-size:12.5px; font-weight:600;
    padding:8px 14px; border-radius:10px; white-space:nowrap;
  }
  .download-btn:hover { opacity:0.9; }
  .pdf-bereich { flex:1; }
  .pdf-bereich embed { width:100%; height:100%; border:none; }
</style>
</head>
<body>

<div class="topbar">
  <a href="documents_history.php" class="back-link">← Zurück</a>
  <div class="doc-titel"><?= htmlspecialchars($doc['dateiname']) ?></div>
  <a href="document_download.php?id=<?= $id ?>" class="download-btn">⬇ Speichern</a>
</div>

<div class="pdf-bereich">
  <embed src="document_view.php?id=<?= $id ?>&raw=1" type="application/pdf">
</div>

</body>
</html>