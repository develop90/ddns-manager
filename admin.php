<?php
require_once __DIR__ . '/config.php';
requireAdmin();

$db = getDb();
$user = getCurrentUser();
$msg = '';
$msgType = '';

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
    <h1><?= APP_NAME ?></h1>
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
    </nav>
</div>

<div class="container">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

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
                    <td><?= $u['is_admin'] ? 'Admin' : 'Utente' ?></td>
                    <td><?= $u['host_count'] ?></td>
                    <td class="text-muted"><?= date('d/m/Y', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div class="actions">
                            <a href="admin.php?edit_user=<?= $u['id'] ?>" class="btn btn-sm" style="background:#7c3aed">Modifica</a>
                            <?php if ($u['id'] !== $user['id']): ?>
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
</div>
<footer style="text-align:center;padding:1rem 0 1.5rem;color:#475569;font-size:0.75rem;border-top:1px solid #1e293b;margin-top:2rem">
    <?= APP_NAME ?> v<?= APP_VERSION ?> — build <?= APP_BUILD ?>
</footer>
</body>
</html>
