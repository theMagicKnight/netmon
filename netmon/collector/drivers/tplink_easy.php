<?php
// netmon – Treiber „TP-Link Easy Smart“ (TL-SG105E, TL-SG108E, TL-SG116E, TL-SG1016PE …), Weboberfläche
// Anmeldung: POST logon.cgi (username, password, logon=Login), Antwort „var logonInfo = new Array(0, …)“ = angemeldet.
// Daten stehen als JavaScript-Variablen in den Seiten *Rpm.htm:
//   SystemInfoRpm.htm   info_ds  = {descriStr:["…"], macStr:["…"], firmwareStr:["…"], hardwareStr:["…"], …}
//   PortSettingRpm.htm  all_info = {state:[…], spd_cfg:[…], spd_act:[…], fc_cfg:[…], fc_act:[…]}, max_port_num = 8
//   PortStatisticsRpm.htm all_info = {…, pkts:[…]} – nur Paketzähler, keine Bytes (daher keine Datenrate)
// Quellen:
//   https://github.com/vmakeev/hass_tplink_easy_smart (MIT) – Anmeldung, Seiten und Variablen
//   https://github.com/pklaus/smrt, https://github.com/rgl/philippechataignon-smrt (GPL) – UDP-Protokoll der
//   „Easy Smart Configuration Utility“, nur zum Nachlesen; netmon nutzt die Weboberfläche, kein Code übernommen.
// Stand: ungetestet an echter Hardware – passt etwas nicht, Analyse-Paket schicken.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class TplinkEasyDriver extends SwitchDriver
{
    public const LABEL = 'TP-Link Easy Smart (TL-SG105E, TL-SG108E, TL-SG116E …)';
    public const HINT  = '/tl-?sg1\d{2,3}[a-z]*e|easy ?smart/i';
    public const NEEDS_USER = true;

    // spd_act: 0 kein Link, 1 Auto, 2 10M halb, 3 10M voll, 4 100M halb, 5 100M voll, 6 1000M voll
    private const SPEED = [2 => '10M', 3 => '10M', 4 => '100M', 5 => '100M', 6 => '1000M'];

    public static function detect(string $ip): bool
    {
        $html = self::probe("http://$ip/");
        return $html !== null && preg_match('/logon\.cgi/i', $html) === 1 && preg_match('/tp-?link|TL-SG/i', $html) === 1;
    }

    protected function login(): void
    {
        $r = $this->get('logon.cgi', ['username' => $this->user !== '' ? $this->user : 'admin', 'password' => $this->pass, 'logon' => 'Login']);
        $code = preg_match('/logonInfo\s*=\s*new\s+Array\s*\(\s*"?(\d+)/i', $r, $m) ? (int) $m[1] : null;
        if ($code === 0) {
            return;
        }
        throw new RuntimeException(match ($code) {
            1       => 'Anmeldung fehlgeschlagen – Benutzer oder Passwort falsch',
            2       => 'Dieser Benutzer darf sich nicht anmelden',
            3, 4    => 'Zu viele Anmeldungen am Switch – kurz warten oder Weboberfläche abmelden',
            5       => 'Sitzung abgelaufen – bitte erneut versuchen',
            null    => 'Keine Antwort von logon.cgi (anderes Modell? Analyse-Paket schicken)',
            default => "Anmeldung fehlgeschlagen (Code $code)",
        });
    }

    protected function logout(): void
    {
        $this->get('Logout.htm');
    }

    // JavaScript-Variable einer Seite: Zahl, Array oder Objekt mit Listen
    private static function jsVar(string $html, string $name): ?string
    {
        return preg_match('/var\s+' . preg_quote($name, '/') . '\s*=\s*(.+?);\s*(?:\n|var\s|<\/script)/is', $html, $m) ? trim($m[1]) : null;
    }

    // {state:[1,0], spd_act:[6,0]} bzw. {descriStr:["Switch"]} -> ['state' => [1,0], …]
    private static function jsObject(?string $js): array
    {
        $out = [];
        if ($js === null) {
            return $out;
        }
        preg_match_all('/["\']?(\w+)["\']?\s*:\s*\[([^\]]*)\]/', $js, $mm, PREG_SET_ORDER);
        foreach ($mm as $m) {
            $out[$m[1]] = array_map(fn($v) => trim($v, " \t\r\n\"'"), $m[2] === '' ? [] : explode(',', $m[2]));
        }
        return $out;
    }

    public function ports(): array
    {
        $html = $this->get('PortSettingRpm.htm');
        $info = self::jsObject(self::jsVar($html, 'all_info'));
        $n = (int) (self::jsVar($html, 'max_port_num') ?? count($info['spd_act'] ?? []));
        if (!$n || empty($info['spd_act'])) {
            throw new SwitchParseError('Angemeldet, aber keine Ports gefunden – bitte Analyse-Paket erstellen und schicken');
        }
        $ports = [];
        for ($p = 1; $p <= $n; $p++) {
            $act = (int) ($info['spd_act'][$p - 1] ?? 0);
            $on = (int) ($info['state'][$p - 1] ?? 1) === 1;
            $link = $on && $act > 0;
            $ports[$p] = ['link' => $link, 'speed' => $link ? (self::SPEED[$act] ?? null) : null, 'desc' => null,
                          'rx' => null, 'tx' => null, 'crc' => null];
        }
        // Paketzähler: je Port [gesendet ok, gesendet fehlerhaft, empfangen ok, empfangen fehlerhaft]
        try {
            $st = self::jsObject(self::jsVar($this->get('PortStatisticsRpm.htm'), 'all_info'));
            foreach ($ports as $p => &$x) {
                $bad = $st['pkts'][($p - 1) * 4 + 3] ?? null;
                $x['crc'] = $bad !== null && ctype_digit($bad) ? (int) $bad : null;
            }
            unset($x);
        } catch (RuntimeException $e) {
            // ohne Statistik weiter
        }
        return $ports;
    }

    public function info(): array
    {
        $i = self::jsObject(self::jsVar($this->get('SystemInfoRpm.htm'), 'info_ds'));
        $v = fn(string $k) => isset($i[$k][0]) && $i[$k][0] !== '' ? $i[$k][0] : null;
        $hw = $v('hardwareStr');
        return [
            'model'    => $hw ? preg_replace('/\s+\d+\.\d+$/', '', $hw) : null,
            'name'     => $v('descriStr'),
            'mac'      => ($m = $v('macStr')) ? strtoupper(str_replace('-', ':', $m)) : null,
            'firmware' => $v('firmwareStr'),
            'serial'   => null,
            'checks'   => [],
        ];
    }

    public function pages(): array
    {
        $out = [];
        foreach (['', 'SystemInfoRpm.htm', 'PortSettingRpm.htm', 'PortStatisticsRpm.htm', 'CableDiagRpm.htm',
                  'LoopPreventionRpm.htm', 'PortMirrorRpm.htm', 'IpSettingRpm.htm', 'PoeConfigRpm.htm'] as $p) {
            try {
                $out[$p === '' ? '/' : $p] = $this->get($p);
            } catch (RuntimeException $e) {
                $out[$p] = '(Fehler: ' . $e->getMessage() . ')';
            }
        }
        return $out;
    }
}
