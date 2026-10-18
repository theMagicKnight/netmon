<?php
// netmon – einen Switch-Port sperren oder freigeben (z. B. per Cron zu festen Zeiten)
// Aufruf:  php /var/www/html/netmon/collector/port.php aus|an <IP oder Geräte-ID des Switches> <Port>
// Beispiel: php /var/www/html/netmon/collector/port.php aus 192.168.2.10 7
// Unterstützt: Netgear GS308Ev4 / GS108Ev4 (neue Oberfläche). Der Uplink-Port wird nie abgeschaltet.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

[$mode, $sw, $port] = [strtolower($argv[1] ?? ''), $argv[2] ?? '', (int) ($argv[3] ?? 0)];
if (!in_array($mode, ['an', 'aus', 'on', 'off'], true) || $sw === '' || $port < 1) {
    fwrite(STDERR, "Aufruf: php port.php aus|an <IP oder Geräte-ID des Switches> <Port>\n");
    exit(2);
}
$on = in_array($mode, ['an', 'on'], true);

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';
require __DIR__ . '/switch_drivers.php';
$db = netmon_pdo($cfg);
netmon_upgrade($db);

$st = $db->prepare(
    "SELECT id, INET_NTOA(ip) AS ip, COALESCE(name, hostname, INET_NTOA(ip)) AS label, sw_pass, sw_user, sw_driver, sw_uplink
       FROM devices WHERE (INET_NTOA(ip) = ? OR id = ?) AND sw_pass IS NOT NULL AND sw_pass <> '' AND is_virtual = 0 LIMIT 1"
);
$st->execute([$sw, ctype_digit($sw) ? (int) $sw : 0]);
$dev = $st->fetch();
if (!$dev) {
    fwrite(STDERR, "Switch $sw nicht gefunden oder ohne Zugang\n");
    exit(1);
}
if (!$on && $dev['sw_uplink'] !== null && (int) $dev['sw_uplink'] === $port) {
    fwrite(STDERR, "Port $port ist der Uplink – wird nicht abgeschaltet\n");
    exit(1);
}
try {
    switch_port_enable($db, $dev, $port, $on);
    printf("%s %s: Port %d %s\n", date('Y-m-d H:i:s'), $dev['label'], $port, $on ? 'freigegeben' : 'gesperrt');
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . " {$dev['label']}: {$e->getMessage()}\n");
    exit(1);
}
