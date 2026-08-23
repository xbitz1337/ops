<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();

$von = $_GET['von'] ?? date('Y-m-01');
$bis = $_GET['bis'] ?? date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT b.erstellt_am, b.kanal, b.status, b.kaeufer_name, b.artikel_name, b.menge,
           b.verkaufspreis_brutto, b.sendungsnummer, b.versendet_am, b.ist_test
    FROM bestellungen b
    WHERE DATE(b.erstellt_am) BETWEEN :von AND :bis
    ORDER BY b.erstellt_am DESC
");
$stmt->execute([':von' => $von, ':bis' => $bis]);
$bestellungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

$kanal_labels = ['ebay' => 'eBay', 'amazon' => 'Amazon', 'tiktok' => 'TikTok Shop'];
$status_labels = ['neu' => 'Neu', 'zu_verpacken' => 'Zu verpacken', 'versand_vorbereitet' => 'Versand vorbereitet', 'versendet' => 'Versendet'];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="Bestellungen_' . $von . '_bis_' . $bis . '.csv"');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['Datum', 'Kanal', 'Status', 'Käufer', 'Artikel', 'Menge', 'Preis (€)', 'Sendungsnr.', 'Versendet am', 'Test'], ';');

foreach ($bestellungen as $b) {
    fputcsv($out, [
        date('d.m.Y H:i', strtotime($b['erstellt_am'])),
        $kanal_labels[$b['kanal']] ?? $b['kanal'],
        $status_labels[$b['status']] ?? $b['status'],
        $b['kaeufer_name'],
        $b['artikel_name'],
        $b['menge'],
        $b['verkaufspreis_brutto'] ? number_format($b['verkaufspreis_brutto'], 2, ',', '') : '',
        $b['sendungsnummer'] ?: '',
        $b['versendet_am'] ? date('d.m.Y', strtotime($b['versendet_am'])) : '',
        $b['ist_test'] ? 'Ja' : 'Nein',
    ], ';');
}

fclose($out);
