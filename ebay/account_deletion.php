<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — eBay Marketplace Account Deletion Notification Endpoint
 *
 * Pflicht-Endpunkt, den eBay von jeder Production-App verlangt (DSGVO-
 * konforme Benachrichtigung, falls ein eBay-Nutzer seinen Account löschen
 * lässt). WICHTIG: Dieser Endpunkt läuft OHNE Login-Schutz, weil eBays
 * Server ihn direkt anspricht — kein Mensch ruft ihn im Browser eingeloggt auf.
 *
 * eBay ruft zwei Arten von Requests auf:
 * 1. GET mit ?challenge_code=... — zum Verifizieren, dass der Endpunkt euch
 *    gehört. Muss mit SHA256(challengeCode + verificationToken + endpointURL)
 *    antworten.
 * 2. POST mit den eigentlichen Löschbenachrichtigungen — einfach mit 200 OK
 *    bestätigen (aktuell speichern wir keine externen eBay-Nutzerdaten
 *    dauerhaft, daher reicht die Bestätigung).
 *
 * NACH DEM HOCHLADEN im eBay Developer Portal eintragen:
 *   Endpoint-URL: https://ops.nacommercesolutions.com/ebay/account_deletion.php
 *   Verification Token: siehe VERIFICATION_TOKEN unten
 */

const VERIFICATION_TOKEN = '0a8a8f752ae1355ad94ec238228e6d4246947bea8bc4cf568d81ef23389fe66f';
const ENDPOINT_URL = 'https://ops.nacommercesolutions.com/ebay/account_deletion.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['challenge_code'])) {
    $challenge_code = $_GET['challenge_code'];
    $hash = hash('sha256', $challenge_code . VERIFICATION_TOKEN . ENDPOINT_URL);

    header('Content-Type: application/json');
    echo json_encode(['challengeResponse' => $hash]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Löschbenachrichtigung protokollieren (nicht öffentlich einsehbar,
    // liegt neben diesem Skript) — reine Nachvollziehbarkeit, keine Pflicht
    $body = file_get_contents('php://input');
    $log_datei = __DIR__ . '/deletion_log.txt';
    file_put_contents($log_datei, date('Y-m-d H:i:s') . ' — ' . $body . "\n", FILE_APPEND);

    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(200);