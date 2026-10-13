-- netmon – Update auf Version 2 (Login, Einstellungen, neue Gerätetypen)
-- Wird von install.sh automatisch ausgeführt, bestehende Daten bleiben erhalten.

ALTER TABLE devices MODIFY device_type
  ENUM('router','switch','ap','server','nas','pc','laptop','pi','iot','kamera','tv','handy',
       'tablet','drucker','konsole','lautsprecher','funk','sonstiges') NULL;

CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username    VARCHAR(64)  NOT NULL UNIQUE,
  pass_hash   VARCHAR(255) NOT NULL,
  role        ENUM('admin','viewer') NOT NULL DEFAULT 'viewer',
  created_at  DATETIME NOT NULL,
  last_login  DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k  VARCHAR(64) PRIMARY KEY,
  v  TEXT NOT NULL
) ENGINE=InnoDB;
