<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\Dpd\DpdConnector;
use App\Integrations\Evri\EvriConnector;
use App\Integrations\ParcelForce\ParcelForceConnector;
use App\Integrations\RoyalMail\RoyalMailConnector;

/**
 * Despatch orders: create shipment + tracking, update order status.
 */
final class DespatchService
{
    public function __construct(private App $app) {}

    /**
     * @param list<int> $orderIds
     * @return array{
     *   despatched:int, mapped:int, shipment_ids:list<int>,
     *   stock_warnings:list<string>, fulfillment_warnings:list<string>, label_warnings:list<string>
     * }
     */
    public function despatchOrders(
        array $orderIds,
        string $trackingPrefix = 'RM',
        string $defaultCarrier = 'royal_mail',
        bool $applyRules = true,
        bool $buyCarrierLabel = true
    ): array {
        $tenantId = $this->app->tenant->id();
        $matcher = new ShippingRuleMatcher($this->app->db);
        $inventory = new InventoryService($this->app);
        $inventory->clearWarnings();
        $despatched = 0;
        $mapped = 0;
        $shipmentIds = [];
        $fulfillmentWarnings = [];
        $labelWarnings = [];
        $fulfillment = new MarketplaceFulfillmentService($this->app);

        foreach ($orderIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $order = $this->app->db->query(
                'SELECT * FROM #__store_orders WHERE id = ? AND tenant_id = ? LIMIT 1',
                [$id, $tenantId]
            )->row;
            if ($order === []) {
                continue;
            }
            $status = (string) ($order['status'] ?? '');
            $wasWarehouse = in_array($status, ['awaiting_despatch', 'printed', 'processing'], true);
            if (!$wasWarehouse) {
                // Allow re-label of already despatched if no shipment yet
                if ($status !== 'despatched') {
                    continue;
                }
            }

            $carrier = $defaultCarrier !== '' ? $defaultCarrier : 'royal_mail';
            $service = (string) ($order['mapped_shipping_method'] ?? $order['shipping_method'] ?? '');
            $serviceCode = '';
            if ($applyRules) {
                $addr = $matcher->addressFromOrder($order);
                $match = $matcher->match($tenantId, $order + $addr + [
                    'weight_grams' => (int) round(((float) ($order['order_weight_kg'] ?? 0)) * 1000),
                ]);
                if ($match['method'] !== null) {
                    $carrier = $match['method']['carrier'] !== '' ? (string) $match['method']['carrier'] : $carrier;
                    $serviceCode = trim((string) ($match['method']['service_code'] ?? ''));
                    $service = $serviceCode !== ''
                        ? $serviceCode
                        : (string) ($match['method']['name'] ?? $service);
                    $mapped++;
                    $sizeId = (int) ($match['method']['packaging_size_id'] ?? 0);
                    if ($sizeId > 0 && (int) ($order['packaging_size_id'] ?? 0) <= 0) {
                        $this->app->db->query(
                            'UPDATE #__store_orders SET packaging_size_id=?
                             WHERE id=? AND tenant_id=? AND (packaging_size_id IS NULL OR packaging_size_id=0)',
                            [$sizeId, $id, $tenantId]
                        );
                        $order['packaging_size_id'] = $sizeId;
                    }
                }
            }
            $provider = $this->courierProvider($carrier);
            $labelChannel = null;
            $labelSettings = [];
            if ($buyCarrierLabel && $provider !== '') {
                [$labelChannel, $labelSettings] = $this->resolveCourierChannel($tenantId, $provider);
            }
            if ($serviceCode === '') {
                $serviceCode = $this->guessServiceCode($service, $labelSettings);
            }

            $existingTrack = trim((string) ($order['tracking_number'] ?? ''));
            $track = $existingTrack;
            $labelMeta = [
                'external_consignment_id' => '',
                'label_path'              => '',
                'label_format'            => 'html',
                'meta'                    => null,
                'source'                  => 'synthetic',
            ];

            $useLive = $buyCarrierLabel
                && $existingTrack === ''
                && $provider !== ''
                && $labelChannel !== null;

            if ($useLive && $serviceCode === '') {
                $labelWarnings[] = 'Order #' . $id . ': set a default service code on the ' . $provider . ' channel.';
                $this->app->logger?->warning(
                    'Carrier label skipped for order ' . $id . ': no service code',
                    ['provider' => $provider],
                    $provider
                );
            } elseif ($useLive) {
                $labelResult = $this->buyCarrierLabel($provider, $labelChannel, $labelSettings, $order, $serviceCode);
                if (!empty($labelResult['settings']) && is_array($labelResult['settings'])) {
                    $labelSettings = $labelResult['settings'];
                    $this->persistChannelSettings((int) $labelChannel['id'], $tenantId, $labelSettings);
                }
                if (!empty($labelResult['ok'])) {
                    $track = (string) ($labelResult['tracking'] ?? '');
                    $labelMeta = [
                        'external_consignment_id' => (string) ($labelResult['consignment_id'] ?? ''),
                        'label_path'              => (string) ($labelResult['label_path'] ?? ''),
                        'label_format'            => (string) ($labelResult['label_format'] ?? 'pdf'),
                        'meta'                    => $labelResult['meta'] ?? null,
                        'source'                  => $provider,
                    ];
                } else {
                    $labelWarnings[] = 'Order #' . $id . ': ' . (string) ($labelResult['message'] ?? 'Label failed');
                    $this->app->logger?->warning(
                        ucfirst(str_replace('_', ' ', $provider)) . ' label failed for order ' . $id,
                        ['message' => (string) ($labelResult['message'] ?? '')],
                        $provider
                    );
                }
            }

            if ($track === '') {
                $track = strtoupper(preg_replace('/[^A-Z0-9]/', '', $trackingPrefix) ?: 'SF')
                    . strtoupper(bin2hex(random_bytes(4)));
            }

            $this->app->db->query(
                "UPDATE #__store_orders
                 SET status='despatched', shipped_at=COALESCE(shipped_at, NOW()),
                     tracking_number=?, carrier_code=?, mapped_shipping_method=?, on_hold=0
                 WHERE id=? AND tenant_id=?",
                [$track, $carrier, $service, $id, $tenantId]
            );

            $shipmentId = $this->upsertShipment($tenantId, $id, $carrier, $service, $track, $order, $labelMeta);
            if ($shipmentId > 0) {
                $shipmentIds[] = $shipmentId;
                // Label file may have been written against a temp name before insert — rename if needed.
                if (($labelMeta['label_path'] ?? '') !== '' && ($labelMeta['source'] ?? '') !== 'synthetic') {
                    $this->finalizeLabelPath($tenantId, $shipmentId, $labelMeta);
                }
            }

            // Deduct stock once when leaving the warehouse flow (not on re-label).
            if ($wasWarehouse) {
                $inventory->consumeOrder($id);
                $this->markLinesFullyShipped($id);
            }

            $upload = $fulfillment->uploadForOrder($id, $shipmentId);
            $ustatus = (string) ($upload['status'] ?? '');
            if (in_array($ustatus, ['failed', 'unsupported'], true)) {
                $fulfillmentWarnings[] = 'Order #' . $id . ': ' . (string) ($upload['message'] ?? $ustatus);
            }

            $despatched++;
        }

        return [
            'despatched'            => $despatched,
            'mapped'                => $mapped,
            'shipment_ids'          => $shipmentIds,
            'stock_warnings'        => $inventory->warnings(),
            'fulfillment_warnings'  => $fulfillmentWarnings,
            'label_warnings'        => $labelWarnings,
        ];
    }

