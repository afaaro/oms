<?php

declare(strict_types=1);

/**
 * Create / reset the initial admin user.
 * Usage: php bin/seed_admin.php [email] [password] [name]
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

$base = dirname(__DIR__);
$config = require $base . '/config/database.php';


$email = trim($argv[1] ?? '');
$password = $argv[2] ?? '';
$name = trim($argv[3] ?? 'Admin');

if (
    $email === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $password === ''
    || strlen($password) < 12
) {
    fwrite(
        STDERR,
        "Usage: php bin/seed_admin.php <email> <password> [name]\n"
        . "Password must be at least 12 characters.\n"
    );
    exit(1);
}

$username = strstr($email, '@', true) ?: 'admin';

$host = $config['hostname'] ?? 'localhost';
$port = (int) ($config['port'] ?? 3306);
$dbname = $config['database'] ?? '';
$user = $config['username'] ?? 'root';
$pass = $config['password'] ?? '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4",
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$hash = password_hash($password, PASSWORD_DEFAULT);
$canonical = strtolower($email);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $uid = (int) $existing['id'];
        $pdo->prepare(
            'UPDATE users SET password_hash = ?, display_name = ?, status = ?, email_canonical = ? WHERE id = ?'
        )->execute([$hash, $name, 'active', $canonical, $uid]);
        echo "Updated user #{$uid} ({$email})\n";
    } else {
        $pdo->prepare(
            'INSERT INTO users (email, email_canonical, username, password_hash, display_name, status, email_verified)
             VALUES (?, ?, ?, ?, ?, ?, 1)'
        )->execute([$email, $canonical, $username, $hash, $name, 'active']);
        $uid = (int) $pdo->lastInsertId();
        echo "Created user #{$uid} ({$email})\n";
    }

    // Ensure admin group membership (id 2 = admin, 3 = sadmin)
    foreach ([2, 3] as $gid) {
        $pdo->prepare(
            'INSERT IGNORE INTO user_group_members (user_id, group_id) VALUES (?, ?)'
        )->execute([$uid, $gid]);
    }

    $pdo->prepare(
        'INSERT IGNORE INTO store_tenant_users (tenant_id, user_id, role, flags)
         VALUES (1, ?, ?, ?)'
    )->execute([$uid, 'owner', json_encode(['grant_all' => true, 'is_admin' => true])]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "Login: {$email} / {$password}\n";
