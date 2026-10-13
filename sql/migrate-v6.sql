-- netmon Version 6: Switch-Treiber, Geräteinfo, Port-Beschreibung, Kabeltest
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
ALTER TABLE devices ADD COLUMN sw_driver VARCHAR(32) NULL AFTER sw_uplink, ADD COLUMN sw_info TEXT NULL AFTER sw_driver;
ALTER TABLE switch_ports ADD COLUMN descr VARCHAR(32) NULL AFTER speed,
  ADD COLUMN cable_result VARCHAR(48) NULL, ADD COLUMN cable_dist VARCHAR(16) NULL, ADD COLUMN cable_at DATETIME NULL;
