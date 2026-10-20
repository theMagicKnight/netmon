# Changelog

## 1.7.4 – 2026-10-20

- **Neue Doku:** vollständiges Handbuch `docs/HANDBUCH.md` (Installation, Treiber, Steuern, Tailscale, IP-Plan,
  Befehle, Fehlersuche, Technik). `LIESMICH.md` ist jetzt eine Kurzanleitung mit Verweisen, README verlinkt das Handbuch.

## 1.7.3 – 2026-10-20

- **Geschützte Ports:** Ein Port lässt sich nicht sperren, wenn dahinter (auch mehrere Ebenen tief) ein Switch, der Router
  oder der netmon-Rechner selbst hängt – sonst schneidet sich netmon vom Netz ab und kann den Port nicht wieder freigeben.
  Anzeige „🛡 geschützt“ mit Grund; gilt auch für Schnellzugriff und `port.php`. Access Points bleiben sperrbar.

## 1.7.2 – 2026-10-20

- Port-Liste am PC: Knöpfe stehen rechts neben dem Namen in derselben Zeile (immer sichtbar); am Handy darunter

## 1.7.1 – 2026-10-20

- Schnellzugriff am PC: Ports als Kacheln nebeneinander, rechts eine Spalte mit LEDs und Ereignissen, vier Kennzahlen oben
- Port-Liste im Switch-Dialog: Sperren, Schnellzugriff und Kabeltest als kleine Knöpfe in einer Zeile; am PC erscheinen sie
  beim Überfahren der Zeile (gesperrte und angeheftete Ports bleiben markiert)

## 1.7.0 – 2026-10-18

- **Schnellzugriff fürs Handy** (`/netmon/schnell.php`, Blitz-Symbol oben): große Knöpfe zum Sperren/Freigeben
  angehefteter Ports, LEDs aller Switches an/aus, Geräte online, letzter Speedtest, letzte Ereignisse;
  aktualisiert sich alle 30 s. Ports anheften: Switch öffnen → Port-Liste → „☆ Schnellzugriff“.
  Auf dem Handy-Startbildschirm: langer Druck auf das netmon-Symbol → „Schnellzugriff“.

## 1.6.2 – 2026-10-18

- Handy-Browser mit eigenem „Dunkelmodus für Webseiten“ (Samsung Internet): netmon meldet, dass es Hell und Dunkel selbst
  kann – der Browser färbt nicht mehr um (vorher weiße Balken über den Beschriftungen im Baum)

## 1.6.1 – 2026-10-18

- Port sperren / freigeben auch bei den Netgear-Plus-Switches mit älterer Oberfläche (GS105E/GS105Ev2, GS108Ev3 …)

## 1.6.0 – 2026-10-18

- **Port sperren / freigeben** (Netgear GS308Ev4 / GS108Ev4): Knopf in der Port-Liste des Switches, der Port wird per
  Software abgeschaltet (Einstellung „Disable“, Name und übrige Port-Einstellungen bleiben). Gesperrte Ports sind rot
  umrandet, jede Änderung steht unter Ereignisse. Der Uplink-Port lässt sich nicht sperren.
- Für Zeitpläne per Cron: `php /var/www/html/netmon/collector/port.php aus|an <Switch-IP> <Port>`
- Datenbank Version 10 (Spalte `switch_ports.admin_off`, ergänzt netmon selbst)

## 1.5.0 – 2026-10-18

- FritzBox-Treiber kann jetzt auch **FRITZ!Repeater**: als Access Point eintragen, WLAN-Clients mit Signal und Band;
  Anmeldung nur mit Kennwort möglich (Benutzer leer lassen – netmon ermittelt den internen Benutzer selbst)
- FritzBox mit eigenem WLAN: deren WLAN-Clients erscheinen ebenfalls (Knoten „WLAN“ unter dem Router)

## 1.4.2 – 2026-10-17

- Baum: Geräte im WLAN des Routers (Speedport, FritzBox) hängen an einem eigenen Knoten „WLAN“ unter dem Router –
  lässt sich zuklappen; ohne WLAN-Geräte am Router (z. B. WLAN aus) erscheint er nicht

## 1.4.1 – 2026-10-17

- Router mit Ports und eigenem WLAN (Speedport): Liste der WLAN-Geräte im Gerätedialog unter der Port-Ansicht

## 1.4.0 – 2026-10-17

- Neuer Treiber **Telekom Speedport** (Smart 3/4, getestet mit Smart 4R): ohne Passwort; Modell, Firmware, DSL-Status
  und -Sync (Verlauf „Internet & DSL“), LAN-Buchsen mit Tempo, WLAN-Geräte am Speedport mit Signal
- Treiber können ohne Anmeldung auskommen – das Passwortfeld wird dann ausgeblendet
- Grafik „Internet & DSL“ zeigt nur die Werte, die der Router auch liefert

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
