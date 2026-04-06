<?php
require_once __DIR__ . '/config.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

if (PLESK_PASSWORD === '') { echo "ERRORE: PLESK_PASSWORD vuota nel .env\n"; exit; }

$sslOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_SSL_VERIFYHOST => PLESK_VERIFY_SSL ? 2 : 0,
    CURLOPT_TIMEOUT        => 10,
];
$base = rtrim(PLESK_HOST, '/');

echo "=== Test REST siteId=1 (gvweb.it) ===\n\n";

// GET record
echo "--- GET /api/v2/dns/records?siteId=1 ---\n";
$ch = curl_init($base . '/api/v2/dns/records?siteId=1');
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
if ($code === 200) {
    $recs = json_decode($resp, true) ?? [];
    echo "Record: " . count($recs) . "\n";
    foreach (array_slice($recs, 0, 5) as $r) echo "  [{$r['type']}] {$r['host']} → {$r['value']}\n";
    if (count($recs) > 5) echo "  ...\n";
} else {
    echo "Risposta: $resp\n";
}

// POST record di test
echo "\n--- POST record A test-ddns.ddns.gvweb.it → 1.2.3.4 (siteId=1) ---\n";
$ch = curl_init($base . '/api/v2/dns/records');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => json_encode(['siteId' => 1, 'type' => 'A', 'host' => 'test-ddns.ddns.gvweb.it.', 'value' => '1.2.3.4', 'ttl' => 300]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\nRisposta: $resp\n";
$result = json_decode($resp, true);
if (($result['id'] ?? null)) {
    echo "\n=== FUNZIONA con siteId=1 (gvweb.it) ===\n";
    $ch = curl_init($base . '/api/v2/dns/records/' . $result['id']);
    curl_setopt_array($ch, $sslOpts + [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    curl_exec($ch); curl_close($ch);
    echo "Record di test eliminato.\n";
}

echo "\n\n=== Test XML API (gvweb.it.) ===\n\n";

// XML GET
echo "--- XML get_rec gvweb.it. ---\n";
$xml = '<?xml version="1.0"?><packet><dns><get_rec><filter><dns-zone-name>gvweb.it.</dns-zone-name></filter></get_rec></dns></packet>';
$ch = curl_init($base . '/enterprise/control/agent.php');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml,
    CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n" . substr($resp, 0, 500) . "\n";

// XML ADD
echo "\n--- XML add_rec test-ddns.ddns.gvweb.it → 1.2.3.4 ---\n";
$xml = '<?xml version="1.0"?><packet><dns><add_rec><site-id>1</site-id><type>A</type><host>test-ddns.ddns.gvweb.it.</host><value>1.2.3.4</value><ttl>300</ttl></add_rec></dns></packet>';
$ch = curl_init($base . '/enterprise/control/agent.php');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml,
    CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n$resp\n";
