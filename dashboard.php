<?php
require_once __DIR__ . '/config.php';
requireLogin();

$db = getDb();
$user = getCurrentUser();
$msg = '';
$msgType = '';

// Aggiungi host
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_host') {
    $hostname = strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['hostname'] ?? '')));
    $domainId = (int)($_POST['domain_id'] ?? 0);

    if ($hostname === '' || $domainId === 0) {
        $msg = 'Hostname non valido.';
        $msgType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT id FROM domains WHERE id = ?");
        $stmt->execute([$domainId]);
        if (!$stmt->fetch()) {
            $msg = 'Dominio non valido.';
            $msgType = 'danger';
        } else {
            $stmt = $db->prepare("SELECT id FROM hosts WHERE hostname = ? AND domain_id = ?");
            $stmt->execute([$hostname, $domainId]);
            if ($stmt->fetch()) {
                $msg = 'Questo hostname esiste già.';
                $msgType = 'danger';
            } else {
                $stmt = $db->prepare("INSERT INTO hosts (user_id, hostname, domain_id) VALUES (?, ?, ?)");
                $stmt->execute([$user['id'], $hostname, $domainId]);
                $msg = 'Host aggiunto con successo!';
                $msgType = 'success';
            }
        }
    }
}

// Elimina host
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_host') {
    $hostId = (int)($_POST['host_id'] ?? 0);
    $stmt = $db->prepare("DELETE FROM hosts WHERE id = ? AND user_id = ?");
    $stmt->execute([$hostId, $user['id']]);
    $msg = 'Host eliminato.';
    $msgType = 'success';
}

// Aggiorna IP manualmente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ip') {
    $hostId = (int)($_POST['host_id'] ?? 0);
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);
    $newIp = trim($_POST['custom_ip'] ?? '') ?: $clientIp;

    if (!filter_var($newIp, FILTER_VALIDATE_IP)) {
        $msg = 'Indirizzo IP non valido.';
        $msgType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT * FROM hosts WHERE id = ? AND user_id = ?");
        $stmt->execute([$hostId, $user['id']]);
        $host = $stmt->fetch();
        if ($host) {
            $db->prepare("UPDATE hosts SET ip_address = ?, last_update = CURRENT_TIMESTAMP WHERE id = ?")
               ->execute([$newIp, $hostId]);
            $db->prepare("INSERT INTO update_log (host_id, old_ip, new_ip, source_ip) VALUES (?, ?, ?, ?)")
               ->execute([$hostId, $host['ip_address'], $newIp, $clientIp]);
            $msg = 'IP aggiornato a ' . $newIp;
            $msgType = 'success';
        }
    }
}

// Modifica host
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_host') {
    $hostId = (int)($_POST['host_id'] ?? 0);
    $newHostname = strtolower(trim(preg_replace('/[^a-zA-Z0-9\-]/', '', $_POST['hostname'] ?? '')));
    $newDomainId = (int)($_POST['domain_id'] ?? 0);

    if ($newHostname === '' || $newDomainId === 0) {
        $msg = 'Hostname non valido.';
        $msgType = 'danger';
    } else {
        // Controlla che non esista già un altro host con stesso nome+dominio
        $stmt = $db->prepare("SELECT id FROM hosts WHERE hostname = ? AND domain_id = ? AND id != ?");
        $stmt->execute([$newHostname, $newDomainId, $hostId]);
        if ($stmt->fetch()) {
            $msg = 'Questo hostname esiste già su quel dominio.';
            $msgType = 'danger';
        } else {
            $customIp = trim($_POST['custom_ip_edit'] ?? '');
            if ($customIp !== '' && !filter_var($customIp, FILTER_VALIDATE_IP)) {
                $msg = 'Indirizzo IP non valido.';
                $msgType = 'danger';
            } else {
                if ($customIp !== '') {
                    $stmt2 = $db->prepare("SELECT ip_address FROM hosts WHERE id = ? AND user_id = ?");
                    $stmt2->execute([$hostId, $user['id']]);
                    $oldHost = $stmt2->fetch();
                    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
                    $db->prepare("UPDATE hosts SET hostname = ?, domain_id = ?, ip_address = ?, last_update = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?")
                       ->execute([$newHostname, $newDomainId, $customIp, $hostId, $user['id']]);
                    $db->prepare("INSERT INTO update_log (host_id, old_ip, new_ip, source_ip) VALUES (?, ?, ?, ?)")
                       ->execute([$hostId, $oldHost['ip_address'] ?? '', $customIp, $clientIp]);
                } else {
                    $db->prepare("UPDATE hosts SET hostname = ?, domain_id = ? WHERE id = ? AND user_id = ?")
                       ->execute([$newHostname, $newDomainId, $hostId, $user['id']]);
                }
                $msg = 'Host aggiornato.';
                $msgType = 'success';
            }
        }
    }
}

