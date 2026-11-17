<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;

final class WarehouseApiController extends ApiController
{
    public function warehouses(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT id, code, name, is_default, active, created_at
             FROM #__store_warehouses WHERE tenant_id = ? ORDER BY is_default DESC, name',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }

    public function locations(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT id, code, name, warehouse_id, active FROM #__store_stock_locations WHERE tenant_id = ? ORDER BY code',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }

    public function pickwaveCreate(): void
    {
        if (!$this->requireScope('warehouse:manage')) {
            return;
        }
        $ids = $this->app->request->input('order_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        try {
            $name = trim((string) $this->app->request->input('name', ''));
            $created = (new \App\Store\PickwaveService($this->app))->create($ids, null, $name !== '' ? $name : null);
            $this->ok([
                'id'          => $created['id'],
                'reference'   => $created['reference'],
                'order_ids'   => $ids,
                'order_count' => $created['order_count'],
                'status'      => 'open',
            ], [], 201);
        } catch (\InvalidArgumentException $e) {
            $this->fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->fail($e->getMessage(), 500);
        }
    }

    public function returns(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $params = [$this->tenantId()];
        $where = 'r.tenant_id = ?';
        $status = trim((string) $this->app->request->get('status', ''));
        if ($status !== '') {
            $where .= ' AND r.status = ?';
            $params[] = $status;
        }
        $total = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_returns r WHERE {$where}",
            $params
        )->row['c'] ?? 0);
        $rows = $this->app->db->query(
            "SELECT r.id, r.order_id, r.external_order_id, r.sku, r.qty, r.reason, r.status, r.created_at
             FROM #__store_returns r
             WHERE {$where}
             ORDER BY r.id DESC
             LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;
        $this->ok($rows, $this->paginationMeta($total));
    }

    public function returnShow(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $id = (int) $this->app->request->get('id', 0);
        $row = $this->app->db->query(
            'SELECT r.*, o.buyer_email, o.status AS order_status
             FROM #__store_returns r
             LEFT JOIN #__store_orders o ON o.id = r.order_id
             WHERE r.id = ? AND r.tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($row === []) {
            $this->fail('Return not found', 404);
            return;
        }
        $this->ok($row);
    }

    public function returnUpdateStatus(): void
    {
        if (!$this->requireScope('warehouse:manage')) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $status = (string) $this->app->request->input('status', '');
        $allowed = ['pending', 'requested', 'received', 'inspecting', 'refunded', 'rejected', 'closed'];
        if ($id <= 0 || !in_array($status, $allowed, true)) {
            $this->fail('Valid id and status required', 422);
            return;
        }
        $this->app->db->query(
            'UPDATE #__store_returns SET status = ? WHERE id = ? AND tenant_id = ?',
            [$status, $id, $this->tenantId()]
        );
        $this->ok(['id' => $id, 'status' => $status]);
    }

    public function dropships(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT d.id, d.reference, d.status, d.order_id, d.supplier_id, d.created_at, s.name AS supplier_name
             FROM #__store_dropships d
             LEFT JOIN #__store_suppliers s ON s.id = d.supplier_id
             WHERE d.tenant_id = ?
             ORDER BY d.id DESC LIMIT 100',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }
}
