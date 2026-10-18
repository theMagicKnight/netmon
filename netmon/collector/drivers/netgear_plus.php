<?php
// netmon – Treiber „Netgear Plus“ (GS105E v2, GS108E v3, GS305E, GS308E …)
// Weboberfläche mit login.cgi: Zufallszahl „rand“, Passwort damit Zeichen für Zeichen verschränkt, als MD5 gesendet.
// Quellen: eigene Mitschnitte eines GS105E v2 (Firmware V1.6.0.11); Modellliste und Abgleich mit
//   https://github.com/foxey/py-netgear-plus (Apache-2.0) – kein Code übernommen.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

class NetgearPlusDriver extends SwitchDriver
{
    public const LABEL = 'Netgear Plus (GS105E v2, GS108E v3, GS305E, GS308E, GSS108E …)';
    public const HINT  = '/netgear|^gs\d{3}e|prosafe|plus/i';   // Name/Hersteller, bei dem dieser Treiber vorgeschlagen wird

    // Neue Oberfläche (GS308E v4, GS305E v2 …): eigene Anmeldeseite mit „signin-button“ – dafür gibt es netgear_plus2
    protected static function isNewUi(string $loginHtml): bool
    {
        return preg_match('/signin-button|loginBody|b_md5\.js/i', $loginHtml) === 1;
    }

    // Erkennung ohne Passwort: Anmeldeseite mit Zufallszahl
    public static function detect(string $ip): bool
    {
        $html = self::probe("http://$ip/login.cgi");
        return $html !== null && preg_match('/id=["\']?rand["\']?/i', $html) === 1 && !self::isNewUi($html)
            && !preg_match('#<title>\s*NETGEAR\s+GS316E|redirect\.html#i', $html);
    }

    protected function login(): void
    {
        $page = $this->get('login.cgi');
        if (!preg_match('/id=["\']?rand["\']?[^>]*value=["\']?(\d+)/i', $page, $m)
            && !preg_match('/value=["\']?(\d+)["\']?[^>]*id=["\']?rand/i', $page, $m)) {
            throw new RuntimeException('Keine Netgear-Plus-Anmeldeseite gefunden (anderes Modell oder Firmware?)');
        }
        $mix = '';
        for ($i = 0, $n = max(strlen($this->pass), strlen($m[1])); $i < $n; $i++) {
            $mix .= ($this->pass[$i] ?? '') . ($m[1][$i] ?? '');
        }
        $this->get('login.cgi', ['password' => md5($mix)]);
        if (stripos($this->cookies(), 'SID') === false) {
            throw new RuntimeException('Anmeldung am Switch fehlgeschlagen – Passwort prüfen (oder ist gerade jemand in der Weboberfläche angemeldet?)');
        }
    }

    protected function logout(): void
    {
        $this->get('logout.cgi');
    }

    // Link, Geschwindigkeit, Beschreibung und Zähler je Port
    // Zeilen von status.cgi: je Port die versteckten Felder [Port, Status, Tempo-Einstellung, Tempo, Flusssteuerung, MTU]
    // und die Beschreibung (Zelle sel="input"). Tempo-Einstellung: 1 Auto, 2 Disable, 3–6 10/100M halb/voll
    private static function statusRows(string $html): array
    {
        $out = [];
        $parts = preg_split('#<tr[^>]*class=["\']portID["\']#i', $html);
        array_shift($parts);
        foreach ($parts as $tr) {
            $tr = preg_split('#</tr>#i', $tr)[0];
            preg_match_all('#<input[^>]*type=["\']?hidden["\']?[^>]*value=["\']([^"\']*)#i', $tr, $hm);
            $h = $hm[1];
            if (!isset($h[0]) || !ctype_digit($h[0])) {
                continue;
            }
            $out[(int) $h[0]] = ['speedCfg' => $h[2] ?? null, 'flow' => $h[4] ?? null,
                'desc' => preg_match('#sel=["\']input["\'][^>]*>([^<]*)<#i', $tr, $dm) ? trim(html_entity_decode($dm[1], ENT_QUOTES)) : ''];
        }
        return $out;
    }

