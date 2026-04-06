<?php
session_start();

define('APP_NAME', 'DDNS Manager');
define('DB_PATH', __DIR__ . '/data/ddns.sqlite');
define('BASE_URL', '');

// Token secret per API
define('TOKEN_SECRET', 'cambia-questa-chiave-segreta-' . md5(__DIR__));

// TTL default per i record DNS (secondi)
define('DEFAULT_TTL', 300);

// Versione applicazione
define('APP_VERSION', '1.0');
define('APP_BUILD', 9);

// Integrazione Plesk DNS API
// Credenziali nel file .env (non versionato)
$_env = is_file(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env') : [];
define('PLESK_HOST',       'https://plesk.gvweb.it:8443');
define('PLESK_USER',       $_env['PLESK_USER']     ?? 'admin');
define('PLESK_PASSWORD',   $_env['PLESK_PASSWORD'] ?? '');
define('PLESK_DOMAIN',     $_env['PLESK_DOMAIN']   ?? ''); // zona gestita da Plesk (es. gvweb.it)
define('PLESK_VERIFY_SSL', false);
unset($_env);

// Autoload database
require_once __DIR__ . '/db.php';
