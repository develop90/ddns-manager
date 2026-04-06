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

function pleskXml(string $xml, array $sslOpts, string $base): string {
    $ch = curl_init($base . '/enterprise/control/agent.php');
    curl_setopt_array($ch, $sslOpts + [
        CURLOPT_POST       => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
    ]);
    $resp = curl_exec($ch); curl_close($ch);
    return $resp;
}

// Test 1: lista record con site-id=1
echo "--- XML get_rec site-id=1 (gvweb.it) ---\n";
$resp = pleskXml('<?xml version="1.0"?><packet><dns><get_rec><filter><site-id>1</site-id></filter></get_rec></dns></packet>', $sslOpts, $base);
echo substr($resp, 0, 800) . "\n";

// Test 2: lista record con site-id=9
echo "\n--- XML get_rec site-id=9 (ddns.gvweb.it) ---\n";
$resp = pleskXml('<?xml version="1.0"?><packet><dns><get_rec><filter><site-id>9</site-id></filter></get_rec></dns></packet>', $sslOpts, $base);
echo substr($resp, 0, 800) . "\n";

// Test 3: add_rec senza ttl, site-id=1
echo "\n--- XML add_rec test-ddns.ddns.gvweb.it site-id=1 (senza ttl) ---\n";
$resp = pleskXml('<?xml version="1.0"?><packet><dns><add_rec><site-id>1</site-id><type>A</type><host>test-ddns.ddns.gvweb.it.</host><value>1.2.3.4</value></add_rec></dns></packet>', $sslOpts, $base);
echo "$resp\n";

// Test 4: add_rec senza ttl, site-id=9
echo "\n--- XML add_rec test-ddns.ddns.gvweb.it site-id=9 (senza ttl) ---\n";
$resp = pleskXml('<?xml version="1.0"?><packet><dns><add_rec><site-id>9</site-id><type>A</type><host>test-ddns.ddns.gvweb.it.</host><value>1.2.3.4</value></add_rec></dns></packet>', $sslOpts, $base);
echo "$resp\n";
