<?php
require_once __DIR__ . '/config.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Plesk DNS Test ===\n\n";
$envPath = __DIR__ . '/.env';
echo ".env cercato in: $envPath\n";
echo ".env esiste:     " . (file_exists($envPath) ? 'SI' : 'NO - file non trovato!') . "\n";
echo "\n";
echo "PLESK_HOST:     " . PLESK_HOST . "\n";
echo "PLESK_USER:     " . PLESK_USER . "\n";
echo "PLESK_PASSWORD: " . (PLESK_PASSWORD !== '' ? str_repeat('*', strlen(PLESK_PASSWORD)) : '(vuota!)') . "\n";
echo "PLESK_DOMAIN:   " . (defined('PLESK_DOMAIN') && PLESK_DOMAIN !== '' ? PLESK_DOMAIN : '(non impostato!)') . "\n";
echo "\n";

if (PLESK_PASSWORD === '') { echo "ERRORE: PLESK_PASSWORD non impostata nel file .env\n"; exit; }
if (!defined('PLESK_DOMAIN') || PLESK_DOMAIN === '') { echo "ERRORE: PLESK_DOMAIN non impostato nel file .env\n"; exit; }

$sslOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_SSL_VERIFYHOST => PLESK_VERIFY_SSL ? 2 : 0,
    CURLOPT_TIMEOUT        => 10,
];

// Test 1: connessione
echo "--- Test 1: connessione a Plesk ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/server');
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
echo "HTTP: $code\n";
if ($err) { echo "cURL error: $err\n"; exit; }
if ($code !== 200) { echo "Risposta: $resp\n"; exit; }
echo "Plesk version: " . (json_decode($resp, true)['panel_version'] ?? '?') . "\n";
echo "OK\n";

// Test 1b: lista domini
echo "\n--- Test 1b: domini registrati in Plesk ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/domains');
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
$domains = json_decode($resp, true) ?? [];
$pleskDomainId = null;
foreach ($domains as $d) {
    echo "  - " . ($d['name'] ?? '?') . " (id=" . ($d['id'] ?? '?') . ")\n";
    if (($d['name'] ?? '') === PLESK_DOMAIN) $pleskDomainId = $d['id'];
}
echo "\nID di " . PLESK_DOMAIN . ": " . ($pleskDomainId ?? 'NON TROVATO!') . "\n";
if (!$pleskDomainId) { echo "ERRORE: controlla PLESK_DOMAIN nel .env\n"; exit; }

// Test 2: record DNS via domainId
echo "\n--- Test 2: record DNS di " . PLESK_DOMAIN . " (id=$pleskDomainId) ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records?' . http_build_query(['domainId' => $pleskDomainId]));
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
$records = json_decode($resp, true);
if ($code === 200 && is_array($records)) {
    echo "Record trovati: " . count($records) . "\n";
    foreach ($records as $r) echo "  [{$r['type']}] {$r['host']} → {$r['value']}\n";
} else {
    echo "Risposta: $resp\n"; exit;
}

// Test 3: crea record A di test via domainId
echo "\n--- Test 3: crea record A test-ddns." . PLESK_DOMAIN . " → 1.2.3.4 ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => json_encode(['domainId' => $pleskDomainId, 'type' => 'A', 'host' => 'test-ddns.' . PLESK_DOMAIN . '.', 'value' => '1.2.3.4', 'ttl' => 300]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
$result = json_decode($resp, true);
if ($code >= 200 && $code < 300) {
    echo "Creato con ID: " . ($result['id'] ?? '?') . "\n";
    echo "OK - il DNS funziona!\n";
    if ($id = $result['id'] ?? null) {
        $ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records/' . $id);
        curl_setopt_array($ch, $sslOpts + [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        curl_exec($ch); curl_close($ch);
        echo "Record di test eliminato.\n";
    }
} else {
    echo "Risposta: $resp\n";
}
