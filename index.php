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
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    if (strpos($clientIp, ',') !== false) $clientIp = trim(explode(',', $clientIp)[0]);

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
        $error = 'Credenziali non valide.';
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
