<?php
// netmon – Verbindungstest. Nach dem Test wieder löschen!
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

function ok($t)   { echo "<div style='color:#3c3'>✔ $t</div>"; }
function bad($t)  { echo "<div style='color:#e44'>✘ $t</div>"; }
function info($t) { echo "<div style='color:#999'>· $t</div>"; }

echo "<body style='background:#0d0f14;color:#ddd;font:14px monospace;padding:20px'><h2>netmon – mysqltest</h2>";

// PHP
info('PHP ' . PHP_VERSION . ' · läuft als ' . (function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : get_current_user()));
foreach (['pdo_mysql', 'mbstring', 'session', 'simplexml'] as $ext) {
    extension_loaded($ext) ? ok("Erweiterung $ext") : bad("Erweiterung $ext fehlt");
}

// Konfiguration
$f = __DIR__ . '/config.php';
if (!file_exists($f))      { bad("$f nicht vorhanden"); exit; }
if (!is_readable($f))      { bad("$f nicht lesbar für den Webserver (Rechte: " . substr(sprintf('%o', fileperms($f)), -4) . ')'); exit; }
$c = require $f;
ok("$f gelesen");
info('DSN: ' . htmlspecialchars($c['db']['dsn']) . ' · Benutzer: ' . htmlspecialchars($c['db']['user']));
file_exists(__DIR__ . '/collector/settings.php') ? ok('collector/settings.php vorhanden') : bad('collector/settings.php fehlt – Ordner collector hochgeladen?');

// Verbindung
try {
    $db = new PDO($c['db']['dsn'], $c['db']['user'], $c['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    ok('Verbindung zur Datenbank');
} catch (PDOException $e) {
    bad('Verbindung: ' . htmlspecialchars($e->getMessage()));
    exit;
}

// Tabellen
$have = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (['devices', 'device_events', 'scans', 'settings', 'speedtests', 'speedtests_daily', 'users', 'zones'] as $t) {
    in_array($t, $have) ? ok("Tabelle $t") : bad("Tabelle $t fehlt");
}
if (in_array('users', $have)) {
    info('Benutzer angelegt: ' . $db->query('SELECT COUNT(*) FROM users')->fetchColumn());
}

// Schreibrecht (Test in einer Transaktion, wird zurückgenommen)
try {
    $db->beginTransaction();
    $db->prepare("INSERT INTO users (username, pass_hash, role, created_at) VALUES ('__test__', 'x', 'viewer', NOW())")->execute();
    $db->rollBack();
    ok('Schreibrecht auf users');
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    bad('Schreiben: ' . htmlspecialchars($e->getMessage()));
}

// Sitzungen
$p = session_save_path() ?: sys_get_temp_dir();
@session_start() ? ok("Sitzung startet ($p)") : bad("Sitzung startet nicht ($p)");

echo "<p style='color:#e9a'>Fertig – Datei nach dem Test löschen.</p></body>";
