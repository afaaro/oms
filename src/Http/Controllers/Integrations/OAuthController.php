<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\ConnectorRegistry;

final class OAuthController extends Controller
{
    public function callback(): void
    {
        // Match demo channels callback: read OAuth params via Request
        // (GET + typed cast), same as system/library/request.php.
        $state = (string) $this->app->request->get('state', 'string', '');
        $code = (string) $this->app->request->get('code', 'string', '');
        $oauthError = (string) $this->app->request->get('error', 'string', '');
        $oauthErrorDesc = (string) $this->app->request->get('error_description', 'string', '');
        $query = [
            'state'              => $state,
            'code'               => $code,
            'error'              => $oauthError,
            'error_description'  => $oauthErrorDesc,
        ];
        $payload = $this->decodeState($state);

        if ($payload === null) {
            $this->failAndRedirect('Invalid or expired OAuth state. Start Connect again from Integration Manager.');
            return;
        }

        $channelId = (int) ($payload['channel_id'] ?? 0);
        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $provider = (string) ($payload['provider'] ?? '');
        $userId = $this->app->auth->id();

        if (
            $userId === null
            || !$this->app->auth->userBelongsToTenant($userId, $tenantId)
        ) {
            $this->failAndRedirect('You are not authorised to connect this channel.');
            return;
        }

        if ($tenantId > 0) {
            $this->app->tenant->setActive($tenantId);
        }

        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$channelId, $tenantId > 0 ? $tenantId : $this->app->tenant->id()]
        )->row;
        if ($channel === []) {
            $this->failAndRedirect('Channel not found for OAuth callback.');
            return;
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->get($provider) ?? $registry->forChannel($channel, $settings);
        if ($connector === null) {
            $this->failAndRedirect('No connector for provider: ' . $provider);
            return;
        }

        if ($oauthError !== '') {
            $this->failAndRedirect('Marketplace denied access: ' . ($oauthErrorDesc !== '' ? $oauthErrorDesc : $oauthError));
            return;
        }

        $result = $connector->handleCallback($query, $channel, $settings);
        if (empty($result['ok'])) {
            $this->failAndRedirect((string) ($result['message'] ?? 'OAuth failed.'), $channelId);
            return;
        }

        $newSettings = $result['settings'] ?? $settings;
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ?, active = 1 WHERE id = ? AND tenant_id = ?',
            [
                json_encode($newSettings, JSON_UNESCAPED_UNICODE),
                $channelId,
                (int) $channel['tenant_id'],
            ]
        );

        $this->app->session->flash('success', (string) ($result['message'] ?? 'Channel linked via OAuth.'));
        $this->app->session->remove('oauth_state');

        if (!$this->app->auth->check()) {
            $this->app->session->set('redirect_after_login', 'admin/settings/channels/edit?id=' . $channelId);
            $this->app->session->flash('success', (string) ($result['message'] ?? 'Channel linked via OAuth.') . ' Sign in to continue.');
            $this->redirect((string) $this->app->config->get('app.login_route', 'login'));
            return;
        }

        $this->redirect('admin/settings/channels/edit', ['id' => $channelId]);
    }

    /** @return array<string, mixed>|null */
    private function decodeState(string $state): ?array
    {
        if ($state === '' || preg_match('/^[a-f0-9]{64}$/D', $state) !== 1) {
            return null;
        }

        $expected = $this->app->session->get('oauth_state');

        if (
            !is_string($expected)
            || $expected === ''
            || !hash_equals($expected, $state)
        ) {
            return null;
        }

        $data = $this->app->session->get('oauth_state_payload');

        if (!is_array($data)) {
            return null;
        }

        // Consume the state immediately to prevent replay.
        $this->app->session->remove('oauth_state');
        $this->app->session->remove('oauth_state_payload');

        $channelId = (int) ($data['channel_id'] ?? 0);
        $tenantId = (int) ($data['tenant_id'] ?? 0);
        $provider = (string) ($data['provider'] ?? '');
        $expiresAt = (int) ($data['exp'] ?? 0);

        if (
            $channelId < 1
            || $tenantId < 1
            || $provider === ''
            || $expiresAt <= time()
        ) {
            return null;
        }

        return $data;
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

    private function failAndRedirect(string $message, int $channelId = 0): void
    {
        $this->app->session->flash('error', $message);
        if ($channelId > 0 && $this->app->auth->check()) {
            $this->redirect('admin/settings/channels/edit', ['id' => $channelId]);
            return;
        }
        $this->redirect('admin/settings/channels');
    }
}
