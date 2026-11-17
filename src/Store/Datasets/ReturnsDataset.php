<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ReturnsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'r.tenant_id = ?';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_returns r WHERE r.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (r.external_order_id LIKE ? OR r.sku LIKE ? OR r.reason LIKE ? OR r.status LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_returns r WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT r.*
             FROM #__store_returns r
             WHERE {$where}
             ORDER BY r.id DESC
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
