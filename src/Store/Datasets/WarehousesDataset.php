<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class WarehousesDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'w.tenant_id = ?';
        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_warehouses w WHERE w.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (w.name LIKE ? OR w.code LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_warehouses w WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['w.name', 'w.code', 'w.is_default', 'w.active', 'w.id'];
        $orderBy = $columns[$req->orderColumn] ?? 'w.is_default';
        $dir = $req->orderColumn === 2 ? 'DESC' : $req->orderDir;

        $rows = $this->db->query(
            "SELECT w.id, w.name, w.code, w.is_default, w.active, w.created_at
             FROM #__store_warehouses w
             WHERE {$where}
             ORDER BY {$orderBy} {$dir}, w.name ASC
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
