-- netmon – Datenbankschema
-- Import: mariadb -u netmon -p netmon < schema.sql

SET NAMES utf8mb4;

-- Bereiche (logische Gruppen nach IP-Bereich)
CREATE TABLE zones (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(64)  NOT NULL,
  ip_from     INT UNSIGNED NOT NULL,          -- INET_ATON('192.168.5.11')
  ip_to       INT UNSIGNED NOT NULL,
  zone_type   ENUM('kabel','wlan','labor','dhcp') NOT NULL DEFAULT 'kabel',
  parent_device_id INT UNSIGNED NULL,         -- an welchem Switch/Router der Bereich hängt
  sort        SMALLINT NOT NULL DEFAULT 0,
  INDEX idx_range (ip_from, ip_to)
) ENGINE=InnoDB;

-- Geräte, eindeutig über die MAC-Adresse
CREATE TABLE devices (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mac           CHAR(17)     NULL UNIQUE,     -- NULL nur beim Scanner selbst
  ip            INT UNSIGNED NULL,            -- NULL bei Geräten ohne IP (is_virtual)
  hostname      VARCHAR(128) NULL,            -- aus DNS (z. B. FritzBox)
  vendor        VARCHAR(128) NULL,            -- Hersteller laut nmap
  name          VARCHAR(64)  NULL,            -- selbst vergebener Name
  location      VARCHAR(64)  NULL,            -- Raum/Ort (frei, z. B. Wohnzimmer)
  device_type   ENUM('router','switch','ap','server','nas','pc','laptop','pi','iot','kamera','tv','handy',
                     'tablet','drucker','konsole','lautsprecher','funk','sonstiges') NULL,
  parent_id     INT UNSIGNED NULL,            -- feste Zuordnung zu einem Switch (überschreibt Bereich)
  port          VARCHAR(16)  NULL,            -- Switch-Port, falls bekannt
  is_private_mac TINYINT(1)  NOT NULL DEFAULT 0,
  is_virtual    TINYINT(1)   NOT NULL DEFAULT 0, -- von Hand angelegt: unmanaged/geplant, wird nicht gescannt
  is_online     TINYINT(1)   NOT NULL DEFAULT 1,
  missed_scans  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  first_seen    DATETIME     NOT NULL,
  last_seen     DATETIME     NOT NULL,
  notes         TEXT NULL,
  sw_pass       VARCHAR(64) NULL,             -- Passwort der Weboberfläche (Netgear Plus) für die Port-Abfrage
  sw_user       VARCHAR(32) NULL,             -- Benutzername, falls das Gerät einen braucht (FritzBox)
  sw_uplink     TINYINT UNSIGNED NULL,        -- Port, über den der Switch selbst angebunden ist
  sw_driver     VARCHAR(32) NULL,             -- Abfrage-Treiber, z. B. netgear_plus
  sw_info       TEXT NULL,                    -- Modell, Firmware, Check (JSON)
  INDEX idx_ip (ip),
  INDEX idx_parent (parent_id),
  CONSTRAINT fk_dev_parent FOREIGN KEY (parent_id) REFERENCES devices(id) ON DELETE SET NULL
) ENGINE=InnoDB;

ALTER TABLE zones
  ADD CONSTRAINT fk_zone_parent FOREIGN KEY (parent_device_id) REFERENCES devices(id) ON DELETE SET NULL;

-- Nur Statuswechsel speichern, nicht jeden Scan
CREATE TABLE device_events (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  device_id   INT UNSIGNED NOT NULL,
  event       ENUM('neu','online','offline','ip_wechsel','port') NOT NULL,
  old_ip      INT UNSIGNED NULL,
  new_ip      INT UNSIGNED NULL,
  info        VARCHAR(80) NULL,               -- z. B. „Port 3: Link weg“
  created_at  DATETIME NOT NULL,
  INDEX idx_dev_time (device_id, created_at),
  INDEX idx_time (created_at),
  CONSTRAINT fk_evt_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Protokoll der Scan-Durchläufe
CREATE TABLE scans (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at  DATETIME NOT NULL,
  duration_ms INT UNSIGNED NOT NULL,
  hosts_up    SMALLINT UNSIGNED NOT NULL,
  INDEX idx_time (started_at)
) ENGINE=InnoDB;

-- Speedtest-Rohdaten (werden nach 30 Tagen verdichtet)
CREATE TABLE speedtests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  created_at    DATETIME NOT NULL,
  ping_ms       DECIMAL(7,2) NULL,
  jitter_ms     DECIMAL(7,2) NULL,
  download_mbps DECIMAL(8,2) NULL,
  upload_mbps   DECIMAL(8,2) NULL,
  packet_loss   DECIMAL(5,2) NULL,
  isp           VARCHAR(64)  NULL,
  server_name   VARCHAR(128) NULL,
  result_url    VARCHAR(255) NULL,
  error         VARCHAR(255) NULL,            -- falls der Test fehlschlug
  INDEX idx_time (created_at)
) ENGINE=InnoDB;

