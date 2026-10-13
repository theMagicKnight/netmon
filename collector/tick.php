<?php
// netmon – Taktgeber, läuft per Cron jede Minute (/etc/cron.d/netmon)
// Entscheidet anhand der Einstellungen, ob Scan, Speedtest oder Aufräumen fällig sind.

declare(strict_types=1);

// Nur von der Kommandozeile (Cron), nie über den Browser
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';

$db = netmon_pdo($cfg);
netmon_upgrade($db);   // neue Spalten/Tabellen nach einem Update, auch ohne Aufruf der Weboberfläche
$s  = netmon_settings($db, $cfg);

$minute = (int) date('G') * 60 + (int) date('i');   // Minute des Tages

$run = function (string $script, array $args = []) {
    $cmd = sprintf('%s %s %s >> %s 2>&1 &',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(__DIR__ . "/$script.php"),
        implode(' ', array_map('escapeshellarg', $args)),
        escapeshellarg("/var/log/netmon-$script.log"));
    exec($cmd);
};

// Netzwerkscan
$scanEvery = max(1, (int) $s['scan_interval']);
if ($minute % $scanEvery === 0 || $s['scan_request']) {
    if ($s['scan_request']) {
        netmon_set($db, 'scan_request', 0);
    }
    $run('scan');
    $run('switches');   // Port-Status der Netgear-Plus-Switches im selben Takt
}

// Speedtest, versetzt um 7 Minuten, damit er nicht mit dem Scan zusammenfällt
if ($s['speedtest_enabled']) {
    $speedEvery = max(10, (int) $s['speedtest_interval']);
    if ((($minute - 7) % $speedEvery + $speedEvery) % $speedEvery === 0 || $s['speedtest_request']) {
        if ($s['speedtest_request']) {
            netmon_set($db, 'speedtest_request', 0);
        }
        $run('speedtest');
    }
}

// Nächtliches Aufräumen um 3:30 Uhr
if ($minute === 3 * 60 + 30) {
    $run('cleanup');
}
