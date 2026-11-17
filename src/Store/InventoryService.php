<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

/**
 * Allocate / release / consume stock for warehouse orders.
 * Shared inventory (packs/cuts) writes to inventory_source_id × inventory_unit_factor.
 *
 * Uses unused order.pre_despatched as stock-state flag:
 * 0 = not allocated, 1 = allocated, 2 = consumed on despatch.
 */
final class InventoryService
{
    private const STATE_NONE = 0;
    private const STATE_ALLOCATED = 1;
    private const STATE_CONSUMED = 2;

    /** @var list<string> Soft warnings (negative stock etc.) for flash/log. */
    private array $warnings = [];

    /** @var list<int> Inventory products whose available qty changed this call. */
    private array $touchedProductIds = [];

    public function __construct(private App $app) {}

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function clearWarnings(): void
    {
        $this->warnings = [];
    }

    /**
     * Resolve which product inventory row to write, and qty in source units.
     *
     * @return array{product_id:int,qty:int}|null
     */
    public function resolveTarget(int $tenantId, int $productId, int $qty): ?array
    {
        if ($productId <= 0 || $qty <= 0) {
            return null;
        }

        $product = $this->app->db->query(
            'SELECT id, inventory_source_id, inventory_unit_factor
             FROM #__store_products WHERE id=? AND tenant_id=? LIMIT 1',
            [$productId, $tenantId]
        )->row;
        if ($product === []) {
            return null;
        }

        $sourceId = (int) ($product['inventory_source_id'] ?? 0);
        $factor = (float) ($product['inventory_unit_factor'] ?? 1);
        if ($factor <= 0) {
            $factor = 1.0;
        }

        $targetId = $sourceId > 0 ? $sourceId : $productId;
        $units = $sourceId > 0 ? (int) max(1, (int) round($qty * $factor)) : $qty;

        return ['product_id' => $targetId, 'qty' => $units];
    }

    public function allocateOrder(int $orderId): bool
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->loadOrder($tenantId, $orderId);
        if ($order === null) {
            return false;
        }

