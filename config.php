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
define('PLESK_HOST',       'https://plesk.gvweb.it:8443');
define('PLESK_API_KEY',    ''); // Genera in Plesk > Strumenti e impostazioni > Chiavi API
define('PLESK_VERIFY_SSL', false); // true se il certificato Plesk è valido

// Autoload database
require_once __DIR__ . '/db.php';