// Rigenera token
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regen_token') {
    $newToken = bin2hex(random_bytes(32));
    $stmt = $db->prepare("UPDATE users SET api_token = ? WHERE id = ?");
    $stmt->execute([$newToken, $user['id']]);
    $user['api_token'] = $newToken;
    $msg = 'Token API rigenerato.';
    $msgType = 'success';
}

// Cambia password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $oldPass = $_POST['old_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    if (!password_verify($oldPass, $user['password'])) {
        $msg = 'Password attuale non corretta.';
        $msgType = 'danger';
    } elseif (strlen($newPass) < 4) {
        $msg = 'La nuova password deve avere almeno 4 caratteri.';
        $msgType = 'danger';
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $user['id']]);
        $msg = 'Password aggiornata!';
        $msgType = 'success';
    }
}

// Carica dati
$domains = $db->query("SELECT * FROM domains ORDER BY zone")->fetchAll();
$hosts = $db->prepare("
    SELECT h.*, d.zone
    FROM hosts h
    JOIN domains d ON h.domain_id = d.id
    WHERE h.user_id = ?
    ORDER BY h.hostname
");
$hosts->execute([$user['id']]);
$hosts = $hosts->fetchAll();

$serverHost = $_SERVER['HTTP_HOST'] ?? 'tuoserver.com';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="navbar">
    <h1><?= APP_NAME ?></h1>
    <nav>
        <a href="dashboard.php" class="active">
            <span class="nav-icon">🏠</span>
            <span class="nav-label">Dashboard</span>
        </a>
        <?php if (isAdmin()): ?>
        <a href="admin.php">
            <span class="nav-icon">⚙️</span>
            <span class="nav-label">Admin</span>
        </a>
        <?php endif; ?>
        <a href="logout.php">
            <span class="nav-icon">🚪</span>
            <span class="nav-label"><?= htmlspecialchars($user['username']) ?></span>
        </a>
    </nav>
</div>

<div class="container">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats mb-1" style="margin-bottom:1.5rem">
        <div class="stat-item">
            <div class="value"><?= count($hosts) ?></div>
            <div class="label">Host attivi</div>
        </div>
        <div class="stat-item">
            <div class="value"><?= count($domains) ?></div>
            <div class="label">Domini disponibili</div>
        </div>
        <div class="stat-item">
            <div class="value"><?= count(array_filter($hosts, fn($h) => $h['ip_address'] !== '')) ?></div>
            <div class="label">IP assegnati</div>
        </div>
    </div>

    <!-- Aggiungi host -->
    <div class="card">
        <h2>Aggiungi nuovo host</h2>
        <?php if (empty($domains)): ?>
            <div class="alert alert-info">Nessun dominio disponibile. Contatta l'amministratore.</div>
        <?php else: ?>
            <form method="POST" class="form-inline">
                <input type="hidden" name="action" value="add_host">
                <div class="form-group">
                    <label>Hostname</label>
                    <input type="text" name="hostname" placeholder="miopc" required pattern="[a-zA-Z0-9\-]+">
                </div>
                <div class="form-group">
                    <label>Dominio</label>
                    <select name="domain_id" required>
                        <?php foreach ($domains as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['zone']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary" style="margin-bottom:0;align-self:end">Aggiungi</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Lista hosts -->
    <div class="card">
        <h2>I tuoi host</h2>
        <?php if (empty($hosts)): ?>
            <p class="text-muted">Nessun host configurato.</p>
        <?php else: ?>
            <?php $editingId = (int)($_GET['edit'] ?? 0); ?>
            <table>
                <thead>
                    <tr>
                        <th>Hostname</th>
                        <th>IP attuale</th>
                        <th>Ultimo aggiornamento</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hosts as $h): ?>
                    <?php if ($editingId === (int)$h['id']): ?>
                    <!-- Riga modifica -->
                    <tr style="background:#0f172a">
                        <td colspan="4">
                            <form method="POST" class="form-inline" style="gap:0.4rem;align-items:end">
                                <input type="hidden" name="action" value="edit_host">
                                <input type="hidden" name="host_id" value="<?= $h['id'] ?>">
                                <div class="form-group">
                                    <label>Hostname</label>
                                    <input type="text" name="hostname" value="<?= htmlspecialchars($h['hostname']) ?>" required pattern="[a-zA-Z0-9\-]+">
                                </div>
                                <div class="form-group">
                                    <label>Dominio</label>
                                    <select name="domain_id" required>
                                        <?php foreach ($domains as $d): ?>
                                            <option value="<?= $d['id'] ?>" <?= $d['id'] == $h['domain_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($d['zone']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>IP manuale (opz.)</label>
                                    <input type="text" name="custom_ip_edit" placeholder="lascia vuoto = invariato">
                                </div>
                                <button type="submit" class="btn btn-success btn-sm">Salva</button>
                                <a href="dashboard.php" class="btn btn-sm" style="background:#334155">Annulla</a>
                            </form>
                        </td>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($h['hostname'] . '.' . $h['zone']) ?></strong></td>
                        <td>
                            <?php if ($h['ip_address']): ?>
                                <span class="ip-badge"><?= htmlspecialchars($h['ip_address']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">Non assegnato</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= $h['last_update'] ? date('d/m/Y H:i', strtotime($h['last_update'])) : '-' ?></td>
                        <td>
                            <div class="actions">
                                <!-- Aggiorna IP -->
                                <form method="POST" style="display:inline" class="form-update-ip">
                                    <input type="hidden" name="action" value="update_ip">
                                    <input type="hidden" name="host_id" value="<?= $h['id'] ?>">
                                    <input type="hidden" name="custom_ip" class="ip-input">
                                    <button type="submit" class="btn btn-success btn-sm" title="Aggiorna con il tuo IP pubblico">Aggiorna IP</button>
                                </form>
                                <!-- Modifica -->
                                <a href="dashboard.php?edit=<?= $h['id'] ?>" class="btn btn-sm" style="background:#7c3aed">Modifica</a>
                                <!-- Elimina -->
                                <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare questo host?')">
                                    <input type="hidden" name="action" value="delete_host">
                                    <input type="hidden" name="host_id" value="<?= $h['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Elimina</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Token API e configurazione router -->
    <div class="card">
        <h2>Configurazione Router / Client</h2>
        <p class="text-muted mb-1">Il tuo token API per aggiornare il DNS dal router:</p>
        <div class="token-box"><?= htmlspecialchars($user['api_token']) ?></div>
        <form method="POST" class="mt-1">
            <input type="hidden" name="action" value="regen_token">
            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Rigenerare il token? Dovrai aggiornare la configurazione del router.')">Rigenera token</button>
        </form>

        <div class="api-help mt-2">
            <p><strong>Protocollo compatibile DynDNS/No-IP</strong></p>
            <p class="text-muted mt-1">Configura il tuo router con questi parametri:</p>
            <table>
                <tr><td><strong>Servizio</strong></td><td>Custom / DynDNS</td></tr>
                <tr><td><strong>Server</strong></td><td><?= htmlspecialchars($serverHost) ?></td></tr>
                <tr><td><strong>URL di aggiornamento</strong></td><td>/nic/update?hostname=&lt;h&gt;&amp;myip=&lt;a&gt;</td></tr>
                <tr><td><strong>Username</strong></td><td><?= htmlspecialchars($user['username']) ?></td></tr>
                <tr><td><strong>Password</strong></td><td>La tua password di login</td></tr>
            </table>

            <p class="mt-2"><strong>Oppure aggiorna via URL diretta (con token):</strong></p>
            <code>http://<?= htmlspecialchars($serverHost) ?>/update.php?token=<?= htmlspecialchars($user['api_token']) ?>&amp;hostname=miopc.esempio.it&amp;myip=1.2.3.4</code>

            <p class="mt-2"><strong>Oppure via HTTP Basic Auth (compatibile con i router):</strong></p>
            <code>http://<?= htmlspecialchars($user['username']) ?>:PASSWORD@<?= htmlspecialchars($serverHost) ?>/nic/update?hostname=miopc.esempio.it&amp;myip=1.2.3.4</code>

            <p class="text-muted mt-1">Se ometti <em>myip</em>, verrà usato l'IP da cui proviene la richiesta.</p>
        </div>
    </div>

    <!-- Cambio password -->
    <div class="card">
        <h2>Cambia password</h2>
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="form-inline">
                <div class="form-group">
                    <label>Password attuale</label>
                    <input type="password" name="old_password" required>
                </div>
                <div class="form-group">
                    <label>Nuova password</label>
                    <input type="password" name="new_password" required minlength="4">
                </div>
                <button type="submit" class="btn btn-primary" style="align-self:end">Aggiorna</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.form-update-ip').forEach(form => {
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        const btn = this.querySelector('button');
        btn.textContent = '...';
        btn.disabled = true;
        try {
            const res = await fetch('https://api.ipify.org?format=json');
            const data = await res.json();
            this.querySelector('.ip-input').value = data.ip;
        } catch (_) {
            // fallback: invia senza ip, il server userà REMOTE_ADDR
        }
        this.submit();
    });
});
</script>
<footer style="text-align:center;padding:1rem 0 1.5rem;color:#475569;font-size:0.75rem;border-top:1px solid #1e293b;margin-top:2rem">
    <?= APP_NAME ?> v<?= APP_VERSION ?> — build <?= APP_BUILD ?>
</footer>
</body>
</html>
