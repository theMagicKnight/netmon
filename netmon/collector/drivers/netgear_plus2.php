<?php
// netmon – Treiber „Netgear Plus, neue Oberfläche“ (GS308E v4, GS305E v2 …)
// Anmeldung wie bei den älteren Plus-Switches (Zufallszahl „rand“ + MD5), die Daten liegen aber auf anderen Seiten:
// dashboard.cgi (Name, Port-Status), portStatistics.cgi (Zähler), cableTester.cgi, loopDetection.cgi, mirroring.cgi.
// Erste Fassung nach Firmware-Quelltext ohne echte Port-Seiten – passt der Aufbau nicht, meldet der Treiber das
// und das Analyse-Paket (mit Anmeldung) liefert die Seiten zum Nachbessern.
// Quellen: Analyse-Paket eines GS308E v4 (Firmware V1.0.1.13) und dessen Firmware-JavaScript;
//   Abgleich mit den Beispielseiten (GS108Ev4, GS308EP) aus https://github.com/foxey/py-netgear-plus (Apache-2.0).

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class NetgearPlus2Driver extends NetgearPlusDriver
{
    public const LABEL = 'Netgear Plus, neue Oberfläche (GS308E v4, GS108E v4, GS305EP/GS308EP(P) …)';
    public const HINT  = '/gs30[58]e ?v?[2-9]|gs108e ?v?4|gs30[58]epp?/i';

    private ?string $dash = null;
    private ?string $model = null;

    public static function detect(string $ip): bool
    {
        $html = self::probe("http://$ip/login.cgi");
        return $html !== null && preg_match('/id=["\']?rand["\']?/i', $html) === 1 && self::isNewUi($html);
    }

    protected function login(): void
    {
        $page = $this->get('login.cgi');
        if (!preg_match('/id=["\']?rand["\']?[^>]*value=["\']?(\d+)/i', $page, $m)
            && !preg_match('/value=["\']?(\d+)["\']?[^>]*id=["\']?rand/i', $page, $m)) {
            throw new RuntimeException('Keine Netgear-Anmeldeseite gefunden (anderes Modell oder Firmware?)');
        }
        $this->model = preg_match('#<title>\s*NETGEAR\s+([^\s<]+)#i', $page, $t) ? $t[1] : null;
        $mix = '';
        for ($i = 0, $n = max(strlen($this->pass), strlen($m[1])); $i < $n; $i++) {
            $mix .= ($this->pass[$i] ?? '') . ($m[1][$i] ?? '');
        }
        $r = $this->get('login.cgi', ['password' => md5($mix)]);
        // Erfolg: die Startdaten kommen ohne Umleitung zur Anmeldeseite
        $dash = $this->get('dashboard.cgi');
        if ($dash === '' || (stripos($dash, 'login.cgi') !== false && stripos($dash, 'port') === false)) {
            $why = preg_match('/pwdErr[^>]*>([^<]{3,})/i', $r, $e) ? ' (' . trim($e[1]) . ')' : '';
            throw new RuntimeException('Anmeldung am Switch fehlgeschlagen – Passwort prüfen (oder ist gerade jemand in der Weboberfläche angemeldet?)' . $why);
        }
        $this->dash = $dash;
    }

    protected function logout(): void
    {
        $this->get('logout.cgi', []);
    }

    private function dash(): string
    {
        return $this->dash ??= $this->get('dashboard.cgi');
    }

    // Teile einer Seite, die mit einem bestimmten class-Namen beginnen (z. B. je Port ein „list_item“)
    private static function blocks(string $html, string $class): array
    {
        $parts = preg_split('#<(?:li|div)\b[^>]*class=["\'][^"\']*\b' . preg_quote($class, '#') . '\b#i', $html);
        array_shift($parts);
        return $parts;
    }

    private static function txt(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<[^>]*>#', ' $0 ', $html)), ENT_QUOTES)));
    }

    public function ports(): array
    {
        $html = $this->dash();
        $pd = stristr($html, 'port_details') ?: $html;
        $ports = [];
        $hid = fn(string $b, string $class) => preg_match('#<input[^>]*class=["\']' . $class . '["\'][^>]*value=["\']([^"\']*)#i', $b, $m)
            || preg_match('#<input[^>]*value=["\']([^"\']*)["\'][^>]*class=["\']' . $class . '["\']#i', $b, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES)) : null;
        foreach (self::blocks($pd, 'list_item') as $i => $b) {
            // GS308E v4: <input class="port" value="1">, <input class="portName">, <input class="LinkedSpeed" value="1000M full">,
            // Status <span class="text-success-1">UP</span> (success-2 = verbunden, aber langsamer)
            $num = $hid($b, 'port');
            $p = $num !== null && ctype_digit($num) ? (int) $num : $i + 1;
            $state = preg_match('#padding_r_18[^>]*>\s*<span[^>]*class=["\']([^"\']*)["\'][^>]*>([^<]*)#i', $b, $m) ? $m : null;
            $link = $state ? (bool) preg_match('/success-[12]/', $state[1]) : (bool) preg_match('/\b(up|hoch|connected)\b/i', self::txt($b));
            $ls = $hid($b, 'LinkedSpeed') ?? self::txt($b);
            $speed = preg_match('/\b(10|100|1000|2500)\s*M/i', $ls, $sm) ? $sm[1] . 'M'
                : (preg_match('/\b([12](?:[.,]5)?)\s*G/i', $ls, $gm) ? (int) round((float) str_replace(',', '.', $gm[1]) * 1000) . 'M' : null);
            $desc = $hid($b, 'portName');
            if ($desc === null && preg_match('#index_li_title.*?<span[^>]*>\s*\d{1,2}\s*-\s*([^<]+?)\s*</span>#is', $b, $tm)) {
                $desc = trim(html_entity_decode($tm[1], ENT_QUOTES));
            }
            $ports[$p] = ['link' => $link, 'speed' => $link ? $speed : null, 'desc' => $desc !== '' ? $desc : null, 'rx' => null, 'tx' => null, 'crc' => null];
            if (count($ports) >= 52) {
                break;
            }
        }
        if (!$ports) {
            throw new SwitchParseError('Angemeldet, aber den Port-Status nicht gefunden – bitte Analyse-Paket erstellen und schicken');
        }
        // Zähler: je Port eine Zeile, je Wert zwei versteckte Felder [oben, unten] (wie bei den älteren Modellen)
        $stats = $this->get('portStatistics.cgi');
        $sec = stristr($stats, 'statistic-ul');
        $rows = $sec !== false ? preg_split('#<li\b#i', $sec) : [];
        array_shift($rows);
        $n = 0;
        foreach ($rows as $row) {
            preg_match_all('#<input[^>]*type=["\']?hidden["\']?[^>]*value=["\']?(\d+)#i', $row, $hm);
            $h = array_map('intval', $hm[1]);
            if (count($h) < 4) {
                continue;   // Kopfzeile
            }
            $n++;
            $p = preg_match('#^[^>]*>\s*(?:<[^>]+>\s*)*(\d{1,2})\s*<#', $row, $pm) ? (int) $pm[1] : $n;
            if (!isset($ports[$p])) {
                continue;
            }
            $ports[$p]['rx']  = $h[0] * 4294967296 + $h[1];
            $ports[$p]['tx']  = $h[2] * 4294967296 + $h[3];
            $ports[$p]['crc'] = isset($h[5]) ? $h[4] * 4294967296 + $h[5] : null;
        }
        ksort($ports);
        return $ports;
    }

    public function info(): array
    {
        $home = $this->get('index.cgi');
        $dash = $this->dash();
        $val = fn(string $html, string $id) => preg_match('#id=["\']' . $id . '["\'][^>]*value=["\']([^"\']*)#i', $html, $m)
            || preg_match('#value=["\']([^"\']*)["\'][^>]*id=["\']' . $id . '["\']#i', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES)) : null;
        $info = [
            'model'    => $val($home, 'prdNam') ?? $this->model ?? (preg_match('#<title>\s*NETGEAR\s+([^<]+)#i', $home, $t) ? trim($t[1]) : null),
            'serial'   => $val($home, 'serNum')
                ?? (preg_match('#ml198</span></div>\s*<div><span>\s*([0-9A-Z]{8,20})\s*<#', $dash, $sn) ? $sn[1] : null),
            'name'     => $val($dash, 'switchName') ?: (preg_match('#id=["\']switch_name["\'][^>]*>([^<]*)#i', $dash, $sn) ? trim($sn[1]) : null),
            'mac'      => preg_match('/\b([0-9A-F]{2}(?::[0-9A-F]{2}){5})\b/i', self::txt(stristr($dash, 'sys_info') ?: $dash), $mm) ? strtoupper($mm[1]) : null,
            'firmware' => preg_match('/\bV?(\d+\.\d+\.\d+(?:\.\d+)?)\b/', self::txt(stristr($dash, 'sys_info') ?: $dash), $fm) ? 'V' . $fm[1] : null,
            'dhcp'     => null,
        ];
        $checks = [];
        $loop = $this->get('loopDetection.cgi');
        if (preg_match('#<input[^>]*(?:id|name)=["\']loop(?:Prev)?Mod["\'][^>]*>#i', $loop, $lm)) {
            $checks[] = stripos($lm[0], 'checked') !== false
                ? ['ok', 'Loop-Erkennung an']
                : ['warn', 'Loop-Erkennung aus – ein Schleifenkabel kann das ganze Netz lahmlegen'];
        }
        $mir = $this->get('mirroring.cgi');
        if (preg_match('#<input[^>]*(?:id|name)=["\'](?:portMirrorMod|mirrorStatus)["\'][^>]*>#i', $mir, $mm)) {
            $checks[] = stripos($mm[0], 'checked') !== false
                ? ['warn', 'Port-Spiegelung ist an – Datenverkehr wird auf einen anderen Port kopiert']
                : ['ok', 'Port-Spiegelung aus'];
        }
        if (preg_match('#id=["\']dhcpMod["\'][^>]*>#i', $dash, $dh) || preg_match('#id=["\']dhcpMod["\'][^>]*>#i', $home, $dh)) {
            if (stripos($dh[0], 'checked') !== false) {
                $info['dhcp'] = 'Enable';
                $checks[] = ['info', 'IP per DHCP – sie kann sich ändern, besser fest vergeben'];
            }
        }
        $info['checks'] = $checks;
        return $info;
    }

    // Kabeltest: Port ist ein paar Sekunden ohne Link; die Weboberfläche wartet 11 s auf das Ergebnis
    public function cableTest(int $port): array
    {
        $page = $this->get('cableTester.cgi');
        $hash = preg_match('#id=["\']hash["\'][^>]*value=["\']?(\w+)#i', $page, $m) || preg_match('#name=["\']hash["\'][^>]*value=["\']?(\w+)#i', $page, $m)
            ? $m[1] : (preg_match('#id=["\']hash["\'][^>]*value=["\']?(\w+)#i', $this->dash(), $m) ? $m[1] : '');
        $r = $this->get('cableTester.cgi', ['hash' => $hash, 'port' . ($port - 1) => 'checked']);
        if (trim($r) === 'CHECK HASH FAILED') {
            throw new RuntimeException('Switch hat den Kabeltest abgelehnt (Sitzung abgelaufen)');
        }
        sleep(11);
        $res = $this->get('cableTester.cgi');
        // GS308E v4: je Port <li class="testResult_li"> mit Port-Span und zwei Ergebnis-Spans (Ergebnis, Entfernung)
        foreach (self::blocks($res, 'testResult_li') as $b) {
            if (!preg_match('#testResult_content_port[^>]*>\s*(\d{1,2})#i', $b, $pm) || (int) $pm[1] !== $port) {
                continue;
            }
            preg_match_all('#testResult_content_result[^>]*>(.*?)</span>#is', $b, $rm);
            $vals = array_map(fn($v) => $this->lang(self::txt($v)), $rm[1]);
            if (($vals[0] ?? '') !== '') {
                return ['result' => $vals[0], 'dist' => ($vals[1] ?? '') !== '' ? preg_replace('/\s*m$/i', '', $vals[1]) : null];
            }
            throw new SwitchParseError('Kabeltest gelaufen, aber noch kein Ergebnis – bitte gleich noch einmal versuchen');
        }
        foreach (self::table($res, ['port' => 'Port', 'result' => 'Test Results', 'dist' => 'Cable Fault Distance']) as $row) {
            if ((int) ($row['port'] ?? 0) === $port && ($row['result'] ?? '') !== '') {
                return ['result' => $row['result'], 'dist' => ($row['dist'] ?? '') !== '' ? $row['dist'] : null];
            }
        }
        // Freier Aufbau: Text rund um „Port n“ im Ergebnisbereich
        $t = self::txt(stristr($res, 'testResult') ?: $res);
        if (preg_match('/Port\s*' . $port . '\b\s*[:\-]?\s*([A-Za-zÄÖÜäöü ]{2,30}?)(?:\s+(\d+(?:[.,]\d+)?)\s*m\b)?(?=\s+Port\s*\d|$)/u', $t, $m)) {
            return ['result' => trim($m[1]), 'dist' => $m[2] ?? null];
        }
        throw new SwitchParseError('Kabeltest gelaufen, Ergebnis aber nicht lesbar – bitte Analyse-Paket schicken');
    }

    // Die Oberfläche zeigt Platzhalter wie „ml177“ und übersetzt sie per de.js/en.js – hier dasselbe für Ergebnisse
    private ?array $langMap = null;

    private function lang(string $v): string
    {
        if (!preg_match('/^ml\d{3}$/', $v)) {
            return $v;
        }
        if ($this->langMap === null) {
            $this->langMap = [];
            foreach (['de.js', 'en.js'] as $f) {
                try {
                    $js = $this->get($f);
                } catch (RuntimeException $e) {
                    continue;
                }
                if (strncmp($js, "\x1f\x8b", 2) === 0) {
                    $js = (string) @gzdecode($js);
                }
                if (preg_match_all('/["\']?(ml\d{3})["\']?\s*[:=]\s*["\']([^"\']*)["\']/', $js, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $m) {
                        $this->langMap[$m[1]] ??= $m[2];
                    }
                    break;
                }
            }
        }
        return $this->langMap[$v] ?? $v;
    }

    public function pages(): array
    {
        $out = [];
        foreach (['index.cgi', 'dashboard.cgi', 'portStatistics.cgi', 'cableTester.cgi', 'loopDetection.cgi', 'mirroring.cgi',
                  'rateLimit.cgi', 'qos.cgi', 'lag.cgi', 'multicast.cgi', 'switchDiscovery.cgi', 'settings.cgi', 'ip_dhcp.cgi',
                  'httpConfiguration.cgi', 'green_ethernet.cgi', 'de.js'] as $p) {
            try {
                $html = $this->get($p);
            } catch (RuntimeException $e) {
                $html = '(Fehler: ' . $e->getMessage() . ')';
            }
            if (strncmp($html, "\x1f\x8b", 2) === 0) {
                $html = (string) @gzdecode($html);
            }
            $out[$p === '' ? '/' : $p] = $html;
        }
        return $out;
    }
}
