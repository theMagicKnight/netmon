<?php
// netmon – JSON-Schnittstelle für die Weboberfläche (nur mit Anmeldung)
// Lesen (GET):     status, tree, speed, events, devices, settings*, users*
// Schreiben (POST, JSON + Header X-CSRF-Token):
//   save_device*, delete_device*, save_settings*, save_zone*, delete_zone*,
//   save_user*, delete_user*, run_now*, change_password
// * nur für Admins

declare(strict_types=1);

require __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const DEVICE_TYPES = ['router', 'switch', 'ap', 'server', 'nas', 'pc', 'laptop', 'pi', 'iot', 'kamera', 'tv', 'handy',
                      'tablet', 'drucker', 'konsole', 'lautsprecher', 'funk', 'sonstiges'];
const INFRA_TYPES  = ['router', 'switch', 'ap'];

final class HttpError extends RuntimeException {}

try {
    if (current_user() === null) {
        throw new HttpError('Bitte anmelden', 401);
    }
    $action = $_GET['action'] ?? '';
    if ($action === 'switch_pages') {   // Download statt JSON
        admin();
        switchPages((int) ($_GET['id'] ?? 0));
        exit;
    }
    $out = match ($action) {
        'status'          => status(),
        'tree'            => tree(),
        'speed'           => speed($_GET['range'] ?? '7d'),
        'events'          => events(),
        'devices'         => devices(),
        'settings'        => admin() ?? settingsGet(),
        'users'           => admin() ?? usersGet(),
        'save_device'     => admin() ?? saveDevice(body()),
        'delete_device'   => admin() ?? deleteDevice(body()),
        'create_device'   => admin() ?? createDevice(body()),
        'save_room_layout'=> admin() ?? saveRoomLayout(body()),
        'switch_poll'     => admin() ?? switchPoll(body()),
        'switch_cable'    => admin() ?? switchCable(body()),
        'switch_port'     => family() ?? switchPortSet(body()),
        'quick'           => quick(),
        'quick_pin'       => admin() ?? quickPin(body()),
        'leds_all'        => family() ?? ledsAll(body()),
        'router_action'   => family() ?? routerAction(body()),
        'connect'         => admin() ?? connectDevices(body()),
        'dismiss_suggestion' => admin() ?? dismissSuggestion(body()),
        'switch_drivers'  => admin() ?? switchDriverList(),
        'history'         => deviceHistory((int) ($_GET['id'] ?? 0), (string) ($_GET['range'] ?? '1h')),
        'live'            => deviceLive((int) ($_GET['id'] ?? 0)),
        'save_settings'   => admin() ?? settingsSave(body()),
        'save_zone'       => admin() ?? zoneSave(body()),
        'delete_zone'     => admin() ?? zoneDelete(body()),
        'save_user'       => admin() ?? userSave(body()),
        'delete_user'     => admin() ?? userDelete(body()),
        'run_now'         => admin() ?? runNow(body()),
        'change_password' => changePassword(body()),
        default           => throw new InvalidArgumentException('Unbekannte Aktion'),
    };
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
} catch (HttpError $e) {
    http_response_code($e->getCode());
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('netmon api: ' . $e->getMessage());
    echo json_encode(['error' => 'Serverfehler, Details im Apache-Log'], JSON_UNESCAPED_UNICODE);
}

// ---------------------------------------------------------------

// Gibt null zurück, wenn der Benutzer Admin ist, sonst Abbruch mit 403
function admin(): ?array
{
    if (!is_admin()) {
        throw new HttpError('Nur für Admins', 403);
    }
    return null;
}

function family(): ?array
{
    if (!can_switch()) {
        throw new HttpError('Dein Konto darf nur ansehen – zum Sperren bitte den Admin fragen', 403);
    }
    return null;
}

function body(): array
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new InvalidArgumentException('POST erwartet');
    }
    if (!hash_equals(csrf_token(), (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        throw new HttpError('Sitzung abgelaufen, bitte Seite neu laden', 403);
    }
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Ungültige Daten');
    }
    return $data;
}

function displayName(array $d): string
{
    foreach (['name', 'hostname', 'vendor', 'mac'] as $k) {
        if (!empty($d[$k])) {
            return (string) $d[$k];
        }
    }
    return 'unbekannt';
}

