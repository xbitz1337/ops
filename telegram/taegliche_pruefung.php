<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Telegram — Tägliche Lager-Prüfung
 *
 * Per Cron-Job einmal täglich aufrufen (z.B. morgens 8 Uhr). Schickt eine
 * Telegram-Nachricht, falls Engpässe oder niedrige Bestände vorliegen —
 * bleibt bei allem in Ordnung einfach still (kein Spam).
 *
 * Cron-Befehl (hPanel):
 *   0 8 * * *
 *   /usr/bin/php /home/USERNAME/domains/.../public_html/ops/telegram/taegliche_pruefung.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/notify.php';

$pdo = db();

// Offener Bedarf je Produkt (alle Kanäle, nicht versendete Bestellungen)
$bedarf_je_produkt = [];
$bedarf_rows = $pdo->query("
    SELECT produkt_id, SUM(menge) AS bedarf
    FROM bestellungen
    WHERE status IN ('neu', 'zu_verpacken', 'versand_vorbereitet') AND produkt_id IS NOT NULL
    GROUP BY produkt_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($bedarf_rows as $row) { $bedarf_je_produkt[$row['produkt_id']] = (int)$row['bedarf']; }

$alle_produkte = $pdo->query("
    SELECT p.id, p.name,
           COALESCE(SUM(CASE
               WHEN b.typ = 'zugang_einkauf' THEN b.menge
               WHEN b.typ = 'retoure' AND b.wieder_verkaufsfaehig = 1 THEN b.menge
               WHEN b.typ = 'korrektur' THEN b.menge
               WHEN b.typ IN ('abgang_verkauf','abgang_eigenbedarf') THEN -b.menge
               ELSE 0 END), 0) AS bestand,
           COALESCE(SUM(CASE WHEN b.typ = 'zugang_einkauf' THEN b.menge ELSE 0 END), 0) AS insgesamt
    FROM lager_produkte p
    LEFT JOIN lager_bewegungen b ON b.produkt_id = p.id
    WHERE p.aktiv = 1
    GROUP BY p.id, p.name
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../aufgaben/aufgabe_helper.php';

$engpass_zeilen = [];
$niedrig_zeilen = [];

foreach ($alle_produkte as $row) {
    $bedarf = $bedarf_je_produkt[$row['id']] ?? 0;
    if ($bedarf > $row['bestand']) {
        $engpass_zeilen[] = "⚠️ {$row['name']}: {$bedarf} bestellt, nur {$row['bestand']} auf Lager";
        aufgabe_erstellen(
            $pdo,
            "Nachbestellen: {$row['name']}",
            'lager_engpass',
            'hoch',
            'beide',
            "Reicht nicht für alle offenen Bestellungen — {$bedarf} benötigt, nur {$row['bestand']} auf Lager."
        );
    }

    $schwelle = max(5, (int)round($row['insgesamt'] * 0.10));
    if ($row['bestand'] > 0 && $row['bestand'] <= $schwelle) {
        $niedrig_zeilen[] = "📉 {$row['name']}: nur noch {$row['bestand']} Stk";
    }
}

if (empty($engpass_zeilen) && empty($niedrig_zeilen)) {
    // Alles in Ordnung — keine Nachricht nötig
    exit;
}

$nachricht = "🗓 <b>Tägliche Lager-Prüfung</b>\n\n";
if (!empty($engpass_zeilen)) {
    $nachricht .= "<b>Lagerengpässe:</b>\n" . implode("\n", $engpass_zeilen) . "\n\n";
}
if (!empty($niedrig_zeilen)) {
    $nachricht .= "<b>Niedrige Bestände:</b>\n" . implode("\n", $niedrig_zeilen);
}

telegram_senden($nachricht);
