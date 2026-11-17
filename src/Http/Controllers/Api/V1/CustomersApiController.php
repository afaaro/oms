<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;

final class CustomersApiController extends ApiController
{
    public function index(): void
    {
        if (!$this->requireScope('customers:read')) {
            return;
        }
        $params = [$this->tenantId()];
        $where = 'tenant_id = ?';
        $q = trim((string) $this->app->request->get('q', ''));
        if ($q !== '') {
            $where .= ' AND (email LIKE ? OR name LIKE ? OR phone LIKE ? OR postcode LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $total = (int) ($this->app->db->query("SELECT COUNT(*) AS c FROM #__store_customers WHERE {$where}", $params)->row['c'] ?? 0);
        $rows = $this->app->db->query(
            "SELECT id, name, email, phone, postcode, channel_id, created_at, updated_at
             FROM #__store_customers WHERE {$where}
             ORDER BY id DESC LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;
        $this->ok($rows, $this->paginationMeta($total));
    }

    public function show(): void
    {
        if (!$this->requireScope('customers:read')) {
            return;
        }
        $id = (int) $this->app->request->get('id', 0);
        $customer = $this->app->db->query(
            'SELECT * FROM #__store_customers WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($customer === []) {
            $this->fail('Customer not found', 404);
            return;
        }
        $orders = $this->app->db->query(
            'SELECT id, external_order_id, status, total, currency, created_at
             FROM #__store_orders
             WHERE tenant_id = ? AND buyer_email = ?
             ORDER BY id DESC LIMIT 50',
            [$this->tenantId(), $customer['email']]
        )->rows;
        $this->ok(['customer' => $customer, 'orders' => $orders]);
    }

    public function store(): void
    {
        if (!$this->requireScope('customers:write')) {
            return;
        }
        $email = trim((string) $this->app->request->input('email', ''));
        $name = trim((string) $this->app->request->input('name', ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->fail('Valid email required', 422);
            return;
        }
        if ($name === '') {
            $name = $email;
        }
        $ins = $this->app->db->query(
            'INSERT INTO #__store_customers (tenant_id, name, email, phone, postcode, channel_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $this->tenantId(),
                $name,
                $email,
                trim((string) $this->app->request->input('phone', '')),
                trim((string) $this->app->request->input('postcode', '')),
                (int) $this->app->request->input('channel_id', 0) ?: null,
            ]
        );
        $this->ok(['id' => (int) ($ins->insert_id ?: 0), 'email' => $email], [], 201);
    }

    public function update(): void
    {
        if (!$this->requireScope('customers:write')) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        if ($id <= 0) {
            $this->fail('id required', 422);
            return;
        }
        $this->app->db->query(
            'UPDATE #__store_customers SET name=?, phone=?, postcode=?, channel_id=? WHERE id=? AND tenant_id=?',
            [
                trim((string) $this->app->request->input('name', '')),
                trim((string) $this->app->request->input('phone', '')),
                trim((string) $this->app->request->input('postcode', '')),
                (int) $this->app->request->input('channel_id', 0) ?: null,
                $id,
                $this->tenantId(),
            ]
        );
        $this->ok(['id' => $id]);
    }
}
