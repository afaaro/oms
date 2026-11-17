<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class SuppliersDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 's.tenant_id = ?';
        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_suppliers s WHERE s.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (s.name LIKE ? OR s.code LIKE ? OR s.email LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_suppliers s WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['s.name', 's.code', 's.email', 's.phone', 's.active', 's.id'];
        $orderBy = $columns[$req->orderColumn] ?? 's.name';

        $rows = $this->db->query(
            "SELECT s.*
             FROM #__store_suppliers s
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
