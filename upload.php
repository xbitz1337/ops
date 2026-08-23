<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['foto'])) {
    echo json_encode(['error' => 'Keine Datei erhalten.']);
    exit;
}

$file     = $_FILES['foto'];
$maxSize  = 15 * 1024 * 1024; // 5MB
$allowed  = ['image/jpeg', 'image/png', 'image/webp'];
$uploadDir = __DIR__ . '/uploads/';

// Ordner erstellen falls nicht vorhanden
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Validierung
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'Upload fehlgeschlagen.']);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['error' => 'Datei zu groß (max 5MB).']);
    exit;
}

$mime = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowed)) {
    echo json_encode(['error' => 'Nur JPG, PNG oder WEBP erlaubt.']);
    exit;
}

// Eindeutiger Dateiname
$ext      = match($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    default      => 'jpg'
};
$filename = 'produkt_' . uniqid() . '.' . $ext;
$filepath = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['error' => 'Datei konnte nicht gespeichert werden.']);
    exit;
}

$url = APP_URL . '/uploads/' . $filename;
echo json_encode(['success' => true, 'url' => $url]);
exit;
?>
