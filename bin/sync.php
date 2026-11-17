<?php

declare(strict_types=1);

/**
 * Run due order and message syncs for one or all tenants.
 *
 * Usage:
 *   php bin/sync.php [--tenant=ID] [--force]
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Store\SyncScheduler;
use App\Support\Logger;

$base = dirname(__DIR__);
$app = new App($base);
$app->logger = new Logger($base);

$tenantId = 0;
$force = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    } elseif ($arg === '--force') {
        $force = true;
    }
}

$result = (new SyncScheduler($app))->run($tenantId > 0 ? $tenantId : null, 'cli', $force);

if (!empty($result['locked'])) {
    fwrite(STDERR, "already running\n");
    exit(1);
}

$exit = 0;
foreach ($result['tenants'] as $row) {
    $line = sprintf(
        "tenant %d: %s%s\n",
        (int) ($row['tenant_id'] ?? 0),
        (string) ($row['status'] ?? ''),
        isset($row['summary']) && $row['summary'] !== '' ? ' — ' . $row['summary'] : ''
    );
    if (($row['status'] ?? '') === 'error') {
        fwrite(STDERR, $line);
        $exit = 1;
    } else {
        echo $line;
    }
}

exit($exit);
