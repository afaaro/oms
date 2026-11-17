<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Integrations\ConnectorRegistry;
use App\Integrations\ConversationSync;
use App\Integrations\Ebay\EbayConnector;
use App\Integrations\MessageSync;
use App\Store\ConversationRepository;
use App\Store\SupportCaseRepository;

final class MessagesApiController extends ApiController
{
    public function index(): void
    {
        if (!$this->requireScope('messages:read')) {
            return;
        }
        $params = [$this->tenantId()];
        $where = 'm.tenant_id = ?';
        $status = trim((string) $this->app->request->get('status', ''));
        $provider = trim((string) $this->app->request->get('provider', ''));
        $folder = trim((string) $this->app->request->get('folder', ''));
        $channelId = (int) $this->app->request->get('channel_id', 0);
        if ($folder === 'mail' || $folder === 'message') {
            $where .= ' AND m.folder = ?';
            $params[] = $folder;
        }
        if ($status !== '') {
            $where .= ' AND m.status = ?';
            $params[] = $status;
        }
        if ($provider !== '') {
            $where .= ' AND m.provider = ?';
            $params[] = $provider;
        }
        if ($channelId > 0) {
            $where .= ' AND m.channel_id = ?';
            $params[] = $channelId;
        }

        $total = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_buyer_messages m WHERE {$where}",
            $params
        )->row['c'] ?? 0);

        $rows = $this->app->db->query(
            "SELECT m.id, m.channel_id, m.provider, m.folder, m.external_id, m.thread_id, m.order_external_id,
                    m.buyer_name, m.buyer_email, m.subject, m.body, m.direction, m.status,
                    m.received_at, m.synced_at, c.name AS channel_name, c.code AS channel_code
             FROM #__store_buyer_messages m
             LEFT JOIN #__store_channels c ON c.id = m.channel_id
             WHERE {$where}
             ORDER BY COALESCE(m.received_at, m.created_at) DESC
             LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;

        $this->ok($rows, $this->paginationMeta($total));
    }

    public function show(): void
    {
        if (!$this->requireScope('messages:read')) {
            return;
        }
        $id = (int) $this->app->request->get('id', 0);
        $row = $this->app->db->query(
            'SELECT m.*, c.name AS channel_name, c.code AS channel_code
             FROM #__store_buyer_messages m
             LEFT JOIN #__store_channels c ON c.id = m.channel_id
             WHERE m.id = ? AND m.tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($row === []) {
            $this->fail('Message not found', 404);
            return;
        }
        $this->ok($row);
    }

    public function sync(): void
    {
        if (!$this->requireScope('messages:manage')) {
            return;
        }
        $days = max(1, min(30, (int) $this->app->request->input('days', 14)));
        $folder = (string) $this->app->request->input('folder', 'mail');
        if ($folder === 'message') {
            $result = (new ConversationSync($this->app))->syncAll($this->tenantId(), 5, 10);
            $this->ok($result);
            return;
        }
        $result = (new MessageSync($this->app))->syncAll($this->tenantId(), $days);
        $this->ok($result);
    }

    public function updateStatus(): void
    {
        if (!$this->requireScope('messages:manage')) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $status = (string) $this->app->request->input('status', '');
        if ($id <= 0 || !in_array($status, ['unread', 'open', 'replied', 'closed'], true)) {
            $this->fail('Valid id and status required', 422);
            return;
        }
        $this->app->db->query(
            'UPDATE #__store_buyer_messages SET status = ? WHERE id = ? AND tenant_id = ?',
            [$status, $id, $this->tenantId()]
        );
        $this->ok(['id' => $id, 'status' => $status]);
    }

    public function conversations(): void
    {
        if (!$this->requireScope('messages:read')) {
            return;
        }
        $channelId = (int) $this->app->request->get('channel_id', 0);
        $rows = (new ConversationRepository($this->app->db))->listForTenant(
            $this->tenantId(),
            $channelId > 0 ? $channelId : null,
            $this->perPage()
        );
        $this->ok($rows, ['count' => count($rows)]);
    }

    public function conversationReply(): void
    {
        if (!$this->requireScope('messages:manage')) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        $body = trim((string) $this->app->request->input('body', ''));
        if ($id <= 0 || $body === '') {
            $this->fail('id and body required', 422);
            return;
        }
        $tenantId = $this->tenantId();
        $repo = new ConversationRepository($this->app->db);
        $conv = $repo->findForTenant($tenantId, $id);
        if ($conv === null) {
            $this->fail('Conversation not found', 404);
            return;
        }
        $channel = $this->app->db->query(
            'SELECT * FROM #__store_channels WHERE id=? AND tenant_id=? LIMIT 1',
            [(int) $conv['channel_id'], $tenantId]
        )->row;
        if ($channel === []) {
            $this->fail('Channel not found', 404);
            return;
        }
        $settings = $this->decodeSettings($channel['settings'] ?? null);
        $connector = (new ConnectorRegistry($this->app))->forChannel($channel, $settings);
        if (!$connector instanceof EbayConnector) {
            $this->fail('Conversation reply only supported for eBay', 422);
            return;
        }
        $sent = $connector->sendConversationMessage($settings, (string) $conv['external_id'], $body);
        if (!empty($sent['settings'])) {
            $this->app->db->query(
                'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
                [json_encode($sent['settings'], JSON_UNESCAPED_UNICODE), (int) $channel['id'], $tenantId]
            );
        }
        if (!$sent['ok']) {
            $this->fail($sent['message'], 502);
            return;
        }
        $this->ok(['id' => $id, 'sent' => true, 'message' => $sent['message']]);
    }

    public function cases(): void
    {
        if (!$this->requireScope('messages:read')) {
            return;
        }
        $channelId = (int) $this->app->request->get('channel_id', 0);
        $kind = trim((string) $this->app->request->get('kind', ''));
        $status = trim((string) $this->app->request->get('status', ''));
        $rows = (new SupportCaseRepository($this->app->db))->listForTenant(
            $this->tenantId(),
            $channelId,
            $status,
            $kind,
            $this->perPage()
        );
        $this->ok($rows, ['count' => count($rows)]);
    }

    /** @return array<string,string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            $out = [];
            foreach ($raw as $k => $v) {
                $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
            }
            return $out;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = is_scalar($v) || $v === null ? (string) $v : json_encode($v);
        }
        return $out;
    }
}