// Kopfzeile: letzter Scan, letzter Speedtest, Anzahl online
function status(): array
{
    $db = db();
    $scan  = $db->query('SELECT started_at, hosts_up, duration_ms FROM scans ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    $speed = $db->query('SELECT created_at, download_mbps, upload_mbps, ping_ms, error FROM speedtests ORDER BY created_at DESC, id DESC LIMIT 1')->fetch() ?: null;
    $cnt   = $db->query('SELECT SUM(is_online) AS online, COUNT(*) AS total FROM devices WHERE is_virtual = 0')->fetch();
    $new24 = (int) $db->query("SELECT COUNT(*) FROM device_events WHERE event = 'neu' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
    return [
        'scan'   => $scan,
        'speed'  => $speed,
        'online' => (int) $cnt['online'],
        'total'  => (int) $cnt['total'],
        'new24h' => $new24,
        'subnet' => settings()['subnet'],
        'speedtestEnabled' => (bool) settings()['speedtest_enabled'],
        'animations'       => (bool) settings()['animations'],
        'user'   => ['name' => current_user()['username'], 'role' => current_user()['role']],
    ];
}

// Baum: Router -> Switches -> Bereiche -> Geräte, als flache Liste mit parent
function tree(): array
{
    $db = db();
    $devs  = $db->query(
        'SELECT id, mac, ip AS ipn, INET_NTOA(ip) AS ip, hostname, vendor, name, location, device_type, parent_id, port,
                is_private_mac, is_virtual, is_online, missed_scans, last_seen, sw_uplink,
                TIMESTAMPDIFF(HOUR, last_seen, NOW()) AS off_hours
           FROM devices ORDER BY ipn IS NULL, ipn, name'
    )->fetchAll();
    $ports = switchPorts();
    $wifi = wifiByMac();
    $hideDays = (int) settings()['hide_offline_days'];
    $typeOf = array_column($devs, 'device_type', 'id');
    $zones = $db->query('SELECT id, name, ip_from, ip_to, zone_type, parent_device_id FROM zones ORDER BY sort, ip_from')->fetchAll();

    // Wurzel: erster Router, sonst ein virtueller Knoten
    $rootId = null;
    foreach ($devs as $d) {
        if ($d['device_type'] === 'router') {
            $rootId = 'd' . $d['id'];
            break;
        }
    }
    $nodes = [];
    if ($rootId === null) {
        $rootId = 'root';
        $nodes['root'] = ['id' => 'root', 'parent' => null, 'kind' => 'infra', 'label' => settings()['subnet'], 'online' => true];
    }

    foreach ($zones as $z) {
        $nodes['z' . $z['id']] = [
            'id'       => 'z' . $z['id'],
            'parent'   => $z['parent_device_id'] ? 'd' . $z['parent_device_id'] : $rootId,
            'kind'     => 'zone',
            'label'    => $z['name'],
            'zoneType' => $z['zone_type'],
            'range'    => long2ip((int) $z['ip_from']) . ' – ' . long2ip((int) $z['ip_to']),
        ];
    }

    foreach ($devs as $d) {
        $id = 'd' . $d['id'];
        $w = $d['mac'] ? ($wifi[$d['mac']] ?? null) : null;
        // WLAN-Geräte, die nur an Router/AP „geparkt“ oder gar nicht zugeordnet sind, hängen am AP, an dem sie gerade angemeldet sind
        $wifiAuto = $w && isset($typeOf[$w['apId']]) && $w['apId'] !== (int) $d['id']
            && (!$d['parent_id'] || in_array($typeOf[$d['parent_id']] ?? '', ['router', 'ap'], true));
        if ($id === $rootId) {
            $parent = null;
        } elseif ($wifiAuto && ($typeOf[$w['apId']] ?? '') === 'router') {
            // WLAN des Routers als eigener, zuklappbarer Zweig unter dem Router
            $parent = 'w' . $w['apId'];
            $nodes[$parent] ??= ['id' => $parent, 'parent' => 'd' . $w['apId'], 'kind' => 'zone', 'zoneType' => 'wlan',
                                 'label' => 'WLAN', 'range' => 'Geräte im WLAN des Routers'];
        } elseif ($wifiAuto) {
            $parent = 'd' . $w['apId'];
        } elseif ($d['parent_id']) {
            $parent = 'd' . $d['parent_id'];
        } else {
            $parent = $rootId;
            foreach ($d['ipn'] === null ? [] : $zones as $z) {
                if ((int) $d['ipn'] >= (int) $z['ip_from'] && (int) $d['ipn'] <= (int) $z['ip_to']) {
                    $parent = 'z' . $z['id'];
                    break;
                }
            }
        }
        $nodes[$id] = [
            'id'        => $id,
            'dbId'      => (int) $d['id'],
            'parent'    => $parent,
            'kind'      => in_array($d['device_type'], INFRA_TYPES, true) ? 'infra' : 'device',
            'label'     => displayName($d),
            'ip'        => $d['ip'],
            'mac'       => $d['mac'],
            'vendor'    => $d['vendor'],
            'hostname'  => $d['hostname'],
            'location'  => $d['location'],
            'type'      => $d['device_type'],
            'port'      => $d['port'],
            'online'    => (bool) $d['is_online'],
            'missed'    => (int) $d['missed_scans'],
            'private'   => (bool) $d['is_private_mac'],
            'virtual'   => (bool) $d['is_virtual'],
            'lastSeen'  => $d['last_seen'],
            'stale'     => $hideDays > 0 && !$d['is_online'] && !$d['is_virtual'] && (int) $d['off_hours'] >= $hideDays * 24,
            'wifi'      => $w,
            'wifiAuto'  => $wifiAuto,
            'ports'     => $ports[(int) $d['id']] ?? null,
            'uplink'    => $d['sw_uplink'] !== null ? (int) $d['sw_uplink'] : null,
        ];
    }

    // Verweise auf nicht vorhandene Eltern und Zyklen auflösen -> an die Wurzel hängen
    foreach ($nodes as $id => $n) {
        if ($n['parent'] !== null && !isset($nodes[$n['parent']])) {
            $nodes[$id]['parent'] = $rootId;
        }
    }
    foreach (array_keys($nodes) as $id) {
        $seen = [$id => true];
        $p = $nodes[$id]['parent'];
        while ($p !== null) {
            if (isset($seen[$p])) {
                $nodes[$id]['parent'] = $id === $rootId ? null : $rootId;
                break;
            }
            $seen[$p] = true;
            $p = $nodes[$p]['parent'] ?? null;
        }
    }

    $layout = json_decode((string) settings()['room_layout'], true);
    return ['root' => $rootId, 'nodes' => array_values($nodes), 'hideDays' => $hideDays, 'roomLayout' => is_array($layout) ? $layout : (object) []];
}

// WLAN-Clients aller APs: MAC => Zeile (leer, solange die Tabelle fehlt)
function wifiByMac(): array
{
    $out = [];
    try {
        foreach (db()->query('SELECT mac, ap_id, band, guest, signal_dbm, tx_kbps, rx_kbps, online_s, host, updated FROM wifi_clients') as $r) {
            $out[$r['mac']] = [
                'apId' => (int) $r['ap_id'], 'band' => $r['band'], 'guest' => (bool) $r['guest'],
                'signal' => $r['signal_dbm'] !== null ? (int) $r['signal_dbm'] : null,
                'tx' => $r['tx_kbps'] !== null ? (int) $r['tx_kbps'] : null, 'rx' => $r['rx_kbps'] !== null ? (int) $r['rx_kbps'] : null,
                'online' => $r['online_s'] !== null ? (int) $r['online_s'] : null, 'host' => $r['host'], 'updated' => $r['updated'],
            ];
        }
    } catch (PDOException $e) {
    }
    return $out;
}

// Port-Status aller abgefragten Switches: device_id => Liste
function switchPorts(): array
{
    $out = [];
    try {
        $rows = db()->query('SELECT device_id, port, link, admin_off, speed, descr, rx_bps, tx_bps, crc, updated, cable_result, cable_dist, cable_at FROM switch_ports ORDER BY device_id, port')->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    foreach ($rows as $r) {
        $out[(int) $r['device_id']][] = [
            'p' => (int) $r['port'], 'link' => (bool) $r['link'], 'off' => (bool) ($r['admin_off'] ?? 0), 'speed' => $r['speed'],
            'rx' => $r['rx_bps'] !== null ? (int) $r['rx_bps'] : null, 'tx' => $r['tx_bps'] !== null ? (int) $r['tx_bps'] : null,
            'crc' => $r['crc'] !== null ? (int) $r['crc'] : null, 'updated' => $r['updated'], 'desc' => $r['descr'],
            'cable' => $r['cable_result'] !== null ? ['result' => $r['cable_result'], 'dist' => $r['cable_dist'], 'at' => $r['cable_at']] : null,
        ];
    }
    return $out;
}

// Speedtest-Verlauf
function speed(string $range): array
{
    $db = db();
    $days = match ($range) {
        '24h'  => 1,
        '7d'   => 7,
        '30d'  => 30,
        '365d' => 365,
        default => throw new InvalidArgumentException('Ungültiger Zeitraum'),
    };

    if ($days > 30) {
        // Langzeit: verdichtete Tageswerte plus Tagesmittel der Rohdaten
        $st = $db->prepare(
            "SELECT t, down, up, ping FROM (
               SELECT day AS t, down_avg AS down, up_avg AS up, ping_avg AS ping
                 FROM speedtests_daily WHERE day >= CURDATE() - INTERVAL ? DAY
               UNION ALL
               SELECT DATE(created_at), AVG(download_mbps), AVG(upload_mbps), AVG(ping_ms)
                 FROM speedtests WHERE error IS NULL AND created_at >= CURDATE() - INTERVAL ? DAY
                GROUP BY DATE(created_at)
             ) x ORDER BY t"
        );
        $st->execute([$days, $days]);
        $rows = $st->fetchAll();
        $points = array_map(fn($r) => [
            't' => (string) $r['t'], 'down' => num($r['down']), 'up' => num($r['up']), 'ping' => num($r['ping']), 'error' => null,
        ], $rows);
        $daily = true;
    } else {
        $st = $db->prepare(
            'SELECT created_at, download_mbps, upload_mbps, ping_ms, error
               FROM speedtests WHERE created_at >= NOW() - INTERVAL ? DAY ORDER BY created_at'
        );
        $st->execute([$days]);
        $points = array_map(fn($r) => [
            't' => $r['created_at'], 'down' => num($r['download_mbps']), 'up' => num($r['upload_mbps']),
            'ping' => num($r['ping_ms']), 'error' => $r['error'],
        ], $st->fetchAll());
        $daily = false;
    }

    $ok = array_filter($points, fn($p) => $p['down'] !== null);
    $stat = fn(string $k, callable $fn) => $ok ? round($fn(array_column($ok, $k)), 1) : null;

    return [
        'range'  => $range,
        'daily'  => $daily,
        'points' => array_values($points),
        'errors' => count($points) - count($ok),
        'stats'  => [
            'down' => ['avg' => $stat('down', fn($a) => array_sum($a) / count($a)), 'min' => $stat('down', 'min'), 'max' => $stat('down', 'max')],
            'up'   => ['avg' => $stat('up',   fn($a) => array_sum($a) / count($a)), 'min' => $stat('up', 'min'),   'max' => $stat('up', 'max')],
            'ping' => ['avg' => $stat('ping', fn($a) => array_sum($a) / count($a)), 'min' => $stat('ping', 'min'), 'max' => $stat('ping', 'max')],
        ],
    ];
}

function num(mixed $v): ?float
{
    return $v === null ? null : round((float) $v, 2);
}

// Letzte Statuswechsel
function events(): array
{
    $rows = db()->query(
        'SELECT e.created_at, e.event, INET_NTOA(e.old_ip) AS old_ip, INET_NTOA(e.new_ip) AS new_ip, e.info,
                d.id, d.name, d.location, d.hostname, d.vendor, d.mac
           FROM device_events e JOIN devices d ON d.id = e.device_id
          ORDER BY e.id DESC LIMIT 1000'
    )->fetchAll();
    return array_map(fn($r) => [
        't'      => $r['created_at'],
        'event'  => $r['event'],
        'oldIp'  => $r['old_ip'],
        'newIp'  => $r['new_ip'],
        'device' => displayName($r),
        'location' => $r['location'],
        'info'   => $r['info'],
        'vendor' => $r['vendor'],
        'mac'    => $r['mac'],
        'search' => mb_strtolower(implode(' ', array_filter([$r['name'], $r['location'], $r['hostname'], $r['vendor'], $r['mac'], $r['old_ip'], $r['new_ip'], $r['info']]))),
        'dbId'   => (int) $r['id'],
    ], $rows);
}

// Geräteliste zum Bearbeiten
function devices(): array
{
    $rows = db()->query(
        'SELECT id, mac, INET_NTOA(ip) AS ip, ip AS ipn, hostname, vendor, name, location, device_type, parent_id, port, notes,
                is_private_mac, is_virtual, is_online, first_seen, last_seen, sw_uplink, sw_driver, sw_user, sw_info, COALESCE(LENGTH(sw_pass), 0) > 0 AS has_sw_pass, COALESCE(LENGTH(sw_pass) > 0 AND sw_pass <> CHAR(45 USING utf8mb4), 0) AS has_real_pass
           FROM devices ORDER BY ipn IS NULL, ipn, name'
    )->fetchAll();
    $ports = switchPorts();
    $wifi = wifiByMac();
    $byMac = [];
    foreach ($rows as $r) {
        if ($r['mac']) {
            $byMac[$r['mac']] = $r;
        }
    }
    $clientsOf = [];
    foreach ($wifi as $mac => $w) {
        $dev = $byMac[$mac] ?? null;
        $named = $dev && ($dev['name'] || $dev['hostname']);
        $clientsOf[$w['apId']][] = ['mac' => $mac, 'id' => $dev ? (int) $dev['id'] : null,
                                    'name' => $named ? displayName($dev) : ($w['host'] ?: ($dev ? displayName($dev) : $mac)),
                                    'location' => $dev['location'] ?? null] + $w;
    }
    foreach ($clientsOf as &$list) {
        usort($list, fn($a, $b) => ($b['signal'] ?? -999) <=> ($a['signal'] ?? -999));
    }
    unset($list);
    // Welches Gerät steckt an welchem Port? Aus „Hängt an“ + „Port“ der Geräte
    $onPort = [];
    foreach ($rows as $r) {
        if ($r['parent_id'] !== null && preg_match('/^\d+$/', (string) $r['port'])) {
            $onPort[(int) $r['parent_id']][(int) $r['port']][] = displayName($r);
        }
    }
    $parents = [];
    foreach ($rows as $r) {
        if (in_array($r['device_type'], INFRA_TYPES, true)) {
            $parents[] = ['id' => (int) $r['id'], 'label' => displayName($r) . ' (' . ($r['ip'] ?? 'ohne IP') . ')'];
        }
    }
    $locations = array_values(array_unique(array_filter(array_map(fn($r) => $r['location'], $rows))));
    natcasesort($locations);
    return [
        'dismissed' => json_decode((string) settings()['sug_dismissed'], true) ?: [],
        'quickPorts' => array_map(fn($x) => $x[0] . ':' . $x[1], quickList()),
        'protectedPorts' => (function () { require_once NETMON_DIR . '/collector/switch_drivers.php'; return (object) switch_protected_ports(db()); })(),
        'types'     => DEVICE_TYPES,
        'parents'   => $parents,
        'locations' => array_values($locations),
        'devices' => array_map(fn($r) => [
            'id'        => (int) $r['id'],
            'mac'       => $r['mac'],
            'ip'        => $r['ip'],
            'ipn'       => (int) $r['ipn'],
            'hostname'  => $r['hostname'],
            'vendor'    => $r['vendor'],
            'name'      => $r['name'],
            'location'  => $r['location'],
            'type'      => $r['device_type'],
            'parentId'  => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'port'      => $r['port'],
            'notes'     => $r['notes'],
            'private'   => (bool) $r['is_private_mac'],
            'virtual'   => (bool) $r['is_virtual'],
            'swPass'    => (bool) $r['has_sw_pass'],
            'swRealPass'=> (bool) $r['has_real_pass'],
            'wifi'      => $r['mac'] ? ($wifi[$r['mac']] ?? null) : null,
            'wifiClients' => $clientsOf[(int) $r['id']] ?? null,
            'uplink'    => $r['sw_uplink'] !== null ? (int) $r['sw_uplink'] : null,
            'driver'    => $r['sw_driver'],
            'swUser'    => $r['sw_user'],
            'swInfo'    => $r['sw_info'] ? json_decode($r['sw_info'], true) : null,
            'ports'     => $ports[(int) $r['id']] ?? null,
            'onPort'    => $onPort[(int) $r['id']] ?? (object) [],
            'online'    => (bool) $r['is_online'],
            'firstSeen' => $r['first_seen'],
            'lastSeen'  => $r['last_seen'],
        ], $rows),
    ];
}

function saveDevice(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Gerät fehlt');
    }
    $clean = fn($v, int $max) => ($v = trim((string) ($v ?? ''))) === '' ? null : mb_substr($v, 0, $max);

    $type = $in['type'] ?? null;
    if ($type === '' || $type === null) {
        $type = null;
    } elseif (!in_array($type, DEVICE_TYPES, true)) {
        throw new InvalidArgumentException('Ungültiger Gerätetyp');
    }

    $parent = $in['parentId'] ?? null;
    $parent = ($parent === '' || $parent === null) ? null : (int) $parent;
    if ($parent === $id) {
        throw new InvalidArgumentException('Ein Gerät kann nicht an sich selbst hängen');
    }

    // Switch-Zugang: leeres Feld = unverändert, „-“ = löschen
    if (array_key_exists('swUplink', $in)) {
        $u = trim((string) $in['swUplink']);
        if ($u !== '' && (!ctype_digit($u) || (int) $u < 1 || (int) $u > 52)) {
            throw new InvalidArgumentException('Uplink-Port: Zahl von 1 bis 52');
        }
        db()->prepare('UPDATE devices SET sw_uplink = ? WHERE id = ?')->execute([$u === '' ? null : (int) $u, $id]);
    }
    if (array_key_exists('swDriver', $in) && $in['swDriver'] !== 'auto') {
        db()->prepare('UPDATE devices SET sw_driver = ? WHERE id = ?')->execute([$in['swDriver'] === '' ? null : mb_substr((string) $in['swDriver'], 0, 32), $id]);
    }
    if (array_key_exists('swUser', $in)) {
        db()->prepare('UPDATE devices SET sw_user = ? WHERE id = ?')->execute([$clean($in['swUser'], 32), $id]);
    }
    $pw = (string) ($in['swPass'] ?? '');
    if ($pw === '-') {
        db()->prepare('UPDATE devices SET sw_pass = NULL, sw_info = NULL WHERE id = ?')->execute([$id]);
        db()->prepare('DELETE FROM switch_ports WHERE device_id = ?')->execute([$id]);
    } elseif ($pw !== '') {
        db()->prepare('UPDATE devices SET sw_pass = ? WHERE id = ?')->execute([mb_substr($pw, 0, 64), $id]);
    }

    $st = db()->prepare('UPDATE devices SET name = ?, location = ?, device_type = ?, parent_id = ?, port = ?, notes = ? WHERE id = ?');
    $st->execute([$clean($in['name'] ?? null, 64), $clean($in['location'] ?? null, 64), $type, $parent, $clean($in['port'] ?? null, 16), $clean($in['notes'] ?? null, 2000), $id]);
    return ['ok' => true];
}

// Gerät ohne IP von Hand anlegen (unmanaged Switch, geplanter AP, Netzwerkdose …)
function createDevice(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('Bitte einen Namen angeben');
    }
    $db = db();
    $db->prepare("INSERT INTO devices (mac, ip, name, is_virtual, is_online, first_seen, last_seen) VALUES (NULL, NULL, ?, 1, 0, NOW(), NOW())")
       ->execute([mb_substr($name, 0, 64)]);
    $id = (int) $db->lastInsertId();
    saveDevice(['id' => $id] + $in);
    return ['ok' => true, 'id' => $id];
}

