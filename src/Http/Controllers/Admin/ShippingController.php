<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\ShippingRuleMatcher;

final class ShippingController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->app->tenant->id();
        echo $this->view('admin/settings/shipping', [
            'title'      => 'Shipping Configuration',
            'navSection' => 'settings',
            'methods'    => (int) ($this->app->db->query('SELECT COUNT(*) AS c FROM #__store_shipping_methods WHERE tenant_id=?', [$tenantId])->row['c'] ?? 0),
            'regions'    => (int) ($this->app->db->query('SELECT COUNT(*) AS c FROM #__store_shipping_regions WHERE tenant_id=?', [$tenantId])->row['c'] ?? 0),
            'rules'      => (int) ($this->app->db->query('SELECT COUNT(*) AS c FROM #__store_shipping_rules WHERE tenant_id=?', [$tenantId])->row['c'] ?? 0),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function methods(): void
    {
        echo $this->view('admin/settings/shipping_methods', [
            'title'      => 'Shipping Methods',
            'navSection' => 'settings',
            'flash'      => $this->app->session->flash('success'),
            'sizes'      => $this->packagingSizes(),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storeMethod(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/shipping/methods');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        if ($name !== '') {
            $sizeId = (int) $this->app->request->post('packaging_size_id', 0);
            $this->app->db->query(
                'INSERT INTO #__store_shipping_methods (tenant_id, name, carrier, service_code, packaging_size_id, active) VALUES (?,?,?,?,?,1)',
                [
                    $this->app->tenant->id(),
                    $name,
                    trim((string) $this->app->request->post('carrier', '')),
                    trim((string) $this->app->request->post('service_code', '')),
                    $sizeId > 0 ? $sizeId : null,
                ]
            );
            $this->app->session->flash('success', 'Shipping method created.');
        }
        $this->redirect('admin/settings/shipping/methods');
    }

    public function packaging(): void
    {
        echo $this->view('admin/settings/packaging', [
            'title'      => 'Packaging sizes',
            'navSection' => 'settings',
            'flash'      => $this->app->session->flash('success'),
            'sizes'      => $this->packagingSizes(),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storePackaging(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/shipping/packaging');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        if ($name !== '') {
            $this->app->db->query(
                'INSERT INTO #__store_packaging_sizes (tenant_id, name, active) VALUES (?,?,1)',
                [$this->app->tenant->id(), substr($name, 0, 64)]
            );
            $this->app->session->flash('success', 'Packaging size added.');
        }
        $this->redirect('admin/settings/shipping/packaging');
    }

    /** @return list<array<string,mixed>> */
    private function packagingSizes(): array
    {
        return $this->app->db->query(
            'SELECT id, name FROM #__store_packaging_sizes WHERE tenant_id=? AND active=1 ORDER BY name',
            [$this->app->tenant->id()]
        )->rows;
    }

    public function regions(): void
    {
        echo $this->view('admin/settings/shipping_regions', [
            'title'      => 'Shipping Regions',
            'navSection' => 'settings',
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storeRegion(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/shipping/regions');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        if ($name !== '') {
            $this->app->db->query(
                'INSERT INTO #__store_shipping_regions (tenant_id, name, countries, postcode_prefixes, active) VALUES (?,?,?,?,1)',
                [
                    $this->app->tenant->id(),
                    $name,
                    trim((string) $this->app->request->post('countries', 'GB')),
                    trim((string) $this->app->request->post('postcode_prefixes', '')),
                ]
            );
            $this->app->session->flash('success', 'Shipping region created.');
        }
        $this->redirect('admin/settings/shipping/regions');
    }

    public function rules(): void
    {
        $tenantId = $this->app->tenant->id();
        echo $this->view('admin/settings/shipping_rules', [
            'title'      => 'Shipping Rules',
            'navSection' => 'settings',
            'methods'    => $this->app->db->query(
                'SELECT id, name FROM #__store_shipping_methods WHERE tenant_id=? AND active=1 ORDER BY name',
                [$tenantId]
            )->rows,
            'regions'    => $this->app->db->query(
                'SELECT id, name FROM #__store_shipping_regions WHERE tenant_id=? AND active=1 ORDER BY name',
                [$tenantId]
            )->rows,
            'channels'   => $this->app->db->query(
                'SELECT id, name FROM #__store_channels WHERE tenant_id=? AND active=1 ORDER BY name',
                [$tenantId]
            )->rows,
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storeRule(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/shipping/rules');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        if ($name !== '') {
            $this->app->db->query(
                'INSERT INTO #__store_shipping_rules
                 (tenant_id, name, priority, channel_id, region_id, method_id, match_shipping_method, weight_min_g, weight_max_g, active)
                 VALUES (?,?,?,?,?,?,?,?,?,1)',
                [
                    $this->app->tenant->id(),
                    $name,
                    (int) $this->app->request->post('priority', 100),
                    (int) $this->app->request->post('channel_id', 0) ?: null,
                    (int) $this->app->request->post('region_id', 0) ?: null,
                    (int) $this->app->request->post('method_id', 0) ?: null,
                    trim((string) $this->app->request->post('match_shipping_method', '')),
                    (int) $this->app->request->post('weight_min_g', 0),
                    (int) $this->app->request->post('weight_max_g', 0),
                ]
            );
            $this->app->session->flash('success', 'Shipping rule created.');
        }
        $this->redirect('admin/settings/shipping/rules');
    }

    public function tester(): void
    {
        $tenantId = $this->app->tenant->id();
        $result = null;
        $sample = [
            'channel_id'      => (int) $this->app->request->get('channel_id', 0),
            'shipping_method' => (string) $this->app->request->get('shipping_method', 'Royal Mail 48'),
            'weight_grams'    => (int) $this->app->request->get('weight_grams', 500),
            'postcode'        => (string) $this->app->request->get('postcode', 'M1 1AE'),
            'country'         => (string) $this->app->request->get('country', 'GB'),
        ];

        if ($this->app->request->get('run', '') === '1') {
            $result = (new ShippingRuleMatcher($this->app->db))->match($tenantId, $sample);
        }

        $orderId = (int) $this->app->request->get('order_id', 0);
        if ($orderId > 0) {
            $order = $this->app->db->query(
                'SELECT * FROM #__store_orders WHERE id = ? AND tenant_id = ? LIMIT 1',
                [$orderId, $tenantId]
            )->row;
            if ($order !== []) {
                $matcher = new ShippingRuleMatcher($this->app->db);
                $addr = $matcher->addressFromOrder($order);
                $sample = [
                    'channel_id'      => (int) $order['channel_id'],
                    'shipping_method' => (string) $order['shipping_method'],
                    'weight_grams'    => (int) round(((float) ($order['order_weight_kg'] ?? 0)) * 1000),
                    'postcode'        => $addr['postcode'],
                    'country'         => $addr['country'],
                ];
                $result = $matcher->match($tenantId, $sample + $order);
            }
        }

        echo $this->view('admin/settings/shipping_tester', [
            'title'      => 'Shipping Rules Tester',
            'navSection' => 'settings',
            'channels'   => $this->app->db->query(
                'SELECT id, name FROM #__store_channels WHERE tenant_id=? ORDER BY name',
                [$tenantId]
            )->rows,
            'sample'     => $sample,
            'result'     => $result,
            'user'       => $this->app->auth->user(),
        ]);
    }
}
