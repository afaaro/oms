<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

final class TenantsController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        echo $this->platformView('platform/tenants/index', [
            'title'      => 'Tenants',
            'navSection' => 'tenants',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
        ]);
    }

    public function create(): void
    {
        $this->guardPlatform();
        echo $this->platformView('platform/tenants/form', [
            'title'      => 'New Tenant',
            'navSection' => 'tenants',
            'tenant'     => null,
            'error'      => $this->app->session->flash('error'),
            'old'        => (array) ($this->app->session->flash('old_tenant') ?? []),
        ]);
    }

    public function store(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/tenants/new');
        }

        $name = trim((string) $this->app->request->post('name', ''));
        $slug = strtolower(preg_replace('/[^a-z0-9-]+/i', '-', trim((string) $this->app->request->post('slug', ''))) ?? '');
        $slug = trim($slug, '-') ?: strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'tenant');
        $email = trim((string) $this->app->request->post('contact_email', ''));
        $plan = trim((string) $this->app->request->post('plan', 'standard'));

        $userName = trim((string) $this->app->request->post('user_name', ''));
        $userEmail = trim((string) $this->app->request->post('user_email', ''));
        $userUsername = trim((string) $this->app->request->post('user_username', ''));
        $userPassword = (string) $this->app->request->post('user_password', '');
        $wantsUser = $userEmail !== '' || $userPassword !== '' || $userName !== '' || $userUsername !== '';

        $old = [
            'name'          => $name,
            'slug'          => $slug,
            'contact_email' => $email,
            'plan'          => $plan,
            'user_name'     => $userName,
            'user_email'    => $userEmail,
            'user_username' => $userUsername,
        ];

        if ($name === '') {
            $this->flashFormError('Company name is required.', $old);
        }

        $exists = $this->app->db->query('SELECT id FROM #__store_tenants WHERE slug = ? LIMIT 1', [$slug])->row;
        if ($exists !== []) {
            $this->flashFormError('Slug already in use.', $old);
        }

        if ($wantsUser) {
            if ($userEmail === '' || $userPassword === '') {
                $this->flashFormError('Owner login email and password are required when adding a user.', $old);
            }
            if (strlen($userPassword) < 8) {
                $this->flashFormError('Owner password must be at least 8 characters.', $old);
            }
            if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) {
                $this->flashFormError('Owner login email is invalid.', $old);
            }
            $emailTaken = $this->app->db->query(
                'SELECT id FROM #__users WHERE email = ? OR email_canonical = ? LIMIT 1',
                [$userEmail, strtolower($userEmail)]
            )->row;
            if ($emailTaken !== []) {
                $this->flashFormError('That login email is already registered.', $old);
            }
            if ($userUsername === '') {
                $userUsername = preg_replace('/[^a-zA-Z0-9_]/', '', (string) (strstr($userEmail, '@', true) ?: 'user')) ?: ('user' . time());
            }
            $userUsername = substr((string) preg_replace('/[^a-zA-Z0-9_]/', '', $userUsername), 0, 64);
            if ($userUsername === '') {
                $userUsername = 'user' . time();
            }
            $userTaken = $this->app->db->query(
                'SELECT id FROM #__users WHERE username = ? LIMIT 1',
                [$userUsername]
            )->row;
            if ($userTaken !== []) {
                $userUsername .= '_' . substr(bin2hex(random_bytes(2)), 0, 4);
            }
            if ($email === '') {
                $email = $userEmail;
            }
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__store_tenants (name, slug, status, plan, contact_email)
             VALUES (?, ?, ?, ?, ?)',
            [$name, $slug, 'active', $plan, $email]
        );
        $tid = (int) $ins->insert_id;

        // Default warehouse for new tenant
        $this->app->db->query(
            'INSERT INTO #__store_warehouses (tenant_id, name, code, is_default, active)
             VALUES (?, ?, ?, 1, 1)',
            [$tid, 'Default', 'DEFAULT']
        );

        $msg = 'Tenant created.';
        if ($wantsUser) {
            $uid = $this->createOwnerUser($tid, $userEmail, $userUsername, $userPassword, $userName);
            $msg = 'Tenant created with owner login ' . $userEmail . ' (user #' . $uid . ').';
        }

        $this->app->session->flash('success', $msg);
        $this->redirect('platform/tenants/view', ['id' => $tid]);
    }

    /** @param array<string, string> $old */
    private function flashFormError(string $message, array $old): void
    {
        $this->app->session->flash('error', $message);
        $this->app->session->flash('old_tenant', $old);
        $this->redirect('platform/tenants/new');
    }

    private function createOwnerUser(int $tenantId, string $email, string $username, string $password, string $displayName): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $this->app->db->query(
            'INSERT INTO #__users (email, email_canonical, username, password_hash, display_name, status, email_verified)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [
                $email,
                strtolower($email),
                $username,
                $hash,
                $displayName !== '' ? $displayName : $username,
                'active',
            ]
        );
        $uid = (int) $insert->insert_id;

        // Tenant-scoped owner ACL only — do NOT add global admin groups
        // (those would make the user see/write Default tenant data).
        $this->app->auth->aclService()->grantAll($uid, $tenantId);

        return $uid;
    }

    public function show(): void
    {
        $this->guardPlatform();
        $id = (int) $this->app->request->get('id', 0);
        $tenant = $this->app->db->query(
            'SELECT * FROM #__store_tenants WHERE id = ? LIMIT 1',
            [$id]
        )->row;
        if ($tenant === []) {
            http_response_code(404);
            echo 'Tenant not found';
            return;
        }

        $users = $this->app->db->query(
            'SELECT u.id, u.email, u.display_name, u.status, tu.role
             FROM #__store_tenant_users tu
             INNER JOIN #__users u ON u.id = tu.user_id
             WHERE tu.tenant_id = ?
             ORDER BY u.id',
            [$id]
        )->rows;

        $tickets = $this->app->db->query(
            'SELECT id, subject, status, priority, created_at
             FROM #__support_tickets WHERE tenant_id = ?
             ORDER BY id DESC LIMIT 20',
            [$id]
        )->rows;

        echo $this->platformView('platform/tenants/view', [
            'title'      => (string) $tenant['name'],
            'navSection' => 'tenants',
            'tenant'     => $tenant,
            'users'      => $users,
            'tickets'    => $tickets,
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'isProtected'=> $this->isProtectedTenant($id),
        ]);
    }

    public function save(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/tenants');
        }
        $id = (int) $this->app->request->post('id', 0);
        $status = (string) $this->app->request->post('status', 'active');
        if (!in_array($status, ['active', 'suspended', 'trial', 'cancelled', 'closed'], true)) {
            $status = 'active';
        }
        $this->app->db->query(
            'UPDATE #__store_tenants
             SET name = ?, contact_email = ?, plan = ?, status = ?, support_notes = ?
             WHERE id = ?',
            [
                trim((string) $this->app->request->post('name', '')),
                trim((string) $this->app->request->post('contact_email', '')),
                trim((string) $this->app->request->post('plan', 'standard')),
                $status,
                trim((string) $this->app->request->post('support_notes', '')),
                $id,
            ]
        );
        $this->app->session->flash('success', 'Tenant updated.');
        $this->redirect('platform/tenants/view', ['id' => $id]);
    }

    public function close(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/tenants');
        }
        $id = (int) $this->app->request->post('id', 0);
        $tenant = $this->findTenant($id);
        if ($tenant === null) {
            $this->redirect('platform/tenants');
        }
        if ($this->isProtectedTenant($id)) {
            $this->app->session->flash('error', 'The primary platform tenant cannot be closed.');
            $this->redirect('platform/tenants/view', ['id' => $id]);
        }

        $this->app->db->query(
            "UPDATE #__store_tenants SET status = 'closed', support_notes = CONCAT(COALESCE(support_notes,''), ?) WHERE id = ?",
            ["\n[" . date('Y-m-d H:i') . '] Account closed by platform staff.', $id]
        );
        $this->app->db->query(
            'UPDATE #__store_channels SET active = 0 WHERE tenant_id = ?',
            [$id]
        );
        $this->app->db->query(
            'UPDATE #__store_api_tokens SET revoked_at = NOW() WHERE tenant_id = ? AND revoked_at IS NULL',
            [$id]
        );

        if ($this->app->tenant->isImpersonating() && $this->app->tenant->id() === $id) {
            $this->app->tenant->stopImpersonating();
        }

        $this->app->session->flash('success', 'Account closed. Channels deactivated and API tokens revoked.');
        $this->redirect('platform/tenants/view', ['id' => $id]);
    }

    public function reopen(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/tenants');
        }
        $id = (int) $this->app->request->post('id', 0);
        if ($this->findTenant($id) === null) {
            $this->redirect('platform/tenants');
        }
        $this->app->db->query(
            "UPDATE #__store_tenants SET status = 'active', support_notes = CONCAT(COALESCE(support_notes,''), ?) WHERE id = ?",
            ["\n[" . date('Y-m-d H:i') . '] Account reopened by platform staff.', $id]
        );
        $this->app->session->flash('success', 'Account reopened.');
        $this->redirect('platform/tenants/view', ['id' => $id]);
    }

    public function delete(): void
    {
        $this->guardPlatform();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('platform/tenants');
        }
        $id = (int) $this->app->request->post('id', 0);
        $tenant = $this->findTenant($id);
        if ($tenant === null) {
            $this->redirect('platform/tenants');
        }
        if ($this->isProtectedTenant($id)) {
            $this->app->session->flash('error', 'The primary platform tenant cannot be deleted.');
            $this->redirect('platform/tenants/view', ['id' => $id]);
        }

        $confirm = trim((string) $this->app->request->post('confirm_slug', ''));
        $slug = (string) ($tenant['slug'] ?? '');
        if ($confirm === '' || !hash_equals($slug, $confirm)) {
            $this->app->session->flash('error', 'Type the tenant slug exactly to confirm permanent delete.');
            $this->redirect('platform/tenants/view', ['id' => $id]);
        }

        if ($this->app->tenant->isImpersonating() && $this->app->tenant->id() === $id) {
            $this->app->tenant->stopImpersonating();
        }

        $this->purgeTenantData($id);
        $this->app->db->query('DELETE FROM #__store_tenants WHERE id = ?', [$id]);

        $this->app->session->flash('success', 'Tenant "' . (string) $tenant['name'] . '" permanently deleted.');
        $this->redirect('platform/tenants');
    }

    public function impersonate(): void
    {
        $this->guardPlatform();
        $id = (int) $this->app->request->get('id', 0);
        $tenant = $this->app->db->query(
            'SELECT id, name, status FROM #__store_tenants WHERE id = ? LIMIT 1',
            [$id]
        )->row;
        if ($tenant === []) {
            $this->redirect('platform/tenants');
        }
        $status = (string) ($tenant['status'] ?? '');
        if (in_array($status, ['closed', 'cancelled'], true)) {
            $this->app->session->flash('error', 'Cannot open a closed or cancelled account. Reopen it first.');
            $this->redirect('platform/tenants/view', ['id' => $id]);
        }
        $this->app->tenant->impersonate($id);
        $this->redirect('admin/orders');
    }

    public function stopImpersonate(): void
    {
        $this->guardPlatform();
        $this->app->tenant->stopImpersonating();
        $this->redirect('platform/tenants');
    }

    /** @return array<string, mixed>|null */
    private function findTenant(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT * FROM #__store_tenants WHERE id = ? LIMIT 1',
            [$id]
        )->row;
        return $row !== [] ? $row : null;
    }

    private function isProtectedTenant(int $id): bool
    {
        return $id === 1;
    }

    private function purgeTenantData(int $tenantId): void
    {
        // Child rows without tenant_id
        $this->app->db->query(
            'DELETE oi FROM #__store_order_items oi
             INNER JOIN #__store_orders o ON o.id = oi.order_id
             WHERE o.tenant_id = ?',
            [$tenantId]
        );
        $this->app->db->query(
            'DELETE r FROM #__support_ticket_replies r
             INNER JOIN #__support_tickets t ON t.id = r.ticket_id
             WHERE t.tenant_id = ?',
            [$tenantId]
        );

        $tables = [
            'store_buyer_messages',
            'store_api_tokens',
            'store_listings',
            'store_shipping_rules',
            'store_shipping_regions',
            'store_shipping_methods',
            'store_report_jobs',
            'store_dropships',
            'store_returns',
            'store_inventory',
            'store_stock_locations',
            'store_categories',
            'store_purchase_orders',
            'store_suppliers',
            'support_tickets',
            'store_orders',
            'store_products',
            'store_customers',
            'store_channels',
            'store_warehouses',
            'store_tenant_users',
        ];
        foreach ($tables as $table) {
            $this->app->db->query('DELETE FROM #__' . $table . ' WHERE tenant_id = ?', [$tenantId]);
        }
    }
}
