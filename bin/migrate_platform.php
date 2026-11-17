<?php

declare(strict_types=1);

/**
 * Idempotent platform columns + seed. Run: php bin/migrate_platform.php
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

$base = dirname(__DIR__);
$config = require $base . '/config/database.php';
$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['hostname'] ?? 'localhost',
        (int) ($config['port'] ?? 3306),
        $config['database'] ?? ''
    ),
    $config['username'] ?? 'root',
    $config['password'] ?? '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function addColumn(PDO $pdo, string $table, string $column, string $ddl): void
{
    if (columnExists($pdo, $table, $column)) {
        echo "skip {$table}.{$column}\n";
        return;
    }
    $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$ddl}");
    echo "added {$table}.{$column}\n";
}

addColumn($pdo, 'users', 'is_platform', '`is_platform` tinyint(1) NOT NULL DEFAULT 0 AFTER `status`');
addColumn($pdo, 'store_tenants', 'status', "`status` varchar(32) NOT NULL DEFAULT 'active' AFTER `slug`");
addColumn($pdo, 'store_tenants', 'plan', "`plan` varchar(64) NOT NULL DEFAULT 'standard' AFTER `status`");
addColumn($pdo, 'store_tenants', 'contact_email', "`contact_email` varchar(255) NOT NULL DEFAULT '' AFTER `plan`");
addColumn($pdo, 'store_tenants', 'support_notes', '`support_notes` text NULL AFTER `contact_email`');
addColumn($pdo, 'store_tenants', 'updated_at', '`updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');

$sqlFile = $base . '/storage/migrations/004_platform.sql';
$pdo->exec((string) file_get_contents($sqlFile));
echo "applied 004_platform.sql\n";

addColumn($pdo, 'support_tickets', 'category', "`category` varchar(64) NOT NULL DEFAULT 'general' AFTER `impact`");
addColumn($pdo, 'support_ticket_replies', 'is_internal', '`is_internal` tinyint(1) NOT NULL DEFAULT 0 AFTER `is_staff`');

// Mark first user as platform staff
$pdo->exec('UPDATE users SET is_platform = 1 WHERE id = 1');
echo "user #1 is_platform=1\n";

// Merchant-looking default tenant
$pdo->exec(
    "UPDATE store_tenants
     SET name = 'Duzky Ltd', slug = 'duzky', contact_email = 'info@duzky.com', plan = 'professional', status = 'active'
     WHERE id = 1"
);

// Sample second tenant
$exists = $pdo->query("SELECT id FROM store_tenants WHERE slug = 'acme-demo' LIMIT 1")->fetch();
if (!$exists) {
    $pdo->exec(
        "INSERT INTO store_tenants (name, slug, status, plan, contact_email)
         VALUES ('Acme Retail', 'acme-demo', 'active', 'standard', 'ops@acme.example')"
    );
    echo "created tenant Acme Retail\n";
}

// Sample open ticket
$ticketExists = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets')->fetchColumn();
if ($ticketExists === 0) {
    $pdo->exec(
        "INSERT INTO support_tickets (tenant_id, subject, body, status, priority, impact, created_by_user_id)
         VALUES (1, 'Royal Mail OBA not connecting', 'Labels fail after save. Need OBA authorised.', 'open', 'high', 'Cannot despatch', 1)"
    );
    echo "seeded sample support ticket\n";
}

echo "Done.\n";
