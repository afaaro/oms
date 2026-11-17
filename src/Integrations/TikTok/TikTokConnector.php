<?php

declare(strict_types=1);

namespace App\Integrations\TikTok;

use App\Integrations\BaseConnector;

final class TikTokConnector extends BaseConnector
{
    public function key(): string
    {
        return 'tiktok';
    }

    public function label(): string
    {
        return 'TikTok Shop';
    }

    public function group(): string
    {
        return 'marketplace';
    }

    public function isConfigured(): bool
    {
        $c = $this->platform();
        return trim((string) ($c['client_id'] ?? $c['app_key'] ?? '')) !== ''
            && trim((string) ($c['client_secret'] ?? $c['app_secret'] ?? '')) !== '';
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
        $c = $this->platform();
        $appKey = (string) ($c['client_id'] ?? $c['app_key'] ?? '');
        $redirect = $this->app->url((string) ($c['redirect_path'] ?? 'integrations/oauth/callback'));
        // TikTok Shop Partner authorization
        return 'https://auth.tiktok-shops.com/oauth/authorize?' . http_build_query([
            'app_key'      => $appKey,
            'state'        => $state,
            'redirect_uri' => $redirect,
        ]);
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            return ['ok' => false, 'message' => 'TikTok did not return a code.'];
        }
        $c = $this->platform();
        $appKey = (string) ($c['client_id'] ?? $c['app_key'] ?? '');
        $appSecret = (string) ($c['client_secret'] ?? $c['app_secret'] ?? '');

        $res = $this->http->request(
            'GET',
            'https://auth.tiktok-shops.com/api/v2/token/get?' . http_build_query([
                'app_key'    => $appKey,
                'app_secret' => $appSecret,
                'auth_code'  => $code,
                'grant_type' => 'authorized_code',
            ]),
            ['Accept' => 'application/json']
        );

        $data = $res['json']['data'] ?? $res['json'] ?? null;
        if (!is_array($data) || empty($data['access_token'])) {
            return ['ok' => false, 'message' => 'TikTok token exchange failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 200)];
        }

        $settings['access_token'] = (string) $data['access_token'];
        $settings['refresh_token'] = (string) ($data['refresh_token'] ?? '');
        $settings['token_expires_at'] = (string) (time() + (int) ($data['access_token_expire_in'] ?? 86400));
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'oauth';
        $settings['provider'] = 'tiktok';
        $settings['linked_user'] = (string) ($data['seller_name'] ?? $data['open_id'] ?? 'tiktok');
        $settings['merchant_id'] = (string) ($data['open_id'] ?? '');
        $settings['last_connected_at'] = date('d/m/Y H:i:s');

        return ['ok' => true, 'settings' => $settings, 'message' => 'TikTok Shop linked.'];
    }

    public function testConnection(array $settings): array
    {
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'TikTok Shop not linked.'];
        }
        return ['ok' => true, 'message' => 'TikTok Shop access token present.'];
    }
}
