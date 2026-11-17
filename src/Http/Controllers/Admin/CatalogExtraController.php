<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class CatalogExtraController extends Controller
{
    public function categories(): void
    {
        echo $this->view('admin/products/categories', [
            'title'      => 'Category manager',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storeCategory(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/categories');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        if ($name !== '') {
            $this->app->db->query(
                'INSERT INTO #__store_categories (tenant_id, name, active) VALUES (?, ?, 1)',
                [$this->app->tenant->id(), $name]
            );
            $this->app->session->flash('success', 'Category created.');
        }
        $this->redirect('admin/categories');
    }

    public function dropships(): void
    {
        echo $this->view('admin/products/dropships', [
            'title'      => 'Drop shipments',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function dropshipStatus(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/dropships');
        }
        $id = (int) $this->app->request->post('id', 0);
        $status = trim((string) $this->app->request->post('status', ''));
        $result = (new \App\Store\DropshipService($this->app))->updateStatus($id, $status);
        $this->app->session->flash($result['ok'] ? 'success' : 'error', (string) ($result['message'] ?? ''));
        $this->redirect('admin/dropships');
    }

    public function bulkInventory(): void
    {
        echo $this->view('admin/products/bulk_inventory', [
            'title'      => 'Bulk inventory update',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function bulkInventoryStore(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/inventory/bulk');
        }
        $csv = trim((string) $this->app->request->post('csv', ''));
        if ($csv === '') {
            $this->app->session->flash('error', 'Paste SKU,qty rows.');
            $this->redirect('admin/inventory/bulk');
        }
        $updated = 0;
        foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'sku') === 0) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                continue;
            }
            $sku = trim($parts[0]);
            $qty = (int) $parts[1];
            $product = $this->app->db->query(
                'SELECT id FROM #__store_products WHERE tenant_id = ? AND sku = ? LIMIT 1',
                [$this->app->tenant->id(), $sku]
            )->row;
            if ($product === []) {
                continue;
            }
            $inv = $this->app->db->query(
                'SELECT id FROM #__store_inventory WHERE tenant_id = ? AND product_id = ? LIMIT 1',
                [$this->app->tenant->id(), $product['id']]
            )->row;
            if ($inv === []) {
                $this->app->db->query(
                    'INSERT INTO #__store_inventory (tenant_id, product_id, qty_on_hand) VALUES (?, ?, ?)',
                    [$this->app->tenant->id(), $product['id'], $qty]
                );
            } else {
                $this->app->db->query(
                    'UPDATE #__store_inventory SET qty_on_hand = ? WHERE id = ?',
                    [$qty, $inv['id']]
                );
            }
            $updated++;
        }
        $this->app->session->flash('success', "Updated {$updated} SKUs.");
        $this->redirect('admin/inventory/bulk');
    }

    public function purchaseOrderImport(): void
    {
        $suppliers = $this->app->db->query(
            'SELECT id, name FROM #__store_suppliers
             WHERE tenant_id=? AND active=1
             ORDER BY name ASC',
            [$this->app->tenant->id()]
        )->rows;

        echo $this->view('admin/products/po_import', [
            'title'      => 'Purchase order import',
            'navSection' => 'products',
            'suppliers'  => $suppliers,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function purchaseOrderImportStore(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/purchase-orders/import');
        }

        $supplierId = (int) $this->app->request->post('supplier_id', 0);
        $csv = trim((string) $this->app->request->post('csv', ''));
        $reference = trim((string) $this->app->request->post('reference', ''));
        $asOrdered = $this->app->request->post('status_ordered', '') !== '';

        if ($supplierId <= 0) {
            $this->app->session->flash('error', 'Select a supplier.');
            $this->redirect('admin/purchase-orders/import');
        }
        if ($csv === '') {
            $this->app->session->flash('error', 'Paste CSV lines with SKU and qty.');
            $this->redirect('admin/purchase-orders/import');
        }

        $tenantId = $this->app->tenant->id();
        $lines = [];
        $skipped = [];
        foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'sku') === 0) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 2) {
                continue;
            }
            $sku = trim((string) $parts[0]);
            $qty = (int) $parts[1];
            if ($sku === '' || $qty <= 0) {
                continue;
            }
            $product = $this->app->db->query(
                'SELECT id, name, cost FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
                [$tenantId, $sku]
            )->row;
            if ($product === []) {
                $skipped[] = $sku;
                continue;
            }
            $name = trim((string) ($parts[2] ?? ''));
            if ($name === '') {
                $name = (string) ($product['name'] ?? $sku);
            }
            $unit = isset($parts[3]) && trim((string) $parts[3]) !== ''
                ? (float) $parts[3]
                : (float) ($product['cost'] ?? 0);
            $lines[] = [
                'sku'       => $sku,
                'name'      => $name,
                'qty'       => $qty,
                'unit_cost' => round($unit, 2),
            ];
        }

        if ($lines === []) {
            $msg = 'No known SKUs to import.';
            if ($skipped !== []) {
                $msg .= ' Skipped: ' . implode(', ', array_slice($skipped, 0, 20));
            }
            $this->app->session->flash('error', $msg);
            $this->redirect('admin/purchase-orders/import');
        }

        $result = (new \App\Store\PurchaseOrderService($this->app))->create(
            [
                'supplier_id' => $supplierId,
                'reference'   => $reference,
                'status'      => $asOrdered ? 'ordered' : 'draft',
            ],
            $lines
        );

        if (empty($result['ok'])) {
            $this->app->session->flash('error', (string) ($result['message'] ?? 'Import failed.'));
            $this->redirect('admin/purchase-orders/import');
        }

        $msg = (string) ($result['message'] ?? 'Purchase order created.');
        $msg .= ' Imported ' . count($lines) . ' line(s).';
        if ($skipped !== []) {
            $msg .= ' Skipped unknown SKU(s): ' . implode(', ', array_slice($skipped, 0, 20));
            if (count($skipped) > 20) {
                $msg .= ' +' . (count($skipped) - 20) . ' more';
            }
        }
        $this->app->session->flash('success', $msg);
        $this->redirect('admin/purchase-orders/view', ['id' => (int) ($result['id'] ?? 0)]);
    }
}
