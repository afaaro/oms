<?php

declare(strict_types=1);

/**
 * Wipe all OMS / tenant / user data and restore a minimal empty baseline.
 * Keeps schema + system user_groups. Does NOT drop the database.
 *
 * Usage: php bin/clean_data.php
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';
$email = trim($argv[1] ?? '');
$password = $argv[2] ?? '';

if (
    $email === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || strlen($password) < 12
) {
    fwrite(
        STDERR,
        "Usage: php bin/clean_data.php <email> <password>\n"
        . "Password must be at least 12 characters.\n"
    );
    exit(1);
}

$base = dirname(__DIR__);
$app = new App\Core\App($base);
$pdo = $app->db->pdo();

echo "Cleaning storefeeder data…\n";

$tables = [
    'store_order_items',
    'support_ticket_replies',
    'support_tickets',
    'store_buyer_messages',
    'store_api_tokens',
    'store_listings',
    'store_shipping_rules',
    'store_shipping_regions',
    'store_shipping_methods',
    'store_report_jobs',
    'store_dropships',
    'store_returns',
    'store_inventory',
    'store_stock_locations',
    'store_categories',
    'store_purchase_orders',
    'store_suppliers',
    'store_orders',
    'store_products',
    'store_customers',
    'store_channels',
    'store_warehouses',
    'store_tenant_users',
    'store_tenants',
    'user_group_members',
    'users',
];

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $table) {
    $exists = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
    if (!$exists) {
        echo "  skip missing {$table}\n";
        continue;
    }
    $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
    echo "  truncated {$table}\n";
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// Ensure system groups exist
$pdo->exec(
    "INSERT INTO user_groups (id, name, label, description, is_system, level, permissions) VALUES
      (1, 'member', 'Member', 'Default member', 1, -101, JSON_ARRAY()),
      (2, 'admin', 'Admin', 'Store admin', 1, -102, JSON_ARRAY('*')),
      (3, 'sadmin', 'Super Admin', 'Full access', 1, -103, JSON_ARRAY('*'))
     ON DUPLICATE KEY UPDATE label=VALUES(label)"
);

// Minimal tenant
$pdo->exec(
    "INSERT INTO store_tenants (id, name, slug, status, plan, contact_email)
     VALUES (1, 'Default', 'default', 'active', 'standard', 'admin@storefeeder.local')"
);
$pdo->exec(
    "INSERT INTO store_warehouses (tenant_id, name, code, is_default, active)
     VALUES (1, 'Default', 'DEFAULT', 1, 1)"
);

// Platform admin (can open Platform + Open as tenant)

$hash = password_hash($password, PASSWORD_DEFAULT);
$hasPlatform = (bool) $pdo->query("SHOW COLUMNS FROM users LIKE 'is_platform'")->fetch();
if ($hasPlatform) {
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, email_canonical, username, password_hash, display_name, status, email_verified, is_platform)
         VALUES (?, ?, ?, ?, ?, ?, 1, 1)'
    );
    $stmt->execute([$email, strtolower($email), 'admin', $hash, 'Platform Admin', 'active']);
} else {
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, email_canonical, username, password_hash, display_name, status, email_verified)
         VALUES (?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->execute([$email, strtolower($email), 'admin', $hash, 'Platform Admin', 'active']);
}
$uid = (int) $pdo->lastInsertId();

$pdo->prepare(
    'INSERT INTO store_tenant_users (tenant_id, user_id, role, flags) VALUES (1, ?, ?, ?)'
)->execute([$uid, 'owner', json_encode(['grant_all' => true, 'is_admin' => true])]);

// Clear runtime artifacts (keep migrations)
foreach ([
    $base . '/storage/api_demo_token.txt',
] as $file) {
    if (is_file($file)) {
        @unlink($file);
        echo '  removed ' . basename($file) . "\n";
    }
}
$rateDir = $base . '/storage/rate_limit';
if (is_dir($rateDir)) {
    foreach (glob($rateDir . '/*.json') ?: [] as $f) {
        @unlink($f);
    }
    echo "  cleared rate_limit/\n";
}

echo "\nDone. Database is empty except Default tenant.\n";
echo "Login email: {$email}\n";