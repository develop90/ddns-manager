#!/usr/bin/env python3
"""
DDNS Client — aggiorna automaticamente i record DNS ogni tot secondi.
Usa il protocollo DynDNS2 (compatibile con router e No-IP).

Configurazione tramite variabili d'ambiente o file .env:
    DDNS_USERNAME=mio-utente
    DDNS_PASSWORD=mia-password
    DDNS_HOSTS=casa.ddns.gvweb.it,ufficio.ddns.gvweb.it
    DDNS_SERVER=https://ddns.gvweb.it   (opzionale)
    DDNS_INTERVAL=300                   (opzionale, secondi)

Utilizzo:
    python ddns_client.py               # aggiorna in loop
    python ddns_client.py --interval 120
    python ddns_client.py --once        # aggiorna una volta sola ed esci

Requisiti:
    pip install requests
"""

import argparse
import logging
import os
import time
import requests

# ─────────────────────────────────────────────
#  CONFIGURAZIONE — tramite variabili d'ambiente
#  oppure file .env nella stessa cartella
# ─────────────────────────────────────────────

def _load_env():
    env_file = os.path.join(os.path.dirname(__file__), ".env")
    if os.path.isfile(env_file):
        with open(env_file) as f:
            for line in f:
                line = line.strip()
                if line and not line.startswith("#") and "=" in line:
                    k, _, v = line.partition("=")
                    os.environ.setdefault(k.strip(), v.strip())

_load_env()

SERVER   = os.getenv("DDNS_SERVER",   "https://ddns.gvweb.it")
USERNAME = os.getenv("DDNS_USERNAME", "")
PASSWORD = os.getenv("DDNS_PASSWORD", "")
HOSTS    = [h.strip() for h in os.getenv("DDNS_HOSTS", "").split(",") if h.strip()]
INTERVAL = int(os.getenv("DDNS_INTERVAL", "300"))

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

    if not USERNAME or not PASSWORD:
        log.error("Credenziali mancanti. Imposta DDNS_USERNAME e DDNS_PASSWORD nel file .env")
        raise SystemExit(1)
    if not HOSTS:
        log.error("Nessun host configurato. Imposta DDNS_HOSTS nel file .env (es: casa.ddns.gvweb.it)")
        raise SystemExit(1)

    try:
        run(args.interval, args.once)
    except KeyboardInterrupt:
        log.info("Client fermato.")
