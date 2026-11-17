<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\Database;

/**
 * Picks the best shipping rule for an order (lower priority wins).
 */
final class ShippingRuleMatcher
{
    public function __construct(private Database $db) {}

    /**
     * @param array<string, mixed> $order
     * @return array{rule:array<string,mixed>|null, method:array<string,mixed>|null, reason:string}
     */
    public function match(int $tenantId, array $order): array
    {
        $rules = $this->db->query(
            "SELECT r.*, m.name AS method_name, m.carrier AS method_carrier, m.service_code AS method_service,
                    m.packaging_size_id AS method_packaging_size_id,
                    rg.name AS region_name, rg.countries, rg.postcode_prefixes
             FROM #__store_shipping_rules r
             LEFT JOIN #__store_shipping_methods m ON m.id = r.method_id
             LEFT JOIN #__store_shipping_regions rg ON rg.id = r.region_id
             WHERE r.tenant_id = ? AND r.active = 1
             ORDER BY r.priority ASC, r.id ASC",
            [$tenantId]
        )->rows;

        $channelId = (int) ($order['channel_id'] ?? 0);
        $shippingMethod = trim((string) ($order['shipping_method'] ?? ''));
        $weight = (int) ($order['weight_grams'] ?? 0);
        if ($weight <= 0 && isset($order['order_weight_kg'])) {
            $weight = (int) round(((float) $order['order_weight_kg']) * 1000);
        }
        $postcode = strtoupper(preg_replace('/\s+/', '', (string) ($order['postcode'] ?? '')) ?? '');
        $country = strtoupper((string) ($order['country'] ?? 'GB'));

        foreach ($rules as $rule) {
            if (!empty($rule['channel_id']) && (int) $rule['channel_id'] !== $channelId) {
                continue;
            }

            $matchText = trim((string) ($rule['match_shipping_method'] ?? ''));
            if ($matchText !== '' && stripos($shippingMethod, $matchText) === false) {
                continue;
            }

            $wmin = (int) ($rule['weight_min_g'] ?? 0);
            $wmax = (int) ($rule['weight_max_g'] ?? 0);
            if ($wmin > 0 && $weight > 0 && $weight < $wmin) {
                continue;
            }
            if ($wmax > 0 && $weight > $wmax) {
                continue;
            }

            if (!empty($rule['region_id'])) {
                $countries = array_filter(array_map('trim', explode(',', strtoupper((string) ($rule['countries'] ?? '')))));
                if ($countries !== [] && !in_array($country, $countries, true)) {
                    continue;
                }
                $prefixes = array_filter(array_map('trim', explode(',', strtoupper((string) ($rule['postcode_prefixes'] ?? '')))));
                if ($prefixes !== []) {
                    $hit = false;
                    foreach ($prefixes as $prefix) {
                        if ($prefix !== '' && str_starts_with($postcode, $prefix)) {
                            $hit = true;
                            break;
                        }
                    }
                    if (!$hit) {
                        continue;
                    }
                }
            }

            $method = null;
            if (!empty($rule['method_id'])) {
                $method = [
                    'id'           => (int) $rule['method_id'],
                    'name'         => (string) ($rule['method_name'] ?? ''),
                    'carrier'      => (string) ($rule['method_carrier'] ?? ''),
                    'service_code'       => (string) ($rule['method_service'] ?? ''),
                    'packaging_size_id'  => (int) ($rule['method_packaging_size_id'] ?? 0),
                ];
            }

            return [
                'rule'   => $rule,
                'method' => $method,
                'reason' => 'Matched rule #' . (int) $rule['id'] . ' (' . (string) $rule['name'] . ')',
            ];
        }

        return [
            'rule'   => null,
            'method' => null,
            'reason' => 'No shipping rule matched.',
        ];
    }

    /**
     * @return array{line1:string,city:string,postcode:string,country:string}
     */
    public function addressFromOrder(array $order): array
    {
        $addr = ['line1' => '', 'city' => '', 'postcode' => '', 'country' => 'GB'];
        $raw = $order['shipping_address'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $addr = array_merge($addr, $decoded);
            }
        } elseif (is_array($raw)) {
            $addr = array_merge($addr, $raw);
        }
        return [
            'line1'    => (string) ($addr['line1'] ?? ''),
            'city'     => (string) ($addr['city'] ?? ''),
            'postcode' => (string) ($addr['postcode'] ?? ''),
            'country'  => (string) ($addr['country'] ?? 'GB'),
        ];
    }
}
