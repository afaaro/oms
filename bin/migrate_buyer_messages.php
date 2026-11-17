<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap/autoload.php';

$cfg = require dirname(__DIR__) . '/config/database.php';
$db = new App\Core\Database($cfg);

$sql = file_get_contents(dirname(__DIR__) . '/storage/migrations/009_buyer_messages.sql');
foreach (array_filter(array_map('trim', explode(';', (string) $sql))) as $stmt) {
    if ($stmt === '' || str_starts_with($stmt, '--')) {
        continue;
    }
    $db->query($stmt);
}
echo "applied 009_buyer_messages.sql\n";

$tenants = $db->query('SELECT id FROM store_tenants ORDER BY id')->rows;
if ($tenants === []) {
    $tenants = [['id' => 1]];
}

$samples = [
    ['ebay', 'Where is my order?', 'Hi, I ordered last week and tracking has not updated. Can you help?', 'Sarah Buyer'],
    ['amazon', 'Wrong item received', 'I ordered DZ-TEA-01 but received coffee instead. Please advise.', 'Amazon Customer'],
    ['etsy', 'Gift wrap request', 'Could you gift wrap this before dispatch? Thanks!', 'Jamie Etsy'],
    ['tiktok', 'Cancel order please', 'Please cancel if not shipped yet.', 'TikTok Shopper'],
    ['bandq', 'Delivery slot', 'Can you deliver on Saturday morning?', 'B&Q Customer'],
    ['onbuy', 'VAT invoice', 'Please send a VAT invoice for my order.', 'OnBuy Buyer'],
    ['shopify', 'Size question', 'Is this mug dishwasher safe?', 'Webstore Guest'],
    ['woocommerce', 'Discount code', 'My discount code failed at checkout — order still placed.', 'Woo Customer'],
];

foreach ($tenants as $t) {
    $tid = (int) $t['id'];
    foreach ($samples as $i => [$provider, $subject, $body, $buyer]) {
        $ch = $db->query(
            'SELECT id FROM store_channels WHERE tenant_id = ? AND (code = ? OR JSON_UNQUOTE(JSON_EXTRACT(settings, \'$.provider\')) = ?) LIMIT 1',
            [$tid, $provider, $provider]
        )->row;
        $channelId = (int) ($ch['id'] ?? 0) ?: null;
        $ext = 'DEMO-' . strtoupper($provider) . '-' . ($i + 1);
        $exists = $db->query(
            'SELECT id FROM store_buyer_messages WHERE tenant_id = ? AND external_id = ? LIMIT 1',
            [$tid, $ext]
        )->row;
        if ($exists !== []) {
            continue;
        }
        $db->query(
            'INSERT INTO store_buyer_messages
             (tenant_id, channel_id, provider, external_id, thread_id, order_external_id, buyer_name, buyer_email,
              subject, body, direction, status, received_at, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR), NOW())',
            [
                $tid,
                $channelId,
                $provider,
                $ext,
                'THR-' . $ext,
                'ORD-' . strtoupper($provider) . '-100' . $i,
                $buyer,
                strtolower(str_replace(' ', '.', $buyer)) . '@example.com',
                $subject,
                $body,
                'inbound',
                $i % 3 === 0 ? 'unread' : 'open',
                ($i + 1) * 5,
            ]
        );
        echo "seeded {$ext} tenant={$tid}\n";
    }
}

echo "Done.\n";
