<?php
// netmon – Treiber „Netgear GS116E v2 / JGS-Reihe“ (GS116Ev2, JGS516PE, JGS524Ev2)
// Diese Modelle haben eine andere Oberfläche als die übrigen Plus-Switches: Anmeldung über login.htm mit HMAC-MD5,
// die Daten stehen als JavaScript-Zeilen in config/status_status.htm und config/monitoring_port_statistics.htm.
// Seitenaufbau und Anmeldung nach py-netgear-plus (https://github.com/foxey/py-netgear-plus, Apache-2.0),
// getestet gegen die dort veröffentlichten Beispielseiten eines JGS516PE und JGS524Ev2 – noch nicht an echter Hardware.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class NetgearJgsDriver extends SwitchDriver
{
    public const LABEL = 'Netgear GS116E v2 / JGS (GS116Ev2, JGS516PE, JGS524Ev2)';
    public const HINT  = '/gs116e|jgs5\d\d/i';

    private array $sys = [];

    // Anmeldeseite login.htm enthält „sysGeneInfor = 'Modell?Name?MAC?Firmware?…'“
    public static function detect(string $ip): bool
    {
        $html = self::probe("http://$ip/login.htm");
        return $html !== null && preg_match("/sysGeneInfor\\s*=\\s*'(?:GS116E|JGS)/i", $html) === 1;
    }

    // Passwort wie in der Firmware: mit Nullbytes auf 2048 Zeichen aufgefüllt, HMAC-MD5 mit festem Schlüssel
    public static function hash(string $pass): string
    {
        $n = strlen($pass) + 1;
        $rep = intdiv(2048, $n);
        $padded = str_repeat($pass . "\0", $rep) . str_repeat("\0", 2048 - $rep * $n);
        return hash_hmac('md5', $padded, 'YOU_CAN_NOT_PASS');
    }

    protected function login(): void
    {
        $this->get('login.htm');
        $r = $this->get('login.htm', ['submitId' => 'pwdLogin', 'password' => self::hash($this->pass), 'submitEnd' => '']);
        $page = $this->get('config/status_status.htm');
        if (stripos($page, 'portConfigEntry') === false) {
            $why = preg_match('#class=["\']pwdErrStyle["\'][^>]*>([^<]{3,})#i', $r, $e) ? ' (' . trim($e[1]) . ')' : '';
            throw new RuntimeException('Anmeldung am Switch fehlgeschlagen – Passwort prüfen (oder ist gerade jemand in der Weboberfläche angemeldet?)' . $why);
        }
        $this->status = $page;
    }

    private ?string $status = null;

    protected function logout(): void
    {
        $this->get('logout.cgi', []);
    }

    // „Modell?Name?MAC?Firmware?DHCP?IP?Maske?Gateway?Seriennr.“
    private function sys(): array
    {
        if (!$this->sys) {
            $html = $this->get('config/status_switch_info.htm');
            if (preg_match("/sysGeneInfor\\s*=\\s*'([^']*)'/", $html, $m)) {
                $this->sys = explode('?', $m[1]);
            }
        }
        return $this->sys;
    }

    public function ports(): array
    {
        $html = $this->status ?? $this->get('config/status_status.htm');
        $ports = [];
        // portConfigEntry[0] = '1?Beschreibung?Up?Auto?1000M?Disable'
        if (preg_match_all("/portConfigEntry\\[(\\d+)\\]\\s*=\\s*'([^']*)'/", $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $f = explode('?', $m[2]);
                $p = (int) $m[1] + 1;
                $link = strcasecmp($f[2] ?? '', 'Up') === 0;
                $ports[$p] = [
                    'link'  => $link,
                    'speed' => $link && preg_match('/^(\d+)\s*M/i', $f[4] ?? '', $sm) ? $sm[1] . 'M' : null,
                    'desc'  => trim($f[1] ?? '') !== '' ? trim($f[1]) : null,
                    'rx' => null, 'tx' => null, 'crc' => null,
                ];
            }
        }
        if (!$ports) {
            throw new SwitchParseError('Angemeldet, aber keine Ports gefunden – bitte Analyse-Paket erstellen und schicken');
        }
        // StatisticsEntry[0] = '1?empfangen?gesendet?CRC' (Bytes, schon 64 Bit)
        $stats = $this->get('config/monitoring_port_statistics.htm');
        if (preg_match_all("/StatisticsEntry\\[(\\d+)\\]\\s*=\\s*'([^']*)'/", $stats, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $f = explode('?', $m[2]);
                $p = (int) $m[1] + 1;
                if (isset($ports[$p])) {
                    $ports[$p]['rx']  = isset($f[1]) && ctype_digit($f[1]) ? (int) $f[1] : null;
                    $ports[$p]['tx']  = isset($f[2]) && ctype_digit($f[2]) ? (int) $f[2] : null;
                    $ports[$p]['crc'] = isset($f[3]) && ctype_digit($f[3]) ? (int) $f[3] : null;
                }
            }
        }
        ksort($ports);
        return $ports;
    }

    public function info(): array
    {
        $s = $this->sys();
        $checks = [];
        if (isset($s[4]) && stripos($s[4], 'enable') !== false) {
            $checks[] = ['info', 'IP per DHCP – sie kann sich ändern, besser fest vergeben'];
        }
        return [
            'model'    => $s[0] ?? null,
            'name'     => ($s[1] ?? '') !== '' ? $s[1] : null,
            'mac'      => isset($s[2]) ? strtoupper($s[2]) : null,
            'firmware' => isset($s[3]) ? 'V' . $s[3] : null,
            'dhcp'     => $s[4] ?? null,
            'serial'   => $s[8] ?? null,
            'checks'   => $checks,
        ];
    }

    public function pages(): array
    {
        $out = [];
        foreach (['login.htm', 'config/status_switch_info.htm', 'config/status_status.htm', 'config/monitoring_port_statistics.htm',
                  'config/monitoring_cable_test.htm', 'config/monitoring_loop_detection.htm', 'config/monitoring_mirroring.htm'] as $p) {
            try {
                $out[$p] = $this->get($p);
            } catch (RuntimeException $e) {
                $out[$p] = '(Fehler: ' . $e->getMessage() . ')';
            }
        }
        return $out;
    }
}
