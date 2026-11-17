<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

$cfg = require dirname(__DIR__) . '/config/database.php';
$db = new App\Core\Database($cfg);

$couriers = [
    ['royal_mail', 'Royal Mail', 'courier'],
    ['parcelforce', 'ParcelForce', 'courier'],
    ['dpd', 'DPD', 'courier'],
    ['evri', 'Evri', 'courier'],
    ['amazon_buy_shipping', 'Amazon Buy Shipping', 'courier'],
];

// Legacy codes from earlier seeds → canonical
$aliases = [
    'dpd_courier' => 'dpd',
    'evri_courier' => 'evri',
    'royal_mail_courier' => 'royal_mail',
];

$tenants = $db->query('SELECT id FROM store_tenants ORDER BY id')->rows;
if ($tenants === []) {
    $tenants = [['id' => 1]];
}

foreach ($tenants as $t) {
    $tid = (int) $t['id'];

    foreach ($aliases as $old => $new) {
        $row = $db->query(
            'SELECT id FROM store_channels WHERE tenant_id = ? AND code = ? LIMIT 1',
            [$tid, $old]
        )->row;
        if ($row === []) {
            continue;
        }
        $target = $db->query(
            'SELECT id FROM store_channels WHERE tenant_id = ? AND code = ? LIMIT 1',
            [$tid, $new]
        )->row;
        if ($target === []) {
            $db->query('UPDATE store_channels SET code = ? WHERE id = ?', [$new, $row['id']]);
            echo "renamed {$old} → {$new} tenant={$tid}\n";
        } else {
            echo "skip rename {$old} (canonical {$new} exists) tenant={$tid}\n";
        }
    }

    foreach ($couriers as [$code, $name, $type]) {
        $exists = $db->query(
            'SELECT id FROM store_channels WHERE tenant_id = ? AND code = ? LIMIT 1',
            [$tid, $code]
        )->row;
        $settings = json_encode([
            'provider'          => $code,
            'linked'            => '0',
            'connection_status' => 'unlinked',
            'auth_mode'         => $code === 'amazon_buy_shipping' ? 'oauth' : 'credentials',
            'import_orders'     => '0',
            'send_despatch'     => '1',
            'auto_inventory'    => '0',
            'currency'          => 'GBP',
        ], JSON_UNESCAPED_UNICODE);

        if ($exists === []) {
            $db->query(
                'INSERT INTO store_channels (tenant_id, code, name, type, active, settings) VALUES (?,?,?,?,0,?)',
                [$tid, $code, $name, $type, $settings]
            );
            echo "created {$code} tenant={$tid}\n";
        } else {
            $db->query(
                'UPDATE store_channels SET name=?, type=?, settings=COALESCE(NULLIF(settings,\'\'), ?) WHERE id=?',
                [$name, $type, $settings, $exists['id']]
            );
            echo "updated {$code} tenant={$tid}\n";
        }
    }
}

echo "Done.\n";
