#!/usr/bin/env python3
# netmon – Hilfsprogramm für TP-Link Archer / Deco (WLAN-Clients), nutzt das Paket tplinkrouterc6u
# Aufruf: python tplink_archer.py IP   (Passwort in der Umgebungsvariable NETMON_PASS)
# Ausgabe: JSON {"clients": [...], "info": {...}} – Fehler als Text auf stderr, Rückgabewert 1
import json
import os
import sys

try:
    from tplinkrouterc6u import TplinkRouterProvider
except ImportError:
    print("Python-Paket tplinkrouterc6u fehlt", file=sys.stderr)
    sys.exit(2)


def main():
    ip = sys.argv[1]
    router = TplinkRouterProvider.get_client(f"http://{ip}", os.environ.get("NETMON_PASS", ""))
    router.authorize()
    try:
        status = router.get_status()
        clients = []
        for d in status.devices:
            t = getattr(d, "type", None)
            clients.append({
                "mac": getattr(d, "macaddr", None),
                "ip": getattr(d, "ipaddr", None),
                "type": getattr(t, "value", None) if t is not None else None,
                "signal": getattr(d, "signal", None),
                "tx": getattr(d, "tx_rate", None),
                "rx": getattr(d, "rx_rate", None),
                "online": getattr(d, "online_time", None),
                "host": getattr(d, "hostname", None),
            })
        info = {"status": {k: getattr(status, k, None) for k in (
            "cpu_usage", "mem_usage", "wifi_2g_enable", "wifi_5g_enable", "wifi_6g_enable",
            "guest_2g_enable", "guest_5g_enable", "guest_6g_enable", "iot_2g_enable", "iot_5g_enable",
            "wifi_clients_total", "iot_clients_total", "guest_clients_total", "wired_total", "clients_total")}}
        try:
            fw = router.get_firmware()
            info.update({
                "model": getattr(fw, "model", None),
                "firmware": getattr(fw, "firmware_version", None),
                "hardware": getattr(fw, "hardware_version", None),
            })
        except Exception:
            pass
        print(json.dumps({"clients": clients, "info": info}))
    finally:
        try:
            router.logout()
        except Exception:
            pass


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        print(f"{type(e).__name__}: {e}", file=sys.stderr)
        sys.exit(1)
