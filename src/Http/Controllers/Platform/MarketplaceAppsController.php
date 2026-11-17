<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Integrations\ConnectorRegistry;
use App\Integrations\IntegrationConfig;

/**
 * Global developer credentials (storage/integrations.secrets.json).
 * Platform staff only — shared by every tenant.
 */
final class MarketplaceAppsController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        $cfg = new IntegrationConfig($this->app);
        $registry = new ConnectorRegistry($this->app);

        $groups = [
            'marketplace' => ['title' => 'Marketplaces', 'items' => []],
            'webstore'    => ['title' => 'Webstores', 'items' => []],
            'courier'     => ['title' => 'Couriers', 'items' => []],
            'manual'      => ['title' => 'Internal', 'items' => []],
        ];

        foreach ($registry->all() as $c) {
            $group = $c->group();
            if (!isset($groups[$group])) {
                $group = 'manual';
            }
            $groups[$group]['items'][] = [
                'key'         => $c->key(),
                'label'       => $c->label(),
                'configured'  => $c->isConfigured(),
                'oauth'       => $c->supportsOAuth(),
                'credentials' => $c->supportsCredentials(),
                'config'      => $cfg->provider($c->key()),
            ];
        }

        $ebayDeletionEndpoint = $this->app->url('integrations/ebay/account-deletion');
        $ebayCfg = $cfg->provider('ebay');
        if (trim((string) ($ebayCfg['deletion_endpoint'] ?? '')) !== '') {
            $ebayDeletionEndpoint = (string) $ebayCfg['deletion_endpoint'];
        }

        echo $this->platformView('platform/marketplace_apps', [
            'title'                => 'Marketplace Apps',
            'navSection'           => 'marketplace-apps',
            'groups'               => $groups,
            'callback'             => $this->app->url('integrations/oauth/callback'),
            'ebayDeletionEndpoint' => $ebayDeletionEndpoint,
            'flash'                => $this->app->session->flash('success'),
            'error'                => $this->app->session->flash('error'),
        ]);
    }

    public function save(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/marketplace-apps');
        }

        $cfg = new IntegrationConfig($this->app);
        $existing = $cfg->secrets();
        $registry = new ConnectorRegistry($this->app);
        $data = $existing;

        $keep = static function (array $prev, string $key, string $posted): string {
            $posted = trim($posted);
            return $posted !== '' ? $posted : (string) ($prev[$key] ?? '');
        };

        foreach ($registry->all() as $c) {
            $key = $c->key();
            $prev = (array) ($existing[$key] ?? []);
            $row = $prev;

            $row['client_id'] = $keep($prev, 'client_id', (string) $this->app->request->post($key . '_client_id', ''));
            $row['client_secret'] = $keep($prev, 'client_secret', (string) $this->app->request->post($key . '_client_secret', ''));

            foreach (['ru_name', 'application_id', 'seller_central', 'marketplace_id', 'region', 'store_hash', 'account_number', 'client_name', 'site_id', 'supplier_id', 'department_id'] as $extra) {
                if ($this->app->request->post($key . '_' . $extra, null) !== null) {
                    $row[$extra] = trim((string) $this->app->request->post($key . '_' . $extra, ''));
                }
            }
            if ($key === 'ebay') {
                $row['sandbox'] = $this->app->request->post('ebay_sandbox', '') !== '';
                $delToken = trim((string) $this->app->request->post('ebay_deletion_verification_token', ''));
                if ($delToken !== '') {
                    $row['deletion_verification_token'] = $delToken;
                } elseif (trim((string) ($prev['deletion_verification_token'] ?? '')) === '') {
                    $row['deletion_verification_token'] = bin2hex(random_bytes(24));
                }
                if ($this->app->request->post('ebay_deletion_endpoint', null) !== null) {
                    $row['deletion_endpoint'] = trim((string) $this->app->request->post('ebay_deletion_endpoint', ''));
                }
            }
            if ($key === 'amazon') {
                $row['draft'] = $this->app->request->post('amazon_draft', '') !== '';
            }

            $data[$key] = $row;
        }

        $cfg->saveSecrets($data);
        $this->app->session->flash('success', 'Global marketplace / courier / webstore app credentials saved.');
        $this->redirect('platform/marketplace-apps');
    }
}
