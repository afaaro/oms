<?php

declare(strict_types=1);

/**
 * Ensure every Integration Catalog provider exists as a channel for tenant 1.
 */
require dirname(__DIR__) . '/bootstrap/autoload.php';

$cfg = require dirname(__DIR__) . '/config/database.php';
$db = new App\Core\Database($cfg);

$providers = App\Store\IntegrationCatalog::providers();
$tenants = $db->query('SELECT id FROM store_tenants ORDER BY id')->rows;
if ($tenants === []) {
    $tenants = [['id' => 1]];
}

foreach ($tenants as $t) {
    $tid = (int) $t['id'];
    foreach ($providers as $p) {
        $code = $p['key'];
        // Couriers already seeded with *_courier codes in some installs — keep canonical key
        $exists = $db->query(
            'SELECT id FROM store_channels WHERE tenant_id = ? AND (code = ? OR code = ?) LIMIT 1',
            [$tid, $code, $code . '_courier']
        )->row;

        $settings = json_encode([
            'provider'          => $code,
            'linked'            => '0',
            'connection_status' => 'unlinked',
            'auth_mode'         => in_array($code, ['ebay', 'amazon', 'etsy', 'tiktok', 'shopify', 'amazon_buy_shipping'], true) ? 'oauth' : 'credentials',
            'import_orders'     => $p['type'] === 'courier' ? '0' : '1',
            'send_despatch'     => '1',
            'auto_inventory'    => $p['type'] === 'courier' ? '0' : '1',
            'currency'          => 'GBP',
        ], JSON_UNESCAPED_UNICODE);

        $active = $p['type'] === 'courier' ? 0 : 1;

        if ($exists === []) {
            $db->query(
                'INSERT INTO store_channels (tenant_id, code, name, type, active, settings) VALUES (?,?,?,?,?,?)',
                [$tid, $code, $p['label'], $p['type'] === 'manual' ? 'manual' : $p['type'], $active, $settings]
            );
            echo "created {$code} tenant={$tid}\n";
        } else {
            $db->query(
                'UPDATE store_channels SET name=?, type=?, settings=COALESCE(NULLIF(settings,\'\'), ?) WHERE id=?',
                [$p['label'], $p['type'] === 'manual' ? 'manual' : $p['type'], $settings, $exists['id']]
            );
            echo "ensured {$code} tenant={$tid} id={$exists['id']}\n";
        }
    }
}

echo "Done. Providers: " . count($providers) . "\n";
