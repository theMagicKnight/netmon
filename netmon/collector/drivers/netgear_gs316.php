<?php
// netmon – Treiber „Netgear GS316EP / GS316EPP“ (16 Ports, PoE)
// Eigene Oberfläche: Anmeldung an homepage.html (Zufallszahl + MD5 wie bei den Plus-Switches), danach liefert der Switch
// ein „Gambit“-Kennwort, das jede Seite unter iss/specific/ als Parameter und Cookie braucht.
// Seitenaufbau und Anmeldung nach py-netgear-plus (https://github.com/foxey/py-netgear-plus, Apache-2.0),
// getestet gegen die dort veröffentlichten Beispielseiten eines GS316EPP – noch nicht an echter Hardware.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class NetgearGs316Driver extends SwitchDriver
{
    public const LABEL = 'Netgear GS316EP / GS316EPP (PoE)';
    public const HINT  = '/gs316e/i';

    private string $gambit = '';
    private ?string $dash = null;
    private ?string $model = null;

    public static function detect(string $ip): bool
    {
        foreach (["http://$ip/", "http://$ip/login.htm"] as $url) {
            $html = self::probe($url);
            if ($html !== null && preg_match('#<title>\s*NETGEAR\s+GS316E#i', $html)) {
                return true;
            }
        }
        return false;
    }

    protected function login(): void
    {
        $page = $this->get('');
        $this->model = preg_match('#<title>\s*NETGEAR\s+(GS3\w+)#i', $page, $t) ? $t[1] : null;
        if (!preg_match('/id=["\']?rand["\']?[^>]*value=["\']?(\d+)/i', $page, $m)
            && !preg_match('/value=["\']?(\d+)["\']?[^>]*id=["\']?rand/i', $page, $m)) {
            $page = $this->get('login.htm');
            if (!preg_match('/id=["\']?rand["\']?[^>]*value=["\']?(\d+)/i', $page, $m)) {
                throw new RuntimeException('Keine Anmeldeseite mit Zufallszahl gefunden (andere Firmware?) – bitte Analyse-Paket schicken');
            }
        }
        $mix = '';
        for ($i = 0, $n = max(strlen($this->pass), strlen($m[1])); $i < $n; $i++) {
            $mix .= ($this->pass[$i] ?? '') . ($m[1][$i] ?? '');
        }
        $r = $this->get('homepage.html', ['LoginPassword' => md5($mix)]);
        if (!preg_match('#name=["\']Gambit["\'][^>]*value=["\']([^"\']+)#i', $r, $g)
            && !preg_match('#value=["\']([^"\']+)["\'][^>]*name=["\']Gambit["\']#i', $r, $g)) {
            throw new RuntimeException('Anmeldung am Switch fehlgeschlagen – Passwort prüfen (oder ist gerade jemand in der Weboberfläche angemeldet?)');
        }
        $this->gambit = $g[1];
        $this->setCookie('gambitCookie', $this->gambit);
    }

    protected function logout(): void
    {
        if ($this->gambit !== '') {
            $this->page('logout.html');
        }
    }

    private function page(string $name, array $extra = []): string
    {
        return $this->get('iss/specific/' . $name . '?' . http_build_query(['Gambit' => $this->gambit] + $extra));
    }

    private function dash(): string
    {
        return $this->dash ??= $this->page('dashboard.html');
    }

    private static function txt(string $h): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES)));
    }

    public function ports(): array
    {
        $html = $this->dash();
        $ports = [];
        // je Port: <span class="port-number">1</span> … <span class='name'>…</span> … <span class='status-on-port port-connected …'>
        //          … <p class="bold-title link-speed-text">100M Full</p>
        $parts = preg_split('#<span[^>]*class=["\']port-number["\'][^>]*>#i', $html);
        array_shift($parts);
        foreach ($parts as $b) {
            if (!preg_match('#^\s*(\d{1,2})\s*<#', $b, $pm)) {
                continue;
            }
            $p = (int) $pm[1];
            $name = preg_match('#class=["\']name["\'][^>]*>([^<]*)<#i', $b, $nm) ? trim(html_entity_decode($nm[1], ENT_QUOTES)) : '';
            $st = preg_match('#<span[^>]*class=["\']([^"\']*status-on-port[^"\']*)["\'][^>]*>([^<]*)#i', $b, $sm) ? $sm : null;
            $link = $st ? (stripos($st[1], 'port-connected') !== false || preg_match('/connected|up|verbunden/i', $st[2]) && !preg_match('/dis/i', $st[2])) : false;
            $speed = preg_match('#link-speed-text[^>]*>\s*(\d+)\s*M#i', $b, $lm) ? $lm[1] . 'M'
                : (preg_match('#link-speed-text[^>]*>\s*(\d+(?:\.\d+)?)\s*G#i', $b, $gm) ? (int) ((float) $gm[1] * 1000) . 'M' : null);
            $ports[$p] = ['link' => (bool) $link, 'speed' => $link ? $speed : null, 'desc' => $name !== '' ? $name : null,
                          'rx' => null, 'tx' => null, 'crc' => null];
        }
        if (!$ports) {
            throw new SwitchParseError('Angemeldet, aber keine Ports gefunden – bitte Analyse-Paket erstellen und schicken');
        }
        // Tabelle tbl1: Port | Bytes empfangen | Bytes gesendet | CRC-Fehler
        $stats = $this->page('interface_stats.html');
        foreach (preg_split('#<tr\b#i', $stats) as $tr) {
            preg_match_all('#<td[^>]*>(.*?)(?=<td|</tr|<tr|$)#is', $tr, $cm);
            $c = array_map(fn($v) => self::txt($v), $cm[1]);
            if (count($c) >= 4 && ctype_digit($c[0]) && isset($ports[(int) $c[0]])) {
                $p = (int) $c[0];
                $ports[$p]['rx']  = ctype_digit($c[1]) ? (int) $c[1] : null;
                $ports[$p]['tx']  = ctype_digit($c[2]) ? (int) $c[2] : null;
                $ports[$p]['crc'] = ctype_digit($c[3]) ? (int) $c[3] : null;
            }
        }
        ksort($ports);
        return $ports;
    }

    public function info(): array
    {
        $html = $this->dash();
        $after = fn(string $label) => preg_match('#' . preg_quote($label, '#') . '\s*</p>\s*<p[^>]*>\s*([^<]+)#i', $html, $m) ? trim($m[1]) : null;
        $info = [
            'model'    => $this->model ?? (preg_match('#<title>\s*NETGEAR\s+(GS3\w+)#i', $html, $t) ? $t[1] : 'GS316EP'),
            'name'     => preg_match('#name=["\']switchName["\'][^>]*value=["\']([^"\']*)#i', $html, $n) ? trim($n[1]) : null,
            'serial'   => $after('Serial Number'),
            'firmware' => ($fw = $after('Firmware Version')) ? 'V' . ltrim($fw, 'Vv') : null,
            'mac'      => $after('MAC Address'),
            'checks'   => [],
        ];
        // PoE: abgegebene Leistung je Port aufsummieren
        try {
            $poe = $this->page('poePortStatus.html', ['GetData' => 'TRUE']);
            if (preg_match_all('#OutputPower-text[^>]*>\s*([\d.]+)#i', $poe, $pm)) {
                $sum = array_sum(array_map('floatval', $pm[1]));
                $used = count(array_filter($pm[1], fn($v) => (float) $v > 0));
                $max = stripos($info['model'], 'EPP') !== false ? 231 : 180;
                $pct = (int) round($sum / $max * 100);
                $txt = 'PoE: ' . number_format($sum, 1, ',', '') . " W von $max W an $used Port" . ($used === 1 ? '' : 's');
                $info['checks'][] = $pct >= 85 ? ['warn', "$txt – fast ausgelastet"] : ['ok', $txt];
            }
        } catch (RuntimeException $e) {
            // PoE-Seite fehlt bei manchen Firmwares
        }
        return $info;
    }

    // LEDs: POST iss/specific/leds.html mit PORT_LED_STATUS (1 = an, 0 = aus), nach py-netgear-plus
    public function setLeds(bool $on): void
    {
        $r = trim($this->get('iss/specific/leds.html?' . http_build_query(['Gambit' => $this->gambit]), ['PORT_LED_STATUS' => $on ? 1 : 0]));
        if ($r !== 'SUCCESS') {
            throw new RuntimeException('LEDs schalten fehlgeschlagen (Antwort: ' . mb_substr(strip_tags($r), 0, 60) . ')');
        }
    }

    public function pages(): array
    {
        $out = ['/' => $this->get('')];
        foreach (['dashboard.html', 'interface_stats.html', 'poePortConf.html', 'poePortStatus.html'] as $p) {
            try {
                $out[$p] = $this->page($p, $p === 'poePortStatus.html' ? ['GetData' => 'TRUE'] : []);
            } catch (RuntimeException $e) {
                $out[$p] = '(Fehler: ' . $e->getMessage() . ')';
            }
        }
        return $out;
    }
}
