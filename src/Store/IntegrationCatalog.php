<?php

declare(strict_types=1);

namespace App\Store;

/**
 * Known StoreFeeder-style integration providers for the New Integration wizard.
 */
final class IntegrationCatalog
{
    /**
     * @return list<array{key:string,label:string,type:string,group:string}>
     */
    public static function providers(): array
    {
        return [
            ['key' => 'amazon', 'label' => 'Amazon', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'ebay', 'label' => 'eBay', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'etsy', 'label' => 'Etsy', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'tiktok', 'label' => 'TikTok Shop', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'temu', 'label' => 'Temu', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'onbuy', 'label' => 'OnBuy', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'bandq', 'label' => 'B&Q', 'type' => 'marketplace', 'group' => 'Marketplace'],
            ['key' => 'shopify', 'label' => 'Shopify', 'type' => 'webstore', 'group' => 'Webstore'],
            ['key' => 'woocommerce', 'label' => 'WooCommerce', 'type' => 'webstore', 'group' => 'Webstore'],
            ['key' => 'magento', 'label' => 'Magento', 'type' => 'webstore', 'group' => 'Webstore'],
            ['key' => 'bigcommerce', 'label' => 'BigCommerce', 'type' => 'webstore', 'group' => 'Webstore'],
            ['key' => 'royal_mail', 'label' => 'Royal Mail', 'type' => 'courier', 'group' => 'Courier'],
            ['key' => 'parcelforce', 'label' => 'ParcelForce', 'type' => 'courier', 'group' => 'Courier'],
            ['key' => 'dpd', 'label' => 'DPD', 'type' => 'courier', 'group' => 'Courier'],
            ['key' => 'evri', 'label' => 'Evri', 'type' => 'courier', 'group' => 'Courier'],
            ['key' => 'amazon_buy_shipping', 'label' => 'Amazon Buy Shipping', 'type' => 'courier', 'group' => 'Courier'],
            ['key' => 'moto', 'label' => 'MOTO / Phone', 'type' => 'manual', 'group' => 'Internal'],
            ['key' => 'csv', 'label' => 'CSV Import', 'type' => 'manual', 'group' => 'Internal'],
        ];
    }

    /** @return array{key:string,label:string,type:string,group:string}|null */
    public static function find(string $key): ?array
    {
        foreach (self::providers() as $p) {
            if ($p['key'] === $key) {
                return $p;
            }
        }
        return null;
    }
}