    /**
     * Ship specific line quantities (partial ship). Null $shipQtys ships remaining qty on all lines.
     *
     * @param array<int,int>|null $shipQtys order_item_id => qty this shipment
     * @return array{despatched:int,partial:bool,shipment_ids:list<int>,message:string}
     */
    public function despatchPartial(
        int $orderId,
        ?array $shipQtys = null,
        string $trackingPrefix = 'RM',
        string $defaultCarrier = 'royal_mail'
    ): array {
        $tenantId = $this->app->tenant->id();
        $order = $this->app->db->query(
            'SELECT * FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            return ['despatched' => 0, 'partial' => false, 'shipment_ids' => [], 'message' => 'Order not found.'];
        }

        $items = $this->app->db->query(
            'SELECT * FROM #__store_order_items WHERE order_id=? ORDER BY id',
            [$orderId]
        )->rows;
        $toShip = [];
        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $ordered = max(0, (int) ($item['qty'] ?? 0));
            $already = max(0, (int) ($item['qty_shipped'] ?? 0));
            $remaining = max(0, $ordered - $already);
            if ($remaining <= 0) {
                continue;
            }
            $want = $remaining;
            if ($shipQtys !== null) {
                if (!array_key_exists($itemId, $shipQtys)) {
                    continue;
                }
                $want = max(0, min($remaining, (int) $shipQtys[$itemId]));
            }
            if ($want <= 0) {
                continue;
            }
            $toShip[] = ['item_id' => $itemId, 'qty' => $want];
        }
        if ($toShip === []) {
            return ['despatched' => 0, 'partial' => false, 'shipment_ids' => [], 'message' => 'No remaining quantity to ship.'];
        }

