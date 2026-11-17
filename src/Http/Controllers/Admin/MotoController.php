<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class MotoController extends Controller
{
    public function create(): void
    {
        $tenantId = $this->app->tenant->id();
        $channels = $this->app->db->query(
            'SELECT id, name FROM #__store_channels WHERE tenant_id = ? AND active = 1 ORDER BY name',
            [$tenantId]
        )->rows;

        echo $this->view('admin/orders/moto', [
            'title'      => 'Create order',
            'navSection' => 'orders',
            'channels'   => $channels,
            'products'   => $this->productsForPicker(),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders/moto');
        }

        $email = trim((string) $this->app->request->post('buyer_email', ''));
        $name = trim((string) $this->app->request->post('shipping_name', ''));
        $channelId = (int) $this->app->request->post('channel_id', 0);
        $lines = $this->postedLines();
        if ($email === '' || $name === '' || $channelId <= 0) {
            $this->app->session->flash('error', 'Channel, name and email are required.');
            $this->redirect('admin/orders/moto');
        }
        if ($lines === []) {
            $this->app->session->flash('error', 'Add at least one product to the order.');
            $this->redirect('admin/orders/moto');
        }

        $shippingCost = (float) $this->app->request->post('shipping_cost', 0);
        $itemsTotal = 0.0;
        foreach ($lines as $line) {
            $itemsTotal += $line['line_total'];
        }
        $postedTotal = (float) $this->app->request->post('total', 0);
        $total = $postedTotal > 0 ? $postedTotal : round($itemsTotal + $shippingCost, 2);

        $addr = json_encode([
            'line1'    => trim((string) $this->app->request->post('line1', '')),
            'city'     => trim((string) $this->app->request->post('city', '')),
            'postcode' => trim((string) $this->app->request->post('postcode', '')),
            'country'  => 'GB',
        ], JSON_UNESCAPED_UNICODE);

        $ins = $this->app->db->query(
            'INSERT INTO #__store_orders
             (tenant_id, channel_id, external_order_id, status, buyer_email, shipping_name, total,
              shipping_cost, currency, shipping_address, shipping_method, warehouse, is_paid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $this->app->tenant->id(),
                $channelId,
                'pending',
                (string) $this->app->request->post('status', 'awaiting_despatch'),
                $email,
                $name,
                $total,
                $shippingCost,
                'GBP',
                $addr,
                trim((string) $this->app->request->post('shipping_method', 'Collection')),
                'Default',
            ]
        );
        $orderId = (int) ($ins->insert_id ?: 0);
        $ref = (string) $orderId;
        $this->app->db->query(
            'UPDATE #__store_orders SET external_order_id=? WHERE id=? AND tenant_id=?',
            [$ref, $orderId, $this->app->tenant->id()]
        );

        foreach ($lines as $line) {
            $this->app->db->query(
                'INSERT INTO #__store_order_items (order_id, product_id, sku, name, qty, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $line['product_id'] ?: null,
                    $line['sku'],
                    $line['name'],
                    $line['qty'],
                    $line['unit_price'],
                    $line['line_total'],
                ]
            );
        }

        $status = (string) $this->app->request->post('status', 'awaiting_despatch');
        if (in_array($status, ['awaiting_despatch', 'printed', 'processing'], true)) {
            (new \App\Store\DropshipService($this->app))->ensureForOrder($orderId);
            (new \App\Store\InventoryService($this->app))->allocateOrder($orderId);
        }

        $this->app->session->flash('success', 'Order ' . $ref . ' created with ' . count($lines) . ' line(s).');
        $this->redirect('admin/orders/view', ['id' => $orderId]);
    }

    /** @return list<array{product_id:int,sku:string,name:string,qty:int,unit_price:float,line_total:float}> */
    private function postedLines(): array
    {
        $skus = $this->app->request->post('line_sku', []);
        $names = $this->app->request->post('line_name', []);
        $qtys = $this->app->request->post('line_qty', []);
        $prices = $this->app->request->post('line_price', []);
        $pids = $this->app->request->post('line_product_id', []);
        if (!is_array($skus)) {
            return [];
        }

        $out = [];
        foreach ($skus as $i => $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $qty = max(1, (int) ($qtys[$i] ?? 1));
            $unit = (float) ($prices[$i] ?? 0);
            $out[] = [
                'product_id' => (int) ($pids[$i] ?? 0),
                'sku'        => $sku,
                'name'       => trim((string) ($names[$i] ?? $sku)),
                'qty'        => $qty,
                'unit_price' => $unit,
                'line_total' => round($qty * $unit, 2),
            ];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function productsForPicker(): array
    {
        return $this->app->db->query(
            "SELECT p.id, p.sku, p.name, p.barcode, p.price,
                    COALESCE(SUM(i.qty_on_hand), 0) AS qty_on_hand
             FROM #__store_products p
             LEFT JOIN #__store_inventory i ON i.product_id = p.id AND i.tenant_id = p.tenant_id
             WHERE p.tenant_id=? AND p.active=1
             GROUP BY p.id, p.sku, p.name, p.barcode, p.price
             ORDER BY p.sku ASC
             LIMIT 2000",
            [$this->app->tenant->id()]
        )->rows;
    }
}
