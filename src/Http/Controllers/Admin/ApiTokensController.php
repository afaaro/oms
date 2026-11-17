<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class ApiTokensController extends Controller
{
    public function index(): void
    {
        $rows = $this->app->db->query(
            'SELECT t.id, t.name, t.token_prefix, t.scopes, t.last_used_at, t.expires_at, t.revoked_at, t.created_at,
                    u.email, u.display_name
             FROM #__store_api_tokens t
             LEFT JOIN #__users u ON u.id = t.user_id
             WHERE t.tenant_id = ?
             ORDER BY t.id DESC
             LIMIT 100',
            [$this->app->tenant->id()]
        )->rows;

        echo $this->view('admin/settings/api_tokens', [
            'title'      => 'API Tokens',
            'navSection' => 'settings',
            'tokens'     => $rows,
            'docsUrl'    => $this->app->url('api/v1'),
            'issued'     => $this->app->session->flash('api_token_plain'),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/api');
        }

        $name = trim((string) $this->app->request->post('name', 'api'));
        if ($name === '') {
            $name = 'api';
        }

        $userId = (int) ($this->app->auth->id() ?? 0);
        if ($userId <= 0) {
            $this->app->session->flash('error', 'Not signed in.');
            $this->redirect('admin/settings/api');
        }

        $allowedScopes = [
            'orders:read', 'orders:write',
            'catalog:read', 'catalog:write',
            'customers:read', 'customers:write',
            'users:read',
            'channels:read', 'channels:manage',
            'warehouse:read', 'warehouse:manage',
            'shipping:read', 'shipping:manage',
            'reports:read',
            'messages:read', 'messages:manage',
        ];

        $scopesInput = $this->app->request->post('scopes', []);

        if (!is_array($scopesInput)) {
            $scopesInput = [];
        }

        $scopes = array_values(array_unique(array_filter(
            array_map(
                static fn ($scope): string => is_string($scope) ? trim($scope) : '',
                $scopesInput
            )
        )));

        if ($scopes === [] || array_diff($scopes, $allowedScopes) !== []) {
            $this->app->session->flash('error', 'Please select valid API token scopes.');
            $this->redirect('admin/settings/api');
        }

        $plain = $this->app->auth->issueApiToken(
            $userId,
            $this->app->tenant->id(),
            $name,
            implode(',', $scopes)
        );

        $this->app->session->flash('api_token_plain', $plain);
        $this->app->session->flash('success', 'Token created. Copy it now — it will not be shown again.');
        $this->redirect('admin/settings/api');
    }

    public function revoke(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/api');
        }
        $id = (int) $this->app->request->post('id', 0);
        $this->app->db->query(
            'UPDATE #__store_api_tokens SET revoked_at = NOW() WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL',
            [$id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Token revoked.');
        $this->redirect('admin/settings/api');
    }
}