// Positionen der Raumkarten (Grundriss), für alle Benutzer gleich
function saveRoomLayout(array $in): array
{
    $layout = [];
    foreach (($in['layout'] ?? []) as $room => $pos) {
        $room = mb_substr(trim((string) $room), 0, 64);
        if ($room === '' || !is_array($pos) || count($layout) >= 200) {
            continue;
        }
        $layout[$room] = [max(0, min(6000, (int) ($pos[0] ?? 0))), max(0, min(6000, (int) ($pos[1] ?? 0)))];
    }
    netmon_set(db(), 'room_layout', $layout ? json_encode($layout, JSON_UNESCAPED_UNICODE) : '');
    return ['ok' => true];
}

// Verbindungsvorschlag übernehmen: Kind hängt an Elternteil (Port), optional mit Uplink-Port des Kindes
function connectDevices(array $in): array
{
    $child = (int) ($in['child'] ?? 0);
    $parent = (int) ($in['parent'] ?? 0);
    $port = trim((string) ($in['port'] ?? ''));
    $uplink = trim((string) ($in['uplink'] ?? ''));
    if ($child <= 0 || $parent <= 0 || $child === $parent) {
        throw new InvalidArgumentException('Ungültige Geräte');
    }
    foreach ([$port, $uplink] as $p) {
        if ($p !== '' && (!ctype_digit($p) || (int) $p < 1 || (int) $p > 52)) {
            throw new InvalidArgumentException('Ungültiger Port');
        }
    }
    // Hängt das neue Elternteil bisher (auch über Umwege) unter dem Kind, wäre das eine Schleife. Die neue Angabe gilt:
    // den Zweig, über den das Elternteil unter dem Kind hängt, an die bisherige Stelle des Kindes umhängen
    // (typisch: der Router war versehentlich unter einen Switch gehängt worden).
    $st = db()->prepare('SELECT parent_id FROM devices WHERE id = ?');
    $moved = null;
    for ($p = $parent, $prev = null, $n = 0; $p && $n < 50; $n++) {
        if ($p === $child) {
            $st->execute([$child]);
            $up = $st->fetchColumn() ?: null;
            db()->prepare('UPDATE devices SET parent_id = ?, port = NULL WHERE id = ?')->execute([$up, $prev]);
            $moved = $prev;
            break;
        }
        $st->execute([$p]);
        [$prev, $p] = [$p, (int) $st->fetchColumn()];
    }
    db()->prepare('UPDATE devices SET parent_id = ?, port = ? WHERE id = ?')->execute([$parent, $port === '' ? null : $port, $child]);
    if ($uplink !== '') {
        db()->prepare('UPDATE devices SET sw_uplink = ? WHERE id = ?')->execute([(int) $uplink, $child]);
    }
    return ['ok' => true, 'moved' => $moved];
}

