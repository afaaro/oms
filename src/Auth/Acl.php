<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;

final class Acl
{
    public function __construct(private Database $db) {}

    /**
     * @return array{
     *   role: string,
     *   page_perms: list<string>,
     *   action_perms: list<string>,
     *   warehouse_ids: list<int>,
     *   channel_ids: list<int>,
     *   flags: array<string, mixed>
     * }
     */
    public function forUser(int $userId, int $tenantId = 1): array
    {
        $row = $this->db->query(
            'SELECT role, page_perms, action_perms, warehouse_ids, channel_ids, flags
             FROM #__store_tenant_users
             WHERE tenant_id = ? AND user_id = ?
             LIMIT 1',
            [$tenantId, $userId]
        )->row;

        if ($row === []) {
            return [
                'role' => 'member',
                'page_perms' => [],
                'action_perms' => [],
                'warehouse_ids' => [],
                'channel_ids' => [],
                'flags' => [],
            ];
        }

        return [
            'role' => (string) ($row['role'] ?? 'member'),
            'page_perms' => $this->decodeList($row['page_perms'] ?? null),
            'action_perms' => $this->decodeList($row['action_perms'] ?? null),
            'warehouse_ids' => array_map('intval', $this->decodeList($row['warehouse_ids'] ?? null)),
            'channel_ids' => array_map('intval', $this->decodeList($row['channel_ids'] ?? null)),
            'flags' => $this->decodeObject($row['flags'] ?? null),
        ];
    }

    /**
     * @param list<string> $pagePerms
     * @param list<string> $actionPerms
     * @param list<int> $warehouseIds
     * @param list<int> $channelIds
     * @param array<string, mixed> $flags
     */
    public function save(
        int $userId,
        string $role,
        array $pagePerms,
        array $actionPerms,
        array $warehouseIds,
        array $channelIds,
        array $flags,
        int $tenantId = 1
    ): void {
        $exists = $this->db->query(
            'SELECT user_id FROM #__store_tenant_users WHERE tenant_id = ? AND user_id = ? LIMIT 1',
            [$tenantId, $userId]
        )->row;

        $params = [
            $role,
            json_encode(array_values($pagePerms), JSON_UNESCAPED_SLASHES),
            json_encode(array_values($actionPerms), JSON_UNESCAPED_SLASHES),
            json_encode(array_values(array_map('intval', $warehouseIds)), JSON_UNESCAPED_SLASHES),
            json_encode(array_values(array_map('intval', $channelIds)), JSON_UNESCAPED_SLASHES),
            json_encode($flags, JSON_UNESCAPED_SLASHES),
            $tenantId,
            $userId,
        ];

        if ($exists !== []) {
            $this->db->query(
                'UPDATE #__store_tenant_users
                 SET role = ?, page_perms = ?, action_perms = ?, warehouse_ids = ?, channel_ids = ?, flags = ?
                 WHERE tenant_id = ? AND user_id = ?',
                $params
            );
            return;
        }

        $this->db->query(
            'INSERT INTO #__store_tenant_users
             (role, page_perms, action_perms, warehouse_ids, channel_ids, flags, tenant_id, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            $params
        );
    }

    public function grantAll(int $userId, int $tenantId = 1): void
    {
        $this->save(
            $userId,
            'owner',
            PermissionCatalog::allPageKeys(),
            PermissionCatalog::allActionKeys(),
            [], // empty warehouse/channel = all
            [],
            ['grant_all' => true, 'is_admin' => true],
            $tenantId
        );
    }

    public function hasGrantAll(array $acl): bool
    {
        return !empty($acl['flags']['grant_all']) || !empty($acl['flags']['is_admin']);
    }

    public function canPage(array $acl, string $pageKey): bool
    {
        if ($this->hasGrantAll($acl)) {
            return true;
        }
        return in_array($pageKey, $acl['page_perms'], true);
    }

    public function canAction(array $acl, string $actionKey): bool
    {
        if ($this->hasGrantAll($acl)) {
            return true;
        }
        return in_array($actionKey, $acl['action_perms'], true);
    }

    public function roleLabel(array $acl): string
    {
        if (!empty($acl['flags']['is_admin']) || ($acl['role'] ?? '') === 'owner') {
            return 'Account Owner';
        }
        if ($this->hasGrantAll($acl)) {
            return 'Admin';
        }
        return 'Custom';
    }

    /** @return list<string|int> */
    private function decodeList(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return array_values($json);
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** @return array<string, mixed> */
    private function decodeObject(mixed $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
