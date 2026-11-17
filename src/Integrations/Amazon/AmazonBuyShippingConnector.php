<?php

declare(strict_types=1);

namespace App\Integrations\Amazon;

use App\Integrations\BaseConnector;

/**
 * Amazon Buy Shipping uses the same SP-API / LWA app as Amazon marketplace,
 * but is modelled as a courier channel in Integration Manager.
 */
final class AmazonBuyShippingConnector extends BaseConnector
{
    public function key(): string
    {
        return 'amazon_buy_shipping';
    }

    public function label(): string
    {
        return 'Amazon Buy Shipping';
    }

    public function group(): string
    {
        return 'courier';
    }

    public function isConfigured(): bool
    {
        // Reuse Amazon marketplace app credentials
        $c = $this->cfg->provider('amazon');
        $own = $this->platform();
        $clientId = trim((string) ($own['client_id'] ?? $c['client_id'] ?? ''));
        $secret = trim((string) ($own['client_secret'] ?? $c['client_secret'] ?? ''));
        $appId = trim((string) ($own['application_id'] ?? $c['application_id'] ?? ''));
        return $clientId !== '' && $secret !== '' && $appId !== '';
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    public function supportsCredentials(): bool
    {
        return false;
    }

    public function authorizationUrl(array $channel, array $settings, string $state): string
    {
        // Delegate to Amazon connector auth URL
        return (new AmazonConnector($this->app))->authorizationUrl($channel, $settings, $state);
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        $result = (new AmazonConnector($this->app))->handleCallback($query, $channel, $settings);
        if (!empty($result['ok']) && isset($result['settings']) && is_array($result['settings'])) {
            $result['settings']['provider'] = 'amazon_buy_shipping';
            $result['message'] = 'Amazon Buy Shipping authorised via SP-API.';
        }
        return $result;
    }

    public function testConnection(array $settings): array
    {
        $result = (new AmazonConnector($this->app))->testConnection($settings);
        if (!empty($result['ok'])) {
            $result['message'] = 'Amazon Buy Shipping / SP-API tokens OK.';
        }
        return $result;
    }
}
