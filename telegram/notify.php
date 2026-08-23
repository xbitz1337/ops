<?php
/**
 * NA Ops Hub — Telegram-Benachrichtigungen — Sende-Funktion
 *
 * Überall im System nutzbar für beliebige Nachrichten:
 *   require_once __DIR__ . '/../telegram/notify.php';
 *   telegram_senden("Neue Bestellung eingegangen: ...");
 *
 * Schlägt sauber fehl (kein Fatal Error), falls Token/Chat-ID noch nicht
 * eingetragen sind — das System läuft dann einfach ohne Benachrichtigung weiter.
 */

require_once __DIR__ . '/config.php';

function telegram_senden(string $text): bool
{
    if (empty(TELEGRAM_BOT_TOKEN) || empty(TELEGRAM_CHAT_ID)) {
        return false; // Noch nicht eingerichtet — kein Fehler, einfach überspringen
    }

    $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
    $daten = [
        'chat_id' => TELEGRAM_CHAT_ID,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($daten));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $antwort = curl_exec($ch);
    $erfolgreich = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);

    return $erfolgreich;
}
