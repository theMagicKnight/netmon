# netmon – Installation

Alles gehört in **einen** Ordner: `netmon/` → `/var/www/html/netmon/`. Sonst nirgendwohin.

## Von Hand (FTP + phpMyAdmin)

1. **Hochladen:** Den Ordner `netmon/` komplett per FTP nach `/var/www/html/` laden.
2. **Datenbank (phpMyAdmin):** Datenbank `netmon` anlegen, deinem MySQL-Benutzer Rechte geben,
   dann `sql/schema.sql` importieren (optional danach `sql/zones-beispiel.sql`).
3. **Konfiguration:** Im Webordner `config.example.php` kopieren als **`config.php`**,
   dort `user`, `pass` und `subnet` eintragen. Wird die Datei im Browser aufgerufen, zeigt sie nichts an.
4. **Programme** (einmalig per SSH):
   ```bash
   sudo apt install nmap php-cli php-mysql php-xml php-mbstring php-curl
   ```
5. **Zeitplan** (einmalig per SSH) – startet jede Minute den Taktgeber, der scannt, wenn es fällig ist:
   ```bash
   echo '* * * * * root /usr/bin/php /var/www/html/netmon/collector/tick.php >> /var/log/netmon-tick.log 2>&1' | sudo tee /etc/cron.d/netmon
   ```
6. **Aufrufen:** `http://<server>/netmon/` → Admin-Konto anlegen.
   Stimmt etwas nicht (config.php, DB-Zugang, Tabellen), sagt die Anmeldeseite das direkt.

## Mit Skript

```bash
sudo bash install.sh --zones sql/zones-beispiel.sql     # Optionen: --subnet, --web, --no-speedtest, --speedtest-every
```

## Aktualisieren

Den Ordner `netmon/` einfach erneut hochladen. Im Paket ist **keine** `config.php` –
deine Zugangsdaten werden also nie überschrieben. Neue Datenbank-Spalten (z. B. „Raum / Ort“)
ergänzt netmon beim nächsten Aufruf selbst; wer das lieber von Hand macht: `sql/migrate-v3.sql` bis `sql/migrate-v7.sql`.

## Hinweise

- `collector/` enthält die Cron-Skripte. Sie laufen nur von der Kommandozeile, im Browser gibt es „403“.
- `mysqltest.php` bei Problemen in den Webordner legen und aufrufen, danach wieder löschen.
- Eine ältere Installation in `/opt/netmon` wird nicht mehr gebraucht:
  `sudo rm -r /opt/netmon` (vorher Cron auf den neuen Pfad umstellen, siehe Schritt 5).

## Switch-Abfrage (Treiber)

Im Gerätedialog eines Switches (Typ „Switch“, mit IP): Treiber wählen oder „Automatisch erkennen“, Passwort der
Switch-Weboberfläche eintragen, „Verbinden & abfragen“. Danach liest netmon im Scan-Takt Link, Geschwindigkeit,
Beschreibung und Datenrate je Port, stündlich Modell/Firmware/Einstellungs-Check; Kabeltest per Knopf.
Braucht `php-curl` (`sudo apt install php-curl`).

Treiber: `collector/drivers/` – derzeit Netgear Plus (GS105E, GS108E, GS305E, GS308E …) und
TP-Link Archer / Deco (WLAN-Clients, z. B. Archer AX55 Pro als Access Point).

### Python für TP-Link (einmalig)

TP-Link verschlüsselt seine Weboberfläche; netmon nutzt dafür das Python-Paket `tplinkrouterc6u`:

```bash
sudo apt install python3-venv
sudo python3 -m venv /opt/netmon-python
sudo /opt/netmon-python/bin/pip install tplinkrouterc6u
```

In `config.php`: `'python' => '/opt/netmon-python/bin/python',`
(Nicht in einem Home-Ordner anlegen – der Webserver darf dort nicht hinein.)

WLAN-Geräte, die nur an Router/AP „geparkt“ sind, zeigt netmon automatisch am AP, an dem sie gerade angemeldet sind.
Hinweis: TP-Link erlaubt nur eine Admin-Sitzung – bist du in der AP-Oberfläche angemeldet, meldet dich die nächste
Abfrage dort ab.
Für unbekannte Geräte erzeugt „Analyse-Paket“ eine Textdatei mit allen Seiten der Weboberfläche,
aus der ein neuer Treiber gebaut werden kann.

Tipp: Am Switch die Port-Beschreibungen nach dem benennen, was dort angeschlossen ist (Gerätename, Raum oder
Name des anderen Switches). netmon schlägt die Verbindungen dann im Gerätedialog vor – ein Klick verbindet.

## Verlauf und Live-Ansicht (Reiter „Verkehr“ / „Auslastung“)

- Jede Minute speichert netmon den Verkehr je Port (Switches) bzw. CPU, Speicher und Clients (Access Points).
  Im Gerätedialog zeigt der Reiter das für 1 Std., 24 Std. oder 7 Tage; wie lange es aufbewahrt wird, steht unter
  Einstellungen → „Verlauf Switches & Access Points“ (Standard 7 Tage, Tabellen `port_history` und `ap_history`).
