<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/plesk.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Test crea locale.ddns.gvweb.it → 1.2.3.4 ===\n\n";

$ok = pleskDnsUpdate('locale', 'ddns.gvweb.it', '1.2.3.4');

echo $ok ? "OK - record creato!\nVerifica in Plesk > ddns.gvweb.it > DNS\n" : "ERRORE - record non creato.\n";
