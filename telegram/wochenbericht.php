<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Wochenbericht Umsatz & Gewinn
 *
 * Fasst die letzte abgeschlossene Kalenderwoche (Montag–Sonntag) zusammen:
 * Umsatz, Wareneinsatz, Rohertrag, Reingewinn (nach Steuerrücklage), je
 * Verkaufskanal aufgeschlüsselt, Top-3-Produkte, plus Vergleich zur Vorwoche.
 * Nutzt dieselbe Berechnungslogik wie umsatz/dashboard.php.
 *
 * Cron-Job (hPanel) — einmal wöchentlich, z.B. montags um 7 Uhr:
 *
 *   0 7 * * 1
 *   /usr/bin/php /home/USERNAME/domains/.../public_html/ops/telegram/wochenbericht.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/notify.php';

$pdo = db();

$STEUERSATZ_UMSATZ = 0.30;
$kanal_labels = ['amazon' => 'Amazon', 'ebay' => 'eBay', 'tiktok' => 'TikTok Shop', 'direktverkauf' => 'Direktverkauf', 'sonstiges' => 'Sonstiges'];

/**
 * Liefert Umsatz/Wareneinsatz für einen Zeitraum — gesamt, je Kanal und je
 * Produkt (identische Berechnung wie im Umsatz-Dashboard: FIFO-Stückkosten
 * aus den Verkaufsbewegungen, kein nachträglicher Durchschnitt).
 */
function wochendaten(PDO $pdo, string $von, string $bis): array
{
    $gesamt = $pdo->prepare("
        SELECT SUM(menge * COALESCE(verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto,
               SUM(menge * COALESCE(ek_preis_netto, 0)) AS wareneinsatz,
               SUM(menge) AS stueck
        FROM lager_bewegungen
        WHERE typ = 'abgang_verkauf' AND bewegt_am BETWEEN :von AND :bis
    ");
    $gesamt->execute([':von' => $von, ':bis' => $bis]);
    $g = $gesamt->fetch(PDO::FETCH_ASSOC);

    $je_kanal = $pdo->prepare("
        SELECT verkaufskanal, SUM(menge * COALESCE(verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto
        FROM lager_bewegungen
        WHERE typ = 'abgang_verkauf' AND bewegt_am BETWEEN :von AND :bis
        GROUP BY verkaufskanal ORDER BY umsatz_netto DESC
    ");
    $je_kanal->execute([':von' => $von, ':bis' => $bis]);

    $top_produkte = $pdo->prepare("
        SELECT p.name, SUM(b.menge) AS stueck,
               SUM(b.menge * COALESCE(b.verkaufspreis_brutto, 0)) / 1.19 AS umsatz_netto
        FROM lager_bewegungen b
        JOIN lager_produkte p ON p.id = b.produkt_id
        WHERE b.typ = 'abgang_verkauf' AND b.bewegt_am BETWEEN :von AND :bis
        GROUP BY p.id, p.name ORDER BY umsatz_netto DESC LIMIT 3
    ");
    $top_produkte->execute([':von' => $von, ':bis' => $bis]);

    $umsatz = (float)($g['umsatz_netto'] ?? 0);
    $wareneinsatz = (float)($g['wareneinsatz'] ?? 0);
    $rohertrag = $umsatz - $wareneinsatz;
    $ruecklage = max(0, $rohertrag * $STEUERSATZ_UMSATZ);

    return [
        'umsatz' => $umsatz,
        'wareneinsatz' => $wareneinsatz,
        'rohertrag' => $rohertrag,
        'ruecklage' => $ruecklage,
        'reingewinn' => $rohertrag - $ruecklage,
        'stueck' => (int)($g['stueck'] ?? 0),
        'je_kanal' => $je_kanal->fetchAll(PDO::FETCH_ASSOC),
        'top_produkte' => $top_produkte->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/** Formatiert einen Trend als Pfeil + Prozent gegenüber der Vorwoche. */
function trend(float $aktuell, float $vorwoche): string
{
    if ($vorwoche <= 0) return $aktuell > 0 ? '🆕' : '';
    $diff = round((($aktuell - $vorwoche) / $vorwoche) * 100);
    if ($diff === 0) return '(± 0%)';
    return $diff > 0 ? "(↑ {$diff}%)" : "(↓ " . abs($diff) . "%)";
}

// ── ZEITRAUM: letzte abgeschlossene Kalenderwoche (Mo–So) ────────────────────
$heute = new DateTime();
$letzter_montag = (clone $heute)->modify('monday this week')->modify('-7 days');
$von = $letzter_montag->format('Y-m-d');
$bis = (clone $letzter_montag)->modify('+6 days')->format('Y-m-d');

$vorwoche_von = (clone $letzter_montag)->modify('-7 days')->format('Y-m-d');
$vorwoche_bis = (clone $letzter_montag)->modify('-1 days')->format('Y-m-d');

$daten = wochendaten($pdo, $von, $bis);
$vorwoche = wochendaten($pdo, $vorwoche_von, $vorwoche_bis);

// ── NACHRICHT BAUEN ───────────────────────────────────────────────────────────
$nachricht = "📊 <b>Wochenbericht " . $letzter_montag->format('d.m.') . "–" . (clone $letzter_montag)->modify('+6 days')->format('d.m.Y') . "</b>\n";

$nachricht .= "\n💶 <b>Umsatz &amp; Gewinn</b>\n";
$nachricht .= "Umsatz (netto): €" . number_format($daten['umsatz'], 2, ',', '.') . " " . trend($daten['umsatz'], $vorwoche['umsatz']) . "\n";
$nachricht .= "Wareneinsatz: €" . number_format($daten['wareneinsatz'], 2, ',', '.') . "\n";
$nachricht .= "Rohertrag: €" . number_format($daten['rohertrag'], 2, ',', '.') . "\n";
$nachricht .= "Steuerrücklage (30%): €" . number_format($daten['ruecklage'], 2, ',', '.') . "\n";
$nachricht .= "<b>Reingewinn: €" . number_format($daten['reingewinn'], 2, ',', '.') . "</b> " . trend($daten['reingewinn'], $vorwoche['reingewinn']) . "\n";
$nachricht .= "Verkaufte Stück: {$daten['stueck']}\n";

if (!empty($daten['je_kanal'])) {
    $nachricht .= "\n🛒 <b>Je Kanal</b>\n";
    foreach ($daten['je_kanal'] as $k) {
        $label = $kanal_labels[$k['verkaufskanal']] ?? ucfirst((string)$k['verkaufskanal']);
        $nachricht .= "{$label}: €" . number_format((float)$k['umsatz_netto'], 2, ',', '.') . "\n";
    }
}

if (!empty($daten['top_produkte'])) {
    $nachricht .= "\n🏆 <b>Top-Produkte</b>\n";
    foreach ($daten['top_produkte'] as $i => $p) {
        $platz = ['🥇', '🥈', '🥉'][$i] ?? '•';
        $nachricht .= "{$platz} " . htmlspecialchars($p['name']) . " — {$p['stueck']}x, €" . number_format((float)$p['umsatz_netto'], 2, ',', '.') . "\n";
    }
} else {
    $nachricht .= "\nKeine Verkäufe in dieser Woche.\n";
}

// ── SENDEN ────────────────────────────────────────────────────────────────────
telegram_senden($nachricht);
