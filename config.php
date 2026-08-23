
<?php
// ================================================
// NA OPS HUB — config.php
// WICHTIG: Diese Datei NIEMALS öffentlich zugänglich machen!
// ================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'u308867761_ops');
define('DB_USER', 'u308867761_opss');
define('DB_PASS', '1132ANihau25!'); // <-- Hier dein Passwort eintragen!

define('APP_NAME', 'NA Ops Hub');
define('APP_URL',  'https://ops.nacommercesolutions.com');

// Session starten
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Datenbankverbindung
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Datenbankverbindung fehlgeschlagen.']));
        }
    }
    return $pdo;
}

// Eingeloggten User holen
function currentUser(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    $stmt = db()->prepare('SELECT id, username, name FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

// Login-Schutz
function requireLogin(): void {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}
?>
