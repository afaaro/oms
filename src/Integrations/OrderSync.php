<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;
use DateTimeImmutable;

/**
 * Pull marketplace orders for one tenant. Shared by the scheduler and bin/sync_orders.php.
 */
final class OrderSync
{
    public function __construct(private App $app) {}

    /**
     * @return array{
     *   channels:int,
     *   read:int,
     *   imported:int,
     *   updated:int,
     *   merged:int,
     *   skipped:int,
     *   errors:list<string>,
     *   lines:list<string>
     * }
     */
    public function syncTenant(int $tenantId, DateTimeImmutable $since, int $channelId = 0): array
    {
        $this->app->tenant->setActive($tenantId);
        $params = [$tenantId];
        $sql = "SELECT * FROM #__store_channels
                WHERE tenant_id=? AND type IN ('marketplace','webstore') AND active=1";
        if ($channelId > 0) {
            $sql .= ' AND id=?';
            $params[] = $channelId;
        }
        $channels = $this->app->db->query($sql . ' ORDER BY name', $params)->rows;

        $registry = new ConnectorRegistry($this->app);
        $importer = new OrderImporter($this->app->db, $this->app);

        $read = 0;
        $imported = 0;
        $updated = 0;
        $merged = 0;
        $skipped = 0;
        $touched = 0;
        $errors = [];
        $lines = [];

        foreach ($channels as $channel) {
            $settings = $this->decodeSettings($channel['settings'] ?? null);
            if (($settings['linked'] ?? '0') !== '1') {
                continue;
            }
            if (array_key_exists('import_orders', $settings)
                && !in_array(strtolower((string) $settings['import_orders']), ['1', 'true', 'yes'], true)
            ) {
                $lines[] = "tenant {$tenantId} channel {$channel['id']}: import_orders off";
                continue;
            }
            $connector = $registry->forChannel($channel, $settings);
            if ($connector === null || !method_exists($connector, 'fetchOrders')) {
                continue;
            }
            $touched++;
            try {
                if (method_exists($connector, 'freshSettings')) {
                    $fresh = $connector->freshSettings($settings);
                    if (is_array($fresh)) {
                        $settings = $this->decodeSettings($fresh);
                    }
                }
                $orders = $connector->fetchOrders($settings, $since);
                $result = $importer->import($tenantId, (int) $channel['id'], $orders, $settings);
                $settings['last_sync_at'] = date('c');
                $this->app->db->query(
                    'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                    [json_encode($settings, JSON_UNESCAPED_UNICODE), (int) $channel['id'], $tenantId]
                );
                $count = count($orders);
                $read += $count;
                $imported += (int) ($result['imported'] ?? 0);
                $updated += (int) ($result['updated'] ?? 0);
                $merged += (int) ($result['merged'] ?? 0);
                $skipped += (int) ($result['skipped'] ?? 0);
                $lines[] = sprintf(
                    'tenant %d channel %d: read %d, imported %d, updated %d, merged %d, skipped %d',
                    $tenantId,
                    (int) $channel['id'],
                    $count,
                    $result['imported'] ?? 0,
                    $result['updated'] ?? 0,
                    $result['merged'] ?? 0,
                    $result['skipped'] ?? 0
                );
                foreach (array_slice($result['errors'] ?? [], 0, 5) as $err) {
                    $errors[] = (string) $err;
                    $lines[] = '  error: ' . $err;
                }
            } catch (\Throwable $e) {
                $errors[] = 'channel ' . $channel['id'] . ': ' . $e->getMessage();
                $lines[] = "tenant {$tenantId} channel {$channel['id']}: " . $e->getMessage();
            }
        }

        return [
            'channels' => $touched,
            'read'     => $read,
            'imported' => $imported,
            'updated'  => $updated,
            'merged'   => $merged,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'lines'    => $lines,
        ];
    }

    /** @return array<string, string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v);
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
            $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v);
        }
        return $out;
    }
}
