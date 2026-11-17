<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

$cfg = require dirname(__DIR__) . '/config/database.php';
$db = new App\Core\Database($cfg);

$sql = file_get_contents(dirname(__DIR__) . '/storage/migrations/007_shipping_listings.sql');
foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    $db->query($stmt);
}
echo "applied 007_shipping_listings.sql\n";

$tenants = $db->query('SELECT id FROM store_tenants ORDER BY id')->rows;
if ($tenants === []) {
    $tenants = [['id' => 1]];
}

foreach ($tenants as $t) {
    $tid = (int) $t['id'];
    $existing = (int) ($db->query(
        'SELECT COUNT(*) AS c FROM store_shipping_methods WHERE tenant_id = ?',
        [$tid]
    )->row['c'] ?? 0);
    if ($existing === 0) {
        $methods = [
            ['Royal Mail 48', 'royal_mail', '48'],
            ['Royal Mail Tracked 24', 'royal_mail', 'T24'],
            ['DPD Next Day', 'dpd', 'ND'],
            ['Evri Standard', 'evri', 'STD'],
            ['Collection', 'manual', 'COLL'],
            ['Amazon Prime', 'amazon', 'PRIME'],
        ];
        foreach ($methods as [$name, $carrier, $svc]) {
            $db->query(
                'INSERT INTO store_shipping_methods (tenant_id, name, carrier, service_code, active) VALUES (?,?,?,?,1)',
                [$tid, $name, $carrier, $svc]
            );
        }
        echo "seeded shipping methods tenant={$tid}\n";
    }

    $regCount = (int) ($db->query(
        'SELECT COUNT(*) AS c FROM store_shipping_regions WHERE tenant_id = ?',
        [$tid]
    )->row['c'] ?? 0);
    if ($regCount === 0) {
        $regions = [
            ['UK Mainland', 'GB', ''],
            ['Scottish Highlands', 'GB', 'IV,KW,HS,ZE,PH'],
            ['Northern Ireland', 'GB', 'BT'],
            ['EU', 'IE,FR,DE,NL,BE,ES,IT', ''],
        ];
        foreach ($regions as [$name, $countries, $prefixes]) {
            $db->query(
                'INSERT INTO store_shipping_regions (tenant_id, name, countries, postcode_prefixes, active) VALUES (?,?,?,?,1)',
                [$tid, $name, $countries, $prefixes]
            );
        }
        echo "seeded shipping regions tenant={$tid}\n";
    }

    $ruleCount = (int) ($db->query(
        'SELECT COUNT(*) AS c FROM store_shipping_rules WHERE tenant_id = ?',
        [$tid]
    )->row['c'] ?? 0);
    if ($ruleCount === 0) {
        $methods = $db->query(
            'SELECT id, name FROM store_shipping_methods WHERE tenant_id = ? ORDER BY id',
            [$tid]
        )->rows;
        $regions = $db->query(
            'SELECT id, name FROM store_shipping_regions WHERE tenant_id = ? ORDER BY id',
            [$tid]
        )->rows;
        $byName = [];
        foreach ($methods as $m) {
            $byName[(string) $m['name']] = (int) $m['id'];
        }
        $regionByName = [];
        foreach ($regions as $r) {
            $regionByName[(string) $r['name']] = (int) $r['id'];
        }

        $rules = [
            ['Amazon Prime default', 10, null, null, $byName['Amazon Prime'] ?? null, 'Amazon Prime', 0, 0],
            ['UK Mainland RM48', 20, null, $regionByName['UK Mainland'] ?? null, $byName['Royal Mail 48'] ?? null, 'Royal Mail 48', 0, 2000],
            ['UK Tracked heavy', 30, null, $regionByName['UK Mainland'] ?? null, $byName['Royal Mail Tracked 24'] ?? null, '', 2001, 20000],
            ['Highlands DPD', 40, null, $regionByName['Scottish Highlands'] ?? null, $byName['DPD Next Day'] ?? null, '', 0, 30000],
            ['Collection override', 5, null, null, $byName['Collection'] ?? null, 'Collection', 0, 0],
        ];
        foreach ($rules as [$name, $pri, $ch, $reg, $method, $match, $wmin, $wmax]) {
            $db->query(
                'INSERT INTO store_shipping_rules
                 (tenant_id, name, priority, channel_id, region_id, method_id, match_shipping_method, weight_min_g, weight_max_g, active)
                 VALUES (?,?,?,?,?,?,?,?,?,1)',
                [$tid, $name, $pri, $ch, $reg, $method, $match, $wmin, $wmax]
            );
        }
        echo "seeded shipping rules tenant={$tid}\n";
    }

    $listCount = (int) ($db->query(
        'SELECT COUNT(*) AS c FROM store_listings WHERE tenant_id = ?',
        [$tid]
    )->row['c'] ?? 0);
    if ($listCount === 0) {
        $channels = $db->query(
            "SELECT id, code FROM store_channels WHERE tenant_id = ? AND type IN ('marketplace','webstore') ORDER BY id",
            [$tid]
        )->rows;
        $products = $db->query(
            'SELECT id, sku, name, price FROM store_products WHERE tenant_id = ? AND active = 1 ORDER BY id LIMIT 24',
            [$tid]
        )->rows;
        if ($channels !== [] && $products !== []) {
            $n = 0;
            foreach ($channels as $ci => $ch) {
                foreach ($products as $pi => $p) {
                    if ($pi % count($channels) !== $ci % count($channels) && $pi > 8) {
                        continue;
                    }
                    $mapped = $pi % 3 !== 0;
                    $db->query(
                        'INSERT INTO store_listings
                         (tenant_id, channel_id, product_id, external_id, sku, title, price, qty, status, controlled_by_sf, mapped)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                        [
                            $tid,
                            (int) $ch['id'],
                            $mapped ? (int) $p['id'] : null,
                            strtoupper((string) $ch['code']) . '-' . (string) $p['sku'],
                            (string) $p['sku'],
                            (string) $p['name'],
                            (float) $p['price'],
                            random_int(0, 40),
                            'active',
                            1,
                            $mapped ? 1 : 0,
                        ]
                    );
                    $n++;
                }
            }
            echo "seeded {$n} listings tenant={$tid}\n";
        }
    }
}

echo "Done.\n";
