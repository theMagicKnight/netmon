-- netmon Version 11: fehlgeschlagene Anmeldungen je IP (Sperre gegen Passwort-Raten)
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
CREATE TABLE IF NOT EXISTS login_fails (
  ip  VARCHAR(45) NOT NULL,
  ts  DATETIME    NOT NULL,
  INDEX idx_ip_ts (ip, ts), INDEX idx_ts (ts)
) ENGINE=InnoDB;
UPDATE settings SET v = '11' WHERE k = 'schema_version';
