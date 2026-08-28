# DDNS Manager

## Stack
- PHP 8.x, SQLite (WAL), no framework
- DynDNS2 protocol (`/nic/update`)
- Deploy: GitHub → Plesk webhook (auto)
- Live: `https://ddns.gvweb.it`

## File principali
- `config.php` — costanti app + lettura `.env`
- `db.php` — PDO/SQLite, tabelle, helper auth
- `index.php` — login + brute force protection
- `dashboard.php` — pannello utente
- `admin.php` — pannello admin (domini, utenti, log)
- `update.php` — endpoint DynDNS2
- `plesk.php` — integrazione DNS Plesk via XML API
- `style.css` — dark theme (`#0f172a` bg, `#1e293b` card)

## DB — tabelle
- `users` — id, username, password, is_admin, api_token
- `domains` — id, zone
- `hosts` — id, user_id, hostname, domain_id, ip_address
- `update_log` — host_id, old_ip, new_ip, source_ip, source_type
- `login_log` — username, ip, success, logged_at

## Plesk DNS
- XML API: `POST /enterprise/control/agent.php`
- Auth: Basic (`PLESK_USER`/`PLESK_PASSWORD`)
- `PLESK_DOMAIN=ddns.gvweb.it` → site-id=9
- Host relativo (es. `casa`), Plesk aggiunge il suffisso zona

## .env (non in git)
```
PLESK_USER=admin
PLESK_PASSWORD=...
PLESK_DOMAIN=ddns.gvweb.it
```

## Convenzioni
- `APP_BUILD` in `config.php` va incrementato ad ogni commit
- Footer mostra versione e build su tutte le pagine
- Niente librerie esterne, SVG inline per icone
- `plesk_test.php` è file temporaneo di debug (non rimuovere finché DNS non è stabile)