// Vorschlag ignorieren (oder wieder zulassen); Schlüssel enthält die Beschreibung, eine Änderung macht ihn neu
function dismissSuggestion(array $in): array
{
    $key = (string) ($in['key'] ?? '');
    if (!preg_match('/^\d+:\d+:[\p{L}\p{N}]{1,64}:\d+$/u', $key)) {
        throw new InvalidArgumentException('Ungültiger Vorschlag');
    }
    $list = json_decode((string) settings(true)['sug_dismissed'], true) ?: [];
    $list = array_values(array_diff($list, [$key]));
    if (empty($in['undo'])) {
        $list[] = $key;
    }
    $list = array_slice($list, -300);
    netmon_set(db(), 'sug_dismissed', json_encode($list, JSON_UNESCAPED_UNICODE));
    return ['ok' => true, 'dismissed' => $list];
}

function switchDriverList(): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    return switch_driver_list();
}

// Switch sofort abfragen (Knopf „Ports abfragen“); mit neuem Passwort wird es erst nach Erfolg gespeichert
function switchDevice(int $id): array
{
    $st = db()->prepare('SELECT id, INET_NTOA(ip) AS ip, sw_pass, sw_user, sw_driver, sw_uplink FROM devices WHERE id = ? AND ip IS NOT NULL AND is_virtual = 0');
    $st->execute([$id]);
    $dev = $st->fetch();
    if (!$dev) {
        throw new InvalidArgumentException('Gerät ohne IP kann nicht abgefragt werden');
    }
    return $dev;
}

// „Verbinden & abfragen“: Treiber wählen oder erkennen, anmelden, Ports + Info lesen; Zugang erst nach Erfolg speichern
function switchPoll(array $in): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $dev = switchDevice((int) ($in['id'] ?? 0));
    $pass = (string) ($in['swPass'] ?? '');
    $driver = (string) ($in['swDriver'] ?? '');
    if ($driver === '' || $driver === 'auto') {
        $driver = switch_detect($dev['ip']);
        if ($driver === null) {
            throw new InvalidArgumentException('Für dieses Gerät gibt es noch keinen passenden Treiber. Mit „Analyse-Paket“ kannst du die Seiten für einen neuen Treiber einsammeln.');
        }
    } elseif (!isset(SWITCH_DRIVERS[$driver])) {
        throw new InvalidArgumentException('Unbekannter Treiber');
    } elseif (!(switch_driver_class($driver))::detect($dev['ip']) && ($found = switch_detect($dev['ip'])) !== null) {
        $driver = $found;   // gewählter Treiber passt nicht zum Gerät, ein anderer schon (z. B. neue Netgear-Oberfläche)
    }
    $dev['sw_driver'] = $driver;
    if ($pass === '' && !$dev['sw_pass']) {
        if ((switch_driver_class($driver))::NEEDS_PASS) {
            throw new InvalidArgumentException('Bitte das Passwort der Weboberfläche eintragen');
        }
        $pass = '-';   // Gerät ohne Anmeldung (Speedport): Platzhalter, damit es regelmäßig abgefragt wird
    }
    $user = trim((string) ($in['swUser'] ?? ''));
    if ($user !== '') {
        $dev['sw_user'] = mb_substr($user, 0, 32);
    }
    try {
        $ports = switch_poll(db(), $dev, $pass !== '' ? $pass : null, true);
    } catch (SwitchParseError $e) {
        // Anmeldung hat geklappt, nur der Seitenaufbau ist unbekannt: Zugang trotzdem merken, damit das Analyse-Paket alle Seiten holen kann
        db()->prepare('UPDATE devices SET sw_driver = ?, sw_pass = COALESCE(?, sw_pass), sw_user = COALESCE(?, sw_user) WHERE id = ?')
           ->execute([$driver, $pass !== '' ? mb_substr($pass, 0, 64) : null, $user !== '' ? $dev['sw_user'] : null, $dev['id']]);
        throw new InvalidArgumentException($e->getMessage() . ' – Zugang ist gespeichert, das Analyse-Paket enthält jetzt alle Seiten.');
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage());
    }
    db()->prepare('UPDATE devices SET sw_driver = ?, sw_pass = COALESCE(?, sw_pass), sw_user = COALESCE(?, sw_user) WHERE id = ?')
       ->execute([$driver, $pass !== '' ? mb_substr($pass, 0, 64) : null, $user !== '' ? $dev['sw_user'] : null, $dev['id']]);
    $st = db()->prepare('SELECT COUNT(*) FROM wifi_clients WHERE ap_id = ?');
    $st->execute([$dev['id']]);
    $n = (int) $st->fetchColumn();
    $clients = $driver === 'tplink_archer' || (!$ports && $n) ? $n : null;
    return ['ok' => true, 'driver' => $driver, 'ports' => count($ports), 'up' => count(array_filter($ports, fn($p) => $p['link'])), 'clients' => $clients];
}

