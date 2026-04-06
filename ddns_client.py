#!/usr/bin/env python3
"""
DDNS Client — aggiorna automaticamente i record DNS ogni tot secondi.
Usa il protocollo DynDNS2 (compatibile con router e No-IP).

Utilizzo:
    python ddns_client.py                        # usa la configurazione qui sotto
    python ddns_client.py --interval 120         # aggiorna ogni 120 secondi
    python ddns_client.py --once                 # aggiorna una volta sola ed esci

Requisiti:
    pip install requests
"""

import argparse
import logging
import time
import requests

# ─────────────────────────────────────────────
#  CONFIGURAZIONE — modifica questi valori
# ─────────────────────────────────────────────

SERVER   = "https://ddns.gvweb.it"       # URL del tuo server DDNS
USERNAME = "il-tuo-username"             # username di accesso
PASSWORD = "la-tua-password"            # password di accesso
HOSTS    = [                             # lista degli hostname da aggiornare
    "casa.ddns.gvweb.it",
    # "ufficio.ddns.gvweb.it",           # aggiungi altri host se necessario
]
INTERVAL = 300                           # secondi tra un aggiornamento e l'altro (default: 5 min)

# ─────────────────────────────────────────────

IP_SERVICES = [
    "https://api.ipify.org",
    "https://api4.my-ip.io/ip",
    "https://ipv4.icanhazip.com",
]

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
log = logging.getLogger("ddns")


def get_public_ip() -> str | None:
    for url in IP_SERVICES:
        try:
            r = requests.get(url, timeout=5)
            if r.ok:
                return r.text.strip()
        except requests.RequestException:
            continue
    log.error("Impossibile rilevare l'IP pubblico.")
    return None


def update_host(hostname: str, ip: str) -> bool:
    url = f"{SERVER.rstrip('/')}/nic/update"
    try:
        r = requests.get(
            url,
            params={"hostname": hostname, "myip": ip},
            auth=(USERNAME, PASSWORD),
            timeout=10,
            verify=True,
        )
        response = r.text.strip()
        if response.startswith("good"):
            log.info(f"[{hostname}] Aggiornato → {ip}")
            return True
        elif response.startswith("nochg"):
            log.info(f"[{hostname}] IP invariato ({ip})")
            return True
        elif response == "badauth":
            log.error(f"[{hostname}] Credenziali errate (badauth)")
        elif response == "nohost":
            log.error(f"[{hostname}] Host non trovato sul server (nohost)")
        else:
            log.warning(f"[{hostname}] Risposta inattesa: {response}")
    except requests.RequestException as e:
        log.error(f"[{hostname}] Errore di rete: {e}")
    return False


def run(interval: int, once: bool):
    log.info(f"DDNS Client avviato — server: {SERVER}")
    log.info(f"Host: {', '.join(HOSTS)}")
    log.info(f"Intervallo: {interval}s" if not once else "Modalità: esecuzione singola")

    while True:
        ip = get_public_ip()
        if ip:
            log.info(f"IP pubblico rilevato: {ip}")
            for host in HOSTS:
                update_host(host, ip)
        else:
            log.warning("Salto aggiornamento: IP non disponibile.")

        if once:
            break

        log.info(f"Prossimo aggiornamento tra {interval} secondi...")
        time.sleep(interval)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="DDNS auto-update client")
    parser.add_argument("--interval", type=int, default=INTERVAL,
                        help=f"Secondi tra un aggiornamento e l'altro (default: {INTERVAL})")
    parser.add_argument("--once", action="store_true",
                        help="Aggiorna una volta sola ed esci")
    args = parser.parse_args()

    try:
        run(args.interval, args.once)
    except KeyboardInterrupt:
        log.info("Client fermato.")
