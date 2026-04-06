<?php
require_once __DIR__ . '/config.php';
requireAdmin();

header('Content-Type: text/plain; charset=utf-8');

echo "=== Plesk DNS Test ===\n\n";
if (PLESK_PASSWORD === '') { echo "ERRORE: PLESK_PASSWORD vuota nel .env\n"; exit; }
if (!defined('PLESK_DOMAIN') || PLESK_DOMAIN === '') { echo "ERRORE: PLESK_DOMAIN non impostato nel .env\n"; exit; }

$sslOpts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
    CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
    CURLOPT_SSL_VERIFYHOST => PLESK_VERIFY_SSL ? 2 : 0,
    CURLOPT_TIMEOUT        => 10,
];

// Trova il dominio radice che ha la zona DNS (es. gvweb.it per ddns.gvweb.it)
echo "--- Ricerca zona DNS per " . PLESK_DOMAIN . " ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/domains');
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); curl_close($ch);
$domains = json_decode($resp, true) ?? [];

// Prova prima il dominio esatto, poi i parent (es. gvweb.it per ddns.gvweb.it)
$zoneSiteId = null;
$zoneName   = null;
$parts = explode('.', PLESK_DOMAIN);
while (count($parts) >= 2) {
    $candidate = implode('.', $parts);
    foreach ($domains as $d) {
        if (($d['name'] ?? '') === $candidate) {
            // Verifica che abbia una zona DNS attiva via XML
            $xml = '<?xml version="1.0"?><packet><dns><get_rec><filter><dns-zone-name>' . $candidate . '.</dns-zone-name></filter></get_rec></dns></packet>';
            $ch = curl_init(rtrim(PLESK_HOST, '/') . '/enterprise/control/agent.php');
            curl_setopt_array($ch, $sslOpts + [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $xml,
                CURLOPT_HTTPHEADER => ['Content-Type: text/xml', 'HTTP_AUTH_LOGIN: ' . PLESK_USER, 'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD],
            ]);
            $xresp = curl_exec($ch); curl_close($ch);
            if (strpos($xresp, '<status>ok</status>') !== false || strpos($xresp, '<status>error</status>') === false || strpos($xresp, 'Unable to find') === false) {
                if (strpos($xresp, 'Unable to find') === false) {
                    $zoneSiteId = $d['id'];
                    $zoneName   = $candidate;
                    break 2;
                }
            }
        }
    }
    array_shift($parts);
}

echo "Zona DNS trovata: " . ($zoneName ?? 'NESSUNA') . " (siteId=" . ($zoneSiteId ?? '?') . ")\n";
if (!$zoneSiteId) { echo "ERRORE: nessuna zona DNS trovata per " . PLESK_DOMAIN . "\n"; exit; }

// Test: lista record DNS della zona trovata
echo "\n--- Record DNS esistenti in $zoneName ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records?' . http_build_query(['siteId' => $zoneSiteId]));
curl_setopt_array($ch, $sslOpts + [CURLOPT_HTTPHEADER => ['Accept: application/json']]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\n";
$records = json_decode($resp, true);
if ($code === 200 && is_array($records)) {
    foreach ($records as $r) echo "  [{$r['type']}] {$r['host']} → {$r['value']}\n";
} else {
    echo "Risposta: $resp\n";
}

// Test: crea record A di test
echo "\n--- Crea record A test-ddns." . PLESK_DOMAIN . " → 1.2.3.4 ---\n";
$ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records');
curl_setopt_array($ch, $sslOpts + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => json_encode(['siteId' => $zoneSiteId, 'type' => 'A', 'host' => 'test-ddns.' . PLESK_DOMAIN . '.', 'value' => '1.2.3.4', 'ttl' => 300]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
]);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
echo "HTTP: $code\nRisposta: $resp\n";
$result = json_decode($resp, true);
if ($code >= 200 && $code < 300 && isset($result['id'])) {
    echo "\n=== DNS FUNZIONA! zona=$zoneName siteId=$zoneSiteId ===\n";
    $ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/dns/records/' . $result['id']);
    curl_setopt_array($ch, $sslOpts + [CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    curl_exec($ch); curl_close($ch);
    echo "Record di test eliminato.\n";
}
