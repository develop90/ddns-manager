<?php

/**
 * Crea o aggiorna il record A su Plesk DNS per hostname.zone → ip.
 * Non fa nulla se PLESK_API_KEY è vuoto.
 */
function pleskDnsUpdate(string $hostname, string $zone, string $ip): bool {
    if (!defined('PLESK_API_KEY') || PLESK_API_KEY === '') return false;

    $fqdn = $hostname . '.' . $zone . '.';
    $base = rtrim(PLESK_HOST, '/') . '/api/v2/dns/records';
    $headers = [
        'X-API-Key: ' . PLESK_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    // Cerca record A esistente nella zona
    $ch = curl_init($base . '?' . http_build_query(['domainName' => $zone]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $records = json_decode($resp ?: '[]', true) ?? [];
    $existingId = null;
    foreach ($records as $rec) {
        if ($rec['type'] === 'A' && rtrim($rec['host'], '.') === rtrim($fqdn, '.')) {
            $existingId = $rec['id'];
            break;
        }
    }

    if ($existingId) {
        // Aggiorna record esistente
        $ch = curl_init($base . '/' . $existingId);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => json_encode(['value' => $ip, 'ttl' => DEFAULT_TTL]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
            CURLOPT_TIMEOUT        => 10,
        ]);
    } else {
        // Crea nuovo record
        $ch = curl_init($base);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'domainName' => $zone,
                'type'       => 'A',
                'host'       => $fqdn,
                'value'      => $ip,
                'ttl'        => DEFAULT_TTL,
            ]),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
            CURLOPT_TIMEOUT        => 10,
        ]);
    }

    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $code >= 200 && $code < 300;
}

/**
 * Elimina il record A su Plesk DNS per hostname.zone.
 * Non fa nulla se PLESK_API_KEY è vuoto o il record non esiste.
 */
function pleskDnsDelete(string $hostname, string $zone): bool {
    if (!defined('PLESK_API_KEY') || PLESK_API_KEY === '') return false;

    $fqdn = $hostname . '.' . $zone;
    $base = rtrim(PLESK_HOST, '/') . '/api/v2/dns/records';
    $headers = [
        'X-API-Key: ' . PLESK_API_KEY,
        'Accept: application/json',
    ];

    $ch = curl_init($base . '?' . http_build_query(['domainName' => $zone]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $records = json_decode($resp ?: '[]', true) ?? [];
    foreach ($records as $rec) {
        if ($rec['type'] === 'A' && rtrim($rec['host'], '.') === $fqdn) {
            $ch = curl_init($base . '/' . $rec['id']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $code >= 200 && $code < 300;
        }
    }

    return true; // record non trovato, niente da eliminare
}