    public function ports(): array
    {
        $ports = [];
        $statusHtml = $this->get('status.cgi');
        $cfg = self::statusRows($statusHtml);
        foreach (self::table($statusHtml, ['port' => 'Port', 'desc' => 'Port Description', 'state' => 'Port Status', 'speed' => 'Linked Speed']) as $r) {
            if (!ctype_digit($r['port'] ?? '')) {
                continue;
            }
            $ports[(int) $r['port']] = [
                'link'  => strcasecmp($r['state'] ?? '', 'Up') === 0,
                'off'   => ($cfg[(int) $r['port']]['speedCfg'] ?? '') === '2',
                'speed' => preg_match('/^\d+M$/i', $r['speed'] ?? '') ? $r['speed'] : null,
                'desc'  => ($r['desc'] ?? '') !== '' ? $r['desc'] : null,
                'rx' => null, 'tx' => null, 'crc' => null,
            ];
        }
        // Zähler: Die Seite zeigt nur die unteren 32 Bit; in versteckten Feldern stehen je Wert [oben, unten]
        $stats = $this->get('portStatistics.cgi');
        foreach (preg_split('#<tr\b#i', $stats) as $tr) {
            if (stripos($tr, 'portID') === false || !preg_match('#<td[^>]*>\s*(\d{1,2})\s*</td>#', $tr, $pm)) {
                continue;
            }
            $p = (int) $pm[1];
            preg_match_all('#<input[^>]*type=["\']?hidden["\']?[^>]*value=["\']?(\d+)#i', $tr, $hm);
            $h = array_map('intval', $hm[1]);
            if (!isset($ports[$p]) || count($h) < 4) {
                continue;
            }
            $ports[$p]['rx']  = $h[0] * 4294967296 + $h[1];
            $ports[$p]['tx']  = $h[2] * 4294967296 + $h[3];
            $ports[$p]['crc'] = isset($h[5]) ? $h[4] * 4294967296 + $h[5] : null;
        }
        if (!$ports) {
            throw new SwitchParseError('Angemeldet, aber keine Ports gefunden (Seitenaufbau unbekannt – bitte Analyse-Paket erstellen)');
        }
        ksort($ports);
        return $ports;
    }