        foreach ($toShip as $row) {
            $this->app->db->query(
                'UPDATE #__store_order_items SET qty_shipped = LEAST(qty, COALESCE(qty_shipped,0) + ?)
                 WHERE id=? AND order_id=?',
                [$row['qty'], $row['item_id'], $orderId]
            );
        }

        $fully = $this->isFullyShipped($orderId);
        if ($fully) {
            $result = $this->despatchOrders([$orderId], $trackingPrefix, $defaultCarrier, true, true);
            return [
                'despatched'   => $result['despatched'],
                'partial'      => false,
                'shipment_ids' => $result['shipment_ids'],
                'message'      => 'Fully despatched.',
            ];
        }

        $track = strtoupper(preg_replace('/[^A-Z0-9]/', '', $trackingPrefix) ?: 'SF')
            . strtoupper(bin2hex(random_bytes(4)));
        $this->app->db->query(
            "UPDATE #__store_orders
             SET status='processing', tracking_number=?, carrier_code=?, on_hold=0
             WHERE id=? AND tenant_id=?",
            [$track, $defaultCarrier, $orderId, $tenantId]
        );
        $shipmentId = $this->upsertShipment($tenantId, $orderId, $defaultCarrier, (string) ($order['shipping_method'] ?? ''), $track, $order, [
            'external_consignment_id' => '',
            'label_path'              => '',
            'label_format'            => 'html',
            'meta'                    => ['partial' => true],
            'source'                  => 'partial',
        ]);