// Port sperren / freigeben (Gerät daran ist vom Netz getrennt; Uplink nie)
function switchPortSet(array $in): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $dev = switchDevice((int) ($in['id'] ?? 0));
    $port = (int) ($in['port'] ?? 0);
    $on = !empty($in['on']);
    if ($port < 1 || $port > 52) {
        throw new InvalidArgumentException('Ungültiger Port');
    }
    if (!$on && $dev['sw_uplink'] !== null && (int) $dev['sw_uplink'] === $port) {
        throw new InvalidArgumentException('Der Uplink-Port wird nicht abgeschaltet – sonst wäre der Switch nicht mehr erreichbar');
    }
    if (!$dev['sw_pass'] || !$dev['sw_driver']) {
        throw new InvalidArgumentException('Erst Treiber und Passwort einrichten');
    }
    if (!switch_can_portoff(switch_driver_class($dev['sw_driver']))) {
        throw new InvalidArgumentException('Dieser Switch kann Ports nicht abschalten');
    }
    try {
        switch_port_enable(db(), $dev, $port, $on);
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage());
    }
    return ['ok' => true];
}

// Schnellzugriff (Handy): angeheftete Ports mit Zustand, Status in Kurzform, letzte Ereignisse
function quickList(): array
{
    $l = json_decode((string) settings()['quick_ports'], true);
    return is_array($l) ? array_values(array_filter($l, fn($x) => is_array($x) && count($x) === 2)) : [];
}

