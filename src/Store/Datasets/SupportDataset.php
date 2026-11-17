<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class SupportDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(DataTableRequest $req): array
    {
        $params = [];
        $where = '1=1';
        $status = $req->filters['status'] ?? 'open';

        if ($status === 'open') {
            $where .= " AND t.status IN ('open','pending')";
        } elseif ($status !== '' && $status !== 'all') {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }

        $total = (int) ($this->db->query('SELECT COUNT(*) AS c FROM #__support_tickets')->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (t.subject LIKE ? OR t.category LIKE ? OR n.name LIKE ? OR t.priority LIKE ? OR t.status LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__support_tickets t
             INNER JOIN #__store_tenants n ON n.id = t.tenant_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['t.id', 'n.name', 't.subject', 't.category', 't.priority', 't.status', 't.updated_at'];
        $orderBy = $columns[$req->orderColumn] ?? 't.id';

        $rows = $this->db->query(
            "SELECT t.*, n.name AS tenant_name
             FROM #__support_tickets t
             INNER JOIN #__store_tenants n ON n.id = t.tenant_id
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
