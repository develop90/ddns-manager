<?php
/**
 * Endpoint segreto per sbloccare un IP dalla protezione brute force.
 * Usato da GitHub Actions prima dei test di sicurezza.
 * Non richiede sessione — protetto da UNBLOCK_SECRET nel .env
 *
 * GET /unblock.php?secret=<UNBLOCK_SECRET>&ip=<IP>
 */
require_once __DIR__ . '/config.php';

if (UNBLOCK_SECRET === '' || UNBLOCK_SECRET === 'changeme') {
    http_response_code(503);
    exit('not configured');
}

$secret = $_GET['secret'] ?? '';
$ip     = $_GET['ip']     ?? '';

if (!hash_equals(UNBLOCK_SECRET, $secret)) {
    http_response_code(403);
    exit('forbidden');
}

if (!filter_var($ip, FILTER_VALIDATE_IP)) {
    http_response_code(400);
    exit('invalid ip');
}

$db = getDb();
$db->prepare("DELETE FROM login_log WHERE ip = ? AND success = 0")->execute([$ip]);

echo 'ok';