function quick(): array
{
    $db = db();
    $devs = [];
    foreach ($db->query('SELECT id, INET_NTOA(ip) AS ip, name, hostname, mac, vendor, device_type, parent_id, port, is_online, location,
                                sw_driver, COALESCE(LENGTH(sw_pass), 0) > 0 AS has_pass, is_virtual
                           FROM devices') as $d) {
        $devs[(int) $d['id']] = $d;
    }
    $ports = switchPorts();
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $guard = switch_protected_ports($db);

    // Wer hängt woran: Kabel (parent_id) und WLAN (Client-Liste des Access Points)
    $kids = [];
    foreach ($devs as $d) {
        if ($d['parent_id'] !== null) {
            $kids[(int) $d['parent_id']][] = (int) $d['id'];
        }
    }
    $byMac = [];
    foreach ($devs as $d) {
        if ($d['mac']) {
            $byMac[strtoupper($d['mac'])] = (int) $d['id'];
        }
    }
    $wifiOf = [];   // AP-ID => Geräte-IDs im WLAN
    $apOf = [];     // Geräte-ID => AP-ID
    foreach (wifiByMac() as $mac => $w) {
        $id = $byMac[strtoupper($mac)] ?? null;
        if ($id !== null && isset($devs[$w['apId']]) && !isset($kids[$id]) && $devs[$id]['parent_id'] === null) {
            $wifiOf[$w['apId']][] = $id;
            $apOf[$id] = $w['apId'];
        }
    }
    $below = function (array $start) use ($kids, $wifiOf): array {
        $seen = [];
        $stack = $start;
        while ($stack) {
            $x = array_pop($stack);
            if (isset($seen[$x])) {
                continue;
            }
            $seen[$x] = true;
            array_push($stack, ...($kids[$x] ?? []), ...($wifiOf[$x] ?? []));
        }
        return array_keys($seen);
    };

    // Welches Gerät benutzt gerade netmon? (nur im Heimnetz erkennbar)
    $me = null;
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    foreach ($devs as $d) {
        if ($ip !== '' && $d['ip'] === $ip && !in_array($ip, switch_own_ips_safe(), true)) {
            $me = (int) $d['id'];
            break;
        }
    }
    $via = null;
    if ($me !== null) {
        $d = $devs[$me];
        if (isset($apOf[$me])) {
            $via = 'WLAN von ' . displayName($devs[$apOf[$me]]);
        } elseif ($d['parent_id'] !== null && isset($devs[(int) $d['parent_id']])) {
            $via = 'Kabel an ' . displayName($devs[(int) $d['parent_id']]) . ($d['port'] !== null && $d['port'] !== '' ? ' (Anschluss ' . $d['port'] . ')' : '');
        }
    }
    $where = $me !== null ? ['device' => displayName($devs[$me]), 'via' => $via]
        : ['device' => null, 'via' => null, 'remote' => !preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)];

    $ageOf = [];   // Sekunden seit der letzten Abfrage je Port (in der Datenbank gerechnet – PHP und MariaDB können verschiedene Zeitzonen haben)
    foreach ($db->query('SELECT device_id, port, TIMESTAMPDIFF(SECOND, updated, NOW()) AS age FROM switch_ports') as $r) {
        $ageOf[$r['device_id'] . ':' . $r['port']] = (int) $r['age'];
    }
    $out = [];
    foreach (quickList() as [$id, $p]) {
        $sw = $devs[(int) $id] ?? null;
        if (!$sw) {
            continue;
        }
        $pi = null;
        foreach ($ports[(int) $id] ?? [] as $x) {
            if ($x['p'] === (int) $p) {
                $pi = $x;
            }
        }
        $direct = [];
        $who = [];
        foreach ($devs as $d) {
            if ((int) $d['parent_id'] === (int) $id && (string) $d['port'] === (string) $p) {
                $who[] = displayName($d);
                $direct[] = (int) $d['id'];
            }
        }
        $aff = [];
        foreach ($below($direct) as $x) {
            if (!$devs[$x]['is_virtual']) {
                $aff[] = ['name' => displayName($devs[$x]), 'online' => (bool) $devs[$x]['is_online'], 'me' => $x === $me];
            }
        }
        usort($aff, fn($a, $b) => [$b['me'], $b['online'], $a['name']] <=> [$a['me'], $a['online'], $b['name']]);
        // Geht das Schalten überhaupt? Gründe in Alltagssprache
        $why = null;
        if (!$sw['has_pass'] || !$sw['sw_driver']) {
            $why = 'Der Switch „' . displayName($sw) . '“ ist in netmon noch nicht eingerichtet (Passwort fehlt).';
        } else {
            try {
                if (!switch_can_portoff(switch_driver_class($sw['sw_driver']))) {
                    $why = 'Der Switch „' . displayName($sw) . '“ kann Anschlüsse nicht abschalten.';
                }
            } catch (RuntimeException $e) {
                $why = 'Unbekannter Switch-Typ.';
            }
        }
        $stale = $pi === null || ($ageOf[$id . ':' . $p] ?? 0) > 600;
        $out[] = ['id' => (int) $id, 'p' => (int) $p, 'switch' => displayName($sw), 'location' => $sw['location'],
                  'label' => ($pi['desc'] ?? '') !== '' ? $pi['desc'] : ($who ? implode(', ', $who) : 'Anschluss ' . $p),
                  'desc' => $pi['desc'] ?? null, 'who' => $who, 'link' => $pi['link'] ?? false, 'off' => $pi['off'] ?? false,
                  'speed' => $pi['speed'] ?? null, 'updated' => $pi['updated'] ?? null, 'guard' => $guard[$id . ':' . $p] ?? null,
                  'affected' => $aff, 'onlineCount' => count(array_filter($aff, fn($a) => $a['online'])),
                  'self' => in_array(true, array_column($aff, 'me'), true), 'cannot' => $why, 'stale' => $stale];
    }
    // Router und Access Points mit schaltbaren Funktionen (Speedport, TP-Link Archer …): WLAN, Gäste-WLAN, neu verbinden
    $routers = [];
    foreach ($db->query("SELECT id, sw_driver, sw_pass, sw_info FROM devices WHERE sw_driver IS NOT NULL AND sw_pass IS NOT NULL AND sw_pass <> ''") as $r) {
        try {
            $acts = (switch_driver_class($r['sw_driver']))::ACTIONS;
        } catch (Throwable $e) {
            continue;
        }
        if (!$acts) {
            continue;
        }
        $rid = (int) $r['id'];
        $m = [];
        $st = $db->prepare("SELECT k, v FROM dev_metrics WHERE device_id = ? AND k IN ('wlan_on', 'guest_on') AND ts > NOW() - INTERVAL 15 MINUTE ORDER BY ts");
        $st->execute([$rid]);
        foreach ($st as $x) {
            $m[$x['k']] = (int) $x['v'];   // der jüngste Wert gewinnt
        }
        $info = json_decode((string) $r['sw_info'], true) ?: [];
        // betroffen beim WLAN-Ausschalten: alle WLAN-Geräte – und alles, was dahinter hängt (z. B. Repeater mit seinen Geräten)
        $wl = [];
        foreach (array_diff($below($wifiOf[$rid] ?? []), [$rid]) as $x) {
            if (!$devs[$x]['is_virtual']) {
                $wl[] = ['name' => displayName($devs[$x]), 'online' => (bool) $devs[$x]['is_online'], 'me' => $x === $me];
            }
        }
        usort($wl, fn($a, $b) => [$b['me'], $b['online'], $a['name']] <=> [$a['me'], $a['online'], $b['name']]);
        $routers[] = ['id' => $rid, 'name' => displayName($devs[$rid]), 'model' => $info['model'] ?? null, 'type' => $devs[$rid]['device_type'],
                      'location' => $devs[$rid]['location'],
                      'canAct' => !in_array($r['sw_pass'], ['', '-'], true), 'actions' => array_keys($acts),
                      'wlan' => $m['wlan_on'] ?? null, 'guest' => $m['guest_on'] ?? null,
                      'ssid' => $info['wlan']['ssid'] ?? null, 'guestSsid' => $info['wlan']['guestSsid'] ?? null,
                      'wifiAffected' => $wl, 'self' => in_array(true, array_column($wl, 'me'), true)];
    }

    $ledDevs = 0;
    foreach ($db->query("SELECT sw_driver FROM devices WHERE sw_pass IS NOT NULL AND sw_pass <> '' AND sw_driver IS NOT NULL") as $r) {
        try {
            $ledDevs += switch_can_leds(switch_driver_class($r['sw_driver'])) ? 1 : 0;
        } catch (RuntimeException $e) {
        }
    }
    $s = settings();
    $ev = $db->query("SELECT e.device_id, e.event, e.info, e.created_at, COALESCE(d.name, d.hostname, INET_NTOA(d.ip)) AS label, d.device_type
                        FROM device_events e LEFT JOIN devices d ON d.id = e.device_id ORDER BY e.created_at DESC, e.id DESC LIMIT 60")->fetchAll();
    // Für die einfache Ansicht: nur Sperren/Freigeben und Ausfälle von Router, Switches, Access Points – in Alltagssprache
    $labelOf = [];
    foreach ($out as $q) {
        $labelOf[$q['id'] . ':' . $q['p']] = $q['label'];
    }
    $simple = [];
    foreach ($ev as $e) {
        if ($e['event'] === 'port' && preg_match('/^Port (\d+) (gesperrt|freigegeben)$/', (string) $e['info'], $m)) {
            $what = $labelOf[$e['device_id'] . ':' . $m[1]] ?? ($e['label'] . ', Anschluss ' . $m[1]);
            $simple[] = ['t' => $e['created_at'], 'text' => "„{$what}“ {$m[2]}", 'kind' => $m[2] === 'gesperrt' ? 'off' : 'on'];
        } elseif ($e['event'] === 'port' && in_array($e['device_type'], ['router', 'ap'], true) && !str_starts_with((string) $e['info'], 'Port ')) {
            $simple[] = ['t' => $e['created_at'], 'text' => $e['label'] . ': ' . $e['info'], 'kind' => preg_match('/aus|neu/', (string) $e['info']) ? 'off' : 'on'];
        } elseif (in_array($e['event'], ['offline', 'online'], true) && in_array($e['device_type'], ['router', 'switch', 'ap'], true)) {
            $simple[] = ['t' => $e['created_at'], 'text' => $e['label'] . ($e['event'] === 'offline' ? ' ist ausgefallen' : ' läuft wieder'),
                         'kind' => $e['event'] === 'offline' ? 'bad' : 'on'];
        }
        if (count($simple) >= 6) {
            break;
        }
    }
    return ['ports' => $out, 'routers' => $routers, 'status' => status(), 'internet' => internetState(), 'where' => $where,
            'leds' => ['count' => $ledDevs, 'off' => $s['led_off'], 'on' => $s['led_on']],
            'events' => array_slice($ev, 0, 6), 'simpleEvents' => $simple,
            'admin' => is_admin(), 'canSwitch' => can_switch(), 'user' => current_user()['username']];
}

function switch_own_ips_safe(): array
{
    return function_exists('netmon_own_ips') ? netmon_own_ips() : [];
}

// Ampel für die einfache Ansicht: läuft das Internet?
function internetState(): array
{
    $db = db();
    $s = settings();
    $dsl = null;
    try {
        $r = $db->query("SELECT m.v, m.ts, COALESCE(d.name, d.hostname) AS router FROM dev_metrics m JOIN devices d ON d.id = m.device_id
                          WHERE m.k = 'dsl_ok' AND m.ts > NOW() - INTERVAL 15 MINUTE ORDER BY m.ts DESC LIMIT 1")->fetch();
        if ($r) {
            $dsl = (int) $r['v'];
        }
    } catch (PDOException $e) {
    }
    $sp = $db->query('SELECT created_at, download_mbps, upload_mbps, ping_ms, error, TIMESTAMPDIFF(MINUTE, created_at, NOW()) AS age
                        FROM speedtests ORDER BY created_at DESC, id DESC LIMIT 1')->fetch() ?: null;
    $fresh = $sp && (int) $sp['age'] <= max(30, 3 * (int) $s['speedtest_interval']);
    if ($dsl === 0) {
        return ['state' => 'bad', 'text' => 'Internet gestört', 'detail' => 'Der Router meldet: keine DSL-Verbindung.'];
    }
    if ($fresh && $sp['error']) {
        return ['state' => 'warn', 'text' => 'Internet gestört?', 'detail' => 'Der letzte Geschwindigkeitstest um ' . substr($sp['created_at'], 11, 5) . ' ist fehlgeschlagen.'];
    }
    if ($fresh) {
        $slow = (float) $sp['download_mbps'] < 5;
        return ['state' => $slow ? 'warn' : 'ok', 'text' => $slow ? 'Internet langsam' : 'Internet läuft',
                'detail' => sprintf('%s Mbit/s runter · %s Mbit/s hoch · gemessen %s Uhr', round((float) $sp['download_mbps']),
                                    round((float) $sp['upload_mbps']), substr($sp['created_at'], 11, 5))];
    }
    if ($dsl === 1) {
        return ['state' => 'ok', 'text' => 'Internet läuft', 'detail' => 'Der Router ist mit dem Internet verbunden.'];
    }
    return ['state' => 'unknown', 'text' => 'Internet: keine aktuelle Messung', 'detail' => 'Es gibt keinen aktuellen Geschwindigkeitstest.'];
}

// Port an den Schnellzugriff heften / lösen
function quickPin(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    $p = (int) ($in['port'] ?? 0);
    $list = array_values(array_filter(quickList(), fn($x) => !((int) $x[0] === $id && (int) $x[1] === $p)));
    if (!empty($in['pin']) && $id > 0 && $p > 0) {
        $list[] = [$id, $p];
    }
    netmon_set(db(), 'quick_ports', json_encode(array_slice($list, 0, 20)));
    return ['ok' => true, 'pinned' => array_map(fn($x) => $x[0] . ':' . $x[1], $list)];
}

// Router schalten: WLAN, Gäste-WLAN, neu verbinden (Familie und Admin), neu starten (nur Admin)
function routerAction(array $in): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $dev = switchDevice((int) ($in['id'] ?? 0));
    $key = (string) ($in['act'] ?? '');
    if (!$dev['sw_driver']) {
        throw new InvalidArgumentException('Erst den Treiber einrichten');
    }
    $acts = (switch_driver_class($dev['sw_driver']))::ACTIONS;
    if (!isset($acts[$key])) {
        throw new InvalidArgumentException('Das kann dieses Gerät nicht');
    }
    if ($key === 'reboot' && !is_admin()) {
        throw new HttpError('Neu starten darf nur der Admin', 403);
    }
    if (in_array((string) $dev['sw_pass'], ['', '-'], true)) {
        throw new InvalidArgumentException('Zum Schalten braucht netmon das Gerätepasswort des Routers – Gerät öffnen → Einstellungen → Passwort');
    }
    try {
        return ['ok' => true, 'msg' => switch_router_action(db(), $dev, $key)];
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage());
    }
}

// LEDs aller Switches, die das können, sofort an/aus
function ledsAll(array $in): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $on = !empty($in['on']);
    $ok = 0;
    $err = [];
    foreach (db()->query("SELECT id, INET_NTOA(ip) AS ip, COALESCE(name, hostname, INET_NTOA(ip)) AS label, sw_pass, sw_user, sw_driver
                            FROM devices WHERE sw_pass IS NOT NULL AND sw_pass <> '' AND ip IS NOT NULL AND is_virtual = 0") as $d) {
        try {
            if (!switch_can_leds(switch_driver_class($d['sw_driver'] ?: 'netgear_plus'))) {
                continue;
            }
            switch_leds(db(), $d, $on);
            $ok++;
        } catch (Throwable $e) {
            $err[] = $d['label'] . ': ' . $e->getMessage();
        }
    }
    return ['ok' => true, 'count' => $ok, 'errors' => $err];
}

// Kabeltest an einem Port (der Port ist dabei kurz ohne Link)
function switchCable(array $in): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $dev = switchDevice((int) ($in['id'] ?? 0));
    $port = (int) ($in['port'] ?? 0);
    if ($port < 1 || $port > 52) {
        throw new InvalidArgumentException('Ungültiger Port');
    }
    if ($dev['sw_uplink'] !== null && (int) $dev['sw_uplink'] === $port) {
        throw new InvalidArgumentException('Der Uplink-Port wird nicht getestet – sonst wäre der Switch kurz nicht erreichbar');
    }
    if (!$dev['sw_pass']) {
        throw new InvalidArgumentException('Erst den Zugang einrichten');
    }
    try {
        $r = switch_locked(db(), (int) $dev['id'], 20, fn() => switch_open($dev)->session(fn(SwitchDriver $d) => $d->cableTest($port)));
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage());
    }
    db()->prepare('UPDATE switch_ports SET cable_result = ?, cable_dist = ?, cable_at = NOW() WHERE device_id = ? AND port = ?')
       ->execute([mb_substr($r['result'], 0, 48), $r['dist'] !== null ? mb_substr($r['dist'], 0, 16) : null, $dev['id'], $port]);
    return ['ok' => true] + $r;
}

