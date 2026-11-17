<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ReportJobsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req, string $typeFilter = ''): array
    {
        $params = [$tenantId];
        $where = 'j.tenant_id = ?';
        if ($typeFilter === 'scheduled') {
            $where .= " AND j.type = 'scheduled'";
        } elseif ($typeFilter === 'export') {
            $where .= " AND j.type = 'export'";
        }

        $total = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_report_jobs j WHERE j.tenant_id = ?"
            . ($typeFilter !== '' ? " AND j.type = ?" : ''),
            $typeFilter !== '' ? [$tenantId, $typeFilter] : [$tenantId]
        )->row['c'] ?? 0);

        if ($req->search !== '') {
            $where .= ' AND (j.name LIKE ? OR j.status LIKE ? OR j.schedule LIKE ?)';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like);
        }

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c FROM #__store_report_jobs j WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT j.*
             FROM #__store_report_jobs j
             WHERE {$where}
             ORDER BY j.id DESC
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
