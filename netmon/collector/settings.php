<?php
// netmon – Einstellungen: Standardwerte < config.php < Tabelle settings (Weboberfläche)
// Wird von den Erfassungsskripten und der Weboberfläche gemeinsam genutzt.

declare(strict_types=1);

const NETMON_DEFAULTS = [
    'subnet'              => '192.168.1.0/24',
    'strip_domain'        => '.fritz.box',
    'scan_interval'       => 5,      // Minuten
    'offline_after'       => 3,      // verpasste Scans
    'speedtest_enabled'   => 1,
    'speedtest_interval'  => 10,     // Minuten: 10, 15, 20, 30, 60
    'animations'          => 1,
    'keep_speedtest_days' => 30,
    'keep_event_days'     => 365,
    'keep_scan_days'      => 30,
    'keep_history_days'   => 7,     // Verlauf Port-Verkehr / AP-Status (je Minute)
    'led_off'             => '',     // Switch-LEDs aus um HH:MM (leer = nie)
    'led_on'              => '',     // … und wieder an um HH:MM
    'scan_request'        => 0,      // „Jetzt scannen“ aus der Oberfläche
    'speedtest_request'   => 0,
    'room_layout'         => '',     // Positionen der Raumkarten (JSON)
    'sug_dismissed'       => '',     // ignorierte Verbindungsvorschläge (JSON)
];

