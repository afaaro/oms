<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class StockLocationsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'l.tenant_id = ?';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_stock_locations l WHERE l.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (l.code LIKE ? OR l.name LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_stock_locations l WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT l.id, l.code, l.name, l.active, l.created_at,
                    (SELECT COALESCE(SUM(i.qty_on_hand),0) FROM #__store_inventory i WHERE i.location_id = l.id) AS qty
             FROM #__store_stock_locations l
             WHERE {$where}
             ORDER BY l.code ASC
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
