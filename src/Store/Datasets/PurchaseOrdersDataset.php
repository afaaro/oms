<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class PurchaseOrdersDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'p.tenant_id = ?';
        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_purchase_orders p WHERE p.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (p.reference LIKE ? OR p.status LIKE ? OR s.name LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_purchase_orders p
             LEFT JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['p.reference', 's.name', 'p.status', 'p.total', 'p.expected_at', 'p.id'];
        $orderBy = $columns[$req->orderColumn] ?? 'p.id';

        $rows = $this->db->query(
            "SELECT p.*, s.name AS supplier_name
             FROM #__store_purchase_orders p
             LEFT JOIN #__store_suppliers s ON s.id = p.supplier_id AND s.tenant_id = p.tenant_id
             WHERE {$where}
             ORDER BY {$orderBy} {$req->orderDir}
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
