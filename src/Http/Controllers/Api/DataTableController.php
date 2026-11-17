<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Store\DataTableRequest;
use App\Store\Datasets\AttributesDataset;
use App\Store\Datasets\CategoriesDataset;
use App\Store\Datasets\ChannelsDataset;
use App\Store\Datasets\CustomersDataset;
use App\Store\Datasets\DropshipsDataset;
use App\Store\Datasets\ListingsDataset;
use App\Store\Datasets\OrdersDataset;
use App\Store\Datasets\ProductsDataset;
use App\Store\Datasets\PurchaseOrdersDataset;
use App\Store\Datasets\ReportJobsDataset;
use App\Store\Datasets\ReturnsDataset;
use App\Store\Datasets\ShipmentsDataset;
use App\Store\Datasets\ShippingMethodsDataset;
use App\Store\Datasets\ShippingRegionsDataset;
use App\Store\Datasets\ShippingRulesDataset;
use App\Store\Datasets\StockLocationsDataset;
use App\Store\Datasets\SuppliersDataset;
use App\Store\Datasets\SupportDataset;
use App\Store\Datasets\TenantsDataset;
use App\Store\Datasets\UsersDataset;
use App\Store\Datasets\WarehousesDataset;

final class DataTableController extends Controller
{
    public function orders(): void
    {
        if (!$this->requireScope('orders:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $withSelect = ($req->filters['select'] ?? '') === '1';
        // Checkbox column shifts DataTables order index by 1 vs SQL columns.
        if ($withSelect && $req->orderColumn > 0) {
            $req->orderColumn--;
        } elseif ($withSelect) {
            $req->orderColumn = 0;
        }
        $result = (new OrdersDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $pickingLayout = ($req->filters['layout'] ?? '') === 'picking';
        $company = '';
        if ($pickingLayout) {
            $tenant = $this->app->tenant->tenant() ?? [];
            $company = (string) ($tenant['name'] ?? '');
        }
        $data = [];
        foreach ($result['rows'] as $o) {
            $data[] = $pickingLayout
                ? $this->pickingOrderRow($o, $withSelect, $company)
                : $this->orderRow($o, $withSelect);
        }
        $this->respond($req, $result, $data);
    }

    public function products(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        try {
            $result = (new ProductsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        } catch (\Throwable $e) {
            $this->json([
                'draw'            => $req->draw,
                'recordsTotal'    => 0,
                'recordsFiltered' => 0,
                'data'            => [],
                'error'           => 'Products query failed: ' . $e->getMessage(),
            ]);
            return;
        }
        $viewBase = $this->app->url('admin/products/view');
        $data = [];
        foreach ($result['rows'] as $p) {
            $data[] = $this->productRow($p, $viewBase, false);
        }
        $this->respond($req, $result, $data);
    }

    public function productVariants(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $parentId = (int) $this->app->request->get('parent_id', 0);
        $rows = (new ProductsDataset($this->app->db))->variantsForParent($this->app->tenant->id(), $parentId);
        $viewBase = $this->app->url('admin/products/view');
        $data = [];
        foreach ($rows as $p) {
            $data[] = $this->productRow($p, $viewBase, true);
        }
        $this->json(['ok' => true, 'parent_id' => $parentId, 'data' => $data]);
    }

    /**
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    private function productRow(array $p, string $viewBase, bool $isVariant): array
    {
        $id = (int) $p['id'];
        $sku = $this->e((string) ($p['sku'] ?? ''));
        $img = trim((string) ($p['image'] ?? ''));
        $thumb = $img !== ''
            ? '<img src="' . $this->e($img) . '" alt="" style="width:36px;height:36px;object-fit:contain;border:1px solid #e5e7eb;border-radius:3px;background:#fff">'
            : '<span class="muted" style="font-size:.75rem">—</span>';

        $variantCount = (int) ($p['variant_count'] ?? 0);
        if ($isVariant) {
            $type = '<span class="sf-variant-mark" title="Variant">↳</span>';
        } elseif ($variantCount > 0) {
            $type = '<button type="button" class="sf-variant-toggle" data-parent-id="' . $id
                . '" aria-expanded="false" title="Show ' . $variantCount . ' variant'
                . ($variantCount === 1 ? '' : 's') . '">+</button>';
        } else {
            $type = '<span class="muted">·</span>';
        }

        $onHand = (int) ($p['qty_on_hand'] ?? 0);
        $allocated = (int) ($p['qty_allocated'] ?? 0);
        $available = array_key_exists('qty_available', $p)
            ? (int) $p['qty_available']
            : max(0, $onHand - $allocated);

        return [
            'type'         => $type,
            'image'        => $thumb,
            'sku'          => '<a href="' . $this->e($viewBase . '?id=' . $id) . '"><strong>' . $sku . '</strong></a>',
            'name'         => $this->e((string) ($p['name'] ?? '')),
            'ean'          => $this->e((string) ($p['barcode'] ?? '')),
            'supplier_sku' => $this->e((string) ($p['supplier_sku'] ?? '')),
            'inventory'    => $this->e((string) $onHand),
            'allocated'    => $this->e((string) $allocated),
            'available'    => $this->e((string) $available),
            'supplier'     => $this->e((string) ($p['supplier_label'] ?? '')),
            'brand'        => $this->e((string) ($p['brand'] ?? '')),
            'price'        => $this->e(number_format((float) ($p['price'] ?? 0), 2)),
            'warehouse'    => $this->e((string) ($p['warehouse'] ?? '')),
            'active'       => !empty($p['active']) ? 'Yes' : 'No',
            'DT_RowId'     => 'product-' . $id,
            'DT_RowClass'  => $isVariant ? 'sf-variant-row' : '',
        ];
    }

    public function customers(): void
    {
        if (!$this->requireScope('customers:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new CustomersDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $viewBase = $this->app->url('admin/customers/view');
        $data = [];
        foreach ($result['rows'] as $c) {
            $id = (int) $c['id'];
            $name = $this->e((string) ($c['name'] ?? ''));
            $data[] = [
                'name'     => '<a href="' . $this->e($viewBase . '?id=' . $id) . '">' . $name . '</a>',
                'email'    => $this->e((string) ($c['email'] ?? '')),
                'phone'    => $this->e((string) ($c['phone'] ?? '')),
                'postcode' => $this->e((string) ($c['postcode'] ?? '')),
                'created'  => $this->e((string) ($c['created_at'] ?? '')),
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function users(): void
    {
        if (!$this->requireScope('users:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $tenantId = $this->app->tenant->id();
        $result = (new UsersDataset($this->app->db))->forDataTable($tenantId, $req);
        $editBase = $this->app->url('admin/settings/users/edit');
        $cloneBase = $this->app->url('admin/settings/users/clone');
        $data = [];
        foreach ($result['rows'] as $u) {
            $id = (int) $u['id'];
            $data[] = [
                'name'   => $this->e((string) ($u['display_name'] ?: $u['email'])),
                'email'  => $this->e((string) $u['email']),
                'login'  => $this->e((string) ($u['last_login_at'] ?? '—')),
                'role'   => $this->e((string) ($u['role_label'] ?? 'Custom')),
                'status' => '<span class="badge">' . $this->e((string) ($u['status'] ?? '')) . '</span>',
                'actions'=> '<a href="' . $this->e($editBase . '?id=' . $id) . '">Edit/View</a> · '
                    . '<a href="' . $this->e($cloneBase . '?id=' . $id) . '">Clone</a>',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function channels(): void
    {
        if (!$this->requireScope('channels:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new ChannelsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $editBase = $this->app->url('admin/settings/channels/edit');
        $data = [];
        foreach ($result['rows'] as $c) {
            $id = (int) $c['id'];
            $data[] = [
                'name'    => '<a href="' . $this->e($editBase . '?id=' . $id) . '"><strong>'
                    . $this->e((string) $c['name']) . '</strong></a>',
                'code'    => $this->e((string) $c['code']),
                'type'    => $this->e((string) $c['type']),
                'active'  => !empty($c['active']) ? 'Yes' : 'No',
                'actions' => '<a href="' . $this->e($editBase . '?id=' . $id) . '">Edit</a>',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function warehouses(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new WarehousesDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $data = [];
        foreach ($result['rows'] as $w) {
            $data[] = [
                'name'    => $this->e((string) $w['name']),
                'code'    => $this->e((string) $w['code']),
                'default' => !empty($w['is_default']) ? 'Yes' : 'No',
                'active'  => !empty($w['active']) ? 'Yes' : 'No',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function tenants(): void
    {
        if (!$this->app->auth->isPlatform()) {
            http_response_code(403);
            $this->json(['error' => 'Forbidden']);
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new TenantsDataset($this->app->db))->forDataTable($req);
        $viewBase = $this->app->url('platform/tenants/view');
        $impBase = $this->app->url('platform/tenants/impersonate');
        $data = [];
        foreach ($result['rows'] as $t) {
            $id = (int) $t['id'];
            $st = (string) ($t['status'] ?? '');
            $badgeClass = match ($st) {
                'active' => 'badge-ok',
                'trial' => 'badge-info',
                'suspended', 'cancelled', 'closed' => 'badge-warn',
                default => '',
            };
            $actions = '<a href="' . $this->e($viewBase . '?id=' . $id) . '">Manage</a>';
            if (!in_array($st, ['closed', 'cancelled'], true)) {
                $actions .= ' · <a href="' . $this->e($impBase . '?id=' . $id) . '">Open as tenant</a>';
            } else {
                $actions .= ' · <span class="muted">Closed</span>';
            }
            $data[] = [
                'company'  => '<strong>' . $this->e((string) $t['name']) . '</strong><div class="muted">' . $this->e((string) $t['slug']) . '</div>',
                'plan'     => $this->e((string) ($t['plan'] ?? '')),
                'status'   => '<span class="badge ' . $badgeClass . '">' . $this->e($st) . '</span>',
                'users'    => $this->e((string) ($t['user_count'] ?? 0)),
                'tickets'  => $this->e((string) ($t['open_tickets'] ?? 0)),
                'contact'  => $this->e((string) ($t['contact_email'] ?? '')),
                'actions'  => $actions,
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function support(): void
    {
        if (!$this->app->auth->isPlatform()) {
            http_response_code(403);
            $this->json(['error' => 'Forbidden']);
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new SupportDataset($this->app->db))->forDataTable($req);
        $viewBase = $this->app->url('platform/support/view');
        $data = [];
        foreach ($result['rows'] as $t) {
            $id = (int) $t['id'];
            $data[] = [
                'id'       => '<a href="' . $this->e($viewBase . '?id=' . $id) . '">#' . $id . '</a>',
                'tenant'   => $this->e((string) $t['tenant_name']),
                'subject'  => $this->e((string) $t['subject']),
                'category' => $this->e((string) ($t['category'] ?? '')),
                'priority' => '<span class="badge">' . $this->e((string) $t['priority']) . '</span>',
                'status'   => $this->e((string) $t['status']),
                'updated'  => $this->e((string) $t['updated_at']),
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function suppliers(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new SuppliersDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $editBase = $this->e($this->app->url('admin/suppliers/edit'));
        $data = [];
        foreach ($result['rows'] as $s) {
            $id = (int) $s['id'];
            $data[] = [
                'name'   => '<a href="' . $editBase . '?id=' . $id . '"><strong>' . $this->e((string) $s['name']) . '</strong></a>',
                'code'   => $this->e((string) $s['code']),
                'email'  => $this->e((string) ($s['email'] ?? '')),
                'phone'  => $this->e((string) ($s['phone'] ?? '')),
                'active' => !empty($s['active']) ? 'Yes' : 'No',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function purchaseOrders(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new PurchaseOrdersDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $viewBase = $this->e($this->app->url('admin/purchase-orders/view'));
        $data = [];
        foreach ($result['rows'] as $p) {
            $id = (int) $p['id'];
            $data[] = [
                'reference' => '<a href="' . $viewBase . '?id=' . $id . '"><strong>' . $this->e((string) $p['reference']) . '</strong></a>',
                'supplier'  => $this->e((string) ($p['supplier_name'] ?? '')),
                'status'    => '<span class="badge">' . $this->e((string) $p['status']) . '</span>',
                'total'     => $this->e(number_format((float) ($p['total'] ?? 0), 2) . ' ' . (string) ($p['currency'] ?? 'GBP')),
                'expected'  => $this->e((string) ($p['expected_at'] ?? '—')),
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function categories(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new CategoriesDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $data = [];
        foreach ($result['rows'] as $c) {
            $data[] = [
                'name'     => $this->e((string) $c['name']),
                'products' => $this->e((string) ($c['product_count'] ?? 0)),
                'active'   => !empty($c['active']) ? 'Yes' : 'No',
                'created'  => $this->e((string) ($c['created_at'] ?? '')),
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function attributes(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new AttributesDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $editBase = $this->e($this->app->url('admin/attributes/edit'));
        $data = [];
        foreach ($result['rows'] as $a) {
            $id = (int) ($a['id'] ?? 0);
            $data[] = [
                'name'     => '<a href="' . $editBase . '?id=' . $id . '"><strong>' . $this->e((string) $a['name']) . '</strong></a>',
                'code'     => $this->e((string) ($a['code'] ?? '')),
                'values'   => $this->e((string) ($a['value_count'] ?? 0)),
                'products' => $this->e((string) ($a['product_count'] ?? 0)),
                'variants' => !empty($a['show_on_variants']) ? 'Yes' : 'No',
                'active'   => !empty($a['active']) ? 'Yes' : 'No',
                'actions'  => '<a class="btn btn-ghost" href="' . $editBase . '?id=' . $id . '">Edit</a>',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function stockLocations(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new StockLocationsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $data = [];
        foreach ($result['rows'] as $l) {
            $data[] = [
                'code'   => '<strong>' . $this->e((string) $l['code']) . '</strong>',
                'name'   => $this->e((string) ($l['name'] ?? '')),
                'qty'    => $this->e((string) ($l['qty'] ?? 0)),
                'active' => !empty($l['active']) ? 'Yes' : 'No',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function returns(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new ReturnsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $orderBase = $this->app->url('admin/orders/view');
        $data = [];
        foreach ($result['rows'] as $r) {
            $oid = (int) ($r['order_id'] ?? 0);
            $data[] = [
                'order'  => $oid > 0
                    ? '<a href="' . $this->e($orderBase . '?id=' . $oid) . '">' . $this->e((string) $r['external_order_id']) . '</a>'
                    : $this->e((string) $r['external_order_id']),
                'sku'    => $this->e((string) $r['sku']),
                'qty'    => $this->e((string) $r['qty']),
                'reason' => $this->e((string) $r['reason']),
                'status' => '<span class="badge">' . $this->e((string) $r['status']) . '</span>',
                'date'   => $this->e((string) $r['created_at']),
                'actions'=> '<form method="post" action="' . $this->e($this->app->url('admin/warehouse/returns/update')) . '" style="display:inline">'
                    . '<input type="hidden" name="_token" value="' . $this->e($this->app->session->csrfToken()) . '">'
                    . '<input type="hidden" name="id" value="' . (int) $r['id'] . '">'
                    . '<select name="status" onchange="this.form.submit()">'
                    . $this->option('pending', (string) $r['status'])
                    . $this->option('received', (string) $r['status'])
                    . $this->option('restocked', (string) $r['status'])
                    . $this->option('rejected', (string) $r['status'])
                    . '</select></form>',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function shipments(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $withSelect = ($req->filters['select'] ?? '') === '1';
        if ($withSelect && $req->orderColumn > 0) {
            $req->orderColumn--;
        } elseif ($withSelect) {
            $req->orderColumn = 0;
        }
        $result = (new ShipmentsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $orderBase = $this->app->url('admin/orders/view');
        $labelBase = $this->app->url('admin/documents/label');
        $manifestsUrl = $this->app->url('admin/warehouse/manifests');
        $data = [];
        foreach ($result['rows'] as $s) {
            $oid = (int) ($s['order_id'] ?? 0);
            $ext = (string) ($s['external_order_id'] ?? '');
            $orderLabel = $ext !== '' ? $ext : (string) $oid;
            $orderHtml = $oid > 0
                ? '<a href="' . $this->e($orderBase . '?id=' . $oid) . '">' . $this->e($orderLabel) . '</a>'
                : $this->e($orderLabel !== '' ? $orderLabel : '—');

            $name = trim((string) ($s['shipping_name'] ?? ''));
            if ($name !== '') {
                $orderHtml .= '<div class="text-muted small">' . $this->e($name) . '</div>';
            }

            $carrier = trim((string) ($s['carrier_code'] ?? ''));
            $service = trim((string) ($s['service_code'] ?? ''));
            $carrierHtml = $this->e($carrier !== '' ? $carrier : '—');
            if ($service !== '') {
                $carrierHtml .= '<div class="text-muted small">' . $this->e($service) . '</div>';
            }

            $track = trim((string) ($s['tracking_number'] ?? ''));
            $manifestRef = trim((string) ($s['manifest_reference'] ?? ''));
            $manifestId = (int) ($s['manifest_id'] ?? 0);
            $manifestHtml = $manifestId > 0 && $manifestRef !== ''
                ? '<a href="' . $this->e($manifestsUrl) . '">' . $this->e($manifestRef) . '</a>'
                : ($manifestRef !== '' ? $this->e($manifestRef) : '—');

            $despatched = (string) ($s['despatched_at'] ?? '');
            if ($despatched === '') {
                $despatched = (string) ($s['created_at'] ?? '');
            }

            $fStatus = trim((string) ($s['fulfillment_status'] ?? ''));
            $fError = trim((string) ($s['fulfillment_error'] ?? ''));
            $channelHtml = $fStatus !== ''
                ? '<span class="badge">' . $this->e($fStatus) . '</span>'
                : '<span class="text-muted">—</span>';
            if ($fError !== '' && $fStatus !== 'uploaded') {
                $channelHtml .= '<div class="text-muted small">' . $this->e(mb_substr($fError, 0, 80)) . '</div>';
            }

            $sid = (int) ($s['id'] ?? 0);
            $actions = '';
            if ($oid > 0) {
                $actions .= '<a class="btn btn-sm btn-outline-secondary" href="'
                    . $this->e($labelBase . '?order_id=' . $oid . '&print=1')
                    . '" target="_blank" rel="noopener">Label</a> ';
                $actions .= '<a class="btn btn-sm btn-outline-primary" href="'
                    . $this->e($orderBase . '?id=' . $oid)
                    . '">Order</a>';
                if ($track !== '' && $fStatus !== 'uploaded') {
                    $retryUrl = $this->e($this->app->url('admin/warehouse/deliveries/retry-fulfillment'));
                    $token = $this->e($this->app->session->csrfToken());
                    $actions .= ' <form method="post" action="' . $retryUrl . '" style="display:inline;margin:0">'
                        . '<input type="hidden" name="_token" value="' . $token . '">'
                        . '<input type="hidden" name="order_id" value="' . $oid . '">'
                        . '<input type="hidden" name="shipment_id" value="' . $sid . '">'
                        . '<button class="btn btn-sm btn-outline-secondary" type="submit">Retry upload</button>'
                        . '</form>';
                }
                $carrierKey = strtolower(str_replace(['-', ' '], '_', $carrier));
                $consignment = trim((string) ($s['external_consignment_id'] ?? ''));
                $shipStatus = (string) ($s['status'] ?? '');
                $voidable = ['royal_mail', 'royalmail', 'rm', 'royal_mail_courier', 'evri', 'hermes', 'myhermes', 'dpd', 'dpd_uk', 'dpduk', 'parcelforce', 'parcel_force', 'pf'];
                if ($consignment !== ''
                    && $shipStatus !== 'voided'
                    && in_array($carrierKey, $voidable, true)
                ) {
                    $voidUrl = $this->e($this->app->url('admin/warehouse/deliveries/void'));
                    $token = $this->e($this->app->session->csrfToken());
                    $actions .= ' <form method="post" action="' . $voidUrl . '" style="display:inline;margin:0"'
                        . ' onsubmit="return confirm(\'Void this label? The order stays despatched.\');">'
                        . '<input type="hidden" name="_token" value="' . $token . '">'
                        . '<input type="hidden" name="shipment_id" value="' . $sid . '">'
                        . '<button class="btn btn-sm btn-outline-danger" type="submit">Void</button>'
                        . '</form>';
                }
            } else {
                $actions = '—';
            }

            $row = [
                'order'     => $orderHtml,
                'tracking'  => $track !== '' ? '<code>' . $this->e($track) . '</code>' : '—',
                'carrier'   => $carrierHtml,
                'status'    => '<span class="badge">' . $this->e((string) ($s['status'] ?? '')) . '</span>',
                'channel'   => $channelHtml,
                'manifest'  => $manifestHtml,
                'despatched'=> $this->e($despatched),
                'actions'   => $actions,
            ];
            if ($withSelect) {
                $row['select'] = $oid > 0
                    ? '<input type="checkbox" class="sf-order-check" name="order_ids[]" value="'
                        . $oid . '" data-order-id="' . $oid . '" data-shipment-id="' . $sid . '">'
                    : '';
            }
            $data[] = $row;
        }
        $this->respond($req, $result, $data);
    }

    public function dropships(): void
    {
        if (!$this->requireScope('warehouse:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new DropshipsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $orderBase = $this->app->url('admin/orders/view');
        $statusUrl = $this->e($this->app->url('admin/dropships/status'));
        $token = $this->e($this->app->session->csrfToken());
        $data = [];
        foreach ($result['rows'] as $d) {
            $oid = (int) ($d['order_id'] ?? 0);
            $did = (int) ($d['id'] ?? 0);
            $status = strtolower(trim((string) ($d['status'] ?? '')));

            $actions = '';
            if ($oid > 0) {
                $actions .= '<a class="btn btn-sm btn-outline-primary" href="'
                    . $this->e($orderBase . '?id=' . $oid) . '">Open</a> ';
            }
            if ($did > 0 && $status !== 'sent' && $status !== 'shipped' && $status !== 'cancelled') {
                $actions .= '<form method="post" action="' . $statusUrl . '" style="display:inline;margin:0">'
                    . '<input type="hidden" name="_token" value="' . $token . '">'
                    . '<input type="hidden" name="id" value="' . $did . '">'
                    . '<input type="hidden" name="status" value="sent">'
                    . '<button class="btn btn-sm btn-outline-secondary" type="submit">Mark sent</button>'
                    . '</form> ';
            }
            if ($did > 0 && $status === 'sent') {
                $actions .= '<form method="post" action="' . $statusUrl . '" style="display:inline;margin:0">'
                    . '<input type="hidden" name="_token" value="' . $token . '">'
                    . '<input type="hidden" name="id" value="' . $did . '">'
                    . '<input type="hidden" name="status" value="shipped">'
                    . '<button class="btn btn-sm btn-outline-secondary" type="submit">Mark shipped</button>'
                    . '</form> ';
            }
            if ($did > 0 && $status !== 'cancelled') {
                $actions .= '<form method="post" action="' . $statusUrl . '" style="display:inline;margin:0">'
                    . '<input type="hidden" name="_token" value="' . $token . '">'
                    . '<input type="hidden" name="id" value="' . $did . '">'
                    . '<input type="hidden" name="status" value="cancelled">'
                    . '<button class="btn btn-sm btn-outline-danger" type="submit">Cancel</button>'
                    . '</form>';
            }
            if ($actions === '') {
                $actions = '—';
            }

            $data[] = [
                'reference' => '<strong>' . $this->e((string) $d['reference']) . '</strong>',
                'order'     => $oid > 0
                    ? '<a href="' . $this->e($orderBase . '?id=' . $oid) . '">' . $this->e((string) ($d['external_order_id'] ?? $oid)) . '</a>'
                    : '—',
                'supplier'  => $this->e((string) ($d['supplier_name'] ?? '')),
                'status'    => '<span class="badge">' . $this->e((string) $d['status']) . '</span>',
                'created'   => $this->e((string) $d['created_at']),
                'actions'   => $actions,
            ];
        }
        $this->respond($req, $result, $data);
    }

    /** @param array<string,mixed> $job */
    private function reportFileLink(array $job): string
    {
        $path = trim((string) ($job['file_path'] ?? ''));
        $id = (int) ($job['id'] ?? 0);
        if ($path === '' || $id <= 0 || (string) ($job['status'] ?? '') !== 'completed') {
            return '—';
        }
        $href = $this->e($this->app->url('admin/reports/exports/download', ['id' => $id]));
        return '<a href="' . $href . '">Download</a>';
    }

    public function reportJobs(): void
    {
        if (!$this->requireScope('reports:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $type = (string) ($req->filters['type'] ?? '');
        $result = (new ReportJobsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req, $type);
        $data = [];
        foreach ($result['rows'] as $j) {
            $data[] = [
                'name'     => $this->e((string) $j['name']),
                'type'     => $this->e((string) $j['type']),
                'schedule' => $this->e((string) $j['schedule']),
                'status'   => '<span class="badge">' . $this->e((string) $j['status']) . '</span>',
                'file'     => $this->reportFileLink($j),
                'created'  => $this->e((string) $j['created_at']),
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function shippingMethods(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new ShippingMethodsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $data = [];
        foreach ($result['rows'] as $m) {
            $data[] = [
                'name'    => $this->e((string) $m['name']),
                'carrier' => $this->e((string) $m['carrier']),
                'service'   => $this->e((string) $m['service_code']),
                'packaging' => $this->e(trim((string) ($m['packaging_name'] ?? '')) !== '' ? (string) $m['packaging_name'] : '—'),
                'active'    => !empty($m['active']) ? 'Yes' : 'No',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function shippingRegions(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new ShippingRegionsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $data = [];
        foreach ($result['rows'] as $r) {
            $data[] = [
                'name'      => $this->e((string) $r['name']),
                'countries' => $this->e((string) $r['countries']),
                'prefixes'  => $this->e((string) ($r['postcode_prefixes'] ?: '—')),
                'active'    => !empty($r['active']) ? 'Yes' : 'No',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function shippingRules(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new ShippingRulesDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $data = [];
        foreach ($result['rows'] as $r) {
            $wmin = (int) ($r['weight_min_g'] ?? 0);
            $wmax = (int) ($r['weight_max_g'] ?? 0);
            $weight = $wmax > 0 ? "{$wmin}–{$wmax}g" : ($wmin > 0 ? "≥{$wmin}g" : 'Any');
            $data[] = [
                'name'     => $this->e((string) $r['name']),
                'priority' => $this->e((string) $r['priority']),
                'channel'  => $this->e((string) ($r['channel_name'] ?? 'Any')),
                'region'   => $this->e((string) ($r['region_name'] ?? 'Any')),
                'method'   => $this->e((string) ($r['method_name'] ?? '—')),
                'match'    => $this->e((string) ($r['match_shipping_method'] ?: '—')),
                'weight'   => $this->e($weight),
                'active'   => !empty($r['active']) ? 'Yes' : 'No',
            ];
        }
        $this->respond($req, $result, $data);
    }

    public function listings(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $req = new DataTableRequest($this->app->request);
        $result = (new ListingsDataset($this->app->db))->forDataTable($this->app->tenant->id(), $req);
        $mapBase = $this->app->url('admin/listings/map');
        $productBase = $this->app->url('admin/products/view');
        $unmapUrl = $this->app->url('admin/listings/unmap');
        $token = $this->app->session->csrfToken();
        $data = [];
        foreach ($result['rows'] as $l) {
            $id = (int) $l['id'];
            $mapped = !empty($l['mapped']) && !empty($l['product_id']);
            $productCell = $mapped
                ? '<a href="' . $this->e($productBase . '?id=' . (int) $l['product_id']) . '">'
                    . $this->e((string) ($l['product_sku'] ?? '')) . '</a>'
                    . '<div class="muted">' . $this->e((string) ($l['product_name'] ?? '')) . '</div>'
                : '<span class="badge badge-warn">Unmapped</span>';
            $actions = $mapped
                ? '<form method="post" action="' . $this->e($unmapUrl) . '" style="display:inline">'
                    . '<input type="hidden" name="_token" value="' . $this->e($token) . '">'
                    . '<input type="hidden" name="id" value="' . $id . '">'
                    . '<button class="btn btn-ghost" type="submit" style="padding:.2rem .5rem;font-size:12px">Unmap</button></form>'
                : '<a href="' . $this->e($mapBase . '?id=' . $id) . '">Map</a>';
            $data[] = [
                'listing'  => '<strong>' . $this->e((string) $l['title']) . '</strong>'
                    . '<div class="muted">' . $this->e((string) $l['external_id']) . '</div>',
                'channel'  => $this->e((string) ($l['channel_name'] ?? '')),
                'sku'      => $this->e((string) $l['sku']),
                'price'    => $this->e(number_format((float) $l['price'], 2)),
                'qty'      => $this->e((string) $l['qty']),
                'product'  => $productCell,
                'status'   => '<span class="badge">' . $this->e((string) $l['status']) . '</span>',
                'actions'  => $actions,
            ];
        }
        $this->respond($req, $result, $data);
    }

    private function option(string $value, string $current): string
    {
        $sel = $value === $current ? ' selected' : '';
        return '<option value="' . $this->e($value) . '"' . $sel . '>' . $this->e($value) . '</option>';
    }

    /** @param array<string,mixed> $o */
    private function orderRow(array $o, bool $withSelect = false): array
    {
        $code = strtolower((string) ($o['channel_code'] ?? ''));
        $type = strtolower((string) ($o['channel_type'] ?? ''));
        $dot = 'channel-dot';
        $letter = '•';
        if (str_contains($code, 'amazon') || str_contains($type, 'amazon')) {
            $letter = 'a';
        } elseif (str_contains($code, 'ebay') || str_contains($type, 'ebay')) {
            $dot .= ' ebay';
            $letter = 'e';
        } elseif (str_contains($code, 'shop') || str_contains($type, 'shop')) {
            $dot .= ' shopify';
            $letter = 'S';
        } elseif (str_contains($code, 'moto') || str_contains($type, 'moto')) {
            $dot .= ' moto';
            $letter = 'M';
        }

        $id = (int) $o['id'];
        $view = $this->app->url('admin/orders/view') . '?id=' . $id;
        $productBase = $this->app->url('admin/products/view');
        $pickwaveBase = $this->app->url('admin/warehouse/pickwaves/view');

        $created = (string) ($o['created_at'] ?? '');
        $date = $created !== '' ? date('d/m/Y H:i', strtotime($created) ?: time()) : '—';
        $postcode = trim((string) ($o['ship_postcode'] ?? ''));
        $packaging = trim((string) ($o['package_size_name'] ?? ''));
        $ship = trim((string) (($o['mapped_shipping_method'] ?? '') !== ''
            ? $o['mapped_shipping_method']
            : ($o['shipping_method'] ?? '')));
        $weight = (float) ($o['order_weight_kg'] ?? 0);
        $pickwaveId = (int) ($o['pickwave_id'] ?? 0);
        $notes = trim((string) ($o['notes'] ?? ''));
        $currency = strtoupper((string) ($o['currency'] ?? 'GBP'));
        $symbol = $currency === 'GBP' ? '£' : ($currency === 'EUR' ? '€' : $currency . ' ');

        $flags = '';
        if (!empty($o['is_priority'])) {
            $flags .= '<span class="sf-oflag sf-oflag-p" title="Priority">P</span>';
        }
        if (!empty($o['attention_required'])) {
            $flags .= '<span class="sf-oflag sf-oflag-a" title="Attention">A</span>';
        }
        if (!empty($o['on_hold'])) {
            $flags .= '<span class="sf-oflag sf-oflag-h" title="On hold">H</span>';
        }

        $channelName = trim((string) ($o['channel_name'] ?? $code));
        $row = [
            'flags'     => $flags,
            'order'     => '<a class="sf-order-no" href="' . $this->e($view) . '">' . $id . '</a>',
            'channel'   => '<span class="channel-pill"><span class="' . $this->e($dot) . '">' . $this->e($letter) . '</span> '
                . $this->e($channelName) . '</span>',
            'skus'      => $this->formatSkuLines((string) ($o['sku_lines'] ?? ''), '—', $productBase),
            'date'      => '<span class="text-nowrap">' . $this->e($date) . '</span>',
            'postcode'  => $this->e($postcode !== '' ? $postcode : 'N/A'),
            'email'     => $this->e(trim((string) ($o['buyer_email'] ?? '')) !== ''
                ? (string) $o['buyer_email']
                : '—'),
            'total'     => $this->e($symbol . number_format((float) ($o['total'] ?? 0), 2)),
            'packaging' => $this->e($packaging !== '' ? $packaging : '—'),
            'packages'  => $this->e((string) max(1, (int) ($o['package_count'] ?? 1))),
            'shipping'  => $this->e($ship !== '' ? $ship : '—'),
            'status'    => '<span class="text-nowrap">' . $this->e($this->orderStatusLabel($o)) . '</span>',
            'weight'    => $this->e(number_format($weight, 3) . ' kg'),
            'pickwave'  => $pickwaveId > 0
                ? '<a class="sf-order-no" href="' . $this->e($pickwaveBase . '?id=' . $pickwaveId) . '">' . $pickwaveId . '</a>'
                : '',
            'notes'     => $notes !== ''
                ? '<span class="sf-order-notes" title="' . $this->e($notes) . '">N</span>'
                : '',
        ];
        if ($withSelect) {
            $row = [
                'select' => '<input type="checkbox" class="sf-order-check" name="order_ids[]" value="'
                    . $id . '" data-order-id="' . $id . '" aria-label="Select order ' . $id . '">',
            ] + $row;
        }
        return $row;
    }

    /** @param array<string,mixed> $o */
    private function orderStatusLabel(array $o): string
    {
        $status = (string) ($o['status'] ?? '');
        if ($status === 'cancelled') {
            return 'Cancelled';
        }
        if ($status === 'refunded') {
            return 'Refunded';
        }
        if ($status === 'despatched') {
            return 'Despatched';
        }
        if ($status === 'on_hold' || !empty($o['on_hold'])) {
            return 'On Hold';
        }
        if ($status === 'awaiting_payment' || (int) ($o['is_paid'] ?? 1) === 0) {
            return 'Awaiting Payment';
        }
        if ($status === 'processing') {
            return 'In Progress';
        }
        if (in_array($status, ['awaiting_despatch', 'printed'], true)) {
            return 'Complete (Ready To Pick)';
        }
        return $status !== '' ? ucwords(str_replace('_', ' ', $status)) : '—';
    }

    /** @param array<string,mixed> $o */
    private function pickingOrderRow(array $o, bool $withSelect, string $company): array
    {
        $id = (int) $o['id'];
        $view = $this->app->url('admin/orders/view') . '?id=' . $id;
        $code = strtolower((string) ($o['channel_code'] ?? ''));
        $dot = 'channel-dot';
        $letter = '•';
        if (str_contains($code, 'amazon')) {
            $letter = 'a';
        } elseif (str_contains($code, 'ebay')) {
            $dot .= ' ebay';
            $letter = 'e';
        } elseif (str_contains($code, 'shop')) {
            $dot .= ' shopify';
            $letter = 'S';
        }

        $flags = [];
        if (!empty($o['is_priority'])) {
            $flags[] = 'Priority';
        }
        if (!empty($o['on_hold'])) {
            $flags[] = 'Hold';
        }
        $flagHtml = $flags === [] ? '—' : $this->e(implode(', ', $flags));

        $productBase = $this->app->url('admin/products/view');
        $skuHtml = $this->formatSkuLines((string) ($o['sku_lines'] ?? ''), '—', $productBase);

        $created = (string) ($o['created_at'] ?? '');
        $date = $created !== '' ? date('d/m/Y H:i', strtotime($created) ?: time()) : '—';
        $weight = (float) ($o['order_weight_kg'] ?? 0);
        $postcode = trim((string) ($o['ship_postcode'] ?? ''));
        $shipBy = '—';
        $handling = trim((string) ($o['handling_days'] ?? ''));
        $createdTs = $created !== '' ? strtotime($created) : false;
        if ($handling !== '' && ctype_digit($handling) && $createdTs !== false) {
            $shipBy = date('d/m/Y', strtotime('+' . (int) $handling . ' days', $createdTs)) . ' 23:59';
        }
        $brandHtml = '—';
        $brands = preg_split('/\r\n|\n|\r/', (string) ($o['brand_lines'] ?? '')) ?: [];
        $brands = array_values(array_filter(array_map('trim', $brands)));
        if ($brands !== []) {
            $shownBrands = array_slice($brands, 0, 4);
            $brandHtml = implode('<br>', array_map(fn ($line) => $this->e($line), $shownBrands));
            if (count($brands) > 4) {
                $brandHtml .= '<br><span class="muted">+' . (count($brands) - 4) . ' more</span>';
            }
        }

        $row = [
            'warehouse'    => $this->e((string) (($o['warehouse'] ?? '') !== '' ? $o['warehouse'] : 'Default')),
            'company'      => $this->e($company !== '' ? $company : '—'),
            'channel'      => '<span class="channel-pill"><span class="' . $this->e($dot) . '">' . $this->e($letter) . '</span> '
                . $this->e((string) ($o['channel_name'] ?? $code)) . '</span>',
            'flags'        => $flagHtml,
            'order'        => '<a href="' . $this->e($view) . '">' . $id . '</a>',
            'channel_ref'  => $this->e((string) ($o['external_order_id'] ?? '')),
            'date'         => $this->e($date),
            'ship_by'      => $this->e($shipBy),
            'skus'         => $skuHtml,
            'brand'        => $brandHtml,
            'package_size' => $this->e(trim((string) ($o['package_size_name'] ?? '')) !== ''
                ? (string) $o['package_size_name']
                : '—'),
            'packages'     => $this->e((string) max(1, (int) ($o['package_count'] ?? 1))),
            'weight'       => $this->e(number_format($weight, 2)),
            'total'        => $this->e(number_format((float) ($o['total'] ?? 0), 2)),
            'method'       => $this->e((string) ($o['shipping_method'] ?? '')),
            'postcode'     => $this->e($postcode !== '' ? $postcode : '—'),
        ];
        if ($withSelect) {
            $row = [
                'select' => '<input type="checkbox" class="sf-order-check" name="order_ids[]" value="'
                    . $id . '" data-order-id="' . $id . '" aria-label="Select order ' . $id . '">',
            ] + $row;
        }
        return $row;
    }

    private function formatSkuLines(string $raw, string $empty = '', string $productBase = ''): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $raw) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines)));
        if ($lines === []) {
            return $empty;
        }

        $html = '';
        $shown = 0;
        $max = 4;
        foreach ($lines as $line) {
            if ($shown >= $max) {
                break;
            }
            $productId = 0;
            $sku = '';
            $name = '';
            $qty = 1;
            if (str_contains($line, '|')) {
                $parts = explode('|', $line, 4);
                $productId = (int) ($parts[0] ?? 0);
                $sku = trim((string) ($parts[1] ?? ''));
                $name = trim((string) ($parts[2] ?? ''));
                $qty = max(1, (int) ($parts[3] ?? 1));
            } elseif (str_contains($line, ' · ')) {
                [$sku, $name] = array_pad(explode(' · ', $line, 2), 2, '');
                $sku = trim($sku);
                $name = trim((string) $name);
            } else {
                $name = $line;
            }

            $label = $name !== '' ? $name : ($sku !== '' ? $sku : '');
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 48) {
                $label = mb_substr($label, 0, 47) . '…';
            }

            $html .= ($html !== '' ? '<br>' : '');
            if ($productId > 0 && $productBase !== '') {
                $html .= '<a class="sf-sku-link" href="' . $this->e($productBase . '?id=' . $productId) . '">'
                    . $this->e($label) . '</a>';
            } else {
                $html .= '<span class="sf-sku-link">' . $this->e($label) . '</span>';
            }
            $html .= ' <span class="muted">×' . $qty . '</span>';
            if ($sku !== '' && $name !== '' && strcasecmp($sku, $name) !== 0) {
                $html .= '<div class="muted sf-sku-code">' . $this->e($sku) . '</div>';
            }
            $shown++;
        }

        $extra = count($lines) - $shown;
        if ($extra > 0) {
            $html .= '<br><span class="muted">+' . $extra . ' more</span>';
        }

        return $html !== '' ? '<span class="sf-sku-lines">' . $html . '</span>' : $empty;
    }

    /**
     * @param array{recordsTotal:int,recordsFiltered:int,rows:list<array<string,mixed>>} $result
     * @param list<array<string,mixed>> $data
     */
    private function respond(DataTableRequest $req, array $result, array $data): void
    {
        $this->json([
            'draw'            => $req->draw,
            'recordsTotal'    => $result['recordsTotal'],
            'recordsFiltered' => $result['recordsFiltered'],
            'data'            => $data,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    private function requireScope(string $scope): bool
    {
        if ($this->app->auth->apiTokenAllowsScope($scope)) {
            return true;
        }

        http_response_code(403);
        $this->json([
            'ok' => false,
            'error' => 'Your API token does not have the required permission.',
        ]);

        return false;
    }
}
