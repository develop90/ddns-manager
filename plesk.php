<?php

function _pleskCurl(string $url, array $opts): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

/**
 * Crea o aggiorna il record A su Plesk DNS per hostname.zone → ip.
 * Non fa nulla se PLESK_PASSWORD è vuota.
 */
function pleskDnsUpdate(string $hostname, string $zone, string $ip): bool {
    if (!defined('PLESK_PASSWORD') || PLESK_PASSWORD === '') return false;

    $fqdn = $hostname . '.' . $zone . '.';
    $base = rtrim(PLESK_HOST, '/') . '/api/v2/dns/records';

    // Cerca record A esistente nella zona
    [$code, $resp] = _pleskCurl($base . '?' . http_build_query(['domainName' => $zone]), []);
    $records = json_decode($resp ?: '[]', true) ?? [];

    $existingId = null;
    foreach ($records as $rec) {
        if ($rec['type'] === 'A' && rtrim($rec['host'], '.') === rtrim($fqdn, '.')) {
            $existingId = $rec['id'];
            break;
        }
    }

    if ($existingId) {
        [$code] = _pleskCurl($base . '/' . $existingId, [
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS    => json_encode(['value' => $ip, 'ttl' => DEFAULT_TTL]),
        ]);
    } else {
        [$code] = _pleskCurl($base, [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode([
                'domainName' => $zone,
                'type'       => 'A',
                'host'       => $fqdn,
                'value'      => $ip,
                'ttl'        => DEFAULT_TTL,
            ]),
        ]);
    }

    return $code >= 200 && $code < 300;
}

/**
 * Elimina il record A su Plesk DNS per hostname.zone.
 * Non fa nulla se PLESK_PASSWORD è vuota o il record non esiste.
 */
function pleskDnsDelete(string $hostname, string $zone): bool {
    if (!defined('PLESK_PASSWORD') || PLESK_PASSWORD === '') return false;

    $fqdn = $hostname . '.' . $zone;
    $base = rtrim(PLESK_HOST, '/') . '/api/v2/dns/records';

    [$code, $resp] = _pleskCurl($base . '?' . http_build_query(['domainName' => $zone]), []);
    $records = json_decode($resp ?: '[]', true) ?? [];

    foreach ($records as $rec) {
        if ($rec['type'] === 'A' && rtrim($rec['host'], '.') === $fqdn) {
            [$code] = _pleskCurl($base . '/' . $rec['id'], [
                CURLOPT_CUSTOMREQUEST => 'DELETE',
            ]);
            return $code >= 200 && $code < 300;
        }
    }

    return true;
}