function netmon_pdo(array $cfg): PDO
{
    return new PDO($cfg['db']['dsn'], $cfg['db']['user'], $cfg['db']['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

// Datenbank automatisch auf den aktuellen Stand bringen (neue Spalten), einmal pro Version
const NETMON_SCHEMA = 10;

function netmon_upgrade(PDO $db): void
{
    try {
        $v = (int) ($db->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn() ?: 2);
    } catch (PDOException $e) {
        return;     // Tabellen fehlen noch – Anmeldeseite meldet das
    }
    if ($v >= NETMON_SCHEMA) {
        return;
    }
    if ($v < 3 && !$db->query("SHOW COLUMNS FROM devices LIKE 'location'")->fetch()) {
        $db->exec('ALTER TABLE devices ADD COLUMN location VARCHAR(64) NULL AFTER name');
    }
    // Version 4: Geräte ohne IP (Pseudo-Switch, unmanaged, geplant)
    if ($v < 4 && !$db->query("SHOW COLUMNS FROM devices LIKE 'is_virtual'")->fetch()) {
        $db->exec('ALTER TABLE devices MODIFY ip INT UNSIGNED NULL, ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0 AFTER is_private_mac');
    }
    // Version 5: Port-Abfrage für Netgear-Plus-Switches
    if ($v < 5) {
        if (!$db->query("SHOW COLUMNS FROM devices LIKE 'sw_pass'")->fetch()) {
            $db->exec('ALTER TABLE devices ADD COLUMN sw_pass VARCHAR(64) NULL AFTER notes, ADD COLUMN sw_uplink TINYINT UNSIGNED NULL AFTER sw_pass');
        }
        if (!$db->query("SHOW COLUMNS FROM device_events LIKE 'info'")->fetch()) {
            $db->exec("ALTER TABLE device_events MODIFY event ENUM('neu','online','offline','ip_wechsel','port') NOT NULL, ADD COLUMN info VARCHAR(80) NULL AFTER new_ip");
        }
        $db->exec('CREATE TABLE IF NOT EXISTS switch_ports (
            device_id INT UNSIGNED NOT NULL, port TINYINT UNSIGNED NOT NULL, link TINYINT(1) NOT NULL DEFAULT 0, speed VARCHAR(16) NULL,
            rx_bytes BIGINT UNSIGNED NULL, tx_bytes BIGINT UNSIGNED NULL, crc BIGINT UNSIGNED NULL, rx_bps BIGINT UNSIGNED NULL, tx_bps BIGINT UNSIGNED NULL,
            updated DATETIME NOT NULL, PRIMARY KEY (device_id, port),
            CONSTRAINT fk_port_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB');
    }
    // Version 6: Switch-Treiber, Geräteinfo, Port-Beschreibung, Kabeltest
    if ($v < 6) {
        if (!$db->query("SHOW COLUMNS FROM devices LIKE 'sw_driver'")->fetch()) {
            $db->exec('ALTER TABLE devices ADD COLUMN sw_driver VARCHAR(32) NULL AFTER sw_uplink, ADD COLUMN sw_info TEXT NULL AFTER sw_driver');
        }
        if (!$db->query("SHOW COLUMNS FROM switch_ports LIKE 'descr'")->fetch()) {
            $db->exec('ALTER TABLE switch_ports ADD COLUMN descr VARCHAR(32) NULL AFTER speed,
                       ADD COLUMN cable_result VARCHAR(48) NULL, ADD COLUMN cable_dist VARCHAR(16) NULL, ADD COLUMN cable_at DATETIME NULL');
        }
    }
    // Version 7: WLAN-Clients der Access Points
    if ($v < 7) {
        $db->exec('CREATE TABLE IF NOT EXISTS wifi_clients (
            mac CHAR(17) NOT NULL PRIMARY KEY, ap_id INT UNSIGNED NOT NULL, band VARCHAR(4) NULL, guest TINYINT(1) NOT NULL DEFAULT 0,
            signal_dbm SMALLINT NULL, tx_kbps INT UNSIGNED NULL, rx_kbps INT UNSIGNED NULL, online_s INT UNSIGNED NULL,
            ip VARCHAR(15) NULL, host VARCHAR(64) NULL, updated DATETIME NOT NULL, INDEX idx_ap (ap_id),
            CONSTRAINT fk_wifi_ap FOREIGN KEY (ap_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB');
    }
    // Version 8: Verlauf (je Minute) für Port-Verkehr und Access-Point-Status
    if ($v < 8) {
        $db->exec('CREATE TABLE IF NOT EXISTS port_history (
            device_id INT UNSIGNED NOT NULL, ts DATETIME NOT NULL, port TINYINT UNSIGNED NOT NULL,
            rx_bps BIGINT UNSIGNED NULL, tx_bps BIGINT UNSIGNED NULL, PRIMARY KEY (device_id, ts, port),
            CONSTRAINT fk_ph_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB');
        $db->exec('CREATE TABLE IF NOT EXISTS ap_history (
            device_id INT UNSIGNED NOT NULL, ts DATETIME NOT NULL, cpu TINYINT UNSIGNED NULL, mem TINYINT UNSIGNED NULL,
            clients SMALLINT UNSIGNED NOT NULL DEFAULT 0, c24 SMALLINT UNSIGNED NOT NULL DEFAULT 0, c5 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            weak SMALLINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (device_id, ts),
            CONSTRAINT fk_ah_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB');
    }
    // Version 9: Benutzername für Geräte-Abfrage (FritzBox), Messwerte je Minute (DSL, Internet)
    if ($v < 9) {
        if (!$db->query("SHOW COLUMNS FROM devices LIKE 'sw_user'")->fetch()) {
            $db->exec('ALTER TABLE devices ADD COLUMN sw_user VARCHAR(32) NULL AFTER sw_pass');
        }
        $db->exec('CREATE TABLE IF NOT EXISTS dev_metrics (
            device_id INT UNSIGNED NOT NULL, k VARCHAR(16) NOT NULL, ts DATETIME NOT NULL, v DOUBLE NULL,
            PRIMARY KEY (device_id, k, ts), INDEX idx_ts (ts),
            CONSTRAINT fk_dm_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE) ENGINE=InnoDB');
    }
    // Version 10: Port per Software abgeschaltet (Switch-Einstellung „Disable“)
    if ($v < 10 && !$db->query("SHOW COLUMNS FROM switch_ports LIKE 'admin_off'")->fetch()) {
        $db->exec('ALTER TABLE switch_ports ADD COLUMN admin_off TINYINT(1) NOT NULL DEFAULT 0 AFTER link');
    }
    netmon_set($db, 'schema_version', NETMON_SCHEMA);
}

function netmon_settings(PDO $db, array $cfg): array
{
    $s = NETMON_DEFAULTS;
    foreach ($s as $k => $_) {
        if (array_key_exists($k, $cfg)) {
            $s[$k] = is_bool($cfg[$k]) ? (int) $cfg[$k] : $cfg[$k];
        }
    }
    try {
        foreach ($db->query('SELECT k, v FROM settings') as $row) {
            if (array_key_exists($row['k'], $s)) {
                $s[$row['k']] = is_int(NETMON_DEFAULTS[$row['k']]) ? (int) $row['v'] : $row['v'];
            }
        }
    } catch (PDOException $e) {
        // ältere Datenbank ohne Tabelle settings: Standardwerte und config.php reichen
    }
    return $s;
}

function netmon_set(PDO $db, string $k, $v): void
{
    $db->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
       ->execute([$k, (string) $v]);
}