// Verlauf aus der Minuten-Abfrage: Port-Verkehr (Switch) oder Status (Access Point), für längere Zeiträume gemittelt
function deviceHistory(int $id, string $range): array
{
    [$sec, $step] = match ($range) {
        '24h'   => [86400, 300],
        '7d'    => [7 * 86400, 1800],
        default => [3600, 60],
    };
    $from = date('Y-m-d H:i:s', time() - $sec);
    $b = "FLOOR(UNIX_TIMESTAMP(ts) / $step) * $step";
    $st = db()->prepare("SELECT port, $b AS t, ROUND(AVG(rx_bps)) AS rx, ROUND(AVG(tx_bps)) AS tx
                           FROM port_history WHERE device_id = ? AND ts >= ? GROUP BY port, t ORDER BY port, t");
    $st->execute([$id, $from]);
    $ports = [];
    foreach ($st as $r) {
        $ports[(int) $r['port']][] = [(int) $r['t'], num($r['rx']), num($r['tx'])];
    }
    $st = db()->prepare("SELECT $b AS t, ROUND(AVG(cpu)) AS cpu, ROUND(AVG(mem)) AS mem, ROUND(AVG(clients), 1) AS clients,
                                ROUND(AVG(c24), 1) AS c24, ROUND(AVG(c5), 1) AS c5, ROUND(AVG(weak), 1) AS weak
                           FROM ap_history WHERE device_id = ? AND ts >= ? GROUP BY t ORDER BY t");
    $st->execute([$id, $from]);
    $ap = [];
    foreach ($st as $r) {
        $ap[] = [(int) $r['t'], num($r['cpu']), num($r['mem']), num($r['clients']), num($r['c24']), num($r['c5']), num($r['weak'])];
    }
    // Messwerte (Router): Zählerstände (…_total) als Höchstwert, sonst Mittelwert
    $st = db()->prepare("SELECT k, $b AS t, IF(k LIKE '%\\_total', MAX(v), AVG(v)) AS v
                           FROM dev_metrics WHERE device_id = ? AND ts >= ? GROUP BY k, t ORDER BY k, t");
    $st->execute([$id, $from]);
    $metrics = [];
    foreach ($st as $r) {
        $metrics[$r['k']][] = [(int) $r['t'], $r['v'] === null ? null : round((float) $r['v'], 2)];
    }
    return ['range' => $range, 'from' => time() - $sec, 'to' => time(), 'step' => $step, 'ports' => (object) $ports, 'ap' => $ap,
            'metrics' => (object) $metrics];
}

// Live-Ansicht: das Gerät jetzt abfragen (der Browser ruft das alle paar Sekunden auf, solange der Reiter offen ist)
function deviceLive(int $id): array
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $dev = switchDevice($id);
    if (!$dev['sw_pass']) {
        throw new InvalidArgumentException('Für dieses Gerät ist kein Zugang eingerichtet');
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();   // andere Anfragen der Seite nicht blockieren, während das Gerät antwortet
    }
    try {
        return switch_live(db(), $dev);
    } catch (RuntimeException $e) {
        throw new InvalidArgumentException($e->getMessage());
    }
}

// Analyse-Paket: alle Seiten der Weboberfläche als Textdatei zum Herunterladen
function switchPages(int $id): void
{
    require_once NETMON_DIR . '/collector/switch_drivers.php';
    $dev = switchDevice($id);
    $note = '';
    try {
        $pages = $dev['sw_pass']
            ? switch_open($dev)->session(fn(SwitchDriver $d) => $d->pages())
            : switch_public_pages($dev['ip']);
    } catch (RuntimeException $e) {
        $note = 'Anmeldung nicht möglich (' . $e->getMessage() . ') – nur öffentliche Seiten';
        $pages = switch_public_pages($dev['ip']);
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="netmon-analyse-' . str_replace('.', '-', $dev['ip']) . '.txt"');
    echo "netmon Analyse-Paket · {$dev['ip']} · " . date('Y-m-d H:i') . ' · Treiber: ' . ($dev['sw_driver'] ?: 'keiner') . "\n";
    if ($note) {
        echo "$note\n";
    }
    foreach ($pages as $p => $html) {
        echo "\n\n===== $p (" . strlen($html) . " Bytes) =====\n", $html;
    }
}

function deleteDevice(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        throw new InvalidArgumentException('Gerät fehlt');
    }
    db()->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

// ---------------------------------------------------------------
// Einstellungen
// ---------------------------------------------------------------

function settingsGet(): array
{
    $s = settings(true);
    $zones = db()->query(
        'SELECT z.id, z.name, INET_NTOA(z.ip_from) AS ip_from, INET_NTOA(z.ip_to) AS ip_to, z.zone_type, z.parent_device_id, z.sort,
                (SELECT COUNT(*) FROM devices d WHERE d.ip BETWEEN z.ip_from AND z.ip_to) AS devices
           FROM zones z ORDER BY z.sort, z.ip_from'
    )->fetchAll();
    $parents = [];
    foreach (db()->query("SELECT id, name, hostname, vendor, mac, INET_NTOA(ip) AS ip FROM devices
                           WHERE device_type IN ('router','switch','ap') ORDER BY ip") as $r) {
        $parents[] = ['id' => (int) $r['id'], 'label' => displayName($r) . ' (' . ($r['ip'] ?? 'ohne IP') . ')'];
    }
    unset($s['scan_request'], $s['speedtest_request']);
    return [
        'settings' => $s,
        'zones'    => array_map(fn($z) => [
            'id' => (int) $z['id'], 'name' => $z['name'], 'from' => $z['ip_from'], 'to' => $z['ip_to'],
            'type' => $z['zone_type'], 'parentId' => $z['parent_device_id'] !== null ? (int) $z['parent_device_id'] : null,
            'sort' => (int) $z['sort'], 'devices' => (int) $z['devices'],
        ], $zones),
        'parents'  => $parents,
    ];
}

function settingsSave(array $in): array
{
    $rules = [
        'subnet'              => fn($v) => preg_match('#^(\d{1,3}\.){3}\d{1,3}/(1[6-9]|2\d|3[0-2])$#', $v) && ip2long(explode('/', $v)[0]) !== false,
        'strip_domain'        => fn($v) => mb_strlen($v) <= 64 && !preg_match('/\s/', $v),
        'scan_interval'       => fn($v) => in_array((int) $v, [1, 2, 5, 10, 15, 30], true),
        'offline_after'       => fn($v) => (int) $v >= 1 && (int) $v <= 20,
        'hide_offline_days'   => fn($v) => in_array((int) $v, [0, 1, 2, 3, 7, 14, 30], true),
        'speedtest_enabled'   => fn($v) => in_array((string) $v, ['0', '1'], true),
        'speedtest_interval'  => fn($v) => in_array((int) $v, [10, 15, 20, 30, 60], true),
        'animations'          => fn($v) => in_array((string) $v, ['0', '1'], true),
        'keep_speedtest_days' => fn($v) => (int) $v >= 1 && (int) $v <= 365,
        'keep_event_days'     => fn($v) => (int) $v >= 7 && (int) $v <= 3650,
        'keep_scan_days'      => fn($v) => (int) $v >= 1 && (int) $v <= 365,
        'keep_history_days'   => fn($v) => (int) $v >= 1 && (int) $v <= 90,
        'led_off'             => fn($v) => $v === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v),
        'led_on'              => fn($v) => $v === '' || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v),
    ];
    $labels = [
        'subnet' => 'Subnetz (z. B. 192.168.5.0/24)', 'strip_domain' => 'Domain-Endung', 'scan_interval' => 'Scan-Intervall',
        'offline_after' => 'Offline nach (1–20 Scans)', 'hide_offline_days' => 'Offline-Geräte ausblenden', 'speedtest_interval' => 'Speedtest-Intervall',
        'keep_speedtest_days' => 'Speedtest-Rohdaten (1–365 Tage)', 'keep_event_days' => 'Ereignisse (7–3650 Tage)',
        'keep_scan_days' => 'Scan-Protokoll (1–365 Tage)', 'keep_history_days' => 'Verlauf (1–90 Tage)',
        'led_off' => 'LEDs aus (Uhrzeit)', 'led_on' => 'LEDs an (Uhrzeit)',
    ];
    $save = [];
    foreach ($in as $k => $v) {
        if (!isset($rules[$k])) {
            continue;
        }
        $v = is_bool($v) ? (int) $v : trim((string) $v);
        if (!$rules[$k]($v)) {
            throw new InvalidArgumentException('Ungültiger Wert: ' . ($labels[$k] ?? $k));
        }
        $save[$k] = $v;
    }
    foreach ($save as $k => $v) {
        netmon_set(db(), $k, $v);
    }
    return ['ok' => true, 'settings' => settings(true)];
}

function runNow(array $in): array
{
    $what = $in['what'] ?? '';
    if (!in_array($what, ['scan', 'speedtest'], true)) {
        throw new InvalidArgumentException('Unbekannter Auftrag');
    }
    netmon_set(db(), $what . '_request', 1);
    return ['ok' => true];
}

// Bereiche
function zoneSave(array $in): array
{
    $name = trim((string) ($in['name'] ?? ''));
    $from = ip2long((string) ($in['from'] ?? ''));
    $to   = ip2long((string) ($in['to'] ?? ''));
    $type = (string) ($in['type'] ?? 'kabel');
    $parent = ($in['parentId'] ?? '') === '' || $in['parentId'] === null ? null : (int) $in['parentId'];
    if ($name === '' || mb_strlen($name) > 64) {
        throw new InvalidArgumentException('Bitte einen Namen (bis 64 Zeichen) angeben');
    }
    if ($from === false || $to === false || $from > $to) {
        throw new InvalidArgumentException('Bitte gültige IP-Adressen angeben, „von“ nicht größer als „bis“');
    }
    if (!in_array($type, ['kabel', 'wlan', 'labor', 'dhcp'], true)) {
        throw new InvalidArgumentException('Ungültiger Bereichstyp');
    }
    $id = (int) ($in['id'] ?? 0);
    if ($id > 0) {
        db()->prepare('UPDATE zones SET name = ?, ip_from = ?, ip_to = ?, zone_type = ?, parent_device_id = ? WHERE id = ?')
            ->execute([$name, $from, $to, $type, $parent, $id]);
    } else {
        $sort = (int) db()->query('SELECT COALESCE(MAX(sort), 0) + 10 FROM zones')->fetchColumn();
        db()->prepare('INSERT INTO zones (name, ip_from, ip_to, zone_type, parent_device_id, sort) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $from, $to, $type, $parent, $sort]);
    }
    return ['ok' => true];
}

function zoneDelete(array $in): array
{
    db()->prepare('DELETE FROM zones WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
    return ['ok' => true];
}

// Benutzer
function usersGet(): array
{
    $rows = db()->query('SELECT id, username, role, ui_mode, created_at, last_login FROM users ORDER BY username')->fetchAll();
    return array_map(fn($u) => [
        'id' => (int) $u['id'], 'username' => $u['username'], 'role' => $u['role'], 'mode' => $u['ui_mode'],
        'created' => $u['created_at'], 'lastLogin' => $u['last_login'],
        'self' => (int) $u['id'] === (int) current_user()['id'],
    ], $rows);
}

function adminCount(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
}

function userSave(array $in): array
{
    $id   = (int) ($in['id'] ?? 0);
    $role = in_array($in['role'] ?? '', ['admin', 'family', 'viewer'], true) ? $in['role'] : 'viewer';
    $mode = in_array($in['mode'] ?? '', ['einfach', 'profi'], true) ? $in['mode'] : ($role === 'admin' ? 'profi' : 'einfach');
    $pass = (string) ($in['password'] ?? '');
    if ($pass !== '' && mb_strlen($pass) < 8) {
        throw new InvalidArgumentException('Das Passwort braucht mindestens 8 Zeichen');
    }
    if ($id > 0) {
        $st = db()->prepare('SELECT role FROM users WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetchColumn();
        if ($old === false) {
            throw new InvalidArgumentException('Benutzer nicht gefunden');
        }
        if ($old === 'admin' && $role !== 'admin' && adminCount() <= 1) {
            throw new InvalidArgumentException('Es muss mindestens ein Admin bleiben');
        }
        db()->prepare('UPDATE users SET role = ?, ui_mode = ? WHERE id = ?')->execute([$role, $mode, $id]);
        if ($pass !== '') {
            db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        }
    } else {
        $name = trim((string) ($in['username'] ?? ''));
        if (!preg_match('/^[\w.\-@]{3,64}$/u', $name)) {
            throw new InvalidArgumentException('Benutzername: 3 bis 64 Zeichen, Buchstaben, Ziffern, Punkt, Bindestrich');
        }
        if ($pass === '') {
            throw new InvalidArgumentException('Für neue Benutzer bitte ein Passwort vergeben');
        }
        $st = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$name]);
        if ((int) $st->fetchColumn() > 0) {
            throw new InvalidArgumentException('Diesen Benutzernamen gibt es schon');
        }
        db()->prepare('INSERT INTO users (username, pass_hash, role, ui_mode, created_at) VALUES (?, ?, ?, ?, NOW())')
            ->execute([$name, password_hash($pass, PASSWORD_DEFAULT), $role, $mode]);
    }
    return ['ok' => true];
}

function userDelete(array $in): array
{
    $id = (int) ($in['id'] ?? 0);
    if ($id === (int) current_user()['id']) {
        throw new InvalidArgumentException('Du kannst dich nicht selbst löschen');
    }
    $st = db()->prepare('SELECT role FROM users WHERE id = ?');
    $st->execute([$id]);
    if ($st->fetchColumn() === 'admin' && adminCount() <= 1) {
        throw new InvalidArgumentException('Es muss mindestens ein Admin bleiben');
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    return ['ok' => true];
}

function changePassword(array $in): array
{
    $st = db()->prepare('SELECT pass_hash FROM users WHERE id = ?');
    $st->execute([current_user()['id']]);
    if (!password_verify((string) ($in['old'] ?? ''), (string) $st->fetchColumn())) {
        throw new InvalidArgumentException('Das bisherige Passwort stimmt nicht');
    }
    $new = (string) ($in['new'] ?? '');
    if (mb_strlen($new) < 8) {
        throw new InvalidArgumentException('Das neue Passwort braucht mindestens 8 Zeichen');
    }
    db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), current_user()['id']]);
    return ['ok' => true];
}