        return [
            'despatched'   => 1,
            'partial'      => true,
            'shipment_ids' => $shipmentId > 0 ? [$shipmentId] : [],
            'message'      => 'Partial shipment recorded; order remains open until fully shipped.',
        ];
    }

    private function markLinesFullyShipped(int $orderId): void
    {
        try {
            $this->app->db->query(
                'UPDATE #__store_order_items SET qty_shipped = qty WHERE order_id=?',
                [$orderId]
            );
        } catch (\Throwable) {
            // Column may be missing until migration 034.
        }
    }

    private function isFullyShipped(int $orderId): bool
    {
        try {
            $row = $this->app->db->query(
                'SELECT COUNT(*) AS c FROM #__store_order_items
                 WHERE order_id=? AND COALESCE(qty_shipped,0) < qty',
                [$orderId]
            )->row;
            return (int) ($row['c'] ?? 0) === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param array<string,mixed> $order
     * @param array{
     *   external_consignment_id:string, label_path:string, label_format:string,
     *   meta:mixed, source:string
     * } $labelMeta
     */
    private function upsertShipment(
        int $tenantId,
        int $orderId,
        string $carrier,
        string $service,
        string $tracking,
        array $order,
        array $labelMeta
    ): int {
        $existing = $this->app->db->query(
            "SELECT id FROM #__store_shipments
             WHERE tenant_id=? AND order_id=? AND tracking_number=?
             ORDER BY id DESC LIMIT 1",
            [$tenantId, $orderId, $tracking]
        )->row;

        $weight = (float) ($order['order_weight_kg'] ?? 0);
        $packages = max(1, (int) ($order['package_count'] ?? 1));
        $labelFormat = substr((string) ($labelMeta['label_format'] ?? 'html'), 0, 16);
        $labelPath = substr((string) ($labelMeta['label_path'] ?? ''), 0, 500);
        $consignment = substr((string) ($labelMeta['external_consignment_id'] ?? ''), 0, 128);
        $metaJson = null;
        if (is_array($labelMeta['meta'] ?? null)) {
            $metaJson = json_encode($labelMeta['meta'], JSON_UNESCAPED_UNICODE);
        }

        if ($existing !== []) {
            $sid = (int) $existing['id'];
            $this->app->db->query(
                "UPDATE #__store_shipments
                 SET carrier_code=?, service_code=?, status='labelled',
                     weight_kg=?, package_count=?,
                     label_format=?, label_path=?, external_consignment_id=?,
                     meta=COALESCE(?, meta),
                     labelled_at=COALESCE(labelled_at, NOW()),
                     despatched_at=COALESCE(despatched_at, NOW())
                 WHERE id=? AND tenant_id=?",
                [
                    $carrier, $service, $weight, $packages,
                    $labelFormat, $labelPath, $consignment, $metaJson,
                    $sid, $tenantId,
                ]
            );
            return $sid;
        }

        $ins = $this->app->db->query(
            "INSERT INTO #__store_shipments
             (tenant_id, order_id, carrier_code, service_code, tracking_number, label_format, label_path,
              status, weight_kg, package_count, external_consignment_id, meta, labelled_at, despatched_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'labelled', ?, ?, ?, ?, NOW(), NOW())",
            [
                $tenantId, $orderId, $carrier, $service, $tracking, $labelFormat, $labelPath,
                $weight, $packages, $consignment, $metaJson,
            ]
        );

        return (int) ($ins->insert_id ?: 0);
    }

    /**
     * @return array{0:?array<string,mixed>, 1:array<string,string>}
     */
    private function resolveCourierChannel(int $tenantId, string $provider): array
    {
        $rows = $this->app->db->query(
            "SELECT * FROM #__store_channels
             WHERE tenant_id=? AND type='courier' AND active=1
             ORDER BY id ASC",
            [$tenantId]
        )->rows;

        foreach ($rows as $row) {
            $settings = $this->decodeSettings($row['settings'] ?? null);
            if (!$this->channelMatchesProvider($row, $settings, $provider)) {
                continue;
            }
            if (($settings['linked'] ?? '0') !== '1'
                && ($settings['access_token'] ?? '') === ''
                && ($settings['api_key'] ?? '') === ''
            ) {
                continue;
            }
            return [$row, $settings];
        }

        return [null, []];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $settings
     */
    private function channelMatchesProvider(array $row, array $settings, string $provider): bool
    {
        $fromSettings = $this->courierProvider((string) ($settings['provider'] ?? ''));
        if ($fromSettings !== '') {
            return $fromSettings === $provider;
        }
        $code = strtolower(trim((string) ($row['code'] ?? '')));
        if ($provider === 'royal_mail' && ($code === '' || str_contains($code, 'royal') || $code === 'rm')) {
            return true;
        }
        return $this->courierProvider($code) === $provider;
    }

    private function courierProvider(string $carrier): string
    {
        $c = strtolower(str_replace(['-', ' '], '_', trim($carrier)));
        return match ($c) {
            'royal_mail', 'royalmail', 'rm', 'royal_mail_courier' => 'royal_mail',
            'evri', 'hermes', 'myhermes' => 'evri',
            'dpd', 'dpd_uk', 'dpduk' => 'dpd',
            'parcelforce', 'parcel_force', 'pf' => 'parcelforce',
            default => '',
        };
    }

    /**
     * @param array<string,mixed> $channel
     * @param array<string,string> $settings
     * @param array<string,mixed> $order
     * @return array{
     *   ok:bool, message:string, tracking?:string, consignment_id?:string,
     *   label_path?:string, label_format?:string, meta?:array<string,mixed>,
     *   settings?:array<string,string>
     * }
     */
    private function buyCarrierLabel(string $provider, array $channel, array $settings, array $order, string $serviceCode): array
    {
        $connector = match ($provider) {
            'royal_mail' => new RoyalMailConnector($this->app),
            'evri' => new EvriConnector($this->app),
            'dpd' => new DpdConnector($this->app),
            'parcelforce' => new ParcelForceConnector($this->app),
            default => null,
        };
        if ($connector === null || $channel === []) {
            return ['ok' => false, 'message' => 'No label connector for ' . $provider, 'settings' => $settings];
        }

        $matcher = new ShippingRuleMatcher($this->app->db);
        $addr = $matcher->addressFromOrder($order);

        $items = $this->app->db->query(
            'SELECT sku, name, qty, unit_price FROM #__store_order_items WHERE order_id=? ORDER BY id ASC',
            [(int) $order['id']]
        )->rows;

        $descParts = [];
        $payloadItems = [];
        $weightKg = (float) ($order['order_weight_kg'] ?? 0);
        $itemWeight = $weightKg > 0 && $items !== [] ? round($weightKg / count($items), 3) : 0.1;
        foreach ($items as $item) {
            $name = trim((string) ($item['name'] ?? 'Item'));
            if ($name !== '') {
                $descParts[] = $name;
            }
            $payloadItems[] = [
                'sku'    => (string) ($item['sku'] ?? ''),
                'name'   => $name !== '' ? $name : 'Item',
                'qty'    => max(1, (int) ($item['qty'] ?? 1)),
                'value'  => (float) ($item['unit_price'] ?? 0),
                'weight' => $itemWeight,
            ];
        }

        $payload = [
            'reference'     => (string) (($order['external_order_id'] ?? '') !== ''
                ? $order['external_order_id']
                : ('ORD' . (int) $order['id'])),
            'service_code'  => $serviceCode,
            'weight_kg'     => $weightKg,
            'package_count' => max(1, (int) ($order['package_count'] ?? 1)),
            'currency'      => (string) ($order['currency'] ?? 'GBP'),
            'total'         => (float) ($order['total'] ?? 0),
            'description'   => substr(implode(', ', $descParts), 0, 70) ?: 'Goods',
            'address'       => [
                'contact_name' => (string) ($order['shipping_name'] ?? ''),
                'phone'        => (string) ($order['shipping_phone'] ?? $order['buyer_phone'] ?? ''),
                'email'        => (string) ($order['buyer_email'] ?? ''),
                'line1'        => (string) ($addr['line1'] ?? ''),
                'line2'        => (string) ($this->addressField($order, 'line2')),
                'city'         => (string) ($addr['city'] ?? ''),
                'postcode'     => (string) ($addr['postcode'] ?? ''),
                'country'      => (string) ($addr['country'] ?? 'GB'),
                'company'      => (string) ($this->addressField($order, 'company')),
            ],
            'items'         => $payloadItems,
        ];

        try {
            /** @var array<string,mixed> $res */
            $res = $connector->createLabel($settings, $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'settings' => $settings];
        }

        if (empty($res['ok'])) {
            return [
                'ok'       => false,
                'message'  => (string) ($res['message'] ?? 'Label create failed'),
                'settings' => is_array($res['settings'] ?? null) ? $res['settings'] : $settings,
            ];
        }

        $labelPath = '';
        $labelFormat = strtolower((string) ($res['label_format'] ?? 'pdf'));
        $b64 = trim((string) ($res['label_base64'] ?? ''));
        if ($b64 !== '') {
            $saved = $this->storeLabelPdf(
                $this->app->tenant->id(),
                (int) $order['id'],
                $b64
            );
            if ($saved !== '') {
                $labelPath = $saved;
                $labelFormat = 'pdf';
            }
        }

        return [
            'ok'             => true,
            'message'        => (string) ($res['message'] ?? 'OK'),
            'tracking'       => (string) ($res['tracking'] ?? ''),
            'consignment_id' => (string) ($res['consignment_id'] ?? ''),
            'label_path'     => $labelPath,
            'label_format'   => $labelFormat,
            'meta'           => [
                'source'        => $provider,
                'tracking_url'  => (string) ($res['tracking_url'] ?? ''),
                'service_code'  => $serviceCode,
            ],
            'settings'       => is_array($res['settings'] ?? null) ? $res['settings'] : $settings,
        ];
    }

    private function storeLabelPdf(int $tenantId, int $orderId, string $base64): string
    {
        $raw = base64_decode($base64, true);
        if ($raw === false || $raw === '') {
            return '';
        }
        $dir = rtrim(str_replace('\\', '/', $this->app->basePath), '/')
            . '/storage/labels/' . $tenantId;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $rel = 'storage/labels/' . $tenantId . '/order_' . $orderId . '_' . time() . '.pdf';
        $abs = rtrim(str_replace('\\', '/', $this->app->basePath), '/') . '/' . $rel;
        if (@file_put_contents($abs, $raw) === false) {
            return '';
        }
        return $rel;
    }

    /**
     * @param array{
     *   external_consignment_id:string, label_path:string, label_format:string,
     *   meta:mixed, source:string
     * } $labelMeta
     */
    private function finalizeLabelPath(int $tenantId, int $shipmentId, array $labelMeta): void
    {
        $path = (string) ($labelMeta['label_path'] ?? '');
        if ($path === '') {
            return;
        }
        $this->app->db->query(
            'UPDATE #__store_shipments SET label_path=?, label_format=?, external_consignment_id=?
             WHERE id=? AND tenant_id=?',
            [
                substr($path, 0, 500),
                substr((string) ($labelMeta['label_format'] ?? 'pdf'), 0, 16),
                substr((string) ($labelMeta['external_consignment_id'] ?? ''), 0, 128),
                $shipmentId,
                $tenantId,
            ]
        );
    }

    /**
     * @param array<string,string> $settings
     */
    private function guessServiceCode(string $service, array $settings): string
    {
        $s = trim($service);
        if ($s !== '' && $this->looksLikeServiceCode($s)) {
            return $this->normalizeServiceCode($s);
        }
        $fallback = trim((string) ($settings['default_service_code'] ?? ''));
        return $fallback !== '' ? $this->normalizeServiceCode($fallback) : '';
    }

    private function looksLikeServiceCode(string $s): bool
    {
        if (preg_match('/^\d+\^\d+$/', $s) === 1) {
            return true;
        }
        return preg_match('/^[A-Za-z0-9_]{2,16}$/', $s) === 1;
    }

    private function normalizeServiceCode(string $s): string
    {
        if (preg_match('/^\d+\^\d+$/', $s) === 1) {
            return $s;
        }
        return strtoupper($s);
    }

    private function addressField(array $order, string $key): string
    {
        $raw = $order['shipping_address'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return (string) ($decoded[$key] ?? '');
            }
        }
        if (is_array($raw)) {
            return (string) ($raw[$key] ?? '');
        }
        return '';
    }

    /** @param array<string,string> $settings */
    private function persistChannelSettings(int $channelId, int $tenantId, array $settings): void
    {
        unset($settings['_auth_error']);
        $this->app->db->query(
            'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
        );
    }

    /** @return array<string, string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
            }
            return $out;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
        }
        return $out;
    }
}
