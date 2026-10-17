# netmon

**Network monitor for home and small networks** – PHP/MySQL web app that scans your LAN, shows every device in a
tree, list and room view, and reads switches, access points and the router directly (ports, link speed, traffic,
cable test, Wi-Fi clients, DSL values).
*Deutsche Anleitung: [LIESMICH.md](LIESMICH.md)*

![Network tree](docs/tree.png)

| Rooms | Switch ports | Port traffic |
|---|---|---|
| ![Rooms](docs/rooms.png) | ![Switch](docs/switch.png) | ![Traffic](docs/traffic.png) |
| **FritzBox DSL & internet** | **Access point / Wi-Fi clients** | **Speedtest** |
| ![DSL](docs/dsl.png) | ![AP](docs/ap.png) | ![Speedtest](docs/speed.png) |
| **Light theme** | **Mobile** | |
| ![List, light theme](docs/list-light.png) | ![Mobile](docs/mobile.png) | |

*Screenshots show a demo network with made-up devices.*

## Features

- **Scan** with nmap: new devices, online/offline, IP changes as events; vendor from MAC
- **Views:** tree (router → switches → devices), list, rooms with freely arrangeable cards and cable lines
- **Device drivers** (read-only):

  | Driver | Models | Status |
  |---|---|---|
  | Netgear Plus | GS105E v2, GS108E v3, GS305E, GS308E, GSS108E | in use |
  | Netgear Plus, new UI | GS308E v4, GS108E v4, GS305EP, GS308EP(P) | GS308E v4 in use |
  | Netgear GS116E v2 / JGS | GS116Ev2, JGS516PE, JGS524Ev2 | tested against reference pages |
  | Netgear GS316EP / EPP | GS316EP, GS316EPP (PoE sum) | tested against reference pages |
  | TP-Link Easy Smart | TL-SG105E/108E/116E … | draft, untested |
  | TP-Link Archer / Deco | Wi-Fi clients, CPU/memory | in use (needs Python, see below) |
  | AVM FRITZ!Box | DSL sync, SNR, CRC, internet rate, LAN ports (TR-064) | in use |
  | Telekom Speedport Smart 3/4 | DSL sync, LAN ports, Wi-Fi clients with signal – **no password needed** | in use (Smart 4R) |

- Port front panel, link speed, traffic history (1 h / 24 h / 7 days) and **live view**, cable test, health checks
- **Link speed in colour** on every cable in tree and room view: 10M red, 100M yellow, 1G green, 2.5G+ blue
- **Switch LEDs off at night** on a schedule or via `collector/leds.php aus|an` (Netgear GS30x/GS108Ev4/GS316)
- **Connection suggestions** from switch port descriptions and the FritzBox host list
- **Analysis package**: one click collects a device's web pages to build a driver for a new model
- Dark/light theme, mobile friendly, all assets bundled locally (no CDN)

## Requirements

Debian/Ubuntu/Raspberry Pi OS (or any LAMP) with Apache, PHP ≥ 8.0 (pdo_mysql, curl, mbstring, xml), MariaDB/MySQL, nmap.
Optional: Python 3 venv with `tplinkrouterc6u` for TP-Link access points.

## Install

```bash
git clone https://github.com/theMagicKnight/netmon.git
cd netmon
sudo bash install.sh            # checks what is installed, adds the missing parts
# optional: --with-tailscale    remote access, home network offered as Tailscale subnet route
```

The script checks Apache/PHP/MariaDB/nmap and the PHP extensions, installs only what is missing, copies the app to
`/var/www/html/netmon`, creates database + `config.php` (random password), sets up the cron job and prints the URL.
Running it again **updates** an existing installation – `config.php` and data are kept, the database schema is
upgraded automatically.

Manual install (FTP + phpMyAdmin): see [LIESMICH.md](LIESMICH.md).

## Layout

```
netmon/            web app – everything in one folder (incl. config.php)
  collector/       cron scripts (scan, speedtest, device polling) – CLI only
  collector/drivers/  one file per device family, each names its sources in the header
sql/               schema and migrations
install.sh         installer / updater
```

## Adding a device

Open a device → *Settings* → choose a driver (or *auto-detect*) → password → *Connect & query*.
Unknown model? Click **Analysis package** and open an issue with the file (it contains no passwords).

## Credits

- [py-netgear-plus](https://github.com/foxey/py-netgear-plus) (Apache-2.0) – reference for Netgear login/page layouts and test pages
- [hass_tplink_easy_smart](https://github.com/vmakeev/hass_tplink_easy_smart) (MIT) – reference for TP-Link Easy Smart
- [smrt](https://github.com/pklaus/smrt) / [philippechataignon-smrt](https://github.com/rgl/philippechataignon-smrt) (GPL) – protocol notes only, no code used
- [tplinkrouterc6u](https://github.com/AlexandrErohin/TP-Link-Archer-C6U) – used as library for TP-Link access points
- AVM [TR-064](https://avm.de/service/schnittstellen/) documentation
- [dsl-monitoring](https://github.com/aaronk6/dsl-monitoring) by aaronk6 – how to decrypt the Speedport `Status.json`
- Bundled: Bootstrap 5.3 (MIT), D3 (ISC), Chart.js (MIT), DM Sans / DM Serif Display (SIL OFL 1.1)

No code was copied from the referenced projects; the drivers are written from scratch in PHP.

## License

[MIT](LICENSE)
