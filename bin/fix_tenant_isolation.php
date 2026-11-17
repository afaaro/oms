<?php

declare(strict_types=1);

/**
 * Repair tenant isolation:
 * - Remove global admin groups from non-platform tenant owners
 * - Report product counts per tenant
 *
 * Usage: php bin/fix_tenant_isolation.php
 *        php bin/fix_tenant_isolation.php --move-products=<fromTenantId>:<toTenantId>
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;

$app = new App(dirname(__DIR__));
$db = $app->db;

$args = array_slice($argv, 1);
$move = null;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--move-products=')) {
        $move = substr($arg, strlen('--move-products='));
    }
}

echo "=== Product counts by tenant ===\n";
$rows = $db->query(
    'SELECT t.id, t.name, COUNT(p.id) AS products
     FROM #__store_tenants t
     LEFT JOIN #__store_products p ON p.tenant_id = t.id
     GROUP BY t.id, t.name
     ORDER BY t.id'
)->rows;
foreach ($rows as $r) {
    echo sprintf("  #%d %s — %d product(s)\n", (int) $r['id'], (string) $r['name'], (int) $r['products']);
}

echo "\n=== Strip global admin groups from non-platform tenant-only users ===\n";
$stripped = $db->query(
    'SELECT u.id, u.email, u.display_name
     FROM #__users u
     INNER JOIN #__user_group_members m ON m.user_id = u.id AND m.group_id IN (2, 3)
     WHERE COALESCE(u.is_platform, 0) = 0
       AND NOT EXISTS (
           SELECT 1 FROM #__store_tenant_users tu
           WHERE tu.user_id = u.id AND tu.tenant_id = 1
       )'
)->rows;

foreach ($stripped as $u) {
    $db->query(
        'DELETE FROM #__user_group_members WHERE user_id = ? AND group_id IN (2, 3)',
        [(int) $u['id']]
    );
    echo '  Removed global admin groups from #' . (int) $u['id'] . ' ' . (string) $u['email'] . "\n";
}
if ($stripped === []) {
    echo "  None needed.\n";
}

if ($move !== null) {
    if (!preg_match('/^(\d+):(\d+)$/', $move, $m)) {
        fwrite(STDERR, "Invalid --move-products=from:to\n");
        exit(1);
    }
    $from = (int) $m[1];
    $to = (int) $m[2];
    if ($from <= 0 || $to <= 0 || $from === $to) {
        fwrite(STDERR, "from and to must be different positive tenant ids\n");
        exit(1);
    }
    $fromOk = $db->query('SELECT id FROM #__store_tenants WHERE id=? LIMIT 1', [$from])->row;
    $toOk = $db->query('SELECT id FROM #__store_tenants WHERE id=? LIMIT 1', [$to])->row;
    if ($fromOk === [] || $toOk === []) {
        fwrite(STDERR, "Unknown tenant id\n");
        exit(1);
    }

    echo "\n=== Move catalog from tenant #{$from} → #{$to} ===\n";
    $tables = [
        'store_products',
        'store_inventory',
        'store_categories',
        'store_suppliers',
        'store_purchase_orders',
        'store_warehouses',
        'store_channels',
        'store_listings',
    ];
    foreach ($tables as $table) {
        try {
            $res = $db->query(
                "UPDATE #__{$table} SET tenant_id = ? WHERE tenant_id = ?",
                [$to, $from]
            );
            echo "  {$table}: updated\n";
        } catch (Throwable $e) {
            echo "  {$table}: skipped (" . $e->getMessage() . ")\n";
        }
    }
}

echo "\nDone. Tenant users must log out and sign in again.\n";
