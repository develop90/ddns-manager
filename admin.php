<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$db = getDb();
$user = getCurrentUser();
$msg = '';
$msgType = '';

// Esporta database SQLite (solo admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export_db') {
    $tmpFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ddns-manager-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $db->exec('VACUUM INTO ' . $db->quote($tmpFile));
        if (!is_file($tmpFile)) {
            throw new RuntimeException('Export file non creato.');
        }

        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $downloadName = 'ddns-manager-' . date('Ymd-His') . '.sqlite';
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($tmpFile));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        readfile($tmpFile);
        @unlink($tmpFile);
        exit;
    } catch (Throwable $e) {
        @unlink($tmpFile);
        $msg = 'Export database non riuscito.';
        $msgType = 'danger';
    }
}

// Aggiungi dominio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_domain') {
    $zone = strtolower(trim($_POST['zone'] ?? ''));
    $zone = preg_replace('/[^a-z0-9.\-]/', '', $zone);
    if ($zone === '') {
        $msg = 'Dominio non valido.';
        $msgType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT id FROM domains WHERE zone = ?");
        $stmt->execute([$zone]);
        if ($stmt->fetch()) {
            $msg = 'Questo dominio esiste già.';
            $msgType = 'danger';
        } else {
            $db->prepare("INSERT INTO domains (zone) VALUES (?)")->execute([$zone]);
            $msg = "Dominio '$zone' aggiunto!";
            $msgType = 'success';
        }
    }
}

// Elimina dominio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_domain') {
    $domainId = (int)($_POST['domain_id'] ?? 0);
    $db->prepare("DELETE FROM domains WHERE id = ?")->execute([$domainId]);
    $msg = 'Dominio eliminato (e tutti gli host associati).';
    $msgType = 'success';
}

// Aggiungi utente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

    if ($username === '' || strlen($password) < 4) {
        $msg = 'Username e password (min 4 char) obbligatori.';
        $msgType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $msg = 'Username già in uso.';
            $msgType = 'danger';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $token = bin2hex(random_bytes(32));
            $db->prepare("INSERT INTO users (username, password, is_admin, api_token) VALUES (?, ?, ?, ?)")
                ->execute([$username, $hash, $isAdmin, $token]);
            $msg = "Utente '$username' creato!";
            $msgType = 'success';
        }
    }
}

// Modifica utente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $newUsername = trim($_POST['username'] ?? '');
    $newPassword = $_POST['password'] ?? '';
    $newIsAdmin = isset($_POST['is_admin']) ? 1 : 0;

    if ($newUsername === '') {
        $msg = 'Username non può essere vuoto.';
        $msgType = 'danger';
    } else {
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$newUsername, $userId]);
        if ($stmt->fetch()) {
            $msg = 'Username già in uso.';
            $msgType = 'danger';
        } else {
            if ($newPassword !== '') {
                if (strlen($newPassword) < 4) {
                    $msg = 'Password troppo corta (min 4 caratteri).';
                    $msgType = 'danger';
                    goto end_edit_user;
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $db->prepare("UPDATE users SET username = ?, password = ?, is_admin = ? WHERE id = ?")
                   ->execute([$newUsername, $hash, $newIsAdmin, $userId]);
            } else {
                $db->prepare("UPDATE users SET username = ?, is_admin = ? WHERE id = ?")
                   ->execute([$newUsername, $newIsAdmin, $userId]);
            }
            $msg = 'Utente aggiornato.';
            $msgType = 'success';
        }
    }
    end_edit_user:;
}

// Elimina utente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === $user['id']) {
        $msg = 'Non puoi eliminare te stesso.';
        $msgType = 'danger';
    } else {
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
        $msg = 'Utente eliminato.';
        $msgType = 'success';
    }
}

// Salva impostazioni brute force
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    foreach (['bf_max_attempts','bf_window_min','bf_lockout_min'] as $k) {
        $v = max(1, (int)($_POST[$k] ?? 1));
        $db->prepare("UPDATE settings SET value=? WHERE key=?")->execute([$v, $k]);
    }
    $msg = 'Impostazioni salvate.'; $msgType = 'success';
}

