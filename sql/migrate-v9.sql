-- netmon Version 9: Benutzername für die Geräte-Abfrage (FritzBox) und Messwerte je Minute (DSL, Internet)
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
ALTER TABLE devices ADD COLUMN IF NOT EXISTS sw_user VARCHAR(32) NULL AFTER sw_pass;
CREATE TABLE IF NOT EXISTS dev_metrics (
  device_id INT UNSIGNED NOT NULL, k VARCHAR(16) NOT NULL, ts DATETIME NOT NULL, v DOUBLE NULL,
  PRIMARY KEY (device_id, k, ts), INDEX idx_ts (ts),
  CONSTRAINT fk_dm_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
