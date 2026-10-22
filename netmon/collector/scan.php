<?php
// netmon – Netzwerk-Scan per nmap, speichert Geräte und Statuswechsel
// Aufruf als root (für MAC-Adressen):  php /var/www/html/netmon/collector/scan.php

declare(strict_types=1);

// Nur von der Kommandozeile (Cron), nie über den Browser
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$cfg = require dirname(__DIR__) . '/config.php';
require __DIR__ . '/settings.php';
$db = netmon_pdo($cfg);
netmon_upgrade($db);
$s  = netmon_settings($db, $cfg);

// Nur ein Scan gleichzeitig
$lock = fopen('/tmp/netmon-scan.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Scan läuft bereits, Abbruch.\n");
    exit(1);
}

// ---------------------------------------------------------------
// 1. nmap ausführen
// ---------------------------------------------------------------
$start = microtime(true);
$cmd   = escapeshellcmd($cfg['nmap']) . ' -sn -oX - ' . escapeshellarg($s['subnet']) . ' 2>/dev/null';
$xmlRaw = shell_exec($cmd);
$durationMs = (int) round((microtime(true) - $start) * 1000);

if (!$xmlRaw) {
    fwrite(STDERR, date('c') . " nmap lieferte keine Ausgabe, keine Änderungen.\n");
    exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_string($xmlRaw);
if ($xml === false) {
    fwrite(STDERR, date('c') . " nmap-XML nicht lesbar, keine Änderungen.\n");
    exit(1);
}

// ---------------------------------------------------------------
// 2. Hosts auslesen
// ---------------------------------------------------------------
$raw = [];
foreach ($xml->host as $h) {
    if ((string) $h->status['state'] !== 'up') {
        continue;
    }
    $ip = $mac = $vendor = $hostname = null;
    foreach ($h->address as $a) {
        if ((string) $a['addrtype'] === 'ipv4') {
            $ip = (string) $a['addr'];
        } elseif ((string) $a['addrtype'] === 'mac') {
            $mac    = strtoupper((string) $a['addr']);
            $vendor = isset($a['vendor']) ? (string) $a['vendor'] : null;
        }
    }
    if (isset($h->hostnames->hostname[0])) {
        $hostname = (string) $h->hostnames->hostname[0]['name'];
        if ($s['strip_domain'] !== '' && str_ends_with($hostname, $s['strip_domain'])) {
            $hostname = substr($hostname, 0, -strlen($s['strip_domain']));
        }
    }
    if ($ip === null) {
        continue;
    }
    // Der Scanner selbst erscheint ohne MAC -> eigene MAC nachschlagen
    if ($mac === null) {
        $mac = localMac($ip);
    }
    if ($mac === null) {
        continue;
    }
    $raw[] = compact('ip', 'mac', 'vendor', 'hostname');
}

// Repeater mit „MAC-Tarnung“: Ein FRITZ!Repeater (u. a.), der per WLAN an einem fremden Router hängt, zeigt alle Geräte
// hinter sich mit SEINER MAC. Eine MAC mit mehreren IPs heißt also: Stellvertreter-MAC. netmon merkt sich solche MACs,
// hält den Repeater auf seiner eigenen IP und ordnet die Geräte dahinter getrennt zu (echte MAC aus der WLAN-Liste des
// Repeaters, sonst über die IP).
$byMac = [];
foreach ($raw as $h) {
    $byMac[$h['mac']][] = $h;
}
// Als Repeater gilt eine MAC erst bei mindestens 3 IPs gleichzeitig – oder bei 2 IPs, wenn eine davon als AVM-Gerät antwortet.
// (Manche Geräte haben einfach zwei IPs; die sind kein Repeater.)
$proxy = json_decode((string) ($s['proxy_macs'] ?? ''), true) ?: [];
$selByMac = $db->prepare('SELECT id, INET_NTOA(ip) AS ip FROM devices WHERE mac = ?');
$dropped = [];
foreach ($proxy as $mac => $p) {
    if (!empty($p['not'])) {
        if ($p['not'] < time() - 86400) {
            unset($proxy[$mac]);   // „kein Repeater“ nach einem Tag erneut prüfen
        }
        continue;
    }
    if (($p['n'] ?? 0) < 3 && empty($p['avm'])) {
        $dropped[] = $mac;   // aus älterer Version zu schnell gelernt
        unset($proxy[$mac]);
    }
}
foreach ($byMac as $mac => $list) {
    if (count($list) < 2 || isset($proxy[$mac])) {
        if (isset($proxy[$mac]) && empty($proxy[$mac]['not'])) {
            $proxy[$mac]['n'] = max($proxy[$mac]['n'] ?? 0, count($list));
        }
        continue;
    }
    $avm = count($list) >= 3 ? null : proxyAvmIp(array_column($list, 'ip'));
    if (count($list) < 3 && $avm === null) {
        $proxy[$mac] = ['not' => time()];   // zwei IPs, kein Repeater – einen Tag nicht erneut prüfen
        continue;
    }
    if (count($list) >= 3 || $avm !== null) {
        $proxy[$mac] = ['ip' => $avm, 'n' => count($list), 'avm' => $avm !== null];
        $dropped = array_diff($dropped, [$mac]);
        fwrite(STDERR, date('c') . " $mac meldet sich unter " . count($list) . " IPs – Repeater mit MAC-Tarnung, Geräte dahinter werden getrennt erkannt\n");
    }
}
$realByIp = [];   // IP → [echte MAC, Gerätename] laut WLAN-Liste eines Access Points/Repeaters
try {
    foreach ($db->query('SELECT mac, ip, host FROM wifi_clients WHERE ip IS NOT NULL') as $r) {
        if (empty($proxy[strtoupper($r['mac'])]) || !empty($proxy[strtoupper($r['mac'])]['not'])) {
            $realByIp[$r['ip']] = [strtoupper($r['mac']), $r['host'] ?: null];
        }
    }
} catch (PDOException $e) {
}
$hosts = [];
$behind = [];      // Geräte hinter einem Repeater ohne bekannte echte MAC: [host + proxy]
$keepAlive = [];   // Repeater, deren eigene IP gerade nicht feststellbar ist: bleiben online, IP unverändert
foreach ($byMac as $mac => $list) {
    if (!isset($proxy[$mac]) || !empty($proxy[$mac]['not'])) {
        // mehrere IPs, aber kein Repeater: bei der bekannten IP bleiben, damit der Eintrag nicht hin und her springt
        $selByMac->execute([$mac]);
        $known = $selByMac->fetch()['ip'] ?? null;
        $pick = end($list);
        foreach ($list as $h) {
            if ($h['ip'] === $known) {
                $pick = $h;
            }
        }
        $hosts[$mac] = $pick;
        continue;
    }
    $selByMac->execute([$mac]);
    $own = proxyOwnIp($list, $proxy[$mac]['ip'] ?? null, $selByMac->fetch()['ip'] ?? null);
    $proxy[$mac]['ip'] = $own;
    if ($own === null) {
        $keepAlive[] = $mac;
    }
    foreach ($list as $h) {
        if ($h['ip'] === $own) {
            $hosts[$mac] = $h;
        } elseif (isset($realByIp[$h['ip']])) {
            [$real, $wname] = $realByIp[$h['ip']];
            $hosts[$real] ??= ['ip' => $h['ip'], 'mac' => $real, 'vendor' => null, 'hostname' => $h['hostname'] ?: $wname];
        } else {
            $behind[] = $h + ['proxy' => $mac];
        }
    }
}
if ($proxy || $dropped) {
    netmon_set($db, 'proxy_macs', json_encode((object) $proxy));
}
// Fälschlich gelernte Repeater: deren Einträge „hinter Repeater“ (ohne MAC, ohne Namen) wieder entfernen
foreach ($dropped as $mac) {
    $selByMac->execute([$mac]);
    if ($rid = $selByMac->fetch()['id'] ?? null) {
        $db->prepare("DELETE FROM devices WHERE parent_id = ? AND mac IS NULL AND name IS NULL AND vendor = 'hinter Repeater'")->execute([$rid]);
    }
}

// Sicherung: Ein leerer Scan darf nicht alle Geräte offline setzen
if (count($hosts) + count($behind) === 0) {
    fwrite(STDERR, date('c') . " 0 Geräte gefunden, keine Änderungen.\n");
    exit(1);
}

// ---------------------------------------------------------------
// 3. Datenbank aktualisieren
// ---------------------------------------------------------------

$now = date('Y-m-d H:i:s');
$stats = ['neu' => 0, 'online' => 0, 'offline' => 0, 'ip_wechsel' => 0];

$selDev = $db->prepare('SELECT id, ip, is_online FROM devices WHERE mac = ?');
// Vorab angelegte Geräte ohne MAC (z. B. Router aus sql/zones-beispiel.sql) beim ersten Fund übernehmen
$selPre = $db->prepare('SELECT id, ip, is_online FROM devices WHERE mac IS NULL AND is_virtual = 0 AND ip = INET_ATON(?) LIMIT 1');
$setMac = $db->prepare('UPDATE devices SET mac = ? WHERE id = ?');
$insDev = $db->prepare(
    'INSERT INTO devices (mac, ip, hostname, vendor, is_private_mac, is_online, first_seen, last_seen)
     VALUES (?, INET_ATON(?), ?, ?, ?, 1, ?, ?)'
);
$updDev = $db->prepare(
    'UPDATE devices SET ip = INET_ATON(?), hostname = COALESCE(?, hostname), vendor = COALESCE(?, vendor),
            is_online = 1, missed_scans = 0, last_seen = ?
     WHERE id = ?'
);
$insEvt = $db->prepare(
    'INSERT INTO device_events (device_id, event, old_ip, new_ip, created_at) VALUES (?, ?, ?, ?, ?)'
);

$delTmp = $db->prepare("DELETE FROM devices WHERE mac IS NULL AND name IS NULL AND vendor = 'hinter Repeater' AND ip = INET_ATON(?) AND id <> ?");

$db->beginTransaction();
$seenIds = [];

foreach ($hosts as $h) {
    $selDev->execute([$h['mac']]);
    $dev = $selDev->fetch();
    if (!$dev) {
        $selPre->execute([$h['ip']]);
        if ($dev = $selPre->fetch()) {
            $setMac->execute([$h['mac'], $dev['id']]);
        }
    }
    $newIp = ip2long($h['ip']);

    if (!$dev) {
        $insDev->execute([$h['mac'], $h['ip'], $h['hostname'], $h['vendor'], isPrivateMac($h['mac']) ? 1 : 0, $now, $now]);
        $id = (int) $db->lastInsertId();
        $insEvt->execute([$id, 'neu', null, $newIp, $now]);
        $stats['neu']++;
    } else {
        $id = (int) $dev['id'];
        if ((int) $dev['ip'] !== $newIp) {
            $insEvt->execute([$id, 'ip_wechsel', $dev['ip'], $newIp, $now]);
            $stats['ip_wechsel']++;
        }
        if (!(int) $dev['is_online']) {
            $insEvt->execute([$id, 'online', null, $newIp, $now]);
            $stats['online']++;
        }
        $updDev->execute([$h['ip'], $h['hostname'], $h['vendor'], $now, $id]);
    }
    $seenIds[$id] = true;
    // vorläufiger Eintrag „hinter Repeater“ (ohne MAC, ohne Namen) unter derselben IP ist jetzt überflüssig
    $delTmp->execute([$h['ip'], $id]);
}

// Geräte hinter einem Repeater ohne echte MAC: über die IP erkennen (Eintrag ohne MAC), im Baum unter dem Repeater
$selProxy = $db->prepare('SELECT id FROM devices WHERE mac = ?');
$insBehind = $db->prepare(
    'INSERT INTO devices (mac, ip, hostname, vendor, parent_id, is_online, first_seen, last_seen)
     VALUES (NULL, INET_ATON(?), ?, ?, ?, 1, ?, ?)'
);
foreach ($behind as $h) {
    $selProxy->execute([$h['proxy']]);
    $rep = $selProxy->fetchColumn() ?: null;
    $selPre->execute([$h['ip']]);
    $dev = $selPre->fetch();
    if (!$dev) {
        $insBehind->execute([$h['ip'], $h['hostname'], 'hinter Repeater', $rep, $now, $now]);
        $id = (int) $db->lastInsertId();
        $insEvt->execute([$id, 'neu', null, ip2long($h['ip']), $now]);
        $stats['neu']++;
    } else {
        $id = (int) $dev['id'];
        if (!(int) $dev['is_online']) {
            $insEvt->execute([$id, 'online', null, ip2long($h['ip']), $now]);
            $stats['online']++;
        }
        $updDev->execute([$h['ip'], $h['hostname'], null, $now, $id]);
    }
    $seenIds[$id] = true;
}
// Repeater, dessen eigene IP diesmal nicht feststellbar war: gilt als online, IP bleibt
foreach ($keepAlive as $mac) {
    $selProxy->execute([$mac]);
    if ($rid = $selProxy->fetchColumn()) {
        $db->prepare('UPDATE devices SET is_online = 1, missed_scans = 0, last_seen = ? WHERE id = ?')->execute([$now, $rid]);
        $seenIds[(int) $rid] = true;
    }
}

// Nicht gesehene Geräte: Zähler erhöhen, ab Schwelle offline
$rows = $db->query('SELECT id, ip, is_online, missed_scans FROM devices WHERE is_virtual = 0')->fetchAll();
$updMiss = $db->prepare('UPDATE devices SET missed_scans = ?, is_online = ? WHERE id = ?');

foreach ($rows as $r) {
    if (isset($seenIds[(int) $r['id']])) {
        continue;
    }
    $missed = (int) $r['missed_scans'] + 1;
    $online = (int) $r['is_online'];
    if ($online && $missed >= (int) $s['offline_after']) {
        $online = 0;
        $insEvt->execute([(int) $r['id'], 'offline', $r['ip'], null, $now]);
        $stats['offline']++;
    }
    $updMiss->execute([min($missed, 65535), $online, (int) $r['id']]);
}

$db->prepare('INSERT INTO scans (started_at, duration_ms, hosts_up) VALUES (?, ?, ?)')
   ->execute([$now, $durationMs, count($hosts) + count($behind)]);

$db->commit();

printf(
    "%s %d Geräte online, %d ms | neu: %d, wieder online: %d, offline: %d, IP-Wechsel: %d\n",
    $now, count($hosts) + count($behind), $durationMs, $stats['neu'], $stats['online'], $stats['offline'], $stats['ip_wechsel']
);

// ---------------------------------------------------------------
// Hilfsfunktionen
// ---------------------------------------------------------------

// Zufällige („private“) MAC: zweites Zeichen ist 2, 6, A oder E
function isPrivateMac(string $mac): bool
{
    return in_array(strtoupper($mac[1]), ['2', '6', 'A', 'E'], true);
}

// Eigene IP eines Repeaters mit MAC-Tarnung, in dieser Reihenfolge: die zuletzt erkannte; die IP, die als AVM-Gerät
// antwortet (Port 49000 – Geräte dahinter antworten dort nicht); die IP, die netmon für das Gerät gespeichert hat;
// die einzige IP mit einer Weboberfläche (die meisten Repeater haben eine, Handys dahinter nicht)
function proxyOwnIp(array $list, ?string $cached, ?string $known): ?string
{
    $ips = array_column($list, 'ip');
    if ($cached !== null && in_array($cached, $ips, true)) {
        return $cached;
    }
    if (($avm = proxyAvmIp($ips)) !== null) {
        return $avm;
    }
    if ($known !== null && in_array($known, $ips, true)) {
        return $known;
    }
    $web = array_values(array_filter($ips, fn($ip) => httpProbe("http://$ip/") !== null));
    return count($web) === 1 ? $web[0] : null;
}

function proxyAvmIp(array $ips): ?string
{
    foreach ($ips as $ip) {
        if (stripos((string) httpProbe("http://$ip:49000/tr64desc.xml"), '<deviceType>') !== false) {
            return $ip;
        }
    }
    return null;
}

function httpProbe(string $url): ?string
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 3]);
    $r = curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    return $r === false || $code === 0 ? null : (string) $r;
}

// MAC der lokalen Schnittstelle, die die angegebene IP trägt
function localMac(string $ip): ?string
{
    $out = shell_exec('ip -o -4 addr show 2>/dev/null') ?? '';
    foreach (explode("\n", $out) as $line) {
        if (preg_match('/^\d+:\s+(\S+)\s+inet\s+' . preg_quote($ip, '/') . '\//', $line, $m)) {
            $file = "/sys/class/net/{$m[1]}/address";
            if (is_readable($file)) {
                return strtoupper(trim(file_get_contents($file)));
            }
        }
    }
    return null;
}
