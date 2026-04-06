<?php
require_once __DIR__ . '/config.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Plesk DNS Test ===\n\n";
echo "PLESK_HOST:     " . PLESK_HOST . "\n";
echo "PLESK_USER:     " . PLESK_USER . "\n";
echo "PLESK_PASSWORD: " . (PLESK_PASSWORD !== '' ? str_repeat('*', strlen(PLESK_PASSWORD)) : '(vuota!)') . "\n";
echo "PLESK_DOMAIN:   " . (defined('PLESK_DOMAIN') && PLESK_DOMAIN !== '' ? PLESK_DOMAIN : '(non impostato!)') . "\n\n";

if (PLESK_PASSWORD === '') { echo "ERRORE: PLESK_PASSWORD vuota\n"; exit; }
if (!defined('PLESK_DOMAIN') || PLESK_DOMAIN === '') { echo "ERRORE: PLESK_DOMAIN non impostato\n"; exit; }

$sslOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_SSL_VERIFYHOST => PLESK_VERIFY_SSL ? 2 : 0,
    CURLOPT_TIMEOUT        => 10,
];

// Test 1: connessione + ricava siteId
echo "--- Test 1: connessione REST + siteId ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/domains');
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
$domains = json_decode($resp, true) ?? [];
$siteId = null;
foreach ($domains as $d) {
    if (($d['name'] ?? '') === PLESK_DOMAIN) { $siteId = $d['id']; break; }
}
echo "siteId di " . PLESK_DOMAIN . ": " . ($siteId ?? 'NON TROVATO') . "\n";
if (!$siteId) { echo "ERRORE: dominio non trovato\n"; exit; }

// Test 2: REST con siteId
echo "\n--- Test 2: record DNS via REST (siteId=$siteId) ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records?' . http_build_query(['siteId' => $siteId]));
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
$records = json_decode($resp, true);
if ($code === 200 && is_array($records)) {
    echo "Record trovati: " . count($records) . "\n";
    foreach ($records as $r) echo "  [{$r['type']}] {$r['host']} → {$r['value']}\n";
} else {
    echo "Risposta: $resp\n";
}

// Test 3: REST crea record A di test con siteId
echo "\n--- Test 3: crea A test-ddns." . PLESK_DOMAIN . " → 1.2.3.4 via REST (siteId) ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => json_encode(['siteId' => $siteId, 'type' => 'A', 'host' => 'test-ddns.' . PLESK_DOMAIN . '.', 'value' => '1.2.3.4', 'ttl' => 300]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\nRisposta: $resp\n";
$result = json_decode($resp, true);
if ($code >= 200 && $code < 300 && isset($result['id'])) {
    echo "OK - record creato con ID " . $result['id'] . "!\n";
    // elimina
    $ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records/' . $result['id']);
    curl_setopt_array($ch, $sslOpts + [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    curl_exec($ch); curl_close($ch);
    echo "Record di test eliminato.\n";
    echo "\n=== DNS FUNZIONA! ===\n";
}

// Test 4: XML API con dns-zone-name
echo "\n--- Test 4: record DNS via XML API (dns-zone-name) ---\n";
$xmlReq = '<?xml version="1.0" encoding="UTF-8"?><packet><dns><get_rec><filter><dns-zone-name>' . htmlspecialchars(PLESK_DOMAIN . '.') . '</dns-zone-name></filter></get_rec></dns></packet>';
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/enterprise/control/agent.php');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => $xmlReq,
    CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\nRisposta XML:\n$resp\n";
