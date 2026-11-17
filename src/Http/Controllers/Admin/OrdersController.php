<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class OrdersController extends Controller
{
    public function index(): void
    {
        echo $this->view('admin/orders/index', [
            'title'      => 'Orders',
            'navSection' => 'orders',
            'view'       => (string) $this->app->request->get('view', 'all'),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function bulk(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders');
        }

        $view = (string) $this->app->request->post('return_view', 'all');
        $action = (string) $this->app->request->post('bulk_action', '');
        $ids = $this->selectedIds();
        if ($ids === []) {
            $this->app->session->flash('error', 'Select at least one order.');
            $this->redirect('admin/orders', $view !== 'all' ? ['view' => $view] : []);
        }

        $tenantId = $this->app->tenant->id();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $n = count($ids);

        $inventory = new \App\Store\InventoryService($this->app);

        if ($action === 'printed') {
            $params = array_merge(['printed'], $ids, [$tenantId]);
            $this->app->db->query(
                "UPDATE #__store_orders SET status=? WHERE id IN ({$placeholders}) AND tenant_id=?",
                $params
            );
            $dropships = new \App\Store\DropshipService($this->app);
            foreach ($ids as $oid) {
                $dropships->ensureForOrder($oid);
                $inventory->allocateOrder($oid);
            }
            $this->app->session->flash('success', "Marked {$n} order(s) printed.");
        } elseif ($action === 'despatch') {
            $prefix = trim((string) $this->app->request->post('tracking_prefix', 'RM'));
            $defaultCarrier = trim((string) $this->app->request->post('carrier_code', 'royal_mail'));
            $result = (new \App\Store\DespatchService($this->app))->despatchOrders(
                $ids,
                $prefix,
                $defaultCarrier,
                true,
                true
            );
            $msg = "Despatched {$result['despatched']} order(s). Shipping rules mapped {$result['mapped']}.";
            if (($result['stock_warnings'] ?? []) !== []) {
                $msg .= ' Stock note: ' . implode(' ', $result['stock_warnings']);
            }
            if (($result['label_warnings'] ?? []) !== []) {
                $msg .= ' Label: ' . implode(' ', $result['label_warnings']);
            }
            if (($result['fulfillment_warnings'] ?? []) !== []) {
                $msg .= ' Channel upload: ' . implode(' ', $result['fulfillment_warnings']);
            }
            $this->app->session->flash('success', $msg);
        } elseif ($action === 'hold') {
            foreach ($ids as $oid) {
                $inventory->releaseOrder($oid);
            }
            $params = array_merge($ids, [$tenantId]);
            $this->app->db->query(
                "UPDATE #__store_orders SET status='on_hold', on_hold=1
                 WHERE id IN ({$placeholders}) AND tenant_id=?",
                $params
            );
            $this->app->session->flash('success', "Put {$n} order(s) on hold.");
        } elseif ($action === 'release') {
            $params = array_merge($ids, [$tenantId]);
            $this->app->db->query(
                "UPDATE #__store_orders SET status='awaiting_despatch', on_hold=0
                 WHERE id IN ({$placeholders}) AND tenant_id=? AND status='on_hold'",
                $params
            );
            foreach ($ids as $oid) {
                $inventory->allocateOrder($oid);
            }
            $this->app->session->flash('success', "Released {$n} order(s) from hold.");
        } else {
            $this->app->session->flash('error', 'Unknown bulk action.');
        }

        $this->redirect('admin/orders', $view !== 'all' ? ['view' => $view] : []);
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        $raw = $this->app->request->post('order_ids', []);
        if (!is_array($raw)) {
            $raw = [];
        }
        $ids = [];
        foreach ($raw as $v) {
            $id = (int) $v;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    public function show(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $tenantId = $this->app->tenant->id();
        $order = $this->app->db->query(
            'SELECT o.*, c.name AS channel_name, c.code AS channel_code, c.type AS channel_type,
                    c.settings AS channel_settings
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE o.id = ? AND o.tenant_id = ?
             LIMIT 1',
            [$id, $tenantId]
        )->row;
        if ($order === []) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'orders',
                'message'    => 'Order not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        $items = $this->app->db->query(
            'SELECT oi.*,
                    COALESCE(NULLIF(TRIM(oi.sku), \'\'), NULLIF(TRIM(p.sku), \'\'), \'\') AS sku,
                    COALESCE(NULLIF(TRIM(p.name), \'\'), oi.name) AS product_name,
                    p.id AS linked_product_id
             FROM #__store_order_items oi
             LEFT JOIN #__store_products p ON p.id = oi.product_id AND p.tenant_id = ?
             WHERE oi.order_id = ?
             ORDER BY oi.id',
            [$tenantId, $id]
        )->rows;

        $shipment = $this->app->db->query(
            'SELECT id, tracking_number, carrier_code, fulfillment_status, fulfillment_error,
                    despatched_at, labelled_at, manifest_id, created_at
             FROM #__store_shipments
             WHERE tenant_id=? AND order_id=?
             ORDER BY id DESC LIMIT 1',
            [$tenantId, $id]
        )->row ?: null;

        $pickwave = $this->app->db->query(
            'SELECT po.pickwave_id, p.reference, p.status AS pickwave_status
             FROM #__store_pickwave_orders po
             LEFT JOIN #__store_pickwaves p ON p.id = po.pickwave_id AND p.tenant_id = po.tenant_id
             WHERE po.order_id = ? AND po.tenant_id = ?
             ORDER BY po.pickwave_id DESC
             LIMIT 1',
            [$id, $tenantId]
        )->row ?: null;

        $packagingSizes = $this->app->db->query(
            'SELECT id, name FROM #__store_packaging_sizes WHERE tenant_id=? AND active=1 ORDER BY name',
            [$tenantId]
        )->rows;

        $addr = $this->decodeAddress($order['shipping_address'] ?? null);
        $billing = $this->decodeAddress($order['billing_address'] ?? null);
        if ($billing === []) {
            $billing = $addr;
        }

        $titleExternal = trim((string) ($order['external_order_id'] ?? ''));
        echo $this->view('admin/orders/view', [
            'title'           => 'Edit Order ' . ($titleExternal !== '' ? $titleExternal : '#' . $id),
            'navSection'      => 'orders',
            'order'           => $order,
            'address'         => $addr,
            'billing'         => $billing,
            'items'           => $items,
            'shipment'        => $shipment,
            'pickwave'        => $pickwave,
            'packagingSizes'  => $packagingSizes,
            'flash'           => $this->app->session->flash('success'),
            'error'           => $this->app->session->flash('error'),
            'user'            => $this->app->auth->user(),
        ]);
    }

    /** @return array<string,mixed> */
    private function decodeAddress(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $addr = is_array($decoded) ? $decoded : [];
        } else {
            $addr = is_array($raw) ? $raw : [];
        }
        if ($addr !== []) {
            $addr['phone'] = $this->scalarPhone($addr['phone'] ?? null);
        }
        return $addr;
    }

    private function scalarPhone(mixed $raw): string
    {
        if (is_array($raw)) {
            foreach (['number', 'phoneNumber', 'PhoneNumber', 'phone', 'value'] as $key) {
                if (!array_key_exists($key, $raw)) {
                    continue;
                }
                $nested = $this->scalarPhone($raw[$key]);
                if ($nested !== '') {
                    return $nested;
                }
            }
            // First scalar leaf if structure is unexpected.
            foreach ($raw as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $candidate = trim((string) $value);
                    if (strcasecmp($candidate, 'Array') !== 0) {
                        return mb_substr($candidate, 0, 64);
                    }
                }
            }
            return '';
        }
        $value = trim((string) ($raw ?? ''));
        if ($value === '' || strcasecmp($value, 'Array') === 0) {
            return '';
        }
        return mb_substr($value, 0, 64);
    }

    public function retryFulfillment(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders');
        }
        $orderId = (int) $this->app->request->post('order_id', 0);
        $shipmentId = (int) $this->app->request->post('shipment_id', 0);
        $result = (new \App\Store\MarketplaceFulfillmentService($this->app))->uploadForOrder($orderId, $shipmentId);
        $status = (string) ($result['status'] ?? '');
        if ($status === 'uploaded') {
            $this->app->session->flash('success', 'Channel fulfillment uploaded.');
        } elseif ($status === 'skipped' || $status === 'unsupported') {
            $this->app->session->flash('success', 'Skipped: ' . (string) ($result['message'] ?? $status));
        } else {
            $this->app->session->flash('error', 'Upload failed: ' . (string) ($result['message'] ?? $status));
        }
        $this->redirect('admin/orders/view', ['id' => $orderId]);
    }

    public function cancelOnChannel(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders');
        }
        $orderId = (int) $this->app->request->post('order_id', 0);
        $reason = trim((string) $this->app->request->post('reason', 'seller_cancel'));
        $result = (new \App\Store\OrderChannelActionService($this->app))->cancelOnChannelAndLocal($orderId, $reason);
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/orders/view', ['id' => $orderId]);
    }

    public function refundOnChannel(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders');
        }
        $orderId = (int) $this->app->request->post('order_id', 0);
        $amountRaw = trim((string) $this->app->request->post('amount', ''));
        $amount = $amountRaw !== '' ? (float) $amountRaw : null;
        $comments = trim((string) $this->app->request->post('comments', ''));
        $result = (new \App\Store\OrderChannelActionService($this->app))->issueRefund($orderId, $amount, $comments);
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/orders/view', ['id' => $orderId]);
    }

    public function updateStatus(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders');
        }
        $id = (int) $this->app->request->post('id', 0);
        $status = (string) $this->app->request->post('status', '');
        $allowed = [
            'awaiting_payment', 'awaiting_despatch', 'processing', 'printed',
            'despatched', 'on_hold', 'cancelled', 'refunded',
        ];
        if (!in_array($status, $allowed, true)) {
            $this->redirect('admin/orders/view', ['id' => $id]);
        }

        $tracking = trim((string) $this->app->request->post('tracking_number', ''));
        $carrier = trim((string) $this->app->request->post('carrier_code', ''));
        $shippingMethod = trim((string) $this->app->request->post('shipping_method', ''));
        $notes = trim((string) $this->app->request->post('notes', ''));
        $packagingSizeId = (int) $this->app->request->post('packaging_size_id', 0);
        $packageCount = max(1, (int) $this->app->request->post('package_count', 1));
        $weightKg = (float) $this->app->request->post('order_weight_kg', 0);
        $isPriority = $this->app->request->post('is_priority', '') !== '' ? 1 : 0;
        $attention = $this->app->request->post('attention_required', '') !== '' ? 1 : 0;
        $onHoldFlag = $status === 'on_hold' || $this->app->request->post('on_hold', '') !== '';
        $buyerEmail = trim((string) $this->app->request->post('buyer_email', ''));
        $shippingCost = max(0, (float) $this->app->request->post('shipping_cost', 0));
        $shipAddr = $this->addressFromPost('ship');
        $billAddr = $this->app->request->post('bill_same_as_ship', '') !== ''
            ? $shipAddr
            : $this->addressFromPost('bill');
        $shippingName = trim((string) ($shipAddr['name'] ?? ''));
        $itemsTotal = $this->replaceOrderItems($id, $this->app->tenant->id());
        $orderTotal = round($itemsTotal + $shippingCost, 2);

        // Persist address / contact / totals edits before any status-specific flow.
        $this->app->db->query(
            'UPDATE #__store_orders
             SET buyer_email = ?, shipping_name = ?, shipping_address = ?, billing_address = ?,
                 shipping_cost = ?, total = ?
             WHERE id = ? AND tenant_id = ?',
            [
                mb_substr($buyerEmail, 0, 255),
                mb_substr($shippingName, 0, 255),
                json_encode($shipAddr, JSON_UNESCAPED_UNICODE),
                json_encode($billAddr, JSON_UNESCAPED_UNICODE),
                $shippingCost,
                $orderTotal,
                $id,
                $this->app->tenant->id(),
            ]
        );

        // Despatch path creates store_shipments + tracking so labels/manifests stay in sync.
        if ($status === 'despatched') {
            if ($tracking !== '' || $carrier !== '') {
                $sets = [];
                $bind = [];
                if ($tracking !== '') {
                    $sets[] = 'tracking_number = ?';
                    $bind[] = $tracking;
                }
                if ($carrier !== '') {
                    $sets[] = 'carrier_code = ?';
                    $bind[] = $carrier;
                }
                $bind[] = $id;
                $bind[] = $this->app->tenant->id();
                $this->app->db->query(
                    'UPDATE #__store_orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?',
                    $bind
                );
            }
            $result = (new \App\Store\DespatchService($this->app))->despatchOrders(
                [$id],
                'RM',
                $carrier !== '' ? $carrier : 'royal_mail',
                true,
                $tracking === ''
            );
            $msg = $result['despatched'] > 0
                ? 'Order despatched with shipment / label record.'
                : 'Order was not eligible for despatch (check status).';
            if (($result['label_warnings'] ?? []) !== []) {
                $msg .= ' Label: ' . implode(' ', $result['label_warnings']);
            }
            if (($result['fulfillment_warnings'] ?? []) !== []) {
                $msg .= ' Channel upload: ' . implode(' ', $result['fulfillment_warnings']);
            }
            $this->app->session->flash($result['despatched'] > 0 ? 'success' : 'error', $msg);
            $this->redirect('admin/orders/view', ['id' => $id]);
        }

        $inventory = new \App\Store\InventoryService($this->app);
        $warehouseStatuses = ['awaiting_despatch', 'processing', 'printed'];
        $leaveWarehouse = in_array($status, ['on_hold', 'cancelled', 'refunded'], true);

        if ($leaveWarehouse) {
            $inventory->releaseOrder($id);
        }

        $sets = [
            'status = ?',
            'tracking_number = ?',
            'carrier_code = ?',
            'shipping_method = ?',
            'notes = ?',
            'packaging_size_id = ?',
            'package_count = ?',
            'order_weight_kg = ?',
            'is_priority = ?',
            'attention_required = ?',
            'on_hold = ?',
            'buyer_email = ?',
            'shipping_name = ?',
            'shipping_address = ?',
            'billing_address = ?',
            'shipping_cost = ?',
            'total = ?',
        ];
        $bind = [
            $status,
            $tracking,
            $carrier,
            $shippingMethod,
            mb_substr($notes, 0, 500),
            $packagingSizeId > 0 ? $packagingSizeId : null,
            $packageCount,
            max(0, $weightKg),
            $isPriority,
            $attention,
            $onHoldFlag ? 1 : 0,
            mb_substr($buyerEmail, 0, 255),
            mb_substr($shippingName, 0, 255),
            json_encode($shipAddr, JSON_UNESCAPED_UNICODE),
            json_encode($billAddr, JSON_UNESCAPED_UNICODE),
            $shippingCost,
            $orderTotal,
            $id,
            $this->app->tenant->id(),
        ];

        $this->app->db->query(
            'UPDATE #__store_orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?',
            $bind
        );

        if (in_array($status, $warehouseStatuses, true)) {
            $inventory->releaseOrder($id);
            (new \App\Store\DropshipService($this->app))->ensureForOrder($id);
            $inventory->allocateOrder($id);
        }

        $this->app->session->flash('success', 'Order updated.');
        $this->redirect('admin/orders/view', ['id' => $id]);
    }

    /** @return array<string,string> */
    private function addressFromPost(string $prefix): array
    {
        $get = function (string $key) use ($prefix): string {
            return trim((string) $this->app->request->post($prefix . '_' . $key, ''));
        };
        $country = strtoupper($get('country'));
        if ($country === 'UNITED KINGDOM') {
            $country = 'GB';
        }
        return [
            'name'     => mb_substr($get('name'), 0, 255),
            'phone'    => $this->scalarPhone($get('phone')),
            'line1'    => mb_substr($get('line1'), 0, 255),
            'line2'    => mb_substr($get('line2'), 0, 255),
            'city'     => mb_substr($get('city'), 0, 128),
            'county'   => mb_substr($get('county'), 0, 128),
            'postcode' => mb_substr($get('postcode'), 0, 32),
            'country'  => mb_substr($country !== '' ? $country : 'GB', 0, 64),
        ];
    }

    private function replaceOrderItems(int $orderId, int $tenantId): float
    {
        $skus = $this->app->request->post('item_sku', []);
        $names = $this->app->request->post('item_name', []);
        $qtys = $this->app->request->post('item_qty', []);
        $units = $this->app->request->post('item_unit_price', []);
        $productIds = $this->app->request->post('item_product_id', []);
        if (!is_array($skus)) {
            return 0.0;
        }

        $this->app->db->query('DELETE FROM #__store_order_items WHERE order_id = ?', [$orderId]);

        $total = 0.0;
        $count = count($skus);
        for ($i = 0; $i < $count; $i++) {
            $sku = trim((string) ($skus[$i] ?? ''));
            $name = trim((string) ($names[$i] ?? ''));
            if ($sku === '' && $name === '') {
                continue;
            }
            $qty = max(1, (int) ($qtys[$i] ?? 1));
            $unit = max(0, (float) ($units[$i] ?? 0));
            $line = round($qty * $unit, 2);
            $productId = (int) ($productIds[$i] ?? 0);
            if ($productId <= 0 && $sku !== '') {
                $row = $this->app->db->query(
                    'SELECT id FROM #__store_products WHERE tenant_id = ? AND sku = ? LIMIT 1',
                    [$tenantId, $sku]
                )->row;
                $productId = (int) ($row['id'] ?? 0);
            }
            $this->app->db->query(
                'INSERT INTO #__store_order_items (order_id, product_id, sku, name, qty, unit_price, line_total)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $orderId,
                    $productId > 0 ? $productId : null,
                    mb_substr($sku, 0, 64),
                    mb_substr($name !== '' ? $name : $sku, 0, 255),
                    $qty,
                    $unit,
                    $line,
                ]
            );
            $total += $line;
        }

        return round($total, 2);
    }
}
