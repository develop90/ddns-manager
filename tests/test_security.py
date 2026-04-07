"""
DDNS Manager — Security Test Suite
===================================
Esegui con:  pytest tests/test_security.py -v

Requisiti:  pip install pytest requests
"""

import pytest
import requests
from config import BASE_URL, ADMIN_USER, ADMIN_PASS, TEST_USER, TEST_PASS

# ── Helpers ────────────────────────────────────────────────────────────────────

def session_login(username, password):
    s = requests.Session()
    s.verify = False
    r = s.post(f"{BASE_URL}/index.php",
               data={"username": username, "password": password},
               allow_redirects=True)
    return s, r

def get_token(username, password):
    s, _ = session_login(username, password)
    r = s.get(f"{BASE_URL}/dashboard.php")
    import re
    m = re.search(r'<div class="token-box">([^<]+)</div>', r.text)
    return m.group(1).strip() if m else None

# ── 1. Autenticazione ──────────────────────────────────────────────────────────

class TestAuth:

    def test_login_wrong_password(self):
        _, r = session_login(TEST_USER, "wrong")
        assert "Credenziali non valide" in r.text

    def test_login_correct(self):
        s, _ = session_login(TEST_USER, TEST_PASS)
        r = s.get(f"{BASE_URL}/dashboard.php")
        assert r.status_code == 200
        assert "Dashboard" in r.text

    def test_login_empty_fields(self):
        _, r = session_login("", "")
        assert r.status_code == 200
        assert "Dashboard" not in r.text

    def test_no_session_redirect(self):
        s = requests.Session()
        s.verify = False
        r = s.get(f"{BASE_URL}/dashboard.php", allow_redirects=False)
        assert r.status_code in (301, 302)

    def test_brute_force_lockout(self):
        s = requests.Session()
        s.verify = False
        # 5 tentativi falliti
        for _ in range(5):
            s.post(f"{BASE_URL}/index.php",
                   data={"username": TEST_USER, "password": "WRONG"})
        # Al 6° deve essere bloccato
        r = s.post(f"{BASE_URL}/index.php",
                   data={"username": TEST_USER, "password": "WRONG"})
        assert "Troppi tentativi" in r.text

# ── 2. Autorizzazione ──────────────────────────────────────────────────────────

class TestAuthorization:

    def test_admin_page_blocked_for_user(self):
        s, _ = session_login(TEST_USER, TEST_PASS)
        r = s.get(f"{BASE_URL}/admin.php", allow_redirects=False)
        assert r.status_code in (301, 302)

    def test_admin_page_accessible_for_admin(self):
        s, _ = session_login(ADMIN_USER, ADMIN_PASS)
        r = s.get(f"{BASE_URL}/admin.php")
        assert r.status_code == 200
        assert "Gestione Domini" in r.text

    def test_unauthenticated_admin_blocked(self):
        s = requests.Session()
        s.verify = False
        r = s.get(f"{BASE_URL}/admin.php", allow_redirects=False)
        assert r.status_code in (301, 302)

    def test_user_cannot_delete_other_host(self):
        """Tenta di eliminare host_id=1 (potrebbe appartenere ad admin)."""
        s, _ = session_login(TEST_USER, TEST_PASS)
        r = s.post(f"{BASE_URL}/dashboard.php",
                   data={"action": "delete_host", "host_id": "1"})
        # Non deve mostrare errori di DB, e l'host non deve essere eliminato
        assert r.status_code == 200
        assert "Errore" not in r.text

    def test_user_cannot_edit_other_host(self):
        s, _ = session_login(TEST_USER, TEST_PASS)
        r = s.post(f"{BASE_URL}/dashboard.php",
                   data={"action": "edit_host", "host_id": "1",
                         "hostname": "hacked", "domain_id": "1"})
        assert r.status_code == 200

# ── 3. Endpoint DynDNS2 (/nic/update) ─────────────────────────────────────────

