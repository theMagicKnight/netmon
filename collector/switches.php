<?php
// netmon – Port-Status aller eingerichteten Netgear-Plus-Switches abfragen
// Aufruf: php /var/www/html/netmon/collector/switches.php   (startet tick.php zusammen mit dem Netzwerkscan)

declare(strict_types=1);

// Nur von der Kommandozeile (Cron), nie über den Browser
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/switch_drivers.php';
$db = netmon_pdo($cfg);
netmon_upgrade($db);

$lock = fopen('/tmp/netmon-switches.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

$devs = $db->query(
    "SELECT id, INET_NTOA(ip) AS ip, COALESCE(name, hostname, INET_NTOA(ip)) AS label, sw_pass, sw_user, sw_driver,
            JSON_UNQUOTE(JSON_EXTRACT(sw_info, '$.checked')) AS checked
       FROM devices WHERE sw_pass IS NOT NULL AND sw_pass <> '' AND ip IS NOT NULL AND is_virtual = 0"
)->fetchAll();

// Einmal pro Stunde: alten Verlauf löschen
if ((int) date('i') === 7) {
    $keep = max(1, (int) netmon_settings($db, $cfg)['keep_history_days']);
    foreach (['port_history', 'ap_history', 'dev_metrics'] as $t) {
        $db->prepare("DELETE FROM $t WHERE ts < NOW() - INTERVAL ? DAY")->execute([$keep]);
    }
}

foreach ($devs as $d) {
    try {
        // Modell, Firmware und Einstellungs-Check nur einmal pro Stunde
        $withInfo = !$d['checked'] || strtotime($d['checked']) < time() - 3600;
        $ports = switch_poll($db, $d, null, $withInfo);
        if ($ports) {
            $up = count(array_filter($ports, fn($p) => $p['link']));
            printf("%s %s: %d/%d Ports mit Link\n", date('Y-m-d H:i:s'), $d['label'], $up, count($ports));
        } else {
            $n = $db->prepare('SELECT COUNT(*) FROM wifi_clients WHERE ap_id = ?');
            $n->execute([$d['id']]);
            printf("%s %s: %d WLAN-Clients\n", date('Y-m-d H:i:s'), $d['label'], $n->fetchColumn());
        }
    } catch (Throwable $e) {
        fwrite(STDERR, date('Y-m-d H:i:s') . " {$d['label']}: {$e->getMessage()}\n");
    }
}