    // Modell, Name, Firmware … und ein Gesundheits-Check der Einstellungen
    public function info(): array
    {
        $html = $this->get('switch_info.cgi');
        $row = function (string $label) use ($html): ?string {
            if (!preg_match('#>\s*' . preg_quote($label, '#') . '\s*</td>\s*<td[^>]*>(.*?)</td>#is', $html, $m)) {
                return null;
            }
            if (preg_match('#<input[^>]*value=["\']([^"\']*)#i', $m[1], $v)) {
                return trim($v[1]);
            }
            if (preg_match('#<option[^>]*selected[^>]*>([^<]*)#i', $m[1], $v)) {
                return trim($v[1]);
            }
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES)) ?: null;
        };
        $info = [
            'model'    => $row('Product Name'),
            'name'     => $row('Switch Name'),
            'serial'   => $row('Serial Number'),
            'mac'      => $row('MAC Address'),
            'firmware' => $row('Firmware Version'),
            'dhcp'     => $row('DHCP Mode'),
        ];

        $checks = [];
        $radio = function (string $page, string $field): ?string {
            $h = $this->get($page);
            return preg_match('#<input[^>]*name=["\']' . $field . '["\'][^>]*checked[^>]*/?>\s*(?:<span>)?([^<]+)#i', $h, $m)
                ? trim($m[1]) : null;
        };
        $loop = $radio('loop_detection.cgi', 'loopDetection');
        if ($loop !== null) {
            $checks[] = stripos($loop, 'enable') !== false
                ? ['ok', 'Loop-Erkennung an']
                : ['warn', 'Loop-Erkennung aus – ein Schleifenkabel kann das ganze Netz lahmlegen'];
        }
        $mgmt = $radio('plus_utility.cgi', 'plusUtility');
        if ($mgmt !== null) {
            $checks[] = stripos($mgmt, 'only') !== false
                ? ['ok', 'Verwaltung nur über den Browser']
                : ['info', 'Verwaltung auch über das Plus-Utility erlaubt'];
        }
        $mirror = $this->get('mirror.cgi');
        if (preg_match('#<option value="0"\s*selected>\s*Enable#i', $mirror)) {
            $checks[] = ['warn', 'Port-Spiegelung ist an – Datenverkehr wird auf einen anderen Port kopiert'];
        } elseif (stripos($mirror, 'Disable') !== false) {
            $checks[] = ['ok', 'Port-Spiegelung aus'];
        }
        $limited = [];
        foreach (self::table($this->get('rateLimit.cgi'), ['port' => 'Port', 'in' => 'Ingress Rate', 'out' => 'Egress Rate']) as $r) {
            if (ctype_digit($r['port'] ?? '') && (($r['in'] ?? '') !== 'No Limit' || ($r['out'] ?? '') !== 'No Limit')) {
                $limited[] = "Port {$r['port']} ({$r['in']} / {$r['out']})";
            }
        }
        $checks[] = $limited
            ? ['warn', 'Bandbreite begrenzt: ' . implode(', ', $limited)]
            : ['ok', 'Keine Bandbreitenbegrenzung'];
        if ($info['dhcp'] !== null && stripos($info['dhcp'], 'enable') !== false) {
            $checks[] = ['info', 'IP per DHCP – sie kann sich ändern, besser fest vergeben'];
        }
        $info['checks'] = $checks;
        return $info;
    }

    // Kabeltest: der Port ist dabei ein paar Sekunden ohne Link
    // Port ab-/einschalten wie in der Oberfläche (Port Status → Haken → Speed „Disable“/„Auto“ → Apply);
    // Beschreibung und Flusssteuerung werden unverändert mitgeschickt
    public function setPortEnabled(int $port, bool $on): void
    {
        $page = $this->get('status.cgi');
        $row = self::statusRows($page)[$port] ?? null;
        if ($row === null || $row['speedCfg'] === null) {
            throw new SwitchParseError("Einstellungen von Port $port nicht gefunden – bitte Analyse-Paket schicken");
        }
        $hash = preg_match('#name=["\']hash["\'][^>]*value=["\']?(\w+)#i', $page, $m) ? $m[1] : '';
        $speed = $on ? ($row['speedCfg'] === '2' ? 1 : (int) $row['speedCfg']) : 2;
        $r = $this->get('status.cgi', ['DESCRIPTION' => $row['desc'], 'SPEED' => $speed, 'FLOW_CONTROL' => $row['flow'] ?? 2,
                                       "port$port" => 'checked', 'hash' => $hash]);
        if (stripos($r, 'CHECK HASH FAILED') !== false) {
            throw new RuntimeException('Switch hat das Schalten abgelehnt (Sitzung abgelaufen)');
        }
        $now = self::statusRows($r ?: $this->get('status.cgi'))[$port]['speedCfg'] ?? null;
        if ($now !== null && ($now === '2') === $on) {
            throw new RuntimeException('Switch hat die Änderung nicht übernommen');
        }
    }

    public function cableTest(int $port): array
    {
        $page = $this->get('cableTester.cgi');
        $hash = preg_match('#name=["\']hash["\'][^>]*value=["\']?(\w+)#i', $page, $m) ? $m[1] : '';
        $res = $this->get('cableTester.cgi', ['port' . ($port - 1) => 'checked', 'hash' => $hash]);
        foreach ([$res, $this->get('cableTester.cgi')] as $html) {
            foreach (self::table($html, ['port' => 'Port', 'result' => 'Test Results', 'dist' => 'Cable Fault Distance']) as $r) {
                if ((int) ($r['port'] ?? 0) === $port && ($r['result'] ?? '') !== '') {
                    return ['result' => $r['result'], 'dist' => ($r['dist'] ?? '') !== '' ? $r['dist'] : null];
                }
            }
        }
        throw new RuntimeException('Der Switch hat kein Testergebnis geliefert');
    }

    // Alle Seiten der Weboberfläche roh einsammeln (nur lesen), für die Analyse neuer Modelle
    public function pages(): array
    {
        $out = [];
        $queue = ['index.cgi', 'switch_info.cgi', 'status.cgi', 'portStatistics.cgi', 'frame.js', 'function.js'];
        $skip = '/logout|reboot|restart|factory|default|reset|upgrade|fwug|firmware|restore|backup|save|delete|clear|registration|changeDefPwd/i';
        while ($queue && count($out) < 60) {
            $p = preg_replace('/[?#].*$/', '', ltrim(array_shift($queue), '/'));
            if ($p === '' || isset($out[$p]) || preg_match($skip, $p)) {
                continue;
            }
            $html = $this->get($p);
            if (strncmp($html, "\x1f\x8b", 2) === 0) {
                $html = (string) @gzdecode($html);   // manche Firmwares liefern .js gepackt aus
            }
            $out[$p] = $html;
            preg_match_all('/["\'\/]([\w\-\/]+\.(?:cgi|htm|html|js))(?=[?"\'#])/i', $html, $mm);
            array_push($queue, ...array_unique($mm[1]));
        }
        return $out;
    }

    // Tabelle nach Spaltenüberschriften auslesen: [schlüssel => Überschrift] -> Liste von Zeilen
    protected static function table(string $html, array $cols): array
    {
        $html = preg_replace('#<script\b.*?</script>#is', '', $html);
        $map = null;
        $rows = [];
        foreach (preg_split('#<tr\b#i', $html) as $tr) {   // manche Seiten schließen </tr> nicht
            if (!preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $tr, $m)) {
                continue;
            }
            $cells = array_map(fn($v) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(preg_replace('#<(select|option)\b.*?</\1>#is', '', $v)), ENT_QUOTES))), $m[1]);
            if ($map === null) {
                $found = [];
                foreach ($cols as $k => $label) {
                    $i = array_search(strtolower($label), array_map(fn($c) => strtolower(preg_replace('/\s*\(.*\)$/', '', $c)), $cells), true);
                    if ($i !== false) {
                        $found[$k] = $i;
                    }
                }
                if (isset($found['port']) && count($found) >= 2) {
                    $map = $found;   // Kopfzeile gefunden
                }
                continue;
            }
            $row = [];
            foreach ($map as $k => $i) {
                $row[$k] = $cells[$i] ?? '';
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
