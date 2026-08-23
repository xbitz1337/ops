<?php
/**
 * NA Ops Hub — Aufgaben-Modul — Hilfsfunktion für andere Module
 *
 * Nutzung aus jedem anderen Modul heraus:
 *   require_once __DIR__ . '/../aufgaben/aufgabe_helper.php';
 *   aufgabe_erstellen($pdo, 'Wärmflasche nachbestellen', 'lager_engpass', 'hoch');
 *
 * Verhindert automatisch Duplikate: legt keine neue Aufgabe an, wenn schon
 * eine offene Aufgabe mit demselben Titel + derselben Quelle existiert.
 */
function aufgabe_erstellen(PDO $pdo, string $titel, string $quelle_modul, string $prioritaet = 'mittel', string $assignee = 'beide', ?string $beschreibung = null): ?int
{
    $check = $pdo->prepare("
        SELECT id FROM aufgaben
        WHERE titel = ? AND quelle_modul = ? AND status != 'erledigt'
        LIMIT 1
    ");
    $check->execute([$titel, $quelle_modul]);
    if ($check->fetchColumn()) {
        return null; // Gibt's schon offen, keine Dublette anlegen
    }

    $pdo->prepare("
        INSERT INTO aufgaben (titel, beschreibung, prioritaet, assignee, quelle_modul, erstellt_von)
        VALUES (?, ?, ?, ?, ?, NULL)
    ")->execute([$titel, $beschreibung, $prioritaet, $assignee, $quelle_modul]);

    return (int)$pdo->lastInsertId();
}
