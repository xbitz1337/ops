<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — Report löschen (DB-Eintrag + Datei)
 * Automatisch erzeugte Reports sind geschützt und können nicht gelöscht werden —
 * sie bilden die verlässliche Jahresbasis und sollten nicht versehentlich verschwinden.
 */

require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

$pdo = db();
$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['error' => 'Keine ID angegeben']);
    exit;
}

$stmt = $pdo->prepare("SELECT dateipfad, erzeugt_durch FROM lager_reports WHERE id = :id");
$stmt->execute([':id' => $id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    echo json_encode(['error' => 'Report nicht gefunden']);
    exit;
}

if ($report['erzeugt_durch'] === 'automatisch') {
    echo json_encode(['error' => 'Automatisch erzeugte Reports sind geschützt und können nicht gelöscht werden']);
    exit;
}

$pfad = __DIR__ . '/' . $report['dateipfad'];
if (file_exists($pfad)) {
    unlink($pfad);
}

$pdo->prepare("DELETE FROM lager_reports WHERE id = :id")->execute([':id' => $id]);

echo json_encode(['success' => true]);
