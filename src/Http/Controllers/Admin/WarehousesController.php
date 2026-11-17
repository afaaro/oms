<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class WarehousesController extends Controller
{
    public function index(): void
    {
        echo $this->view('admin/settings/warehouses', [
            'title'      => 'Warehouses',
            'navSection' => 'settings',
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/warehouses');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        $code = trim((string) $this->app->request->post('code', ''));
        if ($name === '') {
            $this->redirect('admin/settings/warehouses');
        }
        $this->app->db->query(
            "INSERT INTO #__store_warehouses
             (tenant_id, name, code, company, address_line1, address_line2, city, county, country, postcode, is_default, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1)",
            [
                $this->app->tenant->id(),
                $name,
                $code,
                trim((string) $this->app->request->post('company', '')),
                trim((string) $this->app->request->post('address_line1', '')),
                trim((string) $this->app->request->post('address_line2', '')),
                trim((string) $this->app->request->post('city', '')),
                trim((string) $this->app->request->post('county', '')),
                strtoupper(substr(trim((string) $this->app->request->post('country', 'GB')) ?: 'GB', 0, 64)),
                strtoupper(trim((string) $this->app->request->post('postcode', ''))),
            ]
        );
        $this->app->session->flash('success', 'Warehouse created.');
        $this->redirect('admin/settings/warehouses');
    }
}
