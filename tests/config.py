# Configurazione test — i valori reali vengono sostituiti in CI via GitHub Secrets
# Per esecuzione locale: esporta le variabili d'ambiente oppure modifica qui
import os

BASE_URL  = os.getenv("DDNS_URL",        "https://ddns.gvweb.it")

ADMIN_USER = os.getenv("DDNS_ADMIN_USER", "admin")
ADMIN_PASS = os.getenv("DDNS_ADMIN_PASS", "la-tua-password-admin")

TEST_USER  = os.getenv("DDNS_TEST_USER",  "test")
TEST_PASS  = os.getenv("DDNS_TEST_PASS",  "la-tua-password-test")
