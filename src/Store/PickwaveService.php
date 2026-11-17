<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

final class PickwaveService
{
    public function __construct(private App $app) {}

    /**
     * Create a named pickwave from selected orders. Marks eligible orders as processing.
     *
     * @param list<int> $orderIds
     * @return array{id:int,reference:string,order_count:int,attached:int,skipped:int}
     */
    public function create(array $orderIds, ?int $createdBy = null, ?string $name = null): array
    {
        $tenantId = $this->app->tenant->id();
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new \InvalidArgumentException('Select orders to add to pickwave.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $eligible = $this->app->db->query(
            "SELECT o.id
             FROM #__store_orders o
             WHERE o.id IN ({$placeholders})
               AND o.tenant_id = ?
               AND o.status IN ('awaiting_despatch','printed','processing')
               AND NOT EXISTS (
                 SELECT 1 FROM #__store_pickwave_orders po
                 WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
               )",
            array_merge($ids, [$tenantId])
        )->rows;
        $eligibleIds = array_map(static fn (array $r): int => (int) $r['id'], $eligible);
        if ($eligibleIds === []) {
            throw new \InvalidArgumentException(
                'No eligible orders for pickwave. Selected orders are already on a pickwave, or not awaiting despatch/printed.'
            );
        }

        $skipped = count($ids) - count($eligibleIds);

        $reference = $this->resolveReference($tenantId, $name);
        $this->app->db->query(
            'INSERT INTO #__store_pickwaves (tenant_id, reference, status, order_count, created_by)
             VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $reference, 'open', count($eligibleIds), $createdBy]
        );
        $pickwaveId = (int) $this->app->db->pdo()->lastInsertId();

        foreach ($eligibleIds as $oid) {
            $this->app->db->query(
                'INSERT IGNORE INTO #__store_pickwave_orders (pickwave_id, order_id, tenant_id) VALUES (?, ?, ?)',
                [$pickwaveId, $oid, $tenantId]
            );
        }

        $ep = implode(',', array_fill(0, count($eligibleIds), '?'));
        $this->app->db->query(
            "UPDATE #__store_orders SET status='processing'
             WHERE id IN ({$ep}) AND tenant_id=? AND status IN ('awaiting_despatch','printed')",
            array_merge($eligibleIds, [$tenantId])
        );

        $inventory = new InventoryService($this->app);
        $dropships = new DropshipService($this->app);
        foreach ($eligibleIds as $oid) {
            $dropships->ensureForOrder($oid);
            $inventory->allocateOrder($oid);
        }

