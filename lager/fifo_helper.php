<?php
/**
 * NA Ops Hub — FIFO-Kostenberechnung (First In, First Out)
 *
 * Berechnet die EXAKTEN Kosten für einen anstehenden Verkauf — nicht den
 * Durchschnitt über die gesamte Kaufhistorie, sondern die tatsächliche(n)
 * Charge(n), die laut Reihenfolge (älteste zuerst) gerade verbraucht würden.
 *
 * Funktioniert, indem die komplette Bewegungshistorie eines Produkts
 * chronologisch "nachgespielt" wird: Zugänge bauen eine Warteschlange auf,
 * bisherige Abgänge verbrauchen sie von vorne (älteste Charge zuerst) — der
 * aktuelle Zustand dieser Warteschlange zeigt genau, was noch da ist und zu
 * welchem Preis. Der neue Verkauf wird dann von vorne aus dieser Warteschlange
 * entnommen.
 *
 * WICHTIG: Diese Funktion nur EINMAL pro Verkauf aufrufen, BEVOR die neue
 * abgang_verkauf-Bewegung selbst gespeichert wird — sonst verfälscht sie
 * sich selbst mit in die Berechnung.
 *
 * @param float $fallback_preis Wird genutzt, wenn eine Charge keinen bekannten
 *                                Preis hat (z.B. eine positive Korrektur ganz am
 *                                Anfang, bevor je ein Einkauf gebucht wurde —
 *                                typisch beim ursprünglichen Lager-Setup). Sonst
 *                                würde diese Charge fälschlich mit 0€ bewertet.
 *                                Übergebt hier den EK-Preis des Produkts (p.ek_preis_netto).
 */
