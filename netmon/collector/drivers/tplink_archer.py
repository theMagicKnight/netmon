#!/usr/bin/env python3
# netmon – Hilfsprogramm für TP-Link Archer / Deco (WLAN-Clients), nutzt das Paket tplinkrouterc6u
# Aufruf: python tplink_archer.py IP            (Passwort in der Umgebungsvariable NETMON_PASS)
#         python tplink_archer.py IP AKTION     wlan_on|wlan_off|guest_on|guest_off|iot_on|iot_off|reboot
# Ausgabe: JSON {"clients": [...], "info": {...}} bzw. {"ok": …, "done": [...], "errors": [...]}
#          – Fehler als Text auf stderr, Rückgabewert 1
import json
import os
import sys

try:
    from tplinkrouterc6u import TplinkRouterProvider, Connection
except ImportError:
    print("Python-Paket tplinkrouterc6u fehlt", file=sys.stderr)
    sys.exit(2)


# Schalten: alle vorhandenen Bänder des gewählten Netzes (2,4 / 5 / 6 GHz) gemeinsam
NETS = {"wlan": ("wifi", "HOST"), "guest": ("guest", "GUEST"), "iot": ("iot", "IOT")}


def action(router, act):
    if act == "reboot":
        router.reboot()
        return {"ok": True, "done": ["reboot"], "errors": []}
    net, _, onoff = act.partition("_")
    if net not in NETS or onoff not in ("on", "off"):
        raise ValueError(f"unbekannte Aktion {act}")
    prefix, conn = NETS[net]
    status = router.get_status()
    done, errors = [], []
    for band in ("2g", "5g", "6g"):
        if getattr(status, f"{prefix}_{band}_enable", None) is None:
            continue   # Band gibt es an diesem Gerät nicht
        try:
            router.set_wifi(getattr(Connection, f"{conn}_{band.upper()}"), onoff == "on")
            done.append(band)
        except Exception as e:
            errors.append(f"{band}: {type(e).__name__}: {e}")
    return {"ok": bool(done), "done": done, "errors": errors}


def main():
    ip = sys.argv[1]
    router = TplinkRouterProvider.get_client(f"http://{ip}", os.environ.get("NETMON_PASS", ""))
    router.authorize()
    try:
        if len(sys.argv) > 2:
            print(json.dumps(action(router, sys.argv[2])))
            return
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
                "active": getattr(d, "active", True),
                "frequency": getattr(d, "frequency", None),
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
