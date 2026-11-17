<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Auth\Acl;
use App\Core\Database;
use App\Store\DataTableRequest;

final class UsersDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'tu.tenant_id = ?';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_tenant_users tu WHERE tu.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ? OR u.status LIKE ? OR tu.role LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_tenant_users tu
             INNER JOIN #__users u ON u.id = tu.user_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $columns = ['u.display_name', 'u.email', 'u.last_login_at', 'tu.role', 'u.status', 'u.id'];
        $orderBy = $columns[$req->orderColumn] ?? 'u.id';

        $rows = $this->db->query(
            "SELECT u.id, u.display_name, u.email, u.last_login_at, u.status, u.created_at, tu.role
             FROM #__store_tenant_users tu
             INNER JOIN #__users u ON u.id = tu.user_id
             WHERE {$where}
             ORDER BY {$orderBy} {$req->orderDir}
             LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;

        $acl = new Acl($this->db);
        foreach ($rows as &$row) {
            $row['role_label'] = $acl->roleLabel($acl->forUser((int) $row['id'], $tenantId));
        }
        unset($row);

        return [
            'recordsTotal'    => $total,
            'recordsFiltered' => $filtered,
            'rows'            => $rows,
        ];
    }
}
