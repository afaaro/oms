<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\Amazon\AmazonConnector;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\Shopify\ShopifyConnector;

/**
 * Pull channel listings into store_listings. A failed call does not delete existing rows.
 */
final class ListingDownloadService
{
    public function __construct(private App $app) {}

    /** @return array{ok:bool,message:string} */
    public function download(int $channelId): array
    {
        $tenantId = $this->app->tenant->id();
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [$channelId, $tenantId]
        )->row;
        if ($channel === []) {
            return ['ok' => false, 'message' => 'Channel not found.'];
        }
        $settings = $this->decode($channel['settings'] ?? null);
        $provider = $this->provider($settings, (string) ($channel['code'] ?? ''));
        if ($provider === '') {
            return ['ok' => false, 'message' => 'Listing download is only available for eBay, Shopify, and Amazon.'];
        }

        $connector = (new ConnectorRegistry($this->app))->get($provider);
        $result = match (true) {
            $connector instanceof EbayConnector => $connector->fetchListings($settings),
            $connector instanceof ShopifyConnector => $connector->fetchListings($settings),
            $connector instanceof AmazonConnector => $connector->fetchListings($settings),
            default => ['ok' => false, 'message' => 'Listing download is only available for eBay, Shopify, and Amazon.', 'items' => [], 'settings' => $settings],
        };
        $next = is_array($result['settings'] ?? null) ? $result['settings'] : $settings;
        if ($next !== $settings) {
            $this->app->db->query(
                'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                [json_encode($next, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
            );
        }
        if (empty($result['ok'])) {
            return ['ok' => false, 'message' => (string) ($result['message'] ?? 'Listing download failed.')];
        }

        $saved = 0;
        $mapped = 0;
        foreach (($result['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $didMap = $this->upsert($tenantId, $channelId, $item);
            $saved++;
            if ($didMap) {
                $mapped++;
            }
        }

        return [
            'ok'      => true,
            'message' => "Downloaded {$saved} listing(s). {$mapped} matched a local SKU.",
        ];
    }

    /** @param array<string,mixed> $item */
    private function upsert(int $tenantId, int $channelId, array $item): bool
    {
        $external = substr(trim((string) ($item['external_id'] ?? '')), 0, 128);
        $sku = substr(trim((string) ($item['sku'] ?? '')), 0, 64);
        if ($external === '' && $sku === '') {
            return false;
        }
        if ($external === '') {
            $external = $sku;
        }
        $title = substr(trim((string) ($item['title'] ?? $sku)), 0, 255);
        $price = (float) ($item['price'] ?? 0);
        $qty = (int) ($item['qty'] ?? 0);
        $status = substr(trim((string) ($item['status'] ?? 'active')) ?: 'active', 0, 32);

        $productId = 0;
        if ($sku !== '') {
            $product = $this->app->db->query(
                'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
                [$tenantId, $sku]
            )->row;
            $productId = (int) ($product['id'] ?? 0);
        }

        $existing = $this->app->db->query(
            'SELECT id, product_id, mapped FROM #__store_listings WHERE tenant_id=? AND channel_id=? AND external_id=? LIMIT 1',
            [$tenantId, $channelId, $external]
        )->row;

        if ($existing === []) {
            $this->app->db->query(
                'INSERT INTO #__store_listings
                 (tenant_id, channel_id, product_id, external_id, sku, title, price, qty, status, mapped)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [
                    $tenantId,
                    $channelId,
                    $productId > 0 ? $productId : null,
                    $external,
                    $sku,
                    $title,
                    $price,
                    $qty,
                    $status,
                    $productId > 0 ? 1 : 0,
                ]
            );
            return $productId > 0;
        }

        $keepProduct = (int) ($existing['product_id'] ?? 0);
        $keepMapped = (int) ($existing['mapped'] ?? 0) === 1;
        if ($productId > 0) {
            $keepProduct = $productId;
            $keepMapped = true;
        }
        $this->app->db->query(
            'UPDATE #__store_listings
             SET sku=?, title=?, price=?, qty=?, status=?, product_id=?, mapped=?
             WHERE id=? AND tenant_id=?',
            [
                $sku,
                $title,
                $price,
                $qty,
                $status,
                $keepProduct > 0 ? $keepProduct : null,
                $keepMapped ? 1 : 0,
                (int) $existing['id'],
                $tenantId,
            ]
        );
        return $keepMapped;
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

    /** @param array<string,mixed> $settings */
    private function provider(array $settings, string $code): string
    {
        $raw = strtolower(str_replace(['-', ' '], '_', trim((string) ($settings['provider'] ?? $code))));
        return match ($raw) {
            'ebay', 'ebay_uk', 'ebay_us' => 'ebay',
            'shopify', 'shopify_store' => 'shopify',
            'amazon', 'amazon_uk', 'amazon_us', 'amazon_eu', 'amazon_sp' => 'amazon',
            default => '',
        };
    }
}
