<?php
/**
 * NA Ops Hub — Admin-Hilfsfunktionen (Einstellungen, Aktivitätslog, Papierkorb)
 * Einbinden mit: require_once __DIR__ . '/admin_helper.php'; (Pfad je nach Ordnertiefe anpassen)
 */

function einstellung_holen(string $schluessel, $fallback = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $rows = db()->query("SELECT schluessel, wert FROM einstellungen")->fetchAll(PDO::FETCH_KEY_PAIR);
        $cache = $rows;
    }
    return $cache[$schluessel] ?? $fallback;
}

function einstellung_setzen(string $schluessel, string $wert): void
{
    db()->prepare("
        INSERT INTO einstellungen (schluessel, wert) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE wert = VALUES(wert)
    ")->execute([$schluessel, $wert]);
}

function aktivitaet_loggen(string $modul, string $aktion, string $beschreibung = ''): void
{
    $user = currentUser();
    $benutzer = $user['username'] ?? 'system';
    db()->prepare("
        INSERT INTO aktivitaetslog (benutzer, modul, aktion, beschreibung) VALUES (?, ?, ?, ?)
    ")->execute([$benutzer, $modul, $aktion, $beschreibung]);
}

/**
 * Datensatz in den Papierkorb verschieben, statt ihn direkt zu löschen.
 * $daten = kompletter Datensatz als assoziatives Array (z.B. fetch()-Ergebnis)
 */
function in_papierkorb_verschieben(string $modul, string $original_tabelle, int $original_id, array $daten, string $beschreibung): void
{
    $user = currentUser();
    db()->prepare("
        INSERT INTO papierkorb (modul, original_id, original_tabelle, daten_json, beschreibung, geloescht_von, endgueltig_am)
        VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
    ")->execute([$modul, $original_id, $original_tabelle, json_encode($daten), $beschreibung, $user['username'] ?? 'system']);
}
