<?php
// netmon – Treiber „TP-Link Archer / Deco (WLAN-Clients)“
// Die Weboberfläche verschlüsselt Anmeldung und Abfragen (RSA/AES). Das übernimmt das Python-Paket tplinkrouterc6u,
// netmon ruft dafür tplink_archer.py auf. Pfad zum Python mit dem Paket: 'python' in config.php.

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && count(get_included_files()) === 1) { http_response_code(403); exit; }

final class TplinkArcherDriver extends SwitchDriver
{
    public const LABEL = 'TP-Link Archer / Deco (WLAN-Clients)';
    public const HINT  = '/tp-?link|archer|deco|ax\d{2}/i';

    private ?array $data = null;

    public static function detect(string $ip): bool
    {
        $html = self::probe("http://$ip/");
        return $html !== null && preg_match('/tp-?link|archer|deco/i', $html) === 1;
    }

    protected function login(): void
    {
        $cfg = require dirname(__DIR__, 2) . '/config.php';
        $python = $cfg['python'] ?? 'python3';
        $cmd = [$python, __DIR__ . '/tplink_archer.py', $this->ip];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['NETMON_PASS' => $this->pass, 'PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
        if (!is_resource($proc)) {
            throw new RuntimeException("Python nicht startbar ($python)");
        }
        $out = stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        $code = proc_close($proc);
        if ($code === 2) {
            throw new RuntimeException("Python-Paket tplinkrouterc6u fehlt für $python – siehe LIESMICH (Python für TP-Link)");
        }
        if ($code !== 0) {
            $msg = preg_match('/password|auth|login|401|403/i', $err) ? 'Anmeldung am Access Point fehlgeschlagen – Passwort prüfen' : 'Abfrage fehlgeschlagen';
            throw new RuntimeException($msg . ($err !== '' ? ' (' . mb_substr(strtok($err, "\n"), 0, 160) . ')' : ''));
        }
        $this->data = json_decode((string) $out, true);
        if (!is_array($this->data)) {
            throw new RuntimeException('Unverständliche Antwort vom Python-Helfer');
        }
    }

    protected function logout(): void
    {
        // erledigt der Python-Helfer
    }

    public function ports(): array
    {
        return [];   // Access Point: keine Port-Abfrage
    }

    // WLAN-Clients: MAC im Format AA:BB:CC:DD:EE:FF, Band 2.4/5/6, Signal dBm, Raten kbit/s
    public function wifiClients(): array
    {
        $out = [];
        foreach ($this->data['clients'] ?? [] as $c) {
            $mac = strtoupper(str_replace('-', ':', (string) ($c['mac'] ?? '')));
            if (!preg_match('/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                continue;
            }
            $type = (string) ($c['type'] ?? '');
            $out[] = [
                'mac'    => $mac,
                'ip'     => ($c['ip'] ?? '') !== '0.0.0.0' ? ($c['ip'] ?? null) : null,
                'band'   => str_contains($type, '6g') ? '6' : (str_contains($type, '5g') ? '5' : (str_contains($type, '2g') ? '2.4' : null)),
                'guest'  => str_contains($type, 'guest'),
                'signal' => isset($c['signal']) ? (int) $c['signal'] : null,
                'tx'     => isset($c['tx']) ? (int) $c['tx'] : null,
                'rx'     => isset($c['rx']) ? (int) $c['rx'] : null,
                'online' => isset($c['online']) ? (int) $c['online'] : null,
                'host'   => $c['host'] ?? null,
            ];
        }
        return $out;
    }

    public function stats(): ?array
    {
        $s = $this->data['info']['status'] ?? [];
        $pct = fn($v) => $v === null ? null : (int) round((float) $v * ((float) $v <= 1 ? 100 : 1));
        return ['cpu' => $pct($s['cpu_usage'] ?? null), 'mem' => $pct($s['mem_usage'] ?? null)];
    }

    public function info(): array
    {
        $i = $this->data['info'] ?? [];
        $s = $i['status'] ?? [];
        $pct = fn($v) => $v === null ? null : (int) round((float) $v * ((float) $v <= 1 ? 100 : 1));
        $cpu = $pct($s['cpu_usage'] ?? null);
        $mem = $pct($s['mem_usage'] ?? null);
        $clients = $this->wifiClients();
        $weak = array_filter($clients, fn($c) => $c['signal'] !== null && $c['signal'] < -75);

        $nets = [];
        foreach (['2g' => '2,4 GHz', '5g' => '5 GHz', '6g' => '6 GHz'] as $k => $label) {
            $on = array_filter(['WLAN' => $s["wifi_{$k}_enable"] ?? null, 'IoT' => $s["iot_{$k}_enable"] ?? null, 'Gast' => $s["guest_{$k}_enable"] ?? null], fn($v) => $v === true);
            if ($on) {
                $nets[] = $label . ': ' . implode(', ', array_keys($on));
            }
        }
        $checks = [['ok', count($clients) . ' WLAN-Clients angemeldet'
            . (isset($s['iot_clients_total']) ? " (davon {$s['iot_clients_total']} IoT" . (!empty($s['guest_clients_total']) ? ", {$s['guest_clients_total']} Gäste" : '') . ')' : '')]];
        if ($weak) {
            $checks[] = ['warn', count($weak) . ' Client' . (count($weak) > 1 ? 's' : '') . ' mit schwachem Signal (unter −75 dBm)'];
        }
        if ($cpu !== null) {
            $checks[] = $cpu >= 85 ? ['warn', "CPU-Last hoch: $cpu %"] : ['ok', "CPU $cpu %" . ($mem !== null ? " · Speicher $mem %" : '')];
        }
        if ($mem !== null && $mem >= 90) {
            $checks[] = ['warn', "Speicher fast voll: $mem %"];
        }
        if ($nets) {
            $checks[] = ['info', 'Funknetze – ' . implode(' · ', $nets)];
        }
        if (($s['guest_2g_enable'] ?? false) || ($s['guest_5g_enable'] ?? false)) {
            $checks[] = ['info', 'Gastnetz ist eingeschaltet'];
        }
        return ['model' => $i['model'] ?? null, 'firmware' => $i['firmware'] ?? null, 'serial' => null, 'name' => null,
                'cpu' => $cpu, 'mem' => $mem, 'checks' => $checks];
    }
}
