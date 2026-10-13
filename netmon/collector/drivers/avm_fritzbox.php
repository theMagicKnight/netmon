<?php
// netmon – Treiber „AVM FRITZ!Box“ über TR-064 (offizielle Schnittstelle, Port 49000)
// In der FritzBox: Heimnetz → Netzwerk → Netzwerkeinstellungen → „Zugriff für Anwendungen zulassen“
// und „Statusinformationen über UPnP übertragen“; dazu ein eigener FRITZ!Box-Benutzer mit dem Recht „FRITZ!Box Einstellungen“.
// Quellen: AVM-Schnittstellenbeschreibung TR-064 (https://avm.de/service/schnittstellen/) und
//   Testabfragen an einer FRITZ!Box 7590 (GetAddonInfos, WANDSLInterfaceConfig).

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class FritzBoxDriver extends SwitchDriver
{
    public const LABEL = 'AVM FRITZ!Box (TR-064: DSL, Internet, LAN-Ports)';
    public const HINT  = '/fritz|avm/i';
    public const NEEDS_USER = true;

    private const NS = 'urn:dslforum-org:service:';
    private ?array $hosts = null;
    private ?array $m = null;

    public static function detect(string $ip): bool
    {
        $xml = self::probe("http://$ip:49000/tr64desc.xml");
        return $xml !== null && preg_match('/FRITZ!?Box|AVM/i', $xml) === 1;
    }

    protected function login(): void
    {
        if ($this->user === '') {
            throw new RuntimeException('Für die FritzBox bitte auch den Benutzernamen eintragen (FRITZ!Box-Benutzer mit Recht „FRITZ!Box Einstellungen“)');
        }
        $this->tr('deviceinfo', 'DeviceInfo:1', 'GetInfo');   // prüft gleich die Anmeldung
    }

    protected function logout(): void
    {
        // TR-064 meldet jede Anfrage einzeln an – nichts zu tun
    }

    // TR-064-Aufruf mit Anmeldung (Digest) -> [Feld ohne „New“ => Wert]
    private function tr(string $ctl, string $svc, string $action, array $args = []): array
    {
        return $this->soap("http://{$this->ip}:49000/upnp/control/$ctl", self::NS . $svc, $action, $args, true);
    }

    private function soap(string $url, string $ns, string $action, array $args, bool $auth): array
    {
        $body = '';
        foreach ($args as $k => $v) {
            $body .= "<$k>" . htmlspecialchars((string) $v, ENT_XML1) . "</$k>";
        }
        $xml = '<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" '
            . 's:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/"><s:Body>'
            . "<u:$action xmlns:u=\"$ns\">$body</u:$action></s:Body></s:Envelope>";
        $c = curl_init($url);
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml,
            CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset="utf-8"', "SoapAction: $ns#$action"],
        ]);
        if ($auth) {
            curl_setopt_array($c, [CURLOPT_HTTPAUTH => CURLAUTH_ANY, CURLOPT_USERPWD => $this->user . ':' . $this->pass]);
        }
        $r = curl_exec($c);
        $code = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        if ($r === false) {
            throw new RuntimeException("FritzBox {$this->ip} nicht erreichbar (Port 49000)");
        }
        if ($code === 401) {
            throw new RuntimeException('Anmeldung an der FritzBox fehlgeschlagen – Benutzer/Passwort prüfen');
        }
        if ($code !== 200) {
            $err = preg_match('#<errorDescription>([^<]*)#', (string) $r, $m) ? $m[1] : "HTTP $code";
            throw new RuntimeException("FritzBox: $action – $err");
        }
        $out = [];
        if (preg_match_all('#<New([\w\-]+)>([^<]*)</New\1>#', (string) $r, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out[$m[1]] = html_entity_decode($m[2], ENT_QUOTES | ENT_XML1);
            }
        }
        return $out;
    }

    private function try(callable $fn): ?array
    {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            return null;
        }
    }

    // Heimnetz-Liste der FritzBox (alle bekannten Geräte mit Anschlussart, LAN-Port und Tempo)
    private function hosts(): array
    {
        if ($this->hosts !== null) {
            return $this->hosts;
        }
        $path = $this->tr('hosts', 'Hosts:1', 'X_AVM-DE_GetHostListPath')['X_AVM-DE_HostListPath'] ?? '';
        $this->hosts = [];
        if ($path === '') {
            return $this->hosts;
        }
        $c = curl_init("http://{$this->ip}:49000/" . ltrim($path, '/'));
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 10]);
        $xml = (string) curl_exec($c);
        curl_close($c);
        foreach (preg_split('#<Item>#', $xml) as $it) {
            $f = fn($k) => preg_match('#<' . preg_quote($k, '#') . '>([^<]*)</' . preg_quote($k, '#') . '>#', $it, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_XML1) : '';
            $mac = strtoupper($f('MACAddress'));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                continue;
            }
            $this->hosts[] = [
                'mac' => $mac, 'ip' => $f('IPAddress'), 'name' => $f('HostName'), 'active' => $f('Active') === '1',
                'iface' => $f('InterfaceType'), 'port' => (int) $f('X_AVM-DE_Port'), 'speed' => (int) $f('X_AVM-DE_Speed'),
                'guest' => $f('X_AVM-DE_Guest') === '1',
            ];
        }
        return $this->hosts;
    }

    // LAN-Buchsen aus der Heimnetz-Liste: Link, wenn dort ein aktives Gerät hängt. Datenraten je Port liefert TR-064 nicht.
    public function ports(): array
    {
        $ports = [];
        for ($p = 1; $p <= 4; $p++) {
            $ports[$p] = ['link' => false, 'speed' => null, 'desc' => null, 'rx' => null, 'tx' => null, 'crc' => null];
        }
        foreach ($this->try(fn() => $this->hosts()) ?? [] as $h) {
            if (!$h['active'] || $h['iface'] !== 'Ethernet' || $h['port'] < 1 || $h['port'] > 8) {
                continue;
            }
            $p = &$ports[$h['port']];
            $p ??= ['link' => false, 'speed' => null, 'desc' => null, 'rx' => null, 'tx' => null, 'crc' => null];
            $p['link'] = true;
            if ($h['speed'] > (int) $p['speed']) {
                $p['speed'] = $h['speed'] . 'M';
            }
            unset($p);
        }
        ksort($ports);
        return $ports;
    }

    // Messwerte für Verlauf und Live: Internet-Rate, DSL-Sync, Störabstand, Fehlerzähler (Raten in Bit/s, dB als Zahl)
    public function metrics(): array
    {
        if ($this->m !== null) {
            return $this->m;
        }
        $m = [];
        $igd = $this->try(fn() => $this->soap("http://{$this->ip}:49000/igdupnp/control/WANCommonIFC1",
            'urn:schemas-upnp-org:service:WANCommonInterfaceConfig:1', 'GetAddonInfos', [], false));
        if ($igd) {
            $m['wan_rx'] = (int) ($igd['ByteReceiveRate'] ?? 0) * 8;
            $m['wan_tx'] = (int) ($igd['ByteSendRate'] ?? 0) * 8;
        }
        $dsl = $this->try(fn() => $this->tr('wandslifconfig1', 'WANDSLInterfaceConfig:1', 'GetInfo'));
        if ($dsl) {
            $m['dsl_ok']   = strcasecmp($dsl['Status'] ?? '', 'Up') === 0 ? 1 : 0;
            $m['dsl_down'] = (int) ($dsl['DownstreamCurrRate'] ?? 0) * 1000;
            $m['dsl_up']   = (int) ($dsl['UpstreamCurrRate'] ?? 0) * 1000;
            $m['max_down'] = (int) ($dsl['DownstreamMaxRate'] ?? 0) * 1000;
            $m['max_up']   = (int) ($dsl['UpstreamMaxRate'] ?? 0) * 1000;
            $m['snr_down'] = isset($dsl['DownstreamNoiseMargin']) ? (int) $dsl['DownstreamNoiseMargin'] / 10 : null;
            $m['snr_up']   = isset($dsl['UpstreamNoiseMargin']) ? (int) $dsl['UpstreamNoiseMargin'] / 10 : null;
            $m['att_down'] = isset($dsl['DownstreamAttenuation']) ? (int) $dsl['DownstreamAttenuation'] / 10 : null;
        }
        $st = $this->try(fn() => $this->tr('wandslifconfig1', 'WANDSLInterfaceConfig:1', 'GetStatisticsTotal'));
        if ($st) {
            $m['crc_total'] = isset($st['CRCErrors']) ? (int) $st['CRCErrors'] : null;
            $m['fec_total'] = isset($st['FECErrors']) ? (int) $st['FECErrors'] : null;
        }
        return $this->m = $m;
    }

    public function info(): array
    {
        $dev = $this->try(fn() => $this->tr('deviceinfo', 'DeviceInfo:1', 'GetInfo')) ?? [];
        $wan = $this->try(fn() => $this->tr('wanpppconn1', 'WANPPPConnection:1', 'GetInfo'))
            ?? $this->try(fn() => $this->tr('wanipconnection1', 'WANIPConnection:1', 'GetInfo')) ?? [];
        $m = $this->metrics();
        $checks = [];
        $mbit = fn($b) => number_format($b / 1e6, 1, ',', '.') . ' Mbit/s';
        if (isset($m['dsl_ok'])) {
            if ($m['dsl_ok']) {
                $checks[] = ['ok', 'DSL synchron: ↓ ' . $mbit($m['dsl_down']) . ' / ↑ ' . $mbit($m['dsl_up'])];
                if ($m['max_down'] > 0) {
                    $pct = (int) round($m['dsl_down'] / $m['max_down'] * 100);
                    $checks[] = ['info', "Sync bei $pct % dessen, was die Leitung schafft (↓ {$mbit($m['max_down'])})"];
                }
            } else {
                $checks[] = ['warn', 'DSL nicht synchron'];
            }
        }
        if (isset($m['snr_down'])) {
            $snr = min($m['snr_down'], $m['snr_up'] ?? 99);
            $txt = 'Störabstand ↓ ' . str_replace('.', ',', (string) $m['snr_down']) . ' dB / ↑ ' . str_replace('.', ',', (string) ($m['snr_up'] ?? '–')) . ' dB';
            $checks[] = $snr < 6 ? ['warn', $txt . ' – knapp, Abbrüche möglich'] : ['ok', $txt];
        }
        if (isset($m['crc_total'])) {
            $checks[] = $m['crc_total'] > 0
                ? ['info', number_format($m['crc_total'], 0, ',', '.') . ' CRC-Fehler seit der letzten Synchronisation']
                : ['ok', 'Keine CRC-Fehler seit der letzten Synchronisation'];
        }
        if (!isset($m['wan_rx'])) {
            $checks[] = ['info', 'Internet-Rate fehlt – in der FritzBox „Statusinformationen über UPnP übertragen“ einschalten'];
        }
        if (($wan['ConnectionStatus'] ?? '') !== '') {
            $up = (int) ($wan['Uptime'] ?? 0);
            $checks[] = $wan['ConnectionStatus'] === 'Connected'
                ? ['ok', 'Internet verbunden seit ' . ($up >= 172800 ? floor($up / 86400) . ' Tagen' : ($up >= 86400 ? '1 Tag' : floor($up / 3600) . ' Std.'))
                    . (($wan['ExternalIPAddress'] ?? '') !== '' ? ' · IP ' . $wan['ExternalIPAddress'] : '')]
                : ['warn', 'Internet: ' . $wan['ConnectionStatus']];
        }
        $lan = [];
        foreach ($this->try(fn() => $this->hosts()) ?? [] as $h) {
            if ($h['active'] && $h['iface'] === 'Ethernet' && $h['port'] > 0) {
                $lan[$h['port']][] = $h['mac'];
            }
        }
        return [
            'model' => $dev['ModelName'] ?? null, 'firmware' => $dev['SoftwareVersion'] ?? null, 'serial' => $dev['SerialNumber'] ?? null,
            'name' => null, 'checks' => $checks, 'lanHosts' => $lan ?: null,
        ];
    }

    // Für das Analyse-Paket: Gerätebeschreibung und die wichtigsten Antworten (ohne Passwörter)
    public function pages(): array
    {
        $out = [];
        $c = curl_init("http://{$this->ip}:49000/tr64desc.xml");
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6]);
        $out['tr64desc.xml'] = (string) curl_exec($c);
        curl_close($c);
        foreach ([['deviceinfo', 'DeviceInfo:1', 'GetInfo'], ['wandslifconfig1', 'WANDSLInterfaceConfig:1', 'GetInfo'],
                  ['wandslifconfig1', 'WANDSLInterfaceConfig:1', 'GetStatisticsTotal'], ['wanpppconn1', 'WANPPPConnection:1', 'GetInfo']] as [$ctl, $svc, $act]) {
            $r = $this->try(fn() => $this->tr($ctl, $svc, $act));
            $out["$svc $act"] = $r === null ? '(Fehler)' : print_r($r, true);
        }
        $out['Heimnetz (Anzahl)'] = (string) count($this->try(fn() => $this->hosts()) ?? []);
        return $out;
    }
}
