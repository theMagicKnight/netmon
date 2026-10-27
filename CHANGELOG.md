# Changelog

## 1.17.1 – 2026-10-27

- Kabel-Stil „Echt“: Kabel kommen immer von oben in die Ziel-Buchse und liegen nicht mehr quer über den Nachbar-Buchsen
  und Gerätesymbolen.

## 1.17.0 – 2026-10-27

- Ansicht „Kabel“: dritter Kabel-Stil **„Echt“** – RJ45-Stecker mit Knickschutz in Kabelfarbe, Kabel vorn mit Schatten,
  jedes Kabel mit eigenem Charakter (straff, locker durchhängend, mit Schlaufe); kurze Kabel hoch zu den Endgeräten;
  Ports mit Link ohne bekanntes Gerät bekommen ein herabhängendes Kabel. Namen bleiben lesbar, Kabel berühren zeigt die Infos.
- Kabelführung: das senkrechte Stück unter dem Start wird jetzt mitgeprüft – Kabel laufen nicht mehr durch ein Gerät
  dazwischen, sondern außen durch die Kabelrinne.

## 1.16.0 – 2026-10-27

- Ansicht „Kabel“:
  - **Anordnen** (Admin, am PC): Router und Switches frei verschieben, gespeichert für alle (neue Einstellung `rack_layout`);
    „Automatisch anordnen“ stellt wieder untereinander.
  - **Kabel-Stil** umschaltbar: „Kabelrinne“ (eckig, außen vorbei) oder „Geschwungen“ (Bögen wie Patchkabel), je Browser gemerkt.
  - **Kabel berühren** zeigt wie im Baum: von wo nach wo, Port ↔ Port, Tempo, Datenrate; das Kabel wird hervorgehoben.
  - Bessere Kabelführung: probiert mehrere Wege (Mitte, knapp unter dem Start, knapp über dem Ziel) und nimmt den ersten,
    der an keinem anderen Gerät vorbeiführt; sonst außen durch die Kabelrinne.

## 1.15.0 – 2026-10-27

- **Neue Ansicht „Kabel“** (vierter Reiter neben Baum, Liste, Räume): Router und Switches von vorn untereinander wie im
  Netzwerkschrank, Kabel vom Port am oberen Gerät zum Uplink-Port des Switches darunter (Farbe nach Tempo, gestrichelt
  offline, lange Kabel links durch eine Kabelrinne). Über jeder Buchse das angeschlossene Gerät mit Symbol und schrägem
  Namen; gesperrte Ports rot. Maus über Buchse/Kabel zeigt Tempo und Datenrate, Klick öffnet das Gerät. Switches ohne IP
  (Patchfeld) erscheinen gestrichelt, Geräte ohne Port-Angabe als „ohne Port“. Am Handy seitlich wischen.
- Handy: Reiter ohne Symbole, damit vier Ansichten, „+N“ und „Nur online“ in eine Zeile passen.

## 1.14.1 – 2026-10-25

- **Doku überarbeitet:** neue Bilder (Demo-Netz) inkl. einfacher Ansicht am PC und Handy; README (englisch) auf den aktuellen
  Stand – Steuer-Funktionen, Treiber-Tabelle mit „liest / schaltet“, Rollen, Internet-Zugang; Handbuch mit Bildern,
  neuem Abschnitt 7.4 „Router & WLAN schalten“, aktualisiertem Überblick, ersten Schritten und Fehlersuche.
- Handy: „N ausgeblendet“ ist jetzt kurz („+N“) und schiebt den Schalter „Nur online“ nicht mehr aus dem Bild.
- Einfache Ansicht: Aufruf vom Rechner selbst (localhost) gilt nicht mehr als „von unterwegs“.

## 1.14.0 – 2026-10-23

- **Netgear GS116Ev2** (Treiber „GS116E v2 / JGS“), am echten Gerät mit Firmware V2.6.0.50:
  - Link und Tempo werden jetzt erkannt, auch wenn der Switch auf Deutsch antwortet („Aktiv“, „1000M“, „Nicht verbunden“) –
    vorher stand bei allen Ports „kein Link“.
  - **Ports sperren/freigeben** wie die Weboberfläche (Portstatus → Geschwindigkeit „Deaktiviert“ bzw. „Auto“); Beschreibung
    und Flusssteuerung bleiben erhalten. Gilt auch für Schnellzugriff, einfache Ansicht und `port.php`.