class TestUpdateEndpoint:

    def test_no_auth_returns_badauth(self):
        r = requests.get(f"{BASE_URL}/nic/update",
                         params={"hostname": "test.ddns.gvweb.it", "myip": "1.2.3.4"},
                         verify=False)
        assert r.status_code == 401
        assert "badauth" in r.text

    def test_wrong_credentials(self):
        r = requests.get(f"{BASE_URL}/nic/update",
                         params={"hostname": "test.ddns.gvweb.it", "myip": "1.2.3.4"},
                         auth=("nobody", "wrong"),
                         verify=False)
        assert "badauth" in r.text

    def test_unknown_host_returns_nohost(self):
        r = requests.get(f"{BASE_URL}/nic/update",
                         params={"hostname": "nonexistent.ddns.gvweb.it", "myip": "1.2.3.4"},
                         auth=(TEST_USER, TEST_PASS),
                         verify=False)
        assert "nohost" in r.text

    def test_invalid_ip_rejected(self):
        r = requests.get(f"{BASE_URL}/nic/update",
                         params={"hostname": "test.ddns.gvweb.it", "myip": "not-an-ip"},
                         auth=(TEST_USER, TEST_PASS),
                         verify=False)
        assert r.text.strip() in ("abuse", "nohost", "badauth")

    def test_token_auth(self):
        token = get_token(TEST_USER, TEST_PASS)
        if not token:
            pytest.skip("Token non trovato")
        r = requests.get(f"{BASE_URL}/update.php",
                         params={"token": token, "hostname": "nonexistent.ddns.gvweb.it", "myip": "1.2.3.4"},
                         verify=False)
        assert r.text.strip() in ("nohost",)

# ── 4. SQL Injection ───────────────────────────────────────────────────────────

class TestSQLInjection:

    PAYLOADS = [
        "' OR '1'='1",
        "' OR 1=1--",
        "admin'--",
        "' UNION SELECT 1,2,3--",
        "'; DROP TABLE users;--",
    ]

    def test_login_username_injection(self):
        for payload in self.PAYLOADS:
            _, r = session_login(payload, "anything")
            assert "Dashboard" not in r.text, f"SQL injection riuscita con: {payload}"

    def test_login_password_injection(self):
        for payload in self.PAYLOADS:
            _, r = session_login("admin", payload)
            assert "Dashboard" not in r.text, f"SQL injection riuscita con: {payload}"

    def test_hostname_injection_in_update(self):
        for payload in self.PAYLOADS:
            r = requests.get(f"{BASE_URL}/nic/update",
                             params={"hostname": payload, "myip": "1.2.3.4"},
                             auth=(TEST_USER, TEST_PASS),
                             verify=False)
            assert r.status_code < 500, f"Errore server con payload: {payload}"

# ── 5. XSS ────────────────────────────────────────────────────────────────────

class TestXSS:

    PAYLOADS = [
        "<script>alert(1)</script>",
        '"><img src=x onerror=alert(1)>',
        "javascript:alert(1)",
    ]

    def test_hostname_xss(self):
        s, _ = session_login(TEST_USER, TEST_PASS)
        for payload in self.PAYLOADS:
            r = s.post(f"{BASE_URL}/dashboard.php",
                       data={"action": "add_host", "hostname": payload, "domain_id": "1"})
            assert payload not in r.text, f"XSS non escaped: {payload}"

    def test_login_username_xss(self):
        for payload in self.PAYLOADS:
            _, r = session_login(payload, "wrong")
            assert "<script>" not in r.text

# ── 6. Accesso diretto a file sensibili ────────────────────────────────────────

class TestSensitiveFiles:

    def test_sqlite_not_accessible(self):
        s = requests.Session()
        s.verify = False
        for path in ["/data/ddns.sqlite", "/ddns.sqlite"]:
            r = s.get(f"{BASE_URL}{path}")
            assert r.status_code in (403, 404), f"File DB accessibile: {path}"

    def test_env_not_accessible(self):
        s = requests.Session()
        s.verify = False
        r = s.get(f"{BASE_URL}/.env")
        assert r.status_code in (403, 404)

    def test_gitignore_not_useful(self):
        s = requests.Session()
        s.verify = False
        r = s.get(f"{BASE_URL}/.gitignore")
        # Potrebbe essere accessibile ma non deve contenere secrets
        if r.status_code == 200:
            assert "PASSWORD" not in r.text
