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

// Test: add_rec con solo hostname relativo (site-id=9, host="test-ddns")
echo "--- XML add_rec host=test-ddns site-id=9 ---\n";
$resp = pleskXml('<?xml version="1.0"?><packet><dns><add_rec><site-id>9</site-id><type>A</type><host>test-ddns</host><value>1.2.3.4</value></add_rec></dns></packet>', $sslOpts, $base);
echo "$resp\n";

// Estrai id dal risultato
preg_match('/<id>(\d+)<\/id>/', $resp, $m);
$newId = $m[1] ?? null;

if ($newId && strpos($resp, '<status>ok</status>') !== false) {
    echo "\n=== FUNZIONA! record creato con id=$newId ===\n";
    echo "Il record sara' test-ddns.ddns.gvweb.it → 1.2.3.4\n";

    // Elimina record di test
    echo "\n--- Elimino record di test (id=$newId) ---\n";
    $resp = pleskXml('<?xml version="1.0"?><packet><dns><del_rec><filter><id>' . $newId . '</id></filter></del_rec></dns></packet>', $sslOpts, $base);
    echo "$resp\n";
} else {
    echo "ERRORE - provo con host relativo diverso...\n";
}
