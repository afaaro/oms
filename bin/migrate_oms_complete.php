<?php

declare(strict_types=1);

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

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$pdo->exec((string) file_get_contents($base . '/storage/migrations/006_oms_complete.sql'));
echo "applied 006_oms_complete.sql\n";

if (!columnExists($pdo, 'store_products', 'category_id')) {
    $pdo->exec('ALTER TABLE store_products ADD COLUMN category_id int unsigned NULL AFTER brand');
    echo "added store_products.category_id\n";
}

// Categories
if ((int) $pdo->query('SELECT COUNT(*) FROM store_categories WHERE tenant_id=1')->fetchColumn() === 0) {
    $pdo->exec(
        "INSERT INTO store_categories (tenant_id, name) VALUES
         (1,'Tea & Coffee'),(1,'Pantry'),(1,'Homeware'),(1,'Gift Sets')"
    );
    echo "seeded categories\n";
}

// Assign categories
$cats = $pdo->query('SELECT id, name FROM store_categories WHERE tenant_id=1')->fetchAll(PDO::FETCH_KEY_PAIR);
$tea = (int) array_search('Tea & Coffee', $cats, true);
$pantry = (int) array_search('Pantry', $cats, true);
$home = (int) array_search('Homeware', $cats, true);
$gift = (int) array_search('Gift Sets', $cats, true);
$pdo->exec("UPDATE store_products SET category_id = {$tea} WHERE tenant_id=1 AND (sku LIKE 'DZ-TEA%' OR sku LIKE 'DZ-COFFEE%' OR sku LIKE 'DZ-KIT%')");
$pdo->exec("UPDATE store_products SET category_id = {$pantry} WHERE tenant_id=1 AND (sku LIKE 'DZ-JAM%' OR sku LIKE 'DZ-HONEY%' OR sku LIKE 'DZ-BISCUIT%')");
$pdo->exec("UPDATE store_products SET category_id = {$home} WHERE tenant_id=1 AND (sku LIKE 'DZ-MUG%' OR sku LIKE 'DZ-TOWEL%')");
$pdo->exec("UPDATE store_products SET category_id = {$gift} WHERE tenant_id=1 AND sku LIKE 'DZ-GIFT%'");

// Stock locations
if ((int) $pdo->query('SELECT COUNT(*) FROM store_stock_locations WHERE tenant_id=1')->fetchColumn() === 0) {
    $wh = (int) $pdo->query('SELECT id FROM store_warehouses WHERE tenant_id=1 ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare(
        "INSERT INTO store_stock_locations (tenant_id, warehouse_id, code, name) VALUES
         (1, ?, 'A-01-01', 'Aisle A Bay 1'),
         (1, ?, 'A-01-02', 'Aisle A Bay 2'),
         (1, ?, 'B-02-01', 'Aisle B Bay 1'),
         (1, ?, 'PACK', 'Pack bench')"
    )->execute([$wh, $wh, $wh, $wh]);
    echo "seeded stock locations\n";
}

// Inventory for products
if ((int) $pdo->query('SELECT COUNT(*) FROM store_inventory WHERE tenant_id=1')->fetchColumn() === 0) {
    $loc = (int) $pdo->query('SELECT id FROM store_stock_locations WHERE tenant_id=1 ORDER BY id LIMIT 1')->fetchColumn();
    $products = $pdo->query('SELECT id FROM store_products WHERE tenant_id=1')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare('INSERT INTO store_inventory (tenant_id, product_id, location_id, qty_on_hand, qty_allocated) VALUES (1,?,?,?,?)');
    foreach ($products as $pid) {
        $ins->execute([(int) $pid, $loc, random_int(0, 250), random_int(0, 20)]);
    }
    echo "seeded inventory for " . count($products) . " products\n";
}

// Order line items for orders missing them
$orders = $pdo->query(
    'SELECT o.id, o.total FROM store_orders o
     WHERE o.tenant_id=1 AND NOT EXISTS (SELECT 1 FROM store_order_items i WHERE i.order_id=o.id)
     ORDER BY o.id'
)->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query('SELECT id, sku, name, price FROM store_products WHERE tenant_id=1 AND active=1')->fetchAll(PDO::FETCH_ASSOC);
$insItem = $pdo->prepare(
    'INSERT INTO store_order_items (order_id, product_id, sku, name, qty, unit_price, line_total)
     VALUES (?,?,?,?,?,?,?)'
);
$added = 0;
foreach ($orders as $o) {
    $n = random_int(1, 3);
    $remaining = (float) $o['total'];
    for ($i = 0; $i < $n; $i++) {
        $p = $products[array_rand($products)];
        $qty = random_int(1, 3);
        $price = (float) $p['price'];
        if ($i === $n - 1 && $remaining > 0) {
            $line = max($price, $remaining);
            $price = round($line / $qty, 2);
        }
        $line = round($price * $qty, 2);
        $remaining = max(0, $remaining - $line);
        $insItem->execute([(int) $o['id'], (int) $p['id'], $p['sku'], $p['name'], $qty, $price, $line]);
        $added++;
    }
}
echo "seeded {$added} order items\n";

// Returns
if ((int) $pdo->query('SELECT COUNT(*) FROM store_returns WHERE tenant_id=1')->fetchColumn() === 0) {
    $sample = $pdo->query(
        "SELECT o.id, o.external_order_id, i.sku FROM store_orders o
         INNER JOIN store_order_items i ON i.order_id=o.id
         WHERE o.tenant_id=1 ORDER BY o.id DESC LIMIT 5"
    )->fetchAll(PDO::FETCH_ASSOC);
    $ins = $pdo->prepare(
        'INSERT INTO store_returns (tenant_id, order_id, external_order_id, sku, qty, reason, status)
         VALUES (1,?,?,?,?,?,?)'
    );
    $reasons = ['Damaged in transit', 'Wrong item', 'Customer changed mind'];
    foreach ($sample as $i => $row) {
        $ins->execute([
            $row['id'], $row['external_order_id'], $row['sku'], 1,
            $reasons[$i % 3], $i === 0 ? 'pending' : ($i === 1 ? 'received' : 'refunded'),
        ]);
    }
    echo "seeded returns\n";
}

// Dropships
if ((int) $pdo->query('SELECT COUNT(*) FROM store_dropships WHERE tenant_id=1')->fetchColumn() === 0) {
    $sup = (int) $pdo->query('SELECT id FROM store_suppliers WHERE tenant_id=1 ORDER BY id LIMIT 1')->fetchColumn();
    $ords = $pdo->query('SELECT id FROM store_orders WHERE tenant_id=1 ORDER BY id DESC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare(
        'INSERT INTO store_dropships (tenant_id, order_id, supplier_id, reference, status) VALUES (1,?,?,?,?)'
    );
    foreach ($ords as $i => $oid) {
        $ins->execute([(int) $oid, $sup, 'DS-' . (1000 + $i), $i === 0 ? 'pending' : 'sent']);
    }
    echo "seeded dropships\n";
}

// Report jobs
if ((int) $pdo->query('SELECT COUNT(*) FROM store_report_jobs WHERE tenant_id=1')->fetchColumn() === 0) {
    $pdo->exec(
        "INSERT INTO store_report_jobs (tenant_id, name, type, schedule, status, completed_at) VALUES
         (1, 'Daily despatch summary', 'scheduled', 'daily 06:00', 'completed', NOW()),
         (1, 'Orders by channel export', 'export', 'manual', 'completed', NOW()),
         (1, 'Inventory snapshot', 'scheduled', 'weekly Mon', 'queued', NULL)"
    );
    echo "seeded report jobs\n";
}

echo "Done.\n";