// Salva impostazioni Plesk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_plesk_settings') {
    $pleskHost = trim($_POST['plesk_host'] ?? '');
    $pleskUser = trim($_POST['plesk_user'] ?? '');
    $pleskPassword = $_POST['plesk_password'] ?? '';
    $pleskDomain = strtolower(trim($_POST['plesk_domain'] ?? ''));
    $pleskSiteId = trim($_POST['plesk_site_id'] ?? '');
    $pleskVerifySsl = isset($_POST['plesk_verify_ssl']) ? '1' : '0';

    if ($pleskHost === '' || !filter_var($pleskHost, FILTER_VALIDATE_URL)) {
        $msg = 'Host Plesk non valido.';
        $msgType = 'danger';
    } elseif ($pleskUser === '') {
        $msg = 'Utente Plesk obbligatorio.';
        $msgType = 'danger';
    } elseif ($pleskDomain === '') {
        $msg = 'Dominio Plesk obbligatorio.';
        $msgType = 'danger';
    } elseif ($pleskSiteId !== '' && (!ctype_digit($pleskSiteId) || (int)$pleskSiteId < 1)) {
        $msg = 'Site ID Plesk non valido.';
        $msgType = 'danger';
    } else {
        setSettingValue('plesk_host', rtrim($pleskHost, '/'));
        setSettingValue('plesk_user', $pleskUser);
        if ($pleskPassword !== '') {
            setSettingValue('plesk_password', $pleskPassword);
        }
        setSettingValue('plesk_domain', $pleskDomain);
        setSettingValue('plesk_site_id', $pleskSiteId);
        setSettingValue('plesk_verify_ssl', $pleskVerifySsl);
        $msg = 'Impostazioni Plesk salvate.';
        $msgType = 'success';
    }
}

// Abilita/disabilita utente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === $user['id']) {
        $msg = 'Non puoi disabilitare te stesso.'; $msgType = 'danger';
    } else {
        $db->prepare("UPDATE users SET active = 1 - active WHERE id = ?")->execute([$userId]);
        $msg = 'Utente aggiornato.'; $msgType = 'success';
    }
}

// Sblocca IP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unblock_ip') {
    $ip = $_POST['ip'] ?? '';
    $db->prepare("DELETE FROM login_log WHERE ip=? AND success=0")->execute([$ip]);
    $msg = "IP $ip sbloccato."; $msgType = 'success';
}

// Upload logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_logo') {
    $file = $_FILES['logo'] ?? null;
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/svg+xml'=>'svg'];
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $msg = 'Errore nel caricamento del file.'; $msgType = 'danger';
    } elseif (!isset($allowed[$file['type']])) {
        $msg = 'Formato non supportato (usa JPG, PNG, GIF, WEBP, SVG).'; $msgType = 'danger';
    } elseif ($file['size'] > 2 * 1024 * 1024) {
        $msg = 'File troppo grande (max 2 MB).'; $msgType = 'danger';
    } else {
        $ext = $allowed[$file['type']];
        // Rimuovi logo precedente
        foreach (['jpg','jpeg','png','gif','webp','svg'] as $e) {
            @unlink(__DIR__ . '/data/logo.' . $e);
        }
        move_uploaded_file($file['tmp_name'], __DIR__ . '/data/logo.' . $ext);
        $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('logo_ext',?)")->execute([$ext]);
        $msg = 'Logo caricato.'; $msgType = 'success';
    }
}

// Salva URL logo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_logo_url') {
    $url = trim($_POST['logo_url'] ?? '');
    if ($url && !filter_var($url, FILTER_VALIDATE_URL)) {
        $msg = 'URL non valido.'; $msgType = 'danger';
    } else {
        if ($url) {
            $db->prepare("INSERT OR REPLACE INTO settings (key,value) VALUES ('logo_url',?)")->execute([$url]);
        } else {
            $db->prepare("DELETE FROM settings WHERE key='logo_url'")->execute();
        }
        $msg = $url ? 'URL logo salvato.' : 'URL logo rimosso.'; $msgType = 'success';
    }
}

// Elimina logo (file + URL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_logo') {
    $ext = $db->query("SELECT value FROM settings WHERE key='logo_ext'")->fetchColumn();
    if ($ext) @unlink(__DIR__ . '/data/logo.' . $ext);
    $db->prepare("DELETE FROM settings WHERE key IN ('logo_ext','logo_url')")->execute();
    $msg = 'Logo rimosso.'; $msgType = 'success';
}

