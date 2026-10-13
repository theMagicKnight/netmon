<?php
// netmon – nächtliches Aufräumen: Speedtests verdichten, alte Daten löschen
// Aufruf:  php /var/www/html/netmon/collector/cleanup.php   (per Cron einmal nachts)

declare(strict_types=1);

// Nur von der Kommandozeile (Cron), nie über den Browser
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';
$db = netmon_pdo($cfg);
$s  = netmon_settings($db, $cfg);

// Aufbewahrung in Tagen (Einstellungen in der Weboberfläche)
$keepSpeedRaw = max(1, (int) $s['keep_speedtest_days']);   // danach nur noch Tageswerte
$keepEvents   = max(1, (int) $s['keep_event_days']);
$keepScans    = max(1, (int) $s['keep_scan_days']);

$db->beginTransaction();

// 1. Alte Speedtest-Rohdaten zu Tageswerten verdichten (nur ganze Tage)
$agg = $db->prepare(
    "INSERT INTO speedtests_daily
       (day, tests, down_avg, down_min, down_max, up_avg, up_min, up_max, ping_avg, ping_max)
     SELECT DATE(created_at), COUNT(*),
            AVG(download_mbps), MIN(download_mbps), MAX(download_mbps),
            AVG(upload_mbps),   MIN(upload_mbps),   MAX(upload_mbps),
            AVG(ping_ms),       MAX(ping_ms)
       FROM speedtests
      WHERE created_at < CURDATE() - INTERVAL ? DAY
        AND error IS NULL
      GROUP BY DATE(created_at)
     ON DUPLICATE KEY UPDATE
       tests = VALUES(tests),
       down_avg = VALUES(down_avg), down_min = VALUES(down_min), down_max = VALUES(down_max),
       up_avg = VALUES(up_avg),     up_min = VALUES(up_min),     up_max = VALUES(up_max),
       ping_avg = VALUES(ping_avg), ping_max = VALUES(ping_max)"
);
$agg->execute([$keepSpeedRaw]);
$days = $agg->rowCount();

$del = $db->prepare('DELETE FROM speedtests WHERE created_at < CURDATE() - INTERVAL ? DAY');
$del->execute([$keepSpeedRaw]);
$speedDeleted = $del->rowCount();

// 2. Alte Statuswechsel löschen
$del = $db->prepare('DELETE FROM device_events WHERE created_at < NOW() - INTERVAL ? DAY');
$del->execute([$keepEvents]);
$eventsDeleted = $del->rowCount();

// 3. Altes Scan-Protokoll löschen
$del = $db->prepare('DELETE FROM scans WHERE started_at < NOW() - INTERVAL ? DAY');
$del->execute([$keepScans]);
$scansDeleted = $del->rowCount();

$db->commit();

printf(
    "%s Aufgeräumt: %d Speedtests (in Tageswerte verdichtet), %d Ereignisse, %d Scans\n",
    date('Y-m-d H:i:s'), $speedDeleted, $eventsDeleted, $scansDeleted
);
