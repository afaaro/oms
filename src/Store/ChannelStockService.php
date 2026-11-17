<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\Amazon\AmazonConnector;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\Shopify\ShopifyConnector;

/**
 * Push available quantity (on hand minus allocated) to mapped marketplace listings.
 * Only channels with auto_inventory enabled. Failures are warnings, not rollbacks.
 */
final class ChannelStockService
{
    public function __construct(private App $app) {}

    /**
     * @param list<int> $productIds inventory products whose available qty changed
     * @return list<string> warnings
     */
    public function pushProducts(array $productIds): array
    {
        $tenantId = $this->app->tenant->id();
        $stockIds = $this->stockFamilyIds($tenantId, $productIds);
        if ($stockIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($stockIds), '?'));
        $params = array_merge([$tenantId], $stockIds, $stockIds);
        $listings = $this->app->db->query(
            "SELECT l.id, l.sku, l.external_id, l.product_id, l.channel_id,
                    p.inventory_source_id, p.inventory_unit_factor,
                    c.settings, c.code, c.active
             FROM #__store_listings l
             INNER JOIN #__store_channels c ON c.id = l.channel_id AND c.tenant_id = l.tenant_id
             INNER JOIN #__store_products p ON p.id = l.product_id AND p.tenant_id = l.tenant_id
             WHERE l.tenant_id = ?
               AND l.mapped = 1
               AND l.controlled_by_sf = 1
               AND c.active = 1
               AND (l.product_id IN ({$placeholders}) OR p.inventory_source_id IN ({$placeholders}))",
            $params
        )->rows;

        $warnings = [];
        $registry = new ConnectorRegistry($this->app);
        foreach ($listings as $listing) {
            $settings = $this->decodeSettings($listing['settings'] ?? null);
            if ((string) ($settings['auto_inventory'] ?? '0') !== '1') {
                continue;
            }
            $provider = $this->provider($settings, (string) ($listing['code'] ?? ''));
            if ($provider === '') {
                continue;
            }

            $qty = $this->availableQty(
                $tenantId,
                (int) $listing['product_id'],
                (int) ($listing['inventory_source_id'] ?? 0),
                (float) ($listing['inventory_unit_factor'] ?? 1)
            );
            $sku = trim((string) ($listing['sku'] ?? ''));
            $externalId = trim((string) ($listing['external_id'] ?? ''));

            $connector = $registry->get($provider);
            $result = match (true) {
                $connector instanceof EbayConnector => $connector->pushQuantity($settings, $sku !== '' ? $sku : $externalId, $qty),
                $connector instanceof ShopifyConnector => $connector->pushQuantity($settings, $sku, $qty, $externalId),
                $connector instanceof AmazonConnector => $connector->pushQuantity($settings, $sku !== '' ? $sku : $externalId, $qty),
                default => ['ok' => false, 'message' => '', 'settings' => $settings],
            };
            if (($result['message'] ?? '') === '' && empty($result['ok'])) {
                continue;
            }

            $next = is_array($result['settings'] ?? null) ? $result['settings'] : $settings;
            if ($next !== $settings) {
                $this->app->db->query(
                    'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                    [json_encode($next, JSON_UNESCAPED_UNICODE), (int) $listing['channel_id'], $tenantId]
                );
            }

            if (!empty($result['ok'])) {
                $this->app->db->query(
                    'UPDATE #__store_listings SET qty=? WHERE id=? AND tenant_id=?',
                    [$qty, (int) $listing['id'], $tenantId]
                );
                continue;
            }

            $warnings[] = (string) $result['message'];
        }

        return $warnings;
    }

    /**
     * @param list<int> $productIds
     * @return list<int>
     */
    private function stockFamilyIds(int $tenantId, array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $productId) {
            $productId = (int) $productId;
            if ($productId <= 0) {
                continue;
            }
            $product = $this->app->db->query(
                'SELECT id, inventory_source_id FROM #__store_products WHERE id=? AND tenant_id=? LIMIT 1',
                [$productId, $tenantId]
            )->row;
            if ($product === []) {
                continue;
            }
            $source = (int) ($product['inventory_source_id'] ?? 0);
            $ids[] = $source > 0 ? $source : $productId;
        }

        return array_values(array_unique($ids));
    }

    private function availableQty(int $tenantId, int $productId, int $sourceId, float $factor): int
    {
        $stockId = $sourceId > 0 ? $sourceId : $productId;
        $row = $this->app->db->query(
            'SELECT COALESCE(SUM(qty_on_hand), 0) AS on_hand, COALESCE(SUM(qty_allocated), 0) AS allocated
             FROM #__store_inventory WHERE tenant_id=? AND product_id=?',
            [$tenantId, $stockId]
        )->row;
        $available = max(0, (int) ($row['on_hand'] ?? 0) - (int) ($row['allocated'] ?? 0));
        if ($sourceId > 0) {
            if ($factor <= 0) {
                $factor = 1.0;
            }
            return (int) floor($available / $factor);
        }

        return $available;
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

    /** @param array<string,mixed> $settings */
    private function provider(array $settings, string $code): string
    {
        $raw = strtolower(trim((string) ($settings['provider'] ?? $code)));
        $raw = str_replace(['-', ' '], '_', $raw);
        return match ($raw) {
            'ebay' => 'ebay',
            'shopify' => 'shopify',
            'amazon', 'amazon_uk', 'amazon_sp' => 'amazon',
            default => '',
        };
    }
}
