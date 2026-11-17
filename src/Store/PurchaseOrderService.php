<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

final class PurchaseOrderService
{
    public function __construct(private App $app) {}

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->app->db->query(
            "SELECT po.*, s.name AS supplier_name, s.code AS supplier_code, s.email AS supplier_email,
                    s.phone AS supplier_phone, s.supplier_reference, s.lead_time_days, s.min_order_value,
                    s.address_line1 AS supplier_line1, s.address_line2 AS supplier_line2,
                    s.city AS supplier_city, s.country AS supplier_country, s.postcode AS supplier_postcode,
                    s.currency AS supplier_currency, s.po_change_status AS supplier_po_change_status,
                    w.name AS warehouse_name
             FROM #__store_purchase_orders po
             LEFT JOIN #__store_suppliers s ON s.id = po.supplier_id AND s.tenant_id = po.tenant_id
             LEFT JOIN #__store_warehouses w ON w.id = po.warehouse_id AND w.tenant_id = po.tenant_id
             WHERE po.id=? AND po.tenant_id=? LIMIT 1",
            [$id, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function items(int $purchaseOrderId): array
    {
        return $this->app->db->query(
            'SELECT * FROM #__store_purchase_order_items
             WHERE purchase_order_id=? AND tenant_id=? ORDER BY id ASC',
            [$purchaseOrderId, $this->app->tenant->id()]
        )->rows;
    }

    /**
     * @param array<string,mixed> $header
     * @param list<array{sku:string,name?:string,qty:int,unit_cost:float}> $lines
     * @return array{ok:bool, id?:int, message:string}
     */
    public function create(array $header, array $lines): array
    {
        $tenantId = $this->app->tenant->id();
        $supplierId = (int) ($header['supplier_id'] ?? 0);
        if ($supplierId <= 0) {
            return ['ok' => false, 'message' => 'Select a supplier.'];
        }
        $supplier = $this->app->db->query(
            'SELECT id FROM #__store_suppliers WHERE id=? AND tenant_id=? LIMIT 1',
            [$supplierId, $tenantId]
        )->row;
        if ($supplier === []) {
            return ['ok' => false, 'message' => 'Supplier not found.'];
        }

        $status = $this->normalizeStatus((string) ($header['status'] ?? 'draft'), 'draft');
        $parsed = $this->normalizeLines($lines);
        if ($parsed === [] && $status !== 'draft') {
            return ['ok' => false, 'message' => 'Add at least one line with SKU and qty.'];
        }

        $reference = trim((string) ($header['reference'] ?? ''));
        if ($reference === '') {
            $reference = (string) random_int(100000000, 999999999);
        }

        $total = 0.0;
        foreach ($parsed as $line) {
            $total += $line['line_total'];
        }
        $delivery = round((float) ($header['delivery_cost'] ?? 0), 2);
        if (!empty($header['include_delivery_cost'])) {
            $total += $delivery;
        }

        $fields = $this->headerColumns($header, $status, $reference, $total, true);
        $cols = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
        $ins = $this->app->db->query(
            'INSERT INTO #__store_purchase_orders (tenant_id, ' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')',
            array_merge([$tenantId], array_values($fields))
        );
        $poId = (int) ($ins->insert_id ?: 0);
        if ($poId <= 0) {
            return ['ok' => false, 'message' => 'Could not create purchase order.'];
        }

        $this->replaceItems($poId, $parsed);
        return ['ok' => true, 'id' => $poId, 'message' => "Purchase order {$reference} created."];
    }

    /**
     * @param array<string,mixed> $header
     * @param list<array{sku:string,name?:string,qty:int,unit_cost:float}>|null $lines
     * @return array{ok:bool, message:string}
     */
    public function update(int $id, array $header, ?array $lines = null): array
    {
        $po = $this->get($id);
        if ($po === null) {
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }

        $status = $this->normalizeStatus((string) ($header['status'] ?? $po['status']), (string) $po['status']);
        $current = (string) ($po['status'] ?? 'draft');
        if ($current === 'received' && $status !== 'received') {
            return ['ok' => false, 'message' => 'Fully received POs cannot change status.'];
        }
        if ($current === 'cancelled' && $status !== 'cancelled') {
            return ['ok' => false, 'message' => 'Cancelled POs cannot be reopened here.'];
        }

        $reference = trim((string) ($header['reference'] ?? $po['reference']));
        $total = (float) ($po['total'] ?? 0);
        $parsed = null;

        $canEditLines = in_array($current, ['draft', 'ordered'], true)
            && in_array($status, ['draft', 'ordered', 'cancelled'], true)
            && $lines !== null;

        if ($canEditLines) {
            $parsed = $this->normalizeLines($lines);
            if ($parsed === [] && $status !== 'cancelled' && $status !== 'draft') {
                return ['ok' => false, 'message' => 'Add at least one line with SKU and qty.'];
            }
            $total = 0.0;
            foreach ($parsed as $line) {
                $total += $line['line_total'];
            }
            $delivery = round((float) ($header['delivery_cost'] ?? $po['delivery_cost'] ?? 0), 2);
            if (!empty($header['include_delivery_cost'])) {
                $total += $delivery;
            }
            $this->replaceItems($id, $parsed, true);
        } else {
            $delivery = round((float) ($header['delivery_cost'] ?? $po['delivery_cost'] ?? 0), 2);
            // Rebuild merchandise total from items when only header changes.
            $items = $this->items($id);
            $merch = 0.0;
            foreach ($items as $it) {
                $merch += (float) ($it['line_total'] ?? 0);
            }
            $total = $merch;
            if (!empty($header['include_delivery_cost'])) {
                $total += $delivery;
            }
        }

        $fields = $this->headerColumns($header, $status, $reference, $total, false, $po);
        $sets = [];
        $bind = [];
        foreach ($fields as $col => $val) {
            $sets[] = "{$col}=?";
            $bind[] = $val;
        }
        if ($status === 'ordered' && empty($po['ordered_at'])) {
            $sets[] = 'ordered_at = NOW()';
        }
        $bind[] = $id;
        $bind[] = $this->app->tenant->id();
        $this->app->db->query(
            'UPDATE #__store_purchase_orders SET ' . implode(', ', $sets) . ' WHERE id=? AND tenant_id=?',
            $bind
        );

        return ['ok' => true, 'message' => 'Purchase order saved.'];
    }

    /**
     * @param array<int,int> $receiveByItemId
     * @return array{ok:bool, message:string, received?:int}
     */
    public function receive(int $id, array $receiveByItemId): array
    {
        $po = $this->get($id);
        if ($po === null) {
            return ['ok' => false, 'message' => 'Purchase order not found.'];
        }
        $status = (string) ($po['status'] ?? '');
        if (!in_array($status, ['ordered', 'partial', 'draft'], true)) {
            return ['ok' => false, 'message' => 'Only draft/ordered/partial POs can be received.'];
        }

        $items = $this->items($id);
        if ($items === []) {
            return ['ok' => false, 'message' => 'Purchase order has no lines.'];
        }

        $tenantId = $this->app->tenant->id();
        $receivedNow = 0;
        $stockProductIds = [];

        foreach ($items as $item) {
            $itemId = (int) $item['id'];
            $qtyNow = max(0, (int) ($receiveByItemId[$itemId] ?? 0));
            if ($qtyNow <= 0) {
                continue;
            }
            $ordered = (int) ($item['qty_ordered'] ?? 0);
            $already = (int) ($item['qty_received'] ?? 0);
            $remaining = max(0, $ordered - $already);
            if ($remaining <= 0) {
                continue;
            }
            $qtyNow = min($qtyNow, $remaining);
            $newReceived = $already + $qtyNow;

            $this->app->db->query(
                'UPDATE #__store_purchase_order_items SET qty_received=? WHERE id=? AND tenant_id=?',
                [$newReceived, $itemId, $tenantId]
            );

            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku !== '') {
                    $product = $this->app->db->query(
                        'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
                        [$tenantId, $sku]
                    )->row;
                    $productId = (int) ($product['id'] ?? 0);
                    if ($productId > 0) {
                        $this->app->db->query(
                            'UPDATE #__store_purchase_order_items SET product_id=? WHERE id=? AND tenant_id=?',
                            [$productId, $itemId, $tenantId]
                        );
                    }
                }
            }

            if ($productId > 0) {
                $this->addStock($tenantId, $productId, $qtyNow);
                $stockProductIds[] = $productId;
                $unitCost = (float) ($item['unit_cost'] ?? 0);
                $saveCost = true;
                if (!empty($po['supplier_id'])) {
                    $supFlag = $this->app->db->query(
                        'SELECT save_cost_to_product FROM #__store_suppliers WHERE id=? AND tenant_id=? LIMIT 1',
                        [(int) $po['supplier_id'], $tenantId]
                    )->row;
                    if ($supFlag !== []) {
                        $saveCost = (int) ($supFlag['save_cost_to_product'] ?? 1) === 1;
                    }
                }
                if ($saveCost && $unitCost > 0) {
                    $this->app->db->query(
                        'UPDATE #__store_products SET cost=? WHERE id=? AND tenant_id=?',
                        [$unitCost, $productId, $tenantId]
                    );
                }
            }

            $receivedNow += $qtyNow;
        }

        if ($receivedNow <= 0) {
            return ['ok' => false, 'message' => 'Enter qty to receive on at least one line.'];
        }

        $fresh = $this->items($id);
        $allDone = true;
        foreach ($fresh as $item) {
            if ((int) ($item['qty_received'] ?? 0) < (int) ($item['qty_ordered'] ?? 0)) {
                $allDone = false;
                break;
            }
        }

        if ($allDone) {
            $this->app->db->query(
                "UPDATE #__store_purchase_orders
                 SET status='received', received_at=NOW(), ordered_at=COALESCE(ordered_at, NOW())
                 WHERE id=? AND tenant_id=?",
                [$id, $tenantId]
            );
            $msg = "Received {$receivedNow} unit(s). PO fully received — stock updated.";
        } else {
            $this->app->db->query(
                "UPDATE #__store_purchase_orders
                 SET status='partial', ordered_at=COALESCE(ordered_at, NOW())
                 WHERE id=? AND tenant_id=?",
                [$id, $tenantId]
            );
            $msg = "Received {$receivedNow} unit(s). PO partially received — stock updated.";
        }

        if ($stockProductIds !== []) {
            try {
                $warnings = (new ChannelStockService($this->app))->pushProducts($stockProductIds);
            } catch (\Throwable $e) {
                $warnings = ['Channel stock push failed: ' . $e->getMessage()];
            }
            if ($warnings !== []) {
                $msg .= ' ' . implode(' ', $warnings);
            }
        }

        return ['ok' => true, 'message' => $msg, 'received' => $receivedNow];
    }

    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed>|null $existing
     * @return array<string, mixed>
     */
    private function headerColumns(
        array $header,
        string $status,
        string $reference,
        float $total,
        bool $isCreate,
        ?array $existing = null
    ): array {
        $dateOrNull = static function (mixed $v): ?string {
            $v = trim((string) $v);
            return $v !== '' ? $v : null;
        };
        $ex = $existing ?? [];

        $fields = [
            'supplier_id'            => (int) ($header['supplier_id'] ?? $ex['supplier_id'] ?? 0),
            'warehouse_id'           => ((int) ($header['warehouse_id'] ?? $ex['warehouse_id'] ?? 0)) ?: null,
            'reference'              => $reference,
            'status'                 => $status,
            'total'                  => round($total, 2),
            'currency'               => strtoupper(substr((string) ($header['currency'] ?? $ex['currency'] ?? 'GBP'), 0, 8)),
            'delivery_cost'          => round((float) ($header['delivery_cost'] ?? $ex['delivery_cost'] ?? 0), 2),
            'include_delivery_cost'  => !empty($header['include_delivery_cost']) ? 1 : 0,
            'expected_at'            => $dateOrNull($header['expected_at'] ?? $ex['expected_at'] ?? ''),
            'date_sent'              => $dateOrNull($header['date_sent'] ?? $ex['date_sent'] ?? ''),
            'paid_on'                => $dateOrNull($header['paid_on'] ?? $ex['paid_on'] ?? ''),
            'payment_reference'      => trim((string) ($header['payment_reference'] ?? $ex['payment_reference'] ?? '')),
            'payment_confirmed_by'   => trim((string) ($header['payment_confirmed_by'] ?? $ex['payment_confirmed_by'] ?? '')),
            'change_to_status'       => trim((string) ($header['change_to_status'] ?? $ex['change_to_status'] ?? '')),
            'palletized'             => !empty($header['palletized']) ? 1 : 0,
            'estimated_pallets'      => max(0, (int) ($header['estimated_pallets'] ?? $ex['estimated_pallets'] ?? 0)),
            'notes'                  => trim((string) ($header['notes'] ?? $ex['notes'] ?? '')),
            'delivery_company'       => trim((string) ($header['delivery_company'] ?? $ex['delivery_company'] ?? '')),
            'delivery_line1'         => trim((string) ($header['delivery_line1'] ?? $ex['delivery_line1'] ?? '')),
            'delivery_line2'         => trim((string) ($header['delivery_line2'] ?? $ex['delivery_line2'] ?? '')),
            'delivery_city'          => trim((string) ($header['delivery_city'] ?? $ex['delivery_city'] ?? '')),
            'delivery_country'       => strtoupper(substr(trim((string) ($header['delivery_country'] ?? $ex['delivery_country'] ?? 'GB')) ?: 'GB', 0, 64)),
            'delivery_postcode'      => strtoupper(trim((string) ($header['delivery_postcode'] ?? $ex['delivery_postcode'] ?? ''))),
        ];

        if ($isCreate && in_array($status, ['ordered', 'partial', 'received'], true)) {
            $fields['ordered_at'] = date('Y-m-d H:i:s');
        }

        return $fields;
    }

