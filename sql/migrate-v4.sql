-- netmon Version 4: Geräte ohne IP (Pseudo-Switch, unmanaged, geplant)
-- Nur nötig, wenn du es von Hand einspielen willst – die Weboberfläche ergänzt das sonst beim ersten Aufruf selbst.
ALTER TABLE devices MODIFY ip INT UNSIGNED NULL, ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0 AFTER is_private_mac;
