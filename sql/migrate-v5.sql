-- netmon Version 5: Port-Abfrage für Netgear-Plus-Switches
-- Nur nötig, wenn du es von Hand einspielen willst – die Weboberfläche ergänzt das sonst beim ersten Aufruf selbst.
ALTER TABLE devices ADD COLUMN sw_pass VARCHAR(64) NULL AFTER notes, ADD COLUMN sw_uplink TINYINT UNSIGNED NULL AFTER sw_pass;
ALTER TABLE device_events MODIFY event ENUM('neu','online','offline','ip_wechsel','port') NOT NULL, ADD COLUMN info VARCHAR(80) NULL AFTER new_ip;
CREATE TABLE IF NOT EXISTS switch_ports (
  device_id INT UNSIGNED NOT NULL, port TINYINT UNSIGNED NOT NULL, link TINYINT(1) NOT NULL DEFAULT 0, speed VARCHAR(16) NULL,
  rx_bytes BIGINT UNSIGNED NULL, tx_bytes BIGINT UNSIGNED NULL, crc BIGINT UNSIGNED NULL, rx_bps BIGINT UNSIGNED NULL, tx_bps BIGINT UNSIGNED NULL,
  updated DATETIME NOT NULL, PRIMARY KEY (device_id, port),
  CONSTRAINT fk_port_dev FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
