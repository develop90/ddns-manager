<?php

function _pleskSetting(string $key, string $default = ''): string {
    try {
        $value = getSettingValue($key, null);
    } catch (Throwable $e) {
        $value = null;
    }
    return ($value !== null && $value !== '') ? $value : $default;
}

function _pleskConfig(): array {
    return [
        'host' => _pleskSetting('plesk_host', defined('PLESK_HOST') ? PLESK_HOST : ''),
        'user' => _pleskSetting('plesk_user', defined('PLESK_USER') ? PLESK_USER : ''),
        'password' => _pleskSetting('plesk_password', defined('PLESK_PASSWORD') ? PLESK_PASSWORD : ''),
        'domain' => _pleskSetting('plesk_domain', defined('PLESK_DOMAIN') ? PLESK_DOMAIN : ''),
        'site_id' => (int)_pleskSetting('plesk_site_id', '0'),
        'verify_ssl' => _pleskSetting(
            'plesk_verify_ssl',
            (defined('PLESK_VERIFY_SSL') && PLESK_VERIFY_SSL) ? '1' : '0'
        ) === '1',
    ];
}

function _pleskXml(string $xml): string {
    $cfg = _pleskConfig();
    $ch = curl_init(rtrim($cfg['host'], '/') . '/enterprise/control/agent.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_USERPWD        => $cfg['user'] . ':' . $cfg['password'],
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml',
            'HTTP_AUTH_LOGIN: '  . $cfg['user'],
            'HTTP_AUTH_PASSWD: ' . $cfg['password'],
        ],
        CURLOPT_SSL_VERIFYPEER => $cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
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
    $cfg = _pleskConfig();
    if ($cfg['site_id'] > 0) return $id = $cfg['site_id'];
    if ($cfg['domain'] === '') return $id = null;

    $ch = curl_init(rtrim($cfg['host'], '/') . '/api/v2/domains');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $cfg['user'] . ':' . $cfg['password'],
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => $cfg['verify_ssl'],
        CURLOPT_SSL_VERIFYHOST => $cfg['verify_ssl'] ? 2 : 0,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch); curl_close($ch);

    foreach (json_decode($resp ?: '[]', true) ?? [] as $d) {
        if (($d['name'] ?? '') === $cfg['domain']) return $id = (int)$d['id'];
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
    $cfg = _pleskConfig();
    if ($cfg['host'] === '') {
        _pleskLog('update', $hostname, $zone, $ip, false, 'PLESK_HOST non configurato.');
        return false;
    }
    if ($cfg['password'] === '') {
        _pleskLog('update', $hostname, $zone, $ip, false, 'PLESK_PASSWORD non configurata.');
        return false;
    }
    $siteId = _pleskSiteId();
    if (!$siteId) {
        _pleskLog('update', $hostname, $zone, $ip, false, 'Site ID Plesk non trovato per PLESK_DOMAIN=' . $cfg['domain'] . '.');
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
    $cfg = _pleskConfig();
    if ($cfg['host'] === '') {
        _pleskLog('delete', $hostname, $zone, '', false, 'PLESK_HOST non configurato.');
        return false;
    }
    if ($cfg['password'] === '') {
        _pleskLog('delete', $hostname, $zone, '', false, 'PLESK_PASSWORD non configurata.');
        return false;
    }
    $siteId = _pleskSiteId();
    if (!$siteId) {
        _pleskLog('delete', $hostname, $zone, '', false, 'Site ID Plesk non trovato per PLESK_DOMAIN=' . $cfg['domain'] . '.');
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