        $state = (int) ($order['pre_despatched'] ?? 0);
        if ($state === self::STATE_ALLOCATED || $state === self::STATE_CONSUMED) {
            return false;
        }

        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, ['awaiting_despatch', 'printed', 'processing'], true)) {
            return false;
        }

        $dropship = new DropshipService($this->app);
        foreach ($this->orderLines($orderId) as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($dropship->isDropshipLine($tenantId, $pid, $sku)) {
                continue;
            }
            $this->adjustAllocated($tenantId, $pid, (int) ($line['qty'] ?? 0), +1);
        }

        $this->setState($tenantId, $orderId, self::STATE_ALLOCATED);
        $this->pushTouchedStock();
        return true;
    }

    public function releaseOrder(int $orderId): bool
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->loadOrder($tenantId, $orderId);
        if ($order === null) {
            return false;
        }

        if ((int) ($order['pre_despatched'] ?? 0) !== self::STATE_ALLOCATED) {
            return false;
        }

        $dropship = new DropshipService($this->app);
        foreach ($this->orderLines($orderId) as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($dropship->isDropshipLine($tenantId, $pid, $sku)) {
                continue;
            }
            $this->adjustAllocated($tenantId, $pid, (int) ($line['qty'] ?? 0), -1);
        }

        $this->setState($tenantId, $orderId, self::STATE_NONE);
        $this->pushTouchedStock();
        return true;
    }

    /**
     * On despatch: reduce on-hand and clear allocation for the order lines.
     * Idempotent when already consumed or never allocated.
     */
    public function consumeOrder(int $orderId): bool
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->loadOrder($tenantId, $orderId);
        if ($order === null) {
            return false;
        }

        $state = (int) ($order['pre_despatched'] ?? 0);
        if ($state === self::STATE_CONSUMED) {
            return false;
        }

        // If never allocated (legacy orders), still deduct on-hand once.
        $hadAllocation = $state === self::STATE_ALLOCATED;
        $dropship = new DropshipService($this->app);

        foreach ($this->orderLines($orderId) as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($dropship->isDropshipLine($tenantId, $productId, $sku)) {
                continue;
            }
            $qty = (int) ($line['qty'] ?? 0);
            $target = $this->resolveTarget($tenantId, $productId, $qty);
            if ($target === null) {
                continue;
            }

            $this->touchedProductIds[] = $target['product_id'];
            $invId = $this->ensureInventoryRow($tenantId, $target['product_id']);
            $row = $this->app->db->query(
                'SELECT qty_on_hand, qty_allocated FROM #__store_inventory WHERE id=? AND tenant_id=? LIMIT 1',
                [$invId, $tenantId]
            )->row;

            $onHand = (int) ($row['qty_on_hand'] ?? 0);
            $allocated = (int) ($row['qty_allocated'] ?? 0);
            $deduct = $target['qty'];

            $newOnHand = $onHand - $deduct;
            if ($newOnHand < 0) {
                $this->warnings[] = sprintf(
                    'Order #%d: stock for product #%d went negative (on hand %d, deduct %d).',
                    $orderId,
                    $target['product_id'],
                    $onHand,
                    $deduct
                );
                $newOnHand = 0;
            }

            $newAllocated = $hadAllocation ? max(0, $allocated - $deduct) : $allocated;

            $this->app->db->query(
                'UPDATE #__store_inventory SET qty_on_hand=?, qty_allocated=? WHERE id=? AND tenant_id=?',
                [$newOnHand, $newAllocated, $invId, $tenantId]
            );
        }

        $this->setState($tenantId, $orderId, self::STATE_CONSUMED);
        $this->pushTouchedStock();
        return true;
    }

    /**
     * Credit on-hand for a returned product (shared source aware). Does not change qty_allocated.
     */
    public function restockProduct(int $productId, int $qty): bool
    {
        $tenantId = $this->app->tenant->id();
        $target = $this->resolveTarget($tenantId, $productId, $qty);
        if ($target === null) {
            return false;
        }

        $invId = $this->ensureInventoryRow($tenantId, $target['product_id']);
        $this->app->db->query(
            'UPDATE #__store_inventory SET qty_on_hand = qty_on_hand + ? WHERE id=? AND tenant_id=?',
            [$target['qty'], $invId, $tenantId]
        );
        $this->touchedProductIds[] = $target['product_id'];
        $this->pushTouchedStock();
        return true;
    }

    /**
     * Resolve product by SKU (tenant) then restock. Returns false if SKU unknown.
     */
    public function restockBySku(string $sku, int $qty): bool
    {
        $sku = trim($sku);
        if ($sku === '' || $qty <= 0) {
            return false;
        }
        $tenantId = $this->app->tenant->id();
        $product = $this->app->db->query(
            'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
            [$tenantId, $sku]
        )->row;
        if ($product === []) {
            $this->warnings[] = 'Unknown SKU for restock: ' . $sku;
            return false;
        }
        return $this->restockProduct((int) $product['id'], $qty);
    }

    /** @return array<string,mixed>|null */
    private function loadOrder(int $tenantId, int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT id, status, pre_despatched FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function orderLines(int $orderId): array
    {
        return $this->app->db->query(
            'SELECT product_id, sku, qty FROM #__store_order_items WHERE order_id=?',
            [$orderId]
        )->rows;
    }

    private function setState(int $tenantId, int $orderId, int $state): void
    {
        $this->app->db->query(
            'UPDATE #__store_orders SET pre_despatched=? WHERE id=? AND tenant_id=?',
            [$state, $orderId, $tenantId]
        );
    }

    private function adjustAllocated(int $tenantId, int $productId, int $qty, int $sign): void
    {
        $target = $this->resolveTarget($tenantId, $productId, $qty);
        if ($target === null) {
            return;
        }

        $this->touchedProductIds[] = $target['product_id'];
        $invId = $this->ensureInventoryRow($tenantId, $target['product_id']);
        $delta = $sign >= 0 ? $target['qty'] : -$target['qty'];

        if ($delta >= 0) {
            $this->app->db->query(
                'UPDATE #__store_inventory SET qty_allocated = qty_allocated + ? WHERE id=? AND tenant_id=?',
                [$delta, $invId, $tenantId]
            );
            return;
        }

        $row = $this->app->db->query(
            'SELECT qty_allocated FROM #__store_inventory WHERE id=? AND tenant_id=? LIMIT 1',
            [$invId, $tenantId]
        )->row;
        $current = (int) ($row['qty_allocated'] ?? 0);
        $newVal = max(0, $current + $delta);
        $this->app->db->query(
            'UPDATE #__store_inventory SET qty_allocated=? WHERE id=? AND tenant_id=?',
            [$newVal, $invId, $tenantId]
        );
    }

    private function ensureInventoryRow(int $tenantId, int $productId): int
    {
        return (new StockLocationService($this->app))->ensureInventoryRow($tenantId, $productId);
    }

    private function pushTouchedStock(): void
    {
        $ids = array_values(array_unique(array_filter($this->touchedProductIds)));
        $this->touchedProductIds = [];
        if ($ids === []) {
            return;
        }
        try {
            foreach ((new ChannelStockService($this->app))->pushProducts($ids) as $warning) {
                $this->warnings[] = $warning;
            }
        } catch (\Throwable $e) {
            $this->warnings[] = 'Channel stock push failed: ' . $e->getMessage();
        }
    }
}
