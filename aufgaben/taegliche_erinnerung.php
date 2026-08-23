<?php
date_default_timezone_set('Europe/Berlin');
/**
 * NA Ops Hub — Aufgaben — Tägliche Erinnerung (per Cron um 6 Uhr aufrufen)
 *
 * Cron-Befehl (hPanel):
 *   0 6 * * *
 *   /usr/bin/php /home/USERNAME/domains/.../public_html/ops/aufgaben/taegliche_erinnerung.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../telegram/notify.php';

$pdo = db();

$heute = date('Y-m-d');
$stmt = $pdo->prepare("
    SELECT titel, assignee, prioritaet, wiederholung_typ
    FROM aufgaben
    WHERE deadline = ? AND status != 'erledigt'
    ORDER BY FIELD(prioritaet, 'hoch', 'mittel', 'niedrig')
");
$stmt->execute([$heute]);
$faellig = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Zusätzlich: überfällige Aufgaben (Deadline in der Vergangenheit, noch offen)
$stmt2 = $pdo->prepare("
    SELECT titel, assignee, prioritaet, deadline
    FROM aufgaben
    WHERE deadline < ? AND status != 'erledigt'
    ORDER BY deadline ASC
");
$stmt2->execute([$heute]);
$ueberfaellig = $stmt2->fetchAll(PDO::FETCH_ASSOC);

if (empty($faellig) && empty($ueberfaellig)) {
    exit; // Nichts zu tun heute — keine Nachricht, kein Spam
}

$prio_icons = ['hoch' => '🔴', 'mittel' => '🟠', 'niedrig' => '🟢'];

$nachricht = "☀️ <b>Guten Morgen! Aufgaben für heute (" . date('d.m.Y') . ")</b>\n\n";

if (!empty($faellig)) {
    foreach ($faellig as $a) {
        $wiederholung = $a['wiederholung_typ'] !== 'keine' ? ' 🔁' : '';
        $nachricht .= "{$prio_icons[$a['prioritaet']]} {$a['titel']} — <i>" . strtoupper($a['assignee']) . "</i>{$wiederholung}\n";
    }
}

if (!empty($ueberfaellig)) {
    $nachricht .= "\n⚠️ <b>Überfällig:</b>\n";
    foreach ($ueberfaellig as $a) {
        $tage = (new DateTime())->diff(new DateTime($a['deadline']))->days;
        $nachricht .= "{$prio_icons[$a['prioritaet']]} {$a['titel']} — <i>" . strtoupper($a['assignee']) . "</i> ({$tage} Tage überfällig)\n";
    }
}

telegram_senden($nachricht);
