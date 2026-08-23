<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean');
require_once __DIR__ . '/../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT r.*, o.firma, o.ansprechpartner, o.adresse
    FROM clean_rechnungen r
    JOIN clean_objekte o ON o.id = r.objekt_id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { die('Rechnung nicht gefunden.'); }

// ── ROH-MODUS: erzeugt das PDF und liefert es fürs <embed> bzw. Download ──
if (($_GET['raw'] ?? '') === '1') {
    $html = '
    <html><head><style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1D1D1F; }
        .header { margin-bottom: 40px; }
        .firma-name { font-size: 20px; font-weight: bold; }
        .adresse-block { margin: 30px 0; }
        h1 { font-size: 18px; margin-bottom: 4px; }
        .meta { color: #666; font-size: 11px; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th { text-align: left; border-bottom: 2px solid #333; padding: 8px 4px; font-size: 11px; text-transform: uppercase; }
        td { padding: 10px 4px; border-bottom: 1px solid #eee; }
        .text-right { text-align: right; }
        .total-row td { border-bottom: none; border-top: 2px solid #333; font-weight: bold; padding-top: 12px; }
        .hinweis { margin-top: 40px; font-size: 10px; color: #666; }
    </style></head><body>

    <div class="header">
        <div class="firma-name">NA Clean Service</div>
        <div>Nour (Mhd Nour Shaaban)</div>
        <div>Am Bahndamm 7, 28719 Bremen</div>
    </div>

    <div class="adresse-block">
        ' . htmlspecialchars($r['firma']) . '<br>
        ' . ($r['ansprechpartner'] ? htmlspecialchars($r['ansprechpartner']) . '<br>' : '') . '
        ' . nl2br(htmlspecialchars($r['adresse'] ?? '')) . '
    </div>

    <h1>Rechnung ' . htmlspecialchars($r['rechnungsnummer']) . '</h1>
    <div class="meta">
        Rechnungsdatum: ' . date('d.m.Y', strtotime($r['erstellt_am'])) . '
        ' . ($r['zeitraum_von'] ? ' · Leistungszeitraum: ' . date('d.m.Y', strtotime($r['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($r['zeitraum_bis'])) : '') . '
    </div>

    <table>
        <thead><tr><th>Leistung</th><th class="text-right">Betrag</th></tr></thead>
        <tbody>
            <tr>
                <td>Gebäudereinigung' . ($r['zeitraum_von'] ? ' (' . date('d.m.Y', strtotime($r['zeitraum_von'])) . ' – ' . date('d.m.Y', strtotime($r['zeitraum_bis'])) . ')' : '') . '</td>
                <td class="text-right">' . number_format($r['betrag'], 2, ',', '.') . ' €</td>
            </tr>
            <tr class="total-row">
                <td>Gesamtbetrag</td>
                <td class="text-right">' . number_format($r['betrag'], 2, ',', '.') . ' €</td>
            </tr>
        </tbody>
    </table>

    <div class="hinweis">
        Gemäß § 19 UStG wird keine Umsatzsteuer berechnet (Kleinunternehmerregelung).<br>
        Bitte überweisen Sie den Betrag innerhalb von 14 Tagen unter Angabe der Rechnungsnummer.
    </div>

    </body></html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $als_download = ($_GET['download'] ?? '') === '1';
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($als_download ? 'attachment' : 'inline') . '; filename="' . $r['rechnungsnummer'] . '.pdf"');
    echo $dompdf->output();
    exit;
}

// ── ANSICHT: eigene Seite mit PDF + immer sichtbarem Speichern-Button ──────
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Rechnung <?= htmlspecialchars($r['rechnungsnummer']) ?> — NA Ops Hub</title>
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
  <a href="rechnungen.php" class="icon-btn" aria-label="Zurück">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
  </a>
  <div class="doc-titel-block">
    <div class="doc-titel">Rechnung <?= htmlspecialchars($r['rechnungsnummer']) ?></div>
    <div class="doc-untertitel"><?= htmlspecialchars($r['firma']) ?></div>
  </div>
  <a href="?id=<?= $id ?>&raw=1&download=1" class="icon-btn" aria-label="Speichern">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4M12 16l-4-4M12 16l4-4"></path><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"></path></svg>
  </a>
</div>

<div class="pdf-bereich">
  <embed src="?id=<?= $id ?>&raw=1" type="application/pdf">
</div>

</body>
</html>
