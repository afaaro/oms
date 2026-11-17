<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Integrations\Dpd\DpdConnector;
use App\Integrations\Evri\EvriConnector;
use App\Integrations\ParcelForce\ParcelForceConnector;
use App\Integrations\RoyalMail\RoyalMailConnector;

/**
 * Courier-specific REST Auth APIs (Royal Mail, Evri, ParcelForce, DPD).
 */
final class CouriersApiController extends ApiController
{
    public function index(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $_GET['type'] = 'courier';
        (new ChannelsApiController($this->app))->index();
    }

    public function royalMailAuthInfo(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $this->authInfo('royal_mail', 'Royal Mail Auth API', 'client_credentials', RoyalMailConnector::TOKEN_URL, RoyalMailConnector::API_BASE, [
            'GET  /api/v1/couriers/royal-mail/auth' => 'This help',
            'POST /api/v1/couriers/royal-mail/auth' => 'Issue access_token',
            'POST /api/v1/couriers/royal-mail/test' => 'Auth + API probe',
        ], 'https://developer.royalmail.net/start');
    }

    public function royalMailAuth(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runAuth('royal_mail', new RoyalMailConnector($this->app));
    }

    public function royalMailTest(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runTest('royal_mail', new RoyalMailConnector($this->app));
    }

    public function evriAuthInfo(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $this->authInfo('evri', 'Evri Auth API', 'client_credentials', EvriConnector::TOKEN_URL, EvriConnector::API_BASE, [
            'GET  /api/v1/couriers/evri/auth' => 'This help',
            'POST /api/v1/couriers/evri/auth' => 'Issue access_token',
            'POST /api/v1/couriers/evri/test' => 'Auth + API probe',
        ], 'https://api.business.evri.com/.p2g/index.html');
    }

    public function evriAuth(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runAuth('evri', new EvriConnector($this->app));
    }

    public function evriTest(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runTest('evri', new EvriConnector($this->app));
    }

    public function parcelforceAuthInfo(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $this->authInfo('parcelforce', 'ParcelForce ExpressLink Auth API', 'expresslink_soap', ParcelForceConnector::WSDL_HINT, ParcelForceConnector::TEST_HINT, [
            'GET  /api/v1/couriers/parcelforce/auth' => 'This help',
            'POST /api/v1/couriers/parcelforce/auth' => 'Validate & store ExpressLink username/password',
            'POST /api/v1/couriers/parcelforce/test' => 'Credential check',
        ], 'Contact ParcelForce for ExpressLink + WSDL');
    }

    public function parcelforceAuth(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runAuth('parcelforce', new ParcelForceConnector($this->app));
    }

    public function parcelforceTest(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runTest('parcelforce', new ParcelForceConnector($this->app));
    }

    public function dpdAuthInfo(): void
    {
        if (!$this->requireScope('shipping:read')) {
            return;
        }
        $this->authInfo('dpd', 'DPD Auth API', 'basic_login_geosession', DpdConnector::LOGIN_URL, DpdConnector::API_BASE, [
            'GET  /api/v1/couriers/dpd/auth' => 'This help',
            'POST /api/v1/couriers/dpd/auth' => 'Login — issue geoSession',
            'POST /api/v1/couriers/dpd/test' => 'Auth probe',
        ], 'https://api.dpd.co.uk/');
    }

    public function dpdAuth(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runAuth('dpd', new DpdConnector($this->app));
    }

    public function dpdTest(): void
    {
        if (!$this->requireScope('shipping:manage')) {
            return;
        }
        $this->runTest('dpd', new DpdConnector($this->app));
    }

    /** @param array<string, string> $endpoints */
    private function authInfo(
        string $provider,
        string $label,
        string $grantType,
        string $tokenUrl,
        string $apiBase,
        array $endpoints,
        string $docs,
    ): void {
        $this->ok([
            'provider'   => $provider,
            'label'      => $label,
            'grant_type' => $grantType,
            'token_url'  => $tokenUrl,
            'api_base'   => $apiBase,
            'docs'       => $docs,
            'how'        => [
                '1' => 'Save credentials under Platform → Marketplace Apps → ' . $label,
                '2' => 'POST /api/v1/couriers/' . str_replace('_', '-', $provider === 'royal_mail' ? 'royal-mail' : $provider) . '/auth',
                '3' => 'Pass api_key / api_secret (and account_number where required) or use saved Marketplace Apps values',
            ],
            'endpoints'  => $endpoints,
        ]);
    }

