<?php

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    // Crea tabelle se non esistono
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            is_admin INTEGER DEFAULT 0,
            api_token TEXT UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS domains (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            zone TEXT UNIQUE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS hosts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            hostname TEXT NOT NULL,
            domain_id INTEGER NOT NULL,
            ip_address TEXT DEFAULT '',
            last_update DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (domain_id) REFERENCES domains(id) ON DELETE CASCADE,
            UNIQUE(hostname, domain_id)
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS update_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            host_id INTEGER NOT NULL,
            old_ip TEXT,
            new_ip TEXT,
            source_ip TEXT,
            source_type TEXT DEFAULT '',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (host_id) REFERENCES hosts(id) ON DELETE CASCADE
        )
    ");
    // Migrazione: aggiunge source_type se non esiste (DB già esistente)
    try { $pdo->exec("ALTER TABLE update_log ADD COLUMN source_type TEXT DEFAULT ''"); } catch (PDOException $e) {}

    // Crea admin di default se non esiste
    $stmt = $pdo->query("SELECT COUNT(*) as c FROM users");
    if ($stmt->fetch()['c'] == 0) {
        $hash = password_hash('admin', PASSWORD_BCRYPT);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare("INSERT INTO users (username, password, is_admin, api_token) VALUES (?, ?, 1, ?)")
            ->execute(['admin', $hash, $token]);
    }

    return $pdo;
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        http_response_code(403);
        exit('Accesso negato');
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db = getDb();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function getFullHostname(array $host, PDO $db): string {
    $stmt = $db->prepare("SELECT zone FROM domains WHERE id = ?");
    $stmt->execute([$host['domain_id']]);
    $domain = $stmt->fetch();
    return $host['hostname'] . '.' . ($domain['zone'] ?? 'unknown');
}
