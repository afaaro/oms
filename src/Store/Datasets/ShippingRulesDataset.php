<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ShippingRulesDataset
{
    public function __construct(private Database $db) {}

    /** @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>} */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'r.tenant_id = ?';
        $total = (int) ($this->db->query('SELECT COUNT(*) AS c FROM #__store_shipping_rules r WHERE r.tenant_id=?', [$tenantId])->row['c'] ?? 0);
        if ($req->search !== '') {
            $where .= ' AND (r.name LIKE ? OR r.match_shipping_method LIKE ? OR m.name LIKE ? OR rg.name LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_shipping_rules r
             LEFT JOIN #__store_shipping_methods m ON m.id = r.method_id
             LEFT JOIN #__store_shipping_regions rg ON rg.id = r.region_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT r.*, m.name AS method_name, rg.name AS region_name, c.name AS channel_name
             FROM #__store_shipping_rules r
             LEFT JOIN #__store_shipping_methods m ON m.id = r.method_id
             LEFT JOIN #__store_shipping_regions rg ON rg.id = r.region_id
             LEFT JOIN #__store_channels c ON c.id = r.channel_id
             WHERE {$where}
             ORDER BY r.priority ASC, r.id ASC
             LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;

        return ['recordsTotal' => $total, 'recordsFiltered' => $filtered, 'rows' => $rows];
    }
}
