<?php
/**
 * NA Ops Hub — Verpackungsmaterial-Hilfsfunktionen
 * Einbinden mit: require_once __DIR__ . '/../verpackung_helper.php';
 */

/**
 * Zieht laut Rezept des verkauften Produkts automatisch das passende
 * Verpackungsmaterial vom Bestand ab. Macht nichts, falls kein Rezept
 * hinterlegt ist (kein Fehler, einfach übersprungen).
 */
function verpackung_abziehen(PDO $pdo, int $produkt_id, int $verkaufte_menge, string $bestellnummer = '', string $erfasst_von = 'system'): void
{
    $rezept = $pdo->prepare("
        SELECT material_id, menge_je_verkauf FROM produkt_verpackung_rezept WHERE produkt_id = ?
    ");
    $rezept->execute([$produkt_id]);
    $positionen = $rezept->fetchAll(PDO::FETCH_ASSOC);

    foreach ($positionen as $pos) {
        $abzug = $pos['menge_je_verkauf'] * $verkaufte_menge;
        $pdo->prepare("
            INSERT INTO verpackung_bewegungen (material_id, typ, menge, bestellnummer, notiz, erfasst_von)
            VALUES (?, 'abgang_verkauf', ?, ?, 'Automatisch abgezogen', ?)
        ")->execute([$pos['material_id'], $abzug, $bestellnummer, $erfasst_von]);

        $pdo->prepare("UPDATE verpackungsmaterial SET bestand = bestand - ? WHERE id = ?")
            ->execute([$abzug, $pos['material_id']]);
    }
}
