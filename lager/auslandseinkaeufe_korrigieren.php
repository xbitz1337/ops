<?php
date_default_timezone_set('Europe/Berlin');
require_once __DIR__ . '/../config.php';
requireLogin();
$pdo = db();

/**
 * NA Ops Hub — Einmalige Korrektur der Auslandseinkäufe auf die neue Logik
 *
 * Neue Regel: Solange die ausländische MwSt NICHT als "Erhalten" markiert ist,
 * gilt im Lager der volle BRUTTO-Betrag als Wareneinsatz (Geld ist ja
 * tatsächlich vorgestreckt). Erst bei Status "Erhalten" wird auf NETTO
 * umgestellt.
 *
 * Dieses Skript geht alle bestehenden Einträge durch und korrigiert die
 * verknüpfte Lager-Bewegung entsprechend — für Einträge, die VOR dieser
 * Logik-Umstellung schon gebucht wurden.
 *
 * NUR EINMAL AUSFÜHREN. Danach kann diese Datei gelöscht werden.
 */

if (!isset($_GET['bestaetigt'])) {
    $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM auslaendische_vorsteuer")->fetchColumn();
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;'>";
    echo "<h2>Auslandseinkäufe korrigieren</h2>";
    echo "<p>{$anzahl} Eintrag/Einträge werden auf die neue Brutto/Netto-Logik umgestellt.</p>";
    echo "<p><a href='?bestaetigt=1'>→ Jetzt korrigieren</a></p>";
    echo "</body></html>";
    exit;
}

$eintraege = $pdo->query("SELECT * FROM auslaendische_vorsteuer WHERE lager_bewegung_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
$korrigiert = 0;
$log = [];

foreach ($eintraege as $e) {
    $bewegung_stmt = $pdo->prepare("SELECT menge, ek_preis_netto FROM lager_bewegungen WHERE id = ?");
    $bewegung_stmt->execute([$e['lager_bewegung_id']]);
    $bewegung = $bewegung_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bewegung || $bewegung['menge'] <= 0) continue;

    if ($e['status'] === 'erhalten') {
        // Erstattung ist sicher → Netto muss stehen
        $ziel_preis = round((float)$e['nettobetrag'] / $bewegung['menge'], 4);
        $ziel_beschreibung = 'Netto (erstattet)';
    } else {
        // Noch nicht sicher erstattet → Brutto muss stehen
        $ziel_preis = round((float)$e['bruttobetrag'] / $bewegung['menge'], 4);
        $ziel_beschreibung = 'Brutto (noch nicht erstattet)';
    }

    if (abs((float)$bewegung['ek_preis_netto'] - $ziel_preis) > 0.001) {
        $pdo->prepare("UPDATE lager_bewegungen SET ek_preis_netto = ? WHERE id = ?")
            ->execute([$ziel_preis, $e['lager_bewegung_id']]);
        $korrigiert++;
        $log[] = "#{$e['id']} ({$e['land']}): war €" . number_format($bewegung['ek_preis_netto'], 2, ',', '.') . "/Stk → jetzt €" . number_format($ziel_preis, 2, ',', '.') . "/Stk [{$ziel_beschreibung}]";
    }
}

echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:40px;'>";
echo "<h2>✓ Fertig</h2>";
echo "<p>{$korrigiert} Bewegung(en) korrigiert.</p>";
if ($log) {
    echo "<ul>";
    foreach ($log as $zeile) { echo "<li>" . htmlspecialchars($zeile) . "</li>"; }
    echo "</ul>";
}
echo "<p><a href='auslaendische_vorsteuer.php'>→ Zur Übersicht</a></p>";
echo "</body></html>";