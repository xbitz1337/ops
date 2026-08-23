<?php
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('clean', 'bearbeiten');
header('Content-Type: application/json');

if (!isset($_FILES['foto'])) { echo json_encode(['error' => 'Kein Foto']); exit; }

$erlaubt = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($_FILES['foto']['type'], $erlaubt)) { echo json_encode(['error' => 'Ungültiges Format']); exit; }

$ordner = __DIR__ . '/einsatz_fotos';
if (!is_dir($ordner)) { mkdir($ordner, 0755, true); }

$dateiname = uniqid('einsatz_') . '_' . preg_replace('/[^a-zA-Z0-9.]/', '_', $_FILES['foto']['name']);
$zielpfad = $ordner . '/' . $dateiname;

if (move_uploaded_file($_FILES['foto']['tmp_name'], $zielpfad)) {
    echo json_encode(['success' => true, 'url' => 'einsatz_fotos/' . $dateiname]);
} else {
    echo json_encode(['error' => 'Upload fehlgeschlagen']);
}
