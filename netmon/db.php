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

// Schutz gegen Passwort-Raten: 5 Fehlversuche je IP in 15 Minuten -> diese IP 15 Minuten gesperrt.
// Von außen (keine private IP) zusätzlich höchstens 30 Fehlversuche in 15 Minuten insgesamt.
const LOGIN_MAX_IP = 5, LOGIN_MAX_ALL = 30, LOGIN_WINDOW_MIN = 15;

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

function ip_is_local(string $ip): bool
{
    return $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
        || str_starts_with($ip, '100.');   // Tailscale (100.64.0.0/10)
}

// Minuten bis zur nächsten erlaubten Anmeldung, 0 = darf
function login_blocked(): int
{
    $ip = client_ip();
    try {
        $st = db()->prepare('SELECT COUNT(*), MIN(ts) FROM login_fails WHERE ip = ? AND ts > NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE');
        $st->execute([$ip]);
        [$n] = $st->fetch(PDO::FETCH_NUM);
        if ((int) $n >= LOGIN_MAX_IP) {
            return LOGIN_WINDOW_MIN;
        }
        if (!ip_is_local($ip)) {
            $all = (int) db()->query("SELECT COUNT(*) FROM login_fails WHERE ts > NOW() - INTERVAL " . LOGIN_WINDOW_MIN . " MINUTE
                                        AND ip NOT LIKE '192.168.%' AND ip NOT LIKE '10.%' AND ip NOT LIKE '100.%'")->fetchColumn();
            if ($all >= LOGIN_MAX_ALL) {
                return LOGIN_WINDOW_MIN;
            }
        }
    } catch (PDOException $e) {
        // Tabelle fehlt noch (vor dem Update) – nicht sperren
    }
    return 0;
}

function login(string $username, string $password): bool
{
    if (login_blocked() > 0) {
        return false;
    }
    $st = db()->prepare('SELECT id, pass_hash FROM users WHERE username = ?');
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['pass_hash'])) {
        try {
            db()->prepare('INSERT INTO login_fails (ip, ts) VALUES (?, NOW())')->execute([client_ip()]);
        } catch (PDOException $e) {
        }
        usleep(800000);   // Ausprobieren bremsen
        return false;
    }
    db()->prepare('DELETE FROM login_fails WHERE ip = ?')->execute([client_ip()]);
    session_begin();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['id'];
    db()->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$row['id']]);
    return true;
}