    private function addStock(int $tenantId, int $productId, int $qty): void
    {
        $product = $this->app->db->query(
            'SELECT id, parent_id, inventory_source_id, inventory_unit_factor
             FROM #__store_products WHERE id=? AND tenant_id=? LIMIT 1',
            [$productId, $tenantId]
        )->row;
        if ($product === []) {
            return;
        }

        // Longer cut sizes credit the base SKU stock (e.g. 3.7m → 1m × factor).
        $sourceId = (int) ($product['inventory_source_id'] ?? 0);
        $factor = (float) ($product['inventory_unit_factor'] ?? 1);
        if ($factor <= 0) {
            $factor = 1.0;
        }
        $targetId = $sourceId > 0 ? $sourceId : $productId;
        $addQty = $sourceId > 0 ? (int) round($qty * $factor) : $qty;

        $locations = new StockLocationService($this->app);
        $invId = $locations->ensureInventoryRow($tenantId, $targetId);
        if ($invId > 0) {
            $this->app->db->query(
                'UPDATE #__store_inventory SET qty_on_hand = qty_on_hand + ? WHERE id=?',
                [$addQty, $invId]
            );
        } else {
            $this->app->db->query(
                'INSERT INTO #__store_inventory (tenant_id, product_id, qty_on_hand, qty_allocated) VALUES (?, ?, ?, 0)',
                [$tenantId, $targetId, $addQty]
            );
        }

        // If this is a variant, roll stock up to the main SKU (owners only).
        $parentId = (int) ($product['parent_id'] ?? 0);
        if ($parentId > 0) {
            $sum = (int) ($this->app->db->query(
                "SELECT COALESCE(SUM(i.qty_on_hand), 0) AS c
                 FROM #__store_products p
                 LEFT JOIN #__store_inventory i ON i.product_id = p.id AND i.tenant_id = p.tenant_id
                 WHERE p.tenant_id=? AND p.parent_id=?
                   AND (p.inventory_source_id IS NULL OR p.inventory_source_id = 0)",
                [$tenantId, $parentId]
            )->row['c'] ?? 0);
            $parentInvId = $locations->ensureInventoryRow($tenantId, $parentId);
            if ($parentInvId <= 0) {
                $this->app->db->query(
                    'INSERT INTO #__store_inventory (tenant_id, product_id, qty_on_hand, qty_allocated) VALUES (?, ?, ?, 0)',
                    [$tenantId, $parentId, $sum]
                );
            } else {
                $this->app->db->query(
                    'UPDATE #__store_inventory SET qty_on_hand=? WHERE id=? AND tenant_id=?',
                    [$sum, $parentInvId, $tenantId]
                );
            }
        }
    }

