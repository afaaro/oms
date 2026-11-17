<?php

declare(strict_types=1);

/**
 * Seed demo OMS data for tenants (idempotent by external ids / SKUs).
 * Usage: php bin/seed_dataset.php
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

function upsertChannel(PDO $pdo, int $tenantId, string $code, string $name, string $type): int
{
    $stmt = $pdo->prepare('SELECT id FROM store_channels WHERE tenant_id = ? AND code = ? LIMIT 1');
    $stmt->execute([$tenantId, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pdo->prepare('UPDATE store_channels SET name = ?, type = ?, active = 1 WHERE id = ?')
            ->execute([$name, $type, $row['id']]);
        return (int) $row['id'];
    }
    $pdo->prepare(
        'INSERT INTO store_channels (tenant_id, code, name, type, active) VALUES (?, ?, ?, ?, 1)'
    )->execute([$tenantId, $code, $name, $type]);
    return (int) $pdo->lastInsertId();
}

function ensureWarehouse(PDO $pdo, int $tenantId, string $name, string $code, bool $default = false): int
{
    $stmt = $pdo->prepare('SELECT id FROM store_warehouses WHERE tenant_id = ? AND code = ? LIMIT 1');
    $stmt->execute([$tenantId, $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int) $row['id'];
    }
    $pdo->prepare(
        'INSERT INTO store_warehouses (tenant_id, name, code, is_default, active) VALUES (?, ?, ?, ?, 1)'
    )->execute([$tenantId, $name, $code, $default ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

function upsertProduct(PDO $pdo, int $tenantId, array $p): int
{
    $stmt = $pdo->prepare('SELECT id FROM store_products WHERE sku = ? LIMIT 1');
    $stmt->execute([$p['sku']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $fields = [
        $tenantId,
        $p['sku'],
        $p['name'],
        $p['brand'] ?? '',
        $p['description'] ?? '',
        $p['barcode'] ?? '',
        $p['warehouse'] ?? 'Default',
        $p['price'],
        $p['rrp'] ?? $p['price'],
        $p['cost'] ?? round($p['price'] * 0.45, 2),
        $p['weight_grams'] ?? 250,
        $p['active'] ?? 1,
    ];
    if ($row) {
        $pdo->prepare(
            'UPDATE store_products SET tenant_id=?, name=?, brand=?, description=?, barcode=?, warehouse=?,
             price=?, rrp=?, cost=?, weight_grams=?, active=? WHERE id=?'
        )->execute([
            $tenantId, $p['name'], $p['brand'] ?? '', $p['description'] ?? '', $p['barcode'] ?? '',
            $p['warehouse'] ?? 'Default', $p['price'], $p['rrp'] ?? $p['price'],
            $p['cost'] ?? round($p['price'] * 0.45, 2), $p['weight_grams'] ?? 250, $p['active'] ?? 1,
            $row['id'],
        ]);
        return (int) $row['id'];
    }
    $pdo->prepare(
        'INSERT INTO store_products
         (tenant_id, sku, name, brand, description, barcode, warehouse, price, rrp, cost, weight_grams, active, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'\')'
    )->execute($fields);
    return (int) $pdo->lastInsertId();
}

function upsertCustomer(PDO $pdo, int $tenantId, array $c, ?int $channelId): int
{
    $stmt = $pdo->prepare('SELECT id FROM store_customers WHERE tenant_id = ? AND email = ? LIMIT 1');
    $stmt->execute([$tenantId, $c['email']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $pdo->prepare(
            'UPDATE store_customers SET name=?, phone=?, postcode=?, channel_id=? WHERE id=?'
        )->execute([$c['name'], $c['phone'] ?? '', $c['postcode'] ?? '', $channelId, $row['id']]);
        return (int) $row['id'];
    }
    $pdo->prepare(
        'INSERT INTO store_customers (tenant_id, name, email, phone, postcode, channel_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$tenantId, $c['name'], $c['email'], $c['phone'] ?? '', $c['postcode'] ?? '', $channelId]);
    return (int) $pdo->lastInsertId();
}

function upsertOrder(PDO $pdo, int $tenantId, int $channelId, array $o): int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM store_orders WHERE tenant_id = ? AND external_order_id = ? LIMIT 1'
    );
    $stmt->execute([$tenantId, $o['external_order_id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $addr = json_encode($o['shipping_address'] ?? [
        'line1' => '12 High Street',
        'city' => 'London',
        'postcode' => 'SW1A 1AA',
        'country' => 'GB',
    ], JSON_UNESCAPED_UNICODE);

    $params = [
        $tenantId,
        $channelId,
        $o['external_order_id'],
        $o['status'],
        $o['buyer_username'] ?? '',
        $o['buyer_email'],
        $o['total'],
        $o['shipping_cost'] ?? 0,
        $o['currency'] ?? 'GBP',
        $o['shipping_name'] ?? '',
        $addr,
        $o['shipping_method'] ?? 'Royal Mail 48',
        $o['warehouse'] ?? 'Default',
        $o['is_priority'] ?? 0,
        $o['on_hold'] ?? 0,
        $o['created_at'] ?? date('Y-m-d H:i:s'),
        $o['shipped_at'] ?? null,
        $o['tracking_number'] ?? '',
        $o['carrier_code'] ?? '',
    ];

    if ($row) {
        $pdo->prepare(
            'UPDATE store_orders SET channel_id=?, status=?, buyer_username=?, buyer_email=?, total=?,
             shipping_cost=?, currency=?, shipping_name=?, shipping_address=?, shipping_method=?,
             warehouse=?, is_priority=?, on_hold=?, created_at=?, shipped_at=?, tracking_number=?, carrier_code=?
             WHERE id=?'
        )->execute([
            $channelId, $o['status'], $o['buyer_username'] ?? '', $o['buyer_email'], $o['total'],
            $o['shipping_cost'] ?? 0, $o['currency'] ?? 'GBP', $o['shipping_name'] ?? '', $addr,
            $o['shipping_method'] ?? 'Royal Mail 48', $o['warehouse'] ?? 'Default',
            $o['is_priority'] ?? 0, $o['on_hold'] ?? 0, $o['created_at'] ?? date('Y-m-d H:i:s'),
            $o['shipped_at'] ?? null, $o['tracking_number'] ?? '', $o['carrier_code'] ?? '',
            $row['id'],
        ]);
        return (int) $row['id'];
    }

    $pdo->prepare(
        'INSERT INTO store_orders
         (tenant_id, channel_id, external_order_id, status, buyer_username, buyer_email, total,
          shipping_cost, currency, shipping_name, shipping_address, shipping_method, warehouse,
          is_priority, on_hold, created_at, shipped_at, tracking_number, carrier_code)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute($params);
    return (int) $pdo->lastInsertId();
}

$pdo->beginTransaction();
try {
    // Ensure platform tenants look right
    $pdo->exec(
        "UPDATE store_tenants SET name='Duzky Ltd', slug='duzky', contact_email='info@duzky.com',
         plan='professional', status='active' WHERE id=1"
    );
    $acme = $pdo->query("SELECT id FROM store_tenants WHERE slug='acme-demo' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$acme) {
        $pdo->exec(
            "INSERT INTO store_tenants (name, slug, status, plan, contact_email)
             VALUES ('Acme Retail', 'acme-demo', 'active', 'standard', 'ops@acme.example')"
        );
        $acmeId = (int) $pdo->lastInsertId();
    } else {
        $acmeId = (int) $acme['id'];
    }

    // Link staff user to Duzky as owner (impersonation still works without membership)
    $uid = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
    if ($uid > 0) {
        $pdo->prepare(
            'INSERT IGNORE INTO store_tenant_users (tenant_id, user_id, role, flags)
             VALUES (1, ?, \'owner\', ?)'
        )->execute([$uid, json_encode(['grant_all' => true, 'is_admin' => true])]);
    }

    // --- Duzky (tenant 1) ---
    ensureWarehouse($pdo, 1, 'Default', 'DEFAULT', true);
    ensureWarehouse($pdo, 1, 'Birmingham FC', 'BHX', false);

    $chEbay = upsertChannel($pdo, 1, 'ebay-uk', 'eBay UK', 'marketplace');
    $chAmz = upsertChannel($pdo, 1, 'amazon-uk', 'Amazon UK', 'marketplace');
    $chShop = upsertChannel($pdo, 1, 'shopify', 'Shopify Store', 'webstore');
    $chMoto = upsertChannel($pdo, 1, 'moto', 'MOTO / Phone', 'manual');

    $duzkyProducts = [
        ['sku' => 'DZ-TEA-500', 'name' => 'English Breakfast Tea 500g', 'brand' => 'Duzky', 'price' => 8.99, 'rrp' => 9.99, 'barcode' => '5050123456001', 'weight_grams' => 520],
        ['sku' => 'DZ-MUG-01', 'name' => 'Ceramic Mug — Navy', 'brand' => 'Duzky Home', 'price' => 6.50, 'rrp' => 7.50, 'barcode' => '5050123456002', 'weight_grams' => 380],
        ['sku' => 'DZ-KIT-BREW', 'name' => 'Brew Starter Kit', 'brand' => 'Duzky', 'price' => 24.99, 'rrp' => 29.99, 'barcode' => '5050123456003', 'weight_grams' => 1100],
        ['sku' => 'DZ-JAM-340', 'name' => 'Strawberry Jam 340g', 'brand' => 'Duzky Pantry', 'price' => 4.25, 'rrp' => 4.75, 'barcode' => '5050123456004', 'weight_grams' => 450],
        ['sku' => 'DZ-HONEY-454', 'name' => 'Clear Honey 454g', 'brand' => 'Duzky Pantry', 'price' => 5.95, 'rrp' => 6.50, 'barcode' => '5050123456005', 'weight_grams' => 500],
        ['sku' => 'DZ-TOWEL-SET', 'name' => 'Tea Towel Set (3)', 'brand' => 'Duzky Home', 'price' => 12.00, 'rrp' => 14.00, 'barcode' => '5050123456006', 'weight_grams' => 320],
        ['sku' => 'DZ-COFFEE-1KG', 'name' => 'House Blend Coffee 1kg', 'brand' => 'Duzky', 'price' => 18.50, 'rrp' => 21.00, 'barcode' => '5050123456007', 'weight_grams' => 1050],
        ['sku' => 'DZ-GIFT-BOX', 'name' => 'Afternoon Tea Gift Box', 'brand' => 'Duzky', 'price' => 34.99, 'rrp' => 39.99, 'barcode' => '5050123456008', 'weight_grams' => 1800],
        ['sku' => 'DZ-DISC-OLD', 'name' => 'Discontinued Sampler Pack', 'brand' => 'Duzky', 'price' => 3.00, 'rrp' => 5.00, 'active' => 0, 'barcode' => '5050123456009'],
        ['sku' => 'DZ-BISCUIT-200', 'name' => 'Shortbread Biscuits 200g', 'brand' => 'Duzky Pantry', 'price' => 3.75, 'rrp' => 4.25, 'barcode' => '5050123456010', 'weight_grams' => 220],
    ];
    foreach ($duzkyProducts as $p) {
        upsertProduct($pdo, 1, $p);
    }

    $customers = [
        ['name' => 'Sarah Mitchell', 'email' => 'sarah.mitchell@example.com', 'phone' => '07700 900111', 'postcode' => 'M1 1AE', 'ch' => $chEbay],
        ['name' => 'James Patel', 'email' => 'james.patel@example.com', 'phone' => '07700 900222', 'postcode' => 'B1 1BB', 'ch' => $chAmz],
        ['name' => 'Emily Hughes', 'email' => 'emily.hughes@example.com', 'phone' => '07700 900333', 'postcode' => 'EH1 1YZ', 'ch' => $chShop],
        ['name' => 'Oliver Wright', 'email' => 'oliver.wright@example.com', 'phone' => '07700 900444', 'postcode' => 'BS1 4DJ', 'ch' => $chEbay],
        ['name' => 'Chloe Bennett', 'email' => 'chloe.bennett@example.com', 'phone' => '07700 900555', 'postcode' => 'LS1 4DY', 'ch' => $chAmz],
        ['name' => 'Daniel Okafor', 'email' => 'daniel.okafor@example.com', 'phone' => '07700 900666', 'postcode' => 'SE1 9SG', 'ch' => $chShop],
        ['name' => 'Hannah Price', 'email' => 'hannah.price@example.com', 'phone' => '0161 496 0100', 'postcode' => 'SK1 1AA', 'ch' => $chMoto],
        ['name' => 'Tom Richards', 'email' => 'tom.richards@example.com', 'phone' => '07700 900777', 'postcode' => 'CF10 1EP', 'ch' => $chEbay],
    ];
    foreach ($customers as $c) {
        upsertCustomer($pdo, 1, $c, $c['ch']);
    }

    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/London'));
    $orders = [
        [
            'external_order_id' => 'EB-104821',
            'channel_id' => $chEbay,
            'status' => 'awaiting_despatch',
            'buyer_username' => 'sarah_m_uk',
            'buyer_email' => 'sarah.mitchell@example.com',
            'shipping_name' => 'Sarah Mitchell',
            'total' => 17.48,
            'shipping_cost' => 2.50,
            'shipping_method' => 'Royal Mail 48',
            'is_priority' => 0,
            'created_at' => $now->modify('-2 hours')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '18 Deansgate', 'city' => 'Manchester', 'postcode' => 'M1 1AE', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'AMZ-8823411',
            'channel_id' => $chAmz,
            'status' => 'processing',
            'buyer_username' => 'jpatel82',
            'buyer_email' => 'james.patel@example.com',
            'shipping_name' => 'James Patel',
            'total' => 43.49,
            'shipping_cost' => 0.00,
            'shipping_method' => 'Amazon Prime',
            'is_priority' => 1,
            'created_at' => $now->modify('-5 hours')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '4 Bull Street', 'city' => 'Birmingham', 'postcode' => 'B1 1BB', 'country' => 'GB'],
            'warehouse' => 'Birmingham FC',
        ],
        [
            'external_order_id' => 'SH-90012',
            'channel_id' => $chShop,
            'status' => 'printed',
            'buyer_username' => '',
            'buyer_email' => 'emily.hughes@example.com',
            'shipping_name' => 'Emily Hughes',
            'total' => 29.99,
            'shipping_cost' => 3.95,
            'shipping_method' => 'DPD Next Day',
            'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '22 Princes Street', 'city' => 'Edinburgh', 'postcode' => 'EH1 1YZ', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'EB-104790',
            'channel_id' => $chEbay,
            'status' => 'despatched',
            'buyer_username' => 'oliwright',
            'buyer_email' => 'oliver.wright@example.com',
            'shipping_name' => 'Oliver Wright',
            'total' => 12.00,
            'shipping_cost' => 2.95,
            'shipping_method' => 'Royal Mail Tracked 24',
            'tracking_number' => 'JD0002245689012GB',
            'carrier_code' => 'royal_mail',
            'created_at' => $now->modify('-2 days')->format('Y-m-d H:i:s'),
            'shipped_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '9 Park Street', 'city' => 'Bristol', 'postcode' => 'BS1 4DJ', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'AMZ-8819002',
            'channel_id' => $chAmz,
            'status' => 'awaiting_despatch',
            'buyer_username' => 'cbennett',
            'buyer_email' => 'chloe.bennett@example.com',
            'shipping_name' => 'Chloe Bennett',
            'total' => 8.99,
            'shipping_cost' => 0.00,
            'shipping_method' => 'Amazon Logistics',
            'created_at' => $now->modify('-3 hours')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '11 Boar Lane', 'city' => 'Leeds', 'postcode' => 'LS1 4DY', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'SH-89988',
            'channel_id' => $chShop,
            'status' => 'on_hold',
            'buyer_username' => '',
            'buyer_email' => 'daniel.okafor@example.com',
            'shipping_name' => 'Daniel Okafor',
            'total' => 53.48,
            'shipping_cost' => 4.99,
            'shipping_method' => 'Evri Standard',
            'on_hold' => 1,
            'created_at' => $now->modify('-6 hours')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '1 London Bridge', 'city' => 'London', 'postcode' => 'SE1 9SG', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'MOTO-441',
            'channel_id' => $chMoto,
            'status' => 'awaiting_payment',
            'buyer_username' => '',
            'buyer_email' => 'hannah.price@example.com',
            'shipping_name' => 'Hannah Price',
            'total' => 34.99,
            'shipping_cost' => 0.00,
            'shipping_method' => 'Collection',
            'created_at' => $now->modify('-30 minutes')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => 'Warehouse Collection', 'city' => 'Stockport', 'postcode' => 'SK1 1AA', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'EB-104650',
            'channel_id' => $chEbay,
            'status' => 'cancelled',
            'buyer_username' => 'tomr_wales',
            'buyer_email' => 'tom.richards@example.com',
            'shipping_name' => 'Tom Richards',
            'total' => 6.50,
            'shipping_cost' => 2.50,
            'shipping_method' => 'Royal Mail 48',
            'created_at' => $now->modify('-4 days')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '3 Queen Street', 'city' => 'Cardiff', 'postcode' => 'CF10 1EP', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'AMZ-8801120',
            'channel_id' => $chAmz,
            'status' => 'despatched',
            'buyer_username' => 'jpatel82',
            'buyer_email' => 'james.patel@example.com',
            'shipping_name' => 'James Patel',
            'total' => 24.99,
            'shipping_cost' => 0.00,
            'shipping_method' => 'Amazon Prime',
            'tracking_number' => 'TBA3099281771',
            'carrier_code' => 'amazon',
            'created_at' => $now->modify('-5 days')->format('Y-m-d H:i:s'),
            'shipped_at' => $now->modify('-4 days')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '4 Bull Street', 'city' => 'Birmingham', 'postcode' => 'B1 1BB', 'country' => 'GB'],
            'warehouse' => 'Birmingham FC',
        ],
        [
            'external_order_id' => 'SH-90101',
            'channel_id' => $chShop,
            'status' => 'awaiting_despatch',
            'buyer_username' => '',
            'buyer_email' => 'emily.hughes@example.com',
            'shipping_name' => 'Emily Hughes',
            'total' => 15.70,
            'shipping_cost' => 2.95,
            'shipping_method' => 'Royal Mail Tracked 48',
            'is_priority' => 1,
            'created_at' => $now->modify('-45 minutes')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '22 Princes Street', 'city' => 'Edinburgh', 'postcode' => 'EH1 1YZ', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'EB-104900',
            'channel_id' => $chEbay,
            'status' => 'refunded',
            'buyer_username' => 'sarah_m_uk',
            'buyer_email' => 'sarah.mitchell@example.com',
            'shipping_name' => 'Sarah Mitchell',
            'total' => 8.99,
            'shipping_cost' => 2.50,
            'shipping_method' => 'Royal Mail 48',
            'created_at' => $now->modify('-8 days')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '18 Deansgate', 'city' => 'Manchester', 'postcode' => 'M1 1AE', 'country' => 'GB'],
        ],
        [
            'external_order_id' => 'AMZ-8830001',
            'channel_id' => $chAmz,
            'status' => 'processing',
            'buyer_username' => 'cbennett',
            'buyer_email' => 'chloe.bennett@example.com',
            'shipping_name' => 'Chloe Bennett',
            'total' => 39.49,
            'shipping_cost' => 0.00,
            'shipping_method' => 'Amazon Prime',
            'is_priority' => 1,
            'created_at' => $now->modify('-90 minutes')->format('Y-m-d H:i:s'),
            'shipping_address' => ['line1' => '11 Boar Lane', 'city' => 'Leeds', 'postcode' => 'LS1 4DY', 'country' => 'GB'],
        ],
    ];

    foreach ($orders as $o) {
        $cid = (int) $o['channel_id'];
        unset($o['channel_id']);
        upsertOrder($pdo, 1, $cid, $o);
    }

    // Extra support tickets for Duzky
    $ticketCount = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets WHERE tenant_id = 1')->fetchColumn();
    if ($ticketCount < 3) {
        $pdo->exec(
            "INSERT INTO support_tickets (tenant_id, subject, body, status, priority, category, impact, created_by_user_id)
             VALUES
             (1, 'Amazon feed stuck on reconcile', 'SKU DZ-COFFEE-1KG not mapping after price update.', 'open', 'normal', 'integrations', 'Channel sync delayed', {$uid}),
             (1, 'Need extra warehouse user seats', 'Hiring 2 pickers next week — need logins for BHX.', 'pending', 'low', 'onboarding', '', {$uid})"
        );
    }

    // --- Acme (lighter set) ---
    ensureWarehouse($pdo, $acmeId, 'Default', 'DEFAULT', true);
    $acmeEbay = upsertChannel($pdo, $acmeId, 'ebay-uk', 'eBay UK', 'marketplace');
    $acmeWeb = upsertChannel($pdo, $acmeId, 'website', 'Acme Website', 'webstore');

    foreach ([
        ['sku' => 'ACME-BOLT-M8', 'name' => 'M8 Hex Bolt Pack (50)', 'brand' => 'Acme', 'price' => 9.50, 'weight_grams' => 600],
        ['sku' => 'ACME-WASHER-M8', 'name' => 'M8 Washer Pack (100)', 'brand' => 'Acme', 'price' => 4.20, 'weight_grams' => 200],
        ['sku' => 'ACME-TOOL-KIT', 'name' => 'Basic Hand Tool Kit', 'brand' => 'Acme Pro', 'price' => 49.99, 'weight_grams' => 3200],
        ['sku' => 'ACME-GLOVE-L', 'name' => 'Work Gloves Large', 'brand' => 'Acme', 'price' => 7.99, 'weight_grams' => 180],
    ] as $p) {
        upsertProduct($pdo, $acmeId, $p);
    }

    upsertCustomer($pdo, $acmeId, [
        'name' => 'BuildRight Supplies',
        'email' => 'orders@buildright.example',
        'phone' => '0121 496 0001',
        'postcode' => 'B5 4DY',
    ], $acmeWeb);

    upsertCustomer($pdo, $acmeId, [
        'name' => 'Nina Cole',
        'email' => 'nina.cole@example.com',
        'phone' => '07700 900888',
        'postcode' => 'NG1 2BT',
    ], $acmeEbay);

    upsertOrder($pdo, $acmeId, $acmeEbay, [
        'external_order_id' => 'ACME-EB-2201',
        'status' => 'awaiting_despatch',
        'buyer_username' => 'ninacole',
        'buyer_email' => 'nina.cole@example.com',
        'shipping_name' => 'Nina Cole',
        'total' => 17.49,
        'shipping_cost' => 3.50,
        'shipping_method' => 'Evri Standard',
        'created_at' => $now->modify('-3 hours')->format('Y-m-d H:i:s'),
        'shipping_address' => ['line1' => '7 Market Square', 'city' => 'Nottingham', 'postcode' => 'NG1 2BT', 'country' => 'GB'],
    ]);

    upsertOrder($pdo, $acmeId, $acmeWeb, [
        'external_order_id' => 'ACME-WEB-88',
        'status' => 'processing',
        'buyer_username' => '',
        'buyer_email' => 'orders@buildright.example',
        'shipping_name' => 'BuildRight Supplies',
        'total' => 59.49,
        'shipping_cost' => 0.00,
        'shipping_method' => 'Palletway',
        'is_priority' => 1,
        'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
        'shipping_address' => ['line1' => 'Unit 4 Digbeth', 'city' => 'Birmingham', 'postcode' => 'B5 4DY', 'country' => 'GB'],
    ]);

    // Volume dataset for DataTables pagination / search demos
    $duzkyOrderCount = (int) $pdo->query('SELECT COUNT(*) FROM store_orders WHERE tenant_id = 1')->fetchColumn();
    if ($duzkyOrderCount < 80) {
        $statuses = ['awaiting_despatch', 'processing', 'printed', 'despatched', 'on_hold', 'awaiting_payment', 'cancelled'];
        $channels = [$chEbay, $chAmz, $chShop, $chMoto];
        $methods = ['Royal Mail 48', 'Royal Mail Tracked 24', 'Amazon Prime', 'DPD Next Day', 'Evri Standard', 'Collection'];
        $buyers = $pdo->query('SELECT name, email FROM store_customers WHERE tenant_id = 1')->fetchAll(PDO::FETCH_ASSOC);
        if ($buyers === []) {
            $buyers = [['name' => 'Guest Buyer', 'email' => 'guest@example.com']];
        }
        for ($i = $duzkyOrderCount + 1; $i <= 120; $i++) {
            $buyer = $buyers[array_rand($buyers)];
            $status = $statuses[array_rand($statuses)];
            $cid = $channels[array_rand($channels)];
            $hoursAgo = random_int(1, 240);
            upsertOrder($pdo, 1, $cid, [
                'external_order_id' => 'GEN-' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'status' => $status,
                'buyer_username' => '',
                'buyer_email' => (string) $buyer['email'],
                'shipping_name' => (string) $buyer['name'],
                'total' => round(random_int(399, 8999) / 100, 2),
                'shipping_cost' => round(random_int(0, 595) / 100, 2),
                'shipping_method' => $methods[array_rand($methods)],
                'is_priority' => random_int(0, 5) === 0 ? 1 : 0,
                'on_hold' => $status === 'on_hold' ? 1 : 0,
                'created_at' => $now->modify("-{$hoursAgo} hours")->format('Y-m-d H:i:s'),
                'shipped_at' => $status === 'despatched'
                    ? $now->modify('-' . max(1, $hoursAgo - 5) . ' hours')->format('Y-m-d H:i:s')
                    : null,
                'shipping_address' => [
                    'line1' => random_int(1, 99) . ' Sample Street',
                    'city' => 'London',
                    'postcode' => 'E' . random_int(1, 17) . ' ' . random_int(1, 9) . 'AA',
                    'country' => 'GB',
                ],
            ]);
        }
        echo "expanded Duzky orders to ~120\n";
    }

    $productCount = (int) $pdo->query('SELECT COUNT(*) FROM store_products WHERE tenant_id = 1')->fetchColumn();
    if ($productCount < 40) {
        for ($i = $productCount + 1; $i <= 48; $i++) {
            upsertProduct($pdo, 1, [
                'sku' => 'DZ-GEN-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'name' => 'Catalogue Item ' . $i,
                'brand' => ($i % 2 === 0) ? 'Duzky' : 'Duzky Pantry',
                'price' => round(random_int(250, 4599) / 100, 2),
                'barcode' => '5050199' . str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'weight_grams' => random_int(80, 2000),
                'active' => $i % 11 === 0 ? 0 : 1,
            ]);
        }
        echo "expanded Duzky products to ~48\n";
    }

    $customerCount = (int) $pdo->query('SELECT COUNT(*) FROM store_customers WHERE tenant_id = 1')->fetchColumn();
    if ($customerCount < 30) {
        $first = ['Alex', 'Sam', 'Jordan', 'Taylor', 'Casey', 'Riley', 'Morgan', 'Quinn', 'Avery', 'Jamie'];
        $last = ['Brooks', 'Reed', 'Hayes', 'Foster', 'Griffin', 'Murray', 'Pearson', 'Walsh', 'Keller', 'Newman'];
        for ($i = $customerCount + 1; $i <= 36; $i++) {
            $fn = $first[array_rand($first)];
            $ln = $last[array_rand($last)];
            $email = strtolower($fn . '.' . $ln . $i . '@example.com');
            upsertCustomer($pdo, 1, [
                'name' => $fn . ' ' . $ln,
                'email' => $email,
                'phone' => '07' . random_int(700000000, 799999999),
                'postcode' => 'N' . random_int(1, 22) . ' ' . random_int(1, 9) . 'AB',
            ], $channels[array_rand($channels)] ?? $chShop);
        }
        echo "expanded Duzky customers to ~36\n";
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

$counts = $pdo->query(
    "SELECT
      (SELECT COUNT(*) FROM store_channels WHERE tenant_id=1) AS ch,
      (SELECT COUNT(*) FROM store_products WHERE tenant_id=1) AS pr,
      (SELECT COUNT(*) FROM store_customers WHERE tenant_id=1) AS cu,
      (SELECT COUNT(*) FROM store_orders WHERE tenant_id=1) AS ord,
      (SELECT COUNT(*) FROM store_orders WHERE tenant_id={$acmeId}) AS acme_ord"
)->fetch(PDO::FETCH_ASSOC);

echo "Dataset seeded.\n";
echo "Duzky: {$counts['ch']} channels, {$counts['pr']} products, {$counts['cu']} customers, {$counts['ord']} orders\n";
echo "Acme: {$counts['acme_ord']} orders\n";
