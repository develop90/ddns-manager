<?php
require_once __DIR__ . '/config.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $db = getDb();

    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);

    $getSetting = fn($k) => $db->prepare("SELECT value FROM settings WHERE key=?")->execute([$k]) ?
                            (int)$db->prepare("SELECT value FROM settings WHERE key=?")->execute([$k]) : 5;
    $bfMax     = (int)$db->query("SELECT value FROM settings WHERE key='bf_max_attempts'")->fetchColumn();
    $bfWindow  = (int)$db->query("SELECT value FROM settings WHERE key='bf_window_min'")->fetchColumn();
    $bfLockout = (int)$db->query("SELECT value FROM settings WHERE key='bf_lockout_min'")->fetchColumn();

    $stmtBf = $db->prepare("SELECT COUNT(*) as c FROM login_log WHERE ip=? AND success=0 AND logged_at >= datetime('now', ? || ' minutes')");
    $stmtBf->execute([$clientIp, '-' . $bfWindow]);
    $failCount = (int)$stmtBf->fetch()['c'];

    if ($failCount >= $bfMax) {
        $error = "Troppi tentativi falliti. Riprova tra $bfLockout minuti.";
    } else {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $db->prepare("INSERT INTO login_log (username, ip, success) VALUES (?, ?, 1)")
               ->execute([$username, $clientIp]);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $user['is_admin'];
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        } else {
            $db->prepare("INSERT INTO login_log (username, ip, success) VALUES (?, ?, 0)")
               ->execute([$username, $clientIp]);
            $remaining = $bfMax - $failCount - 1;
            $error = 'Credenziali non valide.' . ($remaining > 0 ? " Tentativi rimasti: $remaining." : ' Account bloccato temporaneamente.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= APP_NAME ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-box">
        <h1><?= APP_NAME ?></h1>
        <p class="subtitle">Dynamic DNS Management</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Accedi</button>
        </form>

    </div>
</div>
<footer style="text-align:center;padding:1rem 0 1.5rem;color:#475569;font-size:0.75rem;border-top:1px solid #1e293b;margin-top:2rem">
    <?= APP_NAME ?> v<?= APP_VERSION ?> — build <?= APP_BUILD ?>
</footer>
</body>
</html>
