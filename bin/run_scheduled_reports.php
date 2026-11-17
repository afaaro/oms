<?php

declare(strict_types=1);

/**
 * Write orders CSVs for scheduled report jobs that are queued or due again.
 *
 * Usage:
 *   php bin/run_scheduled_reports.php [--tenant=ID]
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Store\OrderExportService;

$base = dirname(__DIR__);
$app = new App($base);

$onlyTenant = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $onlyTenant = (int) substr($arg, 9);
    }
}

$params = [];
$tenantSql = '';
if ($onlyTenant > 0) {
    $tenantSql = ' AND tenant_id = ?';
    $params[] = $onlyTenant;
}

$jobs = $app->db->query(
    "SELECT * FROM #__store_report_jobs
     WHERE type = 'scheduled'
       {$tenantSql}
       AND (
         status = 'queued'
         OR (status = 'completed' AND schedule = 'daily' AND completed_at < CURDATE())
         OR (status = 'completed' AND schedule = 'weekly' AND completed_at <= DATE_SUB(NOW(), INTERVAL 7 DAY))
         OR (status = 'completed' AND schedule = 'monthly' AND completed_at <= DATE_SUB(NOW(), INTERVAL 1 MONTH))
       )
     ORDER BY id ASC",
    $params
)->rows;

if ($jobs === []) {
    echo "No scheduled reports due.\n";
    exit(0);
}

$export = new OrderExportService($app);
$ran = 0;
foreach ($jobs as $job) {
    $id = (int) ($job['id'] ?? 0);
    $tenantId = (int) ($job['tenant_id'] ?? 0);
    $name = (string) ($job['name'] ?? 'Scheduled report');
    try {
        $app->tenant->setActive($tenantId);
        $path = $export->write($tenantId);
        $app->db->query(
            "UPDATE #__store_report_jobs
             SET status='completed', file_path=?, completed_at=NOW()
             WHERE id=? AND tenant_id=?",
            [$path, $id, $tenantId]
        );
        $ran++;
        echo "Completed #{$id} {$name} -> {$path}\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "Job #{$id} failed: " . $e->getMessage() . "\n");
    }
}

echo "Ran {$ran} of " . count($jobs) . " due report(s).\n";
exit($ran === count($jobs) ? 0 : 1);
