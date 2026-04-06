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

// Test 1: connessione REST
echo "--- Test 1: connessione REST ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/server');
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
if ($err) { echo "cURL error: $err\n"; exit; }
echo "HTTP $code — Plesk " . (json_decode($resp, true)['panel_version'] ?? '?') . " OK\n";

// Test 2: XML API — lista record DNS di PLESK_DOMAIN
echo "\n--- Test 2: record DNS via XML API per " . PLESK_DOMAIN . " ---\n";
$xmlReq = '<?xml version="1.0" encoding="UTF-8"?>
<packet><dns><get_rec><filter><site>' . htmlspecialchars(PLESK_DOMAIN) . '</site></filter></get_rec></dns></packet>';

$ch = curl_init(rtrim(PLESK_HOST, '/') . '/enterprise/control/agent.php');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => $xmlReq,
    CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
echo "Risposta XML:\n$resp\n";

// Test 3: XML API — crea record A di test
echo "\n--- Test 3: crea record A test-ddns." . PLESK_DOMAIN . " → 1.2.3.4 via XML API ---\n";
$xmlAdd = '<?xml version="1.0" encoding="UTF-8"?>
<packet><dns><add_rec><site>' . htmlspecialchars(PLESK_DOMAIN) . '</site><type>A</type><host>test-ddns.' . htmlspecialchars(PLESK_DOMAIN) . '.</host><value>1.2.3.4</value><ttl>300</ttl></add_rec></dns></packet>';

$ch = curl_init(rtrim(PLESK_HOST, '/') . '/enterprise/control/agent.php');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => $xmlAdd,
    CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
echo "Risposta XML:\n$resp\n";
