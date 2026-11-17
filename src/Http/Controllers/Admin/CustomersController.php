<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\CustomerRepository;

final class CustomersController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->app->tenant->id();
        $repo = new CustomerRepository($this->app->db);
        if ($repo->count($tenantId) === 0) {
            $created = $repo->syncFromOrders($tenantId);
            if ($created > 0) {
                $this->app->session->flash('success', "Synced {$created} customer(s) from orders.");
            }
        }

        echo $this->view('admin/customers/index', [
            'title'      => 'Customers',
            'navSection' => 'customers',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
            'csrf'       => $this->app->session->csrfToken(),
        ]);
    }

    public function sync(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/customers');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $created = (new CustomerRepository($this->app->db))->syncFromOrders($this->app->tenant->id());
        if ($created > 0) {
            $this->app->session->flash('success', "Synced {$created} new customer(s) from orders.");
        } else {
            $this->app->session->flash('success', 'No new customers found in orders.');
        }
        $this->redirect('admin/customers');
    }

    public function show(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $customer = $this->app->db->query(
            'SELECT * FROM #__store_customers WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($customer === []) {
            http_response_code(404);
            echo 'Customer not found';
            return;
        }
        $email = trim((string) ($customer['email'] ?? ''));
        $orders = $this->app->db->query(
            'SELECT o.id, o.external_order_id, o.status, o.total, o.currency, o.created_at, c.name AS channel_name
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE o.tenant_id = ?
               AND (o.buyer_email = ? OR o.buyer_username = ?)
             ORDER BY o.id DESC
             LIMIT 50',
            [$this->app->tenant->id(), $email, $email]
        )->rows;

        echo $this->view('admin/customers/view', [
            'title'      => (string) $customer['name'],
            'navSection' => 'customers',
            'customer'   => $customer,
            'orders'     => $orders,
            'user'       => $this->app->auth->user(),
        ]);
    }
}
