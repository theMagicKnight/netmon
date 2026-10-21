# netmon – Kurzanleitung

Netzwerk-Monitor fürs Heimnetz: Geräte, Baum, Räume, Switch-Ports, WLAN, Speedtest, LEDs, Ports sperren, Fernzugriff.

📖 **Ausführlich: [Handbuch](docs/HANDBUCH.md)**

## Installieren

```bash
git clone https://github.com/theMagicKnight/netmon.git
cd netmon
sudo bash install.sh                  # optional: --with-tailscale (Fernzugriff), --with-internet (DynDNS + HTTPS)
```

Danach `http://<server>/netmon/` aufrufen und das Admin-Konto anlegen.
Alles liegt in **einem** Ordner: `/var/www/html/netmon/`.

Ohne Skript (FTP + phpMyAdmin): [Handbuch, Kapitel 2](docs/HANDBUCH.md#2-installation).

## Aktualisieren

```bash
cd ~/netmon && git pull && sudo bash install.sh
```

Daten und `config.php` bleiben erhalten. Danach im Browser **Strg + F5**.

## Wo steht was im Handbuch?

| Thema | Kapitel |
|---|---|
| Erste Schritte (Router, Switches, APs eintragen, Baum aufbauen) | [4](docs/HANDBUCH.md#4-erste-schritte) |
| Oberfläche, Farben der Leitungen | [5](docs/HANDBUCH.md#5-die-oberfläche) |
| Geräte-Treiber: Netgear, TP-Link, FRITZ!Box/Repeater, Speedport | [6](docs/HANDBUCH.md#6-geräte-abfragen-treiber) |
| LEDs, Ports sperren, einfache Ansicht, Benutzer-Rollen | [7](docs/HANDBUCH.md#7-steuern-leds-ports-sperren-schnellzugriff) |
| Fernzugriff mit Tailscale | [8](docs/HANDBUCH.md#8-fernzugriff-mit-tailscale) |
| IP-Plan fürs Heimnetz | [9](docs/HANDBUCH.md#9-empfehlung-ip-plan-fürs-heimnetz) |
| Befehle, Fehlersuche, Technik | [11–13](docs/HANDBUCH.md#11-befehle-für-die-kommandozeile) |
| Neue Geräte anlernen | [14](docs/HANDBUCH.md#14-neue-geräte-anlernen) |

Änderungen je Version: [CHANGELOG.md](CHANGELOG.md) · Lizenz: MIT
