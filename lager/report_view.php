<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — Report inline ansehen (ohne Download-Zwang)
 */

require_once __DIR__ . '/../config.php';
$pdo = db();

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT dateiname, dateipfad FROM lager_reports WHERE id = :id");
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

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $report['dateiname'] . '"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
