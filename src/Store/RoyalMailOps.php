<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\RoyalMail\RoyalMailConnector;

/**
 * Royal Mail void and manifest calls. Failures do not change local shipment or manifest status.
 */
final class RoyalMailOps
{
    public function __construct(private App $app) {}

    /** @return array{ok:bool,message:string} */
    public function voidShipment(int $shipmentId): array
    {
        $tenantId = $this->app->tenant->id();
        $shipment = $this->app->db->query(
            'SELECT * FROM #__store_shipments WHERE id=? AND tenant_id=? LIMIT 1',
            [$shipmentId, $tenantId]
        )->row;
        if ($shipment === []) {
            return ['ok' => false, 'message' => 'Shipment not found.'];
        }
        if (!$this->isRoyalMail((string) ($shipment['carrier_code'] ?? ''))) {
            return ['ok' => false, 'message' => 'Only Royal Mail shipments can be voided here.'];
        }
        if ((string) ($shipment['status'] ?? '') === 'voided') {
            return ['ok' => false, 'message' => 'Shipment is already voided.'];
        }

        $consignment = trim((string) ($shipment['external_consignment_id'] ?? ''));
        if ($consignment === '') {
            return ['ok' => false, 'message' => 'This shipment has no Royal Mail shipment id to cancel.'];
        }

        [$channel, $settings] = $this->resolveChannel($tenantId);
        if ($channel === null) {
            return ['ok' => false, 'message' => 'No linked Royal Mail channel.'];
        }

        $connector = new RoyalMailConnector($this->app);
        $result = $connector->voidShipment($settings, $consignment);
        $this->persistSettings((int) $channel['id'], $tenantId, $result['settings'] ?? $settings);
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Royal Mail void failed.')];
        }

        $this->app->db->query(
            "UPDATE #__store_shipments SET status='voided' WHERE id=? AND tenant_id=?",
            [$shipmentId, $tenantId]
        );

        return ['ok' => true, 'message' => (string) $result['message']];
    }

    /**
     * Ask Royal Mail to manifest the account. Does not update the local manifest row.
     *
     * @return array{ok:bool,message:string,manifest_number:string}
     */
    public function createManifest(): array
    {
        $tenantId = $this->app->tenant->id();
        [$channel, $settings] = $this->resolveChannel($tenantId);
        if ($channel === null) {
            return ['ok' => false, 'message' => 'No linked Royal Mail channel.', 'manifest_number' => ''];
        }

        $connector = new RoyalMailConnector($this->app);
        $result = $connector->createManifest($settings);
        $this->persistSettings((int) $channel['id'], $tenantId, $result['settings'] ?? $settings);
        if (empty($result['ok'])) {
            return [
                'ok'              => false,
                'message'         => (string) ($result['message'] ?? 'Royal Mail manifest failed.'),
                'manifest_number' => '',
            ];
        }

        return [
            'ok'              => true,
            'message'         => (string) $result['message'],
            'manifest_number' => (string) ($result['manifest_number'] ?? ''),
        ];
    }

    public function isRoyalMail(string $carrier): bool
    {
        $c = strtolower(str_replace(['-', ' '], '_', trim($carrier)));
        return in_array($c, ['royal_mail', 'royalmail', 'rm', 'royal_mail_courier'], true);
    }

    /**
     * @return array{0:?array<string,mixed>, 1:array<string,mixed>}
     */
    private function resolveChannel(int $tenantId): array
    {
        $rows = $this->app->db->query(
            "SELECT * FROM #__store_channels
             WHERE tenant_id=? AND type='courier' AND active=1
             ORDER BY id ASC",
            [$tenantId]
        )->rows;

        foreach ($rows as $row) {
            $settings = $this->decodeSettings($row['settings'] ?? null);
            $provider = strtolower(str_replace(['-', ' '], '_', trim((string) ($settings['provider'] ?? ''))));
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            $match = $this->isRoyalMail($provider)
                || ($provider === '' && ($code === '' || str_contains($code, 'royal') || $code === 'rm'));
            if (!$match) {
                continue;
            }
            if (($settings['linked'] ?? '0') !== '1' && ($settings['access_token'] ?? '') === '' && ($settings['client_id'] ?? '') === '') {
                continue;
            }
            return [$row, $settings];
        }

        return [null, []];
    }

    /** @param array<string,mixed> $settings */
    private function persistSettings(int $channelId, int $tenantId, array $settings): void
    {
        unset($settings['_auth_error']);
        $this->app->db->query(
            'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
        );
    }

    /** @return array<string,mixed> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