function fifo_stueckkosten_berechnen(PDO $pdo, int $produkt_id, int $menge_zu_verkaufen, float $fallback_preis = 0.0): float
{
    if ($menge_zu_verkaufen <= 0) return 0.0;

    $stmt = $pdo->prepare("
        SELECT typ, menge, ek_preis_netto, wieder_verkaufsfaehig
        FROM lager_bewegungen
        WHERE produkt_id = ?
        ORDER BY bewegt_am ASC, erstellt_am ASC, id ASC
    ");
    $stmt->execute([$produkt_id]);
    $bewegungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $queue = [];
    $verbrauchen = function (int $anzahl) use (&$queue) {
        while ($anzahl > 0 && !empty($queue)) {
            if ($queue[0]['menge'] <= $anzahl) {
                $anzahl -= $queue[0]['menge'];
                array_shift($queue);
            } else {
                $queue[0]['menge'] -= $anzahl;
                $anzahl = 0;
            }
        }
    };

    foreach ($bewegungen as $b) {
        if ($b['typ'] === 'zugang_einkauf') {
            $preis = ($b['ek_preis_netto'] !== null && $b['ek_preis_netto'] > 0) ? (float)$b['ek_preis_netto'] : $fallback_preis;
            $queue[] = ['menge' => (int)$b['menge'], 'preis' => $preis];
        } elseif ($b['typ'] === 'retoure' && $b['wieder_verkaufsfaehig']) {
            $letzter_preis = !empty($queue) ? end($queue)['preis'] : $fallback_preis;
            $queue[] = ['menge' => (int)$b['menge'], 'preis' => $letzter_preis];
        } elseif ($b['typ'] === 'korrektur') {
            $delta = (int)$b['menge'];
            if ($delta > 0) {
                $letzter_preis = !empty($queue) ? end($queue)['preis'] : $fallback_preis;
                $queue[] = ['menge' => $delta, 'preis' => $letzter_preis];
            } else {
                $verbrauchen(abs($delta));
            }
        } elseif (in_array($b['typ'], ['abgang_verkauf', 'abgang_eigenbedarf'])) {
            $verbrauchen((int)$b['menge']);
        }
    }

    $rest = $menge_zu_verkaufen;
    $gesamtkosten = 0.0;
    foreach ($queue as $eintrag) {
        if ($rest <= 0) break;
        $entnahme = min($rest, $eintrag['menge']);
        $gesamtkosten += $entnahme * $eintrag['preis'];
        $rest -= $entnahme;
    }

    if ($rest > 0) {
        $letzter_preis = !empty($queue) ? end($queue)['preis'] : $fallback_preis;
        $gesamtkosten += $rest * $letzter_preis;
    }

    return $menge_zu_verkaufen > 0 ? round($gesamtkosten / $menge_zu_verkaufen, 4) : 0.0;
}

/**
 * Berechnet den ECHTEN Wert des aktuell noch vorhandenen Bestands nach FIFO —
 * also NUR der Wert der Chargen, die laut "älteste zuerst" tatsächlich noch
 * übrig sind (nicht der Durchschnitt über die gesamte Kaufhistorie, die
 * würde auch längst verkaufte Chargen fälschlich mit reinrechnen).
 *
 * @param string|null $bis_datum Optional: Stichtag, für Reports zu einem
 *                                bestimmten Zeitpunkt in der Vergangenheit.
 * @param float $fallback_preis Siehe fifo_stueckkosten_berechnen() — Preis für
 *                                Chargen ohne bekannten Ursprungspreis.
 */
function fifo_lagerwert_berechnen(PDO $pdo, int $produkt_id, ?string $bis_datum = null, float $fallback_preis = 0.0): array
{
    $sql = "
        SELECT typ, menge, ek_preis_netto, wieder_verkaufsfaehig
        FROM lager_bewegungen
        WHERE produkt_id = ?" . ($bis_datum ? " AND bewegt_am <= ?" : "") . "
        ORDER BY bewegt_am ASC, erstellt_am ASC, id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($bis_datum ? [$produkt_id, $bis_datum] : [$produkt_id]);
    $bewegungen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $queue = [];
    $verbrauchen = function (int $anzahl) use (&$queue) {
        while ($anzahl > 0 && !empty($queue)) {
            if ($queue[0]['menge'] <= $anzahl) {
                $anzahl -= $queue[0]['menge'];
                array_shift($queue);
            } else {
                $queue[0]['menge'] -= $anzahl;
                $anzahl = 0;
            }
        }
    };

    foreach ($bewegungen as $b) {
        if ($b['typ'] === 'zugang_einkauf') {
            $preis = ($b['ek_preis_netto'] !== null && $b['ek_preis_netto'] > 0) ? (float)$b['ek_preis_netto'] : $fallback_preis;
            $queue[] = ['menge' => (int)$b['menge'], 'preis' => $preis];
        } elseif ($b['typ'] === 'retoure' && $b['wieder_verkaufsfaehig']) {
            $letzter_preis = !empty($queue) ? end($queue)['preis'] : $fallback_preis;
            $queue[] = ['menge' => (int)$b['menge'], 'preis' => $letzter_preis];
        } elseif ($b['typ'] === 'korrektur') {
            $delta = (int)$b['menge'];
            if ($delta > 0) {
                $letzter_preis = !empty($queue) ? end($queue)['preis'] : $fallback_preis;
                $queue[] = ['menge' => $delta, 'preis' => $letzter_preis];
            } else {
                $verbrauchen(abs($delta));
            }
        } elseif (in_array($b['typ'], ['abgang_verkauf', 'abgang_eigenbedarf'])) {
            $verbrauchen((int)$b['menge']);
        }
    }

    $bestand = 0;
    $lagerwert = 0.0;
    foreach ($queue as $eintrag) {
        $bestand += $eintrag['menge'];
        $lagerwert += $eintrag['menge'] * $eintrag['preis'];
    }

    return [
        'bestand' => $bestand,
        'lagerwert' => round($lagerwert, 2),
        'ek_avg' => $bestand > 0 ? round($lagerwert / $bestand, 4) : 0.0,
    ];
}
