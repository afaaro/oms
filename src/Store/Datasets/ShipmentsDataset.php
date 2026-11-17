<?php

declare(strict_types=1);

namespace App\Store\Datasets;

use App\Core\Database;
use App\Store\DataTableRequest;

final class ShipmentsDataset
{
    public function __construct(private Database $db) {}

    /**
     * @return array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>}
     */
    public function forDataTable(int $tenantId, DataTableRequest $req): array
    {
        $params = [$tenantId];
        $where = 's.tenant_id = ?';

        $total = (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_shipments s WHERE s.tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);

        $fulfillment = strtolower(trim((string) ($req->filters['fulfillment'] ?? '')));
        if (in_array($fulfillment, ['failed', 'skipped', 'unsupported', 'uploaded', 'pending'], true)) {
            $where .= ' AND s.fulfillment_status = ?';
            $params[] = $fulfillment;
        } elseif ($fulfillment === 'needs_retry') {
            $where .= " AND s.fulfillment_status IN ('failed','skipped','unsupported')";
        } elseif ($fulfillment === 'not_uploaded') {
            $where .= " AND (s.fulfillment_status IS NULL OR s.fulfillment_status = '' OR s.fulfillment_status IN ('failed','skipped','unsupported','pending'))";
        }

        if ($req->search !== '') {
            $where .= ' AND (
                s.tracking_number LIKE ?
                OR s.carrier_code LIKE ?
                OR s.service_code LIKE ?
                OR s.status LIKE ?
                OR s.fulfillment_status LIKE ?
                OR o.external_order_id LIKE ?
                OR o.shipping_name LIKE ?
                OR m.reference LIKE ?
            )';
            $like = '%' . $req->search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        $join = 'FROM #__store_shipments s
             LEFT JOIN #__store_orders o ON o.id = s.order_id AND o.tenant_id = s.tenant_id
             LEFT JOIN #__store_manifests m ON m.id = s.manifest_id AND m.tenant_id = s.tenant_id';

        $filtered = (int) ($this->db->query(
            "SELECT COUNT(*) AS c {$join} WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->db->query(
            "SELECT s.id, s.order_id, s.manifest_id, s.carrier_code, s.service_code,
                    s.tracking_number, s.status, s.fulfillment_status, s.fulfillment_error,
                    s.weight_kg, s.package_count, s.external_consignment_id,
                    s.despatched_at, s.labelled_at, s.created_at,
                    o.external_order_id, o.shipping_name,
                    m.reference AS manifest_reference
             {$join}
             WHERE {$where}
             ORDER BY COALESCE(s.despatched_at, s.created_at) DESC, s.id DESC
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
