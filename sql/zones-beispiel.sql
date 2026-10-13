-- netmon – Beispiel: Bereiche für ein Heimnetz 192.168.5.0/24 (Router .10). Vor dem Einspielen an dein Netz anpassen!
-- Wird von install.sh nur bei einer frischen Datenbank eingespielt.

INSERT INTO devices (mac, ip, hostname, vendor, name, device_type, first_seen, last_seen) VALUES
(NULL, INET_ATON('192.168.5.10'), 'fritz.box', 'AVM', 'FritzBox', 'router', NOW(), NOW());

INSERT INTO zones (name, ip_from, ip_to, zone_type, parent_device_id, sort) VALUES
('Labor',            INET_ATON('192.168.5.1'),   INET_ATON('192.168.5.9'),   'labor', (SELECT id FROM devices WHERE name = 'FritzBox'), 10),
('PCs und Server',   INET_ATON('192.168.5.11'),  INET_ATON('192.168.5.59'),  'kabel', (SELECT id FROM devices WHERE name = 'FritzBox'), 20),
('Raspberry Pis',    INET_ATON('192.168.5.60'),  INET_ATON('192.168.5.99'),  'kabel', (SELECT id FROM devices WHERE name = 'FritzBox'), 30),
('Smart Home',       INET_ATON('192.168.5.100'), INET_ATON('192.168.5.199'), 'wlan',  (SELECT id FROM devices WHERE name = 'FritzBox'), 40),
('DHCP / unbekannt', INET_ATON('192.168.5.200'), INET_ATON('192.168.5.254'), 'dhcp',  (SELECT id FROM devices WHERE name = 'FritzBox'), 50);
