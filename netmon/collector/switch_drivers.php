<?php
// netmon – Switch-Treiber: gemeinsame Grundlage, Liste der Treiber, Erkennung und Abfrage
// Neuer Hersteller = neue Datei in drivers/ mit einer Klasse, die SwitchDriver erweitert, und ein Eintrag in SWITCH_DRIVERS.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

const SWITCH_DRIVERS = [
    'netgear_gs316' => ['class' => 'NetgearGs316Driver', 'file' => 'netgear_gs316.php'],
    'netgear_jgs'   => ['class' => 'NetgearJgsDriver', 'file' => 'netgear_jgs.php'],
    'netgear_plus2' => ['class' => 'NetgearPlus2Driver', 'file' => 'netgear_plus2.php'],   // vor netgear_plus: erbt davon
    'netgear_plus'  => ['class' => 'NetgearPlusDriver', 'file' => 'netgear_plus.php'],
    'tplink_archer' => ['class' => 'TplinkArcherDriver', 'file' => 'tplink_archer.php'],
    'avm_fritzbox'  => ['class' => 'FritzBoxDriver', 'file' => 'avm_fritzbox.php'],
    'telekom_speedport' => ['class' => 'SpeedportDriver', 'file' => 'telekom_speedport.php'],
    'tplink_easy'   => ['class' => 'TplinkEasyDriver', 'file' => 'tplink_easy.php'],
];

// Angemeldet, aber die Seiten sehen anders aus als erwartet (neues Modell/Firmware) – Zugang ist richtig
class SwitchParseError extends RuntimeException {}

abstract class SwitchDriver
{
    public const LABEL = '';
    public const HINT  = '/^$/';
    public const NEEDS_USER = false;   // braucht das Gerät zusätzlich einen Benutzernamen?
    public const NEEDS_PASS = true;    // false: Daten ohne Anmeldung lesbar (Speedport)

    private $curl;

    public function __construct(protected string $ip, protected string $pass, protected string $user = '')
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP-Erweiterung curl fehlt (sudo apt install php-curl)');
        }
        $this->curl = curl_init();
        curl_setopt_array($this->curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE     => '',      // Cookies nur im Speicher
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 20,      // der Kabeltest braucht ein paar Sekunden
        ]);
    }

    public function __destruct()
    {
        curl_close($this->curl);
    }

    // Erkennt der Treiber das Gerät unter dieser IP (ohne Passwort)?
    abstract public static function detect(string $ip): bool;
    abstract protected function login(): void;
    abstract protected function logout(): void;
    // [port => [link, speed, desc, rx, tx, crc]]
    abstract public function ports(): array;
    // [model, name, serial, mac, firmware, checks => [[ok|warn|info, text], …]]
    public function info(): array { return []; }
    // WLAN-Clients eines Access Points: [[mac, ip, band, signal, tx, rx, online, host], …] – null = kann der Treiber nicht
    public function wifiClients(): ?array { return null; }
    // Auslastung des Geräts in Prozent: [cpu, mem] – null = kann der Treiber nicht (oder nur teuer)
    public function stats(): ?array { return null; }
    // Messwerte für Verlauf/Live (Router: DSL, Internet-Rate …): [schlüssel => zahl] – null = keine
    public function metrics(): ?array { return null; }
    public function cableTest(int $port): array { throw new RuntimeException('Dieser Switch kann keinen Kabeltest'); }
    // Port per Software ab-/einschalten (Gerät daran ist dann vom Netz getrennt)
    public function setPortEnabled(int $port, bool $on): void { throw new RuntimeException('Dieser Switch kann Ports nicht abschalten'); }
    // LEDs an der Front ein-/ausschalten (Ports arbeiten normal weiter)
    public function setLeds(bool $on): void { throw new RuntimeException('Dieses Gerät kann die LEDs nicht schalten'); }
    // [seite => inhalt] für das Analyse-Paket
    public function pages(): array { return []; }

    // Anmelden, Arbeit erledigen, immer abmelden (sonst sperren manche Switches weitere Anmeldungen)
    public function session(callable $fn)
    {
        $this->login();
        try {
            return $fn($this);
        } finally {
            try { $this->logout(); } catch (Throwable $e) {}
        }
    }

    protected function get(string $page, ?array $post = null): string
    {
        curl_setopt($this->curl, CURLOPT_URL, "http://{$this->ip}/" . ltrim($page, '/'));
        curl_setopt($this->curl, CURLOPT_POST, $post !== null);
        if ($post !== null) {
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $r = curl_exec($this->curl);
        if ($r === false) {
            throw new RuntimeException("Switch {$this->ip} nicht erreichbar");
        }
        return (string) $r;
    }

    // Cookie selbst setzen (manche Switches liefern die Sitzung im Seiteninhalt statt als Cookie)
    protected function setCookie(string $name, string $value): void
    {
        curl_setopt($this->curl, CURLOPT_COOKIELIST, "Set-Cookie: $name=$value; Domain={$this->ip}; Path=/");
    }

    protected function cookies(): string
    {
        return implode("\n", curl_getinfo($this->curl, CURLINFO_COOKIELIST) ?: []);
    }

    protected static function probe(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4]);
        $r = curl_exec($c);
        curl_close($c);
        return $r === false ? null : (string) $r;
    }
}

