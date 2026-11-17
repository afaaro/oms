<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Store\ShippingRuleMatcher;

final class ShippingApiController extends ApiController
{
    public function methods(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT id, name, carrier, service_code, active, created_at
             FROM #__store_shipping_methods WHERE tenant_id = ? ORDER BY name',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }

    public function regions(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT id, name, countries, postcode_prefixes, active, created_at
             FROM #__store_shipping_regions WHERE tenant_id = ? ORDER BY name',
            [$this->tenantId()]
        )->rows;
        foreach ($rows as &$row) {
            $countries = preg_split('/\s*,\s*/', (string) ($row['countries'] ?? '')) ?: [];
            $row['countries_list'] = array_values(array_filter($countries));
        }
        unset($row);
        $this->ok($rows);
    }

    public function rules(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT r.id, r.name, r.priority, r.active, r.channel_id, r.region_id, r.method_id,
                    r.match_shipping_method, r.weight_min_g, r.weight_max_g, r.created_at,
                    m.name AS method_name, m.carrier, m.service_code,
                    rg.name AS region_name
             FROM #__store_shipping_rules r
             LEFT JOIN #__store_shipping_methods m ON m.id = r.method_id
             LEFT JOIN #__store_shipping_regions rg ON rg.id = r.region_id
             WHERE r.tenant_id = ?
             ORDER BY r.priority ASC, r.id ASC',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }

    public function test(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $payload = [
            'country' => strtoupper(trim((string) $this->app->request->input('country', 'GB'))),
            'postcode' => trim((string) $this->app->request->input('postcode', '')),
            'weight_grams' => (int) $this->app->request->input('weight_grams', 0),
            'total' => (float) $this->app->request->input('total', 0),
            'channel_id' => (int) $this->app->request->input('channel_id', 0),
            'shipping_method' => trim((string) $this->app->request->input('shipping_method', '')),
        ];
        $matcher = new ShippingRuleMatcher($this->app->db);
        $result = $matcher->match($this->tenantId(), $payload);
        $this->ok([
            'input' => $payload,
            'matched_rule' => $result['rule'],
            'method' => $result['method'],
        ]);
    }
}
