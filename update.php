<?php
/**
 * Endpoint di aggiornamento DDNS
 *
 * Compatibile con il protocollo DynDNS2 / No-IP.
 * I router possono usare HTTP Basic Auth oppure un token API.
 *
 * Parametri:
 *   hostname  - FQDN da aggiornare (es: miopc.ddns.esempio.it)
 *   myip      - nuovo indirizzo IP (opzionale, default = IP del client)
 *   token     - token API alternativo all'autenticazione Basic
 *
 * Risposte (compatibili DynDNS2):
 *   good <ip>    - aggiornamento riuscito
 *   nochg <ip>   - IP invariato
 *   nohost       - hostname non trovato
 *   badauth      - autenticazione fallita
 *   notfqdn      - hostname non valido
 *   abuse        - richiesta bloccata
 */

// Non servono sessioni per l'API
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/plesk.php';

header('Content-Type: text/plain; charset=utf-8');

$db = getDb();
$user = null;

// --- Autenticazione ---

// 1) Token via query string
if (!empty($_GET['token'])) {
    $token = $_GET['token'];
    $stmt = $db->prepare("SELECT * FROM users WHERE api_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
}

// 2) HTTP Basic Auth (usato dai router)
if (!$user && isset($_SERVER['PHP_AUTH_USER'])) {
    $username = $_SERVER['PHP_AUTH_USER'];
    $password = $_SERVER['PHP_AUTH_PW'] ?? '';
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $candidate = $stmt->fetch();
    if ($candidate && password_verify($password, $candidate['password'])) {
        $user = $candidate;
    }
}

// 3) Authorization header fallback (per server che non passano PHP_AUTH_*)
if (!$user && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($auth, 'Basic ') === 0) {
        $decoded = base64_decode(substr($auth, 6));
        if ($decoded && strpos($decoded, ':') !== false) {
            [$username, $password] = explode(':', $decoded, 2);
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $candidate = $stmt->fetch();
            if ($candidate && password_verify($password, $candidate['password'])) {
                $user = $candidate;
            }
        }
    }
}

if (!$user || !($user['active'] ?? 1)) {
    http_response_code(401);
    header('WWW-Authenticate: Basic realm="DDNS Update"');
    echo 'badauth';
    exit;
}

// --- Parametri ---

$hostname = trim($_GET['hostname'] ?? '');
if ($hostname === '') {
    echo 'notfqdn';
    exit;
}

// Ricava IP del client
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($clientIp, ',') !== false) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}

$myip = trim($_GET['myip'] ?? '');
if ($myip === '') {
    $myip = $clientIp;
}

// Validazione IP (v4 o v6)
if (!filter_var($myip, FILTER_VALIDATE_IP)) {
    echo 'abuse';
    exit;
}

// --- Trova l'host ---

// Prova a fare match su hostname.zone
$host = null;
$domains = $db->query("SELECT * FROM domains ORDER BY LENGTH(zone) DESC")->fetchAll();

foreach ($domains as $domain) {
    $suffix = '.' . $domain['zone'];
    if (str_ends_with($hostname, $suffix)) {
        $hostPart = substr($hostname, 0, -strlen($suffix));
        if ($hostPart !== '') {
            $stmt = $db->prepare("
                SELECT * FROM hosts
                WHERE hostname = ? AND domain_id = ? AND user_id = ?
            ");
            $stmt->execute([$hostPart, $domain['id'], $user['id']]);
            $host = $stmt->fetch();
            if ($host) break;
        }
    }
}

if (!$host) {
    echo 'nohost';
    exit;
}

// --- Aggiorna IP ---

$oldIp = $host['ip_address'];

if ($oldIp === $myip) {
    echo 'nochg ' . $myip;
    exit;
}

$db->prepare("UPDATE hosts SET ip_address = ?, last_update = CURRENT_TIMESTAMP WHERE id = ?")
    ->execute([$myip, $host['id']]);

$db->prepare("INSERT INTO update_log (host_id, old_ip, new_ip, source_ip, source_type) VALUES (?, ?, ?, ?, ?)")
    ->execute([$host['id'], $oldIp, $myip, $clientIp, 'Router / API']);

pleskDnsUpdate($hostPart, $domain['zone'], $myip);

echo 'good ' . $myip;
