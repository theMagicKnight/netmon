-- netmon Version 12: Rolle „Familie“ und gemerkte Ansicht je Benutzer
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
ALTER TABLE users MODIFY role ENUM('admin','family','viewer') NOT NULL DEFAULT 'viewer';
ALTER TABLE users ADD COLUMN IF NOT EXISTS ui_mode ENUM('einfach','profi') NOT NULL DEFAULT 'profi' AFTER role;
UPDATE settings SET v = '12' WHERE k = 'schema_version';
