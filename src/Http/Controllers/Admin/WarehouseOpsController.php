<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\DespatchService;
use App\Store\DocumentService;
use App\Store\DropshipService;
use App\Store\InventoryService;
use App\Store\ManifestService;
use App\Store\MarketplaceFulfillmentService;
use App\Store\PickwaveService;
use App\Store\CourierOps;

final class WarehouseOpsController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->app->tenant->id();
        $count = static function ($app, string $sql, array $p): int {
            return (int) ($app->db->query($sql, $p)->row['c'] ?? 0);
        };

        echo $this->view('admin/warehouse/index', [
            'title'      => 'Warehouse',
            'navSection' => 'warehouse',
            'ready'      => $count($this->app, "SELECT COUNT(*) AS c FROM #__store_orders o
                WHERE o.tenant_id=? AND o.status IN ('awaiting_despatch','printed','processing')
                  AND NOT EXISTS (
                    SELECT 1 FROM #__store_pickwave_orders po
                    WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
                  )", [$tenantId]),
            'openPickwaves' => (new PickwaveService($this->app))->openCount(),
            'despatched' => $count($this->app, "SELECT COUNT(*) AS c FROM #__store_orders WHERE tenant_id=? AND status='despatched'", [$tenantId]),
            'tracked'    => $count($this->app, "SELECT COUNT(*) AS c FROM #__store_orders WHERE tenant_id=? AND status='despatched' AND tracking_number<>''", [$tenantId]),
            'returns'    => $count($this->app, "SELECT COUNT(*) AS c FROM #__store_returns WHERE tenant_id=? AND status='pending'", [$tenantId]),
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function picking(): void
    {
        $tab = strtolower(trim((string) $this->app->request->get('tab', 'open')));
        // open = open waves; recent = last 10 completed (full list lives on /pickwaves)
        if (!in_array($tab, ['open', 'recent'], true)) {
            $tab = 'open';
        }
        $svc = new PickwaveService($this->app);
        $tenant = $this->app->tenant->tenant() ?? [];
        echo $this->view('admin/warehouse/picking', [
            'title'      => 'Picking',
            'navSection' => 'warehouse',
            'tab'        => $tab,
            'stats'      => $svc->pickingStats(),
            'pickwaves'  => $svc->listForPicking($tab),
            'company'    => (string) ($tenant['name'] ?? ''),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function despatch(): void
    {
        echo $this->view('admin/warehouse/despatch', [
            'title'      => 'Despatch order / pickwave',
            'navSection' => 'warehouse',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function deliveries(): void
    {
        $fulfillment = strtolower(trim((string) $this->app->request->get('fulfillment', 'all')));
        $allowed = ['all', 'needs_retry', 'failed', 'skipped', 'unsupported', 'uploaded', 'not_uploaded'];
        if (!in_array($fulfillment, $allowed, true)) {
            $fulfillment = 'all';
        }
        echo $this->view('admin/warehouse/deliveries', [
            'title'        => 'Deliveries',
            'navSection'   => 'warehouse',
            'fulfillment'  => $fulfillment,
            'flash'        => $this->app->session->flash('success'),
            'error'        => $this->app->session->flash('error'),
            'user'         => $this->app->auth->user(),
        ]);
    }

    public function retryFulfillment(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/deliveries');
        }

        $svc = new MarketplaceFulfillmentService($this->app);
        $isBulk = $this->app->request->post('bulk', '') !== '';

        if ($isBulk) {
            $orderIds = $this->selectedIds();
            if ($orderIds === []) {
                $this->app->session->flash('error', 'Select at least one delivery.');
                $this->redirect('admin/warehouse/deliveries');
            }
            $targets = [];
            foreach ($orderIds as $oid) {
                $ship = $this->app->db->query(
                    'SELECT id FROM #__store_shipments
                     WHERE tenant_id=? AND order_id=?
                     ORDER BY id DESC LIMIT 1',
                    [$this->app->tenant->id(), $oid]
                )->row;
                $targets[] = [
                    'order_id'    => $oid,
                    'shipment_id' => (int) ($ship['id'] ?? 0),
                ];
            }
            $result = $svc->retryMany($targets);
            $msg = "Retry done: {$result['uploaded']} uploaded, {$result['skipped']} skipped, {$result['failed']} failed.";
            if ($result['messages'] !== []) {
                $msg .= ' ' . implode(' ', array_slice($result['messages'], 0, 5));
            }
            $this->app->session->flash($result['failed'] > 0 && $result['uploaded'] === 0 ? 'error' : 'success', $msg);
            $this->redirect('admin/warehouse/deliveries', ['fulfillment' => 'needs_retry']);
        }

        $orderId = (int) $this->app->request->post('order_id', 0);
        $shipmentId = (int) $this->app->request->post('shipment_id', 0);
        $result = $svc->uploadForOrder($orderId, $shipmentId);
        $status = (string) ($result['status'] ?? '');
        if ($status === 'uploaded') {
            $this->app->session->flash('success', 'Channel fulfillment uploaded for order #' . $orderId . '.');
        } elseif ($status === 'skipped' || $status === 'unsupported') {
            $this->app->session->flash('success', 'Skipped: ' . (string) ($result['message'] ?? $status));
        } else {
            $this->app->session->flash('error', 'Upload failed: ' . (string) ($result['message'] ?? $status));
        }
        $this->redirect('admin/warehouse/deliveries');
    }

    public function voidShipment(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/deliveries');
        }
        $result = (new CourierOps($this->app))->voidShipment((int) $this->app->request->post('shipment_id', 0));
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/warehouse/deliveries');
    }

    public function manifests(): void
    {
        $svc = new ManifestService($this->app);
        echo $this->view('admin/warehouse/manifests', [
            'title'      => 'Manifests',
            'navSection' => 'warehouse',
            'manifests'  => $svc->list($this->app->tenant->id()),
            'pending'    => $svc->pendingByCarrier(),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function createManifest(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/manifests');
        }
        $result = (new ManifestService($this->app))->createAndAttach(
            (string) $this->app->request->post('carrier_code', ''),
            trim((string) $this->app->request->post('notes', ''))
        );
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/warehouse/manifests');
    }

    public function closeManifest(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/manifests');
        }
        $result = (new ManifestService($this->app))->close((int) $this->app->request->post('id', 0));
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/warehouse/manifests');
    }

    public function courierExport(): void
    {
        $pending = (new ManifestService($this->app))->pendingByCarrier();
        $carriers = $this->app->db->query(
            "SELECT carrier_code, COUNT(*) AS c FROM #__store_shipments
             WHERE tenant_id=? AND tracking_number<>'' GROUP BY carrier_code ORDER BY carrier_code",
            [$this->app->tenant->id()]
        )->rows;
        if ($carriers === []) {
            $carriers = $pending;
        }
        echo $this->view('admin/warehouse/courier_export', [
            'title'      => 'Courier file export',
            'navSection' => 'warehouse',
            'carriers'   => $carriers,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function courierExportDownload(): void
    {
        $docs = new DocumentService($this->app);
        $carrier = trim((string) $this->app->request->get('carrier', ''));
        $from = trim((string) $this->app->request->get('from', ''));
        $to = trim((string) $this->app->request->get('to', ''));
        $rows = $docs->courierExportRows(
            $carrier !== '' ? $carrier : null,
            $from !== '' ? $from : null,
            $to !== '' ? $to : null
        );

        $filename = 'courier-export-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        if ($out === false) {
            echo "Unable to write CSV.\n";
            exit;
        }
        $headers = [
            'order_id', 'external_order_id', 'tracking_number', 'carrier_code', 'service_code',
            'name', 'email', 'line1', 'city', 'postcode', 'country',
            'weight_kg', 'package_count', 'total', 'currency', 'despatched_at',
        ];
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $row[$h] ?? '';
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    public function stockLocations(): void
    {
        echo $this->view('admin/warehouse/stock_locations', [
            'title'      => 'Stock locations',
            'navSection' => 'warehouse',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storeLocation(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/stock-locations');
        }
        $code = trim((string) $this->app->request->post('code', ''));
        $name = trim((string) $this->app->request->post('name', ''));
        if ($code === '') {
            $this->app->session->flash('error', 'Enter a location code (bin).');
            $this->redirect('admin/warehouse/stock-locations');
        }
        $svc = new \App\Store\StockLocationService($this->app);
        $tenantId = $this->app->tenant->id();
        if ($svc->findIdByCode($tenantId, $code) > 0) {
            $this->app->session->flash('error', 'Location code already exists.');
            $this->redirect('admin/warehouse/stock-locations');
        }
        $svc->ensureLocation($tenantId, $code, $name !== '' ? $name : $code);
        $this->app->session->flash('success', 'Location created.');
        $this->redirect('admin/warehouse/stock-locations');
    }

    public function returns(): void
    {
        echo $this->view('admin/warehouse/returns', [
            'title'      => 'Return order items',
            'navSection' => 'warehouse',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function returnsNew(): void
    {
        $tenantId = $this->app->tenant->id();
        $orderId = (int) $this->app->request->get('order_id', 0);
        $orderRef = trim((string) $this->app->request->get('order_ref', ''));
        $order = null;
        $items = [];

        if ($orderId <= 0 && $orderRef !== '') {
            $found = $this->app->db->query(
                'SELECT id FROM #__store_orders WHERE tenant_id=? AND (external_order_id=? OR id=?) LIMIT 1',
                [$tenantId, $orderRef, (int) $orderRef]
            )->row;
            if ($found !== []) {
                $orderId = (int) $found['id'];
            }
        }

        if ($orderId > 0) {
            $order = $this->app->db->query(
                'SELECT id, external_order_id, status, buyer_email, shipping_name
                 FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
                [$orderId, $tenantId]
            )->row;
            if ($order === []) {
                $order = null;
            } else {
                $items = $this->app->db->query(
                    'SELECT id, product_id, sku, name, qty, unit_price
                     FROM #__store_order_items WHERE order_id=? ORDER BY id',
                    [$orderId]
                )->rows;
                // Cap returnable qty by already-returned (non-rejected) amounts per SKU
                $returned = $this->app->db->query(
                    "SELECT sku, COALESCE(SUM(qty),0) AS q
                     FROM #__store_returns
                     WHERE tenant_id=? AND order_id=? AND status<>'rejected'
                     GROUP BY sku",
                    [$tenantId, $orderId]
                )->rows;
                $returnedBySku = [];
                foreach ($returned as $r) {
                    $returnedBySku[(string) $r['sku']] = (int) $r['q'];
                }
                foreach ($items as &$it) {
                    $sku = (string) ($it['sku'] ?? '');
                    $already = $returnedBySku[$sku] ?? 0;
                    $it['qty_returnable'] = max(0, (int) ($it['qty'] ?? 0) - $already);
                }
                unset($it);
            }
        }

        echo $this->view('admin/warehouse/returns_new', [
            'title'      => 'New return',
            'navSection' => 'warehouse',
            'order'      => $order,
            'items'      => $items,
            'orderId'    => $orderId,
            'orderRef'   => $orderRef !== '' ? $orderRef : (string) ($order['external_order_id'] ?? ''),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function createReturns(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/returns');
        }

        $tenantId = $this->app->tenant->id();
        $orderId = (int) $this->app->request->post('order_id', 0);
        $reason = trim((string) $this->app->request->post('reason', ''));
        $lineIds = $this->app->request->post('line_id', []);
        $lineQtys = $this->app->request->post('line_qty', []);
        if (!is_array($lineIds)) {
            $lineIds = [];
        }
        if (!is_array($lineQtys)) {
            $lineQtys = [];
        }

        $order = $this->app->db->query(
            'SELECT id, external_order_id FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            $this->app->session->flash('error', 'Order not found.');
            $this->redirect('admin/warehouse/returns/new');
        }

        $created = 0;
        foreach ($lineIds as $i => $lineIdRaw) {
            $lineId = (int) $lineIdRaw;
            if ($lineId <= 0) {
                continue;
            }
            $qty = max(0, (int) ($lineQtys[$i] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $line = $this->app->db->query(
                'SELECT id, product_id, sku, name, qty FROM #__store_order_items WHERE id=? AND order_id=? LIMIT 1',
                [$lineId, $orderId]
            )->row;
            if ($line === []) {
                continue;
            }

            $sku = (string) ($line['sku'] ?? '');
            $ordered = (int) ($line['qty'] ?? 0);
            $already = (int) ($this->app->db->query(
                "SELECT COALESCE(SUM(qty),0) AS q FROM #__store_returns
                 WHERE tenant_id=? AND order_id=? AND sku=? AND status<>'rejected'",
                [$tenantId, $orderId, $sku]
            )->row['q'] ?? 0);
            $maxQty = max(0, $ordered - $already);
            if ($maxQty <= 0) {
                continue;
            }
            $qty = min($qty, $maxQty);

            $this->app->db->query(
                'INSERT INTO #__store_returns
                 (tenant_id, order_id, external_order_id, sku, qty, reason, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $tenantId,
                    $orderId,
                    (string) ($order['external_order_id'] ?? ''),
                    $sku,
                    $qty,
                    $reason !== '' ? $reason : 'Customer return',
                    'pending',
                ]
            );
            $created++;
        }

        if ($created === 0) {
            $this->app->session->flash('error', 'Select at least one line with a returnable quantity.');
            $this->redirect('admin/warehouse/returns/new', ['order_id' => $orderId]);
        }

        $this->app->session->flash('success', "Created {$created} return line(s). Mark as restocked when stock is put back.");
        $this->redirect('admin/warehouse/returns');
    }

    public function updateReturn(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/returns');
        }
        $tenantId = $this->app->tenant->id();
        $id = (int) $this->app->request->post('id', 0);
        $status = (string) $this->app->request->post('status', 'received');
        if (!in_array($status, ['pending', 'received', 'restocked', 'rejected'], true)) {
            $this->redirect('admin/warehouse/returns');
        }

        $row = $this->app->db->query(
            'SELECT * FROM #__store_returns WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $tenantId]
        )->row;
        if ($row === []) {
            $this->app->session->flash('error', 'Return not found.');
            $this->redirect('admin/warehouse/returns');
        }

        $prev = (string) ($row['status'] ?? '');
        $this->app->db->query(
            'UPDATE #__store_returns SET status = ? WHERE id = ? AND tenant_id = ?',
            [$status, $id, $tenantId]
        );

        $msg = 'Return updated.';
        if ($prev !== 'restocked' && $status === 'restocked') {
            $inventory = new InventoryService($this->app);
            $ok = $this->restockReturnRow($inventory, $row);
            if ($ok) {
                $msg = 'Return marked restocked; stock put back on hand.';
            } else {
                $warn = $inventory->warnings();
                $msg = 'Return marked restocked, but stock was not updated'
                    . ($warn !== [] ? ': ' . implode(' ', $warn) : ' (unknown SKU).');
                $this->app->session->flash('error', $msg);
                $this->redirect('admin/warehouse/returns');
            }
        }

        $this->app->session->flash('success', $msg);
        $this->redirect('admin/warehouse/returns');
    }

    /**
     * @param array<string,mixed> $row
     */
    private function restockReturnRow(InventoryService $inventory, array $row): bool
    {
        $qty = (int) ($row['qty'] ?? 0);
        $sku = trim((string) ($row['sku'] ?? ''));
        $orderId = (int) ($row['order_id'] ?? 0);
        if ($qty <= 0 || $sku === '') {
            return false;
        }

        $productId = 0;
        if ($orderId > 0) {
            $line = $this->app->db->query(
                'SELECT product_id FROM #__store_order_items
                 WHERE order_id=? AND sku=? AND product_id IS NOT NULL AND product_id > 0
                 ORDER BY id ASC LIMIT 1',
                [$orderId, $sku]
            )->row;
            if ($line !== []) {
                $productId = (int) $line['product_id'];
            }
        }

        if ($productId > 0) {
            return $inventory->restockProduct($productId, $qty);
        }

        return $inventory->restockBySku($sku, $qty);
    }

    public function markPrinted(): void
    {
        $this->bulkStatus('printed', 'admin/warehouse/picking', 'Orders marked printed.');
    }

    public function markDespatched(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/despatch');
        }
        $ids = $this->selectedIds();
        $trackingPrefix = trim((string) $this->app->request->post('tracking_prefix', 'RM'));
        $defaultCarrier = trim((string) $this->app->request->post('carrier_code', 'royal_mail'));
        $applyRules = $this->app->request->post('apply_shipping_rules', '') !== '';
        $buyRmLabel = $this->app->request->post('buy_rm_label', '') !== '';
        $printLabels = $this->app->request->post('print_labels', '') !== '';
        if ($ids === []) {
            $this->app->session->flash('error', 'Select at least one order.');
            $this->redirect('admin/warehouse/despatch');
        }

        $result = (new DespatchService($this->app))->despatchOrders(
            $ids,
            $trackingPrefix,
            $defaultCarrier,
            $applyRules,
            $buyRmLabel
        );

        $msg = "Despatched {$result['despatched']} order(s).";
        if ($applyRules) {
            $msg .= " Shipping rules mapped {$result['mapped']}.";
        }
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

        if ($printLabels && $result['despatched'] > 0) {
            $this->redirect('admin/documents/labels', ['ids' => implode(',', $ids), 'print' => '1']);
        }
        $this->redirect('admin/warehouse/despatch');
    }

    public function pickwaves(): void
    {
        $status = strtolower(trim((string) $this->app->request->get('status', 'completed')));
        if (!in_array($status, ['all', 'open', 'completed'], true)) {
            $status = 'completed';
        }
        $limit = $status === 'completed' || $status === 'all' ? 2000 : 200;
        $svc = new PickwaveService($this->app);
        echo $this->view('admin/warehouse/pickwaves', [
            'title'      => 'Pickwaves',
            'navSection' => 'warehouse',
            'status'     => $status,
            'pickwaves'  => $svc->list($limit, $status),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function pickwaveView(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $svc = new PickwaveService($this->app);
        $wave = $svc->find($id);
        if ($wave === null) {
            $this->app->session->flash('error', 'Pickwave not found.');
            $this->redirect('admin/warehouse/pickwaves');
        }
        echo $this->view('admin/warehouse/pickwave_view', [
            'title'      => 'Pickwave ' . (string) $wave['reference'],
            'navSection' => 'warehouse',
            'pickwave'   => $wave,
            'orders'     => $svc->orders($id),
            'scanSummary'=> $svc->scanSummary($id),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function pickwavePickList(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $svc = new PickwaveService($this->app);
        $wave = $svc->find($id);
        if ($wave === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'warehouse',
                'message'    => 'Pickwave not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        $orders = $svc->orders($id);
        $orderCount = 0;
        foreach ($orders as $o) {
            if (!in_array((string) ($o['status'] ?? ''), ['cancelled', 'refunded', 'despatched'], true)) {
                $orderCount++;
            }
        }

        echo $this->view('admin/warehouse/pickwave_picklist', [
            'title'      => 'Pick list ' . (string) $wave['reference'],
            'pickwave'   => $wave,
            'lines'      => $svc->pickLines($id),
            'orderCount' => $orderCount,
            'autoPrint'  => $this->app->request->get('print', '') !== '',
        ], 'layouts/print');
    }

    public function pickwaveScan(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $svc = new PickwaveService($this->app);
        $wave = $svc->find($id);
        if ($wave === null) {
            $this->app->session->flash('error', 'Pickwave not found.');
            $this->redirect('admin/warehouse/pickwaves');
        }
        echo $this->view('admin/warehouse/pickwave_scan', [
            'title'      => 'Scan ' . (string) $wave['reference'],
            'navSection' => 'warehouse',
            'pickwave'   => $wave,
            'lines'      => $svc->pickProgress($id),
            'summary'    => $svc->scanSummary($id),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function pickwaveScanConfirm(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/pickwaves');
        }
        $id = (int) $this->app->request->post('id', 0);
        $code = trim((string) $this->app->request->post('code', ''));
        try {
            $result = (new PickwaveService($this->app))->scanConfirm($id, $code);
            if (!empty($result['ok'])) {
                $this->app->session->flash('success', (string) ($result['message'] ?? 'Confirmed.'));
            } else {
                $this->app->session->flash('error', (string) ($result['message'] ?? 'Scan failed.'));
            }
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
        }
        $this->redirect('admin/warehouse/pickwaves/scan', ['id' => $id]);
    }

    public function pickwavePackingList(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $svc = new PickwaveService($this->app);
        $wave = $svc->find($id);
        if ($wave === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'warehouse',
                'message'    => 'Pickwave not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        $docs = new DocumentService($this->app);
        $bundles = [];
        foreach ($svc->orders($id) as $o) {
            $status = (string) ($o['status'] ?? '');
            if (in_array($status, ['cancelled', 'refunded', 'despatched'], true)) {
                continue;
            }
            $bundle = $docs->orderBundle((int) $o['id']);
            if ($bundle !== null) {
                $bundles[] = $bundle;
            }
        }

        if ($bundles === []) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'warehouse',
                'message'    => 'No open orders on this pickwave to pack.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        echo $this->view('admin/documents/packing_batch', [
            'title'         => 'Packing list ' . (string) $wave['reference'],
            'bundles'       => $bundles,
            'waveReference' => (string) $wave['reference'],
            'autoPrint'     => $this->app->request->get('print', '') !== '',
        ], 'layouts/print');
    }

    public function pickwaveClose(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/pickwaves');
        }
        $id = (int) $this->app->request->post('id', 0);
        $svc = new PickwaveService($this->app);
        if ($svc->close($id)) {
            $this->app->session->flash('success', 'Pickwave closed.');
        } else {
            $this->app->session->flash('error', 'Could not close pickwave.');
        }
        $this->redirect('admin/warehouse/pickwaves/view', ['id' => $id]);
    }

    public function pickwaveRemoveOrder(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/pickwaves');
        }
        $pickwaveId = (int) $this->app->request->post('pickwave_id', 0);
        $orderId = (int) $this->app->request->post('order_id', 0);
        try {
            (new PickwaveService($this->app))->removeOrder($pickwaveId, $orderId);
            $this->app->session->flash('success', 'Order removed from pickwave and returned to Picking.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
        }
        $this->redirect('admin/warehouse/pickwaves/view', ['id' => $pickwaveId]);
    }

    public function pickwaveRename(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/pickwaves');
        }
        $id = (int) $this->app->request->post('id', 0);
        try {
            (new PickwaveService($this->app))->rename(
                $id,
                trim((string) $this->app->request->post('pickwave_name', ''))
            );
            $this->app->session->flash('success', 'Pickwave renamed.');
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
        }
        $this->redirect('admin/warehouse/pickwaves/view', ['id' => $id]);
    }

    public function pickwaveMarkPrinted(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/pickwaves');
        }
        $id = (int) $this->app->request->post('id', 0);
        try {
            $result = (new PickwaveService($this->app))->markPrinted($id, $this->optionalOrderIds());
            $this->app->session->flash('success', "Marked {$result['updated']} order(s) printed on {$result['reference']}.");
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
        }
        $this->redirect('admin/warehouse/pickwaves/view', ['id' => $id]);
    }

    public function pickwaveDespatch(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/pickwaves');
        }
        $id = (int) $this->app->request->post('id', 0);
        $trackingPrefix = trim((string) $this->app->request->post('tracking_prefix', 'RM'));
        $defaultCarrier = trim((string) $this->app->request->post('carrier_code', 'royal_mail'));
        $applyRules = $this->app->request->post('apply_shipping_rules', '') !== '';
        $buyRmLabel = $this->app->request->post('buy_rm_label', '') !== '';
        $printLabels = $this->app->request->post('print_labels', '') !== '';
        try {
            $result = (new PickwaveService($this->app))->despatch(
                $id,
                $trackingPrefix,
                $defaultCarrier,
                $applyRules,
                $this->optionalOrderIds(),
                $buyRmLabel
            );
            $msg = "Despatched {$result['despatched']} order(s) from {$result['reference']}.";
            if ($applyRules) {
                $msg .= " Shipping rules mapped {$result['mapped']}.";
            }
            if ($result['closed']) {
                $msg .= ' Pickwave closed.';
            }
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
            if ($printLabels && $result['despatched'] > 0 && $result['order_ids'] !== []) {
                $this->redirect('admin/documents/labels', [
                    'ids'   => implode(',', $result['order_ids']),
                    'print' => '1',
                ]);
            }
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
        }
        $this->redirect('admin/warehouse/pickwaves/view', ['id' => $id]);
    }

    public function pickwave(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/warehouse/picking');
        }
        $ids = $this->selectedIds();
        try {
            $user = $this->app->auth->user();
            $created = (new PickwaveService($this->app))->create(
                $ids,
                $user ? (int) ($user['id'] ?? 0) ?: null : null,
                trim((string) $this->app->request->post('pickwave_name', ''))
            );
            $msg = 'Pickwave ' . $created['reference'] . ' created with ' . $created['order_count'] . ' order(s).';
            if (!empty($created['skipped'])) {
                $msg .= ' Skipped ' . $created['skipped'] . ' already on a pickwave.';
            }
            $this->app->session->flash('success', $msg);
            $this->redirect('admin/warehouse/pickwaves/view', ['id' => $created['id']]);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
            $this->redirect('admin/warehouse/picking');
        }
    }

    private function bulkStatus(string $status, string $redirect, string $message): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect($redirect);
        }
        $ids = $this->selectedIds();
        if ($ids === []) {
            $this->app->session->flash('error', 'Select at least one order.');
            $this->redirect($redirect);
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$status], $ids, [$this->app->tenant->id()]);
        $this->app->db->query(
            "UPDATE #__store_orders SET status=? WHERE id IN ({$placeholders}) AND tenant_id=?",
            $params
        );
        if ($status === 'printed') {
            $inventory = new InventoryService($this->app);
            $dropships = new DropshipService($this->app);
            foreach ($ids as $oid) {
                $dropships->ensureForOrder($oid);
                $inventory->allocateOrder($oid);
            }
        }
        $this->app->session->flash('success', $message);
        $this->redirect($redirect);
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        $raw = $this->app->request->post('order_ids', []);
        if (!is_array($raw)) {
            $single = trim((string) $this->app->request->post('order_ids_text', ''));
            if ($single === '') {
                $raw = [];
            } else {
                $raw = preg_split('/[\s,]+/', $single) ?: [];
            }
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

    /** @return list<int>|null null = all orders on the pickwave */
    private function optionalOrderIds(): ?array
    {
        $ids = $this->selectedIds();
        return $ids === [] ? null : $ids;
    }
}
