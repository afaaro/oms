<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\ConnectorInterface;
use App\Integrations\ConnectorRegistry;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\OrderImporter;
use App\Store\IntegrationCatalog;
use App\Store\ListingDownloadService;

final class ChannelsController extends Controller
{
    public function index(): void
    {
        $rows = $this->app->db->query(
            'SELECT id, code, name, type, active, settings, updated_at
             FROM #__store_channels
             WHERE tenant_id = ?
             ORDER BY type, name',
            [$this->app->tenant->id()]
        )->rows;

        $groups = [
            'marketplace' => ['title' => 'Existing Marketplace Integrations', 'rows' => []],
            'webstore'    => ['title' => 'Existing Webstore Integrations', 'rows' => []],
            'courier'     => ['title' => 'Existing Courier Integrations', 'rows' => []],
            'manual'      => ['title' => 'Existing Internal Integrations', 'rows' => []],
        ];
        foreach ($rows as $row) {
            $type = (string) ($row['type'] ?? 'marketplace');
            if (!isset($groups[$type])) {
                $type = 'manual';
            }
            $groups[$type]['rows'][] = $row;
        }

        echo $this->view('admin/settings/channels', [
            'title'      => 'Integration Manager',
            'navSection' => 'settings',
            'groups'     => $groups,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function create(): void
    {
        $providerKey = (string) $this->app->request->get('provider', '');
        $provider = $providerKey !== '' ? IntegrationCatalog::find($providerKey) : null;

        echo $this->view('admin/settings/channel_new', [
            'title'      => 'New Integration',
            'navSection' => 'settings',
            'providers'  => IntegrationCatalog::providers(),
            'provider'   => $provider,
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }

        $providerKey = trim((string) $this->app->request->post('provider', ''));
        $provider = IntegrationCatalog::find($providerKey);
        $type = $provider['type'] ?? trim((string) $this->app->request->post('type', 'marketplace'));
        if (!in_array($type, ['marketplace', 'webstore', 'courier', 'manual'], true)) {
            $type = 'marketplace';
        }

        $name = trim((string) $this->app->request->post('name', ''));
        $code = trim((string) $this->app->request->post('code', ''));
        if ($name === '') {
            $this->app->session->flash('error', 'Profile name is required.');
            $this->redirect('admin/settings/channels/new', $providerKey !== '' ? ['provider' => $providerKey] : []);
        }
        if ($code === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name) ?? 'channel');
            $code = ($provider['key'] ?? $type) . '_' . trim($slug, '_');
        }
        $code = strtolower(preg_replace('/[^a-z0-9_\-]+/i', '_', $code) ?? $code);

        $exists = $this->app->db->query(
            'SELECT id FROM #__store_channels WHERE tenant_id = ? AND code = ? LIMIT 1',
            [$this->app->tenant->id(), $code]
        )->row;
        if ($exists !== []) {
            $this->app->session->flash('error', 'That channel code already exists.');
            $this->redirect('admin/settings/channels/new', $providerKey !== '' ? ['provider' => $providerKey] : []);
        }

        $settings = $this->defaultSettings($type);
        if ($provider !== null) {
            $settings['provider'] = $provider['key'];
        }
        $active = $type === 'courier' ? 0 : 1;

        $ins = $this->app->db->query(
            'INSERT INTO #__store_channels (tenant_id, code, name, type, active, settings)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $this->app->tenant->id(),
                $code,
                $name,
                $type,
                $active,
                json_encode($settings, JSON_UNESCAPED_UNICODE),
            ]
        );
        $this->app->session->flash('success', 'Integration created. Link the account to finish setup.');
        $this->redirect('admin/settings/channels/edit', ['id' => (int) ($ins->insert_id ?: 0)]);
    }

    public function edit(): void
    {
        $channel = $this->loadChannel((int) $this->app->request->get('id', 0));
        if ($channel === null) {
            return;
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);

        echo $this->view('admin/settings/channel_edit', [
            'title'           => (string) $channel['name'] . ' - Integration Details',
            'navSection'      => 'settings',
            'channel'         => $channel,
            'settings'        => $settings,
            'oauthReady'      => $connector !== null && $connector->supportsOAuth() && $connector->isConfigured(),
            'credReady'       => $connector !== null && $connector->supportsCredentials() && $connector->isConfigured(),
            'connectorMode'   => $connector === null ? '' : ($connector->supportsOAuth() ? 'oauth' : 'credentials'),
            'oauthProvider'   => $connector?->label(),
            'appsUrl'         => $this->app->url('platform/marketplace-apps'),
            'flash'           => $this->app->session->flash('success'),
            'error'           => $this->app->session->flash('error'),
            'user'            => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $name = trim((string) $this->app->request->post('name', ''));
        if ($name === '') {
            $this->app->session->flash('error', 'Profile name is required.');
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        $existing = $this->app->db->query(
            'SELECT settings, type FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($existing === []) {
            $this->redirect('admin/settings/channels');
        }

        $settings = $this->decodeSettings($existing['settings'] ?? null);
        $settings = array_merge($settings, $this->settingsFromPost($settings, (string) ($existing['type'] ?? 'marketplace')));

        $this->app->db->query(
            'UPDATE #__store_channels
             SET name = ?, active = ?, settings = ?
             WHERE id = ? AND tenant_id = ?',
            [
                $name,
                $this->app->request->post('active', '') !== '' ? 1 : 0,
                json_encode($settings, JSON_UNESCAPED_UNICODE),
                $id,
                $this->app->tenant->id(),
            ]
        );

        $this->app->session->flash('success', 'Integration saved.');
        $this->redirect('admin/settings/channels/edit', ['id' => $id]);
    }

    /** GET start — used by the Connect link (most reliable in browsers). */
    public function oauthStart(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $this->beginOAuth($id);
    }

    public function connect(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($channel === []) {
            $this->redirect('admin/settings/channels');
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $seller = trim((string) $this->app->request->post('linked_user', ''));
        if ($seller !== '') {
            $settings['linked_user'] = $seller;
        }
        $storeUrl = trim((string) $this->app->request->post('store_url', ''));
        if ($storeUrl !== '') {
            $settings['store_url'] = $storeUrl;
        }
        $providerPost = trim((string) $this->app->request->post('provider', ''));
        if ($providerPost !== '') {
            $settings['provider'] = $providerPost;
        }
        $apiKey = trim((string) $this->app->request->post('api_key', ''));
        if ($apiKey !== '') {
            $settings['api_key'] = $apiKey;
        }
        $apiSecret = trim((string) $this->app->request->post('api_secret', ''));
        if ($apiSecret !== '') {
            $settings['api_secret'] = $apiSecret;
        }

        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);

        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
        );

        if ($connector !== null && $connector->supportsOAuth()) {
            $this->beginOAuth($id, $channel, $settings, $connector);
            return;
        }

        if ($connector !== null && $connector->supportsCredentials()) {
            $result = $connector->connectCredentials($channel, $settings);
            if (!empty($result['ok'])) {
                $newSettings = $result['settings'] ?? $settings;
                $this->app->db->query(
                    'UPDATE #__store_channels SET settings = ?, active = 1 WHERE id = ? AND tenant_id = ?',
                    [json_encode($newSettings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
                );
                $this->app->session->flash('success', (string) ($result['message'] ?? 'Channel linked.'));
                $this->redirect('admin/settings/channels/edit', ['id' => $id]);
            }
            $this->app->session->flash('error', (string) ($result['message'] ?? 'Could not link with API credentials.'));
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        if ($seller === '') {
            $seller = (string) ($settings['linked_user'] ?? '');
        }
        if ($seller === '') {
            $seller = preg_replace('/[^a-z0-9_]+/i', '_', (string) $channel['code']) ?: 'seller';
        }

        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['linked_user'] = $seller;
        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        $settings['auth_mode'] = 'manual';

        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ?, active = 1 WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
        );

        $this->app->session->flash('success', 'Channel activated.');
        $this->redirect('admin/settings/channels/edit', ['id' => $id]);
    }

    /**
     * Build marketplace authorize URL and send the browser there via a visible bridge page
     * (more reliable than a bare Location header on some hosts).
     *
     * @param array<string,mixed>|null $channel
     * @param array<string,string>|null $settings
     */
    private function beginOAuth(
        int $id,
        ?array $channel = null,
        ?array $settings = null,
        ?ConnectorInterface $connector = null
    ): void {
        if ($channel === null) {
            $channel = $this->app->db->query(
                'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
                [$id, $this->app->tenant->id()]
            )->row;
        }
        if ($channel === [] || $channel === null) {
            $this->app->session->flash('error', 'Channel not found.');
            $this->redirect('admin/settings/channels');
        }

        $settings ??= $this->decodeSettings($channel['settings'] ?? null);
        $registry = new ConnectorRegistry($this->app);
        $connector ??= $registry->forChannel($channel, $settings);

        if ($connector === null || !$connector->supportsOAuth()) {
            $this->app->session->flash('error', 'This channel does not support OAuth connect.');
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        if (!$connector->isConfigured()) {
            $this->app->session->flash(
                'error',
                $connector->label() . ' is not configured. Add Client ID, Secret'
                . ($connector->key() === 'ebay' ? ' and RuName' : '')
                . ' under Marketplace Apps, then try Connect again.'
            );
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        $statePayload = [
            'channel_id' => $id,
            'tenant_id'  => $this->app->tenant->id(),
            'provider'   => $connector->key(),
            'exp'        => time() + 900,
        ];

        $state = bin2hex(random_bytes(32));

        $this->app->session->set('oauth_state', $state);
        $this->app->session->set('oauth_state_payload', $statePayload);

        try {
            $authorizeUrl = $connector->authorizationUrl($channel, $settings, $state);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', $e->getMessage());
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        if ($authorizeUrl === '' || !preg_match('#^https://#i', $authorizeUrl)) {
            $this->app->session->flash('error', 'OAuth authorize URL was empty or not HTTPS.');
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        // Persist for support / retry
        $this->app->session->set('oauth_authorize_url', $authorizeUrl);

        echo $this->view('admin/settings/oauth_redirect', [
            'title'        => 'Connecting ' . $connector->label(),
            'navSection'   => 'settings',
            'provider'     => $connector->label(),
            'authorizeUrl' => $authorizeUrl,
            'channelId'    => $id,
            'callbackUrl'  => $this->app->url('integrations/oauth/callback'),
            'user'         => $this->app->auth->user(),
        ]);
        exit;
    }

    public function unlink(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $channel = $this->app->db->query(
            'SELECT settings FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($channel === []) {
            $this->redirect('admin/settings/channels');
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $settings['linked'] = '0';
        $settings['connection_status'] = 'unlinked';
        $settings['last_connected_at'] = '';
        $settings['auth_mode'] = (string) ($settings['auth_mode'] ?? 'oauth');
        unset($settings['access_token'], $settings['refresh_token'], $settings['token_expires_at'], $settings['token_type']);
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Channel unlinked and OAuth tokens cleared.');
        $this->redirect('admin/settings/channels/edit', ['id' => $id]);
    }

    public function testConnection(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($channel === []) {
            $this->redirect('admin/settings/channels');
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        if (($settings['linked'] ?? '0') !== '1') {
            $this->app->session->flash('error', 'Link the channel first, then test the connection.');
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);
        if ($connector !== null && $connector->isConfigured() && ($settings['access_token'] ?? '') !== '') {
            $result = $connector->testConnection($settings);
            if (!empty($result['settings'])) {
                $settings = array_merge($settings, $result['settings']);
            }
            $settings['last_connected_at'] = date('d/m/Y H:i:s');
            $settings['connection_status'] = !empty($result['ok']) ? 'linked' : 'error';
            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
            );
            if (!empty($result['ok'])) {
                $this->app->session->flash('success', (string) $result['message']);
            } else {
                $this->app->session->flash('error', (string) ($result['message'] ?? 'Connection test failed.'));
            }
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        $settings['connection_status'] = 'linked';
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Connection tested (simulated). Last connected: ' . $settings['last_connected_at']);
        $this->redirect('admin/settings/channels/edit', ['id' => $id]);
    }

    public function syncOrders(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($channel === []) {
            $this->redirect('admin/settings/channels');
        }

        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $registry = new ConnectorRegistry($this->app);
        $connector = $registry->forChannel($channel, $settings);
        if ($connector === null || !$connector->isConfigured()) {
            $this->app->session->flash('error', 'Configure marketplace app credentials and connect OAuth before syncing.');
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }
        $hasAuth = ($settings['access_token'] ?? '') !== ''
            || ($settings['refresh_token'] ?? '') !== ''
            || ($settings['api_key'] ?? '') !== ''
            || ($settings['api_secret'] ?? '') !== '';
        if (($settings['linked'] ?? '0') !== '1' || !$hasAuth) {
            $this->app->session->flash('error', 'Channel is not linked with API credentials. Use Connect / Link first.');
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        if ($connector instanceof EbayConnector) {
            try {
                $settings = $connector->freshSettings($settings);
            } catch (\Throwable $e) {
                $this->app->session->flash('error', 'Order sync failed: ' . $e->getMessage());
                $this->redirect('admin/settings/channels/edit', ['id' => $id]);
            }
            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
            );
        }

        // eBay Fulfillment getOrders only returns orders from the last ~2 years.
        // "all" uses -2 years +1 day so the start stays inside eBay's hard limit.
        $daysRaw = strtolower(trim((string) $this->app->request->post('days', 'all')));
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
            if ($connector instanceof EbayConnector) {
                // fetchOrders may refresh the token — keep channel settings current.
                $settings = $connector->freshSettings($settings);
            }
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Order sync failed: ' . $e->getMessage());
            $this->redirect('admin/settings/channels/edit', ['id' => $id]);
        }

        $result = (new OrderImporter($this->app->db, $this->app))->import(
            $this->app->tenant->id(),
            $id,
            $orders,
            $settings
        );
        $settings['last_sync_at'] = date('c');
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $id, $this->app->tenant->id()]
        );

        $window = $isAll ? 'all available (2 years)' : ('last ' . $days . ' days');
        $msg = 'Sync complete (' . $window . '): ' . count($orders) . ' read from channel, '
            . ($result['imported'] ?? 0) . ' imported, '
            . ($result['updated'] ?? 0) . ' updated, '
            . ($result['merged'] ?? 0) . ' merged, '
            . ($result['skipped'] ?? 0) . ' skipped.';
        if (($result['errors'] ?? []) !== []) {
            $msg .= ' Errors: ' . implode('; ', array_slice($result['errors'], 0, 3));
        }
        if (count($orders) === 0) {
            $this->app->session->flash('error', $msg . ' eBay returned no orders for that window.');
        } else {
            $this->app->session->flash('success', $msg);
        }
        $this->redirect('admin/settings/channels/edit', ['id' => $id]);
    }

    public function downloadListings(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $result = (new ListingDownloadService($this->app))->download($id);
        $this->app->session->flash($result['ok'] ? 'success' : 'error', $result['message']);
        $this->redirect('admin/settings/channels/edit', ['id' => $id]);
    }

    public function toggle(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/channels');
        }
        $id = (int) $this->app->request->post('id', 0);
        $row = $this->app->db->query(
            'SELECT active FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($row !== []) {
            $next = empty($row['active']) ? 1 : 0;
            $this->app->db->query(
                'UPDATE #__store_channels SET active = ? WHERE id = ? AND tenant_id = ?',
                [$next, $id, $this->app->tenant->id()]
            );
            $this->app->session->flash('success', $next ? 'Integration turned on.' : 'Integration turned off.');
        }
        $this->redirect('admin/settings/channels');
    }

    /** @return array<string,mixed>|null */
    private function loadChannel(int $id): ?array
    {
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($channel === []) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'settings',
                'message'    => 'Integration not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return null;
        }
        return $channel;
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

    private function normalizeDefaultServiceCode(string $code): string
    {
        $code = trim($code);
        if (preg_match('/^\d+\^\d+$/', $code) === 1) {
            return $code;
        }
        return strtoupper($code);
    }

    /** @return array<string, string> */
    private function defaultSettings(string $type): array
    {
        return [
            'provider'              => $type === 'webstore' ? 'shopify' : ($type === 'courier' ? 'royal_mail' : 'ebay'),
            'store_url'             => '',
            'api_key'               => '',
            'api_secret'            => '',
            'merchant_id'           => '',
            'linked_user'           => '',
            'linked'                => '0',
            'connection_status'     => 'unlinked',
            'auth_mode'             => $type === 'manual' ? 'none' : 'oauth',
            'last_connected_at'     => '',
            'import_orders'         => '1',
            'send_despatch'         => '1',
            'ignore_channel_tax'    => '0',
            'merge_orders'          => '0',
            'disable_invoice'       => '0',
            'handling_days'         => '',
            'enforce_warehouse'     => '0',
            'auto_inventory'        => '1',
            'auto_price'            => '0',
            'auto_listing_download' => '1',
            'upload_listings'       => '0',
            'currency'              => 'GBP',
            'notes'                 => '',
            'shipping_account_id'   => '',
            'shipping_location_id'  => '',
            'default_service_code'  => $type === 'courier' ? 'CRL1' : '',
            'account_number'        => '',
            'department_id'         => '',
            'client_uid'            => '',
            'expresslink_url'       => '',
            'collection_name'       => '',
            'collection_phone'      => '',
            'collection_line1'      => '',
            'collection_city'       => '',
            'collection_postcode'   => '',
            'collection_country'    => 'GB',
        ];
    }

    /**
     * @param array<string, string> $existing
     * @return array<string, string>
     */
    private function settingsFromPost(array $existing, string $type): array
    {
        $flag = function (string $key): string {
            return $this->app->request->post($key, '') !== '' ? '1' : '0';
        };

        // API key/secret are optional — blank input keeps the stored value.
        $apiKey = trim((string) $this->app->request->post('api_key', ''));
        $apiSecret = trim((string) $this->app->request->post('api_secret', ''));
        $linkedUser = trim((string) $this->app->request->post('linked_user', ''));

        return [
            'provider'              => trim((string) $this->app->request->post('provider', $existing['provider'] ?? '')),
            'store_url'             => trim((string) $this->app->request->post('store_url', '')),
            'api_key'               => $apiKey !== '' ? $apiKey : (string) ($existing['api_key'] ?? ''),
            'api_secret'            => $apiSecret !== '' ? $apiSecret : (string) ($existing['api_secret'] ?? ''),
            'merchant_id'           => trim((string) $this->app->request->post('merchant_id', '')),
            'linked_user'           => $linkedUser !== '' ? $linkedUser : (string) ($existing['linked_user'] ?? ''),
            'linked'                => (string) ($existing['linked'] ?? '0'),
            'connection_status'     => (string) ($existing['connection_status'] ?? 'unlinked'),
            'auth_mode'             => (string) ($existing['auth_mode'] ?? ($type === 'manual' ? 'none' : 'oauth')),
            'last_connected_at'     => (string) ($existing['last_connected_at'] ?? ''),
            'import_orders'         => $flag('import_orders'),
            'send_despatch'         => $flag('send_despatch'),
            'ignore_channel_tax'    => $flag('ignore_channel_tax'),
            'merge_orders'          => $flag('merge_orders'),
            'disable_invoice'       => $flag('disable_invoice'),
            'handling_days'         => trim((string) $this->app->request->post('handling_days', '')),
            'enforce_warehouse'     => $flag('enforce_warehouse'),
            'auto_inventory'        => $flag('auto_inventory'),
            'auto_price'            => $flag('auto_price'),
            'auto_listing_download' => $flag('auto_listing_download'),
            'upload_listings'       => $flag('upload_listings'),
            'currency'              => trim((string) $this->app->request->post('currency', 'GBP')),
            'notes'                 => trim((string) $this->app->request->post('notes', '')),
            'channel_type'          => $type,
            'shipping_account_id'   => trim((string) $this->app->request->post(
                'shipping_account_id',
                (string) ($existing['shipping_account_id'] ?? '')
            )),
            'shipping_location_id'  => trim((string) $this->app->request->post(
                'shipping_location_id',
                (string) ($existing['shipping_location_id'] ?? '')
            )),
            'default_service_code'  => $this->normalizeDefaultServiceCode((string) $this->app->request->post(
                'default_service_code',
                (string) ($existing['default_service_code'] ?? ($type === 'courier' ? 'CRL1' : ''))
            )),
            'account_number'        => trim((string) $this->app->request->post(
                'account_number',
                (string) ($existing['account_number'] ?? '')
            )),
            'department_id'         => trim((string) $this->app->request->post(
                'department_id',
                (string) ($existing['department_id'] ?? '')
            )),
            'client_uid'            => trim((string) $this->app->request->post(
                'client_uid',
                (string) ($existing['client_uid'] ?? '')
            )),
            'expresslink_url'       => trim((string) $this->app->request->post(
                'expresslink_url',
                (string) ($existing['expresslink_url'] ?? '')
            )),
            'collection_name'       => trim((string) $this->app->request->post(
                'collection_name',
                (string) ($existing['collection_name'] ?? '')
            )),
            'collection_phone'      => trim((string) $this->app->request->post(
                'collection_phone',
                (string) ($existing['collection_phone'] ?? '')
            )),
            'collection_line1'      => trim((string) $this->app->request->post(
                'collection_line1',
                (string) ($existing['collection_line1'] ?? '')
            )),
            'collection_city'       => trim((string) $this->app->request->post(
                'collection_city',
                (string) ($existing['collection_city'] ?? '')
            )),
            'collection_postcode'   => trim((string) $this->app->request->post(
                'collection_postcode',
                (string) ($existing['collection_postcode'] ?? '')
            )),
            'collection_country'    => strtoupper(trim((string) $this->app->request->post(
                'collection_country',
                (string) ($existing['collection_country'] ?? 'GB')
            ))),
        ];
    }
}
