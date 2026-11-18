<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ListingsDataset
{
    public function __construct(private Database $db) {}

    /** @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>} */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 'l.tenant_id = ?';
        $view = $req->filters['view'] ?? 'all';
        if ($view === 'unmapped') {
            $where .= ' AND l.mapped = 0';
        } elseif ($view === 'mapped') {
            $where .= ' AND l.mapped = 1';
        }

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_listings l WHERE l.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (l.sku LIKE ? OR l.title LIKE ? OR l.external_id LIKE ? OR c.name LIKE ? OR p.sku LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c
             FROM #__store_listings l
            LEFT JOIN #__store_channels c
                ON c.id = l.channel_id
                AND c.tenant_id = l.tenant_id
            LEFT JOIN #__store_products p
                ON p.id = l.product_id
                AND p.tenant_id = l.tenant_id
             WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT l.*, c.name AS channel_name, p.sku AS product_sku, p.name AS product_name
             FROM #__store_listings l
            LEFT JOIN #__store_channels c
                ON c.id = l.channel_id
                AND c.tenant_id = l.tenant_id
            LEFT JOIN #__store_products p
                ON p.id = l.product_id
                AND p.tenant_id = l.tenant_id
             WHERE {$where}
             ORDER BY l.mapped ASC, l.id DESC
             LIMIT {$req->length} OFFSET {$req->start}",
            $params
        )->rows;

        return ['recordsTotal' => $total, 'recordsFiltered' => $filtered, 'rows' => $rows];
    }
}
