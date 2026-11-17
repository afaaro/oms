<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

$cfg = require dirname(__DIR__) . '/config/database.php';
$db = new App\Core\Database($cfg);

$sql = file_get_contents(dirname(__DIR__) . '/storage/migrations/008_api_tokens.sql');
foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    $db->query($stmt);
}
echo "applied 008_api_tokens.sql\n";

$user = $db->query(
    "SELECT id FROM users WHERE email = 'duzky@storefeeder.local' OR email = 'admin@storefeeder.local' ORDER BY id LIMIT 1"
)->row;
if ($user === []) {
    $user = $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->row;
}
if ($user === []) {
    echo "No user to issue token.\n";
    exit(0);
}

$plain = 'sf_' . bin2hex(random_bytes(24));
$hash = hash('sha256', $plain);
$prefix = substr($plain, 0, 10);

$existing = $db->query(
    'SELECT id FROM store_api_tokens WHERE user_id = ? AND name = ? AND revoked_at IS NULL LIMIT 1',
    [(int) $user['id'], 'demo']
)->row;

if ($existing !== []) {
    $db->query(
        'UPDATE store_api_tokens SET token_hash=?, token_prefix=?, tenant_id=1, scopes=?, expires_at=DATE_ADD(NOW(), INTERVAL 30 DAY), revoked_at=NULL WHERE id=?',
        [$hash, $prefix, 'orders:read', (int) $existing['id']]
    );
    echo "rotated demo token for user #{$user['id']}\n";
} else {
    $db->query(
        'INSERT INTO store_api_tokens (tenant_id, user_id, name, token_hash, token_prefix, scopes, expires_at)
         VALUES (1, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))',
        [(int) $user['id'], 'demo', $hash, $prefix, 'orders:read']
    );
    echo "created demo token for user #{$user['id']}\n";
}

file_put_contents(dirname(__DIR__) . '/storage/api_demo_token.txt', $plain . PHP_EOL);
echo "API token (also saved to storage/api_demo_token.txt):\n{$plain}\n";
echo "Done.\n";
