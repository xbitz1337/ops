<?php
/**
 * NA Ops Hub — Lager-Modul v2
 * Kernfunktionen: Bewegung erfassen, Bestand zum Stichtag berechnen.
 * $pdo = bestehende PDO-Verbindung aus eurem Ops Hub (config.php einbinden).
 */

/**
 * Erfasst eine neue Lagerbewegung. Der Bestand wird NIE direkt verändert —
 * er ergibt sich immer aus der Summe aller Bewegungen (siehe View).
 */
function lager_bewegung_erfassen(PDO $pdo, array $daten): int
{
    $sql = "INSERT INTO lager_bewegungen
        (produkt_id, typ, menge, ek_preis_netto, verkaufskanal, verkaufspreis_brutto,
         bestellnummer, retoure_grund, wieder_verkaufsfaehig, bezug_bewegung_id,
         eigenbedarf_grund, korrektur_grund, notiz, erfasst_von, bewegt_am)
        VALUES
        (:produkt_id, :typ, :menge, :ek_preis_netto, :verkaufskanal, :verkaufspreis_brutto,
         :bestellnummer, :retoure_grund, :wieder_verkaufsfaehig, :bezug_bewegung_id,
         :eigenbedarf_grund, :korrektur_grund, :notiz, :erfasst_von, :bewegt_am)";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':produkt_id'            => $daten['produkt_id'],
        ':typ'                   => $daten['typ'],
        ':menge'                 => $daten['menge'],
        ':ek_preis_netto'        => $daten['ek_preis_netto'] ?? null,
        ':verkaufskanal'         => $daten['verkaufskanal'] ?? null,
        ':verkaufspreis_brutto'  => $daten['verkaufspreis_brutto'] ?? null,
        ':bestellnummer'         => $daten['bestellnummer'] ?? null,
        ':retoure_grund'         => $daten['retoure_grund'] ?? null,
        ':wieder_verkaufsfaehig' => $daten['wieder_verkaufsfaehig'] ?? null,
        ':bezug_bewegung_id'     => $daten['bezug_bewegung_id'] ?? null,
        ':eigenbedarf_grund'     => $daten['eigenbedarf_grund'] ?? null,
        ':korrektur_grund'       => $daten['korrektur_grund'] ?? null,
        ':notiz'                 => $daten['notiz'] ?? null,
        ':erfasst_von'           => $daten['erfasst_von'],   // 'qaf' oder 'qns'
        ':bewegt_am'             => $daten['bewegt_am'],     // 'YYYY-MM-DD'
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Bestand JE PRODUKT zu einem beliebigen Stichtag (inkl. Datum).
 * Das ist die Kernfunktion für den Monatsreport / die Inventur zum 31.12.
 *
 * WICHTIG: Der EK-Preis kommt jetzt aus der echten FIFO-Restwert-Berechnung
 * (siehe fifo_helper.php) — nur der Wert der Chargen, die zum Stichtag
 * laut "älteste zuerst" tatsächlich noch da waren. Ein simpler Durchschnitt
 * über die gesamte Kaufhistorie würde auch längst verkaufte Chargen
 * fälschlich mitzählen.
 */
function lager_bestand_zum_stichtag(PDO $pdo, string $stichtag): array
{
    require_once __DIR__ . '/fifo_helper.php';

    $sql = "
        SELECT p.id AS produkt_id, p.name, k.name AS kategorie, p.vorsteuersatz, p.ek_preis_netto AS produkt_ek_preis
        FROM lager_produkte p
        JOIN lager_kategorien k ON k.id = p.kategorie_id
        WHERE p.aktiv = 1
        ORDER BY k.sortierung, p.name
    ";
    $stmt = $pdo->query($sql);
    $produkte = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($produkte as &$p) {
        $fifo = fifo_lagerwert_berechnen($pdo, $p['produkt_id'], $stichtag, (float)($p['produkt_ek_preis'] ?? 0));
        $p['bestand_stueck'] = $fifo['bestand'];
        $p['ek_preis_netto'] = $fifo['ek_avg'];
    }
    unset($p);

    return $produkte;
}

/**
 * Verkäufe/Retouren/Eigenbedarf in einem Zeitraum, gruppiert je Kanal.
 * Für die "was ist diesen Monat passiert"-Sektion im Report.
 */
function lager_bewegungen_zeitraum(PDO $pdo, string $von, string $bis): array
{
    $sql = "
        SELECT b.typ, b.verkaufskanal, p.name AS produkt, p.kategorie_id,
               k.name AS kategorie, b.menge, b.verkaufspreis_brutto, b.bewegt_am
        FROM lager_bewegungen b
        JOIN lager_produkte p ON p.id = b.produkt_id
        JOIN lager_kategorien k ON k.id = p.kategorie_id
        WHERE b.bewegt_am BETWEEN :von AND :bis
          AND p.aktiv = 1
        ORDER BY b.bewegt_am, k.sortierung, p.name
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':von' => $von, ':bis' => $bis]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
