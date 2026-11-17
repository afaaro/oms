<?php

declare(strict_types=1);

namespace App\Integrations\Etsy;

use App\Integrations\BaseConnector;

final class EtsyConnector extends BaseConnector
{
    public function key(): string
    {
        return 'etsy';
    }

    public function label(): string
    {
        return 'Etsy';
    }

    public function group(): string
    {
        return 'marketplace';
    }

    public function isConfigured(): bool
    {
        $c = $this->platform();
        return trim((string) ($c['client_id'] ?? '')) !== ''
            && trim((string) ($c['client_secret'] ?? '')) !== '';
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
        $redirect = $this->app->url((string) ($c['redirect_path'] ?? 'integrations/oauth/callback'));
        $codeVerifier = bin2hex(random_bytes(32));
        $this->app->session->set('etsy_code_verifier', $codeVerifier);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return 'https://www.etsy.com/oauth/connect?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => (string) $c['client_id'],
            'redirect_uri'          => $redirect,
            'scope'                 => (string) ($c['scopes'] ?? 'listings_r listings_w shops_r transactions_r transactions_w'),
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        $code = (string) ($query['code'] ?? '');
        if ($code === '') {
            return ['ok' => false, 'message' => 'Etsy did not return a code.'];
        }
        $c = $this->platform();
        $redirect = $this->app->url((string) ($c['redirect_path'] ?? 'integrations/oauth/callback'));
        $verifier = (string) $this->app->session->get('etsy_code_verifier', '');

        $res = $this->http->request(
            'POST',
            'https://api.etsy.com/v3/public/oauth/token',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'grant_type'    => 'authorization_code',
                'client_id'     => (string) $c['client_id'],
                'redirect_uri'  => $redirect,
                'code'          => $code,
                'code_verifier' => $verifier,
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return ['ok' => false, 'message' => 'Etsy token exchange failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 200)];
        }

        $settings['access_token'] = (string) ($res['json']['access_token'] ?? '');
        $settings['refresh_token'] = (string) ($res['json']['refresh_token'] ?? '');
        $settings['token_expires_at'] = (string) (time() + (int) ($res['json']['expires_in'] ?? 3600));
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'oauth';
        $settings['provider'] = 'etsy';
        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        $settings['linked_user'] = (string) ($res['json']['access_token'] ?? 'etsy');

        return ['ok' => true, 'settings' => $settings, 'message' => 'Etsy shop linked.'];
    }

    public function testConnection(array $settings): array
    {
        $token = (string) ($settings['access_token'] ?? '');
        $clientId = (string) ($this->platform()['client_id'] ?? '');
        if ($token === '') {
            return ['ok' => false, 'message' => 'Etsy not linked.'];
        }
        $res = $this->http->request('GET', 'https://openapi.etsy.com/v3/application/users/me', [
            'Authorization'   => 'Bearer ' . $token,
            'x-api-key'       => $clientId,
            'Accept'          => 'application/json',
        ]);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'Etsy API OK.'];
        }
        return ['ok' => false, 'message' => 'Etsy HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 160)];
    }
}
