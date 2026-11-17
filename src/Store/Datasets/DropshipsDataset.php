<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class DropshipsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'd.tenant_id = ?';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_dropships d WHERE d.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (d.reference LIKE ? OR d.status LIKE ? OR s.name LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_dropships d
             LEFT JOIN #__store_suppliers s ON s.id = d.supplier_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT d.*, s.name AS supplier_name, o.external_order_id
             FROM #__store_dropships d
             LEFT JOIN #__store_suppliers s ON s.id = d.supplier_id
             LEFT JOIN #__store_orders o ON o.id = d.order_id
             WHERE {$where}
             ORDER BY d.id DESC
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