## 1.13.1 – 2026-10-23

- **Verbinden statt „Das ergäbe eine Schleife“:** Hing das neue Elternteil bisher unter dem Gerät (typisch: der Router war
  über die Port-Beschreibung „fritz.box“ versehentlich unter einen Switch gehängt worden), hängt netmon diesen Zweig jetzt
  selbst an die alte Stelle um und verbindet dann wie vorgeschlagen.
- Vorschläge aus Port-Beschreibungen: Der Router liegt immer oberhalb – eine Beschreibung wie „fritz.box“ am Switch heißt
  „dieser Port führt zum Router“ (Uplink), nicht „der Router hängt hier dran“.

## 1.13.0 – 2026-10-23

- **TP-Link Archer / Deco schalten:** WLAN, Gäste-WLAN und IoT-Netz an/aus (alle vorhandenen Bänder zusammen) und neu
  starten – über das Passwort, das für die Abfrage ohnehin eingetragen ist (Paket tplinkrouterc6u). Profi-Ansicht: Knöpfe im
  Geräte-Dialog; einfache Ansicht: eigene Karte unter „Router & WLAN“.
- Einfache Ansicht: Vor dem WLAN-Ausschalten zählen jetzt auch die Geräte **hinter** einem Repeater im WLAN als betroffen
  (Speedport → FRITZ!Repeater, Archer C80 → Repeater Terrasse). Bei mehreren Geräten trägt jede Karte Name und Ort; jede zeigt
  nur die Knöpfe, die das Gerät kann.

## 1.12.2 – 2026-10-22

- Geräte hinter einem Repeater: Sobald der Repeater die echte MAC meldet, verschwindet der vorläufige Eintrag „hinter Repeater“
  unter derselben IP (keine Doppelten mehr). Der Gerätename kommt aus der WLAN-Liste des Repeaters, wenn das Netz keinen liefert.

## 1.12.1 – 2026-10-22

- Repeater-Erkennung strenger: erst ab drei IPs unter einer MAC (oder zwei, wenn eine als AVM-Gerät antwortet). Geräte, die
  einfach zwei IPs haben, gelten nicht mehr als Repeater; ein von 1.12.0 zu schnell gelernter Eintrag wird samt seiner
  Geräte „hinter Repeater“ wieder entfernt. Solche Geräte bleiben bei ihrer bekannten IP, statt hin und her zu springen.
- Eigene IP eines Repeaters, der nicht von AVM ist: die von netmon gespeicherte IP, sonst die einzige mit Weboberfläche.
- Prüfungen werden gemerkt – der Scan wird dadurch nicht langsamer.

## 1.12.0 – 2026-10-22

- **Repeater mit MAC-Tarnung:** Ein FRITZ!Repeater (u. a.) per WLAN an einem fremden Router zeigt alle Geräte hinter sich
  mit seiner eigenen MAC. Der Scan merkte sich je MAC nur eine IP – der Repeater-Eintrag sprang deshalb zwischen den IPs
  seiner Geräte hin und her. Jetzt erkennt netmon solche MACs selbst (eine MAC unter mehreren IPs, gemerkt in
  `proxy_macs`), hält den Repeater auf seiner eigenen IP (erkannt an der AVM-Schnittstelle auf Port 49000) und legt die
  Geräte dahinter getrennt an, im Baum unter dem Repeater. Echte MAC-Adressen kommen aus der WLAN-Liste des Repeaters,
  sobald er mit Kennwort abgefragt wird; bis dahin werden die Geräte an ihrer IP erkannt.

## 1.11.1 – 2026-10-21

- Speedport am Smart 4R Typ A bestätigt: Anmeldung, Gäste-WLAN schalten, öffentliche IP.
- Profi-Ansicht: Router-Knöpfe zeigen nur die passende Richtung (WLAN an → nur „WLAN aus“).

## 1.11.0 – 2026-10-21

- **Speedport mit Gerätepasswort:** Das Passwort ist freiwillig. Ohne liest netmon wie bisher alles Offene (jetzt auch, ob
  WLAN und Gäste-WLAN an sind). Mit Passwort meldet es sich an wie die Weboberfläche und kann **WLAN** und **Gäste-WLAN**
  schalten, **Internet neu verbinden** und **neu starten**; dazu die öffentlichen IP-Adressen. Angemeldet wird nur bei Bedarf
  (Schalten, stündlicher Info-Abgleich, Analyse-Paket), der Abgleich jede Minute bleibt ohne Anmeldung.
  Vorlage: speedport-api von Andre0512 (MIT), in PHP nachgebaut – kein Python nötig.
