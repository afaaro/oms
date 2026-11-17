<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class CategoriesDataset
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
            'SELECT COUNT(*) AS c FROM #__store_categories c WHERE c.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND c.name LIKE ?';
            $params[] = '%' . $req->search . '%';
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_categories c WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT c.id, c.name, c.active, c.created_at,
                    (SELECT COUNT(*) FROM #__store_products p WHERE p.category_id = c.id) AS product_count
             FROM #__store_categories c
             WHERE {$where}
             ORDER BY c.name ASC
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
