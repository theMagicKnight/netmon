-- netmon Version 10: Port per Software abgeschaltet (Switch-Einstellung „Disable“)
-- Nur nötig, wenn du es von Hand einspielen willst – netmon ergänzt das sonst selbst.
ALTER TABLE switch_ports ADD COLUMN IF NOT EXISTS admin_off TINYINT(1) NOT NULL DEFAULT 0 AFTER link;