function switch_driver_class(string $key): string
{
    if (!isset(SWITCH_DRIVERS[$key])) {
        throw new RuntimeException("Unbekannter Treiber „{$key}“");
    }
    if ($key === 'netgear_plus2') {
        require_once __DIR__ . '/drivers/' . SWITCH_DRIVERS['netgear_plus']['file'];
    }
    require_once __DIR__ . '/drivers/' . SWITCH_DRIVERS[$key]['file'];
    return SWITCH_DRIVERS[$key]['class'];
}

// Für die Oberfläche: Schlüssel, Bezeichnung, Erkennungsmuster für Name/Hersteller
function switch_driver_list(): array
{
    $out = [];
    foreach (array_keys(SWITCH_DRIVERS) as $k) {
        $cls = switch_driver_class($k);
        $out[] = ['key' => $k, 'label' => $cls::LABEL, 'hint' => trim($cls::HINT, '/i'), 'user' => $cls::NEEDS_USER, 'pass' => $cls::NEEDS_PASS,
                  'cable' => (new ReflectionMethod($cls, 'cableTest'))->getDeclaringClass()->getName() === $cls,
                  'leds'  => switch_can_leds($cls), 'portoff' => switch_can_portoff($cls)];
    }
    return $out;
}

function switch_can_leds(string $cls): bool
{
    return (new ReflectionMethod($cls, 'setLeds'))->getDeclaringClass()->getName() !== SwitchDriver::class;
}

function switch_can_portoff(string $cls): bool
{
    return (new ReflectionMethod($cls, 'setPortEnabled'))->getDeclaringClass()->getName() !== SwitchDriver::class;
}

// IPs dieses Rechners (netmon selbst) – damit er sich nicht über einen gesperrten Port vom Netz abschneidet
function netmon_own_ips(): array
{
    $ips = preg_split('/\s+/', trim((string) @shell_exec('hostname -I 2>/dev/null')));
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $ips[] = $_SERVER['SERVER_ADDR'];
    }
    return array_values(array_filter(array_unique($ips), fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !str_starts_with($ip, '127.')));
}

