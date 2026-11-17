<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\ConnectorRegistry;

/**
 * Push local despatch tracking to the order's marketplace channel.
 */
final class MarketplaceFulfillmentService
{
    public function __construct(private App $app) {}

    /**
     * Retry channel upload for many shipments / orders.
     *
     * @param list<array{order_id:int, shipment_id?:int}>|list<int> $targets
     *        Either list of order ids, or list of ['order_id'=>…, 'shipment_id'=>…]
     * @return array{uploaded:int, failed:int, skipped:int, messages:list<string>}
     */
    public function retryMany(array $targets): array
    {
        $uploaded = 0;
        $failed = 0;
        $skipped = 0;
        $messages = [];

        foreach ($targets as $target) {
            if (is_int($target) || (is_string($target) && ctype_digit((string) $target))) {
                $orderId = (int) $target;
                $shipmentId = 0;
            } elseif (is_array($target)) {
                $orderId = (int) ($target['order_id'] ?? 0);
                $shipmentId = (int) ($target['shipment_id'] ?? 0);
            } else {
                continue;
            }
            if ($orderId <= 0) {
                continue;
            }
            $result = $this->uploadForOrder($orderId, $shipmentId);
            $status = (string) ($result['status'] ?? '');
            $msg = (string) ($result['message'] ?? $status);
            if ($status === 'uploaded') {
                $uploaded++;
            } elseif (in_array($status, ['skipped', 'unsupported'], true)) {
                $skipped++;
                $messages[] = 'Order #' . $orderId . ': ' . $msg;
            } else {
                $failed++;
                $messages[] = 'Order #' . $orderId . ': ' . $msg;
            }
        }

        return [
            'uploaded' => $uploaded,
            'failed'   => $failed,
            'skipped'  => $skipped,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{status:string, message:string}
     */
    public function uploadForOrder(int $orderId, int $shipmentId = 0): array
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->app->db->query(
            'SELECT * FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            return ['status' => 'failed', 'message' => 'Order not found.'];
        }

        $tracking = trim((string) ($order['tracking_number'] ?? ''));
        if ($tracking === '') {
            $result = ['status' => 'skipped', 'message' => 'No tracking number on order.'];
            $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
            return $result;
        }

        $channelId = (int) ($order['channel_id'] ?? 0);
        if ($channelId <= 0) {
            $result = ['status' => 'skipped', 'message' => 'Order has no channel.'];
            $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
            return $result;
        }

        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [$channelId, $tenantId]
        )->row;
        if ($channel === []) {
            $result = ['status' => 'skipped', 'message' => 'Channel not found.'];
            $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
            return $result;
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        // Default on when key missing (older channels); checkbox stores '0' when off.
        if (array_key_exists('send_despatch', $settings)
            && !in_array(strtolower((string) $settings['send_despatch']), ['1', 'true', 'yes'], true)
        ) {
            $result = ['status' => 'skipped', 'message' => 'Channel send_despatch is off.'];
            $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
            return $result;
        }

        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if ($connector === null) {
            $result = ['status' => 'skipped', 'message' => 'No connector for channel.'];
            $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
            return $result;
        }

        $lines = $this->app->db->query(
            'SELECT sku, name, qty FROM #__store_order_items WHERE order_id=? ORDER BY id ASC',
            [$orderId]
        )->rows;
        $payload = [
            'external_order_id' => (string) ($order['external_order_id'] ?? ''),
            'tracking_number'   => $tracking,
            'carrier_code'      => (string) ($order['carrier_code'] ?? ''),
            'service_code'      => (string) ($order['mapped_shipping_method'] ?? $order['shipping_method'] ?? ''),
            'shipped_at'        => (string) ($order['shipped_at'] ?? date('c')),
            'lines'             => array_map(static function (array $r): array {
                return [
                    'sku'  => (string) ($r['sku'] ?? ''),
                    'name' => (string) ($r['name'] ?? ''),
                    'qty'  => max(1, (int) ($r['qty'] ?? 1)),
                ];
            }, $lines),
        ];

        try {
            $res = $connector->submitFulfillment($settings, $payload);
        } catch (\Throwable $e) {
            $result = ['status' => 'failed', 'message' => $e->getMessage()];
            $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
            return $result;
        }

        if (!empty($res['settings']) && is_array($res['settings'])) {
            $merged = array_merge($settings, $res['settings']);
            $this->app->db->query(
                'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                [json_encode($merged, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
            );
        }

        $status = (string) ($res['status'] ?? (!empty($res['ok']) ? 'uploaded' : 'failed'));
        if ($status === '' && !empty($res['skipped'])) {
            $status = 'skipped';
        }
        if ($status === '' && empty($res['ok'])) {
            $status = 'failed';
        }
        if ($status === '' && !empty($res['ok'])) {
            $status = 'uploaded';
        }

        $result = [
            'status'  => $status,
            'message' => (string) ($res['message'] ?? ''),
        ];
        $this->persistShipment($tenantId, $orderId, $shipmentId, $result);
        return $result;
    }

    /**
     * @param array{status:string, message:string} $result
     */
    private function persistShipment(int $tenantId, int $orderId, int $shipmentId, array $result): void
    {
        $status = substr((string) ($result['status'] ?? ''), 0, 32);
        $error = substr((string) ($result['message'] ?? ''), 0, 512);
        if ($status === 'uploaded') {
            $error = '';
        }

        if ($shipmentId > 0) {
            $this->app->db->query(
                'UPDATE #__store_shipments
                 SET fulfillment_status=?, fulfillment_error=?
                 WHERE id=? AND tenant_id=?',
                [$status, $error, $shipmentId, $tenantId]
            );
            return;
        }

        $row = $this->app->db->query(
            'SELECT id FROM #__store_shipments
             WHERE tenant_id=? AND order_id=?
             ORDER BY id DESC LIMIT 1',
            [$tenantId, $orderId]
        )->row;
        if ($row !== []) {
            $this->app->db->query(
                'UPDATE #__store_shipments
                 SET fulfillment_status=?, fulfillment_error=?
                 WHERE id=? AND tenant_id=?',
                [$status, $error, (int) $row['id'], $tenantId]
            );
        }
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
