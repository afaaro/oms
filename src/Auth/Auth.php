<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use App\Core\Session;

final class Auth
{
    private ?array $user = null;
    private bool $resolved = false;
    private ?array $aclCache = null;
    private Acl $aclService;

    public function __construct(
        private Database $db,
        private Session $session,
    ) {
        $this->aclService = new Acl($db);
    }

    public function aclService(): Acl
    {
        return $this->aclService;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }
        $this->resolved = true;

        $id = (int) $this->session->get('user_id', 0);
        if ($id <= 0) {
            return $this->user = null;
        }

        $row = $this->db->query(
            'SELECT id, email, username, password_hash, display_name, status, is_platform, last_login_at
             FROM #__users WHERE id = ? LIMIT 1',
            [$id]
        )->row;

        if ($row === [] || (string) ($row['status'] ?? '') !== 'active') {
            $this->session->remove('user_id');
            return $this->user = null;
        }

        return $this->user = $row;
    }

    public function id(): ?int
    {
        $u = $this->user();
        return $u ? (int) $u['id'] : null;
    }

    public function login(array $user): void
    {
        session_regenerate_id(true);
        $this->session->set('user_id', (int) $user['id']);

        $this->session->remove('api_token_id');
        $this->session->remove('api_token_scopes');

        $this->session->remove('impersonate_tenant_id');
        $this->session->remove('impersonate_return_tenant_id');
        $tenantId = $this->resolvePrimaryTenantId((int) $user['id'], !empty($user['is_platform']));
        $this->session->set('active_tenant_id', $tenantId);
        $this->user = $user;
        $this->resolved = true;
        $this->aclCache = null;
    }

    public function logout(): void
    {
        $this->session->remove('user_id');

        $this->session->remove('api_token_id');
        $this->session->remove('api_token_scopes');
        
        $this->session->remove('active_tenant_id');
        $this->session->remove('impersonate_tenant_id');
        $this->session->remove('impersonate_return_tenant_id');
        $this->user = null;
        $this->resolved = true;
        $this->aclCache = null;
        session_regenerate_id(true);
    }

    /**
     * Prefer the user's own tenant membership; platform users fall back to tenant 1.
     */
    public function resolvePrimaryTenantId(int $userId, bool $isPlatform = false): int
    {
        $row = $this->db->query(
            'SELECT tenant_id FROM #__store_tenant_users WHERE user_id = ? ORDER BY tenant_id ASC LIMIT 1',
            [$userId]
        )->row;
        if ($row !== []) {
            return max(1, (int) ($row['tenant_id'] ?? 1));
        }
        return $isPlatform ? 1 : 1;
    }

    public function userBelongsToTenant(int $userId, int $tenantId): bool
    {
        if ($tenantId <= 0 || $userId <= 0) {
            return false;
        }
        $row = $this->db->query(
            'SELECT user_id FROM #__store_tenant_users WHERE user_id = ? AND tenant_id = ? LIMIT 1',
            [$userId, $tenantId]
        )->row;
        return $row !== [];
    }

    /** @return array<string, mixed>|null */
    public function findByLogin(string $login): ?array
    {
        $login = trim($login);
        if ($login === '') {
            return null;
        }

        $row = $this->db->query(
            'SELECT id, email, username, password_hash, display_name, status, is_platform, last_login_at
             FROM #__users
             WHERE email = ? OR username = ?
             LIMIT 1',
            [$login, $login]
        )->row;

        return $row !== [] ? $row : null;
    }

    public function touchLastLogin(int $userId): void
    {
        $this->db->query(
            'UPDATE #__users SET last_login_at = NOW() WHERE id = ?',
            [$userId]
        );
    }

    /**
     * Authenticate an API bearer token (does not regenerate the session).
     */
    public function attemptApiToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || !str_starts_with($token, 'sf_')) {
            return false;
        }

        $hash = hash('sha256', $token);
        $row = $this->db->query(
            'SELECT t.id, t.tenant_id, t.user_id, t.scopes, t.expires_at, t.revoked_at,
                    u.email, u.username, u.display_name, u.status, u.is_platform, u.password_hash, u.last_login_at
             FROM #__store_api_tokens t
             INNER JOIN #__users u ON u.id = t.user_id
             WHERE t.token_hash = ?
             LIMIT 1',
            [$hash]
        )->row;

        if ($row === [] || (string) ($row['status'] ?? '') !== 'active') {
            return false;
        }
        if (!empty($row['revoked_at'])) {
            return false;
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            return false;
        }

        $this->db->query('UPDATE #__store_api_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $row['id']]);
        $this->session->set('user_id', (int) $row['user_id']);
        $this->session->set('active_tenant_id', (int) $row['tenant_id']);
        $this->session->set('api_token_id', (int) $row['id']);
        $this->session->set('api_token_scopes', (string) ($row['scopes'] ?? '*'));

        $this->user = [
            'id'            => (int) $row['user_id'],
            'email'         => $row['email'],
            'username'      => $row['username'],
            'password_hash' => $row['password_hash'],
            'display_name'  => $row['display_name'],
            'status'        => $row['status'],
            'is_platform'   => $row['is_platform'],
            'last_login_at' => $row['last_login_at'],
        ];
        $this->resolved = true;
        $this->aclCache = null;
        return true;
    }

    /**
     * Issue a new API token for a user/tenant. Returns the plaintext token once.
     */
    public function issueApiToken(int $userId, int $tenantId, string $name = 'api', string $scopes = '*'): string
    {
        $plain = 'sf_' . bin2hex(random_bytes(24));
        $this->db->query(
            'INSERT INTO #__store_api_tokens (tenant_id, user_id, name, token_hash, token_prefix, scopes)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $userId,
                $name,
                hash('sha256', $plain),
                substr($plain, 0, 10),
                $scopes,
            ]
        );
        return $plain;
    }

    public function isPlatform(): bool
    {
        $u = $this->user();
        return $u !== null && !empty($u['is_platform']);
    }

    /** @return array<string, mixed> */
    public function acl(): array
    {
        if ($this->aclCache !== null) {
            return $this->aclCache;
        }
        $id = $this->id();
        if ($id === null) {
            return $this->aclCache = [
                'role' => 'guest',
                'page_perms' => [],
                'action_perms' => [],
                'warehouse_ids' => [],
                'channel_ids' => [],
                'flags' => [],
            ];
        }
        $tenantId = (int) $this->session->get('impersonate_tenant_id', 0);
        if ($tenantId <= 0) {
            $tenantId = (int) $this->session->get('active_tenant_id', 0);
        }
        if ($tenantId <= 0) {
            $tenantId = $this->resolvePrimaryTenantId($id, $this->isPlatform());
            $this->session->set('active_tenant_id', $tenantId);
        }

        return $this->aclCache = $this->aclService->forUser($id, $tenantId);
    }

    public function canPage(string $pageKey): bool
    {
        if ($this->isLegacyAdmin()) {
            return true;
        }
        return $this->aclService->canPage($this->acl(), $pageKey);
    }

    public function canAction(string $actionKey): bool
    {
        if ($this->isLegacyAdmin()) {
            return true;
        }
        return $this->aclService->canAction($this->acl(), $actionKey);
    }

    /**
     * Generic gate: page.* → page perms, else action perms, else legacy groups.
     */
    public function can(string $permission): bool
    {
        if ($this->isLegacyAdmin()) {
            return true;
        }
        if (str_starts_with($permission, 'page.')) {
            return $this->canPage($permission);
        }
        if ($this->canAction($permission)) {
            return true;
        }
        // Fallback legacy group JSON (during transition)
        return $this->legacyGroupCan($permission);
    }

    private function isLegacyAdmin(): bool
    {
        $id = $this->id();
        if ($id === null) {
            return false;
        }
        $acl = $this->acl();
        if ($this->aclService->hasGrantAll($acl)) {
            return true;
        }
        $groups = $this->db->query(
            'SELECT g.level FROM #__user_group_members m
             INNER JOIN #__user_groups g ON g.id = m.group_id
             WHERE m.user_id = ?',
            [$id]
        )->rows;
        foreach ($groups as $g) {
            if ((int) ($g['level'] ?? 0) <= -102) {
                return true;
            }
        }
        return false;
    }

    private function legacyGroupCan(string $permission): bool
    {
        $id = $this->id();
        if ($id === null) {
            return false;
        }
        $groups = $this->db->query(
            'SELECT g.permissions FROM #__user_group_members m
             INNER JOIN #__user_groups g ON g.id = m.group_id
             WHERE m.user_id = ?',
            [$id]
        )->rows;
        foreach ($groups as $g) {
            $perms = json_decode((string) ($g['permissions'] ?? '[]'), true);
            if (!is_array($perms)) {
                continue;
            }
            foreach ($perms as $p) {
                $p = (string) $p;
                if ($p === '*' || $p === $permission) {
                    return true;
                }
                if (str_ends_with($p, '.*') && str_starts_with($permission, substr($p, 0, -1))) {
                    return true;
                }
            }
        }
        return false;
    }
}
