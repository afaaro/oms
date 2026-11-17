<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

/**
 * Load order + shipment data for printable labels, invoices, packing slips.
 */
final class DocumentService
{
    public function __construct(private App $app) {}

    /**
     * @return array{order:array<string,mixed>, items:list<array<string,mixed>>, address:array<string,mixed>, billing:array<string,mixed>, channel:array<string,mixed>, shipment:?array<string,mixed>, company:array<string,mixed>}|null
     */
    public function orderBundle(int $orderId): ?array
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->app->db->query(
            "SELECT o.*, c.name AS channel_name, c.code AS channel_code, c.settings AS channel_settings
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id AND c.tenant_id = o.tenant_id
             WHERE o.id=? AND o.tenant_id=? LIMIT 1",
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            return null;
        }

        $items = $this->app->db->query(
            'SELECT * FROM #__store_order_items WHERE order_id=? ORDER BY id ASC',
            [$orderId]
        )->rows;

        $address = $this->decodeJson($order['shipping_address'] ?? null);
        $billing = $this->decodeJson($order['billing_address'] ?? null);
        if ($billing === []) {
            $billing = $address;
        }

        $shipment = $this->app->db->query(
            'SELECT * FROM #__store_shipments WHERE order_id=? AND tenant_id=? ORDER BY id DESC LIMIT 1',
            [$orderId, $tenantId]
        )->row;

        $channelSettings = $this->decodeJson($order['channel_settings'] ?? null);
        $tenant = $this->app->tenant->tenant() ?? [];

        return [
            'order'    => $order,
            'items'    => $items,
            'address'  => $address,
            'billing'  => $billing,
            'channel'  => [
                'name'     => (string) ($order['channel_name'] ?? ''),
                'code'     => (string) ($order['channel_code'] ?? ''),
                'settings' => $channelSettings,
            ],
            'shipment' => $shipment !== [] ? $shipment : null,
            'company'  => [
                'name'  => (string) ($tenant['name'] ?? $this->app->config->get('app.name', 'StoreFeeder')),
                'email' => (string) ($tenant['contact_email'] ?? ''),
            ],
        ];
    }

    public function invoicesDisabled(array $channelSettings): bool
    {
        return ($channelSettings['disable_invoice'] ?? '0') === '1';
    }

    /** @return array<string,mixed>|null */
    public function shipment(int $shipmentId): ?array
    {
        $row = $this->app->db->query(
            'SELECT * FROM #__store_shipments WHERE id=? AND tenant_id=? LIMIT 1',
            [$shipmentId, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /**
     * CSV rows for courier export.
     *
     * @return list<array<string,string>>
     */
    public function courierExportRows(?string $carrier = null, ?string $fromDate = null, ?string $toDate = null): array
    {
        $tenantId = $this->app->tenant->id();
        $where = 's.tenant_id=? AND s.tracking_number <> \'\'';
        $params = [$tenantId];
        if ($carrier !== null && $carrier !== '') {
            $where .= ' AND s.carrier_code=?';
            $params[] = $carrier;
        }
        if ($fromDate !== null && $fromDate !== '') {
            $where .= ' AND DATE(s.despatched_at) >= ?';
            $params[] = $fromDate;
        }
        if ($toDate !== null && $toDate !== '') {
            $where .= ' AND DATE(s.despatched_at) <= ?';
            $params[] = $toDate;
        }

        $rows = $this->app->db->query(
            "SELECT s.tracking_number, s.carrier_code, s.service_code, s.weight_kg, s.package_count,
                    s.despatched_at, o.id AS order_id, o.external_order_id, o.shipping_name,
                    o.buyer_email, o.shipping_address, o.total, o.currency
             FROM #__store_shipments s
             INNER JOIN #__store_orders o ON o.id = s.order_id AND o.tenant_id = s.tenant_id
             WHERE {$where}
             ORDER BY s.id ASC",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $r) {
            $addr = $this->decodeJson($r['shipping_address'] ?? null);
            $out[] = [
                'order_id'         => (string) ($r['order_id'] ?? ''),
                'external_order_id'=> (string) ($r['external_order_id'] ?? ''),
                'tracking_number'  => (string) ($r['tracking_number'] ?? ''),
                'carrier_code'     => (string) ($r['carrier_code'] ?? ''),
                'service_code'     => (string) ($r['service_code'] ?? ''),
                'name'             => (string) ($r['shipping_name'] ?? ''),
                'email'            => (string) ($r['buyer_email'] ?? ''),
                'line1'            => (string) ($addr['line1'] ?? ''),
                'city'             => (string) ($addr['city'] ?? ''),
                'postcode'         => (string) ($addr['postcode'] ?? ''),
                'country'          => (string) ($addr['country'] ?? 'GB'),
                'weight_kg'        => (string) ($r['weight_kg'] ?? '0'),
                'package_count'    => (string) ($r['package_count'] ?? '1'),
                'total'            => (string) ($r['total'] ?? ''),
                'currency'         => (string) ($r['currency'] ?? 'GBP'),
                'despatched_at'    => (string) ($r['despatched_at'] ?? ''),
            ];
        }
        return $out;
    }

    /** @return array<string,string> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_map('strval', $raw);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
