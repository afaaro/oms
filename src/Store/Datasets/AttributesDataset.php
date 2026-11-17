<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class AttributesDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'a.tenant_id = ?';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_attributes a WHERE a.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (a.name LIKE ? OR a.code LIKE ?)';
            $like = '%' . $req->search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_attributes a WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT a.id, a.name, a.code, a.sort_order, a.show_on_variants, a.active, a.created_at,
                    (SELECT COUNT(*) FROM #__store_attribute_values v
                     WHERE v.attribute_id = a.id AND v.tenant_id = a.tenant_id) AS value_count,
                    (SELECT COUNT(*) FROM #__store_product_attributes pa
                     WHERE pa.attribute_id = a.id AND pa.tenant_id = a.tenant_id
                       AND TRIM(pa.value_text) <> '') AS product_count
             FROM #__store_attributes a
             WHERE {$where}
             ORDER BY a.sort_order ASC, a.name ASC
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
