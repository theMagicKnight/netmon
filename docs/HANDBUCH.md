# netmon – Handbuch

Stand: Version 1.10 · Oktober 2026

netmon zeigt dir dein Heimnetz auf einen Blick: welche Geräte da sind, wo sie hängen, wie schnell die Leitungen
laufen, was der Internetanschluss leistet – und lässt dich Switches, Router und Access Points direkt abfragen und
steuern. Es läuft auf jedem kleinen Linux-Rechner mit Apache, PHP und MariaDB, z. B. einem Raspberry Pi.

---

## Inhalt

1. [Überblick](#1-überblick)
2. [Installation](#2-installation)
3. [Aktualisieren](#3-aktualisieren)
4. [Erste Schritte](#4-erste-schritte)
5. [Die Oberfläche](#5-die-oberfläche)
6. [Geräte abfragen (Treiber)](#6-geräte-abfragen-treiber)
7. [Steuern: LEDs, Ports sperren, Schnellzugriff](#7-steuern-leds-ports-sperren-schnellzugriff)
8. [Fernzugriff mit Tailscale](#8-fernzugriff-mit-tailscale)
9. [Empfehlung: IP-Plan fürs Heimnetz](#9-empfehlung-ip-plan-fürs-heimnetz)
10. [Einstellungen](#10-einstellungen)
11. [Befehle für die Kommandozeile](#11-befehle-für-die-kommandozeile)
12. [Fehlersuche](#12-fehlersuche)
13. [Technik: Aufbau, Dateien, Datenbank](#13-technik-aufbau-dateien-datenbank)
14. [Neue Geräte anlernen](#14-neue-geräte-anlernen)
15. [Quellen und Danksagung](#15-quellen-und-danksagung)

---

## 1. Überblick

| Bereich | Was netmon macht |
|---|---|
| **Netzwerkscan** | findet alle Geräte im Subnetz (nmap), merkt sich Name, Hersteller, IP, erstmals/zuletzt gesehen; erkennt neue Geräte, offline/online, IP-Wechsel |
| **Ansichten** | Baum (wer hängt wo), Liste, Räume (Grundriss mit Leitungen zwischen Räumen) |
| **Leitungen** | Tempo jeder Kabelverbindung farbig: 10M rot · 100M gelb · 1G grün · 2,5G+ blau · unbekannt grau |
| **Switches** | Port-Status, Tempo, Beschreibung, Datenrate, Fehlerzähler, Kabeltest, Einstellungs-Check, Verlauf + Live |
| **Router** | FRITZ!Box: DSL-Sync, Störabstand, CRC, Internet-Rate · Speedport: DSL-Sync, LAN-Buchsen, WLAN-Geräte |
| **WLAN** | Access Points und Router mit WLAN melden ihre Clients mit Signal – netmon hängt die Geräte automatisch an den richtigen AP |
| **Speedtest** | regelmäßige Messung (Ookla), Verlauf als Diagramm |
| **Steuern** | LEDs der Switches an/aus (auch nach Zeitplan), Ports sperren/freigeben, Handy-Seite „Schnellzugriff“ |
| **Fernzugriff** | über Tailscale ohne Portfreigabe – netmon und alle Geräte im Heimnetz von unterwegs |

---

## 2. Installation

### Voraussetzungen

- Debian, Ubuntu, Linux Mint oder Raspberry Pi OS
- Apache, PHP ≥ 8.0 mit `pdo_mysql`, `curl`, `mbstring`, `xml`, dazu MariaDB/MySQL und `nmap`
- Für TP-Link-Access-Points zusätzlich Python 3 (siehe [6.4](#64-tp-link-archer--deco-access-points))

`install.sh` prüft das alles und installiert nur, was fehlt.

### Mit dem Installationsskript (empfohlen)

```bash
git clone https://github.com/theMagicKnight/netmon.git
cd netmon
sudo bash install.sh
```

Das Skript

1. prüft Apache, PHP (+ Erweiterungen), MariaDB, nmap und installiert Fehlendes,
2. kopiert die Web-App nach `/var/www/html/netmon`,
3. legt Datenbank `netmon`, Benutzer und `config.php` an (zufälliges Passwort),
4. richtet den Zeitplan ein (`/etc/cron.d/netmon`, jede Minute),
5. macht einen ersten Scan und zeigt die Adresse, z. B. `http://192.168.2.203/netmon/`.

Beim ersten Aufruf legst du dort dein Admin-Konto an.

**Optionen**

| Option | Wirkung |
|---|---|
| `--subnet 192.168.2.0/24` | Subnetz vorgeben (sonst automatisch erkannt) |
| `--web /var/www/html/netmon` | anderer Zielordner |
| `--no-speedtest` | Speedtest zunächst aus |
| `--speedtest-every 20` | Speedtest alle 20 Minuten (10, 15, 20, 30, 60) |
| `--zones sql/zones-beispiel.sql` | Beispiel-Bereiche einspielen |
| `--with-tailscale` | Fernzugriff einrichten (siehe [Kapitel 8](#8-fernzugriff-mit-tailscale)) |
| `--with-internet` | netmon aus dem Internet erreichbar machen: Strato-DynDNS + HTTPS (siehe [8.4](#84-ohne-tailscale-aus-dem-internet-dyndns--https)) |

### Von Hand (FTP + phpMyAdmin)

1. Ordner `netmon/` nach `/var/www/html/` hochladen.
2. In phpMyAdmin Datenbank `netmon` anlegen, Benutzer berechtigen, `sql/schema.sql` importieren.
3. `config.example.php` als `config.php` kopieren, Datenbank-Zugang eintragen.
4. Programme: `sudo apt install nmap php-cli php-mysql php-xml php-mbstring php-curl`
5. Zeitplan:
   ```bash
   echo '* * * * * root /usr/bin/php /var/www/html/netmon/collector/tick.php >> /var/log/netmon-tick.log 2>&1' | sudo tee /etc/cron.d/netmon
   ```
6. `http://<server>/netmon/` aufrufen. Stimmt etwas nicht (config.php, Datenbank), sagt die Anmeldeseite es direkt.

---

## 3. Aktualisieren

```bash
cd ~/netmon && git pull && sudo bash install.sh
```

- Daten, Einstellungen und `config.php` bleiben erhalten.
- Neue Tabellen/Spalten ergänzt netmon selbst (Datenbank-Version steht in `settings.schema_version`).
- Danach im Browser einmal **Strg + F5**, damit die neue Oberfläche geladen wird.
- Vorher ein Backup schadet nie:
  ```bash
  sudo mysqldump -u root -p netmon > ~/netmon-backup-$(date +%F).sql
  ```

Wer von Hand installiert hat: Ordner `netmon/` erneut hochladen – im Paket ist nie eine `config.php`.
Für Datenbank-Änderungen von Hand liegen `sql/migrate-vN.sql` bei (sonst macht netmon das selbst).

---

## 4. Erste Schritte

1. **Einstellungen → Netzwerkscan:** Subnetz prüfen (z. B. `192.168.2.0/24`), Scan-Intervall wählen.
2. **Router eintragen:** Router öffnen → Einstellungen → Typ „Router“ → Treiber wählen
   (FRITZ!Box, Speedport) → „Verbinden & abfragen“.
3. **Switches eintragen:** je Switch Typ „Switch“, Treiber (oder „automatisch“), Passwort der Weboberfläche,
   **Uplink-Port** (der Port Richtung Router).
4. **Access Points:** Typ „Access Point“, Treiber + Passwort – dann hängen die WLAN-Geräte automatisch darunter.
5. **Baum aufbauen:** bei jedem Gerät „Hängt an“ (Switch/AP) und ggf. Port eintragen.
   Schneller geht's mit **Port-Beschreibungen**: am Switch je Port den Gerätenamen eintragen – netmon schlägt
   die Verbindung im Switch-Dialog vor, ein Klick verbindet.
6. **Räume:** Feld „Raum / Ort“ füllen – die Raumansicht ordnet Geräte und Leitungen danach.
7. **Geräte ohne IP** (unmanaged Switch, Patchfeld, Powerline-Adapter): unter Geräte → „Gerät ohne IP“ anlegen
   und wie einen Switch einhängen.

---

## 5. Die Oberfläche

### 5.1 Netzwerk – Baum, Liste, Räume

- **Baum:** Router links, alles andere nach rechts verzweigt. Zweige lassen sich zuklappen.
  Fährst du mit der Maus über eine Leitung, steht da Port ↔ Port, Tempo, Datenrate, Kabeltest bzw. WLAN-Signal.
- **Farben der Leitungen** (Legende über dem Baum):

  | Farbe | Tempo |
  |---|---|
  | rot | 10 Mbit/s – meist Gerät im Standby oder Kabelproblem |
  | gelb | 100 Mbit/s – altes Gerät (Pi 3, Fernseher) oder 4-adriges Kabel |
  | grün | 1 Gbit/s |
  | blau | 2,5 Gbit/s und mehr |
  | grau | unbekannt (WLAN, Gerät ohne Port-Abfrage) |
  | gestrichelt | Gerät offline |

- **WLAN des Routers:** Geräte im WLAN von Speedport/FRITZ!Box hängen an einem eigenen Knoten „WLAN“ unter dem
  Router – zuklappbar.
- **Lange offline:** Geräte, die länger als eingestellt offline sind (Standard 3 Tage), werden ausgeblendet.
  Neben „Nur online“ steht dann „N ausgeblendet“ – ein Klick zeigt sie wieder. Sie bleiben gespeichert und erscheinen
  von selbst wieder, sobald sie online sind. Geräte, an denen etwas hängt, bleiben immer sichtbar.
- **Liste:** alle Geräte untereinander, zum Suchen und Durchsehen.
- **Räume:** Raumkarten mit ihren Geräten, Leitungen zwischen den Räumen (Farbe = langsamster Port der Strecke).
  „Anordnen“ verschiebt die Karten.

### 5.2 Gerätedialog

Klick auf ein Gerät öffnet den Dialog:

- **Übersicht:** IP, MAC, Hersteller, erstmals/zuletzt; bei Switches die Frontansicht mit allen Buchsen, Modell,
  Firmware, Einstellungs-Check und die **Port-Liste**. Je Port: wer dran hängt, Tempo, Datenrate und die Knöpfe
  🔒 Sperren · ☆ Schnellzugriff · ⌁ Kabeltest (am PC rechts in der Zeile, am Handy darunter).
- **Verkehr / Auslastung / Internet & DSL:** Verlauf 1 Std., 24 Std., 7 Tage und **Live**
  (Switch alle 5 s, Access Point alle 10 s; pausiert nach 10 Minuten).
- **Einstellungen:** Name, Typ, Raum, Hängt an, Port, Notizen, Treiber, Passwort, Uplink-Port, Analyse-Paket.

### 5.3 Speedtest, Ereignisse, Geräte

- **Speedtest:** Download, Upload, Ping als Verlauf; fehlgeschlagene Tests erscheinen als Lücke.
- **Ereignisse:** neu, offline, wieder online, IP-Wechsel, Port-Änderungen (Link da/weg, Tempo, gesperrt/freigegeben),
  DSL-Neusynchronisation.
- **Geräte:** Tabelle aller Geräte, filterbar.

### 5.4 Hell/Dunkel, Handy

Mond/Sonne oben schaltet das Design um. netmon läuft auch als „App“ auf dem Handy: im Browser
„Zum Startbildschirm hinzufügen“. Langer Druck auf das Symbol bietet „Schnellzugriff“ an.

> Samsung Internet: Ist dort „Dunkler Modus für Websites“ an, färbt der Browser die Seite selbst um.
> netmon meldet seit 1.6.2, dass es das selbst kann – sollten die Beschriftungen im Baum trotzdem weiße Balken haben,
> den Dunkelmodus des Browsers für netmon ausschalten.

---

## 6. Geräte abfragen (Treiber)

Treiber lesen die Weboberfläche bzw. Schnittstelle eines Geräts. Einrichten: Gerät öffnen → Einstellungen →
Treiber (oder „automatisch erkennen“) → Passwort → **Verbinden & abfragen**. Der Zugang wird erst nach
erfolgreicher Abfrage gespeichert.

### 6.1 Übersicht

| Treiber | Modelle | Liest | Steuert | Stand |
|---|---|---|---|---|
| Netgear Plus | GS105E/v2, GS108E v3, GS305E, GS308E (alte Oberfläche) | Ports, Tempo, Beschreibung, Datenrate, CRC, Check | Ports sperren, Kabeltest | im Einsatz |
| Netgear Plus, neue Oberfläche | GS308E v4, GS108E v4, GS30xEP | dto. | Ports sperren, LEDs, Kabeltest | im Einsatz |
| Netgear GS116E v2 / JGS | GS116Ev2, JGS516PE, JGS524Ev2 | Ports, Datenrate | – | gegen Beispielseiten getestet |
| Netgear GS316EP/EPP | GS316EP, GS316EPP | Ports, Datenrate, PoE-Summe | LEDs | gegen Beispielseiten getestet |
| TP-Link Easy Smart | TL-SG105E/108E/116E … | Ports, Tempo (keine Datenrate) | – | Entwurf |
| TP-Link Archer / Deco | Archer AX55 Pro, Archer C80 … | WLAN-Clients, CPU/Speicher | – | im Einsatz |
| AVM FRITZ!Box / Repeater | 7590 u. a., FRITZ!Repeater | DSL, Internet-Rate, LAN, WLAN-Clients | – | im Einsatz |
| Telekom Speedport | Smart 3, Smart 4, Smart 4R | DSL, LAN-Buchsen, WLAN-Clients | – | im Einsatz |

### 6.2 Netgear Plus (alle Varianten)

- Passwort der Switch-Weboberfläche eintragen, Treiber „automatisch“ genügt.
- Netgear erlaubt meist nur **eine Anmeldung gleichzeitig** – bist du in der Switch-Oberfläche angemeldet,
  meldet die nächste Abfrage dich dort ab (und umgekehrt).
- **Uplink-Port** eintragen: der wird nie gesperrt und nie im Kabeltest unterbrochen.
- **Port-Beschreibungen** am Switch pflegen (Gerätename oder Name des nächsten Switches) – netmon schlägt
  daraus die Verbindungen vor.

### 6.3 FRITZ!Box und FRITZ!Repeater

1. In der FritzBox: *Heimnetz → Netzwerk → Netzwerkeinstellungen* → „Zugriff für Anwendungen zulassen“ und
   „Statusinformationen über UPnP übertragen“.
2. *System → FRITZ!Box-Benutzer:* eigenen Benutzer (z. B. `netmon`) mit Recht „FRITZ!Box Einstellungen“.
3. In netmon: Typ „Router“, Treiber „AVM FRITZ!Box / Repeater“, Benutzer + Passwort.

**Repeater:** Typ „Access Point“, Treiber „AVM FRITZ!Box / Repeater“, **Benutzer leer lassen**, nur Kennwort –
netmon ermittelt den internen Benutzer selbst. Liefert die WLAN-Clients (Signal kommt in Prozent und wird grob in dBm
umgerechnet).

### 6.4 TP-Link Archer / Deco (Access Points)

TP-Link verschlüsselt seine Oberfläche; netmon nutzt dafür das Python-Paket `tplinkrouterc6u`:

```bash
sudo apt install python3-venv
sudo python3 -m venv /opt/netmon-python
sudo /opt/netmon-python/bin/pip install tplinkrouterc6u
```

In `/var/www/html/netmon/config.php` im `return [ … ]`-Block ergänzen:

```php
'python' => '/opt/netmon-python/bin/python',
```

(Nicht im Home-Ordner anlegen – der Webserver darf dort nicht hinein.)
Im **AP-Modus** liefert TP-Link die Signalstärke, im **Router-Modus** (z. B. C80) nicht – dann zeigt netmon „WLAN“
und das Band. Kabel-Geräte und abgemeldete Einträge aus der Liste ignoriert netmon. Auch TP-Link erlaubt nur eine
Admin-Sitzung (Tether-App wird abgemeldet).

### 6.5 Telekom Speedport

Kein Passwort nötig: Typ „Router“, Treiber „Telekom Speedport“ → Verbinden & abfragen.
Gelesen wird die Statusseite `/data/Status.json` (bei Smart 3/4 verschlüsselt, netmon entschlüsselt sie selbst):
Modell, Firmware, DSL-Status und -Sync, „Internet verbunden seit“, LAN-Buchsen mit Tempo, WLAN-Geräte mit Signal.
Internet-Rate und Störabstand stehen dort nicht drin.

**Hinweis:** Geräte im WLAN eines Repeaters/Access Points sieht der Speedport als „LAN-Gerät“ – sie erscheinen erst
richtig, wenn der Repeater/AP selbst abgefragt wird.

---

## 7. Steuern: LEDs, Ports sperren, Schnellzugriff

### 7.1 Switch-LEDs

- **Einstellungen → Switch-LEDs:** zwei Uhrzeiten (aus/an), z. B. 22:00 und 06:30. Gilt für alle Switches,
  die es können (GS308Ev4/GS108Ev4/GS30xEP, GS316EP). Leer = nie.
- Sofort: im Schnellzugriff „LEDs aus/an“ oder auf der Kommandozeile `leds.php aus|an`.

### 7.2 Ports sperren

Ein gesperrter Port ist per Software abgeschaltet – alles daran ist vom Netz getrennt, bis du ihn freigibst.
Typischer Einsatz: ein eigener Access Point fürs Kinderzimmer.

- Switch öffnen → Port-Liste → **🔒 Sperren** bzw. **🔓 Freigeben** (ohne Rückfrage). netmon wartet, bis der Switch
  den neuen Zustand meldet (ein paar Sekunden).
- Gesperrte Ports sind rot umrandet, jede Änderung steht unter Ereignisse.
- Name und übrige Einstellungen des Ports bleiben erhalten.
- Unterstützt: Netgear GS308Ev4/GS108Ev4 und die älteren Plus-Switches (GS105E/v2, GS108Ev3).

**Geschützte Ports (🛡):** Nicht sperrbar sind der Uplink-Port und jeder Port, hinter dem – auch mehrere Ebenen tief –
ein **Switch**, der **Router** oder **netmon selbst** hängt. Sonst würde sich netmon vom Netz abschneiden und könnte den
Port nicht wieder freigeben. Access Points bleiben sperrbar. Der Schutz richtet sich nach „Hängt an“/Port im Baum –
stimmt der Baum, stimmt der Schutz.

**Zeitplan per Cron**, z. B. `/etc/cron.d/netmon-kinder`:

```
0 20 * * 1-5  root  /usr/bin/php /var/www/html/netmon/collector/port.php aus 192.168.2.10 7
0 14 * * 1-5  root  /usr/bin/php /var/www/html/netmon/collector/port.php an  192.168.2.10 7
```

### 7.3 Einfache Ansicht (Schnellzugriff)

Für alle, die sich mit Netzwerken nicht auskennen – und fürs Handy. Aufruf: ⚡-Symbol in der Profi-Ansicht
(oder `…/netmon/schnell.php`). Zurück über „Profi-Ansicht“ unten. **netmon merkt sich je Benutzer die zuletzt
benutzte Ansicht** und startet beim nächsten Mal wieder darin.

- **Ampel:** „Internet läuft“ (grün, mit gemessener Geschwindigkeit), „langsam / Test fehlgeschlagen“ (gelb),
  „gestört“ (rot, Router meldet keine DSL-Verbindung).
- **Wo bin ich:** „Du bist hier mit *Handy* – über WLAN von *TP-Link C80*“ bzw. „von unterwegs“.
- **Anschlüsse** in Alltagssprache („An · 3 Geräte online“, „Gesperrt – hier gibt es kein Netz“).
  Beim Sperren erscheint zuerst, **wer danach kein Netz mehr hat** – auch die WLAN-Geräte eines Access Points
  dahinter. Hängt das eigene Gerät daran, warnt netmon deutlich: von diesem Gerät aus ließe sich der Anschluss
  danach nicht mehr freigeben.
- Geht etwas nicht, steht der Grund dabei: „geschützt“ (dahinter hängt ein Switch, der Router oder netmon),
  „Switch nicht eingerichtet“, „Switch hat sich länger nicht gemeldet“, „nur ansehen“.
- **Lichter an den Switches** aus/an.
- **Was zuletzt passiert ist:** nur Sperren/Freigeben und Ausfälle von Router, Switches, Access Points.
- **Hilfe** mit den häufigsten Fragen (auch: „Ich habe mich selbst ausgesperrt“).
- **Anschlüsse hinzufügen** (Admin): Profi-Ansicht → Switch öffnen → Port-Liste → **☆ Schnellzugriff**.
  Als Name erscheint die Port-Beschreibung vom Switch – also dort z. B. „WLAN Kinderzimmer“ eintragen.

**Benutzer und Rollen** (Einstellungen → Benutzer):

| Rolle | darf | Start-Ansicht |
|---|---|---|
| Admin | alles | Profi |
| Familie | ansehen, Anschlüsse sperren/freigeben, Lichter schalten | Einfach |
| Betrachter | nur ansehen | Einfach |

Die Start-Ansicht ist nur ein Vorschlag – jeder kann umschalten.

---

## 8. Fernzugriff mit Tailscale

Damit erreichst du von unterwegs netmon **und** alle Geräte im Heimnetz über ihre normale IP – ohne Portfreigabe.
Der netmon-Rechner wird dabei zum **Subnet-Router** für sein Heimnetz.

### 8.1 Einrichten

1. **Tailscale-Konto** auf <https://login.tailscale.com> anlegen (privat kostenlos).
2. **Auf dem netmon-Rechner:**
   ```bash
   cd ~/netmon && sudo bash install.sh --with-tailscale
   ```
   Beim ersten Mal erscheint ein Anmelde-Link – im Browser bestätigen. Das Skript zeigt am Ende die Tailscale-IP.
3. **Route freigeben** (einmalig): <https://login.tailscale.com/admin/machines> → Rechner → „…“ →
   **Edit route settings** → Heimnetz anhaken → **Save**.
4. **Handy/Laptop:** Tailscale-App, mit demselben Konto anmelden; „Use Tailscale subnets“ an
   (Linux: `sudo tailscale up --accept-routes`).
5. **Test** mit WLAN aus: `http://<IP des netmon-Rechners>/netmon/`, Router-IP, Switch-IP.

Bei späteren Updates reicht `sudo bash install.sh` – die Tailscale-Einstellung bleibt.

### 8.2 Mehrere Standorte

Jeder Standort mit eigenem netmon + `--with-tailscale` bietet sein eigenes Netz an. Die Netze müssen
**verschieden** sein (z. B. zu Hause `192.168.5.0/24`, bei Verwandten `192.168.2.0/24`), sonst weiß Tailscale nicht,
welches `192.168.x.10` gemeint ist.

In der Admin-Konsole je Rechner nur **sein** Netz angehakt lassen. Alte, nicht mehr angebotene Routen
(rotes Ausrufezeichen, „approved but not advertised anymore“) mit **Remove** entfernen.

### 8.3 Fehlersuche Tailscale

- `tailscale status` – ist der Rechner online?
- Welche Route bietet er an? `tailscale debug prefs | grep -A3 AdvertiseRoutes`
- Nur die 100.x-Adresse geht, 192.168.x nicht → Route nicht freigegeben oder „Use Tailscale subnets“ aus.
- „ERR_NETWORK_CHANGED“ → Tailscale verbindet sich gerade neu, kurz warten.
- Firewall (`sudo ufw status`) muss Weiterleitung erlauben (bei Mint/Raspberry Pi OS meist aus).

---

### 8.4 Ohne Tailscale: aus dem Internet (DynDNS + HTTPS)

Für Leute ohne Tailscale-App: netmon ist dann unter z. B. `https://netmon.meinedomain.de/netmon/` erreichbar.
Tailscale ist sicherer (nichts ist offen) – wer beides will, kann beides.

**Vorbereiten**

1. **Strato:** Domains → Subdomain anlegen (z. B. `netmon.meinedomain.de`) → DNS → **DynDNS** einschalten und ein
   DynDNS-Passwort für *diese* Subdomain vergeben. DynDNS-Benutzer ist die Subdomain selbst.
   Ein von Hand eingetragener A-Record reicht nicht – dann meldet Strato `nohost`.
2. **Router:** Port **80** und **443** (TCP) auf den netmon-Rechner weiterleiten – für **IPv4 und IPv6**
   (Speedport: Internet → Portfreischaltung, dort auch die IPv6-Freigaben; FritzBox: Internet → Freigaben,
   beim Gerät „IPv4“ und „IPv6“ anhaken). netmon meldet beide Adressen an Strato; viele Handys nutzen zuerst IPv6.
   Ohne IPv6-Freigabe: `DD_IPV6=0` in `/etc/netmon-dyndns.conf` und bei Strato den AAAA-Eintrag löschen.

**Einrichten**

```bash
cd ~/netmon && sudo bash install.sh --with-internet
```

Das Skript fragt einmalig Name, DynDNS-Benutzer (meist der Name selbst), DynDNS-Passwort und eine E-Mail
für Let's Encrypt und

- meldet die öffentliche IP alle 5 Minuten an Strato, wenn sie sich ändert (`/usr/local/bin/netmon-dyndns`,
  Protokoll `/var/log/netmon-dyndns.log`, Zugang in `/etc/netmon-dyndns.conf`, nur für root lesbar),
- holt ein **HTTPS-Zertifikat** von Let's Encrypt (erneuert sich selbst),
- lässt von außen **nur netmon** durch – phpMyAdmin und alles andere auf dem Webserver bleiben im Heimnetz und
  über Tailscale erreichbar,
- leitet von außen immer auf HTTPS um.

**Schutz im Login:** Nach 5 falschen Passwörtern ist eine IP 15 Minuten gesperrt, von außen insgesamt höchstens
30 Fehlversuche in 15 Minuten. Im Heimnetz und über Tailscale gilt nur die Sperre je IP.
Trotzdem: **starkes Admin-Passwort** – und für Familie/Freunde lieber eigene Konten mit der Rolle „Betrachter“
(Einstellungen → Benutzer), die nur ansehen dürfen.

**Prüfen / Fehlersuche**

| Problem | Lösung |
|---|---|
| Strato meldet `badauth` | Benutzer/Passwort falsch – `/etc/netmon-dyndns.conf` korrigieren oder löschen und neu aufrufen |
| Strato meldet `nohost` | DynDNS für die Subdomain bei Strato nicht eingeschaltet |
| Zertifikat geht nicht | Port 80 nicht weitergeleitet, oder der Name zeigt noch nicht hierher – später erneut `--with-internet` |
| IP sofort neu melden | `sudo netmon-dyndns --force` |
| Von außen geht nichts, von innen schon | im Handy WLAN aus und testen; Portfreigabe im Router prüfen |

## 9. Empfehlung: IP-Plan fürs Heimnetz

Feste Infrastruktur unten, wechselnde Geräte oben – dann sagt die IP schon, was es ist, und ein Fremder mit niedriger
IP fällt sofort auf.

| Bereich | Wofür |
|---|---|
| `.1` | Router |
| `.2 – .9` | Server, Raspberry Pi (netmon) |
| `.10 – .19` | Switches |
| `.20 – .29` | Access Points, Repeater |
| `.30 – .149` | Geräte mit fester IP (Drucker, Kameras, Fernseher …) |
| `.150 – .250` | DHCP für alles andere |

- Im Router den **DHCP-Bereich** auf `.150 – .250` setzen.
- Feste Adressen am besten als **Reservierung im Router** („immer die gleiche IP“) – nicht im Gerät selbst.
- In netmon unter **Einstellungen → Bereiche** dieselbe Aufteilung anlegen.
- netmon erkennt Geräte an der **MAC-Adresse** – wechselt eine IP, bleibt die Zuordnung erhalten (Ereignis „neue IP“).
- Handys nutzen je WLAN eine zufällige MAC („private MAC“). Für eindeutige Erkennung im Handy beim Heim-WLAN
  „Geräte-MAC verwenden“ einstellen.

---

## 10. Einstellungen

| Abschnitt | Einstellung | Standard |
|---|---|---|
| Netzwerkscan | Subnetz | automatisch erkannt |
| | Scan-Intervall | 5 Minuten |
| | Offline nach | 3 verpassten Scans |
| | Offline-Geräte ausblenden nach (nie, 1–30 Tage) | 3 Tage |
| | Domain-Endung (wird vom Hostnamen abgeschnitten) | `.fritz.box` |
| Speedtest | an/aus, Intervall | an, 10 Minuten |
| Switch-LEDs | aus um / an um | leer (nie) |
| Aufbewahrung | Speedtest-Rohdaten | 30 Tage (danach Tageswerte) |
| | Ereignisse | 365 Tage |
| | Scan-Protokoll | 30 Tage |
| | Verlauf Switches & Access Points (je Minute) | 7 Tage |
| Bereiche | IP-Bereiche mit Typ (Kabel, WLAN, Labor, DHCP) und übergeordnetem Gerät | – |
| Benutzer | Admin (darf alles) / Familie (ansehen + Anschlüsse sperren) / Betrachter (nur ansehen); Start-Ansicht einfach/profi | – |

---

## 11. Befehle für die Kommandozeile

Alle unter `/var/www/html/netmon/collector/`, Aufruf mit `php …` (bzw. `sudo php …`):

| Befehl | Wirkung |
|---|---|
| `tick.php` | Taktgeber (läuft per Cron jede Minute): Scan, Abfragen, Speedtest, LEDs, Aufräumen |
| `scan.php` | Netzwerkscan sofort |
| `switches.php` | alle eingerichteten Geräte sofort abfragen |
| `speedtest.php` | Speedtest sofort |
| `netmon-dyndns [--force]` | (mit `--with-internet`) öffentliche IP an Strato melden |
| `leds.php aus\|an [IP …]` | LEDs aller (oder bestimmter) Switches schalten |
| `port.php aus\|an <Switch-IP> <Port>` | Port sperren/freigeben (geschützte Ports werden abgelehnt) |
| `cleanup.php` | alte Daten löschen (läuft nachts von selbst) |

Protokolle: `/var/log/netmon-tick.log`, `/var/log/netmon-scan.log`, `/var/log/netmon-switches.log`,
`/var/log/netmon-leds.log`.

---

## 12. Fehlersuche

| Problem | Lösung |
|---|---|
| Nach Update alte Oberfläche | Browser **Strg + F5** |
| Anmeldeseite meldet Datenbankfehler | `config.php` prüfen; `mysqltest.php` in den Webordner legen und aufrufen, danach löschen |
| `install.sh`: „Access denied for user root“ | ab 1.1.1 behoben – install.sh nutzt den Zugang aus `config.php` |
| Switch: „Anmeldung fehlgeschlagen“ | Passwort prüfen; ist jemand in der Switch-Oberfläche angemeldet? abmelden |
| FritzBox: „Anmeldung fehlgeschlagen“ | TR-064 („Zugriff für Anwendungen“) an? Benutzer mit Recht „Einstellungen“? |
| TP-Link: „Python-Paket fehlt“ | [6.4](#64-tp-link-archer--deco-access-points) – venv anlegen, `config.php` ergänzen |
| Unbekanntes Modell / „Seitenaufbau unbekannt“ | Analyse-Paket erstellen ([Kapitel 14](#14-neue-geräte-anlernen)) |
| Gerät hängt im Baum falsch | „Hängt an“/Port im Gerät setzen oder Port-Beschreibung am Switch pflegen |
| WLAN-Geräte am falschen AP | Den AP/Repeater selbst abfragen lassen (Treiber + Passwort) |
| Gelbe Leitung (100M) | altes Gerät (Pi 3, TV) – normal; sonst Kabel/Stecker prüfen, Kabeltest |
| Rote Leitung (10M) | meist Gerät im Standby; sonst Kabeltest |
| LEDs/Port schalten klappt nicht | Treiber kann es? Switch-Oberfläche abmelden, nochmal |

---

## 13. Technik: Aufbau, Dateien, Datenbank

### 13.1 Ablauf

```
cron (jede Minute) → collector/tick.php
   ├─ scan.php        nmap → devices, device_events, scans
   ├─ switches.php    Treiber je Gerät → switch_ports, port_history, wifi_clients, ap_history, dev_metrics
   ├─ speedtest.php   Ookla → speedtests
   ├─ leds.php        nach Zeitplan
   └─ cleanup.php     nachts 3:30
Browser → index.php / schnell.php → api.php (JSON, Anmeldung + CSRF)
```

Damit sich Cron und Live-Ansicht nicht gegenseitig abmelden, meldet sich netmon je Gerät immer nur einmal
gleichzeitig an (MariaDB `GET_LOCK`).

### 13.2 Dateien

```
netmon/                    → /var/www/html/netmon
  index.php                Hauptoberfläche
  schnell.php              Schnellzugriff (Handy)
  login.php, logout.php
  api.php                  JSON-Schnittstelle
  db.php                   Anmeldung, Sitzung, Einstellungen
  config.php               Datenbank-Zugang (nie im Paket)
  assets/                  app.js, app.css, Bootstrap, D3, Chart.js, Schriften (alles lokal, kein CDN)
  collector/               Cron-Skripte (nur Kommandozeile, im Browser 403)
    switch_drivers.php     gemeinsame Treiber-Grundlage
    drivers/               ein Treiber je Hersteller/Oberfläche
sql/                       schema.sql, migrate-vN.sql, zones-beispiel.sql
install.sh                 Installation/Update
docs/                      Handbuch, Bilder
```

### 13.3 Datenbank (Version 12)

| Tabelle | Inhalt |
|---|---|
| `devices` | alle Geräte (MAC, IP, Name, Typ, Raum, Hängt an, Port, Treiber, Zugang) |
| `device_events` | Ereignisse |
| `scans` | Scan-Protokoll |
| `speedtests`, `speedtests_daily` | Speedtest roh und Tageswerte |
| `zones` | IP-Bereiche |
| `users` | Benutzer (Admin/Familie/Betrachter, zuletzt benutzte Ansicht) |
| `settings` | Einstellungen (Schlüssel/Wert) |
| `switch_ports` | aktueller Port-Status je Switch (inkl. `admin_off` = gesperrt) |
| `port_history` | Datenrate je Port und Minute |
| `wifi_clients` | WLAN-Clients je AP/Router mit Signal |
| `ap_history` | CPU, Speicher, Clients je AP und Minute |
| `login_fails` | fehlgeschlagene Anmeldungen je IP (Login-Sperre) |
| `dev_metrics` | Router-Messwerte je Minute (DSL, Internet) |

---

## 14. Neue Geräte anlernen

Für ein Gerät ohne passenden Treiber:

1. Gerät öffnen → Einstellungen → Passwort eintragen → **Analyse-Paket**.
   netmon lädt alle Seiten der Weboberfläche als Textdatei (ohne Passwörter, ohne Anmelde-Anfragen).
2. Alternativ ein **HAR** aus dem Browser: Weboberfläche öffnen, **erst anmelden**, dann F12 → Netzwerk →
   die gewünschte Aktion ausführen (z. B. Clientliste öffnen, LED umschalten) → „Als HAR speichern“.
3. Datei als Issue auf GitHub anhängen.

Ein Treiber ist eine PHP-Klasse in `collector/drivers/`, die `SwitchDriver` erweitert
(`detect`, `login`, `logout`, `ports`, optional `info`, `wifiClients`, `metrics`, `stats`, `cableTest`,
`setLeds`, `setPortEnabled`, `pages`) und in `SWITCH_DRIVERS` eingetragen wird.

---

## 15. Quellen und Danksagung

- **py-netgear-plus** (foxey u. a., Apache-2.0) – Vorlage für Anmeldung und Seitenaufbau mehrerer Netgear-Treiber,
  Beispielseiten zum Testen. Kein Code übernommen.
- **tplinkrouterc6u** (AlexandrErohin) – Bibliothek für TP-Link-Access-Points.
- **hass_tplink_easy_smart** (Vladimir Makeev, MIT) – Vorlage für „TP-Link Easy Smart“.
- **smrt** (pklaus, rgl; GPL) – Protokollbeschreibung, nur zum Nachlesen.
- **AVM TR-064** – Schnittstelle des FritzBox-/Repeater-Treibers.
- **dsl-monitoring** (aaronk6) – Entschlüsselung der Speedport-`Status.json`.
- Mitgeliefert: Bootstrap 5.3 (MIT), D3 (ISC), Chart.js (MIT), DM Sans / DM Serif Display (SIL OFL 1.1).

Jede Treiberdatei nennt ihre Quellen im Kopf. Lizenz von netmon: MIT.