    /**
     * @param list<array{sku:string,name?:string,qty:int,unit_cost:float}> $lines
     * @return list<array{product_id:?int,sku:string,name:string,qty_ordered:int,unit_cost:float,line_total:float}>
     */
    private function normalizeLines(array $lines): array
    {
        $tenantId = $this->app->tenant->id();
        $out = [];
        foreach ($lines as $line) {
            $sku = trim((string) ($line['sku'] ?? ''));
            $qty = (int) ($line['qty'] ?? 0);
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $unit = round((float) ($line['unit_cost'] ?? 0), 2);
            $name = trim((string) ($line['name'] ?? ''));
            $productId = null;
            $product = $this->app->db->query(
                'SELECT id, name, cost FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
                [$tenantId, $sku]
            )->row;
            if ($product !== []) {
                $productId = (int) $product['id'];
                if ($name === '') {
                    $name = (string) ($product['name'] ?? $sku);
                }
                if ($unit <= 0 && (float) ($product['cost'] ?? 0) > 0) {
                    $unit = round((float) $product['cost'], 2);
                }
            }
            if ($name === '') {
                $name = $sku;
            }
            $out[] = [
                'product_id'  => $productId,
                'sku'         => $sku,
                'name'        => $name,
                'qty_ordered' => $qty,
                'unit_cost'   => $unit,
                'line_total'  => round($unit * $qty, 2),
            ];
        }
        return $out;
    }

