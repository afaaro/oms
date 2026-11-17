<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Database;
use App\Core\Session;

/**
 * Active merchant tenant for the request (real membership or platform impersonation).
 */
final class TenantContext
{
    private ?array $tenant = null;
    private bool $resolved = false;

    public function __construct(
        private Database $db,
        private Session $session,
    ) {}

    public function id(): int
    {
        $t = $this->tenant();
        return $t ? (int) $t['id'] : 1;
    }

    public function name(): string
    {
        $t = $this->tenant();
        return $t ? (string) $t['name'] : 'Default';
    }

    public function isImpersonating(): bool
    {
        return (int) $this->session->get('impersonate_tenant_id', 0) > 0;
    }

    public function impersonate(int $tenantId): void
    {
        if ((int) $this->session->get('impersonate_tenant_id', 0) <= 0) {
            $this->session->set(
                'impersonate_return_tenant_id',
                (int) $this->session->get('active_tenant_id', 1) ?: 1
            );
        }
        $this->session->set('impersonate_tenant_id', $tenantId);
        $this->session->set('active_tenant_id', $tenantId);
        $this->resolved = false;
        $this->tenant = null;
    }

    public function stopImpersonating(): void
    {
        $returnId = (int) $this->session->get('impersonate_return_tenant_id', 0);
        $this->session->remove('impersonate_tenant_id');
        $this->session->remove('impersonate_return_tenant_id');
        if ($returnId > 0) {
            $this->session->set('active_tenant_id', $returnId);
        }
        $this->resolved = false;
        $this->tenant = null;
    }

    public function setActive(int $tenantId): void
    {
        $this->session->set('active_tenant_id', $tenantId);
        $this->resolved = false;
        $this->tenant = null;
    }

    /** @return array<string, mixed>|null */
    public function tenant(): ?array
    {
        if ($this->resolved) {
            return $this->tenant;
        }
        $this->resolved = true;

        $id = $this->resolveTenantId();
        $row = $this->db->query(
            'SELECT id, name, slug, status, plan, contact_email, support_notes, created_at
             FROM #__store_tenants WHERE id = ? LIMIT 1',
            [$id]
        )->row;

        return $this->tenant = ($row !== [] ? $row : null);
    }

    private function resolveTenantId(): int
    {
        $impersonateId = (int) $this->session->get('impersonate_tenant_id', 0);
        if ($impersonateId > 0) {
            return $impersonateId;
        }

        $activeId = (int) $this->session->get('active_tenant_id', 0);
        $userId = (int) $this->session->get('user_id', 0);

        if ($userId > 0) {
            $user = $this->db->query(
                'SELECT id, is_platform FROM #__users WHERE id = ? LIMIT 1',
                [$userId]
            )->row;
            $isPlatform = $user !== [] && !empty($user['is_platform']);

            if ($activeId > 0) {
                if ($isPlatform || $this->userInTenant($userId, $activeId)) {
                    return $activeId;
                }
            }

            // Non-platform users must use their membership tenant, never silently Default.
            $membership = $this->db->query(
                'SELECT tenant_id FROM #__store_tenant_users WHERE user_id = ? ORDER BY tenant_id ASC LIMIT 1',
                [$userId]
            )->row;
            if ($membership !== []) {
                $tid = (int) ($membership['tenant_id'] ?? 0);
                if ($tid > 0) {
                    $this->session->set('active_tenant_id', $tid);
                    return $tid;
                }
            }

            if ($isPlatform) {
                if ($activeId > 0) {
                    return $activeId;
                }
                $this->session->set('active_tenant_id', 1);
                return 1;
            }
        }

        return $activeId > 0 ? $activeId : 1;
    }

    private function userInTenant(int $userId, int $tenantId): bool
    {
        $row = $this->db->query(
            'SELECT user_id FROM #__store_tenant_users WHERE user_id = ? AND tenant_id = ? LIMIT 1',
            [$userId, $tenantId]
        )->row;
        return $row !== [];
    }
}
