#!/usr/bin/env bash
# netmon – Installation auf einem Debian/Ubuntu/Raspberry-Pi-OS-System mit Apache, PHP und MariaDB
#
# Aufruf (im entpackten Ordner):
#   sudo ./install.sh                                  # Subnetz wird automatisch erkannt
#   sudo ./install.sh --zones sql/zones-beispiel.sql        # mit vorbereiteten Bereichen
#   sudo ./install.sh --subnet 192.168.1.0/24 --web /var/www/html/netmon --speedtest-every 20
#   sudo ./install.sh --no-speedtest                    # Speedtest zunächst aus (in den Einstellungen umschaltbar)
#   sudo ./install.sh --with-tailscale                  # zusätzlich Fernzugriff: Tailscale + Heimnetz als Subnet-Route
#
# Alles landet in EINEM Ordner (Standard /var/www/html/netmon): Weboberfläche, collector/ und config.php.
# Ein zweiter Aufruf aktualisiert; Datenbank, config.php und Einstellungen bleiben erhalten.

set -euo pipefail

SUBNET=""
WEB="/var/www/html/netmon"
ZONES=""
SPEED_EVERY=""          # Minuten zwischen zwei Speedtests (10, 15, 20, 30 oder 60), sonst Einstellung behalten
SPEED_ON=1
SPEED_OFF_GIVEN=0
DB=netmon
TAILSCALE=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --subnet)          SUBNET="$2"; shift 2 ;;
    --web)             WEB="$2"; shift 2 ;;
    --zones)           ZONES="$2"; shift 2 ;;
    --speedtest-every) SPEED_EVERY="$2"; shift 2 ;;
    --no-speedtest)    SPEED_ON=0; SPEED_OFF_GIVEN=1; shift ;;
    --with-tailscale)  TAILSCALE=1; shift ;;
    -h|--help)         sed -n '2,13p' "$0"; exit 0 ;;
    *) echo "Unbekannte Option: $1"; exit 1 ;;
  esac
done

# Heimnetz ermitteln: Schnittstelle der Standardroute (nie tailscale0), dort das direkt verbundene Netz
detect_subnet() {
  IFACE=$(ip -o -4 route show default | awk '$5 !~ /^tailscale/ {print $5; exit}')
  SUBNET=$([[ -n "$IFACE" ]] && ip -o -4 route show dev "$IFACE" scope link | awk '{print $1; exit}' || true)
}

