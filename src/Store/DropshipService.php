<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

/**
 * Create and manage store_dropships from order lines linked to dropship suppliers.
 */
final class DropshipService
{
    private const STATUSES = ['pending', 'sent', 'shipped', 'cancelled'];

    public function __construct(private App $app) {}

    /**
     * Idempotent: one dropship row per order + dropship-supplier.
     *
     * @return array{created:int, existing:int, dropship_ids:list<int>}
     */
    public function ensureForOrder(int $orderId): array
    {
        $tenantId = $this->app->tenant->id();
        if ($orderId <= 0) {
            return ['created' => 0, 'existing' => 0, 'dropship_ids' => []];
        }

        $order = $this->app->db->query(
            'SELECT id, status, external_order_id FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            return ['created' => 0, 'existing' => 0, 'dropship_ids' => []];
        }

        $groups = $this->dropshipGroupsForOrder($tenantId, $orderId);
        $created = 0;
        $existing = 0;
        $ids = [];

        foreach ($groups as $supplierId => $meta) {
            $row = $this->app->db->query(
                'SELECT id, status FROM #__store_dropships
                 WHERE tenant_id=? AND order_id=? AND supplier_id=?
                 ORDER BY id DESC LIMIT 1',
                [$tenantId, $orderId, $supplierId]
            )->row;

            if ($row !== []) {
                $existing++;
                $ids[] = (int) $row['id'];
                continue;
            }

            $initial = strtolower(trim((string) ($meta['change_status'] ?? '')));
            if (!in_array($initial, self::STATUSES, true)) {
                $initial = 'pending';
            }

            $ref = $this->makeReference($order, $supplierId);
            $ins = $this->app->db->query(
                'INSERT INTO #__store_dropships (tenant_id, order_id, supplier_id, reference, status)
                 VALUES (?, ?, ?, ?, ?)',
                [$tenantId, $orderId, $supplierId, $ref, $initial]
            );
            $did = (int) ($ins->insert_id ?: 0);
            if ($did > 0) {
                $created++;
                $ids[] = $did;
            }
        }

        return [
            'created'      => $created,
            'existing'     => $existing,
            'dropship_ids' => $ids,
        ];
    }

    /**
     * @return array{ok:bool, message:string, status?:string}
     */
    public function updateStatus(int $dropshipId, string $status): array
    {
        $tenantId = $this->app->tenant->id();
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'message' => 'Invalid dropship status.'];
        }

        $row = $this->app->db->query(
            'SELECT id FROM #__store_dropships WHERE id=? AND tenant_id=? LIMIT 1',
            [$dropshipId, $tenantId]
        )->row;
        if ($row === []) {
            return ['ok' => false, 'message' => 'Dropship not found.'];
        }

        $this->app->db->query(
            'UPDATE #__store_dropships SET status=? WHERE id=? AND tenant_id=?',
            [$status, $dropshipId, $tenantId]
        );

        return ['ok' => true, 'message' => 'Dropship updated to ' . $status . '.', 'status' => $status];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    /**
     * Whether a product (by id or sku) is supplied by a dropship supplier.
     */
    public function isDropshipLine(int $tenantId, int $productId, string $sku = ''): bool
    {
        return $this->resolveDropshipSupplier($tenantId, $productId, $sku) !== null;
    }

    /**
     * True when every resolvable line is dropship (or order has no stockable lines).
     */
    public function isDropshipOnlyOrder(int $orderId): bool
    {
        $tenantId = $this->app->tenant->id();
        $lines = $this->app->db->query(
            'SELECT product_id, sku FROM #__store_order_items WHERE order_id=?',
            [$orderId]
        )->rows;
        if ($lines === []) {
            return false;
        }

        $hasStockLine = false;
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            $sku = trim((string) ($line['sku'] ?? ''));
            if ($pid <= 0 && $sku === '') {
                continue;
            }
            if (!$this->isDropshipLine($tenantId, $pid, $sku)) {
                $hasStockLine = true;
                break;
            }
        }

        return !$hasStockLine;
    }

    /**
     * @return array<int, array{change_status:string, supplier_name:string}>
     */
    private function dropshipGroupsForOrder(int $tenantId, int $orderId): array
    {
        $lines = $this->app->db->query(
            'SELECT product_id, sku, name, qty FROM #__store_order_items WHERE order_id=? ORDER BY id ASC',
            [$orderId]
        )->rows;

        $groups = [];
        foreach ($lines as $line) {
            $supplier = $this->resolveDropshipSupplier(
                $tenantId,
                (int) ($line['product_id'] ?? 0),
                trim((string) ($line['sku'] ?? ''))
            );
            if ($supplier === null) {
                continue;
            }
            $sid = (int) $supplier['id'];
            if (!isset($groups[$sid])) {
                $groups[$sid] = [
                    'change_status' => (string) ($supplier['dropship_change_status'] ?? ''),
                    'supplier_name' => (string) ($supplier['name'] ?? ''),
                ];
            }
        }
        return $groups;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveDropshipSupplier(int $tenantId, int $productId, string $sku): ?array
    {
        if ($productId > 0) {
            $row = $this->app->db->query(
                "SELECT s.id, s.name, s.dropship_change_status, s.is_dropship_supplier
                 FROM #__store_products p
                 INNER JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
                 WHERE p.id=? AND p.tenant_id=? AND p.supplier_id IS NOT NULL
                 LIMIT 1",
                [$productId, $tenantId]
            )->row;
            if ($row !== [] && (int) ($row['is_dropship_supplier'] ?? 0) === 1) {
                return $row;
            }
        }

        if ($sku !== '') {
            $row = $this->app->db->query(
                "SELECT s.id, s.name, s.dropship_change_status, s.is_dropship_supplier
                 FROM #__store_products p
                 INNER JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
                 WHERE p.tenant_id=? AND p.sku=? AND p.supplier_id IS NOT NULL
                 LIMIT 1",
                [$tenantId, $sku]
            )->row;
            if ($row !== [] && (int) ($row['is_dropship_supplier'] ?? 0) === 1) {
                return $row;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $order */
    private function makeReference(array $order, int $supplierId): string
    {
        $ext = preg_replace('/[^A-Za-z0-9\-]/', '', (string) ($order['external_order_id'] ?? '')) ?: (string) (int) $order['id'];
        return substr('DS-' . $ext . '-S' . $supplierId, 0, 64);
    }
}
