<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Store\DespatchService;

final class OrdersApiController extends ApiController
{
    public function index(): void
    {
        if (!$this->requireScope('orders:read')) {
            return;
        }
        $tenantId = $this->tenantId();
        $params = [$tenantId];
        $where = 'o.tenant_id = ?';
        $status = trim((string) $this->app->request->get('status', ''));
        $view = (string) $this->app->request->get('view', 'all');
        $q = trim((string) $this->app->request->get('q', ''));

        if ($status !== '') {
            $where .= ' AND o.status = ?';
            $params[] = $status;
        } elseif ($view === 'picking' || $view === 'ready') {
            $where .= " AND o.status IN ('awaiting_despatch','printed','processing')
                        AND NOT EXISTS (
                          SELECT 1 FROM #__store_pickwave_orders po
                          WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
                        )";
        } elseif ($view === 'despatch') {
            $where .= " AND o.status IN ('processing','printed')";
        } elseif ($view === 'despatched') {
            $where .= " AND o.status = 'despatched'";
        }

        if ($q !== '') {
            $where .= ' AND (o.external_order_id LIKE ? OR o.buyer_email LIKE ? OR o.shipping_name LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        $total = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_orders o WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->app->db->query(
            "SELECT o.id, o.external_order_id, o.status, o.buyer_email, o.shipping_name, o.total, o.currency,
                    o.shipping_method, o.carrier_code, o.tracking_number, o.channel_id, o.created_at,
                    c.name AS channel_name, c.code AS channel_code
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE {$where}
             ORDER BY o.id DESC
             LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;

        $this->ok($rows, $this->paginationMeta($total));
    }

    public function show(): void
    {
        if (!$this->requireScope('orders:read')) {
            return;
        }
        $id = (int) $this->app->request->get('id', 0);
        $order = $this->app->db->query(
            'SELECT o.*, c.name AS channel_name, c.code AS channel_code
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE o.id = ? AND o.tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($order === []) {
            $this->fail('Order not found', 404);
            return;
        }
        $items = $this->app->db->query(
            'SELECT id, sku, name, qty, unit_price, line_total, product_id FROM #__store_order_items WHERE order_id = ? ORDER BY id',
            [$id]
        )->rows;
        $this->ok(['order' => $order, 'items' => $items]);
    }

    public function store(): void
    {
        if (!$this->requireScope('orders:write')) {
            return;
        }
        $body = $this->app->request->allPost();
        $channelId = (int) ($body['channel_id'] ?? 0);
        $email = trim((string) ($body['buyer_email'] ?? ''));
        $name = trim((string) ($body['shipping_name'] ?? ''));
        $total = (float) ($body['total'] ?? 0);
        if ($channelId <= 0 || $email === '' || $name === '') {
            $this->fail('channel_id, buyer_email and shipping_name are required', 422);
            return;
        }

        $ref = trim((string) ($body['external_order_id'] ?? ''));
        if ($ref === '') {
            $ref = 'API-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        }
        $addr = json_encode([
            'line1'    => (string) ($body['line1'] ?? ''),
            'city'     => (string) ($body['city'] ?? ''),
            'postcode' => (string) ($body['postcode'] ?? ''),
            'country'  => (string) ($body['country'] ?? 'GB'),
        ], JSON_UNESCAPED_UNICODE);

        $ins = $this->app->db->query(
            'INSERT INTO #__store_orders
             (tenant_id, channel_id, external_order_id, status, buyer_email, shipping_name, total,
              shipping_cost, currency, shipping_address, shipping_method, warehouse, is_paid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $this->tenantId(),
                $channelId,
                $ref,
                (string) ($body['status'] ?? 'awaiting_despatch'),
                $email,
                $name,
                $total,
                (float) ($body['shipping_cost'] ?? 0),
                (string) ($body['currency'] ?? 'GBP'),
                $addr,
                (string) ($body['shipping_method'] ?? 'Collection'),
                (string) ($body['warehouse'] ?? 'Default'),
            ]
        );
        $orderId = (int) ($ins->insert_id ?: 0);

        $items = $body['items'] ?? [];
        if (is_array($items)) {
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $qty = max(1, (int) ($item['qty'] ?? 1));
                $unit = (float) ($item['unit_price'] ?? 0);
                $this->app->db->query(
                    'INSERT INTO #__store_order_items (order_id, product_id, sku, name, qty, unit_price, line_total)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $orderId,
                        (int) ($item['product_id'] ?? 0) ?: null,
                        (string) ($item['sku'] ?? ''),
                        (string) ($item['name'] ?? ''),
                        $qty,
                        $unit,
                        $qty * $unit,
                    ]
                );
            }
        }

        $status = (string) ($body['status'] ?? 'awaiting_despatch');
        if (in_array($status, ['awaiting_despatch', 'printed', 'processing'], true)) {
            (new \App\Store\DropshipService($this->app))->ensureForOrder($orderId);
            (new \App\Store\InventoryService($this->app))->allocateOrder($orderId);
        }

        $this->ok(['id' => $orderId, 'external_order_id' => $ref], [], 201);
    }

    public function updateStatus(): void
    {
        if (!$this->requireScope('orders:write')) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $status = (string) $this->app->request->input('status', '');
        $allowed = [
            'awaiting_payment', 'awaiting_despatch', 'processing', 'printed',
            'despatched', 'on_hold', 'cancelled', 'refunded',
        ];
        if ($id <= 0 || !in_array($status, $allowed, true)) {
            $this->fail('Valid id and status required', 422);
            return;
        }

        $sets = ['status = ?'];
        $bind = [$status];
        $tracking = trim((string) $this->app->request->input('tracking_number', ''));
        $carrier = trim((string) $this->app->request->input('carrier_code', ''));
        if ($status === 'despatched') {
            $sets[] = 'shipped_at = COALESCE(shipped_at, NOW())';
        }
        if ($tracking !== '') {
            $sets[] = 'tracking_number = ?';
            $bind[] = $tracking;
        }
        if ($carrier !== '') {
            $sets[] = 'carrier_code = ?';
            $bind[] = $carrier;
        }
        $sets[] = $status === 'on_hold' ? 'on_hold = 1' : 'on_hold = 0';
        $bind[] = $id;
        $bind[] = $this->tenantId();

        $this->app->db->query(
            'UPDATE #__store_orders SET ' . implode(', ', $sets) . ' WHERE id = ? AND tenant_id = ?',
            $bind
        );

        if (in_array($status, ['awaiting_despatch', 'printed', 'processing'], true)) {
            (new \App\Store\DropshipService($this->app))->ensureForOrder($id);
            (new \App\Store\InventoryService($this->app))->allocateOrder($id);
        }

        $this->ok(['id' => $id, 'status' => $status]);
    }

    public function despatch(): void
    {
        if (!$this->requireScope('orders:write')) {
            return;
        }
        $ids = $this->app->request->input('order_ids', []);
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            $this->fail('order_ids required', 422);
            return;
        }

        $prefix = trim((string) $this->app->request->input('tracking_prefix', 'RM'));
        $defaultCarrier = trim((string) $this->app->request->input('carrier_code', 'royal_mail'));
        $applyRules = filter_var(
            $this->app->request->input('apply_shipping_rules', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($applyRules === null) {
            $applyRules = true;
        }

        $buyLabel = filter_var(
            $this->app->request->input('buy_carrier_label', true),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($buyLabel === null) {
            $buyLabel = true;
        }

        $result = (new DespatchService($this->app))->despatchOrders(
            $ids,
            $prefix !== '' ? $prefix : 'RM',
            $defaultCarrier !== '' ? $defaultCarrier : 'royal_mail',
            $applyRules,
            $buyLabel
        );

        $tenantId = $this->tenantId();
        $out = [];
        foreach ($ids as $id) {
            $order = $this->app->db->query(
                'SELECT id, tracking_number, carrier_code, mapped_shipping_method, status
                 FROM #__store_orders WHERE id = ? AND tenant_id = ? LIMIT 1',
                [$id, $tenantId]
            )->row;
            if ($order === [] || (string) ($order['status'] ?? '') !== 'despatched') {
                continue;
            }
            $out[] = [
                'id'                     => (int) $order['id'],
                'tracking_number'        => (string) ($order['tracking_number'] ?? ''),
                'carrier_code'           => (string) ($order['carrier_code'] ?? ''),
                'mapped_shipping_method' => (string) ($order['mapped_shipping_method'] ?? ''),
            ];
        }

        $this->ok([
            'despatched'            => $result['despatched'],
            'mapped'                => $result['mapped'],
            'shipment_ids'          => $result['shipment_ids'],
            'orders'                => $out,
            'label_warnings'        => $result['label_warnings'] ?? [],
            'fulfillment_warnings'  => $result['fulfillment_warnings'] ?? [],
            'stock_warnings'        => $result['stock_warnings'] ?? [],
        ]);
    }
}
