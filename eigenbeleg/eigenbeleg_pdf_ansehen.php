<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
require_modul_zugriff('dokumente');
$pdo = db();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT dateiname, dateipfad FROM eigenbelege WHERE id = ?");
$stmt->execute([$id]);
$beleg = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$beleg) { http_response_code(404); die('Beleg nicht gefunden.'); }

$pfad = __DIR__ . '/' . $beleg['dateipfad'];
if (!file_exists($pfad)) { http_response_code(404); die('Datei nicht mehr vorhanden.'); }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $beleg['dateiname'] . '"');
header('Content-Length: ' . filesize($pfad));
readfile($pfad);
