<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Lager-Modul v2 — Cron-Job
 *
 * Erzeugt automatisch am 1. eines Monats den Report für den VORHERIGEN Monat.
 * Zwei Reports werden erzeugt: einen für Wärmflasche (CozyCore-Branding) und
 * einen für den Rest (NA Commerce Branding) — so bekommt ihr direkt getrennte
 * PDFs, falls ihr die unterschiedlich weiterverwenden wollt. Zusätzlich einen
 * Gesamtreport über alles.
 *
 * Einrichtung bei Hostinger:
 * hPanel -> Erweitert -> Cron-Jobs -> neuer Job
 *   Zeitplan: 0 6 1 * *   (jeden 1. um 06:00 Uhr)
 *   Befehl:   php /home/USERNAME/domains/ops.nacommercesolutions.com/public_html/lager/cron_monatsreport.php
 * (Pfad an eure tatsächliche Serverstruktur anpassen)
 */

require_once __DIR__ . '/../config.php';
$pdo = db();
require_once __DIR__ . '/report_generate.php';

// Vorheriger Monat, ausgehend von "heute" (Serverzeit)
$von = date('Y-m-01', strtotime('first day of last month'));
$bis = date('Y-m-t', strtotime('first day of last month'));

try {
    $gesamt = lager_report_generieren($pdo, $von, $bis, null, 'automatisch');
    $waermflasche = lager_report_generieren($pdo, $von, $bis, 'Wärmflasche', 'automatisch');

    error_log("[Lager-Cron] Reports für {$von} bis {$bis} erfolgreich erzeugt: "
        . $gesamt['dateiname'] . ', ' . $waermflasche['dateiname']);

    // Optional: Push-Benachrichtigung übers bestehende Ops Hub To-Do-System
    // auslösen, damit ihr Bescheid bekommt, sobald der Report fertig ist.
    // -> hier eure bestehende Notification-Funktion aufrufen, falls vorhanden.

} catch (Throwable $e) {
    error_log('[Lager-Cron] FEHLER bei Report-Generierung: ' . $e->getMessage());
}
