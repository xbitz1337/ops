<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Automatisches Datenbank-Backup
 *
 * Per Cron-Job einmal täglich aufrufen (z.B. nachts 3 Uhr). Exportiert die
 * komplette Datenbank als SQL-Datei — reine PHP-Lösung, funktioniert auch
 * auf Shared Hosting ohne mysqldump/Shell-Zugriff.
 *
 * Behält automatisch die letzten 14 Tage, ältere Backups werden gelöscht.
 *
 * WICHTIG: Der backup/dumps/-Ordner sollte NICHT öffentlich erreichbar sein
 * (enthält alle Geschäftsdaten). Legt zur Sicherheit eine leere .htaccess
 * mit "Deny from all" dort rein, oder verschiebt den Ordner eine Ebene
 * über public_html, falls euer Hosting das zulässt.
 *
 * Cron-Befehl (hPanel):
 *   0 3 * * *
 *   /usr/bin/php /home/USERNAME/domains/.../public_html/ops/backup/backup_cron.php
 */

require_once __DIR__ . '/../config.php';
$pdo = db();

$backup_ordner = __DIR__ . '/dumps';
if (!is_dir($backup_ordner)) {
    mkdir($backup_ordner, 0755, true);
}

$dateiname = 'backup_' . date('Y-m-d_His') . '.sql';
$dateipfad = $backup_ordner . '/' . $dateiname;

$fp = fopen($dateipfad, 'w');
if (!$fp) {
    error_log('[Backup] Konnte Backup-Datei nicht öffnen: ' . $dateipfad);
    exit;
}

fwrite($fp, "-- NA Ops Hub — Datenbank-Backup\n-- Erstellt am " . date('d.m.Y H:i:s') . "\n\n");
fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

// Alle Tabellen der aktuellen Datenbank ermitteln
$tabellen = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

foreach ($tabellen as $tabelle) {
    // Tabellen-Struktur exportieren
    $create = $pdo->query("SHOW CREATE TABLE `$tabelle`")->fetch(PDO::FETCH_ASSOC);
    fwrite($fp, "DROP TABLE IF EXISTS `$tabelle`;\n");
    fwrite($fp, $create['Create Table'] . ";\n\n");

    // Tabellen-Inhalt exportieren, in Blöcken um Speicher zu schonen
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
            $werte = array_map(function ($wert) use ($pdo) {
                if ($wert === null) return 'NULL';
                return $pdo->quote($wert);
            }, array_values($zeile));
            $werte_zeilen[] = '(' . implode(', ', $werte) . ')';
        }

        fwrite($fp, "INSERT INTO `$tabelle` ($spalten_sql) VALUES\n" . implode(",\n", $werte_zeilen) . ";\n\n");
    }
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fp);

// Backup komprimieren, spart Speicherplatz
$gz_pfad = $dateipfad . '.gz';
$inhalt = file_get_contents($dateipfad);
file_put_contents($gz_pfad, gzencode($inhalt, 9));
unlink($dateipfad); // unkomprimierte Version löschen, nur .gz behalten

error_log('[Backup] Erfolgreich erstellt: ' . basename($gz_pfad) . ' (' . round(filesize($gz_pfad) / 1024, 1) . ' KB)');

// Alte Backups aufräumen — nur die letzten 14 Tage behalten
$alle_backups = glob($backup_ordner . '/backup_*.sql.gz');
$grenze = strtotime('-14 days');
foreach ($alle_backups as $datei) {
    if (filemtime($datei) < $grenze) {
        unlink($datei);
    }
}

// Optional: Telegram-Bestätigung (still, nur bei Fehler wichtig — hier zur
// Kontrolle mit drin, kann entfernt werden falls unerwünscht)
if (file_exists(__DIR__ . '/../telegram/notify.php')) {
    require_once __DIR__ . '/../telegram/notify.php';
    telegram_senden("💾 Tägliches Backup erstellt: " . basename($gz_pfad) . " (" . round(filesize($gz_pfad) / 1024, 0) . " KB)");
}
