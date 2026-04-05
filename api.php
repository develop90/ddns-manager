<?php
/**
 * API JSON per query DNS
 *
 * GET /ddns/api.php?hostname=miopc.ddns.esempio.it
 *
 * Restituisce l'IP associato all'hostname in formato JSON.
 * Utile per script, monitoring, o integrazione con altri sistemi.
 */

require_once __DIR__ . '/db.php';
define('DB_PATH', __DIR__ . '/data/ddns.sqlite');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$hostname = trim($_GET['hostname'] ?? '');

if ($hostname === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Parametro hostname mancante']);
    exit;
}

$db = getDb();
$domains = $db->query("SELECT * FROM domains ORDER BY LENGTH(zone) DESC")->fetchAll();

$host = null;
$zone = '';

foreach ($domains as $domain) {
    $suffix = '.' . $domain['zone'];
    if (str_ends_with($hostname, $suffix)) {
        $hostPart = substr($hostname, 0, -strlen($suffix));
        if ($hostPart !== '') {
            $stmt = $db->prepare("SELECT h.*, d.zone FROM hosts h JOIN domains d ON h.domain_id = d.id WHERE h.hostname = ? AND h.domain_id = ?");
            $stmt->execute([$hostPart, $domain['id']]);
            $host = $stmt->fetch();
            if ($host) {
                $zone = $domain['zone'];
                break;
            }
        }
    }
}

if (!$host) {
    http_response_code(404);
    echo json_encode(['error' => 'Host non trovato']);
    exit;
}

echo json_encode([
    'hostname' => $host['hostname'] . '.' . $zone,
    'ip' => $host['ip_address'] ?: null,
    'last_update' => $host['last_update'],
    'ttl' => 300,
]);