say()  { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*"; }
die()  { printf '\033[1;31mFehler: %s\033[0m\n' "$*"; exit 1; }

[[ $EUID -eq 0 ]] || die "Bitte mit sudo starten."
cd "$(dirname "$0")"
[[ -f netmon/collector/scan.php && -f sql/schema.sql ]] || die "Bitte im entpackten netmon-Ordner starten."
[[ -z "$ZONES" || -f "$ZONES" ]] || die "Bereichsdatei $ZONES nicht gefunden."

# ---------------------------------------------------------------
say "Bestandsaufnahme"
command -v apt-get >/dev/null || die "Kein apt gefunden. Bitte von Hand installieren: Apache oder nginx mit PHP 8, MariaDB/MySQL, nmap und die PHP-Erweiterungen pdo_mysql, curl, mbstring, xml – dann LIESMICH.md „Von Hand“."
have()  { printf '  \033[32m✓\033[0m %s\n' "$1"; }
lack()  { printf '  \033[33m✗\033[0m %s – wird installiert\n' "$1"; }
PKGS=()
if command -v apache2 >/dev/null; then have "Apache"; else lack "Apache"; PKGS+=(apache2 libapache2-mod-php); fi
if command -v mariadb >/dev/null || command -v mysql >/dev/null; then have "MariaDB/MySQL"; else lack "MariaDB"; PKGS+=(mariadb-server); fi
if command -v php >/dev/null; then have "PHP $(php -r 'echo PHP_VERSION;')"; else lack "PHP"; PKGS+=(php-cli); fi
if command -v nmap >/dev/null; then have "nmap"; else lack "nmap"; PKGS+=(nmap); fi
command -v unzip >/dev/null || PKGS+=(unzip)
for ext in pdo_mysql:php-mysql curl:php-curl mbstring:php-mbstring xml:php-xml; do
  mod=${ext%%:*}; pkg=${ext##*:}
  if command -v php >/dev/null && php -m | grep -qix "$mod"; then have "PHP-Erweiterung $mod"; else lack "PHP-Erweiterung $mod"; PKGS+=("$pkg"); fi
done
if [[ ${#PKGS[@]} -gt 0 ]]; then
  apt-get update -q >/dev/null || warn "apt-get update meldet Fehler – versuche trotzdem zu installieren"
  apt-get install -y -q "${PKGS[@]}" >/dev/null
  # neue PHP-Erweiterungen erst nach dem Neuladen in der Weboberfläche aktiv
  systemctl reload apache2 2>/dev/null || systemctl restart apache2 2>/dev/null || true
  echo "nachinstalliert: ${PKGS[*]}"
else
  echo "alles vorhanden"
fi
php -r 'exit(PHP_VERSION_ID >= 80000 ? 0 : 1);' || die "PHP 8.0 oder neuer wird benötigt (gefunden: $(php -r 'echo PHP_VERSION;'))."
SQL=$(command -v mariadb || command -v mysql)
WEBGROUP=$(getent group www-data >/dev/null && echo www-data || stat -c %G "$(dirname "$WEB")")
echo "PHP $(php -r 'echo PHP_VERSION;'), Webserver-Gruppe: $WEBGROUP"

# ---------------------------------------------------------------
say "Ookla-Speedtest"
# Das Programm wird immer installiert, damit sich der Speedtest später in den Einstellungen einschalten lässt
if [[ -x /usr/local/bin/speedtest ]]; then
  echo "bereits vorhanden"
else
  case "$(uname -m)" in
    x86_64)  ARCH=x86_64 ;;
    aarch64) ARCH=aarch64 ;;
    armv7l)  ARCH=armhf ;;
    *) ARCH="" ;;
  esac
  TMP=$(mktemp -d)
  if [[ -n "$ARCH" ]] && curl -fsSL "https://install.speedtest.net/app/cli/ookla-speedtest-1.2.0-linux-${ARCH}.tgz" -o "$TMP/st.tgz" \
     && tar xzf "$TMP/st.tgz" -C "$TMP" speedtest; then
    install -m 755 "$TMP/speedtest" /usr/local/bin/speedtest
    echo "installiert ($ARCH)"
  else
    warn "Speedtest konnte nicht installiert werden. Bitte von speedtest.net/apps/cli herunterladen und nach /usr/local/bin/speedtest legen."
  fi
  rm -rf "$TMP"
fi
[[ -x /usr/local/bin/speedtest ]] && /usr/local/bin/speedtest --accept-license --accept-gdpr -L >/dev/null 2>&1 || true
SPEED_BOOL=$([[ $SPEED_ON -eq 1 ]] && echo true || echo false)

# ---------------------------------------------------------------
say "Weboberfläche und Skripte nach $WEB"
install -d -m 755 "$WEB"
# config.php nie überschreiben; ältere Installation (Version 2 in /opt/netmon) übernehmen
[[ ! -f "$WEB/config.php" && -f /opt/netmon/config.php ]] && cp /opt/netmon/config.php "$WEB/config.php" && echo "config.php aus /opt/netmon übernommen"
cp -r netmon/. "$WEB/"
chown -R root:"$WEBGROUP" "$WEB"
find "$WEB" -type d -exec chmod 755 {} + ; find "$WEB" -type f -exec chmod 644 {} +

NEW_CONFIG=0
if [[ -f "$WEB/config.php" ]]; then
  echo "config.php vorhanden, bleibt unverändert"
else
  if [[ -z "$SUBNET" ]]; then
    detect_subnet
    [[ -n "$SUBNET" ]] || die "Subnetz nicht erkannt, bitte mit --subnet angeben."
    echo "Subnetz erkannt: $SUBNET (Schnittstelle $IFACE)"
  fi
  DB_PASS=$(head -c 32 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 24)
  sed -e "s|'PASSWORT'|'$DB_PASS'|" -e "s|'192.168.5.0/24'|'$SUBNET'|" -e "s|'speedtest_enabled' => false|'speedtest_enabled' => $SPEED_BOOL|" \
      netmon/config.example.php > "$WEB/config.php"
  NEW_CONFIG=1
fi
chown root:"$WEBGROUP" "$WEB/config.php"
chmod 640 "$WEB/config.php"

# ---------------------------------------------------------------
say "Datenbank"
# Zugang aus config.php (Update) – root wird nur zum Anlegen einer neuen Datenbank gebraucht
cfg() { php -r '$c = require $argv[1]; $d = $c["db"];
  $v = ["dsn_db" => preg_match("/dbname=([^;]+)/", $d["dsn"], $m) ? $m[1] : "", "dsn_host" => preg_match("/host=([^;]+)/", $d["dsn"], $m) ? $m[1] : "localhost",
        "user" => $d["user"], "pass" => $d["pass"]]; echo $v[$argv[2]];' "$WEB/config.php" "$1"; }
CFG_DB=$(cfg dsn_db); CFG_HOST=$(cfg dsn_host); CFG_USER=$(cfg user); CFG_PASS=$(cfg pass)
[[ -n "$CFG_DB" ]] && DB="$CFG_DB"
app_sql() { MYSQL_PWD="$CFG_PASS" "$SQL" -h "$CFG_HOST" -u "$CFG_USER" "$@"; }
ROOT_PW=""
root_sql() { if [[ -n "$ROOT_PW" ]]; then MYSQL_PWD="$ROOT_PW" "$SQL" -u root "$@"; else "$SQL" "$@"; fi; }
root_login() {
  "$SQL" -e "SELECT 1" >/dev/null 2>&1 && return 0
  [[ -t 0 ]] || die "Keine Anmeldung als MariaDB-root möglich. Bitte im Terminal starten (fragt dann nach dem root-Passwort)."
  read -r -s -p "Passwort für MariaDB-Benutzer root: " ROOT_PW; echo
  MYSQL_PWD="$ROOT_PW" "$SQL" -u root -e "SELECT 1" >/dev/null 2>&1 || die "MariaDB-root-Passwort falsch."
}

if [[ $NEW_CONFIG -eq 0 ]] && app_sql "$DB" -e "SELECT 1" >/dev/null 2>&1; then
  echo "Datenbank '$DB' vorhanden (Zugang aus config.php), Daten bleiben erhalten"
  app_sql "$DB" < sql/migrate-v2.sql
  echo "Datenbank auf Version 2 gebracht; alles Weitere erledigt netmon beim ersten Aufruf selbst"
else
  root_login
  if root_sql -e "USE \`$DB\`" 2>/dev/null; then
    [[ $NEW_CONFIG -eq 0 ]] || die "Datenbank '$DB' existiert schon, aber es gab keine config.php. Passwort bitte selbst in $WEB/config.php eintragen."
    warn "Zugang aus config.php klappt nicht – Benutzer/Passwort in $WEB/config.php prüfen"
    root_sql "$DB" < sql/migrate-v2.sql
    echo "Datenbank '$DB' vorhanden, Daten bleiben erhalten"
  else
    [[ $NEW_CONFIG -eq 1 ]] || DB_PASS="$CFG_PASS"
    root_sql <<SQLEOF
CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'netmon'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER 'netmon'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO 'netmon'@'localhost';
FLUSH PRIVILEGES;
SQLEOF
    root_sql "$DB" < sql/schema.sql
    echo "Tabellen angelegt"
    if [[ -n "$ZONES" ]]; then
      root_sql "$DB" < "$ZONES"
      echo "Bereiche aus $ZONES eingespielt"
    fi
  fi
fi

# ---------------------------------------------------------------
say "Zeitplan (/etc/cron.d/netmon)"
# Ein Taktgeber pro Minute; Intervalle stellst du in der Weboberfläche unter Einstellungen ein
cat > /etc/cron.d/netmon <<CRONEOF
# netmon – Taktgeber: startet Scan, Speedtest und Aufräumen laut Einstellungen
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

* * * * *  root  /usr/bin/php $WEB/collector/tick.php >> /var/log/netmon-tick.log 2>&1
CRONEOF
chmod 644 /etc/cron.d/netmon
# Alte Einträge aus dem root-Crontab entfernen (Version 1)
if crontab -l 2>/dev/null | grep -q "/opt/netmon/"; then
  crontab -l | grep -v "/opt/netmon/" | crontab -
  echo "alte netmon-Einträge aus dem root-Crontab entfernt"
fi
# Startwerte aus den Optionen in die Einstellungen übernehmen
setting() { "$SQL" "$DB" -e "INSERT INTO settings (k, v) VALUES ('$1', '$2') ON DUPLICATE KEY UPDATE v = VALUES(v)"; }
[[ $SPEED_OFF_GIVEN -eq 1 ]] && setting speedtest_enabled 0
[[ -n "$SUBNET" ]] && setting subnet "$SUBNET"
if [[ -n "$SPEED_EVERY" ]]; then
  [[ "$SPEED_EVERY" =~ ^(10|15|20|30|60)$ ]] || die "--speedtest-every muss 10, 15, 20, 30 oder 60 sein."
  setting speedtest_interval "$SPEED_EVERY"
fi
echo "Taktgeber jede Minute; Scan- und Speedtest-Intervalle unter Einstellungen"

# ---------------------------------------------------------------
if [[ $TAILSCALE -eq 1 ]]; then
  say "Tailscale (Fernzugriff)"
  if command -v tailscale >/dev/null; then
    echo "bereits installiert ($(tailscale version | head -1))"
  else
    curl -fsSL https://tailscale.com/install.sh | sh || die "Tailscale-Installation fehlgeschlagen"
  fi
  [[ -n "$SUBNET" ]] || detect_subnet
  [[ -n "$SUBNET" ]] || die "Heimnetz nicht erkannt, bitte mit --subnet angeben."
  echo "Heimnetz für die Subnet-Route: $SUBNET"
  case "$SUBNET" in
    192.168.178.0/24|192.168.0.0/24|192.168.1.0/24|192.168.2.0/24|10.0.0.0/24)
      warn "$SUBNET ist ein sehr verbreitetes Netz. Hat ein zweites Tailscale-Netz (z. B. bei Freunden) dasselbe, kollidieren die Routen – dann eines umstellen." ;;
  esac
  printf 'net.ipv4.ip_forward = 1\nnet.ipv6.conf.all.forwarding = 1\n' > /etc/sysctl.d/99-tailscale.conf
  sysctl -q -p /etc/sysctl.d/99-tailscale.conf
  if tailscale status >/dev/null 2>&1; then
    tailscale set --ssh --advertise-routes="$SUBNET"
    echo "angemeldet, Subnet-Route $SUBNET angeboten"
  else
    echo "Gleich erscheint ein Anmelde-Link – im Browser öffnen und mit deinem Tailscale-Konto bestätigen."
    tailscale up --ssh --advertise-routes="$SUBNET"
  fi
  TS_IP=$(tailscale ip -4 2>/dev/null | head -1 || true)
  echo "Tailscale-IP dieses Rechners: ${TS_IP:-?}"
  warn "Noch einmalig nötig: login.tailscale.com/admin/machines → dieser Rechner → „…“ → Edit route settings → $SUBNET anhaken"
fi

# ---------------------------------------------------------------
say "Erster Scan"
php "$WEB/collector/scan.php" || warn "Scan fehlgeschlagen, Details siehe oben"

IP=$(ip -o -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')
[[ -n "$IP" ]] || IP=$(hostname -I | awk '{print $1}')
URL="http://$IP/${WEB#/var/www/html/}/"
say "Fertig"
SRC=$(pwd)
VER=$(cat VERSION 2>/dev/null || echo "?")
if [[ $NEW_CONFIG -eq 1 ]]; then FIRST="   (beim ersten Aufruf legst du dort dein Admin-Konto an)"; else FIRST=""; fi
if [[ -d .git ]]; then UPD="cd $SRC && git pull && sudo bash install.sh"; else UPD="neues Paket entpacken und darin  sudo bash install.sh  starten"; fi
cat <<HINTS
  netmon $VER
  Weboberfläche:  $URL$FIRST
  Konfiguration:  $WEB/config.php   (Datenbank-Zugang; Subnetz und Intervalle stellst du in der Weboberfläche ein)
  Protokoll:      /var/log/netmon-tick.log
  Aktualisieren:  $UPD   (Daten und config.php bleiben erhalten)

  Geräte-Abfrage (Switches, FritzBox, Access Points): Gerät öffnen → Einstellungen → Treiber + Passwort.
  TP-Link-Access-Points brauchen zusätzlich Python, siehe LIESMICH.md „Python für TP-Link“.
HINTS
