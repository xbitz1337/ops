<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Dokument löschen (DB-Eintrag + Datei auf der Platte)
 */

require_once __DIR__ . '/config.php';
requireLogin();
header('Content-Type: application/json');

$pdo = db();
$id = (int)($_POST['id'] ?? 0);

if (!$id) {
    echo json_encode(['error' => 'Keine ID angegeben']);
    exit;
}

$stmt = $pdo->prepare("SELECT dateipfad FROM dokumente WHERE id = :id");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    echo json_encode(['error' => 'Dokument nicht gefunden']);
    exit;
}

// Datei von der Platte entfernen, falls vorhanden
$pfad = __DIR__ . '/' . $doc['dateipfad'];
if (file_exists($pfad)) {
    unlink($pfad);
}

$pdo->prepare("DELETE FROM dokumente WHERE id = :id")->execute([':id' => $id]);

echo json_encode(['success' => true]);