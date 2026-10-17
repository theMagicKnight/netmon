<?php
// netmon – Treiber „Telekom Speedport“ (Smart 3, Smart 4, Smart 4R …)
// Die Statusseite der Weboberfläche lädt /data/Status.json – ohne Anmeldung, aber AES-256-CCM-verschlüsselt mit einem
// festen Schlüssel (Nonce = erste 8 Bytes des Schlüssels, Prüfsumme 16 Bytes am Ende). Ältere Modelle liefern Klartext.
// Quellen: https://github.com/aaronk6/dsl-monitoring (Entschlüsselung), Node-RED-Flow „Get status data from Speedport Smart 4“
// (https://flows.nodered.org/flow/49c9f12d75c31bc41d40059176120a81). Getestet mit einem Speedport Smart 4R Typ A.
// Liefert: Modell, Firmware, DSL-Status und -Sync, LAN-Buchsen mit Tempo, WLAN-Geräte am Speedport mit Signal.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class SpeedportDriver extends SwitchDriver
{
    public const LABEL = 'Telekom Speedport (Smart 3/4, ohne Passwort)';
    public const HINT  = '/speedport|telekom/i';
    public const NEEDS_PASS = false;

    private const KEY = 'cdc0cac1280b516e674f0057e4929bca84447cca8425007e33a88a5cf598a190';

    private ?array $vars = null;
    private array $devices = [];

    public static function detect(string $ip): bool
    {
        $raw = self::probe("http://$ip/data/Status.json");
        return $raw !== null && ($txt = self::decode($raw)) !== null && stripos($txt, 'speedport') !== false;
    }

    // Klartext-JSON oder Hex-Text → JSON-Text; null, wenn es nicht passt
    private static function decode(string $raw): ?string
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
        $key = hex2bin(self::KEY);
        $txt = openssl_decrypt(substr($bin, 0, -16), 'aes-256-ccm', $key, OPENSSL_RAW_DATA, substr($key, 0, 8), substr($bin, -16));
        return $txt === false ? null : $txt;
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
        return [
            'model'    => $this->v('device_name'),
            'firmware' => $this->v('firmware_version'),
            'serial'   => $this->v('serial_number'),
            'name'     => null,
            'checks'   => $checks,
        ];
    }

    // Analyse-Paket: entschlüsselte Statusdaten, Geräteliste nur gezählt
    public function pages(): array
    {
        $out = [];
        foreach ($this->vars ?? [] as $k => $v) {
            if (!preg_match('/pass|pin|password|t_number|serial/i', $k)) {
                $out[] = "$k = $v";
            }
        }
        return ['Status.json (Werte)' => implode("\n", $out), 'Geräte im Heimnetz' => (string) count($this->devices)];
    }
}