- **Einfache Ansicht:** neue Karte „Router“ mit WLAN, Gäste-WLAN und „Internet neu verbinden“. Vor dem WLAN-Ausschalten
  steht da, wer dann kein Netz mehr hat – mit Warnung, wenn man selbst darüber verbunden ist.
- **Profi-Ansicht:** Router-Knöpfe im Geräte-Dialog (WLAN, Gäste-WLAN, neu verbinden, neu starten).
- Analyse-Paket des Speedports enthält mit Passwort weitere Seiten (IP-Daten, WLAN, Sicherheitsstatus) – ohne Passwörter,
  Schlüssel, Rufnummern und Seriennummern.

## 1.10.0 – 2026-10-21

- **Einfache Ansicht** für Einsteiger (bisher „Schnellzugriff“, ⚡): Internet-Ampel, „Du bist hier mit … über …“,
  Anschlüsse in Alltagssprache. Vor dem Sperren zeigt sie, welche Geräte danach kein Netz mehr haben (auch WLAN-Geräte
  eines Access Points dahinter), und warnt deutlich, wenn das eigene Gerät daran hängt. Gründe, warum etwas nicht geht
  („geschützt“, „Switch nicht eingerichtet“, „nur ansehen“), stehen direkt dabei; dazu eine Hilfe mit den häufigsten Fragen
  und Ereignisse nur noch zu Sperren/Freigeben und Ausfällen von Router, Switches und Access Points.
- Die bisherige Oberfläche heißt **Profi-Ansicht**. netmon merkt sich je Benutzer die zuletzt benutzte Ansicht.
- Neue Rolle **„Familie“**: ansehen, Anschlüsse sperren/freigeben und Switch-Lichter schalten – aber nichts einstellen.
  Beim Anlegen eines Benutzers lässt sich die Start-Ansicht wählen. Datenbank-Version 12 (wird selbst ergänzt).
- `install.sh --with-internet`: Meldet Strato `nohost`, gibt es nur noch eine Warnung, das Zertifikat wird trotzdem geholt.

## 1.9.1 – 2026-10-21

- DynDNS meldet jetzt IPv4 **und** die feste IPv6 des Rechners an Strato (Strato hatte die IPv6 sonst einmalig selbst
  eingetragen und danach nicht mehr aktualisiert). Wer IPv6 im Router nicht freigeben will: `DD_IPV6=0` in
  `/etc/netmon-dyndns.conf` und bei Strato den AAAA-Eintrag löschen.

## 1.9.0 – 2026-10-21

- **Aus dem Internet erreichbar:** `install.sh --with-internet` richtet Strato-DynDNS (alle 5 Minuten, nur bei neuer IP)
  und HTTPS mit Let's Encrypt ein. Von außen ist nur netmon erreichbar (phpMyAdmin u. a. bleiben im Heimnetz/Tailscale),
  unverschlüsselte Aufrufe von außen werden auf HTTPS umgeleitet.
- **Login-Sperre:** 5 falsche Passwörter je IP → 15 Minuten gesperrt; von außen zusätzlich höchstens 30 Fehlversuche
  in 15 Minuten insgesamt. Neue Tabelle `login_fails` (Datenbank-Version 11, wird selbst ergänzt).
- **Ports sperren/freigeben:** wirkt jetzt beim ersten Klick auch in der Anzeige – netmon wartet, bis der Switch den neuen
  Zustand meldet (vorher zeigte die sofortige Abfrage noch den alten Zustand). Die Rückfrage beim Sperren entfällt.

## 1.8.0 – 2026-10-20

- **Offline-Geräte ausblenden:** Neue Einstellung (Netzwerkscan → „Offline-Geräte ausblenden“, Standard 3 Tage).
  Geräte, die länger offline sind, verschwinden aus Baum, Liste und Räumen – gespeichert bleiben sie (Name, Raum, Verlauf)
  und tauchen wieder auf, sobald sie online sind. Neben „Nur online“ zeigt „N ausgeblendet“ sie per Klick wieder an.
  Geräte, an denen etwas hängt, werden nie ausgeblendet; unter „Geräte“ stehen weiterhin alle.

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
