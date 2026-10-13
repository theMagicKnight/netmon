-- netmon Version 7: WLAN-Clients der Access Points
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
CREATE TABLE IF NOT EXISTS wifi_clients (
  mac CHAR(17) NOT NULL PRIMARY KEY, ap_id INT UNSIGNED NOT NULL, band VARCHAR(4) NULL, guest TINYINT(1) NOT NULL DEFAULT 0,
  signal_dbm SMALLINT NULL, tx_kbps INT UNSIGNED NULL, rx_kbps INT UNSIGNED NULL, online_s INT UNSIGNED NULL,
  ip VARCHAR(15) NULL, host VARCHAR(64) NULL, updated DATETIME NOT NULL, INDEX idx_ap (ap_id),
  CONSTRAINT fk_wifi_ap FOREIGN KEY (ap_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
