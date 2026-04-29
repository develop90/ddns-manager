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

function _pleskLog(string $action, string $hostname, string $zone, string $ip, bool $success, string $message): void {
    try {
        getDb()->prepare("
            INSERT INTO plesk_log (action, hostname, zone, ip_address, success, message)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$action, $hostname, $zone, $ip, $success ? 1 : 0, substr($message, 0, 1000)]);
    } catch (Throwable $e) {
        error_log('[DDNS] Plesk log failed: ' . $e->getMessage());
    }
}

function _pleskResultMessage(string $resp): string {
    if ($resp === '') return 'Risposta vuota da Plesk.';
    $xml = @simplexml_load_string($resp);
    if (!$xml) return 'Risposta XML non valida da Plesk.';

    $result = $xml->xpath('//result')[0] ?? null;
    if (!$result) return 'Risposta Plesk senza nodo result.';

    $status = (string)($result->status ?? '');
    $errText = trim((string)($result->errtext ?? ''));
    $id = trim((string)($result->id ?? ''));

    if ($status === 'ok') {
        return $id !== '' ? "OK, record id $id." : 'OK.';
    }

    return $errText !== '' ? $errText : 'Operazione Plesk non riuscita.';
}

function _pleskResponseOk(string $resp): bool {
    $xml = @simplexml_load_string($resp);
    if (!$xml) return false;
    $result = $xml->xpath('//result')[0] ?? null;
    return $result && (string)($result->status ?? '') === 'ok';
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
    if (!defined('PLESK_HOST') || PLESK_HOST === '') {
        _pleskLog('update', $hostname, $zone, $ip, false, 'PLESK_HOST non configurato.');
        return false;
    }
    if (!defined('PLESK_PASSWORD') || PLESK_PASSWORD === '') {
        _pleskLog('update', $hostname, $zone, $ip, false, 'PLESK_PASSWORD non configurata.');
        return false;
    }
    $siteId = _pleskSiteId();
    if (!$siteId) {
        _pleskLog('update', $hostname, $zone, $ip, false, 'Site ID Plesk non trovato per PLESK_DOMAIN=' . PLESK_DOMAIN . '.');
        return false;
    }

    $fqdn = $hostname . '.' . $zone . '.';
    $notes = [];

    // Elimina eventuale record A esistente
    $existingId = _pleskFindRecord($siteId, $fqdn);
    if ($existingId) {
        $delResp = _pleskXml('<?xml version="1.0"?><packet><dns><del_rec><filter><id>' . $existingId . '</id></filter></del_rec></dns></packet>');
        $notes[] = 'Delete record esistente: ' . _pleskResultMessage($delResp);
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

    $ok = _pleskResponseOk($resp);
    $notes[] = 'Add record: ' . _pleskResultMessage($resp);
    _pleskLog('update', $hostname, $zone, $ip, $ok, implode(' ', $notes));
    return $ok;
}

/**
 * Elimina il record A su Plesk DNS per hostname.zone.
 */
function pleskDnsDelete(string $hostname, string $zone): bool {
    if (!defined('PLESK_HOST') || PLESK_HOST === '') {
        _pleskLog('delete', $hostname, $zone, '', false, 'PLESK_HOST non configurato.');
        return false;
    }
    if (!defined('PLESK_PASSWORD') || PLESK_PASSWORD === '') {
        _pleskLog('delete', $hostname, $zone, '', false, 'PLESK_PASSWORD non configurata.');
        return false;
    }
    $siteId = _pleskSiteId();
    if (!$siteId) {
        _pleskLog('delete', $hostname, $zone, '', false, 'Site ID Plesk non trovato per PLESK_DOMAIN=' . PLESK_DOMAIN . '.');
        return false;
    }

    $fqdn = $hostname . '.' . $zone . '.';
    $existingId = _pleskFindRecord($siteId, $fqdn);
    if (!$existingId) {
        _pleskLog('delete', $hostname, $zone, '', true, 'Record non presente su Plesk.');
        return true;
    }

    $resp = _pleskXml('<?xml version="1.0"?><packet><dns><del_rec><filter><id>' . $existingId . '</id></filter></del_rec></dns></packet>');
    $ok = _pleskResponseOk($resp);
    _pleskLog('delete', $hostname, $zone, '', $ok, _pleskResultMessage($resp));
    return $ok;
}
