<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class OrdersDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'o.tenant_id = ?';
        $view = $req->filters['view'] ?? 'all';

        if ($view === 'picking' || $view === 'ready') {
            $where .= " AND o.status IN ('awaiting_despatch','printed','processing')
                        AND NOT EXISTS (
                          SELECT 1 FROM #__store_pickwave_orders po
                          WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
                        )";
        } elseif ($view === 'despatch') {
            $where .= " AND o.status IN ('processing','printed')";
        } elseif ($view === 'priority') {
            $where .= " AND o.is_priority = 1 AND o.status NOT IN ('despatched','cancelled','refunded')";
        } elseif ($view === 'today') {
            $where .= ' AND DATE(o.created_at) = CURDATE()';
        } elseif ($view === 'back') {
            $where .= " AND o.status = 'on_hold'";
        } elseif ($view === 'despatched' || $view === 'deliveries') {
            $where .= " AND o.status = 'despatched'";
        } elseif ($view === 'disputes') {
            $where .= " AND (o.status IN ('cancelled','refunded','on_hold') OR o.attention_required = 1)";
        } elseif ($view === 'manifests') {
            $where .= " AND o.status = 'despatched' AND o.tracking_number <> ''";
        }

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_orders o WHERE o.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (
                CAST(o.id AS CHAR) LIKE ?
                OR o.external_order_id LIKE ?
                OR o.buyer_email LIKE ?
                OR o.buyer_username LIKE ?
                OR o.shipping_name LIKE ?
                OR c.name LIKE ?
                OR c.code LIKE ?
                OR o.status LIKE ?
                OR o.shipping_method LIKE ?
                OR JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address, \'$.postcode\')) LIKE ?
                OR EXISTS (
                  SELECT 1 FROM #__store_order_items oi
                  LEFT JOIN #__store_products p ON p.id = oi.product_id AND p.tenant_id = o.tenant_id
                  WHERE oi.order_id = o.id
                    AND (oi.sku LIKE ? OR oi.name LIKE ? OR p.sku LIKE ?)
                )
            )';
            $like = '%' . $req->search . '%';
            array_push(
                $params,
                $like, $like, $like, $like, $like, $like, $like, $like, $like, $like,
                $like, $like, $like
            );
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $picking = $view === 'picking';
        $columns = $picking
            ? [
                'o.warehouse', 'o.id', 'c.name', 'o.id', 'o.id', 'o.external_order_id',
                'o.created_at', 'o.id', 'o.id', 'o.id', 'o.id', 'o.package_count',
                'o.order_weight_kg', 'o.total', 'o.shipping_method', 'o.id',
            ]
            : [
                'o.is_priority',
                'o.id',
                'c.name',
                'o.id',
                'o.created_at',
                'ship_postcode',
                'o.buyer_email',
                'o.total',
                'package_size_name',
                'o.package_count',
                'o.shipping_method',
                'o.status',
                'o.order_weight_kg',
                'pickwave_id',
                'o.notes',
            ];
        $orderBy = $columns[$req->orderColumn] ?? 'o.created_at';
        // Aliases used in SELECT — safe for ORDER BY in MySQL.
        $allowedOrder = [
            'o.is_priority', 'o.id', 'c.name', 'o.created_at', 'ship_postcode',
            'o.buyer_email', 'o.total', 'package_size_name', 'o.package_count',
            'o.shipping_method', 'o.status', 'o.order_weight_kg', 'pickwave_id',
            'o.notes', 'o.warehouse', 'o.external_order_id',
        ];
        if (!in_array($orderBy, $allowedOrder, true)) {
            $orderBy = 'o.created_at';
        }

        $itemLinesSql = "(SELECT GROUP_CONCAT(
                  CONCAT(
                    COALESCE(oi.product_id, 0),
                    '|',
                    REPLACE(REPLACE(COALESCE(NULLIF(TRIM(oi.sku), ''), NULLIF(TRIM(p.sku), ''), ''), '|', '/'), '\\n', ' '),
                    '|',
                    REPLACE(REPLACE(COALESCE(oi.name, ''), '|', '/'), '\\n', ' '),
                    '|',
                    oi.qty
                  ) ORDER BY oi.id SEPARATOR '\\n')
                FROM #__store_order_items oi
                LEFT JOIN #__store_products p ON p.id = oi.product_id AND p.tenant_id = o.tenant_id
                WHERE oi.order_id = o.id)";

        $commonExtras = ",
            o.on_hold, o.attention_required, o.is_paid, o.package_count, o.order_weight_kg, o.notes,
            o.packaging_size_id, o.mapped_shipping_method,
            JSON_UNQUOTE(JSON_EXTRACT(o.shipping_address, '$.postcode')) AS ship_postcode,
            (SELECT ps.name FROM #__store_packaging_sizes ps
              WHERE ps.id = o.packaging_size_id AND ps.tenant_id = o.tenant_id LIMIT 1) AS package_size_name,
            (SELECT po.pickwave_id FROM #__store_pickwave_orders po
              WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
              ORDER BY po.pickwave_id DESC LIMIT 1) AS pickwave_id,
            {$itemLinesSql} AS sku_lines";

        $pickingSelect = $picking
            ? ", o.warehouse,
               JSON_UNQUOTE(JSON_EXTRACT(c.settings, '$.handling_days')) AS handling_days,
               (SELECT GROUP_CONCAT(DISTINCT p.brand ORDER BY p.brand SEPARATOR '\\n')
                FROM #__store_order_items oi
                INNER JOIN #__store_products p ON p.id = oi.product_id AND p.tenant_id = o.tenant_id
                WHERE oi.order_id = o.id AND p.brand <> '') AS brand_lines
               {$commonExtras}"
            : $commonExtras;

        $rows = $this->db->query(
            "SELECT o.id, o.external_order_id, o.status, o.buyer_email, o.buyer_username,
                    o.total, o.currency, o.created_at, o.shipping_method, o.channel_id, o.is_priority,
                    o.shipping_name, c.name AS channel_name, c.code AS channel_code, c.type AS channel_type
                    {$pickingSelect}
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE {$where}
             ORDER BY {$orderBy} {$req->orderDir}, o.id DESC
             LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;

        return [
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'rows'            => $rows,
        ];
    }
}
