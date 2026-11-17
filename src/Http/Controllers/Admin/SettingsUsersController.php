<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Auth\PermissionCatalog;
use App\Http\Controllers\Controller;

final class SettingsUsersController extends Controller
{
    public function index(): void
    {
        $this->guardSettings();

        echo $this->view('admin/settings/users', [
            'title'      => 'Users',
            'navSection' => 'settings',
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function edit(): void
    {
        $this->guardSettings();
        $id = (int) $this->app->request->get('id', 0);
        $tenantId = $this->tenantId();
        $row = $this->findTenantUser($id, $tenantId);
        if ($row === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title' => 'Not found',
                'navSection' => 'settings',
                'message' => 'User not found on this account.',
            ]);
            return;
        }

        $aclService = $this->app->auth->aclService();
        $userAcl = $aclService->forUser($id, $tenantId);
        $tab = (string) $this->app->request->get('tab', 'basic');
        if (!in_array($tab, ['basic', 'permissions', 'warehouses', 'channels'], true)) {
            $tab = 'basic';
        }

        $warehouses = $this->app->db->query(
            'SELECT id, name FROM #__store_warehouses WHERE tenant_id = ? AND active = 1 ORDER BY name',
            [$tenantId]
        )->rows;
        $channels = $this->app->db->query(
            'SELECT id, name, code FROM #__store_channels WHERE tenant_id = ? AND active = 1 ORDER BY name',
            [$tenantId]
        )->rows;

        echo $this->view('admin/settings/user_edit', [
            'title'      => 'View User',
            'navSection' => 'settings',
            'editUser'   => $row,
            'userAcl'    => $userAcl,
            'tab'        => $tab,
            'actionTree' => PermissionCatalog::actionTree(),
            'pageTree'   => PermissionCatalog::pageTree(),
            'mobileTree' => PermissionCatalog::mobileTree(),
            'warehouses' => $warehouses,
            'channels'   => $channels,
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        $this->guardSettings();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('success', 'Session expired — try again.');
            $this->redirect('admin/settings/users');
        }

        $id = (int) $this->app->request->post('id', 0);
        $tenantId = $this->tenantId();
        if ($this->findTenantUser($id, $tenantId) === null) {
            $this->app->session->flash('error', 'User not found on this account.');
            $this->redirect('admin/settings/users');
        }

        $tab = (string) $this->app->request->post('tab', 'permissions');
        $displayName = trim((string) $this->app->request->post('display_name', ''));
        $status = (string) $this->app->request->post('status', 'active');
        if (!in_array($status, ['active', 'pending', 'suspended'], true)) {
            $status = 'active';
        }

        $this->app->db->query(
            'UPDATE #__users SET display_name = ?, status = ? WHERE id = ?',
            [$displayName, $status, $id]
        );

        $aclService = $this->app->auth->aclService();
        $existing = $aclService->forUser($id, $tenantId);

        if ($tab === 'permissions') {
            $grantAll = (string) $this->app->request->post('grant_all', '') === '1';
            if ($grantAll) {
                $aclService->grantAll($id, $tenantId);
            } else {
                $pagePerms = $this->postList('page_perms');
                $actionPerms = array_values(array_unique(array_merge(
                    $this->postList('action_perms'),
                    $this->postList('mobile_perms')
                )));
                $role = (string) $this->app->request->post('role', $existing['role'] ?: 'member');
                $aclService->save(
                    $id,
                    $role,
                    $pagePerms,
                    $actionPerms,
                    $existing['warehouse_ids'],
                    $existing['channel_ids'],
                    ['grant_all' => false, 'is_admin' => $role === 'owner'],
                    $tenantId
                );
            }
        } elseif ($tab === 'warehouses') {
            $warehouseIds = array_map('intval', $this->postList('warehouse_ids'));
            $aclService->save(
                $id,
                $existing['role'],
                $existing['page_perms'],
                $existing['action_perms'],
                $warehouseIds,
                $existing['channel_ids'],
                $existing['flags'],
                $tenantId
            );
        } elseif ($tab === 'channels') {
            $channelIds = array_map('intval', $this->postList('channel_ids'));
            $aclService->save(
                $id,
                $existing['role'],
                $existing['page_perms'],
                $existing['action_perms'],
                $existing['warehouse_ids'],
                $channelIds,
                $existing['flags'],
                $tenantId
            );
        } elseif ($tab === 'basic') {
            $role = (string) $this->app->request->post('role', $existing['role'] ?: 'member');
            $flags = $existing['flags'];
            if ($role === 'owner') {
                $flags['is_admin'] = true;
                $flags['grant_all'] = true;
                $aclService->grantAll($id, $tenantId);
            } else {
                $flags['is_admin'] = false;
                $aclService->save(
                    $id,
                    $role,
                    $existing['page_perms'],
                    $existing['action_perms'],
                    $existing['warehouse_ids'],
                    $existing['channel_ids'],
                    $flags,
                    $tenantId
                );
            }
        }

        $this->app->session->flash('success', 'User saved.');
        $this->redirect('admin/settings/users/edit', ['id' => $id, 'tab' => $tab]);
    }

    public function create(): void
    {
        $this->guardSettings();
        echo $this->view('admin/settings/user_new', [
            'title'      => 'New User',
            'navSection' => 'settings',
            'error'      => $this->app->session->flash('error'),
            'cloneFrom'  => (int) $this->app->session->get('clone_acl_from', 0),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        $this->guardSettings();
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Session expired.');
            $this->redirect('admin/settings/users/new');
        }

        $tenantId = $this->tenantId();
        $email = trim((string) $this->app->request->post('email', ''));
        $name = trim((string) $this->app->request->post('display_name', ''));
        $password = (string) $this->app->request->post('password', '');
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', (string) (strstr($email, '@', true) ?: 'user')) ?: ('user' . time());

        if ($email === '' || $password === '') {
            $this->app->session->flash('error', 'Email and password are required.');
            $this->redirect('admin/settings/users/new');
        }

        $exists = $this->app->db->query('SELECT id FROM #__users WHERE email = ? LIMIT 1', [$email])->row;
        if ($exists !== []) {
            $this->app->session->flash('error', 'Email already in use.');
            $this->redirect('admin/settings/users/new');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $this->app->db->query(
            'INSERT INTO #__users (email, email_canonical, username, password_hash, display_name, status, email_verified)
             VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$email, strtolower($email), $username, $hash, $name !== '' ? $name : $username, 'active']
        );
        $uid = (int) $insert->insert_id;

        $this->app->db->query(
            'INSERT IGNORE INTO #__user_group_members (user_id, group_id) VALUES (?, 1)',
            [$uid]
        );

        $cloneFrom = (int) $this->app->session->get('clone_acl_from', 0);
        $this->app->session->remove('clone_acl_from');
        if ($cloneFrom > 0 && $this->findTenantUser($cloneFrom, $tenantId) !== null) {
            $src = $this->app->auth->aclService()->forUser($cloneFrom, $tenantId);
            $this->app->auth->aclService()->save(
                $uid,
                (string) $src['role'],
                $src['page_perms'],
                $src['action_perms'],
                $src['warehouse_ids'],
                $src['channel_ids'],
                $src['flags'],
                $tenantId
            );
        } else {
            $this->app->auth->aclService()->save($uid, 'member', [], [], [], [], [], $tenantId);
        }

        $this->redirect('admin/settings/users/edit', ['id' => $uid, 'tab' => 'permissions']);
    }

    public function cloneUser(): void
    {
        $this->guardSettings();
        $id = (int) $this->app->request->get('id', 0);
        if ($this->findTenantUser($id, $this->tenantId()) === null) {
            $this->app->session->flash('error', 'User not found on this account.');
            $this->redirect('admin/settings/users');
        }
        $this->app->session->set('clone_acl_from', $id);
        $this->redirect('admin/settings/users/new');
    }

    private function tenantId(): int
    {
        return $this->app->tenant->id();
    }

    /** @return array<string, mixed>|null */
    private function findTenantUser(int $userId, int $tenantId): ?array
    {
        if ($userId <= 0 || $tenantId <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT u.id, u.email, u.username, u.display_name, u.status, u.last_login_at, u.created_at, tu.role
             FROM #__store_tenant_users tu
             INNER JOIN #__users u ON u.id = tu.user_id
             WHERE tu.tenant_id = ? AND tu.user_id = ?
             LIMIT 1',
            [$tenantId, $userId]
        )->row;
        return $row !== [] ? $row : null;
    }

    private function guardSettings(): void
    {
        if (!$this->app->auth->check()) {
            $this->redirect('login');
        }
        // Account owners / legacy admins always allowed; others need page perm
        if (!$this->app->auth->canPage('page.settings.users') && !$this->app->auth->isPlatform()) {
            http_response_code(403);
            echo $this->view('admin/error', [
                'title' => 'Forbidden',
                'navSection' => 'settings',
                'message' => "You don't have permission to manage users.",
            ]);
            exit;
        }
    }

    /** @return list<string> */
    private function postList(string $key): array
    {
        $raw = $this->app->request->post($key, []);
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map('strval', $raw));
    }
}
