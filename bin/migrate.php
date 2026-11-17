<?php

declare(strict_types=1);

/**
 * Apply storage/migrations/*.sql to the configured database.
 * Usage:
 *   php bin/migrate.php           # pending only
 *   php bin/migrate.php --all     # re-run all files
 *   php bin/migrate.php --sync    # mark files applied without running SQL
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Support\Migrator;

$base = dirname(__DIR__);
$app = new App($base);
$migrator = new Migrator($app->db, $base);

$args = array_slice($argv, 1);
$mode = 'pending';
if (in_array('--all', $args, true)) {
    $mode = 'all';
} elseif (in_array('--sync', $args, true)) {
    $mode = 'sync';
}

$result = match ($mode) {
    'all'  => $migrator->run(false),
    'sync' => $migrator->syncLog(),
    default => $migrator->run(true),
};

foreach ($result['ran'] as $file) {
    echo 'Applied ' . $file . "\n";
}
foreach ($result['skipped'] as $file) {
    echo 'Skipped ' . $file . " (already applied)\n";
}
foreach ($result['errors'] as $err) {
    fwrite(STDERR, $err . "\n");
}

echo $result['message'] . "\n";
exit($result['ok'] ? 0 : 1);