// Geschützte Ports: dahinter (auch mehrere Ebenen tief) hängt ein Switch, ein Router oder netmon selbst.
// Ergebnis: ["Geräte-ID:Port" => Grund]. Access Points bleiben sperrbar (z. B. eigenes WLAN fürs Kinderzimmer).
function switch_protected_ports(PDO $db): array
{
    $devs = $db->query("SELECT id, INET_NTOA(ip) AS ip, COALESCE(name, hostname, INET_NTOA(ip)) AS label, device_type, parent_id, port, is_virtual
                          FROM devices")->fetchAll();
    $kids = [];
    foreach ($devs as $d) {
        if ($d['parent_id'] !== null) {
            $kids[(int) $d['parent_id']][] = $d;
        }
    }
    $own = netmon_own_ips();
    $why = function (array $d) use ($own): ?string {
        if (in_array($d['ip'], $own, true)) {
            return "dahinter läuft netmon selbst ({$d['label']}) – gesperrt wäre netmon nicht mehr erreichbar";
        }
        if (in_array($d['device_type'], ['switch', 'router'], true)) {
            return "dahinter hängt " . ($d['device_type'] === 'router' ? 'der Router' : 'der Switch') . " „{$d['label']}“";
        }
        return null;
    };
    $out = [];
    foreach ($devs as $d) {
        if ($d['parent_id'] === null || !ctype_digit((string) $d['port'])) {
            continue;
        }
        $key = $d['parent_id'] . ':' . (int) $d['port'];
        // alles unterhalb dieses Geräts durchsuchen
        $stack = [$d];
        $seen = [];
        while ($stack && !isset($out[$key])) {
            $x = array_pop($stack);
            if (isset($seen[$x['id']])) {
                continue;
            }
            $seen[$x['id']] = true;
            if (($r = $why($x)) !== null) {
                $out[$key] = $r;
                break;
            }
            array_push($stack, ...($kids[(int) $x['id']] ?? []));
        }
    }
    return $out;
}

// Port schalten und sofort neu abfragen (Status in der Datenbank aktuell)
function switch_port_enable(PDO $db, array $dev, int $port, bool $on): void
{
    if (!$on && ($r = switch_protected_ports($db)[$dev['id'] . ':' . $port] ?? null) !== null) {
        throw new RuntimeException("Port $port ist geschützt: $r");
    }
    switch_locked($db, (int) $dev['id'], 20, fn() => switch_open($dev)->session(fn(SwitchDriver $d) => $d->setPortEnabled($port, $on)));
    $db->prepare("INSERT INTO device_events (device_id, event, info, created_at) VALUES (?, 'port', ?, NOW())")
       ->execute([$dev['id'], "Port $port " . ($on ? 'freigegeben' : 'gesperrt')]);
    // Der Switch übernimmt die Einstellung erst nach ein paar Sekunden – sofort danach gelesen meldet er noch den alten Zustand.
    // Darum kurz warten und nachlesen, bis er passt; klappt das nicht, zählt der geschaltete Zustand (nächster Takt liest neu).
    $st = $db->prepare('SELECT admin_off FROM switch_ports WHERE device_id = ? AND port = ?');
    for ($try = 0; $try < 3; $try++) {
        sleep(2);
        try {
            switch_poll($db, $dev);
        } catch (Throwable $e) {
            continue;
        }
        $st->execute([$dev['id'], $port]);
        if ((int) $st->fetchColumn() === ($on ? 0 : 1)) {
            return;
        }
    }
    $db->prepare('UPDATE switch_ports SET admin_off = ?, link = IF(? = 1, 0, link) WHERE device_id = ? AND port = ?')
       ->execute([$on ? 0 : 1, $on ? 0 : 1, $dev['id'], $port]);
}

// LEDs eines Geräts schalten (mit Sperre, damit sich Cron und Oberfläche nicht in die Quere kommen)
function switch_leds(PDO $db, array $dev, bool $on): void
{
    switch_locked($db, (int) $dev['id'], 20, fn() => switch_open($dev)->session(fn(SwitchDriver $d) => $d->setLeds($on)));
}

// Welcher Treiber passt? null = keiner
function switch_detect(string $ip): ?string
{
    foreach (array_keys(SWITCH_DRIVERS) as $k) {
        $cls = switch_driver_class($k);
        if ($cls::detect($ip)) {
            return $k;
        }
    }
    return null;
}

function switch_open(array $dev, ?string $pass = null): SwitchDriver
{
    $cls = switch_driver_class($dev['sw_driver'] ?: 'netgear_plus');
    return new $cls($dev['ip'], $pass ?? (string) $dev['sw_pass'], (string) ($dev['sw_user'] ?? ''));
}

// Ports abfragen, Raten berechnen, Änderungen als Ereignis speichern; mit $withInfo auch Modell und Check
function switch_poll(PDO $db, array $dev, ?string $pass = null, bool $withInfo = false): array
{
    [$ports, $info, $wifi, $stats, $metrics] = switch_locked($db, (int) $dev['id'], 20, fn() => switch_open($dev, $pass)->session(
        fn(SwitchDriver $d) => [$d->ports(), $withInfo ? $d->info() : null, $d->wifiClients(), $d->stats(), $d->metrics()]));
    if ($metrics !== null) {
        switch_store_metrics($db, (int) $dev['id'], $metrics);
    }
    if ($wifi !== null) {
        switch_store_wifi($db, (int) $dev['id'], $wifi);
        $band = fn($b) => count(array_filter($wifi, fn($c) => wifi_live($c) && $c['band'] === $b));
        $db->prepare('INSERT IGNORE INTO ap_history (device_id, ts, cpu, mem, clients, c24, c5, weak) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
           ->execute([$dev['id'], date('Y-m-d H:i:00'), $stats['cpu'] ?? null, $stats['mem'] ?? null,
                      count(array_filter($wifi, 'wifi_live')), $band('2.4'), $band('5') + $band('6'),
                      count(array_filter($wifi, fn($c) => $c['signal'] !== null && $c['signal'] < -75))]);
    }

    $now = time();
    $prev = [];
    $st = $db->prepare('SELECT port, link, speed, rx_bytes, tx_bytes, UNIX_TIMESTAMP(updated) AS ts FROM switch_ports WHERE device_id = ?');
    $st->execute([$dev['id']]);
    foreach ($st as $r) {
        $prev[(int) $r['port']] = $r;
    }
    // Zähler laufen bei manchen Modellen bei 2^32 über; ein Rückgang darunter heißt „Zähler gelöscht“
    $rate = function (?int $cur, $old, int $dt): ?int {
        if ($cur === null || $old === null || $dt <= 0 || $dt > 3600) {
            return null;
        }
        $d = $cur - (int) $old;
        if ($d < 0) {
            $d = (int) $old > 2 ** 31 && (int) $old < 2 ** 32 ? $d + 2 ** 32 : null;   // nur bei echten 32-Bit-Zählern
        }
        return $d === null ? null : (int) round($d * 8 / $dt);
    };
    $up = $db->prepare(
        'INSERT INTO switch_ports (device_id, port, link, admin_off, speed, descr, rx_bytes, tx_bytes, crc, rx_bps, tx_bps, updated)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?))
         ON DUPLICATE KEY UPDATE link = VALUES(link), admin_off = VALUES(admin_off), speed = VALUES(speed), descr = VALUES(descr), rx_bytes = VALUES(rx_bytes),
                                 tx_bytes = VALUES(tx_bytes), crc = VALUES(crc), rx_bps = VALUES(rx_bps), tx_bps = VALUES(tx_bps), updated = VALUES(updated)'
    );
    $evt = $db->prepare("INSERT INTO device_events (device_id, event, info, created_at) VALUES (?, 'port', ?, FROM_UNIXTIME(?))");
    foreach ($ports as $p => $x) {
        $o = $prev[$p] ?? null;
        $dt = $o ? $now - (int) $o['ts'] : 0;
        $ports[$p]['rx_bps'] = $o ? $rate($x['rx'], $o['rx_bytes'], $dt) : null;
        $ports[$p]['tx_bps'] = $o ? $rate($x['tx'], $o['tx_bytes'], $dt) : null;
        if ($o) {
            if ((bool) $o['link'] !== $x['link']) {
                $evt->execute([$dev['id'], "Port $p: " . ($x['link'] ? 'Link da' . ($x['speed'] ? " ({$x['speed']})" : '') : 'Link weg'), $now]);
            } elseif ($x['link'] && $o['speed'] && $x['speed'] && $o['speed'] !== $x['speed']) {
                $evt->execute([$dev['id'], "Port $p: {$x['speed']} statt {$o['speed']}", $now]);
            }
        }
        $up->execute([$dev['id'], $p, $x['link'] ? 1 : 0, !empty($x['off']) ? 1 : 0, $x['speed'], $x['desc'] ?? null, $x['rx'], $x['tx'], $x['crc'],
                      $ports[$p]['rx_bps'], $ports[$p]['tx_bps'], $now]);
    }
    // Verlauf je Minute (nur Ports mit Link und berechneter Rate)
    $hist = $db->prepare('INSERT IGNORE INTO port_history (device_id, ts, port, rx_bps, tx_bps) VALUES (?, ?, ?, ?, ?)');
    foreach ($ports as $p => $x) {
        if ($x['link'] && ($x['rx_bps'] !== null || $x['tx_bps'] !== null)) {
            $hist->execute([$dev['id'], date('Y-m-d H:i:00', $now), $p, $x['rx_bps'], $x['tx_bps']]);
        }
    }
    if ($info !== null) {
        $info['checked'] = date('Y-m-d H:i:s', $now);
        $db->prepare('UPDATE devices SET sw_info = ? WHERE id = ?')->execute([json_encode($info, JSON_UNESCAPED_UNICODE), $dev['id']]);
    }
    return $ports;
}

// Immer nur eine Anmeldung je Gerät gleichzeitig (Cron, Live-Ansicht, Knöpfe) – sonst werfen sich die Sitzungen gegenseitig raus.
// Die Sperre liegt in MariaDB, damit Cron (root) und Apache (www-data) sie gemeinsam sehen.
function switch_locked(PDO $db, int $id, int $wait, callable $fn)
{
    $name = 'netmon-dev-' . $id;
    $st = $db->prepare('SELECT GET_LOCK(?, ?)');
    $st->execute([$name, $wait]);
    if ((int) $st->fetchColumn() !== 1) {
        throw new RuntimeException('Gerät ist gerade mit einer anderen Abfrage beschäftigt');
    }
    try {
        return $fn();
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
    }
}

// Live-Ansicht: nur Zählerstände bzw. AP-Status lesen, nichts speichern – die Raten rechnet der Browser
function switch_live(PDO $db, array $dev): array
{
    return switch_locked($db, (int) $dev['id'], 8, fn() => switch_open($dev)->session(function (SwitchDriver $d) {
        $m = $d->metrics();
        if ($m !== null) {
            return ['t' => microtime(true), 'metrics' => $m];
        }
        $wifi = $d->wifiClients();
        if ($wifi !== null) {
            $ok = array_values(array_filter($wifi, 'wifi_live'));
            return ['t' => microtime(true), 'stats' => $d->stats(), 'clients' => count($ok),
                    'c24' => count(array_filter($ok, fn($c) => $c['band'] === '2.4')),
                    'c5' => count(array_filter($ok, fn($c) => $c['band'] === '5' || $c['band'] === '6')),
                    'weak' => count(array_filter($ok, fn($c) => $c['signal'] < -75))];
        }
        $out = [];
        foreach ($d->ports() as $p => $x) {
            $out[] = ['p' => $p, 'link' => $x['link'], 'speed' => $x['speed'], 'rx' => $x['rx'], 'tx' => $x['tx']];
        }
        return ['t' => microtime(true), 'ports' => $out];
    }));
}

// Messwerte je Minute speichern; Neusynchronisation der DSL-Leitung als Ereignis festhalten
function switch_store_metrics(PDO $db, int $id, array $m): void
{
    $prev = [];
    $st = $db->prepare('SELECT k, v FROM dev_metrics WHERE device_id = ? AND ts = (SELECT MAX(ts) FROM dev_metrics WHERE device_id = ?)');
    $st->execute([$id, $id]);
    foreach ($st as $r) {
        $prev[$r['k']] = $r['v'] !== null ? (float) $r['v'] : null;
    }
    $ts = date('Y-m-d H:i:00');
    $ins = $db->prepare('INSERT INTO dev_metrics (device_id, k, ts, v) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    foreach ($m as $k => $v) {
        if (is_int($v) || is_float($v) || $v === null) {
            $ins->execute([$id, mb_substr((string) $k, 0, 16), $ts, $v]);
        }
    }
    // DSL neu synchronisiert: Sync-Rate hat sich geändert oder Fehlerzähler wurde zurückgesetzt
    if (isset($m['dsl_down'], $prev['dsl_down']) && $prev['dsl_down'] > 0) {
        $changed = abs($m['dsl_down'] - $prev['dsl_down']) > 1000 || abs(($m['dsl_up'] ?? 0) - ($prev['dsl_up'] ?? 0)) > 1000;
        $reset = isset($m['crc_total'], $prev['crc_total']) && $m['crc_total'] < $prev['crc_total'];
        if ($changed || $reset) {
            $txt = sprintf('DSL neu: %s / %s Mbit/s', number_format($m['dsl_down'] / 1e6, 1, ',', ''), number_format(($m['dsl_up'] ?? 0) / 1e6, 1, ',', ''));
            $db->prepare("INSERT INTO device_events (device_id, event, info, created_at) VALUES (?, 'port', ?, NOW())")->execute([$id, $txt]);
        }
    }
    if (isset($m['dsl_ok'], $prev['dsl_ok']) && (int) $m['dsl_ok'] !== (int) $prev['dsl_ok']) {
        $db->prepare("INSERT INTO device_events (device_id, event, info, created_at) VALUES (?, 'port', ?, NOW())")
           ->execute([$id, $m['dsl_ok'] ? 'DSL wieder verbunden' : 'DSL getrennt']);
    }
}

// Ohne Treiber/Passwort: öffentlich erreichbare Seiten (Startseite, Anmeldeseite) für die Analyse unbekannter Geräte
function switch_public_pages(string $ip): array
{
    $out = [];
    $queue = ['', 'index.htm', 'index.html', 'login.cgi', 'login.htm', 'login.html', 'logon.htm'];
    while ($queue && count($out) < 25) {
        $p = preg_replace('/[?#].*$/', '', ltrim(array_shift($queue), '/'));
        $key = $p === '' ? '/' : $p;
        if (isset($out[$key]) || preg_match('/logout|reboot|reset|factory|upgrade/i', $p)) {
            continue;
        }
        $c = curl_init("http://$ip/$p");
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6, CURLOPT_HEADER => true]);
        $r = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($r === false || $code === 404) {
            continue;
        }
        $body = substr((string) $r, strpos((string) $r, "\r\n\r\n") ?: 0);
        if (strncmp(ltrim($body), "\x1f\x8b", 2) === 0) {
            $body = (string) @gzdecode(ltrim($body));
        }
        $out[$key] = (string) $r === '' ? '' : $body;
        preg_match_all('/["\'\/]([\w\-\/]+\.(?:cgi|htm|html|js|asp|php))(?=[?"\'#])/i', $body, $mm);
        array_push($queue, ...array_unique($mm[1]));
    }
    if (!$out) {
        throw new RuntimeException("Unter http://$ip ist keine Weboberfläche erreichbar");
    }
    return $out;
}

