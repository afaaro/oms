<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\OrderImporter;
use App\Store\IntegrationCatalog;

final class ChannelsApiController extends ApiController
{
    public function couriers(): void
    {
        $_GET['type'] = 'courier';
        $this->index();
    }

    public function marketplaces(): void
    {
        $_GET['type'] = 'marketplace';
        $this->index();
    }

    public function index(): void
    {
        if (!$this->canManageChannels()) {
            return;
        }
        $type = trim((string) $this->app->request->get('type', ''));
        $provider = trim((string) $this->app->request->get('provider', ''));
        $params = [$this->tenantId()];
        $where = 'tenant_id = ?';
        if ($type !== '') {
            $where .= ' AND type = ?';
            $params[] = $type;
        }
        $rows = $this->app->db->query(
            "SELECT id, code, name, type, active, settings, created_at, updated_at
             FROM #__store_channels WHERE {$where} ORDER BY type, name",
            $params
        )->rows;

        $out = [];
        foreach ($rows as $row) {
            $presented = $this->present($row);
            if ($provider !== '' && strtolower((string) ($presented['provider'] ?? $row['code'])) !== strtolower($provider)
                && strtolower((string) $row['code']) !== strtolower($provider)) {
                continue;
            }
            $out[] = $presented;
        }
        $this->ok($out);
    }

