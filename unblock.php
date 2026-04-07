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

$db     = getDb();
$action = $_GET['action'] ?? 'unblock';

if ($action === 'whitelist_add') {
    $current = array_filter(array_map('trim', explode(',',
        $db->query("SELECT value FROM settings WHERE key='bf_whitelist'")->fetchColumn() ?: '')));
    if (!in_array($ip, $current)) {
        $current[] = $ip;
    }
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('bf_whitelist', ?)")
       ->execute([implode(',', $current)]);
} elseif ($action === 'whitelist_remove') {
    $current = array_filter(array_map('trim', explode(',',
        $db->query("SELECT value FROM settings WHERE key='bf_whitelist'")->fetchColumn() ?: '')));
    $current = array_filter($current, fn($x) => $x !== $ip);
    $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('bf_whitelist', ?)")
       ->execute([implode(',', $current)]);
} else {
    // default: sblocca (elimina i log falliti)
    $db->prepare("DELETE FROM login_log WHERE ip = ? AND success = 0")->execute([$ip]);
}

echo 'ok';
