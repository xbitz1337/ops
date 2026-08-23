<?php
// ================================================
// NA OPS HUB — auth.php
// Verarbeitet Login und Logout
// Mit Brute-Force-Schutz: nach 5 Fehlversuchen (pro Username ODER pro
// IP-Adresse) innerhalb von 15 Minuten wird der Login für 15 Minuten gesperrt.
// ================================================
require_once 'config.php';

const MAX_FEHLVERSUCHE = 5;
const SPERRE_MINUTEN = 15;

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// LOGIN
if ($action === 'login') {
    $username = strtolower(trim($_POST['username'] ?? ''));
    $pin      = trim($_POST['pin'] ?? '');
    $ip       = $_SERVER['REMOTE_ADDR'] ?? 'unbekannt';

    if (!$username || !$pin) {
        returnError('Benutzername und PIN eingeben.');
    }

    // Alte Einträge aufräumen (älter als 1 Tag) — hält die Tabelle klein
    db()->prepare('DELETE FROM login_versuche WHERE versucht_am < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute();

    // Prüfen: ist der Username ODER die IP-Adresse aktuell gesperrt?
    $sperre_grenze = date('Y-m-d H:i:s', strtotime('-' . SPERRE_MINUTEN . ' minutes'));

    $stmt = db()->prepare('
        SELECT COUNT(*) FROM login_versuche
        WHERE erfolgreich = 0 AND versucht_am >= ?
        AND (username = ? OR ip_adresse = ?)
    ');
    $stmt->execute([$sperre_grenze, $username, $ip]);
    $fehlversuche = (int)$stmt->fetchColumn();

    if ($fehlversuche >= MAX_FEHLVERSUCHE) {
        // Zeit bis zur Entsperrung berechnen (Zeit seit dem ältesten relevanten
        // Fehlversuch innerhalb des Sperrfensters)
        $stmt = db()->prepare('
            SELECT MIN(versucht_am) FROM login_versuche
            WHERE erfolgreich = 0 AND versucht_am >= ?
            AND (username = ? OR ip_adresse = ?)
        ');
        $stmt->execute([$sperre_grenze, $username, $ip]);
        $aeltester = $stmt->fetchColumn();
        $frei_in = ceil((strtotime($aeltester) + SPERRE_MINUTEN * 60 - time()) / 60);
        $frei_in = max(1, $frei_in);

        returnError("Zu viele Fehlversuche. Bitte in ca. {$frei_in} Minute(n) erneut versuchen.");
    }

    $stmt = db()->prepare('SELECT id, username, name, pin FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $login_erfolgreich = $user && password_verify($pin, $user['pin']);

    // Versuch protokollieren (immer, egal ob erfolgreich oder nicht)
    db()->prepare('INSERT INTO login_versuche (username, ip_adresse, erfolgreich) VALUES (?, ?, ?)')
        ->execute([$username, $ip, $login_erfolgreich ? 1 : 0]);

    if (!$login_erfolgreich) {
        // Falls durch diesen Versuch die Sperrgrenze erreicht wird, per
        // Telegram warnen (falls eingerichtet) — möglicher Angriffsversuch
        if ($fehlversuche + 1 >= MAX_FEHLVERSUCHE) {
            $telegram_pfad = __DIR__ . '/telegram/notify.php';
            if (file_exists($telegram_pfad)) {
                require_once $telegram_pfad;
                telegram_senden("🔒 Login gesperrt: '{$username}' von IP {$ip} nach {$fehlversuche} Fehlversuchen — falls das nicht ihr wart, ist der Zugang jetzt " . SPERRE_MINUTEN . " Min. blockiert.");
            }
        }
        returnError('Zugang verweigert — PIN ungültig.');
    }

    // Erfolgreicher Login — Session setzen
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['name']      = $user['name'];
    echo json_encode(['success' => true, 'name' => $user['name']]);
    exit;
}

// LOGOUT
if ($action === 'logout') {
    session_destroy();
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

function returnError(string $msg): void {
    echo json_encode(['error' => $msg]);
    exit;
}