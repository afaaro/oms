<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;

abstract class BaseConnector implements ConnectorInterface
{
    protected IntegrationConfig $cfg;
    protected HttpClient $http;

    public function __construct(protected App $app)
    {
        $this->cfg = new IntegrationConfig($app);
        $httpsOnly = (bool) $app->config->get('app.security.https_only', true);
        $verifySsl = (bool) $app->config->get('app.security.verify_ssl', true);
        $this->http = new HttpClient($httpsOnly, $verifySsl);
    }

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function group(): string;

    public function supportsOAuth(): bool
    {
        return false;
    }

    public function supportsCredentials(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        $c = $this->cfg->provider($this->key());
        if (isset($c['enabled']) && empty($c['enabled'])) {
            return false;
        }
        $id = trim((string) ($c['client_id'] ?? $c['api_key'] ?? ''));
        $secret = trim((string) ($c['client_secret'] ?? $c['api_secret'] ?? ''));
        // Platform app OR per-channel credentials can satisfy "configured"
        return $id !== '' || $secret !== '';
    }

    public function authorizationUrl(array $channel, array $settings, string $state): string
    {
        throw new \RuntimeException($this->label() . ' does not use browser OAuth. Enter API credentials and Connect.');
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        return ['ok' => false, 'message' => $this->label() . ' does not use OAuth callback.'];
    }

    public function connectCredentials(array $channel, array $settings): array
    {
        $platform = $this->cfg->provider($this->key());
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        $apiSecret = trim((string) ($settings['api_secret'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) ($platform['client_id'] ?? $platform['api_key'] ?? ''));
        }
        if ($apiSecret === '') {
            $apiSecret = trim((string) ($platform['client_secret'] ?? $platform['api_secret'] ?? ''));
        }
        if ($apiKey === '' && $apiSecret === '' && $this->group() !== 'manual') {
            return ['ok' => false, 'message' => 'Add API key/secret on this channel or under Marketplace Apps for ' . $this->label() . '.'];
        }

        $settings['api_key'] = $apiKey;
        $settings['api_secret'] = $apiSecret;
        $settings['provider'] = $this->key();
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'credentials';
        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        if (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? $this->key());
        }

        $test = $this->testConnection($settings);
        if (empty($test['ok']) && ($test['soft'] ?? false) !== true) {
            // Still allow link but surface warning
            $settings['connection_status'] = 'linked';
            return [
                'ok'       => true,
                'settings' => $settings,
                'message'  => 'Linked with credentials. Test note: ' . (string) ($test['message'] ?? 'pending live API check'),
            ];
        }

        if (!empty($test['settings'])) {
            $settings = array_merge($settings, $test['settings']);
        }

        return [
            'ok'       => true,
            'settings' => $settings,
            'message'  => $this->label() . ' linked with API credentials.',
        ];
    }

    public function testConnection(array $settings): array
    {
        if (($settings['api_key'] ?? $settings['access_token'] ?? '') === '' && ($settings['api_secret'] ?? '') === '') {
            return ['ok' => false, 'message' => $this->label() . ' has no credentials.'];
        }
        return [
            'ok'      => true,
            'soft'    => true,
            'message' => $this->label() . ' credentials present (live ping not required).',
        ];
    }

    public function fetchOrders(array $settings, \DateTimeInterface $since): array
    {
        return [];
    }

    public function fetchMessages(array $settings, \DateTimeInterface $since): array
    {
        return [];
    }

    public function submitFulfillment(array $settings, array $payload): array
    {
        return [
            'ok'      => true,
            'skipped' => true,
            'status'  => 'skipped',
            'message' => $this->label() . ' does not support fulfillment upload.',
        ];
    }

    /**
     * Cancel an order on the marketplace when supported.
     *
     * @param array<string, string> $settings
     * @param array{external_order_id:string, reason?:string} $payload
     * @return array{ok:bool, message:string, settings?:array<string,string>}
     */
    public function cancelOrder(array $settings, array $payload): array
    {
        return [
            'ok'      => false,
            'message' => $this->label() . ' does not support channel cancel.',
        ];
    }

    /**
     * Issue a refund on the marketplace when supported.
     *
     * @param array<string, string> $settings
     * @param array{external_order_id:string, amount?:float, currency?:string, comments?:string} $payload
     * @return array{ok:bool, message:string, settings?:array<string,string>}
     */
    public function issueRefund(array $settings, array $payload): array
    {
        return [
            'ok'      => false,
            'message' => $this->label() . ' does not support channel refund.',
        ];
    }

    /** @return array<string, mixed> */
    protected function platform(): array
    {
        return $this->cfg->provider($this->key());
    }
}
