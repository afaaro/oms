<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ShippingMethodsDataset
{
    public function __construct(private Database $db) {}

    /** @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>} */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'm.tenant_id = ?';
        $total = (int) ($this->db->query('SELECT COUNT(*) AS c FROM #__store_shipping_methods m WHERE m.tenant_id=?', [$tenantId])->row['c'] ?? 0);
        if ($req->search !== '') {
            $where .= ' AND (m.name LIKE ? OR m.carrier LIKE ? OR m.service_code LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }
        $filtered = (int) ($this->db->query("SELECT COUNT(*) AS c FROM #__store_shipping_methods m WHERE {$where}", $params)->row['c'] ?? 0);
        $rows = $this->db->query(
            "SELECT m.*, ps.name AS packaging_name
             FROM #__store_shipping_methods m
             LEFT JOIN #__store_packaging_sizes ps ON ps.id = m.packaging_size_id AND ps.tenant_id = m.tenant_id
             WHERE {$where} ORDER BY m.name ASC LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;
        return ['recordsTotal' => $total, 'recordsFiltered' => $filtered, 'rows' => $rows];
    }
}
