<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class TenantsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(DataTableRequest $req): array
    {
        $params = [];
        $where = '1=1';
        $total = (int) ($this->db->query('SELECT COUNT(*) AS c FROM #__store_tenants')->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (t.name LIKE ? OR t.slug LIKE ? OR t.contact_email LIKE ? OR t.plan LIKE ? OR t.status LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_tenants t WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['t.name', 't.plan', 't.status', 'user_count', 'open_tickets', 't.contact_email', 't.id'];
        $orderBy = $columns[$req->orderColumn] ?? 't.id';

        $rows = $this->db->query(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM #__store_tenant_users u WHERE u.tenant_id = t.id) AS user_count,
                    (SELECT COUNT(*) FROM #__support_tickets s WHERE s.tenant_id = t.id AND s.status IN ('open','pending')) AS open_tickets
             FROM #__store_tenants t
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
