<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ProductsDataset
{
    public function __construct(private Database $db) {}

    /**
     * Top-level products only (variants appear via expand).
     *
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'p.tenant_id = ? AND (p.parent_id IS NULL OR p.parent_id = 0)';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_products p
             WHERE p.tenant_id = ? AND (p.parent_id IS NULL OR p.parent_id = 0)',
            [$tenantId]
        )->row['c'] ?? 0);

        $from = 'FROM #__store_products p
             LEFT JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id';

        if ($req->search !== '') {
            $where .= ' AND (
                p.sku LIKE ? OR p.name LIKE ? OR p.brand LIKE ? OR p.barcode LIKE ?
                OR p.supplier_sku LIKE ? OR p.supplier_name LIKE ? OR s.name LIKE ? OR p.mpn LIKE ?
                OR EXISTS (
                    SELECT 1 FROM #__store_products c
                    WHERE c.tenant_id = p.tenant_id AND c.parent_id = p.id
                      AND (c.sku LIKE ? OR c.name LIKE ? OR c.barcode LIKE ? OR c.supplier_sku LIKE ?)
                )
            )';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c {$from} WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        // DataTables columns: type, image, sku, name, ean, supplier_sku, inventory, allocated, available, supplier, brand, price, warehouse, active
        $columns = [
            'p.id',
            'p.id',
            'p.sku',
            'p.name',
            'p.barcode',
            'p.supplier_sku',
            'qty_on_hand',
            'p.id',
            'qty_on_hand',
            'supplier_label',
            'p.brand',
            'p.price',
            'p.warehouse',
            'p.active',
        ];
        $orderBy = $columns[$req->orderColumn] ?? 'p.sku';

        // Keep the proven on-hand subquery (no qty_allocated in SQL — safer on hosts
        // where the column might be missing or queries are strict).
        $rows = $this->db->query(
            "SELECT p.id, p.sku, p.name, p.brand, p.price, p.active, p.created_at, p.barcode, p.mpn,
                    p.warehouse, p.image, p.supplier_sku, p.supplier_name, p.supplier_id, p.parent_id,
                    COALESCE(s.name, p.supplier_name, '') AS supplier_label,
                    (
                        SELECT COUNT(*) FROM #__store_products c
                        WHERE c.tenant_id = p.tenant_id AND c.parent_id = p.id
                    ) AS variant_count,
                    COALESCE((
                        SELECT SUM(i.qty_on_hand)
                        FROM #__store_inventory i
                        INNER JOIN #__store_products x ON x.id = i.product_id AND x.tenant_id = i.tenant_id
                        WHERE i.tenant_id = p.tenant_id
                          AND (
                            (x.id = p.id AND (x.inventory_source_id IS NULL OR x.inventory_source_id = 0))
                            OR (
                              x.parent_id = p.id
                              AND (x.inventory_source_id IS NULL OR x.inventory_source_id = 0)
                            )
                          )
                    ), 0) AS qty_on_hand
             {$from}
             WHERE {$where}
             ORDER BY {$orderBy} {$req->orderDir}
             LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;

        $allocByOwner = $this->allocatedByOwnerIds(
            $tenantId,
            array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows)
        );
        foreach ($rows as &$row) {
            $id = (int) ($row['id'] ?? 0);
            $onHand = (int) ($row['qty_on_hand'] ?? 0);
            $allocated = (int) ($allocByOwner[$id] ?? 0);
            $row['qty_allocated'] = $allocated;
            $row['qty_available'] = max(0, $onHand - $allocated);
        }
        unset($row);

        return [
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'rows'            => $rows,
        ];
    }

    /**
     * @param list<int> $ownerIds
     * @return array<int,int> owner_id => qty_allocated
     */
    private function allocatedByOwnerIds(int $tenantId, array $ownerIds): array
    {
        $ownerIds = array_values(array_unique(array_filter($ownerIds, static fn (int $id): bool => $id > 0)));
        if ($ownerIds === []) {
            return [];
        }

        try {
            $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
            $rows = $this->db->query(
                "SELECT
                    CASE
                      WHEN x.parent_id IS NOT NULL AND x.parent_id > 0 THEN x.parent_id
                      ELSE x.id
                    END AS owner_id,
                    COALESCE(SUM(i.qty_allocated), 0) AS qty_allocated
                 FROM #__store_inventory i
                 INNER JOIN #__store_products x ON x.id = i.product_id AND x.tenant_id = i.tenant_id
                 WHERE i.tenant_id = ?
                   AND (x.inventory_source_id IS NULL OR x.inventory_source_id = 0)
                   AND (
                     x.id IN ({$placeholders})
                     OR x.parent_id IN ({$placeholders})
                   )
                 GROUP BY owner_id",
                array_merge([$tenantId], $ownerIds, $ownerIds)
            )->rows;
        } catch (\Throwable) {
            // Column missing or query unsupported — show products with allocated = 0.
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) ($r['owner_id'] ?? 0)] = (int) ($r['qty_allocated'] ?? 0);
        }
        return $out;
    }

    /**
     * Child variants for a parent product (expand row).
     *
     * @return list<array<string,mixed>>
     */
    public function variantsForParent(int $tenantId, int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }

        try {
            return $this->db->query(
                "SELECT p.id, p.sku, p.name, p.brand, p.price, p.active, p.barcode, p.mpn,
                        p.warehouse, p.image, p.supplier_sku, p.supplier_name, p.supplier_id, p.parent_id,
                        COALESCE(s.name, p.supplier_name, '') AS supplier_label,
                        p.inventory_source_id, p.inventory_unit_factor,
                        src.sku AS inventory_source_sku,
                        CASE
                          WHEN p.inventory_source_id IS NOT NULL AND p.inventory_source_id > 0 THEN
                            FLOOR(COALESCE(src_inv.qty_on_hand, 0) / NULLIF(p.inventory_unit_factor, 0))
                          ELSE COALESCE(own.qty_on_hand, 0)
                        END AS qty_on_hand,
                        CASE
                          WHEN p.inventory_source_id IS NOT NULL AND p.inventory_source_id > 0 THEN
                            FLOOR(COALESCE(src_inv.qty_allocated, 0) / NULLIF(p.inventory_unit_factor, 0))
                          ELSE COALESCE(own.qty_allocated, 0)
                        END AS qty_allocated
                 FROM #__store_products p
                 LEFT JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
                 LEFT JOIN #__store_inventory own ON own.product_id = p.id AND own.tenant_id = p.tenant_id
                 LEFT JOIN #__store_products src ON src.id = p.inventory_source_id AND src.tenant_id = p.tenant_id
                 LEFT JOIN #__store_inventory src_inv ON src_inv.product_id = src.id AND src_inv.tenant_id = p.tenant_id
                 WHERE p.tenant_id=? AND p.parent_id=?
                 ORDER BY p.sku ASC",
                [$tenantId, $parentId]
            )->rows;
        } catch (\Throwable) {
            // Fallback without allocated if the column is missing on the host.
            $rows = $this->db->query(
                "SELECT p.id, p.sku, p.name, p.brand, p.price, p.active, p.barcode, p.mpn,
                        p.warehouse, p.image, p.supplier_sku, p.supplier_name, p.supplier_id, p.parent_id,
                        COALESCE(s.name, p.supplier_name, '') AS supplier_label,
                        p.inventory_source_id, p.inventory_unit_factor,
                        src.sku AS inventory_source_sku,
                        CASE
                          WHEN p.inventory_source_id IS NOT NULL AND p.inventory_source_id > 0 THEN
                            FLOOR(COALESCE(src_inv.qty_on_hand, 0) / NULLIF(p.inventory_unit_factor, 0))
                          ELSE COALESCE(own.qty_on_hand, 0)
                        END AS qty_on_hand,
                        0 AS qty_allocated
                 FROM #__store_products p
                 LEFT JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
                 LEFT JOIN #__store_inventory own ON own.product_id = p.id AND own.tenant_id = p.tenant_id
                 LEFT JOIN #__store_products src ON src.id = p.inventory_source_id AND src.tenant_id = p.tenant_id
                 LEFT JOIN #__store_inventory src_inv ON src_inv.product_id = src.id AND src_inv.tenant_id = p.tenant_id
                 WHERE p.tenant_id=? AND p.parent_id=?
                 ORDER BY p.sku ASC",
                [$tenantId, $parentId]
            )->rows;
            return $rows;
        }
    }
}
