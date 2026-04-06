<?php

function _pleskXml(string $xml): string {
    $ch = curl_init(rtrim(PLESK_HOST, '/') . '/enterprise/control/agent.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml',
            'HTTP_AUTH_LOGIN: '  . PLESK_USER,
            'HTTP_AUTH_PASSWD: ' . PLESK_PASSWORD,
        ],
        CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => PLESK_VERIFY_SSL ? 2 : 0,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ?: '';
}

/**
 * Recupera il site-id Plesk del dominio PLESK_DOMAIN (cache statica).
 */
function _pleskSiteId(): ?int {
    static $id = false;
    if ($id !== false) return $id;
    if (!defined('PLESK_DOMAIN') || PLESK_DOMAIN === '') return $id = null;

    $ch = curl_init(rtrim(PLESK_HOST, '/') . '/api/v2/domains');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => PLESK_USER . ':' . PLESK_PASSWORD,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => PLESK_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => PLESK_VERIFY_SSL ? 2 : 0,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch); curl_close($ch);

    foreach (json_decode($resp ?: '[]', true) ?? [] as $d) {
        if (($d['name'] ?? '') === PLESK_DOMAIN) return $id = (int)$d['id'];
    }
    return $id = null;
}

/**
 * Trova l'id del record A per hostname.zone nella zona Plesk.
 */
function _pleskFindRecord(int $siteId, string $fqdn): ?int {
    $resp = _pleskXml('<?xml version="1.0"?><packet><dns><get_rec><filter><site-id>' . $siteId . '</site-id></filter></get_rec></dns></packet>');
    $xml = @simplexml_load_string($resp);
    if (!$xml) return null;
    foreach ($xml->dns->get_rec->result as $r) {
        if ((string)$r->status === 'ok'
            && (string)$r->data->type === 'A'
            && rtrim((string)$r->data->host, '.') === rtrim($fqdn, '.')) {
            return (int)$r->id;
        }
    }
    return null;
}

/**
 * Crea o aggiorna il record A su Plesk DNS per hostname.zone → ip.
 */
function pleskDnsUpdate(string $hostname, string $zone, string $ip): bool {
    if (!defined('PLESK_PASSWORD') || PLESK_PASSWORD === '') return false;
    $siteId = _pleskSiteId();
    if (!$siteId) return false;

    $fqdn = $hostname . '.' . $zone . '.';

    // Elimina eventuale record A esistente
    $existingId = _pleskFindRecord($siteId, $fqdn);
    if ($existingId) {
        _pleskXml('<?xml version="1.0"?><packet><dns><del_rec><filter><id>' . $existingId . '</id></filter></del_rec></dns></packet>');
    }

    // Crea il nuovo record (host relativo — Plesk aggiunge automaticamente il suffisso zona)
    $resp = _pleskXml(
        '<?xml version="1.0"?><packet><dns><add_rec>' .
        '<site-id>' . $siteId . '</site-id>' .
        '<type>A</type>' .
        '<host>' . htmlspecialchars($hostname, ENT_XML1) . '</host>' .
        '<value>' . htmlspecialchars($ip, ENT_XML1) . '</value>' .
        '</add_rec></dns></packet>'
    );

    return strpos($resp, '<status>ok</status>') !== false;
}

/**
 * Elimina il record A su Plesk DNS per hostname.zone.
 */
function pleskDnsDelete(string $hostname, string $zone): bool {
    if (!defined('PLESK_PASSWORD') || PLESK_PASSWORD === '') return false;
    $siteId = _pleskSiteId();
    if (!$siteId) return false;

    $fqdn = $hostname . '.' . $zone . '.';
    $existingId = _pleskFindRecord($siteId, $fqdn);
    if (!$existingId) return true;

    $resp = _pleskXml('<?xml version="1.0"?><packet><dns><del_rec><filter><id>' . $existingId . '</id></filter></del_rec></dns></packet>');
    return strpos($resp, '<status>ok</status>') !== false;
}
