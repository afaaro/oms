<?php

declare(strict_types=1);

/**
 * Retry marketplace fulfillment uploads for shipments stuck in failed/skipped/unsupported.
 *
 * Usage:
 *   php bin/retry_fulfillments.php [--tenant=ID] [--minutes=30] [--limit=100]
 */

require dirname(__DIR__) . '/bootstrap/autoload.php';

use App\Core\App;
use App\Store\MarketplaceFulfillmentService;

$base = dirname(__DIR__);
$app = new App($base);

$tenantId = 0;
$minutes = 30;
$limit = 100;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    } elseif (str_starts_with($arg, '--minutes=')) {
        $minutes = max(0, (int) substr($arg, 10));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(500, (int) substr($arg, 8)));
    }
}

if ($tenantId <= 0) {
    $row = $app->db->query(
        "SELECT id FROM #__store_tenants WHERE status IN ('active','open','trial') OR status='' OR status IS NULL
         ORDER BY id ASC LIMIT 1"
    )->row;
    if ($row === []) {
        $row = $app->db->query('SELECT id FROM #__store_tenants ORDER BY id ASC LIMIT 1')->row;
    }
    if ($row === []) {
        fwrite(STDERR, "No tenant found.\n");
        exit(1);
    }
    $tenantId = (int) $row['id'];
}
$app->tenant->setActive($tenantId);

$params = [$tenantId];
$ageSql = '';
if ($minutes > 0) {
    $ageSql = ' AND s.updated_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)';
    $params[] = $minutes;
}
$params[] = $limit;

$rows = $app->db->query(
    "SELECT s.id AS shipment_id, s.order_id
     FROM #__store_shipments s
     WHERE s.tenant_id=?
       AND s.fulfillment_status IN ('failed','skipped','unsupported')
       AND s.tracking_number <> ''
       {$ageSql}
     ORDER BY s.id ASC
     LIMIT ?",
    $params
)->rows;

if ($rows === []) {
    echo "No shipments to retry for tenant {$tenantId}.\n";
    exit(0);
}

$targets = [];
foreach ($rows as $r) {
    $targets[] = [
        'order_id'    => (int) $r['order_id'],
        'shipment_id' => (int) $r['shipment_id'],
    ];
}

$result = (new MarketplaceFulfillmentService($app))->retryMany($targets);
echo "Tenant {$tenantId}: uploaded={$result['uploaded']} skipped={$result['skipped']} failed={$result['failed']} (of "
    . count($targets) . ")\n";
foreach (array_slice($result['messages'], 0, 20) as $msg) {
    echo '  - ' . $msg . "\n";
}
exit($result['failed'] > 0 ? 2 : 0);
