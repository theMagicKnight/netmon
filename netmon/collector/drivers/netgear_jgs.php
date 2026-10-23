<?php
// netmon – Treiber „Netgear GS116E v2 / JGS-Reihe“ (GS116Ev2, JGS516PE, JGS524Ev2)
// Diese Modelle haben eine andere Oberfläche als die übrigen Plus-Switches: Anmeldung über login.htm mit HMAC-MD5,
// die Daten stehen als JavaScript-Zeilen in config/status_status.htm und config/monitoring_port_statistics.htm.
// Seitenaufbau und Anmeldung nach py-netgear-plus (https://github.com/foxey/py-netgear-plus, Apache-2.0),
// getestet gegen die dort veröffentlichten Beispielseiten eines JGS516PE und JGS524Ev2 und am echten GS116Ev2 (Firmware
// V2.6.0.50). Die Statuszeilen kommen in der Sprache des Switches („Up“ bzw. „Aktiv“, „Disable“ bzw. „Deaktiviert“).
// Port sperren/freigeben: POST status_status.htm (submitId=sysStatus, secureRand, speed 0 = aus / 1 = Auto, flow, description, port).

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
        // portConfigEntry[0] = '1?Beschreibung?Status?Einstellung?Verbindung?Flusssteuerung'
        //   englisch: '1?fritz.box?Up?Auto?1000M?Disable'   deutsch: '1?fritz.box?Aktiv?Auto?1000M?Deaktiviert'
        foreach ($this->entries($html) as $p => $f) {
            $speed = preg_match('/^(\d+(?:\.\d+)?)\s*([MG])/i', $f[4] ?? '', $sm) ? ($sm[2] === 'G' || $sm[2] === 'g' ? (int) ($sm[1] * 1000) : (int) $sm[1]) . 'M' : null;
            $ports[$p] = [
                'link'  => $speed !== null || preg_match('/^(up|aktiv|active|link ?up)$/i', trim($f[2] ?? '')) === 1,
                'off'   => self::isOff($f[3] ?? ''),
                'speed' => $speed,
                'desc'  => trim(html_entity_decode($f[1] ?? '', ENT_QUOTES)) !== '' ? trim(html_entity_decode($f[1], ENT_QUOTES)) : null,
                'rx' => null, 'tx' => null, 'crc' => null,
            ];
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

    // [port => Felder] aus den portConfigEntry-Zeilen
    private function entries(string $html): array
    {
        $out = [];
        if (preg_match_all("/portConfigEntry\\[(\\d+)\\]\\s*=\\s*'([^']*)'/", $html, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $out[(int) $m[1] + 1] = explode('?', $m[2]);
            }
        }
        return $out;
    }

    // Port-Einstellung „Disable“ / „Deaktiviert“ (alles außer Auto und festen Geschwindigkeiten)
    private static function isOff(string $cfg): bool
    {
        $cfg = trim($cfg);
        return $cfg !== '' && preg_match('/auto|\d/i', $cfg) !== 1;
    }

    // Port ab-/einschalten wie die Weboberfläche (Portstatus → Geschwindigkeit „Deaktiviert“ bzw. „Auto“)
    public function setPortEnabled(int $port, bool $on): void
    {
        $html = $this->get('config/status_status.htm');
        $f = $this->entries($html)[$port] ?? null;
        if ($f === null || !preg_match("/secureRand\\s*=\\s*'(\\w+)'/", $html, $m)) {
            throw new SwitchParseError("Einstellungen von Port $port nicht gefunden – bitte Analyse-Paket schicken");
        }
        $flowOn = preg_match('/^(enable|aktiviert|on)$/i', trim($f[5] ?? '')) === 1;
        $this->get('config/status_status.htm', [
            'submitId' => 'sysStatus', 'secureRand' => $m[1], 'speed' => $on ? 1 : 0, 'flow' => $flowOn ? 1 : 0,
            'description' => html_entity_decode($f[1] ?? '', ENT_QUOTES), 'port' => $port, 'submitEnd' => '',
        ]);
        $this->status = null;
        $now = $this->entries($this->get('config/status_status.htm'))[$port] ?? null;
        if ($now !== null && self::isOff($now[3] ?? '') === $on) {
            throw new RuntimeException('Der Switch hat die Einstellung nicht übernommen');
        }
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