        return [
            'id'          => $pickwaveId,
            'reference'   => $reference,
            'order_count' => count($eligibleIds),
            'attached'    => count($eligibleIds),
            'skipped'     => max(0, $skipped),
        ];
    }

    /**
     * @param 'all'|'open'|'completed' $status
     * @return list<array<string,mixed>>
     */
    public function list(int $limit = 50, string $status = 'all'): array
    {
        $params = [$this->app->tenant->id()];
        $where = 'p.tenant_id = ?';
        if ($status === 'open') {
            $where .= " AND p.status = 'open'";
        } elseif ($status === 'completed') {
            $where .= " AND p.status = 'closed'";
        }
        $limit = max(1, min(5000, $limit));

        return $this->app->db->query(
            "SELECT p.*, u.display_name AS created_by_name,
                    (SELECT COUNT(*) FROM #__store_pickwave_orders po
                     INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
                     WHERE po.pickwave_id = p.id AND o.status = 'despatched') AS despatched_count,
                    (SELECT o.warehouse FROM #__store_pickwave_orders po
                     INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
                     WHERE po.pickwave_id = p.id
                     ORDER BY po.added_at ASC LIMIT 1) AS warehouse_name
             FROM #__store_pickwaves p
             LEFT JOIN #__users u ON u.id = p.created_by
             WHERE {$where}
             ORDER BY COALESCE(p.closed_at, p.created_at) DESC, p.id DESC
             LIMIT {$limit}",
            $params
        )->rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->app->db->query(
            'SELECT p.*, u.display_name AS created_by_name
             FROM #__store_pickwaves p
             LEFT JOIN #__users u ON u.id = p.created_by
             WHERE p.id = ? AND p.tenant_id = ?
             LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        return $row ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function orders(int $pickwaveId): array
    {
        return $this->app->db->query(
            'SELECT o.id, o.external_order_id, o.status, o.buyer_email, o.buyer_username,
                    o.shipping_name, o.total, o.created_at, o.tracking_number,
                    c.name AS channel_name, c.code AS channel_code
             FROM #__store_pickwave_orders po
             INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE po.pickwave_id = ? AND po.tenant_id = ?
             ORDER BY o.id ASC',
            [$pickwaveId, $this->app->tenant->id()]
        )->rows;
    }

    /** @return list<int> */
    public function orderIds(int $pickwaveId, ?array $onlyIds = null): array
    {
        $tenantId = $this->app->tenant->id();
        $rows = $this->orders($pickwaveId);
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($onlyIds !== null) {
            $allow = array_fill_keys(array_map('intval', $onlyIds), true);
            $ids = array_values(array_filter($ids, static fn (int $id): bool => isset($allow[$id])));
        }
        return $ids;
    }

    /**
     * Aggregated pick lines for a wave, sorted by bin then SKU.
     * Skips cancelled / refunded / despatched orders.
     *
     * @return list<array{product_id:int,sku:string,name:string,pick_bin:string,qty:int,order_count:int}>
     */
    public function pickLines(int $pickwaveId): array
    {
        $rows = $this->app->db->query(
            "SELECT
                COALESCE(oi.product_id, 0) AS product_id,
                COALESCE(NULLIF(TRIM(p.sku), ''), NULLIF(TRIM(oi.sku), ''), '') AS sku,
                COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(oi.name), ''), '') AS name,
                COALESCE(NULLIF(TRIM(p.pick_bin), ''), '') AS pick_bin,
                SUM(oi.qty) AS qty,
                COUNT(DISTINCT o.id) AS order_count
             FROM #__store_pickwave_orders po
             INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
             INNER JOIN #__store_order_items oi ON oi.order_id = o.id
             LEFT JOIN #__store_products p ON p.id = oi.product_id AND p.tenant_id = po.tenant_id
             WHERE po.pickwave_id = ? AND po.tenant_id = ?
               AND o.status NOT IN ('cancelled','refunded','despatched')
             GROUP BY COALESCE(oi.product_id, 0),
                      COALESCE(NULLIF(TRIM(p.sku), ''), NULLIF(TRIM(oi.sku), ''), ''),
                      COALESCE(NULLIF(TRIM(p.name), ''), NULLIF(TRIM(oi.name), ''), ''),
                      COALESCE(NULLIF(TRIM(p.pick_bin), ''), '')
             ORDER BY CASE WHEN COALESCE(NULLIF(TRIM(p.pick_bin), ''), '') = '' THEN 1 ELSE 0 END ASC,
                      COALESCE(NULLIF(TRIM(p.pick_bin), ''), '') ASC,
                      COALESCE(NULLIF(TRIM(p.sku), ''), NULLIF(TRIM(oi.sku), ''), '') ASC",
            [$pickwaveId, $this->app->tenant->id()]
        )->rows;

        return array_map(static function (array $r): array {
            return [
                'product_id'  => (int) ($r['product_id'] ?? 0),
                'sku'         => (string) ($r['sku'] ?? ''),
                'name'        => (string) ($r['name'] ?? ''),
                'pick_bin'    => (string) ($r['pick_bin'] ?? ''),
                'qty'         => (int) ($r['qty'] ?? 0),
                'order_count' => (int) ($r['order_count'] ?? 0),
            ];
        }, $rows);
    }

    /**
     * Pick lines plus qty_confirmed from scans.
     *
     * @return list<array{product_id:int,sku:string,name:string,pick_bin:string,qty:int,order_count:int,qty_confirmed:int}>
     */
    public function pickProgress(int $pickwaveId): array
    {
        $lines = $this->pickLines($pickwaveId);
        $confirmed = $this->confirmedBySku($pickwaveId);
        foreach ($lines as &$line) {
            $sku = (string) ($line['sku'] ?? '');
            $line['qty_confirmed'] = (int) ($confirmed[$sku] ?? 0);
        }
        unset($line);
        return $lines;
    }

    /**
     * @return array{required:int,confirmed:int,lines_done:int,lines_total:int,complete:bool}
     */
    public function scanSummary(int $pickwaveId): array
    {
        $lines = $this->pickProgress($pickwaveId);
        $required = 0;
        $confirmed = 0;
        $done = 0;
        foreach ($lines as $line) {
            $qty = (int) ($line['qty'] ?? 0);
            $got = min($qty, (int) ($line['qty_confirmed'] ?? 0));
            $required += $qty;
            $confirmed += $got;
            if ($qty > 0 && $got >= $qty) {
                $done++;
            }
        }
        $total = count($lines);
        return [
            'required'    => $required,
            'confirmed'   => $confirmed,
            'lines_done'  => $done,
            'lines_total' => $total,
            'complete'    => $total > 0 && $done === $total && $required > 0,
        ];
    }

    /**
     * Confirm one unit for a scanned SKU or barcode on this wave.
     *
     * @return array{ok:bool,message:string,sku?:string,qty_confirmed?:int,qty?:int}
     */
    public function scanConfirm(int $pickwaveId, string $code): array
    {
        $this->requireOpen($pickwaveId);
        $code = trim($code);
        if ($code === '') {
            return ['ok' => false, 'message' => 'Scan a SKU or barcode.'];
        }

        $match = $this->matchPickLine($pickwaveId, $code);
        if ($match === null) {
            return ['ok' => false, 'message' => 'No open pick line matches "' . $code . '".'];
        }

        $sku = (string) $match['sku'];
        $needed = (int) $match['qty'];
        $tenantId = $this->app->tenant->id();
        $current = (int) ($this->confirmedBySku($pickwaveId)[$sku] ?? 0);
        if ($current >= $needed) {
            return [
                'ok'            => false,
                'message'       => 'Already fully confirmed for ' . $sku . ' (' . $needed . ').',
                'sku'           => $sku,
                'qty_confirmed' => $current,
                'qty'           => $needed,
            ];
        }

        $newQty = $current + 1;
        $this->app->db->query(
            'INSERT INTO #__store_pickwave_scans (tenant_id, pickwave_id, product_id, sku, qty_confirmed)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty_confirmed = VALUES(qty_confirmed), product_id = VALUES(product_id)',
            [$tenantId, $pickwaveId, (int) $match['product_id'], $sku, $newQty]
        );

        return [
            'ok'            => true,
            'message'       => 'Confirmed ' . $sku . ' (' . $newQty . '/' . $needed . ').',
            'sku'           => $sku,
            'qty_confirmed' => $newQty,
            'qty'           => $needed,
        ];
    }

    /**
     * @return array{product_id:int,sku:string,name:string,pick_bin:string,qty:int,order_count:int}|null
     */
    private function matchPickLine(int $pickwaveId, string $code): ?array
    {
        $needle = strtolower(trim($code));
        $lines = $this->pickLines($pickwaveId);
        foreach ($lines as $line) {
            if (strtolower((string) $line['sku']) === $needle) {
                return $line;
            }
        }

        $tenantId = $this->app->tenant->id();
        $product = $this->app->db->query(
            'SELECT id, sku FROM #__store_products
             WHERE tenant_id=? AND (sku=? OR barcode=?)
             LIMIT 1',
            [$tenantId, $code, $code]
        )->row;
        if ($product === []) {
            return null;
        }
        $sku = strtolower((string) ($product['sku'] ?? ''));
        $pid = (int) ($product['id'] ?? 0);
        foreach ($lines as $line) {
            if ((int) $line['product_id'] === $pid || strtolower((string) $line['sku']) === $sku) {
                return $line;
            }
        }
        return null;
    }

    /** @return array<string,int> sku => qty_confirmed */
    private function confirmedBySku(int $pickwaveId): array
    {
        $rows = $this->app->db->query(
            'SELECT sku, qty_confirmed FROM #__store_pickwave_scans
             WHERE pickwave_id=? AND tenant_id=?',
            [$pickwaveId, $this->app->tenant->id()]
        )->rows;
        $out = [];
        foreach ($rows as $r) {
            $sku = (string) ($r['sku'] ?? '');
            if ($sku !== '') {
                $out[$sku] = (int) ($r['qty_confirmed'] ?? 0);
            }
        }
        return $out;
    }

    /**
     * Mark pickwave orders as printed (warehouse print step).
     *
     * @param list<int>|null $onlyIds
     * @return array{updated:int,reference:string}
     */
    public function markPrinted(int $pickwaveId, ?array $onlyIds = null): array
    {
        $wave = $this->requireOpen($pickwaveId);
        $ids = $this->orderIds($pickwaveId, $onlyIds);
        if ($ids === []) {
            throw new \InvalidArgumentException('No orders to mark printed.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->app->db->query(
            "UPDATE #__store_orders SET status='printed'
             WHERE id IN ({$placeholders}) AND tenant_id=?
               AND status IN ('awaiting_despatch','processing','printed')",
            array_merge($ids, [$this->app->tenant->id()])
        );

        $inventory = new InventoryService($this->app);
        $dropships = new DropshipService($this->app);
        foreach ($ids as $oid) {
            $dropships->ensureForOrder($oid);
            $inventory->allocateOrder($oid);
        }

        return ['updated' => count($ids), 'reference' => (string) $wave['reference']];
    }

    /**
     * Despatch pickwave orders; auto-close wave when none remain undespached.
     *
     * @param list<int>|null $onlyIds
     * @return array{despatched:int,mapped:int,closed:bool,reference:string,order_ids:list<int>}
     */
    public function despatch(
        int $pickwaveId,
        string $trackingPrefix = 'RM',
        string $defaultCarrier = 'royal_mail',
        bool $applyRules = true,
        ?array $onlyIds = null,
        bool $buyCarrierLabel = true
    ): array {
        $wave = $this->requireOpen($pickwaveId);
        $ids = $this->orderIds($pickwaveId, $onlyIds);
        if ($ids === []) {
            throw new \InvalidArgumentException('No orders to despatch on this pickwave.');
        }

        $result = (new DespatchService($this->app))->despatchOrders(
            $ids,
            $trackingPrefix,
            $defaultCarrier,
            $applyRules,
            $buyCarrierLabel
        );

        $remaining = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_pickwave_orders po
             INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
             WHERE po.pickwave_id=? AND po.tenant_id=?
               AND o.status NOT IN ('despatched','cancelled','refunded')",
            [$pickwaveId, $this->app->tenant->id()]
        )->row['c'] ?? 0);

        $closed = false;
        if ($remaining === 0) {
            $closed = $this->close($pickwaveId);
        }

        return [
            'despatched'     => $result['despatched'],
            'mapped'         => $result['mapped'],
            'closed'         => $closed,
            'reference'      => (string) $wave['reference'],
            'order_ids'      => $ids,
            'stock_warnings'       => $result['stock_warnings'] ?? [],
            'fulfillment_warnings' => $result['fulfillment_warnings'] ?? [],
            'label_warnings'       => $result['label_warnings'] ?? [],
        ];
    }

    /**
     * @param 'open'|'completed'|'recent' $tab
     * @return list<array<string,mixed>>
     */
    public function listForPicking(string $tab, ?int $limit = null): array
    {
        $status = $tab === 'open' ? 'open' : 'closed';
        if ($limit === null) {
            $limit = match ($tab) {
                'recent' => 10,
                'completed' => 2000,
                default => 100,
            };
        }
        $limit = max(1, min(5000, $limit));

        return $this->app->db->query(
            "SELECT p.*, u.display_name AS created_by_name,
                    (SELECT COUNT(*) FROM #__store_pickwave_orders po
                     INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
                     WHERE po.pickwave_id = p.id AND o.status = 'despatched') AS despatched_count,
                    (SELECT o.warehouse FROM #__store_pickwave_orders po
                     INNER JOIN #__store_orders o ON o.id = po.order_id AND o.tenant_id = po.tenant_id
                     WHERE po.pickwave_id = p.id
                     ORDER BY po.added_at ASC LIMIT 1) AS warehouse_name
             FROM #__store_pickwaves p
             LEFT JOIN #__users u ON u.id = p.created_by
             WHERE p.tenant_id = ? AND p.status = ?
             ORDER BY COALESCE(p.closed_at, p.created_at) DESC, p.id DESC
             LIMIT {$limit}",
            [$this->app->tenant->id(), $status]
        )->rows;
    }

    /** @return array<string,int> */
    public function pickingStats(): array
    {
        $tenantId = $this->app->tenant->id();
        $count = function (string $sql, array $params = []) use ($tenantId): int {
            return (int) ($this->app->db->query($sql, array_merge([$tenantId], $params))->row['c'] ?? 0);
        };

        $outstanding = $count(
            "SELECT COUNT(*) AS c FROM #__store_orders o
             WHERE o.tenant_id = ? AND o.status IN ('awaiting_despatch','printed','processing')
               AND NOT EXISTS (
                 SELECT 1 FROM #__store_pickwave_orders po
                 WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
               )"
        );
        $openWaves = $count("SELECT COUNT(*) AS c FROM #__store_pickwaves WHERE tenant_id = ? AND status = 'open'");
        $pickedSql = "SELECT COUNT(*) AS c FROM #__store_orders
             WHERE tenant_id = ? AND (
               (status = 'printed' AND updated_at >= ?)
               OR (status = 'despatched' AND COALESCE(shipped_at, updated_at) >= ?)
             )";
        $pickedToday = $count($pickedSql, [date('Y-m-d 00:00:00'), date('Y-m-d 00:00:00')]);
        $since48 = date('Y-m-d H:i:s', time() - 48 * 3600);
        $picked48 = $count($pickedSql, [$since48, $since48]);
        $sinceWeek = date('Y-m-d H:i:s', time() - 7 * 86400);
        $pickedWeek = $count($pickedSql, [$sinceWeek, $sinceWeek]);

        $avgRow = $this->app->db->query(
            "SELECT COUNT(po.order_id) AS orders, COUNT(DISTINCT p.created_by) AS pickers,
                    AVG(TIMESTAMPDIFF(MINUTE, p.created_at, p.closed_at)) AS mins
             FROM #__store_pickwaves p
             LEFT JOIN #__store_pickwave_orders po ON po.pickwave_id = p.id AND po.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.status = 'closed' AND p.closed_at >= ?",
            [$tenantId, $sinceWeek]
        )->row;
        $pickers = (int) ($avgRow['pickers'] ?? 0);
        $avgOrders = $pickers > 0 ? (int) round(((int) ($avgRow['orders'] ?? 0)) / $pickers) : 0;
        $avgMins = (int) round((float) ($avgRow['mins'] ?? 0));

        $remaining = $count(
            "SELECT COUNT(*) AS c FROM #__store_orders o
             INNER JOIN #__store_pickwave_orders po ON po.order_id = o.id AND po.tenant_id = o.tenant_id
             INNER JOIN #__store_pickwaves p ON p.id = po.pickwave_id AND p.status = 'open'
             WHERE o.tenant_id = ? AND o.status IN ('awaiting_despatch','processing')"
        );

        return [
            'outstanding'  => $outstanding,
            'open_waves'   => $openWaves,
            'picked_today' => $pickedToday,
            'picked_48h'   => $picked48,
            'picked_week'  => $pickedWeek,
            'avg_orders'   => $avgOrders,
            'avg_minutes'  => $avgMins,
            'remaining'    => $remaining,
        ];
    }

    public function openCount(): int
    {
        return (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_pickwaves WHERE tenant_id=? AND status='open'",
            [$this->app->tenant->id()]
        )->row['c'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function requireOpen(int $id): array
    {
        $wave = $this->find($id);
        if ($wave === null || (string) ($wave['status'] ?? '') !== 'open') {
            throw new \InvalidArgumentException('Pickwave is closed or not found.');
        }
        return $wave;
    }

    public function close(int $id): bool
    {
        $tenantId = $this->app->tenant->id();
        $open = $this->app->db->query(
            "SELECT id FROM #__store_pickwaves WHERE id=? AND tenant_id=? AND status='open' LIMIT 1",
            [$id, $tenantId]
        )->row;
        if (!$open) {
            return false;
        }
        $this->app->db->query(
            "UPDATE #__store_pickwaves SET status='closed', closed_at=NOW()
             WHERE id=? AND tenant_id=? AND status='open'",
            [$id, $tenantId]
        );
        return true;
    }

    /**
     * Remove an order from an open pickwave and return it to the picking pool.
     */
    public function removeOrder(int $pickwaveId, int $orderId): bool
    {
        $tenantId = $this->app->tenant->id();
        $wave = $this->app->db->query(
            "SELECT id FROM #__store_pickwaves WHERE id=? AND tenant_id=? AND status='open' LIMIT 1",
            [$pickwaveId, $tenantId]
        )->row;
        if (!$wave) {
            throw new \InvalidArgumentException('Pickwave is closed or not found.');
        }

        $link = $this->app->db->query(
            'SELECT order_id FROM #__store_pickwave_orders
             WHERE pickwave_id=? AND order_id=? AND tenant_id=? LIMIT 1',
            [$pickwaveId, $orderId, $tenantId]
        )->row;
        if (!$link) {
            throw new \InvalidArgumentException('Order is not on this pickwave.');
        }

        $this->app->db->query(
            'DELETE FROM #__store_pickwave_orders WHERE pickwave_id=? AND order_id=? AND tenant_id=?',
            [$pickwaveId, $orderId, $tenantId]
        );

        $left = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_pickwave_orders WHERE pickwave_id=? AND tenant_id=?',
            [$pickwaveId, $tenantId]
        )->row['c'] ?? 0);
        $this->app->db->query(
            'UPDATE #__store_pickwaves SET order_count=? WHERE id=? AND tenant_id=?',
            [$left, $pickwaveId, $tenantId]
        );

        // Return to picking pool if still in warehouse flow
        $this->app->db->query(
            "UPDATE #__store_orders SET status='awaiting_despatch'
             WHERE id=? AND tenant_id=? AND status IN ('processing','printed')",
            [$orderId, $tenantId]
        );

        // Plan: release on remove; re-allocate while still warehouse-ready.
        $inventory = new InventoryService($this->app);
        $inventory->releaseOrder($orderId);
        $inventory->allocateOrder($orderId);

        return true;
    }

    public function rename(int $id, string $name): bool
    {
        $tenantId = $this->app->tenant->id();
        $wave = $this->find($id);
        if ($wave === null || (string) ($wave['status'] ?? '') !== 'open') {
            throw new \InvalidArgumentException('Pickwave is closed or not found.');
        }
        $reference = $this->resolveReference($tenantId, $name, $id);
        $this->app->db->query(
            'UPDATE #__store_pickwaves SET reference=? WHERE id=? AND tenant_id=?',
            [$reference, $id, $tenantId]
        );
        return true;
    }

    private function resolveReference(int $tenantId, ?string $name, ?int $excludeId = null): string
    {
        $custom = trim((string) $name);
        if ($custom === '') {
            return $this->nextReference($tenantId);
        }
        $custom = preg_replace('/\s+/', ' ', $custom) ?? $custom;
        $custom = mb_substr($custom, 0, 64);
        if ($custom === '') {
            return $this->nextReference($tenantId);
        }

        $sql = 'SELECT id FROM #__store_pickwaves WHERE tenant_id=? AND reference=?';
        $params = [$tenantId, $custom];
        if ($excludeId !== null) {
            $sql .= ' AND id<>?';
            $params[] = $excludeId;
        }
        $sql .= ' LIMIT 1';
        $exists = $this->app->db->query($sql, $params)->row;
        if ($exists) {
            throw new \InvalidArgumentException('Pickwave name "' . $custom . '" is already used.');
        }
        return $custom;
    }

    private function nextReference(int $tenantId): string
    {
        $day = date('Ymd');
        $prefix = 'PW-' . $day . '-';
        $row = $this->app->db->query(
            "SELECT reference FROM #__store_pickwaves
             WHERE tenant_id=? AND reference LIKE ?
             ORDER BY id DESC LIMIT 1",
            [$tenantId, $prefix . '%']
        )->row;
        $seq = 1;
        if ($row && preg_match('/PW-\d{8}-(\d+)$/', (string) $row['reference'], $m)) {
            $seq = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
