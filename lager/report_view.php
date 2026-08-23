<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — Report ansehen, mit immer sichtbarem
 * Speichern-Button (analog zu documents/document_view.php)
 */

require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT dateiname, dateipfad, zeitraum_von, zeitraum_bis, kategorie_filter FROM lager_reports WHERE id = :id");
$stmt->execute([':id' => $id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    die('Report nicht gefunden.');
}

$pfad = __DIR__ . '/' . $report['dateipfad'];
if (!file_exists($pfad)) {
    http_response_code(404);
    die('Datei nicht mehr auf dem Server vorhanden.');
}

// ── ROH-MODUS: liefert die eigentlichen PDF-Bytes fürs <embed> ─────────────
if (($_GET['raw'] ?? '') === '1') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $report['dateiname'] . '"');
    header('Content-Length: ' . filesize($pfad));
    readfile($pfad);
    exit;
}

// ── ANSICHT: eigene Seite mit PDF + immer sichtbarem Speichern-Button ──────
$untertitel = $report['zeitraum_von']
    ? date('d.m.Y', strtotime($report['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($report['zeitraum_bis']))
    : ($report['kategorie_filter'] ?: 'Lagerreport');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($report['dateiname']) ?> — NA Ops Hub</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { margin:0; padding:0; box-sizing:border-box; }
  body { font-family:'Inter', -apple-system, sans-serif; background:#14161F; height:100vh; display:flex; flex-direction:column; }
  .topbar {
    height:64px; flex-shrink:0; display:flex; align-items:center; justify-content:space-between;
    padding:0 16px; background:#1D2030; border-bottom:1px solid #2E3248;
  }
  .icon-btn {
    width:36px; height:36px; flex-shrink:0; display:flex; align-items:center; justify-content:center;
    color:#9494FF; text-decoration:none; border-radius:10px; background:none; border:none; cursor:pointer;
  }
  .icon-btn:active { background:#262A3D; }
  .icon-btn svg { width:22px; height:22px; }
  .doc-titel-block { flex:1; min-width:0; text-align:center; padding:0 6px; }
  .doc-titel {
    font-size:14.5px; font-weight:700; color:#F5F6FA;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
  }
  .doc-untertitel { font-size:11.5px; color:#8B8FA8; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .pdf-bereich { flex:1; }
  .pdf-bereich embed { width:100%; height:100%; border:none; }
</style>
</head>
<body>

<div class="topbar">
  <a href="history.php" class="icon-btn" aria-label="Zurück">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
  </a>
  <div class="doc-titel-block">
    <div class="doc-titel">Lagerreport</div>
    <div class="doc-untertitel"><?= htmlspecialchars($untertitel) ?></div>
  </div>
  <a href="download_report.php?id=<?= $id ?>" class="icon-btn" aria-label="Speichern">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M12 16l-4-4M12 16l4-4"></path><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path></svg>
  </a>
</div>

<div class="pdf-bereich">
  <embed src="report_view.php?id=<?= $id ?>&raw=1" type="application/pdf">
</div>

</body>
</html>
