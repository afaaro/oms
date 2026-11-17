<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

final class ReplyTemplateService
{
    public function __construct(private App $app) {}

    /** @return list<array<string,mixed>> */
    public function list(?string $channelType = null): array
    {
        $tenantId = $this->app->tenant->id();
        $params = [$tenantId];
        $sql = 'SELECT * FROM #__store_reply_templates WHERE tenant_id=?';
        if ($channelType !== null && $channelType !== '') {
            $sql .= ' AND (channel_type IS NULL OR channel_type=\'\' OR channel_type=?)';
            $params[] = $channelType;
        }
        $sql .= ' ORDER BY name ASC, id ASC';
        return $this->app->db->query($sql, $params)->rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->app->db->query(
            'SELECT * FROM #__store_reply_templates WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /**
     * @param array{name?:string,body?:string,channel_type?:string|null} $data
     */
    public function save(array $data, int $id = 0): int
    {
        $tenantId = $this->app->tenant->id();
        $name = trim((string) ($data['name'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));
        $channelType = trim((string) ($data['channel_type'] ?? ''));
        if ($name === '') {
            $name = 'Template';
        }
        if ($id > 0) {
            $this->app->db->query(
                'UPDATE #__store_reply_templates SET name=?, body=?, channel_type=? WHERE id=? AND tenant_id=?',
                [$name, $body, $channelType !== '' ? $channelType : null, $id, $tenantId]
            );
            return $id;
        }
        $ins = $this->app->db->query(
            'INSERT INTO #__store_reply_templates (tenant_id, name, body, channel_type) VALUES (?, ?, ?, ?)',
            [$tenantId, $name, $body, $channelType !== '' ? $channelType : null]
        );
        return (int) ($ins->insert_id ?: 0);
    }

    public function delete(int $id): void
    {
        $this->app->db->query(
            'DELETE FROM #__store_reply_templates WHERE id=? AND tenant_id=?',
            [$id, $this->app->tenant->id()]
        );
    }
}
