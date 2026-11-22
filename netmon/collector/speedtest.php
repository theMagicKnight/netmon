<?php
// netmon – Speedtest per LibreSpeed-CLI (Standard) oder Ookla-CLI, speichert das Ergebnis in MariaDB
// Aufruf:  php /var/www/html/netmon/collector/speedtest.php [--force]   (normalerweise über tick.php)
//
// Ookla sperrt die kostenlose CLI bei häufigen Tests („Limit reached: Too many requests“).
// LibreSpeed kennt solche Sperren nicht und ist deshalb der Standard.

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

// Programm suchen: Pfad aus config.php, sonst übliche Orte, sonst im PATH
$findBin = function (string $cfgKey, string $name) use ($cfg): ?string {
    foreach ([$cfg[$cfgKey] ?? null, "/usr/local/bin/$name", "/usr/bin/$name"] as $p) {
        if ($p && is_file($p) && is_executable($p)) {
            return $p;
        }
    }
    $p = trim((string) shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
    return $p !== '' ? $p : null;
};

$tool = $s['speedtest_tool'] === 'ookla' ? 'ookla' : 'librespeed';
$bins = [
    'librespeed' => $findBin('librespeed', 'librespeed-cli'),
    'ookla'      => $findBin('speedtest', 'speedtest'),
];
// Gewähltes Programm fehlt -> das andere nehmen, statt nur Fehler zu sammeln
if ($bins[$tool] === null) {
    $other = $tool === 'ookla' ? 'librespeed' : 'ookla';
    if ($bins[$other] !== null) {
        fwrite(STDERR, "$tool nicht gefunden, nehme $other.\n");
        $tool = $other;
    }
}
$bin = $bins[$tool];

// Nur ein Test gleichzeitig
$lock = fopen('/tmp/netmon-speedtest.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Speedtest läuft bereits, Abbruch.\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');

$fail = function (string $err) use ($db, $now, $tool): void {
    $err = mb_substr(trim(preg_replace('/\s+/', ' ', $err)) ?: 'keine Ausgabe', 0, 255);
    $db->prepare('INSERT INTO speedtests (created_at, error) VALUES (?, ?)')->execute([$now, $err]);
    fwrite(STDERR, "$now Speedtest ($tool) fehlgeschlagen: $err\n");
    exit(1);
};

if ($bin === null) {
    $fail('Kein Speedtest-Programm gefunden (librespeed-cli oder speedtest) – install.sh erneut ausführen');
}

// Alle JSON-Zeilen der Ausgabe (die Programme schreiben teils Hinweise davor)
$jsonLines = function (string $out): array {
    $found = [];
    $whole = json_decode(trim($out), true);
    if (is_array($whole)) {
        return [$whole];
    }
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if ($line === '' || ($line[0] !== '{' && $line[0] !== '[')) {
            continue;
        }
        $j = json_decode($line, true);
        if (is_array($j)) {
            $found[] = $j;
        }
    }
    return $found;
};

$cut = fn(?string $v, int $n) => ($v = trim((string) $v)) !== '' ? mb_substr($v, 0, $n) : null;

if ($tool === 'librespeed') {
    // Ergebnis in Mbit/s und ms; Paketverlust misst LibreSpeed nicht
    // Fester Server misst gleichmäßiger als die automatische Wahl (die wechselt je nach Ping von Lauf zu Lauf)
    $server = (int) $s['librespeed_server'] > 0 ? ' --server ' . (int) $s['librespeed_server'] : '';
    $out = shell_exec('timeout 150 ' . escapeshellarg($bin) . $server . ' --json 2>&1') ?? '';
    $result = null;
    foreach ($jsonLines($out) as $j) {
        $cand = isset($j[0]) ? $j[0] : $j;   // neuere Versionen liefern eine Liste
        if (is_array($cand) && isset($cand['download'])) {
            $result = $cand;
            break;
        }
    }
    if ($result === null || (float) ($result['download'] ?? 0) <= 0) {
        $fail('LibreSpeed: ' . $out);
    }
    $client = $result['client'] ?? [];
    $row = [
        $now,
        isset($result['ping'])   ? round((float) $result['ping'], 2)   : null,
        isset($result['jitter']) ? round((float) $result['jitter'], 2) : null,
        round((float) $result['download'], 2),
        isset($result['upload']) ? round((float) $result['upload'], 2) : null,
        null,
        $cut($client['isp'] ?? preg_replace('/^AS\d+\s+/', '', (string) ($client['org'] ?? '')), 64),
        $cut('LibreSpeed: ' . ($result['server']['name'] ?? '?'), 128),
        $cut($result['share'] ?? null, 255),
    ];
} else {
    $out = shell_exec('timeout 120 ' . escapeshellarg($bin) . ' --format=json --accept-license --accept-gdpr 2>&1') ?? '';
    $result = null;
    foreach ($jsonLines($out) as $j) {
        if (($j['type'] ?? '') === 'result') {
            $result = $j;
            break;
        }
    }
    if ($result === null) {
        $fail($out);
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
        $cut($result['isp'] ?? null, 64),
        $cut(trim(($result['server']['name'] ?? '') . ', ' . ($result['server']['location'] ?? ''), ', '), 128),
        $result['result']['url']          ?? null,
    ];
}

$db->prepare(
    'INSERT INTO speedtests (created_at, ping_ms, jitter_ms, download_mbps, upload_mbps, packet_loss, isp, server_name, result_url)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
)->execute($row);

printf("%s [%s] Down %.1f Mbit/s | Up %.1f Mbit/s | Ping %.1f ms\n", $now, $tool, $row[3], (float) $row[4], (float) $row[1]);
