<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Dokument-Felder als JSON, für "Bearbeiten/Duplizieren" im Generator
 */

require_once __DIR__ . '/config.php';
requireLogin();
header('Content-Type: application/json');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT verfasser, empfaenger, betreff, brieftext, ort, dokument_datum, unterzeichner, unterzeichner_titel FROM dokumente WHERE id = :id");
$stmt->execute([':id' => $id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    echo json_encode(['error' => 'Nicht gefunden']);
    exit;
}

echo json_encode($doc);