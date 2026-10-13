<?php
// netmon – Speedtest per Ookla-CLI, speichert das Ergebnis in MariaDB
// Aufruf:  php /var/www/html/netmon/collector/speedtest.php   (per Cron z. B. stündlich)

declare(strict_types=1);

// Nur von der Kommandozeile (Cron), nie über den Browser
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';
$db = netmon_pdo($cfg);
$s  = netmon_settings($db, $cfg);

if (!$s['speedtest_enabled'] && !in_array('--force', $argv, true)) {
    fwrite(STDERR, "Speedtest ist in den Einstellungen abgeschaltet (mit --force trotzdem ausführen).\n");
    exit(0);
}

$bin = $cfg['speedtest'] ?? '/usr/local/bin/speedtest';

// Nur ein Test gleichzeitig
$lock = fopen('/tmp/netmon-speedtest.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Speedtest läuft bereits, Abbruch.\n");
    exit(1);
}

// Test ausführen, nach 120 s abbrechen
$cmd = 'timeout 120 ' . escapeshellcmd($bin) . ' --format=json --accept-license --accept-gdpr 2>&1';
$out = shell_exec($cmd) ?? '';

// Die Ausgabe kann Hinweiszeilen enthalten -> die JSON-Zeile mit "type":"result" suchen
$result = null;
foreach (explode("\n", $out) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] !== '{') {
        continue;
    }
    $j = json_decode($line, true);
    if (is_array($j) && ($j['type'] ?? '') === 'result') {
        $result = $j;
        break;
    }
}


$now = date('Y-m-d H:i:s');

if ($result === null) {
    // Fehlgeschlagenen Test trotzdem festhalten – ein Ausfall ist auch ein Messwert
    $err = mb_substr(trim(preg_replace('/\s+/', ' ', $out)) ?: 'keine Ausgabe', 0, 255);
    $db->prepare('INSERT INTO speedtests (created_at, error) VALUES (?, ?)')->execute([$now, $err]);
    fwrite(STDERR, "$now Speedtest fehlgeschlagen: $err\n");
    exit(1);
}

// bandwidth ist in Byte/s -> Mbit/s
$mbps = fn($bytesPerSec) => $bytesPerSec !== null ? round($bytesPerSec * 8 / 1_000_000, 2) : null;

$row = [
    $now,
    $result['ping']['latency']        ?? null,
    $result['ping']['jitter']         ?? null,
    $mbps($result['download']['bandwidth'] ?? null),
    $mbps($result['upload']['bandwidth']   ?? null),
    $result['packetLoss']             ?? null,
    mb_substr((string) ($result['isp'] ?? ''), 0, 64) ?: null,
    mb_substr(trim(($result['server']['name'] ?? '') . ', ' . ($result['server']['location'] ?? ''), ', '), 0, 128) ?: null,
    $result['result']['url']          ?? null,
];

$db->prepare(
    'INSERT INTO speedtests (created_at, ping_ms, jitter_ms, download_mbps, upload_mbps, packet_loss, isp, server_name, result_url)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute($row);

printf("%s Down %.1f Mbit/s | Up %.1f Mbit/s | Ping %.1f ms\n", $now, $row[3], $row[4], $row[1]);
