<?php
/**
 * NA Ops Hub — Rechte-System — zentrale Prüfungen
 *
 * Nutzung in jeder Seite (nach config.php + requireLogin()):
 *   require_once __DIR__ . '/../berechtigungen/berechtigungen_helper.php';
 *   require_modul_zugriff('lager');                    // mind. Ansehen
 *   require_modul_zugriff('lager', 'bearbeiten');       // Bearbeiten nötig
 *
 * Für Preise/sensible Daten ausblenden:
 *   <?php if (!hat_einschraenkung('lager', 'preise_verbergen')): ?>
 *       ... EK-Preis anzeigen ...
 *   <?php endif; ?>
 *
 * WICHTIG: Artis (Username "qaf") ist Systemverwalter und hat IMMER
 * vollen Zugriff auf alles, egal was in der Datenbank steht — das ist
 * bewusst so, als Sicherheitsnetz.
 */

function hat_modul_zugriff(string $modul, string $mindest = 'ansehen'): bool
{
    $user = currentUser();
    if (!$user) return false;
    if ($user['username'] === 'qaf') return true; // Systemverwalter — immer voller Zugriff

    $pdo = db();
    $stmt = $pdo->prepare("SELECT zugriff FROM benutzer_berechtigungen WHERE user_id = ? AND modul = ?");
    $stmt->execute([$user['id'], $modul]);
    $level = $stmt->fetchColumn();

    if (!$level || $level === 'kein') return false;

    $rang = ['ansehen' => 1, 'bearbeiten' => 2];
    return ($rang[$level] ?? 0) >= ($rang[$mindest] ?? 1);
}

function hat_einschraenkung(string $modul, string $einschraenkung): bool
{
    $user = currentUser();
    if (!$user || $user['username'] === 'qaf') return false; // qaf sieht immer alles

    $pdo = db();
    $stmt = $pdo->prepare("SELECT einschraenkungen FROM benutzer_berechtigungen WHERE user_id = ? AND modul = ?");
    $stmt->execute([$user['id'], $modul]);
    $roh = $stmt->fetchColumn();
    if (!$roh) return false;

    $liste = json_decode($roh, true) ?: [];
    return in_array($einschraenkung, $liste);
}

function require_modul_zugriff(string $modul, string $mindest = 'ansehen'): void
{
    if (!hat_modul_zugriff($modul, $mindest)) {
        http_response_code(403);
        die('<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head><body style="font-family:-apple-system,sans-serif;background:#14161F;color:#E4E6F0;padding:60px 20px;text-align:center;"><h2>🔒 Kein Zugriff</h2><p style="color:#8B8FA8;">Du hast keine Berechtigung für diesen Bereich.</p><a href="/dashboard.php" style="color:#9494FF;">← Zurück zum Dashboard</a></body></html>');
    }
}