    public function show(): void
    {
        if (!$this->canManageChannels()) {
            return;
        }
        $id = (int) $this->app->request->get('id', 0);
        $row = $this->app->db->query(
            'SELECT id, code, name, type, active, settings, created_at, updated_at
             FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($row === []) {
            $this->fail('Channel not found', 404);
            return;
        }
        $this->ok($this->present($row, true));
    }

    public function catalog(): void
    {
        if (!$this->canManageChannels()) {
            return;
        }
        $registry = new ConnectorRegistry($this->app);
        $providers = [];
        foreach (IntegrationCatalog::providers() as $p) {
            $connector = $registry->get($p['key']);
            $providers[] = [
                'key'         => $p['key'],
                'label'       => $p['label'],
                'type'        => $p['type'],
                'group'       => $p['group'],
                'oauth'       => $connector?->supportsOAuth() ?? false,
                'credentials' => $connector?->supportsCredentials() ?? false,
                'configured'  => $connector?->isConfigured() ?? false,
            ];
        }
        $this->ok($providers);
    }

    public function connectors(): void
    {
        if (!$this->canManageChannels()) {
            return;
        }
        $registry = new ConnectorRegistry($this->app);
        $out = [];
        foreach ($registry->all() as $c) {
            $out[] = [
                'key'         => $c->key(),
                'label'       => $c->label(),
                'group'       => $c->group(),
                'oauth'       => $c->supportsOAuth(),
                'credentials' => $c->supportsCredentials(),
                'configured'  => $c->isConfigured(),
            ];
        }
        $this->ok($out);
    }

    public function connect(): void
    {
        if (!$this->canManageChannels()) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($channel === []) {
            $this->fail('Channel not found', 404);
            return;
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        foreach (['api_key', 'api_secret', 'linked_user', 'store_url', 'provider', 'merchant_id'] as $field) {
            $val = trim((string) $this->app->request->input($field, ''));
            if ($val !== '') {
                $settings[$field] = $val;
            }
        }

        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);

        // OAuth: return authorize URL for the client to open
        if ($connector !== null && $connector->supportsOAuth() && $connector->isConfigured()) {

            $statePayload = [
                'channel_id' => $id,
                'tenant_id'  => $this->tenantId(),
                'provider'   => $connector->key(),
                'nonce'      => bin2hex(random_bytes(16)),
                'exp'        => time() + 900,
            ];

            $state = bin2hex(random_bytes(32));

            $this->app->session->set('oauth_state', $state);
            $this->app->session->set('oauth_state_payload', $statePayload);

            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
            );
            try {
                $authUrl = $connector->authorizationUrl($channel, $settings, $state);
            } catch (\Throwable $e) {
                $this->fail($e->getMessage(), 422);
                return;
            }
            $this->ok([
                'id'               => $id,
                'mode'             => 'oauth',
                'authorization_url'=> $authUrl,
                'callback'         => $this->app->url('integrations/oauth/callback'),
                'provider'         => $connector->key(),
            ]);
            return;
        }

        // Credential connect (Royal Mail, Evri, DPD, ParcelForce, B&Q, Woo, …)
        if ($connector !== null && $connector->supportsCredentials()) {
            $result = $connector->connectCredentials($channel, $settings);
            if (empty($result['ok'])) {
                $this->fail((string) ($result['message'] ?? 'Connect failed'), 422);
                return;
            }
            $newSettings = $result['settings'] ?? $settings;
            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ?, active = 1 WHERE id = ? AND tenant_id = ?',
                [json_encode($newSettings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
            );
            $this->ok([
                'id'                => $id,
                'mode'              => 'credentials',
                'linked'            => true,
                'connection_status' => 'linked',
                'provider'          => $connector->key(),
                'message'           => (string) ($result['message'] ?? 'Linked'),
            ]);
            return;
        }

        // Simulated fallback
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'simulated';
        $settings['last_connected_at'] = date('c');
        if (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? 'channel');
        }
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ?, active = 1 WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
        );
        $this->ok([
            'id'                => $id,
            'mode'              => 'simulated',
            'linked'            => true,
            'connection_status' => 'linked',
            'message'           => 'Linked (simulated — configure Marketplace Apps or pass api_key/api_secret)',
        ]);
    }

    public function unlink(): void
    {
        if (!$this->canManageChannels()) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $channel = $this->app->db->query(
            'SELECT settings FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($channel === []) {
            $this->fail('Channel not found', 404);
            return;
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $settings['linked'] = '0';
        $settings['connection_status'] = 'unlinked';
        $settings['last_connected_at'] = '';
        unset($settings['access_token'], $settings['refresh_token'], $settings['token_expires_at'], $settings['token_type']);
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
        );
        $this->ok(['id' => $id, 'linked' => false, 'connection_status' => 'unlinked']);
    }

    public function toggle(): void
    {

        if (!$this->canManageChannels()) {
            return;
        }

        $id = (int) $this->app->request->input('id', 0);
        $enabled = $this->app->request->input('enabled', null);
        $row = $this->app->db->query(
            'SELECT active FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($row === []) {
            $this->fail('Channel not found', 404);
            return;
        }
        $next = $enabled === null ? (empty($row['active']) ? 1 : 0) : ($enabled ? 1 : 0);
        $this->app->db->query(
            'UPDATE #__store_channels SET active = ? WHERE id = ? AND tenant_id = ?',
            [$next, $id, $this->tenantId()]
        );
        $this->ok(['id' => $id, 'active' => (bool) $next]);
    }

    public function test(): void
    {

        if (!$this->canManageChannels()) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($channel === []) {
            $this->fail('Channel not found', 404);
            return;
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);
        if ($connector === null) {
            $this->fail('No connector for this channel', 422);
            return;
        }
        $result = $connector->testConnection($settings);
        if (!empty($result['settings'])) {
            $settings = array_merge($settings, $result['settings']);
            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
            );
        }
        if (empty($result['ok'])) {
            $this->fail((string) ($result['message'] ?? 'Test failed'), 422);
            return;
        }
        $this->ok(['id' => $id, 'provider' => $connector->key(), 'message' => (string) $result['message']]);
    }

    public function sync(): void
    {

        if (!$this->canManageChannels()) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($channel === []) {
            $this->fail('Channel not found', 404);
            return;
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);
        if ($connector === null) {
            $this->fail('No connector for this channel', 422);
            return;
        }

        if ($connector instanceof EbayConnector) {
            try {
                $settings = $connector->freshSettings($settings);
            } catch (\Throwable $e) {
                $this->fail('Sync failed: ' . $e->getMessage(), 502);
                return;
            }
            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
            );
        }

        // eBay Fulfillment history is ~2 years; "all" stays just inside that limit.
        $daysRaw = strtolower(trim((string) $this->app->request->input('days', 'all')));
        $isAll = ($daysRaw === 'all' || $daysRaw === '');
        $days = $isAll ? 0 : max(1, min(729, (int) $daysRaw));
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $since = $isAll
            ? new \DateTimeImmutable('-2 years +1 day', new \DateTimeZone('UTC'))
            : new \DateTimeImmutable('-' . $days . ' days', new \DateTimeZone('UTC'));
        try {
            $orders = $connector->fetchOrders($settings, $since);
        } catch (\Throwable $e) {
            $this->fail('Sync failed: ' . $e->getMessage(), 502);
            return;
        }

        $result = (new OrderImporter($this->app->db, $this->app))->import(
            $this->tenantId(),
            $id,
            $orders,
            $settings
        );
        $settings['last_sync_at'] = date('c');
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->tenantId()]
        );

        $this->ok([
            'id'         => $id,
            'provider'   => $connector->key(),
            'imported'   => $result['imported'] ?? 0,
            'updated'    => $result['updated'] ?? 0,
            'merged'     => $result['merged'] ?? 0,
            'skipped'    => $result['skipped'] ?? 0,
            'errors'     => $result['errors'] ?? [],
            'synced_at'  => $settings['last_sync_at'],
        ]);
    }

    private function canManageChannels(): bool
    {
        if ($this->app->auth->canPage('page.settings.channels')) {
            return true;
        }

        $this->fail('You do not have permission to manage integrations.', 403);

        return false;
    }

    /** @return array<string, string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? array_map('strval', $decoded) : [];
        }
        if (is_array($raw)) {
            return array_map('strval', $raw);
        }
        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row, bool $full = false): array
    {
        $settings = $this->decodeSettings($row['settings'] ?? null);
        $safe = $settings;
        if (isset($safe['api_key']) && $safe['api_key'] !== '') {
            $k = $safe['api_key'];
            $safe['api_key_hint'] = substr($k, 0, 4) . '…' . substr($k, -4);
            unset($safe['api_key']);
        }
        unset($safe['api_secret'], $safe['access_token'], $safe['refresh_token']);

        $out = [
            'id'                => (int) $row['id'],
            'code'              => $row['code'],
            'name'              => $row['name'],
            'type'              => $row['type'],
            'active'            => !empty($row['active']),
            'provider'          => $safe['provider'] ?? $row['code'],
            'linked'            => ($safe['linked'] ?? '0') === '1',
            'connection_status' => $safe['connection_status'] ?? 'unlinked',
            'auth_mode'         => $safe['auth_mode'] ?? null,
            'last_connected_at' => $safe['last_connected_at'] ?? null,
            'last_sync_at'      => $safe['last_sync_at'] ?? null,
            'created_at'        => $row['created_at'] ?? null,
            'updated_at'        => $row['updated_at'] ?? null,
        ];
        if ($full) {
            $out['settings'] = $safe;
        }
        return $out;
    }
}