// Svuota log accessi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_login_log') {
    $db->exec("DELETE FROM login_log");
    $msg = 'Log accessi svuotato.'; $msgType = 'success';
}

// Carica dati
$domains = $db->query("SELECT d.*, COUNT(h.id) as host_count FROM domains d LEFT JOIN hosts h ON h.domain_id = d.id GROUP BY d.id ORDER BY d.zone")->fetchAll();
$users = $db->query("SELECT u.*, COUNT(h.id) as host_count FROM users u LEFT JOIN hosts h ON h.user_id = u.id GROUP BY u.id ORDER BY u.username")->fetchAll();
$recentLogs = $db->query("
    SELECT l.*, h.hostname, d.zone
    FROM update_log l
    JOIN hosts h ON l.host_id = h.id
    JOIN domains d ON h.domain_id = d.id
    ORDER BY l.updated_at DESC
    LIMIT 20
")->fetchAll();
$pleskLogs = $db->query("
    SELECT *
    FROM plesk_log
    ORDER BY created_at DESC
    LIMIT 50
")->fetchAll();
$bfSettings = $db->query("SELECT key, value FROM settings WHERE key LIKE 'bf_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$pleskSettings = [
    'host' => getSettingValue('plesk_host', PLESK_HOST),
    'user' => getSettingValue('plesk_user', PLESK_USER),
    'has_password' => getSettingValue('plesk_password', PLESK_PASSWORD) !== '',
    'domain' => getSettingValue('plesk_domain', PLESK_DOMAIN),
    'site_id' => getSettingValue('plesk_site_id', defined('PLESK_SITE_ID') ? (string)PLESK_SITE_ID : ''),
    'verify_ssl' => getSettingValue('plesk_verify_ssl', PLESK_VERIFY_SSL ? '1' : '0'),
];

$bfMax    = (int)($bfSettings['bf_max_attempts'] ?? 5);
$bfWindow = (int)($bfSettings['bf_window_min']   ?? 10);

$logPerPage = 25;
$logPage    = max(1, (int)($_GET['log_page'] ?? 1));
$logTotal   = (int)$db->query("SELECT COUNT(*) FROM login_log")->fetchColumn();
$logPages   = max(1, (int)ceil($logTotal / $logPerPage));
$logPage    = min($logPage, $logPages);
$logOffset  = ($logPage - 1) * $logPerPage;
$loginLogs  = $db->prepare("
    SELECT *,
        (SELECT COUNT(*) FROM login_log l2
         WHERE l2.ip = login_log.ip AND l2.success = 0
         AND l2.logged_at >= datetime('now', '-{$bfWindow} minutes')) as recent_failures
    FROM login_log ORDER BY logged_at DESC LIMIT ? OFFSET ?
");
$loginLogs->execute([$logPerPage, $logOffset]);
$loginLogs = $loginLogs->fetchAll();
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="navbar">
    <?php $logoUrl = getLogoUrl(); ?>
    <a href="dashboard.php" style="text-decoration:none;color:inherit;display:flex;align-items:center">
        <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>" style="max-height:44px;max-width:160px;object-fit:contain">
        <?php else: ?>
            <h1><?= APP_NAME ?></h1>
        <?php endif; ?>
    </a>
    <nav>
        <a href="dashboard.php">
            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12L12 3l9 9"/><path d="M9 21V12h6v9"/><path d="M3 12v9h18v-9"/></svg></span>
            <span class="nav-label">Dashboard</span>
        </a>
        <a href="admin.php" class="active">
            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
            <span class="nav-label">Admin</span>
        </a>
        <a href="logout.php">
            <span class="nav-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
            <span class="nav-label"><?= htmlspecialchars($user['username']) ?></span>
        </a>
        <button class="theme-toggle" id="themeToggle" title="Cambia tema">🌙</button>
    </nav>
</div>

<div class="container">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- Tab nav -->
    <div class="tabs">
        <button class="tab-btn" data-tab="domini">Domini</button>
        <button class="tab-btn" data-tab="utenti">Utenti</button>
        <button class="tab-btn" data-tab="impostazioni">Impostazioni</button>
        <button class="tab-btn" data-tab="log">Log</button>
    </div>

    <!-- TAB: Domini -->
    <div class="tab-panel" id="tab-domini">
    <!-- Gestione Domini -->
    <div class="card">
        <h2>Gestione Domini</h2>
        <form method="POST" class="form-inline" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="add_domain">
            <div class="form-group">
                <label>Nuovo dominio (zona)</label>
                <input type="text" name="zone" placeholder="ddns.esempio.it" required>
            </div>
            <button type="submit" class="btn btn-primary" style="align-self:end">Aggiungi</button>
        </form>

        <?php if (empty($domains)): ?>
            <p class="text-muted">Nessun dominio configurato.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Dominio</th><th>Host registrati</th><th>Creato il</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($domains as $d): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($d['zone']) ?></strong></td>
                        <td><?= $d['host_count'] ?></td>
                        <td class="text-muted"><?= date('d/m/Y', strtotime($d['created_at'])) ?></td>
                        <td>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare il dominio e tutti i suoi host?')">
                                <input type="hidden" name="action" value="delete_domain">
                                <input type="hidden" name="domain_id" value="<?= $d['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Elimina</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    </div><!-- /tab-domini -->

    <!-- TAB: Utenti -->
    <div class="tab-panel" id="tab-utenti">
    <!-- Gestione Utenti -->
    <div class="card">
        <h2>Gestione Utenti</h2>
        <form method="POST" class="form-inline" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="add_user">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required minlength="4">
            </div>
            <div class="form-group" style="flex:0;align-self:end">
                <label><input type="checkbox" name="is_admin"> Admin</label>
            </div>
            <button type="submit" class="btn btn-success" style="align-self:end">Crea</button>
        </form>

        <?php $editingUserId = (int)($_GET['edit_user'] ?? 0); ?>
        <table>
            <thead>
                <tr><th>Username</th><th>Ruolo</th><th>Host</th><th>Creato il</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <?php if ($editingUserId === (int)$u['id']): ?>
                <tr style="background:#0f172a">
                    <td colspan="5">
                        <form method="POST" class="form-inline" style="gap:0.4rem;align-items:end">
                            <input type="hidden" name="action" value="edit_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <div class="form-group">
                                <label>Username</label>
                                <input type="text" name="username" value="<?= htmlspecialchars($u['username']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Nuova password (opz.)</label>
                                <input type="password" name="password" placeholder="lascia vuoto = invariata" minlength="4">
                            </div>
                            <div class="form-group" style="flex:0;align-self:end">
                                <label><input type="checkbox" name="is_admin" <?= $u['is_admin'] ? 'checked' : '' ?>> Admin</label>
                            </div>
                            <button type="submit" class="btn btn-success btn-sm">Salva</button>
                            <a href="admin.php" class="btn btn-sm" style="background:#334155">Annulla</a>
                        </form>
                    </td>
                </tr>
                <?php else: ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td>
                        <?= $u['is_admin'] ? 'Admin' : 'Utente' ?>
                        <?php if (!($u['active'] ?? 1)): ?>
                            <span style="font-size:0.7rem;background:#7f1d1d;color:#fca5a5;padding:1px 6px;border-radius:4px;margin-left:4px">disabilitato</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $u['host_count'] ?></td>
                    <td class="text-muted"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div class="actions">
                            <a href="admin.php?edit_user=<?= $u['id'] ?>" class="btn btn-sm" style="background:#7c3aed">Modifica</a>
                            <?php if ($u['id'] !== $user['id']): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-sm" style="background:<?= $u['active'] ?? 1 ? '#0369a1' : '#15803d' ?>">
                                    <?= ($u['active'] ?? 1) ? 'Disabilita' : 'Abilita' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Eliminare questo utente e i suoi host?')">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Elimina</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    </div><!-- /tab-utenti -->

    <!-- TAB: Impostazioni -->
    <div class="tab-panel" id="tab-impostazioni">
    <!-- Logo -->
    <div class="card">
        <h2>Logo sito</h2>
        <?php
            $logoUrl    = getLogoUrl();
            $logoUrlSaved = $db->query("SELECT value FROM settings WHERE key='logo_url'")->fetchColumn() ?: '';
        ?>
        <?php if ($logoUrl): ?>
        <div style="margin-bottom:1rem">
            <img src="<?= htmlspecialchars($logoUrl) ?>" style="max-height:80px;max-width:300px;object-fit:contain;background:#0f172a;padding:8px;border-radius:8px;border:1px solid #334155">
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:1.5rem;flex-wrap:wrap;align-items:flex-start">
            <!-- Upload file -->
            <form method="POST" enctype="multipart/form-data" class="form-inline" style="flex:1;min-width:220px">
                <input type="hidden" name="action" value="upload_logo">
                <div class="form-group" style="flex:1">
                    <label>Carica file (JPG, PNG, WEBP, SVG — max 2 MB)</label>
                    <input type="file" name="logo" accept="image/*" required style="padding:0.35rem">
                </div>
                <button type="submit" class="btn btn-primary" style="align-self:end">Carica</button>
            </form>

            <!-- URL esterno -->
            <form method="POST" class="form-inline" style="flex:1;min-width:220px">
                <input type="hidden" name="action" value="save_logo_url">
                <div class="form-group" style="flex:1">
                    <label>Oppure inserisci URL immagine</label>
                    <input type="url" name="logo_url" placeholder="https://..." value="<?= htmlspecialchars($logoUrlSaved) ?>">
                </div>
                <button type="submit" class="btn btn-primary" style="align-self:end">Salva URL</button>
            </form>
        </div>

        <?php if ($logoUrl): ?>
        <form method="POST" style="margin-top:0.75rem" onsubmit="return confirm('Rimuovere il logo?')">
            <input type="hidden" name="action" value="delete_logo">
            <button type="submit" class="btn btn-danger btn-sm">Rimuovi logo</button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Impostazioni Plesk -->
    <div class="card">
        <h2>Configurazione Plesk DNS</h2>
        <form method="POST">
            <input type="hidden" name="action" value="save_plesk_settings">
            <div class="form-inline" style="align-items:end;flex-wrap:wrap">
                <div class="form-group" style="min-width:240px">
                    <label>Host Plesk</label>
                    <input type="url" name="plesk_host" value="<?= htmlspecialchars($pleskSettings['host'] ?? '') ?>" placeholder="https://plesk.gvweb.it:8443" required>
                </div>
                <div class="form-group" style="min-width:160px">
                    <label>Utente</label>
                    <input type="text" name="plesk_user" value="<?= htmlspecialchars($pleskSettings['user'] ?? '') ?>" required>
                </div>
                <div class="form-group" style="min-width:180px">
                    <label>Password <?= $pleskSettings['has_password'] ? '(salvata)' : '' ?></label>
                    <input type="password" name="plesk_password" placeholder="<?= $pleskSettings['has_password'] ? 'lascia vuoto = invariata' : '' ?>">
                </div>
            </div>
            <div class="form-inline" style="align-items:end;flex-wrap:wrap;margin-top:1rem">
                <div class="form-group" style="min-width:220px">
                    <label>Dominio Plesk</label>
                    <input type="text" name="plesk_domain" value="<?= htmlspecialchars($pleskSettings['domain'] ?? '') ?>" placeholder="ddns.gvweb.it" required>
                </div>
                <div class="form-group" style="min-width:120px;max-width:160px">
                    <label>Site ID</label>
                    <input type="number" name="plesk_site_id" value="<?= htmlspecialchars($pleskSettings['site_id'] ?? '') ?>" placeholder="9" min="1">
                </div>
                <div class="form-group" style="flex:0;min-width:150px">
                    <label><input type="checkbox" name="plesk_verify_ssl" <?= ($pleskSettings['verify_ssl'] ?? '0') === '1' ? 'checked' : '' ?>> Verifica SSL</label>
                </div>
                <button type="submit" class="btn btn-primary">Salva Plesk</button>
            </div>
            <p class="text-muted mt-1">Se Plesk non trova il dominio automaticamente, imposta il Site ID manuale. Per ddns.gvweb.it dovrebbe essere 9.</p>
        </form>
    </div>

    <!-- Impostazioni Brute Force -->
    <div class="card">
        <h2>Protezione Brute Force</h2>
        <form method="POST" class="form-inline">
            <input type="hidden" name="action" value="save_settings">
            <div class="form-group">
                <label>Max tentativi falliti</label>
                <input type="number" name="bf_max_attempts" value="<?= (int)($bfSettings['bf_max_attempts'] ?? 5) ?>" min="1" style="width:80px">
            </div>
            <div class="form-group">
                <label>Finestra (minuti)</label>
                <input type="number" name="bf_window_min" value="<?= (int)($bfSettings['bf_window_min'] ?? 10) ?>" min="1" style="width:80px">
            </div>
            <div class="form-group">
                <label>Blocco (minuti)</label>
                <input type="number" name="bf_lockout_min" value="<?= (int)($bfSettings['bf_lockout_min'] ?? 15) ?>" min="1" style="width:80px">
            </div>
            <button type="submit" class="btn btn-primary" style="align-self:end">Salva</button>
        </form>
    </div>

    <!-- Export database -->
    <div class="card">
        <h2>Backup database</h2>
        <p class="text-muted mb-1">Scarica uno snapshot SQLite consistente del database applicativo.</p>
        <form method="POST" onsubmit="return confirm('Esportare il database? Il file contiene utenti, token API e log.')">
            <input type="hidden" name="action" value="export_db">
            <button type="submit" class="btn btn-primary">Esporta database</button>
        </form>
    </div>

    </div><!-- /tab-impostazioni -->

    <!-- TAB: Log -->
    <div class="tab-panel" id="tab-log">
    <!-- Log login -->
    <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem">
            <h2 style="margin:0">Log accessi <?php if ($logTotal > 0): ?><span style="font-size:0.8rem;color:#64748b;font-weight:400">(<?= $logTotal ?> totali)</span><?php endif; ?></h2>
            <?php if ($logTotal > 0): ?>
            <form method="POST" onsubmit="return confirm('Svuotare tutto il log accessi?')">
                <input type="hidden" name="action" value="clear_login_log">
                <button class="btn btn-danger btn-sm">Svuota log</button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (empty($loginLogs)): ?>
            <p class="text-muted">Nessun accesso registrato.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Utente</th><th>IP</th><th>Esito</th><th>Falliti recenti</th><th>Data</th><th></th></tr>
                </thead>
                <tbody>
                    <?php $shownUnblock = []; foreach ($loginLogs as $ll):
                        $f = (int)$ll['recent_failures'];
                        $blocked = $f >= $bfMax;
                        $showBtn = $blocked && !isset($shownUnblock[$ll['ip']]);
                        if ($showBtn) $shownUnblock[$ll['ip']] = true;
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($ll['username']) ?></td>
                        <td class="text-muted"><?= htmlspecialchars($ll['ip']) ?></td>
                        <td>
                            <?php if ($ll['success']): ?>
                                <span style="color:#86efac">✓ Successo</span>
                            <?php else: ?>
                                <span style="color:#fca5a5">✗ Fallito</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="color:<?= $blocked ? '#f87171' : ($f >= 3 ? '#fb923c' : '#64748b') ?>">
                                <?= $f ?><?= $blocked ? ' 🔒' : '' ?>
                            </span>
                        </td>
                        <td class="text-muted"><?= date('d/m/Y H:i:s', strtotime($ll['logged_at'])) ?></td>
                        <td>
                            <?php if ($showBtn): ?>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="unblock_ip">
                                <input type="hidden" name="ip" value="<?= htmlspecialchars($ll['ip']) ?>">
                                <button class="btn btn-sm btn-success">Sblocca</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($logPages > 1): ?>
            <div class="pagination">
                <?php if ($logPage > 1): ?>
                    <a href="?log_page=1">«</a>
                    <a href="?log_page=<?= $logPage - 1 ?>">‹</a>
                <?php endif; ?>
                <?php
                $start = max(1, $logPage - 2);
                $end   = min($logPages, $logPage + 2);
                if ($start > 1) echo '<span class="dots">…</span>';
                for ($p = $start; $p <= $end; $p++):
                ?>
                    <?php if ($p === $logPage): ?>
                        <span class="current"><?= $p ?></span>
                    <?php else: ?>
                        <a href="?log_page=<?= $p ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($end < $logPages) echo '<span class="dots">…</span>'; ?>
                <?php if ($logPage < $logPages): ?>
                    <a href="?log_page=<?= $logPage + 1 ?>">›</a>
                    <a href="?log_page=<?= $logPages ?>">»</a>
                <?php endif; ?>
                <span style="color:#475569;font-size:0.75rem;margin-left:0.5rem">pag. <?= $logPage ?>/<?= $logPages ?></span>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Log aggiornamenti -->
    <div class="card">
        <h2>Ultimi aggiornamenti DNS</h2>
        <?php if (empty($recentLogs)): ?>
            <p class="text-muted">Nessun aggiornamento registrato.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Host</th><th>IP precedente</th><th>Nuovo IP</th><th>Tipo</th><th>Sorgente</th><th>Data</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['hostname'] . '.' . $log['zone']) ?></td>
                        <td><span class="ip-badge"><?= htmlspecialchars($log['old_ip'] ?: '-') ?></span></td>
                        <td><span class="ip-badge"><?= htmlspecialchars($log['new_ip']) ?></span></td>
                        <td><?= htmlspecialchars($log['source_type'] ?: '-') ?></td>
                        <td class="text-muted"><?= htmlspecialchars($log['source_ip']) ?></td>
                        <td class="text-muted"><?= date('d/m/Y H:i:s', strtotime($log['updated_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Log Plesk -->
    <div class="card">
        <h2>Log Plesk DNS</h2>
        <?php if (empty($pleskLogs)): ?>
            <p class="text-muted">Nessuna operazione Plesk registrata.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Data</th><th>Azione</th><th>Host</th><th>IP</th><th>Esito</th><th>Messaggio</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($pleskLogs as $pl): ?>
                    <tr>
                        <td class="text-muted"><?= date('d/m/Y H:i:s', strtotime($pl['created_at'])) ?></td>
                        <td><?= htmlspecialchars($pl['action']) ?></td>
                        <td><?= htmlspecialchars(($pl['hostname'] ?: '-') . ($pl['zone'] ? '.' . $pl['zone'] : '')) ?></td>
                        <td><?= $pl['ip_address'] ? '<span class="ip-badge">' . htmlspecialchars($pl['ip_address']) . '</span>' : '<span class="text-muted">-</span>' ?></td>
                        <td>
                            <?php if ((int)$pl['success'] === 1): ?>
                                <span style="color:#86efac">OK</span>
                            <?php else: ?>
                                <span style="color:#fca5a5">Errore</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?= htmlspecialchars($pl['message'] ?: '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    </div><!-- /tab-log -->

</div><!-- /container -->
<footer style="text-align:center;padding:1rem 0 1.5rem;color:#475569;font-size:0.75rem;border-top:1px solid #1e293b;margin-top:2rem">
    <?= APP_NAME ?> v<?= APP_VERSION ?> — build <?= APP_BUILD ?>
</footer>
<script src="theme.js"></script>
<script>
(function(){
    var tabs   = document.querySelectorAll('.tab-btn');
    var panels = document.querySelectorAll('.tab-panel');
    var saved  = location.hash.replace('#','') || localStorage.getItem('adminTab') || 'domini';

    function activate(name) {
        tabs.forEach(function(b){ b.classList.toggle('active', b.dataset.tab === name); });
        panels.forEach(function(p){ p.classList.toggle('active', p.id === 'tab-' + name); });
        localStorage.setItem('adminTab', name);
        history.replaceState(null,'','#' + name);
    }

    tabs.forEach(function(b){
        b.addEventListener('click', function(){ activate(b.dataset.tab); });
    });

    // Attiva il tab corretto (da hash, localStorage o default)
    var valid = Array.from(tabs).map(function(b){ return b.dataset.tab; });
    activate(valid.includes(saved) ? saved : 'domini');

    // Se c'è un messaggio di feedback, mostra il tab giusto in base all'azione POST
    <?php if ($msg && $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
    var action = <?= json_encode($_POST['action'] ?? '') ?>;
    var map = {
        add_domain:'domini', delete_domain:'domini',
        add_user:'utenti', edit_user:'utenti', delete_user:'utenti', toggle_user:'utenti',
        upload_logo:'impostazioni', save_logo_url:'impostazioni', delete_logo:'impostazioni',
        save_settings:'impostazioni', save_plesk_settings:'impostazioni', export_db:'impostazioni',
        unblock_ip:'log', clear_login_log:'log'
    };
    if (map[action]) activate(map[action]);
    <?php endif; ?>
})();
</script>
</body>
</html>