    /**
     * @param list<array{product_id:?int,sku:string,name:string,qty_ordered:int,unit_cost:float,line_total:float}> $parsed
     */
    private function replaceItems(int $poId, array $parsed, bool $preserveReceived = false): void
    {
        $tenantId = $this->app->tenant->id();
        $receivedMap = [];
        if ($preserveReceived) {
            foreach ($this->items($poId) as $existing) {
                $receivedMap[strtoupper((string) $existing['sku'])] = (int) ($existing['qty_received'] ?? 0);
            }
        }

        $this->app->db->query(
            'DELETE FROM #__store_purchase_order_items WHERE purchase_order_id=? AND tenant_id=?',
            [$poId, $tenantId]
        );

        foreach ($parsed as $line) {
            $prevRecv = $preserveReceived
                ? min((int) ($receivedMap[strtoupper($line['sku'])] ?? 0), $line['qty_ordered'])
                : 0;
            $this->app->db->query(
                "INSERT INTO #__store_purchase_order_items
                 (tenant_id, purchase_order_id, product_id, sku, name, qty_ordered, qty_received, unit_cost, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $tenantId,
                    $poId,
                    $line['product_id'],
                    $line['sku'],
                    $line['name'],
                    $line['qty_ordered'],
                    $prevRecv,
                    $line['unit_cost'],
                    $line['line_total'],
                ]
            );
        }
    }

    private function normalizeStatus(string $status, string $fallback): string
    {
        $allowed = ['draft', 'ordered', 'partial', 'received', 'cancelled'];
        return in_array($status, $allowed, true) ? $status : $fallback;
    }
}