-- Tageswerte für den Langzeitverlauf
CREATE TABLE speedtests_daily (
  day        DATE PRIMARY KEY,
  tests      SMALLINT UNSIGNED NOT NULL,
  down_avg   DECIMAL(8,2), down_min DECIMAL(8,2), down_max DECIMAL(8,2),
  up_avg     DECIMAL(8,2), up_min   DECIMAL(8,2), up_max   DECIMAL(8,2),
  ping_avg   DECIMAL(7,2), ping_max DECIMAL(7,2)
) ENGINE=InnoDB;

-- Benutzer (Admin darf bearbeiten, Betrachter nur ansehen)
CREATE TABLE users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(64)  NOT NULL UNIQUE,
  pass_hash   VARCHAR(255) NOT NULL,
  role        ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
  created_at  DATETIME NOT NULL,
  last_login  DATETIME NULL
) ENGINE=InnoDB;

-- Einstellungen aus der Weboberfläche (überschreiben config.php)
CREATE TABLE settings (
  k  VARCHAR(64) PRIMARY KEY,
  v  TEXT NOT NULL
) ENGINE=InnoDB;

-- Port-Status der Switches (Netgear Plus), alle 5 Minuten aktualisiert
CREATE TABLE switch_ports (
  device_id   INT UNSIGNED NOT NULL,
  port        TINYINT UNSIGNED NOT NULL,
  link        TINYINT(1) NOT NULL DEFAULT 0,
  speed       VARCHAR(16) NULL,                -- z. B. 1000M, 100M
  descr       VARCHAR(32) NULL,                -- Port-Beschreibung aus dem Switch
  rx_bytes    BIGINT UNSIGNED NULL,            -- Zählerstand vom Switch
  tx_bytes    BIGINT UNSIGNED NULL,
  crc         BIGINT UNSIGNED NULL,
  rx_bps      BIGINT UNSIGNED NULL,            -- Rate seit der letzten Abfrage, Bit/s
  tx_bps      BIGINT UNSIGNED NULL,
  updated     DATETIME NOT NULL,
  cable_result VARCHAR(48) NULL,               -- letzter Kabeltest
  cable_dist  VARCHAR(16) NULL,
  cable_at    DATETIME NULL,
  PRIMARY KEY (device_id, port),
  CONSTRAINT fk_port_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- WLAN-Clients der Access Points (Treiber mit WLAN-Abfrage), je MAC der aktuelle AP
CREATE TABLE wifi_clients (
  mac         CHAR(17) NOT NULL PRIMARY KEY,
  ap_id       INT UNSIGNED NOT NULL,
  band        VARCHAR(4) NULL,                -- 2.4, 5, 6
  guest       TINYINT(1) NOT NULL DEFAULT 0,
  signal_dbm  SMALLINT NULL,
  tx_kbps     INT UNSIGNED NULL,
  rx_kbps     INT UNSIGNED NULL,
  online_s    INT UNSIGNED NULL,
  ip          VARCHAR(15) NULL,
  host        VARCHAR(64) NULL,
  updated     DATETIME NOT NULL,
  INDEX idx_ap (ap_id),
  CONSTRAINT fk_wifi_ap FOREIGN KEY (ap_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Verlauf je Minute: Port-Verkehr der Switches (Bit/s)
CREATE TABLE port_history (
  device_id   INT UNSIGNED NOT NULL,
  ts          DATETIME NOT NULL,
  port        TINYINT UNSIGNED NOT NULL,
  rx_bps      BIGINT UNSIGNED NULL,
  tx_bps      BIGINT UNSIGNED NULL,
  PRIMARY KEY (device_id, ts, port),
  CONSTRAINT fk_ph_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Verlauf je Minute: Access-Point-Status (CPU/Speicher in %, Anzahl Clients)
CREATE TABLE ap_history (
  device_id   INT UNSIGNED NOT NULL,
  ts          DATETIME NOT NULL,
  cpu         TINYINT UNSIGNED NULL,
  mem         TINYINT UNSIGNED NULL,
  clients     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  c24         SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- davon 2,4 GHz
  c5          SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- davon 5/6 GHz
  weak        SMALLINT UNSIGNED NOT NULL DEFAULT 0,  -- Signal unter -75 dBm
  PRIMARY KEY (device_id, ts),
  CONSTRAINT fk_ah_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Messwerte je Minute für Router u. Ä. (DSL-Sync, Störabstand, Internet-Rate …), Schlüssel/Wert
CREATE TABLE dev_metrics (
  device_id   INT UNSIGNED NOT NULL,
  k           VARCHAR(16) NOT NULL,
  ts          DATETIME NOT NULL,
  v           DOUBLE NULL,
  PRIMARY KEY (device_id, k, ts),
  INDEX idx_ts (ts),
  CONSTRAINT fk_dm_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
