<?php

declare(strict_types=1);

/**
 * Pull marketplace orders for one or all tenants.
 *
 * Usage:
 *   php bin/sync_orders.php [--tenant=ID] [--days=7|all] [--channel=ID]
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Integrations\OrderSync;

$base = dirname(__DIR__);
$app = new App($base);

$tenantId = 0;
$daysRaw = '7';
$channelId = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    } elseif (str_starts_with($arg, '--days=')) {
        $daysRaw = substr($arg, 7);
    } elseif (str_starts_with($arg, '--channel=')) {
        $channelId = (int) substr($arg, 10);
    }
}

if ($tenantId > 0) {
    $tenants = [['id' => $tenantId]];
} else {
    $tenants = $app->db->query(
        "SELECT id FROM #__store_tenants
         WHERE status IN ('active','open','trial') OR status='' OR status IS NULL
         ORDER BY id ASC"
    )->rows;
}

$isAll = strtolower($daysRaw) === 'all' || $daysRaw === '';
$days = $isAll ? 0 : max(1, min(729, (int) $daysRaw));
$since = $isAll
    ? new DateTimeImmutable('-2 years +1 day', new DateTimeZone('UTC'))
    : new DateTimeImmutable('-' . $days . ' days', new DateTimeZone('UTC'));

$sync = new OrderSync($app);
$exit = 0;

foreach ($tenants as $t) {
    $result = $sync->syncTenant((int) $t['id'], $since, $channelId);
    foreach ($result['lines'] as $line) {
        $isError = str_starts_with($line, '  error:')
            || (str_contains($line, ': ') && !str_contains($line, 'import_orders off') && !str_contains($line, 'read '));
        if ($isError) {
            fwrite(STDERR, $line . "\n");
        } else {
            echo $line . "\n";
        }
    }
    if ($result['errors'] !== []) {
        $exit = 1;
    }
}

exit($exit);
