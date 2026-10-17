# Changelog

## 1.3.1 – 2026-10-17

- TP-Link im Router-Modus (z. B. Archer C80): WLAN-Clients werden jetzt zugeordnet, obwohl der Router kein Signal
  liefert; Kabel-Geräte und abgemeldete Einträge aus seiner Liste werden ignoriert. Anzeige „WLAN“ statt „null dBm“.

## 1.3.0 – 2026-10-16

- install.sh `--with-tailscale`: installiert Tailscale, schaltet IP-Weiterleitung ein und bietet das Heimnetz als
  Subnet-Route an (Router, Switches, APs von unterwegs erreichbar); warnt bei weit verbreiteten Netzen wegen Kollisionen
- Subnetz-Erkennung ignoriert die Tailscale-Schnittstelle

## 1.2.1 – 2026-10-16

- Neue Bilder in README/docs (farbige Leitungen, Legende)
- Baum: Zusatz wie „4/4 online“ über offenen Zweigen entfällt, wenn er in die nächste Spalte ragen würde
- install.sh: Abschluss-Hinweise zeigen Version und den passenden Update-Befehl (git pull), Admin-Hinweis nur bei Neuinstallation

## 1.2.0 – 2026-10-15

- Port-Tempo farbig im Baum, in der Raumansicht und an den Port-Kästchen: 10 Mbit/s rot, 100 gelb, 1 Gbit/s grün,
  2,5/5/10 Gbit/s blau; Leitungen mit unbekanntem Tempo (WLAN, ohne Port-Abfrage) neutral grau. Legende über dem Baum.

## 1.1.1 – 2026-10-14

- install.sh: Update nutzt den Datenbank-Zugang aus config.php; MariaDB-root (ggf. mit Passwortabfrage)
  wird nur noch zum Anlegen einer neuen Datenbank gebraucht

## 1.1.0 – 2026-10-14

- Port-LEDs der Switches schalten: zeitgesteuert unter Einstellungen → Switch-LEDs (z. B. 22:00 aus, 06:30 an)
  oder per Befehl `php /var/www/html/netmon/collector/leds.php aus|an [IP …]`.
  Unterstützt: Netgear GS308Ev4/GS108Ev4/GS30xEP (neue Oberfläche) und GS316EP/EPP – nach py-netgear-plus.

## 1.0.0 – 2026-10-13

Erste veröffentlichte Version.

- Netzwerkscan (nmap) mit Geräteliste, Baum, Liste und Raumansicht, Ereignisse, Speedtest (Ookla)
- Geräte ohne IP (Pseudo-Switches), Räume, IP-Bereiche, Benutzer mit Rollen
- Geräte-Abfrage über Treiber: Netgear Plus (alte und neue Oberfläche, GS116E v2/JGS, GS316EP/EPP),
  TP-Link Easy Smart (Entwurf), TP-Link Archer/Deco (WLAN-Clients), AVM FRITZ!Box (TR-064)
- Port-Status, Datenraten, Kabeltest, Einstellungs-Checks, Verbindungsvorschläge aus Port-Beschreibungen
- Verlauf je Minute und Live-Ansicht für Switch-Ports, Access Points und DSL/Internet
- Analyse-Paket zum Anlernen neuer Geräte
- install.sh mit Bestandsaufnahme, automatischem Datenbank-Upgrade beim Aktualisieren
