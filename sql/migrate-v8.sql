-- netmon Version 8: Verlauf für Port-Verkehr und Access-Point-Status
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
CREATE TABLE IF NOT EXISTS port_history (
  device_id INT UNSIGNED NOT NULL, ts DATETIME NOT NULL, port TINYINT UNSIGNED NOT NULL,
  rx_bps BIGINT UNSIGNED NULL, tx_bps BIGINT UNSIGNED NULL, PRIMARY KEY (device_id, ts, port),
  CONSTRAINT fk_ph_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ap_history (
  device_id INT UNSIGNED NOT NULL, ts DATETIME NOT NULL, cpu TINYINT UNSIGNED NULL, mem TINYINT UNSIGNED NULL,
  clients SMALLINT UNSIGNED NOT NULL DEFAULT 0, c24 SMALLINT UNSIGNED NOT NULL DEFAULT 0, c5 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  weak SMALLINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (device_id, ts),
  CONSTRAINT fk_ah_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
