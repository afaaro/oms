<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\Dpd\DpdConnector;
use App\Integrations\Evri\EvriConnector;
use App\Integrations\ParcelForce\ParcelForceConnector;

/**
 * Void and manifest for Evri, DPD, and ParcelForce. Royal Mail stays on RoyalMailOps.
 */
final class CourierOps
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
        if ((new RoyalMailOps($this->app))->isRoyalMail((string) ($shipment['carrier_code'] ?? ''))) {
            return (new RoyalMailOps($this->app))->voidShipment($shipmentId);
        }
        $provider = $this->provider((string) ($shipment['carrier_code'] ?? ''));
        if ($provider === '') {
            return ['ok' => false, 'message' => 'This carrier cannot be voided here.'];
        }
        if ((string) ($shipment['status'] ?? '') === 'voided') {
            return ['ok' => false, 'message' => 'Shipment is already voided.'];
        }
        $consignment = trim((string) ($shipment['external_consignment_id'] ?? ''));
        if ($consignment === '') {
            return ['ok' => false, 'message' => 'This shipment has no carrier id to cancel.'];
        }

        [$channel, $settings] = $this->resolveChannel($tenantId, $provider);
        if ($channel === null) {
            return ['ok' => false, 'message' => 'No linked ' . $provider . ' channel.'];
        }
        $result = match ($provider) {
            'evri' => (new EvriConnector($this->app))->voidShipment($settings, $consignment),
            'dpd' => (new DpdConnector($this->app))->voidShipment($settings, $consignment),
            'parcelforce' => (new ParcelForceConnector($this->app))->voidShipment($settings, $consignment),
            default => ['ok' => false, 'message' => 'This carrier cannot be voided here.', 'settings' => $settings],
        };
        $this->persist((int) $channel['id'], $tenantId, $result['settings'] ?? $settings);
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Void failed.')];
        }
        $this->app->db->query(
            "UPDATE #__store_shipments SET status='voided' WHERE id=? AND tenant_id=?",
            [$shipmentId, $tenantId]
        );
        return ['ok' => true, 'message' => (string) $result['message']];
    }

    /** @return array{ok:bool,message:string,manifest_number:string} */
    public function createManifest(string $provider): array
    {
        $tenantId = $this->app->tenant->id();
        [$channel, $settings] = $this->resolveChannel($tenantId, $provider);
        if ($channel === null) {
            return ['ok' => false, 'message' => 'No linked ' . $provider . ' channel.', 'manifest_number' => ''];
        }
        $result = match ($provider) {
            'dpd' => (new DpdConnector($this->app))->createManifest($settings),
            'parcelforce' => (new ParcelForceConnector($this->app))->createManifest($settings),
            default => ['ok' => false, 'message' => 'No carrier manifest for ' . $provider . '.', 'manifest_number' => '', 'settings' => $settings],
        };
        $this->persist((int) $channel['id'], $tenantId, $result['settings'] ?? $settings);
        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'message' => (string) ($result['message'] ?? 'Manifest failed.'),
                'manifest_number' => '',
            ];
        }
        return [
            'ok' => true,
            'message' => (string) ($result['message'] ?? 'Manifest created.'),
            'manifest_number' => (string) ($result['manifest_number'] ?? ''),
        ];
    }

    public function provider(string $carrier): string
    {
        $c = strtolower(str_replace(['-', ' '], '_', trim($carrier)));
        return match ($c) {
            'evri', 'hermes', 'myhermes' => 'evri',
            'dpd', 'dpd_uk', 'dpduk' => 'dpd',
            'parcelforce', 'parcel_force', 'pf' => 'parcelforce',
            default => '',
        };
    }

    /**
     * @return array{0:?array<string,mixed>, 1:array<string,mixed>}
     */
    private function resolveChannel(int $tenantId, string $provider): array
    {
        $rows = $this->app->db->query(
            "SELECT * FROM #__store_channels WHERE tenant_id=? AND type='courier' AND active=1 ORDER BY id ASC",
            [$tenantId]
        )->rows;
        foreach ($rows as $row) {
            $settings = $this->decode($row['settings'] ?? null);
            $from = $this->provider((string) ($settings['provider'] ?? ''));
            $code = $this->provider((string) ($row['code'] ?? ''));
            if ($from !== $provider && $code !== $provider) {
                continue;
            }
            if (($settings['linked'] ?? '0') !== '1' && ($settings['access_token'] ?? '') === '' && ($settings['api_key'] ?? '') === '') {
                continue;
            }
            return [$row, $settings];
        }
        return [null, []];
    }

    /** @param array<string,mixed> $settings */
    private function persist(int $channelId, int $tenantId, array $settings): void
    {
        unset($settings['_auth_error']);
        $this->app->db->query(
            'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
        );
    }

    /** @return array<string,mixed> */
    private function decode(mixed $raw): array
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
