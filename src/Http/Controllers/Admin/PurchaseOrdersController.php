<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\PurchaseOrderService;

final class PurchaseOrdersController extends Controller
{
    public function index(): void
    {
        echo $this->view('admin/products/purchase_orders', [
            'title'      => 'Purchase orders',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function create(): void
    {
        echo $this->view('admin/products/purchase_order_form', [
            'title'      => 'Create Purchase Order',
            'navSection' => 'products',
            'po'         => null,
            'items'      => [],
            'suppliers'  => $this->suppliersPayload(),
            'warehouses' => $this->warehousesPayload(),
            'products'   => $this->productsForPicker(),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/purchase-orders');
        }

        $result = (new PurchaseOrderService($this->app))->create(
            $this->postedHeader(),
            $this->postedLines()
        );

        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        if ($result['ok'] && !empty($result['id'])) {
            $this->redirect('admin/purchase-orders/view', ['id' => $result['id']]);
        }
        $this->redirect('admin/purchase-orders/new');
    }

    public function show(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $svc = new PurchaseOrderService($this->app);
        $po = $svc->get($id);
        if ($po === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'products',
                'message'    => 'Purchase order not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        echo $this->view('admin/products/purchase_order_view', [
            'title'      => 'PO ' . (string) ($po['reference'] ?? $id),
            'navSection' => 'products',
            'po'         => $po,
            'items'      => $svc->items($id),
            'suppliers'  => $this->suppliersPayload((int) ($po['supplier_id'] ?? 0)),
            'warehouses' => $this->warehousesPayload((int) ($po['warehouse_id'] ?? 0)),
            'products'   => $this->productsForPicker(),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/purchase-orders');
        }
        $id = (int) $this->app->request->post('id', 0);
        $header = $this->postedHeader();
        $editLines = in_array((string) ($header['status'] ?? 'draft'), ['draft', 'ordered'], true);

        $result = (new PurchaseOrderService($this->app))->update(
            $id,
            $header,
            $editLines ? $this->postedLines() : null
        );

        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/purchase-orders/view', ['id' => $id]);
    }

    public function receive(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/purchase-orders');
        }
        $id = (int) $this->app->request->post('id', 0);
        $raw = $this->app->request->post('receive_qty', []);
        $map = [];
        if (is_array($raw)) {
            foreach ($raw as $itemId => $qty) {
                $map[(int) $itemId] = (int) $qty;
            }
        }

        $result = (new PurchaseOrderService($this->app))->receive($id, $map);
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/purchase-orders/view', ['id' => $id]);
    }

    /** @return array<string,mixed> */
    private function postedHeader(): array
    {
        return [
            'supplier_id'           => (int) $this->app->request->post('supplier_id', 0),
            'warehouse_id'          => (int) $this->app->request->post('warehouse_id', 0),
            'reference'             => trim((string) $this->app->request->post('reference', '')),
            'status'                => (string) $this->app->request->post('status', 'draft'),
            'currency'              => trim((string) $this->app->request->post('currency', 'GBP')),
            'delivery_cost'         => (float) $this->app->request->post('delivery_cost', 0),
            'include_delivery_cost' => $this->app->request->post('include_delivery_cost', '') !== '',
            'expected_at'           => trim((string) $this->app->request->post('expected_at', '')),
            'date_sent'             => trim((string) $this->app->request->post('date_sent', '')),
            'paid_on'               => trim((string) $this->app->request->post('paid_on', '')),
            'payment_reference'     => trim((string) $this->app->request->post('payment_reference', '')),
            'payment_confirmed_by'  => trim((string) $this->app->request->post('payment_confirmed_by', '')),
            'change_to_status'      => trim((string) $this->app->request->post('change_to_status', '')),
            'palletized'            => $this->app->request->post('palletized', '') !== '',
            'estimated_pallets'     => (int) $this->app->request->post('estimated_pallets', 0),
            'notes'                 => trim((string) $this->app->request->post('notes', '')),
            'delivery_company'      => trim((string) $this->app->request->post('delivery_company', '')),
            'delivery_line1'        => trim((string) $this->app->request->post('delivery_line1', '')),
            'delivery_line2'        => trim((string) $this->app->request->post('delivery_line2', '')),
            'delivery_city'         => trim((string) $this->app->request->post('delivery_city', '')),
            'delivery_country'      => trim((string) $this->app->request->post('delivery_country', 'GB')),
            'delivery_postcode'     => trim((string) $this->app->request->post('delivery_postcode', '')),
        ];
    }

    /** @return list<array{sku:string,name:string,qty:int,unit_cost:float}> */
    private function postedLines(): array
    {
        $skus = $this->app->request->post('line_sku', []);
        $names = $this->app->request->post('line_name', []);
        $qtys = $this->app->request->post('line_qty', []);
        $costs = $this->app->request->post('line_cost', []);
        if (!is_array($skus)) {
            return [];
        }
        $lines = [];
        foreach ($skus as $i => $sku) {
            $lines[] = [
                'sku'       => trim((string) $sku),
                'name'      => trim((string) ($names[$i] ?? '')),
                'qty'       => (int) ($qtys[$i] ?? 0),
                'unit_cost' => (float) ($costs[$i] ?? 0),
            ];
        }
        return $lines;
    }

    /** @return list<array<string,mixed>> */
    private function suppliersPayload(int $includeId = 0): array
    {
        return $this->app->db->query(
            "SELECT id, name, code, currency, email, phone, contact_name, supplier_reference,
                    address_line1, address_line2, city, county, country, postcode,
                    lead_time_days, min_order_value, po_change_status, active
             FROM #__store_suppliers
             WHERE tenant_id=? AND (active=1 OR id=?)
             ORDER BY name ASC",
            [$this->app->tenant->id(), $includeId]
        )->rows;
    }

    /** @return list<array<string,mixed>> */
    private function warehousesPayload(int $includeId = 0): array
    {
        return $this->app->db->query(
            "SELECT id, name, code, company, address_line1, address_line2, city, county, country, postcode, is_default, active
             FROM #__store_warehouses
             WHERE tenant_id=? AND (active=1 OR id=?)
             ORDER BY is_default DESC, name ASC",
            [$this->app->tenant->id(), $includeId]
        )->rows;
    }

    /** @return list<array<string,mixed>> */
    private function productsForPicker(): array
    {
        return $this->app->db->query(
            "SELECT p.id, p.sku, p.name, p.barcode, p.supplier_sku, p.supplier_id, p.supplier_name,
                    p.cost, p.price, p.image,
                    COALESCE(SUM(i.qty_on_hand), 0) AS qty_on_hand,
                    COALESCE(SUM(i.qty_allocated), 0) AS qty_allocated
             FROM #__store_products p
             LEFT JOIN #__store_inventory i ON i.product_id = p.id AND i.tenant_id = p.tenant_id
             WHERE p.tenant_id=? AND p.active=1
             GROUP BY p.id, p.sku, p.name, p.barcode, p.supplier_sku, p.supplier_id, p.supplier_name,
                      p.cost, p.price, p.image
             ORDER BY p.sku ASC
             LIMIT 2000",
            [$this->app->tenant->id()]
        )->rows;
    }
}
