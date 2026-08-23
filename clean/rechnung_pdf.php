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

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $r['rechnungsnummer'] . '.pdf"');
echo $dompdf->output();
