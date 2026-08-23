<?php
/**
 * NA Ops Hub — Download eines archivierten Dokuments aus der Historie
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

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $doc['dateiname'] . '"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);