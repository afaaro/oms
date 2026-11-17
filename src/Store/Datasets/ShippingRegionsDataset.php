<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ShippingRegionsDataset
{
    public function __construct(private Database $db) {}

    /** @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>} */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'r.tenant_id = ?';
        $total = (int) ($this->db->query('SELECT COUNT(*) AS c FROM #__store_shipping_regions r WHERE r.tenant_id=?', [$tenantId])->row['c'] ?? 0);
        if ($req->search !== '') {
            $where .= ' AND (r.name LIKE ? OR r.countries LIKE ? OR r.postcode_prefixes LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }
        $filtered = (int) ($this->db->query("SELECT COUNT(*) AS c FROM #__store_shipping_regions r WHERE {$where}", $params)->row['c'] ?? 0);
        $rows = $this->db->query(
            "SELECT r.* FROM #__store_shipping_regions r WHERE {$where} ORDER BY r.name ASC LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;
        return ['recordsTotal' => $total, 'recordsFiltered' => $filtered, 'rows' => $rows];
    }
}
