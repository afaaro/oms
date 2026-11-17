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

$pdo->exec((string) file_get_contents($base . '/storage/migrations/005_suppliers_po.sql'));
echo "applied 005_suppliers_po.sql\n";

$has = (int) $pdo->query('SELECT COUNT(*) FROM store_suppliers WHERE tenant_id = 1')->fetchColumn();
if ($has === 0) {
    $pdo->exec(
        "INSERT INTO store_suppliers (tenant_id, name, code, email, phone, active) VALUES
         (1, 'Twinings Wholesale', 'TWIN', 'orders@twinings.example', '0800 111 222', 1),
         (1, 'Northern Pack Co', 'NPC', 'sales@npc.example', '0161 496 0200', 1),
         (1, 'Pantry Direct Ltd', 'PANTRY', 'buy@pantrydirect.example', '020 7946 0100', 1)"
    );
    echo "seeded suppliers\n";
}

$po = (int) $pdo->query('SELECT COUNT(*) FROM store_purchase_orders WHERE tenant_id = 1')->fetchColumn();
if ($po === 0) {
    $sid = (int) $pdo->query('SELECT id FROM store_suppliers WHERE tenant_id = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $pdo->prepare(
        "INSERT INTO store_purchase_orders (tenant_id, supplier_id, reference, status, total, expected_at, notes)
         VALUES
         (1, ?, 'PO-1001', 'ordered', 420.50, DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'Tea restock'),
         (1, ?, 'PO-1002', 'draft', 89.00, DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'Packaging'),
         (1, ?, 'PO-1003', 'received', 210.75, CURDATE(), 'Honey & jam')"
    )->execute([$sid, $sid + 1, $sid + 2]);
    echo "seeded purchase orders\n";
}

echo "Done.\n";
