<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;

final class AuthApiController extends ApiController
{
    public function token(): void
    {
        $max = (int) $this->app->config->get('app.security.login_max_attempts', 10);
        $decay = (int) $this->app->config->get('app.security.login_decay_seconds', 300);
        if ($this->app->security->tooManyAttempts('api_token', $max, $decay)) {
            $this->fail('Too many token requests. Try again later.', 429);
            return;
        }

        $username = trim((string) $this->app->request->input('username', $this->app->request->input('email', '')));
        $password = (string) $this->app->request->input('password', '');
        $name = trim((string) $this->app->request->input('name', 'api'));
        if ($username === '' || $password === '') {
            $this->fail('username and password are required', 422);
            return;
        }

        $user = $this->app->auth->findByLogin($username);
        $hash = is_array($user) ? (string) ($user['password_hash'] ?? '') : '';
        if ($user === null || $hash === '' || !password_verify($password, $hash)) {
            $this->fail('Invalid credentials', 401);
            return;
        }
        if ((string) ($user['status'] ?? '') !== 'active') {
            $this->fail('Account inactive', 403);
            return;
        }

        $tenantId = 1;
        $membership = $this->app->db->query(
            'SELECT tenant_id FROM #__store_tenant_users WHERE user_id = ? ORDER BY tenant_id LIMIT 1',
            [(int) $user['id']]
        )->row;
        if ($membership !== []) {
            $tenantId = (int) $membership['tenant_id'];
        }

        $this->app->security->clearAttempts('api_token');
        $token = $this->app->auth->issueApiToken((int) $user['id'], $tenantId, $name !== '' ? $name : 'api');
        $this->ok([
            'token'      => $token,
            'token_type' => 'Bearer',
            'tenant_id'  => $tenantId,
            'user_id'    => (int) $user['id'],
        ]);
    }
}
