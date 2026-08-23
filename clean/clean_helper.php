<?php
/**
 * NA Ops Hub — NA Clean Service — Smart-Kalkulation v2
 * Frequenz-Presets mit gestaffeltem Mengenrabatt + Vertragslaufzeit-Rabatt,
 * beide mit Mindestmarge geschützt.
 */

// Frequenz-Presets — jede Option hat Jahres-Einsätze + Mengenrabatt (%)
function clean_frequenz_presets(): array
{
    return [
        ['id' => '1x_woche', 'label' => '1x pro Woche', 'einsaetze_jahr' => 52, 'rabatt' => 0],
        ['id' => '2x_woche', 'label' => '2x pro Woche', 'einsaetze_jahr' => 104, 'rabatt' => 5],
        ['id' => '3x_woche', 'label' => '3x pro Woche', 'einsaetze_jahr' => 156, 'rabatt' => 8],
        ['id' => 'taeglich', 'label' => 'Täglich (Mo–Fr)', 'einsaetze_jahr' => 260, 'rabatt' => 12],
        ['id' => '2x_monat', 'label' => '2x pro Monat', 'einsaetze_jahr' => 24, 'rabatt' => 0],
        ['id' => '1x_monat', 'label' => '1x pro Monat', 'einsaetze_jahr' => 12, 'rabatt' => 0],
        ['id' => 'quartal', 'label' => 'Quartalsweise', 'einsaetze_jahr' => 4, 'rabatt' => 0],
        ['id' => '1x_jahr', 'label' => '1x pro Jahr', 'einsaetze_jahr' => 1, 'rabatt' => 0],
    ];
}

// Vertragslaufzeit-Optionen — Rabatt wirkt auf die MARGE, nicht auf den Wareneinsatz
function clean_laufzeit_optionen(): array
{
    return [
        ['monate' => 1, 'label' => 'Monatlich (kündbar)', 'rabatt' => 0],
        ['monate' => 3, 'label' => 'Quartal (3 Monate)', 'rabatt' => 3],
        ['monate' => 6, 'label' => 'Halbjährlich (6 Monate)', 'rabatt' => 6],
        ['monate' => 12, 'label' => 'Jährlich (12 Monate)', 'rabatt' => 10],
        ['monate' => 24, 'label' => 'Mehrjährig (2+ Jahre)', 'rabatt' => 15],
    ];
}

/**
 * Schlägt einen wettbewerbsfähigen Preis vor, falls der Kunde einen
 * aktuellen Preis genannt hat — aber NIE unter der Mindestmarge.
 */
function clean_wettbewerbspreis_vorschlag(float $aktueller_preis, float $kosten_gesamt, float $mindestmarge_prozent): array
{
    $vorschlag = round($aktueller_preis * 0.925, 2); // ca. 7,5% günstiger als bisher
    $mindestpreis = round($kosten_gesamt * (1 + $mindestmarge_prozent / 100), 2);

    if ($vorschlag < $mindestpreis) {
        return [
            'moeglich' => false,
            'vorschlag' => $mindestpreis,
            'hinweis' => 'Unterbietung des aktuellen Preises wäre unter eurer Mindestmarge — stattdessen Mindestpreis vorgeschlagen.',
        ];
    }

    return ['moeglich' => true, 'vorschlag' => $vorschlag, 'hinweis' => null];
}
