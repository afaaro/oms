<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\ConnectorRegistry;

/**
 * Cancel / refund on marketplace channel + update local order.
 */
final class OrderChannelActionService
{
    public function __construct(private App $app) {}

    /**
     * @return array{ok:bool, channel:string, local:bool, message:string}
     */
    public function cancelOnChannelAndLocal(int $orderId, string $reason = 'seller_cancel'): array
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->loadOrder($orderId, $tenantId);
        if ($order === null) {
            return ['ok' => false, 'channel' => 'skipped', 'local' => false, 'message' => 'Order not found.'];
        }

        $channelResult = $this->callChannel($order, 'cancelOrder', [
            'external_order_id' => (string) ($order['external_order_id'] ?? ''),
            'reason'            => $reason,
        ]);

        (new InventoryService($this->app))->releaseOrder($orderId);
        $this->app->db->query(
            "UPDATE #__store_orders SET status='cancelled', on_hold=0 WHERE id=? AND tenant_id=?",
            [$orderId, $tenantId]
        );

        $msg = 'Order cancelled locally.';
        if ($channelResult['status'] === 'ok') {
            $msg = 'Order cancelled on channel and locally.';
        } elseif ($channelResult['message'] !== '') {
            $msg .= ' Channel: ' . $channelResult['message'];
        }

        return [
            'ok'      => true,
            'channel' => $channelResult['status'],
            'local'   => true,
            'message' => $msg,
        ];
    }

    /**
     * @return array{ok:bool, channel:string, local:bool, message:string}
     */
    public function issueRefund(int $orderId, ?float $amount = null, string $comments = ''): array
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->loadOrder($orderId, $tenantId);
        if ($order === null) {
            return ['ok' => false, 'channel' => 'skipped', 'local' => false, 'message' => 'Order not found.'];
        }

        if ((string) ($order['status'] ?? '') === 'refunded') {
            return ['ok' => true, 'channel' => 'skipped', 'local' => true, 'message' => 'Order already refunded.'];
        }

        $refundAmount = $amount !== null ? $amount : (float) ($order['total'] ?? 0);
        $channelResult = $this->callChannel($order, 'issueRefund', [
            'external_order_id' => (string) ($order['external_order_id'] ?? ''),
            'amount'            => $refundAmount,
            'currency'          => (string) ($order['currency'] ?? 'GBP'),
            'comments'          => $comments,
        ]);

        (new InventoryService($this->app))->releaseOrder($orderId);
        $this->app->db->query(
            "UPDATE #__store_orders SET status='refunded', on_hold=0 WHERE id=? AND tenant_id=?",
            [$orderId, $tenantId]
        );

        $msg = 'Order marked refunded locally.';
        if ($channelResult['status'] === 'ok') {
            $msg = 'Refund submitted on channel; order marked refunded.';
        } elseif ($channelResult['message'] !== '') {
            $msg .= ' Channel: ' . $channelResult['message'];
        }

        return [
            'ok'      => true,
            'channel' => $channelResult['status'],
            'local'   => true,
            'message' => $msg,
        ];
    }

    /**
     * @param array<string,mixed> $order
     * @param array<string,mixed> $payload
     * @return array{status:string, message:string}
     */
    private function callChannel(array $order, string $method, array $payload): array
    {
        $tenantId = $this->app->tenant->id();
        $channelId = (int) ($order['channel_id'] ?? 0);
        if ($channelId <= 0) {
            return ['status' => 'skipped', 'message' => 'No channel on order.'];
        }
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [$channelId, $tenantId]
        )->row;
        if ($channel === []) {
            return ['status' => 'skipped', 'message' => 'Channel not found.'];
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if ($connector === null) {
            return ['status' => 'skipped', 'message' => 'No connector for channel.'];
        }
        if (!method_exists($connector, $method)) {
            return ['status' => 'skipped', 'message' => 'Channel does not support ' . $method . '.'];
        }

        try {
            /** @var array{ok?:bool, message?:string, settings?:array<string,string>} $res */
            $res = $connector->{$method}($settings, $payload);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        if (!empty($res['settings']) && is_array($res['settings'])) {
            $merged = array_merge($settings, $res['settings']);
            $this->app->db->query(
                'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                [json_encode($merged, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
            );
        }

        if (!empty($res['ok'])) {
            return ['status' => 'ok', 'message' => (string) ($res['message'] ?? '')];
        }
        return [
            'status'  => 'error',
            'message' => (string) ($res['message'] ?? 'Channel action failed.'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadOrder(int $orderId, int $tenantId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT * FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        return $row !== [] ? $row : null;
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
