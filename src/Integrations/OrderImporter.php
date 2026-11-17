<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;
use App\Core\Database;
use App\Store\CustomerRepository;
use App\Store\DropshipService;
use App\Store\OrderRuleService;

final class OrderImporter
{
    private const MERGE_WINDOW_HOURS = 48;

    public function __construct(
        private Database $db,
        private ?App $app = null
    ) {}

    /**
     * @param list<array<string, mixed>> $orders
     * @param array<string, string> $channelSettings
     * @return array{imported:int, updated:int, merged:int, skipped:int, errors:list<string>}
     */
    public function import(int $tenantId, int $channelId, array $orders, array $channelSettings = []): array
    {
        $imported = 0;
        $updated = 0;
        $merged = 0;
        $skipped = 0;
        $errors = [];

        if (array_key_exists('import_orders', $channelSettings)
            && !in_array(strtolower((string) $channelSettings['import_orders']), ['1', 'true', 'yes'], true)
        ) {
            return [
                'imported' => 0,
                'updated'  => 0,
                'merged'   => 0,
                'skipped'  => count($orders),
                'errors'   => ['Channel import_orders is off.'],
            ];
        }

        $mergeOn = in_array(
            strtolower((string) ($channelSettings['merge_orders'] ?? '0')),
            ['1', 'true', 'yes'],
            true
        );

        foreach ($orders as $order) {
            $ref = trim((string) ($order['external_order_id'] ?? ''));
            if ($ref === '') {
                $skipped++;
                continue;
            }

            if (!$this->shouldImport($order, $channelSettings)) {
                $skipped++;
                continue;
            }

            try {
                $result = $this->upsertOne($tenantId, $channelId, $order, $mergeOn);
                match ($result) {
                    'created' => $imported++,
                    'updated' => $updated++,
                    'merged'  => $merged++,
                    default   => $skipped++,
                };
            } catch (\Throwable $e) {
                $errors[] = $ref . ': ' . $e->getMessage();
            }
        }

        return compact('imported', 'updated', 'merged', 'skipped', 'errors');
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, string> $settings
     */
    private function shouldImport(array $order, array $settings): bool
    {
        if (array_key_exists('import_unpaid_orders', $settings)
            && !in_array(strtolower((string) $settings['import_unpaid_orders']), ['1', 'true', 'yes'], true)
        ) {
            $paid = $order['is_paid'] ?? $order['payment_status'] ?? true;
            if ($paid === false || $paid === 0 || $paid === '0'
                || (is_string($paid) && strtoupper($paid) !== 'PAID' && strtoupper($paid) !== 'TRUE')
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $order
     * @return 'created'|'updated'|'merged'|'skipped'
     */
    private function upsertOne(int $tenantId, int $channelId, array $order, bool $mergeOn): string
    {
        $ref = trim((string) ($order['external_order_id'] ?? ''));
        $addr = $order['shipping_address'] ?? [];
        if (!is_array($addr)) {
            $addr = [];
        }

        $status = (string) ($order['status'] ?? 'awaiting_despatch');
        $buyerUsername = (string) ($order['buyer_username'] ?? '');
        $buyerEmail = (string) ($order['buyer_email'] ?? '');
        $shippingName = (string) ($order['shipping_name'] ?? '');
        $total = (float) ($order['total'] ?? 0);
        $shippingCost = (float) ($order['shipping_cost'] ?? 0);
        $currency = (string) ($order['currency'] ?? 'GBP');
        $shippingMethod = (string) ($order['shipping_method'] ?? '');
        $isPaid = isset($order['is_paid'])
            ? (in_array($order['is_paid'], [1, '1', true, 'true', 'yes', 'PAID'], true) ? 1 : 0)
            : 1;
        $createdAt = $this->normalizeCreatedAt($order['created_at'] ?? $order['placed_at'] ?? $order['order_date'] ?? null);

        $existing = $this->db->query(
            'SELECT * FROM #__store_orders WHERE tenant_id = ? AND channel_id = ? AND external_order_id = ? LIMIT 1',
            [$tenantId, $channelId, $ref]
        )->row;

        if ($existing === [] && $mergeOn) {
            $candidate = $this->findMergeCandidate(
                $tenantId,
                $channelId,
                $buyerUsername,
                $shippingName,
                $addr
            );
            if ($candidate !== null) {
                $this->mergeInto($tenantId, $candidate, $order, $ref, $total, $addr);
                $this->afterPersist((int) $candidate['id']);
                return 'merged';
            }
        }

        if ($existing !== []) {
            $orderId = (int) $existing['id'];
            // Do not pull warehouse/despatched orders back to awaiting via sync status alone
            // unless the channel says cancelled/refunded/despatched.
            $localStatus = (string) ($existing['status'] ?? '');
            $newStatus = $status;
            if (in_array($localStatus, ['despatched', 'cancelled', 'refunded'], true)
                && !in_array($status, ['despatched', 'cancelled', 'refunded'], true)
            ) {
                $newStatus = $localStatus;
            }

            $sets = 'status=?, buyer_username=?, buyer_email=?, shipping_name=?, total=?,
                     shipping_cost=?, currency=?, shipping_address=?, shipping_method=?, is_paid=?';
            $bind = [
                $newStatus,
                $buyerUsername,
                $buyerEmail,
                $shippingName,
                $total,
                $shippingCost,
                $currency,
                json_encode($addr, JSON_UNESCAPED_UNICODE),
                $shippingMethod,
                $isPaid,
            ];
            // Re-sync corrects order date from channel (was previously sync-time).
            if ($createdAt !== null) {
                $sets .= ', created_at=?';
                $bind[] = $createdAt;
            }
            $bind[] = $orderId;
            $bind[] = $tenantId;
            $this->db->query(
                'UPDATE #__store_orders SET ' . $sets . ' WHERE id=? AND tenant_id=?',
                $bind
            );
            $this->replaceItems($tenantId, $orderId, $order['items'] ?? []);
            try {
                (new CustomerRepository($this->db))->upsertFromOrder($tenantId, $channelId, $order);
            } catch (\Throwable) {
            }
            $this->afterPersist($orderId);
            return 'updated';
        }

        $ins = $this->db->query(
            'INSERT INTO #__store_orders
             (tenant_id, channel_id, external_order_id, status, buyer_username, buyer_email, shipping_name, total,
              shipping_cost, currency, shipping_address, shipping_method, warehouse, is_paid, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $channelId,
                $ref,
                $status,
                $buyerUsername,
                $buyerEmail,
                $shippingName,
                $total,
                $shippingCost,
                $currency,
                json_encode($addr, JSON_UNESCAPED_UNICODE),
                $shippingMethod,
                'Default',
                $isPaid,
                $createdAt ?? date('Y-m-d H:i:s'),
            ]
        );
        $orderId = (int) ($ins->insert_id ?: 0);
        try {
            (new CustomerRepository($this->db))->upsertFromOrder($tenantId, $channelId, $order);
        } catch (\Throwable) {
        }
        $this->replaceItems($tenantId, $orderId, $order['items'] ?? []);
        $this->afterPersist($orderId);
        return 'created';
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $order
     * @param array<string,mixed> $addr
     */
    private function mergeInto(
        int $tenantId,
        array $candidate,
        array $order,
        string $externalId,
        float $total,
        array $addr
    ): void {
        $orderId = (int) $candidate['id'];
        $decoded = [];
        $raw = $candidate['shipping_address'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) {
                $decoded = $tmp;
            }
        } elseif (is_array($raw)) {
            $decoded = $raw;
        }
        $merged = is_array($decoded['_merged_external_ids'] ?? null)
            ? $decoded['_merged_external_ids']
            : [];
        if (!in_array($externalId, $merged, true)) {
            $merged[] = $externalId;
        }
        $decoded['_merged_external_ids'] = $merged;
        foreach ($addr as $k => $v) {
            if (!isset($decoded[$k]) || $decoded[$k] === '' || $decoded[$k] === null) {
                $decoded[$k] = $v;
            }
        }

        $newTotal = (float) ($candidate['total'] ?? 0) + $total;
        $this->db->query(
            'UPDATE #__store_orders SET total=?, shipping_address=? WHERE id=? AND tenant_id=?',
            [round($newTotal, 2), json_encode($decoded, JSON_UNESCAPED_UNICODE), $orderId, $tenantId]
        );

        $items = $order['items'] ?? [];
        if (is_array($items)) {
            $this->insertItems($tenantId, $orderId, $items);
        }
    }

    /**
     * @param array<string,mixed> $addr
     * @return array<string,mixed>|null
     */
    private function findMergeCandidate(
        int $tenantId,
        int $channelId,
        string $buyerUsername,
        string $shippingName,
        array $addr
    ): ?array {
        if ($buyerUsername === '' && $shippingName === '') {
            return null;
        }
        $since = date('Y-m-d H:i:s', time() - self::MERGE_WINDOW_HOURS * 3600);
        $rows = $this->db->query(
            "SELECT * FROM #__store_orders
             WHERE tenant_id=? AND channel_id=? AND created_at >= ?
               AND status NOT IN ('despatched','cancelled','refunded')
             ORDER BY id DESC LIMIT 25",
            [$tenantId, $channelId, $since]
        )->rows;

        $addrKey = $this->addressKey($addr);
        foreach ($rows as $row) {
            $sameBuyer = $buyerUsername !== ''
                && strcasecmp((string) ($row['buyer_username'] ?? ''), $buyerUsername) === 0;
            $sameName = $shippingName !== ''
                && strcasecmp((string) ($row['shipping_name'] ?? ''), $shippingName) === 0;
            if (!$sameBuyer && !$sameName) {
                continue;
            }
            $existingAddr = [];
            $raw = $row['shipping_address'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $existingAddr = $decoded;
                }
            }
            if ($addrKey !== '' && $this->addressKey($existingAddr) !== $addrKey) {
                continue;
            }
            return $row;
        }
        return null;
    }

    /** @param array<string,mixed> $addr */
    private function addressKey(array $addr): string
    {
        $line1 = strtoupper(preg_replace('/\s+/', '', (string) ($addr['line1'] ?? '')) ?? '');
        $postcode = strtoupper(preg_replace('/\s+/', '', (string) ($addr['postcode'] ?? '')) ?? '');
        $city = strtoupper(preg_replace('/\s+/', '', (string) ($addr['city'] ?? '')) ?? '');
        if ($line1 === '' && $postcode === '') {
            return '';
        }
        return $line1 . '|' . $postcode . '|' . $city;
    }

    /** @param mixed $items */
    private function replaceItems(int $tenantId, int $orderId, mixed $items): void
    {
        $this->db->query('DELETE FROM #__store_order_items WHERE order_id=?', [$orderId]);
        if (!is_array($items)) {
            return;
        }
        $this->insertItems($tenantId, $orderId, $items);
    }

    /**
     * @param list<mixed> $items
     */
    private function insertItems(int $tenantId, int $orderId, array $items): void
    {
        $channelId = (int) ($this->db->query(
            'SELECT channel_id FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row['channel_id'] ?? 0);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $unit = (float) ($item['unit_price'] ?? 0);
            $sku = trim((string) ($item['sku'] ?? ''));
            $listingId = trim((string) ($item['listing_id'] ?? $item['legacy_item_id'] ?? ''));
            if ($sku === '' && $listingId !== '' && $channelId > 0) {
                $sku = $this->resolveSkuFromListing($tenantId, $channelId, $listingId);
            }
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0 && $sku !== '') {
                $productId = $this->resolveProductId($tenantId, $sku);
            }
            $this->db->query(
                'INSERT INTO #__store_order_items (order_id, product_id, sku, name, qty, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $productId > 0 ? $productId : null,
                    $sku,
                    (string) ($item['name'] ?? ''),
                    $qty,
                    $unit,
                    $qty * $unit,
                ]
            );
        }
    }

    private function resolveSkuFromListing(int $tenantId, int $channelId, string $listingId): string
    {
        $row = $this->db->query(
            'SELECT sku FROM #__store_listings
             WHERE tenant_id=? AND channel_id=? AND (external_id=? OR sku=?) AND sku<>\'\'
             LIMIT 1',
            [$tenantId, $channelId, $listingId, $listingId]
        )->row;
        return trim((string) ($row['sku'] ?? ''));
    }

    private function afterPersist(int $orderId): void
    {
        if ($this->app === null || $orderId <= 0) {
            return;
        }
        try {
            (new DropshipService($this->app))->ensureForOrder($orderId);
        } catch (\Throwable) {
        }
        try {
            (new OrderRuleService($this->app))->applyToOrder($orderId);
        } catch (\Throwable) {
        }
    }

    private function resolveProductId(int $tenantId, string $sku): int
    {
        $row = $this->db->query(
            'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
            [$tenantId, $sku]
        )->row;
        return (int) ($row['id'] ?? 0);
    }

    private function normalizeCreatedAt(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $raw = $raw['value'] ?? $raw['formattedValue'] ?? null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        // Already local datetime from a connector.
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
