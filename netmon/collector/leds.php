<?php
// netmon – Port-LEDs der Switches ein- oder ausschalten
// Aufruf:  php /var/www/html/netmon/collector/leds.php aus|an [IP oder Geräte-ID …]
//          ohne IP/ID: alle eingerichteten Switches, deren Treiber das kann (GS308Ev4, GS108Ev4, GS30xEP, GS316EP …)
// Zeitgesteuert über Einstellungen → Switch-LEDs (tick.php ruft dieses Skript auf).

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$mode = strtolower($argv[1] ?? '');
if (!in_array($mode, ['an', 'aus', 'on', 'off'], true)) {
    fwrite(STDERR, "Aufruf: php leds.php aus|an [IP oder Geräte-ID …]\n");
    exit(2);
}
$on = in_array($mode, ['an', 'on'], true);
$only = array_slice($argv, 2);

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/switch_drivers.php';
$db = netmon_pdo($cfg);
netmon_upgrade($db);

$devs = $db->query(
    "SELECT id, INET_NTOA(ip) AS ip, COALESCE(name, hostname, INET_NTOA(ip)) AS label, sw_pass, sw_user, sw_driver
       FROM devices WHERE sw_pass IS NOT NULL AND sw_pass <> '' AND ip IS NOT NULL AND is_virtual = 0"
)->fetchAll();

$fail = 0;
$done = 0;
foreach ($devs as $d) {
    if ($only && !in_array($d['ip'], $only, true) && !in_array((string) $d['id'], $only, true)) {
        continue;
    }
    try {
        if (!switch_can_leds(switch_driver_class($d['sw_driver'] ?: 'netgear_plus'))) {
            if ($only) {
                fwrite(STDERR, date('Y-m-d H:i:s') . " {$d['label']}: Treiber kann die LEDs nicht schalten\n");
                $fail++;
            }
            continue;
        }
        switch_leds($db, $d, $on);
        printf("%s %s: LEDs %s\n", date('Y-m-d H:i:s'), $d['label'], $on ? 'an' : 'aus');
        $done++;
    } catch (Throwable $e) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " {$d['label']}: {$e->getMessage()}\n");
        $fail++;
    }
}
if (!$done && !$fail) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " Kein Switch gefunden, der die LEDs schalten kann\n");
}
exit($fail ? 1 : 0);
