<?php
// netmon – Datenbank, Einstellungen und Anmeldung für die Weboberfläche
// Alles liegt in diesem Ordner: config.php (Zugangsdaten) und collector/ (Cron-Skripte)

declare(strict_types=1);

const NETMON_DIR = __DIR__;

require_once NETMON_DIR . '/collector/settings.php';

function cfg(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require NETMON_DIR . '/config.php';
        date_default_timezone_set($cfg['timezone'] ?? 'Europe/Berlin');
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = netmon_pdo(cfg());
        netmon_upgrade($pdo);
    }
    return $pdo;
}

function settings(bool $fresh = false): array
{
    static $s = null;
    if ($s === null || $fresh) {
        $s = netmon_settings(db(), cfg());
    }
    return $s;
}

// ---------------------------------------------------------------
// Anmeldung
// ---------------------------------------------------------------

function session_begin(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('netmon');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,      // 30 Tage angemeldet bleiben
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    ini_set('session.gc_maxlifetime', (string) (60 * 60 * 24 * 30));
    session_start();
}

function current_user(): ?array
{
    session_begin();
    if (empty($_SESSION['uid'])) {
        return null;
    }
    static $user = null;
    if ($user === null) {
        $st = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
        if ($user === null) {
            $_SESSION = [];
        }
    }
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function csrf_token(): string
{
    session_begin();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function users_exist(): bool
{
    try {
        return (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

// Seiten: ohne Anmeldung zur Login-Seite
function require_login_page(): array
{
    $u = current_user();
    if ($u === null) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

function login(string $username, string $password): bool
{
    $st = db()->prepare('SELECT id, pass_hash FROM users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['pass_hash'])) {
        usleep(800000);   // Ausprobieren bremsen
        return false;
    }
    session_begin();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
    return true;
}
