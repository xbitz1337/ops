<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Kombinierter Morgenbericht
 * Ersetzt die drei einzelnen Cron-Jobs (Backup, Aufgaben-Erinnerung,
 * Lagerprüfung) durch EINE gemeinsame Telegram-Nachricht am frühen Morgen.
 *
 * Cron-Job (hPanel) — nur DIESEN einen Job aktiv lassen, die alten drei
 * (backup_cron.php, aufgaben/taegliche_erinnerung.php,
 * telegram/taegliche_pruefung.php) als Cron-Job deaktivieren/löschen:
 *
 *   0 6 * * *
 *   /usr/bin/php /home/USERNAME/domains/.../public_html/ops/telegram/morgenbericht.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/../aufgaben/aufgabe_helper.php';

$pdo = db();
$nachricht = "☀️ <b>Guten Morgen! Tagesbericht " . date('d.m.Y') . "</b>\n";

// ── 1. AUFGABEN ──────────────────────────────────────────────────────────────
$heute = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT titel, assignee, prioritaet, wiederholung_typ
    FROM aufgaben
    WHERE deadline = ? AND status != 'erledigt'
    ORDER BY FIELD(prioritaet, 'hoch', 'mittel', 'niedrig')
");
$stmt->execute([$heute]);
$faellig = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt2 = $pdo->prepare("
    SELECT titel, assignee, prioritaet, deadline
    FROM aufgaben
    WHERE deadline < ? AND status != 'erledigt'
    ORDER BY deadline ASC
");
$stmt2->execute([$heute]);
$ueberfaellig = $stmt2->fetchAll(PDO::FETCH_ASSOC);

$prio_icons = ['hoch' => '🔴', 'mittel' => '🟠', 'niedrig' => '🟢'];

$nachricht .= "\n📋 <b>Aufgaben heute</b>\n";
if (empty($faellig) && empty($ueberfaellig)) {
    $nachricht .= "Keine Aufgaben heute — alles erledigt oder nichts fällig.\n";
} else {
    foreach ($faellig as $a) {
        $wiederholung = $a['wiederholung_typ'] !== 'keine' ? ' 🔁' : '';
        $nachricht .= "{$prio_icons[$a['prioritaet']]} {$a['titel']} — <i>" . strtoupper($a['assignee']) . "</i>{$wiederholung}\n";
    }
    if (!empty($ueberfaellig)) {
        $nachricht .= "\n⚠️ <b>Überfällig:</b>\n";
        foreach ($ueberfaellig as $a) {
            $tage = (new DateTime())->diff(new DateTime($a['deadline']))->days;
            $nachricht .= "{$prio_icons[$a['prioritaet']]} {$a['titel']} — <i>" . strtoupper($a['assignee']) . "</i> ({$tage} Tage überfällig)\n";
        }
    }
}

// ── 2. LAGERPRÜFUNG ──────────────────────────────────────────────────────────
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

$nachricht .= "\n📦 <b>Lagerprüfung</b>\n";
if (empty($engpass_zeilen) && empty($niedrig_zeilen)) {
    $nachricht .= "Alles im grünen Bereich.\n";
} else {
    foreach ($engpass_zeilen as $z) { $nachricht .= "{$z}\n"; }
    foreach ($niedrig_zeilen as $z) { $nachricht .= "{$z}\n"; }
}

// ── 3. BACKUP ─────────────────────────────────────────────────────────────────
$backup_ordner = __DIR__ . '/../backup/dumps';
if (!is_dir($backup_ordner)) {
    mkdir($backup_ordner, 0755, true);
}

$dateiname = 'backup_' . date('Y-m-d_His') . '.sql';
$dateipfad = $backup_ordner . '/' . $dateiname;
$backup_erfolgreich = false;
$backup_groesse_kb = 0;

try {
    $fp = fopen($dateipfad, 'w');
    fwrite($fp, "-- NA Ops Hub — Datenbank-Backup\n-- Erstellt am " . date('d.m.Y H:i:s') . "\n\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

    $tabellen = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tabellen as $tabelle) {
        $create = $pdo->query("SHOW CREATE TABLE `$tabelle`")->fetch(PDO::FETCH_ASSOC);
        fwrite($fp, "DROP TABLE IF EXISTS `$tabelle`;\n");
        fwrite($fp, $create['Create Table'] . ";\n\n");

        $anzahl = (int)$pdo->query("SELECT COUNT(*) FROM `$tabelle`")->fetchColumn();
        if ($anzahl === 0) continue;

        $block_groesse = 500;
        for ($offset = 0; $offset < $anzahl; $offset += $block_groesse) {
            $zeilen = $pdo->query("SELECT * FROM `$tabelle` LIMIT $block_groesse OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
            if (empty($zeilen)) continue;
            $spalten = array_keys($zeilen[0]);
            $spalten_sql = '`' . implode('`, `', $spalten) . '`';
            $werte_zeilen = [];
            foreach ($zeilen as $zeile) {
                $werte = array_map(fn($w) => $w === null ? 'NULL' : $pdo->quote($w), array_values($zeile));
                $werte_zeilen[] = '(' . implode(', ', $werte) . ')';
            }
            fwrite($fp, "INSERT INTO `$tabelle` ($spalten_sql) VALUES\n" . implode(",\n", $werte_zeilen) . ";\n\n");
        }
    }
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);

    $gz_pfad = $dateipfad . '.gz';
    file_put_contents($gz_pfad, gzencode(file_get_contents($dateipfad), 9));
    unlink($dateipfad);

    $backup_erfolgreich = true;
    $backup_groesse_kb = round(filesize($gz_pfad) / 1024, 0);

    // Alte Backups aufräumen — nur die letzten 14 Tage behalten
    $alle_backups = glob($backup_ordner . '/backup_*.sql.gz');
    $grenze = strtotime('-14 days');
    foreach ($alle_backups as $datei) {
        if (filemtime($datei) < $grenze) unlink($datei);
    }
} catch (Throwable $e) {
    error_log('[Morgenbericht] Backup fehlgeschlagen: ' . $e->getMessage());
}

$nachricht .= "\n💾 <b>Backup</b>\n";
$nachricht .= $backup_erfolgreich
    ? "Erfolgreich erstellt ({$backup_groesse_kb} KB)."
    : "⚠️ Backup fehlgeschlagen — bitte Error-Log prüfen.";

// ── SENDEN ────────────────────────────────────────────────────────────────────
telegram_senden($nachricht);