    private function runAuth(string $code, object $connector): void
    {
        $channel = $this->loadCourierChannel($code);
        if ($channel === null) {
            return;
        }
        $settings = $this->mergeCredentialInput($this->decodeSettings($channel['settings'] ?? null));
        if (!method_exists($connector, 'authenticate')) {
            $this->fail('Connector has no Auth API.', 500);
            return;
        }
        /** @var array{ok:bool,message?:string,settings?:array<string,string>,token?:array<string,mixed>} $result */
        $result = $connector->authenticate($settings);
        if (empty($result['ok'])) {
            $this->fail((string) ($result['message'] ?? 'Auth failed'), 401);
            return;
        }
        $newSettings = $result['settings'] ?? $settings;
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ?, active = 1 WHERE id = ? AND tenant_id = ?',
            [json_encode($newSettings, JSON_UNESCAPED_UNICODE), (int) $channel['id'], $this->tenantId()]
        );
        $this->ok([
            'channel_id' => (int) $channel['id'],
            'provider'   => $code,
            'auth'       => $result['token'] ?? null,
            'message'    => (string) ($result['message'] ?? 'OK'),
        ]);
    }

    private function runTest(string $code, object $connector): void
    {
        $channel = $this->loadCourierChannel($code);
        if ($channel === null) {
            return;
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        if (!method_exists($connector, 'testConnection')) {
            $this->fail('Connector has no test API.', 500);
            return;
        }
        /** @var array{ok:bool,message?:string,settings?:array<string,string>} $result */
        $result = $connector->testConnection($settings);
        if (!empty($result['settings'])) {
            $this->app->db->query(
                'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
                [json_encode($result['settings'], JSON_UNESCAPED_UNICODE), (int) $channel['id'], $this->tenantId()]
            );
        }
        if (empty($result['ok'])) {
            $this->fail((string) ($result['message'] ?? 'Test failed'), 422);
            return;
        }
        $this->ok([
            'channel_id' => (int) $channel['id'],
            'provider'   => $code,
            'message'    => (string) $result['message'],
        ]);
    }

    /** @param array<string, string> $settings @return array<string, string> */
    private function mergeCredentialInput(array $settings): array
    {
        foreach (['api_key', 'api_secret', 'client_id', 'client_secret', 'account_number', 'department_id'] as $field) {
            $val = trim((string) $this->app->request->input($field, ''));
            if ($val === '') {
                continue;
            }
            if ($field === 'client_id') {
                $settings['api_key'] = $val;
            } elseif ($field === 'client_secret') {
                $settings['api_secret'] = $val;
            } else {
                $settings[$field] = $val;
            }
        }
        return $settings;
    }

    /** @return array<string, mixed>|null */
    private function loadCourierChannel(string $code): ?array
    {
        $id = (int) $this->app->request->input('id', $this->app->request->get('id', 0));
        if ($id > 0) {
            $row = $this->app->db->query(
                'SELECT * FROM #__store_channels WHERE id = ? AND tenant_id = ? LIMIT 1',
                [$id, $this->tenantId()]
            )->row;
        } else {
            $row = $this->app->db->query(
                'SELECT * FROM #__store_channels WHERE tenant_id = ? AND code = ? LIMIT 1',
                [$this->tenantId(), $code]
            )->row;
        }
        if ($row === []) {
            $this->fail(ucfirst(str_replace('_', ' ', $code)) . ' channel not found. Create it in Integration Manager or pass id.', 404);
            return null;
        }
        $settings = $this->decodeSettings($row['settings'] ?? null);
        $provider = strtolower((string) ($settings['provider'] ?? $row['code'] ?? ''));
        if ($provider !== $code && (string) $row['code'] !== $code) {
            $this->fail('Channel is not a ' . $code . ' courier profile.', 422);
            return null;
        }
        return $row;
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
}
