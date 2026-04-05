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
define('APP_BUILD', 7);

// Autoload database
require_once __DIR__ . '/db.php';
