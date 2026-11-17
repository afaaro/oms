<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ChannelsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'c.tenant_id = ?';
        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_channels c WHERE c.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (c.name LIKE ? OR c.code LIKE ? OR c.type LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_channels c WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['c.name', 'c.code', 'c.type', 'c.active', 'c.id'];
        $orderBy = $columns[$req->orderColumn] ?? 'c.name';

        $rows = $this->db->query(
            "SELECT c.id, c.name, c.code, c.type, c.active, c.created_at
             FROM #__store_channels c
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