- **Live** fragt das Gerät direkt ab, solange der Reiter offen ist: Switch alle 5 s, Access Point alle 10 s.
  Nach 10 Minuten pausiert Live von selbst. Beim TP-Link meldet das eine offene Tether-App/Weboberfläche ab.
- Mit der Maus über einen Port in der Frontansicht: Mini-Diagramm der letzten Stunde.
- Damit sich Cron und Live-Ansicht nicht gegenseitig abmelden, meldet sich netmon je Gerät immer nur einmal
  gleichzeitig an (Sperre über MariaDB `GET_LOCK`).

## FRITZ!Box (Treiber „AVM FRITZ!Box“)

1. In der FritzBox: Heimnetz → Netzwerk → Netzwerkeinstellungen → „Zugriff für Anwendungen zulassen“ und
   „Statusinformationen über UPnP übertragen“ einschalten.
2. System → FRITZ!Box-Benutzer: eigenen Benutzer anlegen (z. B. `netmon`) mit dem Recht „FRITZ!Box Einstellungen“.
3. In netmon die FritzBox öffnen → Einstellungen → Typ „Router“, Treiber „AVM FRITZ!Box“, Benutzer + Passwort →
   „Verbinden & abfragen“.

Gelesen wird über TR-064 (Port 49000): DSL-Sync und mögliche Rate, Störabstand, CRC-Fehler, Internet-Rate,
Verbindungsdauer, Modell/Firmware und die Heimnetz-Liste. Aus der Heimnetz-Liste schlägt netmon vor, welches Gerät
an welcher LAN-Buchse hängt. Eine Neusynchronisation der DSL-Leitung erscheint unter Ereignisse.
Messwerte je Minute liegen in der Tabelle `dev_metrics`.

## Netgear mit neuer Oberfläche (GS308E v4, GS305E v2 …)

Treiber „Netgear Plus, neue Oberfläche“ (wird automatisch erkannt, auch wenn „Netgear Plus“ gewählt ist).
Die Anmeldung ist dieselbe wie bei den älteren Plus-Switches, die Daten stehen auf `dashboard.cgi`,
`portStatistics.cgi`, `cableTester.cgi`, `loopDetection.cgi` und `mirroring.cgi`.
Klappt die Anmeldung, aber der Seitenaufbau passt nicht, speichert netmon den Zugang trotzdem –
dann enthält das **Analyse-Paket** alle Seiten mit Anmeldung, damit der Treiber angepasst werden kann.

## Unterstützte Geräte (Treiber)

| Treiber | Modelle | Stand |
|---|---|---|
| Netgear Plus | GS105E v2, GS108E v3, GS305E, GS308E, GSS108E | GS105E v2 im Einsatz |
| Netgear Plus, neue Oberfläche | GS308E v4, GS108E v4, GS305EP, GS308EP, GS308EPP | GS308E v4 im Einsatz, übrige gegen Beispielseiten getestet |
| Netgear GS116E v2 / JGS | GS116Ev2, JGS516PE, JGS524Ev2 | gegen Beispielseiten getestet |
| Netgear GS316EP / GS316EPP | GS316EP, GS316EPP (mit PoE-Summe) | gegen Beispielseiten getestet |
| TP-Link Easy Smart | TL-SG105E, TL-SG108E, TL-SG116E, TL-SG1016PE … (Benutzer meist „admin“) | Entwurf, ungetestet – nur Link/Tempo, keine Datenrate (Switch liefert nur Paketzähler) |
| TP-Link Archer / Deco | Archer AX55 Pro u. a. (über tplinkrouterc6u) | im Einsatz |
| AVM FRITZ!Box | 7590 u. a. (TR-064) | im Einsatz |

„Gegen Beispielseiten getestet“ heißt: Anmeldung und Seitenaufbau stammen aus dem Projekt
[py-netgear-plus](https://github.com/foxey/py-netgear-plus) (Apache-2.0) und wurden mit den dort veröffentlichten
Original-Seiten der Switches geprüft – an echter Hardware noch nicht. Klappt etwas nicht: Analyse-Paket erstellen.

## Danksagung

- **py-netgear-plus** von foxey u. a. (Apache License 2.0) – Vorlage für Anmeldung und Seitenaufbau der Netgear-Treiber
  „GS116E v2 / JGS“ und „GS316EP / GS316EPP“ sowie Beispielseiten zum Testen. Es wurde kein Code übernommen,
  die Treiber sind in PHP neu geschrieben.
- **tplinkrouterc6u** (https://github.com/AlexandrErohin/TP-Link-Archer-C6U) – Anmeldung und Abfrage der
  TP-Link-Access-Points, wird als Bibliothek genutzt.
- **hass_tplink_easy_smart** von Vladimir Makeev (https://github.com/vmakeev/hass_tplink_easy_smart, MIT) –
  Vorlage für Anmeldung und Seiten des Treibers „TP-Link Easy Smart“.
- **smrt** (https://github.com/pklaus/smrt, https://github.com/rgl/philippechataignon-smrt, GPL) – Beschreibung des
  UDP-Protokolls der Easy Smart Configuration Utility; nur zum Nachlesen, netmon nutzt die Weboberfläche.
- **AVM TR-064** (https://avm.de/service/schnittstellen/) – Schnittstelle des FritzBox-Treibers.

Jede Treiberdatei unter `collector/drivers/` nennt ihre Quellen im Kopf.
