<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;
use App\Integrations\Amazon\AmazonBuyShippingConnector;
use App\Integrations\Amazon\AmazonConnector;
use App\Integrations\Dpd\DpdConnector;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\Etsy\EtsyConnector;
use App\Integrations\Evri\EvriConnector;
use App\Integrations\ParcelForce\ParcelForceConnector;
use App\Integrations\RoyalMail\RoyalMailConnector;
use App\Integrations\Shopify\ShopifyConnector;
use App\Integrations\TikTok\TikTokConnector;

final class ConnectorRegistry
{
    /** @var array<string, ConnectorInterface> */
    private array $map = [];

    /** @var array<string, string> */
    private array $aliases = [
        'ebay_uk' => 'ebay',
        'ebay_us' => 'ebay',
        'amazon_uk' => 'amazon',
        'amazon_us' => 'amazon',
        'amazon_eu' => 'amazon',
        'shopify_store' => 'shopify',
        'woo' => 'woocommerce',
        'woo_commerce' => 'woocommerce',
        'hermes' => 'evri',
        'hermes_evri' => 'evri',
        'dpd_courier' => 'dpd',
        'evri_courier' => 'evri',
        'royal_mail_courier' => 'royal_mail',
        'royalmail' => 'royal_mail',
        'parcel_force' => 'parcelforce',
        'parcelforce_worldwide' => 'parcelforce',
        'b_and_q' => 'bandq',
        'b&q' => 'bandq',
        'diy' => 'bandq',
        'kingfisher' => 'bandq',
    ];

    public function __construct(private App $app)
    {
        $connectors = [
            new EbayConnector($app),
            new AmazonConnector($app),
            new EtsyConnector($app),
            new TikTokConnector($app),
            new ShopifyConnector($app),
            new AmazonBuyShippingConnector($app),

            new GenericCredentialConnector($app, 'temu', 'Temu', 'marketplace', [
                'auth_header' => 'key_secret_headers',
            ]),
            new GenericCredentialConnector($app, 'onbuy', 'OnBuy', 'marketplace', [
                'auth_header' => 'onbuy',
                'orders'      => true,
            ]),
            new GenericCredentialConnector($app, 'bandq', 'B&Q', 'marketplace', [
                'auth_header' => 'key_secret_headers',
                'orders'      => true,
            ]),
            new GenericCredentialConnector($app, 'woocommerce', 'WooCommerce', 'webstore', [
                'auth_header' => 'woo',
                'orders'      => true,
            ]),
            new GenericCredentialConnector($app, 'magento', 'Magento', 'webstore', [
                'auth_header' => 'bearer',
            ]),
            new GenericCredentialConnector($app, 'bigcommerce', 'BigCommerce', 'webstore', [
                'auth_header' => 'key_secret_headers',
            ]),
            new RoyalMailConnector($app),
            new ParcelForceConnector($app),
            new DpdConnector($app),
            new EvriConnector($app),
            // Internal channels — credentials optional / simulated OK
            new GenericCredentialConnector($app, 'moto', 'MOTO / Phone', 'manual', []),
            new GenericCredentialConnector($app, 'csv', 'CSV Import', 'manual', []),
        ];

        foreach ($connectors as $connector) {
            $this->map[$connector->key()] = $connector;
        }
    }

    public function get(string $provider): ?ConnectorInterface
    {
        $provider = strtolower(trim($provider));
        if ($provider === '') {
            return null;
        }
        if (isset($this->map[$provider])) {
            return $this->map[$provider];
        }
        $aliased = $this->aliases[$provider] ?? null;
        if ($aliased !== null && isset($this->map[$aliased])) {
            return $this->map[$aliased];
        }
        return null;
    }

    /** @return list<ConnectorInterface> */
    public function all(): array
    {
        return array_values($this->map);
    }

    /** @return list<ConnectorInterface> */
    public function byGroup(string $group): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (ConnectorInterface $c): bool => $c->group() === $group
        ));
    }

    /**
     * @param array<string, mixed> $channel
     * @param array<string, string> $settings
     */
    public function forChannel(array $channel, array $settings): ?ConnectorInterface
    {
        $provider = strtolower((string) ($settings['provider'] ?? ''));
        if ($provider === '') {
            $provider = strtolower((string) ($channel['code'] ?? ''));
        }
        $found = $this->get($provider);
        if ($found !== null) {
            return $found;
        }
        // Try stripping trailing _profile suffixes: ebay_duzky → ebay
        if (str_contains($provider, '_')) {
            $parts = explode('_', $provider);
            $guess = $parts[0];
            // amazon_buy_shipping must stay intact — already in map
            if ($guess === 'amazon' && str_starts_with($provider, 'amazon_buy')) {
                return $this->get('amazon_buy_shipping');
            }
            return $this->get($guess);
        }
        return null;
    }
}
