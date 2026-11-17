<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class SuppliersController extends Controller
{
    public function index(): void
    {
        echo $this->view('admin/products/suppliers', [
            'title'      => 'Suppliers',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function create(): void
    {
        echo $this->view('admin/products/supplier_form', [
            'title'      => 'Create supplier',
            'navSection' => 'products',
            'supplier'   => null,
            'templates'  => [],
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/suppliers');
        }
        $data = $this->postedSupplier();
        if ($data['name'] === '') {
            $this->app->session->flash('error', 'Supplier name is required.');
            $this->redirect('admin/suppliers/new');
        }

        $cols = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
        $colSql = 'tenant_id, ' . implode(', ', $cols);
        $bind = array_merge([$this->app->tenant->id()], array_values($data));

        $ins = $this->app->db->query(
            "INSERT INTO #__store_suppliers ({$colSql}) VALUES ({$placeholders})",
            $bind
        );
        $id = (int) ($ins->insert_id ?: 0);
        $this->app->session->flash('success', 'Supplier created.');
        $this->redirect('admin/suppliers/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $supplier = $this->find($id);
        if ($supplier === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'products',
                'message'    => 'Supplier not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        // Backfill structured address from legacy free-text column once.
        if (
            trim((string) ($supplier['address_line1'] ?? '')) === ''
            && trim((string) ($supplier['address'] ?? '')) !== ''
        ) {
            $supplier['address_line1'] = (string) $supplier['address'];
        }

        $templates = $this->templatesFor($id);
        $selectedTemplateId = (int) $this->app->request->get('template_id', 0);
        if ($selectedTemplateId > 0) {
            usort($templates, static function (array $a, array $b) use ($selectedTemplateId): int {
                $aHit = (int) ($a['id'] ?? 0) === $selectedTemplateId ? 0 : 1;
                $bHit = (int) ($b['id'] ?? 0) === $selectedTemplateId ? 0 : 1;
                return $aHit <=> $bHit;
            });
        }

        echo $this->view('admin/products/supplier_form', [
            'title'      => 'Supplier',
            'navSection' => 'products',
            'supplier'   => $supplier,
            'templates'  => $templates,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/suppliers');
        }
        $id = (int) $this->app->request->post('id', 0);
        if ($this->find($id) === null) {
            $this->app->session->flash('error', 'Supplier not found.');
            $this->redirect('admin/suppliers');
        }

        $data = $this->postedSupplier();
        if ($data['name'] === '') {
            $this->app->session->flash('error', 'Supplier name is required.');
            $this->redirect('admin/suppliers/edit', ['id' => $id]);
        }

        $sets = [];
        $bind = [];
        foreach ($data as $col => $val) {
            $sets[] = "{$col}=?";
            $bind[] = $val;
        }
        $bind[] = $id;
        $bind[] = $this->app->tenant->id();

        $this->app->db->query(
            'UPDATE #__store_suppliers SET ' . implode(', ', $sets) . ' WHERE id=? AND tenant_id=?',
            $bind
        );
        $this->app->session->flash('success', 'Supplier saved.');
        $this->redirect('admin/suppliers/edit', ['id' => $id]);
    }

    public function delete(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/suppliers');
        }
        $id = (int) $this->app->request->post('id', 0);
        $supplier = $this->find($id);
        if ($supplier === null) {
            $this->app->session->flash('error', 'Supplier not found.');
            $this->redirect('admin/suppliers');
        }

        $openPos = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_purchase_orders
             WHERE tenant_id=? AND supplier_id=? AND status IN ('draft','ordered','partial')",
            [$this->app->tenant->id(), $id]
        )->row['c'] ?? 0);
        if ($openPos > 0) {
            $this->app->session->flash('error', "Cannot delete — {$openPos} open purchase order(s) still linked.");
            $this->redirect('admin/suppliers/edit', ['id' => $id]);
        }

        $this->app->db->query(
            'UPDATE #__store_purchase_orders SET supplier_id=NULL WHERE supplier_id=? AND tenant_id=?',
            [$id, $this->app->tenant->id()]
        );
        $this->app->db->query(
            'UPDATE #__store_dropships SET supplier_id=NULL WHERE supplier_id=? AND tenant_id=?',
            [$id, $this->app->tenant->id()]
        );
        $this->app->db->query(
            'DELETE FROM #__store_suppliers WHERE id=? AND tenant_id=?',
            [$id, $this->app->tenant->id()]
        );
        $this->app->db->query(
            'DELETE FROM #__store_supplier_export_templates WHERE supplier_id=? AND tenant_id=?',
            [$id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Supplier deleted.');
        $this->redirect('admin/suppliers');
    }

    public function saveTemplate(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/suppliers');
        }
        $supplierId = (int) $this->app->request->post('supplier_id', 0);
        if ($this->find($supplierId) === null) {
            $this->app->session->flash('error', 'Supplier not found.');
            $this->redirect('admin/suppliers');
        }

        $id = (int) $this->app->request->post('template_id', 0);
        $data = [
            'name'            => trim((string) $this->app->request->post('template_name', '')),
            'transfer_method' => $this->enum((string) $this->app->request->post('transfer_method', 'manual'), ['manual', 'email', 'ftp', 'sftp'], 'manual'),
            'export_method'   => $this->enum((string) $this->app->request->post('export_method', 'csv'), ['csv', 'xml', 'api'], 'csv'),
            'template_type'   => $this->enum(
                (string) $this->app->request->post('template_type', 'drop_shipment_lines'),
                ['drop_shipment_lines', 'purchase_order_lines', 'products'],
                'drop_shipment_lines'
            ),
            'is_default'      => $this->app->request->post('is_default', '') !== '' ? 1 : 0,
            'delimiter'       => substr(trim((string) $this->app->request->post('delimiter', ',')) ?: ',', 0, 8),
            'export_headers'  => $this->app->request->post('export_headers', '') !== '' ? 1 : 0,
        ];
        if ($data['name'] === '') {
            $data['name'] = ucwords(str_replace('_', ' ', $data['template_type']));
        }

        $tenantId = $this->app->tenant->id();
        if ($data['is_default'] === 1) {
            $this->app->db->query(
                'UPDATE #__store_supplier_export_templates SET is_default=0 WHERE supplier_id=? AND tenant_id=?',
                [$supplierId, $tenantId]
            );
        }

        if ($id > 0) {
            $existing = $this->app->db->query(
                'SELECT id FROM #__store_supplier_export_templates WHERE id=? AND supplier_id=? AND tenant_id=? LIMIT 1',
                [$id, $supplierId, $tenantId]
            )->row;
            if ($existing === []) {
                $this->app->session->flash('error', 'Export template not found.');
                $this->redirect('admin/suppliers/edit', ['id' => $supplierId]);
            }
            $this->app->db->query(
                "UPDATE #__store_supplier_export_templates
                 SET name=?, transfer_method=?, export_method=?, template_type=?, is_default=?, delimiter=?, export_headers=?
                 WHERE id=? AND tenant_id=?",
                [
                    $data['name'], $data['transfer_method'], $data['export_method'], $data['template_type'],
                    $data['is_default'], $data['delimiter'], $data['export_headers'], $id, $tenantId,
                ]
            );
            $this->app->session->flash('success', 'Export template saved.');
        } else {
            $this->app->db->query(
                "INSERT INTO #__store_supplier_export_templates
                 (tenant_id, supplier_id, name, transfer_method, export_method, template_type, is_default, delimiter, export_headers)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $tenantId, $supplierId, $data['name'], $data['transfer_method'], $data['export_method'],
                    $data['template_type'], $data['is_default'], $data['delimiter'], $data['export_headers'],
                ]
            );
            $this->app->session->flash('success', 'Export template added.');
        }

        $this->redirect('admin/suppliers/edit', ['id' => $supplierId]);
    }

    public function deleteTemplate(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/suppliers');
        }
        $supplierId = (int) $this->app->request->post('supplier_id', 0);
        $id = (int) $this->app->request->post('template_id', 0);
        $this->app->db->query(
            'DELETE FROM #__store_supplier_export_templates WHERE id=? AND supplier_id=? AND tenant_id=?',
            [$id, $supplierId, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Export template deleted.');
        $this->redirect('admin/suppliers/edit', ['id' => $supplierId]);
    }

    /** @return list<array<string,mixed>> */
    private function templatesFor(int $supplierId): array
    {
        return $this->app->db->query(
            'SELECT * FROM #__store_supplier_export_templates
             WHERE supplier_id=? AND tenant_id=? ORDER BY is_default DESC, id ASC',
            [$supplierId, $this->app->tenant->id()]
        )->rows;
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT * FROM #__store_suppliers WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return array<string, int|float|string> */
    private function postedSupplier(): array
    {
        $flag = fn(string $key): int => $this->app->request->post($key, '') !== '' ? 1 : 0;
        $line1 = trim((string) $this->app->request->post('address_line1', ''));
        $currency = strtoupper(substr(trim((string) $this->app->request->post('currency', 'GBP')) ?: 'GBP', 0, 8));

        return [
            'name'                       => trim((string) $this->app->request->post('name', '')),
            'contact_name'               => trim((string) $this->app->request->post('contact_name', '')),
            'code'                       => trim((string) $this->app->request->post('code', '')),
            'supplier_reference'         => trim((string) $this->app->request->post('supplier_reference', '')),
            'customs_mid'                => trim((string) $this->app->request->post('customs_mid', '')),
            'email'                      => trim((string) $this->app->request->post('email', '')),
            'notify_emails'              => trim((string) $this->app->request->post('notify_emails', '')),
            'contact_email'              => trim((string) $this->app->request->post('contact_email', '')),
            'phone'                      => trim((string) $this->app->request->post('phone', '')),
            'address_line1'              => $line1,
            'address_line2'              => trim((string) $this->app->request->post('address_line2', '')),
            'city'                       => trim((string) $this->app->request->post('city', '')),
            'county'                     => trim((string) $this->app->request->post('county', '')),
            'country'                    => strtoupper(substr(trim((string) $this->app->request->post('country', 'GB')) ?: 'GB', 0, 64)),
            'postcode'                   => strtoupper(trim((string) $this->app->request->post('postcode', ''))),
            'address'                    => $line1,
            'notes'                      => trim((string) $this->app->request->post('notes', '')),
            'currency'                   => $currency,
            'min_order_value'            => round((float) $this->app->request->post('min_order_value', 0), 2),
            'lead_time_days'             => max(0, (int) $this->app->request->post('lead_time_days', 0)),
            'po_mode'                    => $this->enum(
                (string) $this->app->request->post('po_mode', 'on_demand'),
                ['on_demand', 'scheduled', 'manual'],
                'on_demand'
            ),
            'email_below_reorder'        => $flag('email_below_reorder'),
            'email_below_oos'            => $flag('email_below_oos'),
            'include_equal_reorder'      => $flag('include_equal_reorder'),
            'exclude_oos_zero_reorder'   => $flag('exclude_oos_zero_reorder'),
            'accepts_backorders'         => $flag('accepts_backorders'),
            'is_manufacturer'            => $flag('is_manufacturer'),
            'po_shipping_cost_type'      => $this->enum(
                (string) $this->app->request->post('po_shipping_cost_type', 'per_order'),
                ['per_order', 'per_item', 'fixed', 'none'],
                'per_order'
            ),
            'dropship_shipping_cost_type'=> $this->enum(
                (string) $this->app->request->post('dropship_shipping_cost_type', 'per_item'),
                ['per_order', 'per_item', 'fixed', 'none'],
                'per_item'
            ),
            'po_change_status'           => trim((string) $this->app->request->post('po_change_status', '')),
            'dropship_change_status'     => trim((string) $this->app->request->post('dropship_change_status', '')),
            'po_shipping_cost'           => round((float) $this->app->request->post('po_shipping_cost', 0), 2),
            'dropship_item_shipping_cost'=> round((float) $this->app->request->post('dropship_item_shipping_cost', 0), 2),
            'save_cost_to_product'       => $flag('save_cost_to_product'),
            'is_dropship_supplier'       => $flag('is_dropship_supplier'),
            'include_in_requisitions'    => $flag('include_in_requisitions'),
            'consolidate_dropship_emails'=> $flag('consolidate_dropship_emails'),
            'req_include_unpaid_business'=> $flag('req_include_unpaid_business'),
            'req_exclude_zero_supplier_stock' => $flag('req_exclude_zero_supplier_stock'),
            'active'                     => $flag('active'),
        ];
    }

    /** @param list<string> $allowed */
    private function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }
}
