<?php
session_start();

define('APP_NAME', 'DDNS Manager');
define('DB_PATH', __DIR__ . '/data/ddns.sqlite');
define('BASE_URL', '');

// TTL default per i record DNS (secondi)
define('DEFAULT_TTL', 300);

// Versione applicazione
define('APP_VERSION', '1.0');
define('APP_BUILD', 18);

// Tutti i segreti vengono dal file .env (non versionato)
$_env = is_file(__DIR__ . '/.env') ? parse_ini_file(__DIR__ . '/.env') : [];
define('TOKEN_SECRET',     $_env['TOKEN_SECRET']   ?? '');  // usato per HMAC token API
define('PLESK_HOST',       'https://plesk.gvweb.it:8443');
define('PLESK_USER',       $_env['PLESK_USER']     ?? 'admin');
define('PLESK_PASSWORD',   $_env['PLESK_PASSWORD'] ?? '');
define('PLESK_DOMAIN',     $_env['PLESK_DOMAIN']   ?? '');
define('PLESK_VERIFY_SSL', false);
define('UNBLOCK_SECRET',   $_env['UNBLOCK_SECRET'] ?? '');
unset($_env);

// Autoload database
require_once __DIR__ . '/db.php';
