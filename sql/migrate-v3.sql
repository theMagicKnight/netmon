-- netmon Version 3: Feld Raum/Ort für Geräte
-- Nur nötig, wenn du es von Hand einspielen willst – die Weboberfläche ergänzt die Spalte sonst beim ersten Aufruf selbst.
ALTER TABLE devices ADD COLUMN location VARCHAR(64) NULL AFTER name;
