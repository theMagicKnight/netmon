<?php
// netmon – Treiber „Telekom Speedport“ (Smart 3, Smart 4, Smart 4R …)
// Die Statusseite der Weboberfläche lädt /data/Status.json – ohne Anmeldung, aber AES-256-CCM-verschlüsselt mit einem
// festen Schlüssel (Nonce = erste 8 Bytes des Schlüssels, Prüfsumme 16 Bytes am Ende). Ältere Modelle liefern Klartext.
// Quellen: https://github.com/aaronk6/dsl-monitoring (Entschlüsselung), Node-RED-Flow „Get status data from Speedport Smart 4“
// (https://flows.nodered.org/flow/49c9f12d75c31bc41d40059176120a81), https://github.com/Andre0512/speedport-api (MIT;
// Anmeldung per Challenge, Schalten von WLAN/Gäste-WLAN, Neu verbinden, Neustart – hier in PHP nachgebaut).
// Getestet mit einem Speedport Smart 4R Typ A.
// Ohne Passwort: Modell, Firmware, DSL-Status und -Sync, LAN-Buchsen mit Tempo, WLAN-Geräte mit Signal, WLAN an/aus.
// Mit Gerätepasswort zusätzlich: öffentliche IP-Adressen, WLAN und Gäste-WLAN schalten, neu verbinden, neu starten.
// Angemeldet wird nur bei Bedarf (Schalten, stündlicher Info-Abgleich, Analyse-Paket) – der Speedport erlaubt nur eine Sitzung.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class SpeedportDriver extends SwitchDriver
{
    public const LABEL = 'Telekom Speedport (Smart 3/4 – Passwort freiwillig, zum Schalten)';
    public const HINT  = '/speedport|telekom/i';
    public const NEEDS_PASS = false;
    public const PASS_OPTIONAL = true;
    public const ACTIONS = ['wlan_on' => 'WLAN einschalten', 'wlan_off' => 'WLAN ausschalten', 'guest_on' => 'Gäste-WLAN einschalten',
                            'guest_off' => 'Gäste-WLAN ausschalten', 'reconnect' => 'Internet neu verbinden', 'reboot' => 'Speedport neu starten'];

    private const KEY = 'cdc0cac1280b516e674f0057e4929bca84447cca8425007e33a88a5cf598a190';

    private ?array $vars = null;
    private array $devices = [];
    private ?string $key = null;   // Sitzungsschlüssel nach der Anmeldung (= Challenge)

    public static function detect(string $ip): bool
    {
        $raw = self::probe("http://$ip/data/Status.json");
        return $raw !== null && ($txt = self::decode($raw)) !== null && stripos($txt, 'speedport') !== false;
    }

    // Klartext-JSON oder Hex-Text → JSON-Text; null, wenn es nicht passt
    private static function decode(string $raw, string $keyHex = self::KEY): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if ($raw[0] === '[' || $raw[0] === '{') {
            return $raw;
        }
        if (!ctype_xdigit($raw) || strlen($raw) % 2 !== 0 || strlen($raw) < 64) {
            return null;
        }
        $bin = hex2bin($raw);
        $key = hex2bin($keyHex);
        $txt = openssl_decrypt(substr($bin, 0, -16), 'aes-256-ccm', $key, OPENSSL_RAW_DATA, substr($key, 0, 8), substr($bin, -16));
        return $txt === false ? null : $txt;
    }

    // Gegenstück zu decode(): AES-256-CCM, Nonce = erste 8 Bytes des Schlüssels, 16 Byte Prüfsumme angehängt, als Hex
    private static function encode(string $plain, string $keyHex = self::KEY): string
    {
        $key = hex2bin($keyHex);
        $tag = '';
        $ct = openssl_encrypt($plain, 'aes-256-ccm', $key, OPENSSL_RAW_DATA, substr($key, 0, 8), $tag, '', 16);
        if ($ct === false) {
            throw new RuntimeException('Verschlüsseln fehlgeschlagen (PHP-openssl ohne AES-CCM?)');
        }
        return bin2hex($ct . $tag);
    }

    // [{varid, varvalue}, …] → [varid => wert]; Listen (z. B. Geräte) werden zu [varid => [[unterfeld => wert], …]]
    private static function flat(array $list): array
    {
        $out = [];
        foreach ($list as $e) {
            if (!is_array($e) || !isset($e['varid'])) {
                continue;
            }
            $v = $e['varvalue'] ?? null;
            if (is_array($v)) {
                $sub = [];
                foreach ($v as $x) {
                    if (is_array($x) && isset($x['varid'])) {
                        $sub[$x['varid']] = $x['varvalue'] ?? null;
                    }
                }
                $out[$e['varid']][] = $sub;
            } else {
                $out[$e['varid']] = $v;
            }
        }
        return $out;
    }

    private function hasPass(): bool
    {
        return $this->pass !== '' && $this->pass !== '-';
    }

    // Anfrage an die verschlüsselte Schnittstelle; Antwort als flache Liste
    private function call(string $page, ?string $plainBody, string $keyHex, ?string $referer = null): array
    {
        $raw = $this->request($page, $plainBody !== null ? self::encode($plainBody, $keyHex) : null,
                              $referer !== null ? ['Referer: http://' . $this->ip . '/' . ltrim($referer, '/')] : []);
        $txt = self::decode($raw, $keyHex);
        $list = $txt !== null ? json_decode($txt, true) : null;
        if (!is_array($list)) {
            throw new RuntimeException('Antwort des Speedports nicht lesbar (' . $page . ')');
        }
        return isset($list[0]['varid']) ? self::flat($list) : $list;
    }

    // Sicherheits-Token einer Seite der Weboberfläche (muss jeder geschützten Anfrage beiliegen)
    private function token(string $page): string
    {
        return preg_match('/httoken\s*=\s*(\d+)/', $this->request($page), $m) ? $m[1] : '';
    }

    // Anmeldung: Challenge holen, sha256(challenge:passwort) senden; die Challenge ist danach der Sitzungsschlüssel
    private function auth(): void
    {
        if ($this->key !== null) {
            return;
        }
        if (!$this->hasPass()) {
            throw new RuntimeException('Dafür braucht netmon das Gerätepasswort des Speedports (Gerät öffnen → Einstellungen → Passwort)');
        }
        $tok = $this->token('');
        $r = $this->call('data/Login.json', 'getChallenge=1' . ($tok !== '' ? "&httoken=$tok" : ''), self::KEY, '');
        $ch = (string) ($r['challenge'] ?? '');
        if (strlen($ch) !== 64 || !ctype_xdigit($ch)) {
            throw new RuntimeException('Speedport: Anmeldung nicht möglich (keine Challenge) – anderes Modell? Bitte Analyse-Paket schicken');
        }
        $r = $this->call('data/Login.json', 'showpw=0&password=' . hash('sha256', $ch . ':' . $this->pass), self::KEY);
        if (($r['login'] ?? '') !== 'success') {
            if (!empty($r['login_locked'])) {
                throw new RuntimeException("Der Speedport sperrt die Anmeldung noch {$r['login_locked']} Sekunden (zu viele Versuche)");
            }
            throw new RuntimeException('Speedport: Gerätepasswort falsch');
        }
        $this->key = $ch;
    }

    private function authGet(string $page, string $referer): array
    {
        $this->auth();
        $tok = $this->token($referer);
        return $this->call($page . ($tok !== '' ? "?_tn=$tok" : ''), null, $this->key, $referer);
    }

    private function authPost(string $page, array $data, string $referer): array
    {
        $this->auth();
        if (($tok = $this->token($referer)) !== '') {
            $data['httoken'] = $tok;
        }
        $body = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($data), $data));
        $r = $this->call($page, $body, $this->key, $referer);
        if (isset($r['status']) && !in_array(strtolower((string) $r['status']), ['ok', 'success'], true)) {
            throw new RuntimeException('Speedport hat abgelehnt: ' . $r['status']);
        }
        return $r;
    }

    protected function login(): void
    {
        $txt = self::decode($this->get('data/Status.json'));
        $list = $txt !== null ? json_decode($txt, true) : null;
        if (!is_array($list)) {
            throw new RuntimeException('Status des Speedports nicht lesbar (anderes Modell? Analyse-Paket schicken)');
        }
        $this->vars = [];
        $this->devices = [];
        foreach ($list as $e) {
            $id = $e['varid'] ?? '';
            $v = $e['varvalue'] ?? null;
            if ($id === 'addmdevice' && is_array($v)) {
                $d = [];
                foreach ($v as $x) {
                    $d[$x['varid'] ?? ''] = $x['varvalue'] ?? null;
                }
                $this->devices[] = $d;
            } elseif (!is_array($v)) {
                $this->vars[$id] ??= (string) $v;
            }
        }
    }

    protected function logout(): void
    {
        if ($this->key !== null) {
            try {
                $this->call('data/Login.json', 'logout=byby', $this->key);
            } catch (Throwable $e) {
            }
            $this->key = null;
        }
    }

    public function runAction(string $key): string
    {
        switch ($key) {
            case 'wlan_on':
            case 'wlan_off':
                $this->authPost('data/Modules.json', ['use_wlan' => $key === 'wlan_on' ? '1' : '0'], 'html/content/network/wlan_basic.html');
                return $key === 'wlan_on' ? 'WLAN eingeschaltet' : 'WLAN ausgeschaltet';
            case 'guest_on':
            case 'guest_off':
                $this->authPost('data/WLANBasic.json', ['wlan_guest_active' => $key === 'guest_on' ? '1' : '0'], 'html/content/network/wlan_guest.html');
                return $key === 'guest_on' ? 'Gäste-WLAN eingeschaltet' : 'Gäste-WLAN ausgeschaltet';
            case 'reconnect':
                $this->authPost('data/Connect.json', ['req_connect' => 'reconnect'], 'html/content/internet/con_ipdata.html');
                return 'Internet neu verbunden';
            case 'reboot':
                $this->authPost('data/Reboot.json', ['reboot_device' => 'true'], 'html/content/config/restart.html');
                $this->key = null;   // Sitzung ist mit dem Neustart weg
                return 'Speedport startet neu';
        }
        throw new RuntimeException('Unbekannte Funktion');
    }

    private function v(string $k): ?string
    {
        $v = $this->vars[$k] ?? null;
        return $v === null || $v === '' ? null : $v;
    }

    // LAN 1–4: lanN_device = Tempo in Bit/s, 0 = kein Link. Datenraten je Buchse liefert der Speedport nicht.
    public function ports(): array
    {
        $ports = [];
        for ($p = 1; $p <= 4; $p++) {
            $bps = (int) ($this->v("lan{$p}_device") ?? 0);
            $ports[$p] = ['link' => $bps > 0, 'speed' => $bps > 0 ? (int) round($bps / 1e6) . 'M' : null,
                          'desc' => null, 'rx' => null, 'tx' => null, 'crc' => null];
        }
        return $ports;
    }

    // WLAN-Geräte direkt am Speedport (mdevice_type 1 = 2,4 GHz, 2 = 5 GHz; 0 = Kabel bzw. hinter einem Access Point)
    public function wifiClients(): array
    {
        $out = [];
        foreach ($this->devices as $d) {
            $type = (int) ($d['mdevice_type'] ?? 0);
            if (($d['mdevice_connected'] ?? '') !== '1' || !in_array($type, [1, 2], true)) {
                continue;
            }
            $mac = strtoupper(str_replace('-', ':', (string) ($d['mdevice_mac'] ?? '')));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                continue;
            }
            $rssi = (int) ($d['mdevice_rssi'] ?? 0);
            $out[] = [
                'mac'    => $mac,
                'ip'     => ($d['mdevice_ipv4'] ?? '') !== '' ? $d['mdevice_ipv4'] : null,
                'band'   => $type === 2 ? '5' : '2.4',
                'guest'  => false,
                'signal' => $rssi < 0 ? $rssi : null,
                'live'   => true,
                'tx'     => isset($d['mdevice_downspeed']) ? (int) round((int) $d['mdevice_downspeed'] / 1000) : null,
                'rx'     => isset($d['mdevice_upspeed']) ? (int) round((int) $d['mdevice_upspeed'] / 1000) : null,
                'online' => null,
                'host'   => $d['mdevice_name'] ?? null,
            ];
        }
        return $out;
    }

    // Messwerte für Verlauf und Live: DSL-Sync in Bit/s (Internet-Rate und Störabstand liefert Status.json nicht)
    public function metrics(): array
    {
        $ok = $this->v('dsl_link_status');
        $m = [];
        if ($ok !== null) {
            $m['dsl_ok'] = strcasecmp($ok, 'online') === 0 ? 1 : 0;
        }
        if ($this->v('dsl_downstream') !== null) {
            $m['dsl_down'] = (int) $this->v('dsl_downstream');
            $m['dsl_up']   = (int) ($this->v('dsl_upstream') ?? 0);
        }
        // WLAN-Zustand für die einfache Ansicht (Knöpfe WLAN / Gäste-WLAN)
        if ($this->v('use_wlan') !== null) {
            $m['wlan_on'] = (int) $this->v('use_wlan');
        }
        if ($this->v('wlan_guest_active') !== null) {
            $m['guest_on'] = (int) $this->v('wlan_guest_active');
        }
        return $m;
    }

    public function info(): array
    {
        $mbit = fn($b) => number_format((int) $b / 1e6, 1, ',', '.') . ' Mbit/s';
        $checks = [];
        $dsl = $this->v('dsl_link_status');
        if ($dsl !== null) {
            $checks[] = strcasecmp($dsl, 'online') === 0
                ? ['ok', 'DSL synchron: ↓ ' . $mbit($this->v('dsl_downstream')) . ' / ↑ ' . $mbit($this->v('dsl_upstream'))]
                : ['warn', 'DSL: ' . $dsl];
        }
        if ($this->v('inet_download') !== null) {
            $checks[] = ['info', 'Internet (nach Abzug Protokoll): ↓ ' . $mbit($this->v('inet_download')) . ' / ↑ ' . $mbit($this->v('inet_upload'))];
        }
        $on = $this->v('onlinestatus');
        if ($on !== null) {
            $since = $this->v('inet_uptime');
            $days = (int) ($this->v('days_online') ?? 0);
            $checks[] = strcasecmp($on, 'online') === 0
                ? ['ok', 'Internet verbunden' . ($since ? ' seit ' . date('d.m. H:i', strtotime($since) ?: time()) : '')
                    . ($days > 0 ? " ($days Tage)" : '')]
                : ['warn', 'Internet: ' . $on];
        }
        if (($this->v('router_state') ?? 'OK') !== 'OK') {
            $checks[] = ['warn', 'Router meldet: ' . $this->v('router_state')];
        }
        if ($this->v('fwupd_avail') === '1') {
            $checks[] = ['info', 'Firmware-Update verfügbar'];
        }
        if ($this->v('pwd_changed') === '0') {
            $checks[] = ['info', 'Gerätepasswort ist noch das vom Aufkleber'];
        }
        $wifi = $this->wifiClients();
        $nets = [];
        if ($this->v('use_wlan') === '1') {
            $nets[] = 'WLAN „' . ($this->v('wlan_ssid') ?? '?') . '“';
        }
        if ($this->v('wlan_guest_active') === '1') {
            $nets[] = 'Gastzugang';
        }
        $checks[] = ['info', ($nets ? implode(', ', $nets) . ' · ' : 'WLAN aus · ') . count($wifi) . ' Geräte im Speedport-WLAN'];
        if ($this->hasPass()) {
            try {
                $ip = $this->authGet('data/IPData.json', 'html/content/internet/con_ipdata.html');
                $pub = array_filter([$ip['public_ip_v4'] ?? null, $ip['public_ip_v6'] ?? null]);
                $checks[] = ['ok', 'Mit Gerätepasswort angemeldet' . ($pub ? ' · öffentliche IP ' . implode(', ', $pub) : '')];
            } catch (RuntimeException $e) {
                $checks[] = ['warn', 'Anmeldung mit Gerätepasswort: ' . $e->getMessage()];
            }
        } else {
            $checks[] = ['info', 'Ohne Gerätepasswort: nur Anzeige. Mit Passwort kann netmon WLAN/Gäste-WLAN schalten und neu verbinden.'];
        }
        return [
            'model'    => $this->v('device_name'),
            'firmware' => $this->v('firmware_version'),
            'serial'   => $this->v('serial_number'),
            'name'     => null,
            'checks'   => $checks,
            'wlan'     => ['ssid' => $this->v('wlan_ssid'), 'guestSsid' => $this->v('wlan_guest_ssid'), 'auth' => $this->key !== null,
                           'on' => $this->v('use_wlan') !== null ? $this->v('use_wlan') === '1' : null,
                           'guestOn' => $this->v('wlan_guest_active') !== null ? $this->v('wlan_guest_active') === '1' : null],
        ];
    }

    // Analyse-Paket: entschlüsselte Daten ohne Geheimnisse (Passwörter, Schlüssel, Rufnummern, Seriennummern); Gerätelisten nur gezählt
    public function pages(): array
    {
        $dump = function (array $a): string {
            $out = [];
            foreach ($a as $k => $v) {
                if (preg_match('/pass|pin|psk|key|secret|t_number|serial|sip|user|mail|phone|number/i', (string) $k)) {
                    continue;
                }
                $out[] = is_array($v) ? "$k = [" . count($v) . ' Einträge]' : "$k = $v";
            }
            return implode("\n", $out);
        };
        $pages = ['Status.json (Werte)' => $dump($this->vars ?? []), 'Geräte im Heimnetz' => (string) count($this->devices)];
        foreach (['data/Router.json', 'data/DeviceList.json'] as $pg) {
            try {
                $pages[$pg] = $dump($this->call($pg, null, self::KEY));
            } catch (RuntimeException $e) {
                $pages[$pg] = 'nicht lesbar: ' . $e->getMessage();
            }
        }
        if ($this->hasPass()) {
            foreach ([['data/IPData.json', 'html/content/internet/con_ipdata.html'], ['data/SecureStatus.json', ''],
                      ['data/WLANBasic.json', 'html/content/network/wlan_basic.html'], ['data/WPSStatus.json', 'html/content/network/wlan_wps.html']] as [$pg, $ref]) {
                try {
                    $pages[$pg . ' (angemeldet)'] = $dump($this->authGet($pg, $ref));
                } catch (RuntimeException $e) {
                    $pages[$pg . ' (angemeldet)'] = 'nicht lesbar: ' . $e->getMessage();
                }
            }
        }
        return $pages;
    }
}
