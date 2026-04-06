<?php
require_once __DIR__ . '/config.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Plesk DNS Test ===\n\n";
$envPath = __DIR__ . '/.env';
echo ".env cercato in: $envPath\n";
echo ".env esiste:     " . (file_exists($envPath) ? 'SI' : 'NO - file non trovato!') . "\n";
if (file_exists($envPath)) {
    echo ".env contenuto:  " . file_get_contents($envPath) . "\n";
}
echo "\n";
echo "PLESK_HOST:     " . PLESK_HOST . "\n";
echo "PLESK_USER:     " . PLESK_USER . "\n";
echo "PLESK_PASSWORD: " . (PLESK_PASSWORD !== '' ? str_repeat('*', strlen(PLESK_PASSWORD)) : '(vuota!)') . "\n";
echo "PLESK_DOMAIN:   " . (defined('PLESK_DOMAIN') && PLESK_DOMAIN !== '' ? PLESK_DOMAIN : '(non impostato!)') . "\n";
echo "\n";

if (PLESK_PASSWORD === '') {
    echo "ERRORE: PLESK_PASSWORD non impostata nel file .env\n";
    exit;
}

if (!defined('PLESK_DOMAIN') || PLESK_DOMAIN === '') {
    echo "ERRORE: PLESK_DOMAIN non impostato nel file .env\n";
    exit;
}

// Test 1: connessione al server Plesk
echo "--- Test 1: connessione a Plesk ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/server');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP: $code\n";
if ($err) echo "cURL error: $err\n";
if ($code === 200) {
    $info = json_decode($resp, true);
    echo "Plesk version: " . ($info['panel_version'] ?? '?') . "\n";
    echo "OK\n";
} else {
    echo "Risposta: $resp\n";
    exit;
}

// Test 2: lista record DNS della zona
echo "\n--- Test 2: record DNS di " . PLESK_DOMAIN . " ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records?' . http_build_query(['domainName' => PLESK_DOMAIN]));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: $code\n";
$records = json_decode($resp, true);
if ($code === 200 && is_array($records)) {
    echo "Record trovati: " . count($records) . "\n";
    foreach ($records as $r) {
        echo "  [{$r['type']}] {$r['host']} → {$r['value']}\n";
    }
} else {
    echo "Risposta: $resp\n";
    exit;
}

// Test 3: crea record A di test
echo "\n--- Test 3: crea record A test-ddns." . PLESK_DOMAIN . " → 1.2.3.4 ---\n";
$testFqdn = 'test-ddns.' . PLESK_DOMAIN . '.';
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'domainName' => PLESK_DOMAIN,
        'type'       => 'A',
        'host'       => $testFqdn,
        'value'      => '1.2.3.4',
        'ttl'        => 300,
    ]),
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_TIMEOUT        => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP: $code\n";
$result = json_decode($resp, true);
if ($code >= 200 && $code < 300) {
    echo "Creato con ID: " . ($result['id'] ?? '?') . "\n";
    echo "OK - il DNS funziona!\n";

    // Elimina subito il record di test
    $id = $result['id'] ?? null;
    if ($id) {
        $ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records/' . $id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
            CURLOPT_TIMEOUT        => 10,
        ]);
        curl_exec($ch);
        curl_close($ch);
        echo "Record di test eliminato.\n";
    }
} else {
    echo "Risposta: $resp\n";
}