// Ist der Client gerade an diesem AP angemeldet? Signal vorhanden, oder der Treiber meldet es ausdrücklich (live)
function wifi_live(array $c): bool
{
    return ($c['signal'] ?? null) !== null || !empty($c['live']);
}

// WLAN-Clients eines APs speichern. Veraltete Einträge nach einem AP-Wechsel (ohne Signal/IP) überspringen;
// ein Client gehört immer zu dem AP, der ihn zuletzt mit Signal gemeldet hat.
function switch_store_wifi(PDO $db, int $apId, array $clients): void
{
    $now = date('Y-m-d H:i:s');
    $up = $db->prepare(
        'INSERT INTO wifi_clients (mac, ap_id, band, guest, signal_dbm, tx_kbps, rx_kbps, online_s, ip, host, updated)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE ap_id = VALUES(ap_id), band = VALUES(band), guest = VALUES(guest), signal_dbm = VALUES(signal_dbm),
           tx_kbps = VALUES(tx_kbps), rx_kbps = VALUES(rx_kbps), online_s = VALUES(online_s), ip = VALUES(ip), host = VALUES(host), updated = VALUES(updated)'
    );
    foreach ($clients as $c) {
        if (!wifi_live($c)) {
            continue;
        }
        $up->execute([$c['mac'], $apId, $c['band'], $c['guest'] ? 1 : 0, $c['signal'], $c['tx'], $c['rx'], $c['online'], $c['ip'], $c['host'] ? mb_substr($c['host'], 0, 64) : null, $now]);
    }
    // was dieser AP nicht mehr meldet, ist abgemeldet oder gewechselt
    $db->prepare('DELETE FROM wifi_clients WHERE ap_id = ? AND updated < ?')->execute([$apId, $now]);
}
