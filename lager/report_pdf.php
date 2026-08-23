<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — PDF-Erzeugung für einen Zeitraum
 * Aufruf: report_pdf.php?von=2026-01-01&bis=2026-06-30
 * Optional: &kategorie=Wärmflasche für einen Report nur für eine Kategorie
 * Abwärtskompatibel: ?stichtag=2026-01-31 (dann von = 1. desselben Monats)
 */

require_once __DIR__ . '/../config.php';
$pdo = db();
require_once __DIR__ . '/report_generate.php';

if (isset($_GET['von'], $_GET['bis'])) {
    $von = $_GET['von'];
    $bis = $_GET['bis'];
} elseif (isset($_GET['stichtag'])) {
    $bis = $_GET['stichtag'];
    $von = date('Y-m-01', strtotime($bis));
} else {
    // Fallback: laufender Monat bis heute
    $bis = date('Y-m-d');
    $von = date('Y-m-01');
}

$kategorie = $_GET['kategorie'] ?? null;

$ergebnis = lager_report_generieren($pdo, $von, $bis, $kategorie, 'manuell');

// Nicht direkt herunterladen — zur Ergebnis-Seite mit Ansehen/Download-Wahl
header('Location: report_result.php?id=' . $ergebnis['id']);
exit;
