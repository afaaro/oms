<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;
use App\Integrations\Ebay\EbayConnector;

/**
 * Pull buyer messages from every linked marketplace / webstore channel.
 */
final class MessageSync
{
    public function __construct(private App $app) {}

    /**
     * @return array{channels:int, imported:int, updated:int, errors:list<string>}
     */
    public function syncAll(int $tenantId, int $days = 14): array
    {
        $channels = $this->app->db->query(
            "SELECT * FROM #__store_channels
             WHERE tenant_id = ? AND type IN ('marketplace','webstore','manual') AND active = 1
             ORDER BY type, name",
            [$tenantId]
        )->rows;

        $registry = new ConnectorRegistry($this->app);
        $importer = new MessageImporter($this->app->db);
        $since = new \DateTimeImmutable('-' . max(1, $days) . ' days', new \DateTimeZone('UTC'));

        $imported = 0;
        $updated = 0;
        $errors = [];
        $touched = 0;

        foreach ($channels as $channel) {
            $settings = $this->decodeSettings($channel['settings'] ?? null);
            if (($settings['linked'] ?? '0') !== '1') {
                continue;
            }
            $connector = $registry->forChannel($channel, $settings);
            if ($connector === null) {
                continue;
            }
            $touched++;
            if ($connector instanceof EbayConnector) {
                try {
                    $settings = $connector->freshSettings($settings);
                } catch (\Throwable $e) {
                    $errors[] = ($channel['code'] ?? '?') . ': ' . $e->getMessage();
                    continue;
                }
                $this->app->db->query(
                    'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                    [json_encode($settings, JSON_UNESCAPED_UNICODE), (int) $channel['id'], $tenantId]
                );
            }
            try {
                $messages = $connector->fetchMessages($settings, $since);
            } catch (\Throwable $e) {
                $errors[] = ($channel['code'] ?? '?') . ': ' . $e->getMessage();
                continue;
            }
            $result = $importer->import(
                $tenantId,
                (int) $channel['id'],
                $connector->key(),
                $messages
            );
            $imported += $result['imported'];
            $updated += $result['updated'];
        }

        return [
            'channels' => $touched,
            'imported' => $imported,
            'updated'  => $updated,
            'errors'   => $errors,
        ];
    }

    /** @return array<string, string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? array_map('strval', $decoded) : [];
        }
        return is_array($raw) ? array_map('strval', $raw) : [];
    }
}
