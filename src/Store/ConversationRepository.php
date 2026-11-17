<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\Database;

/**
 * eBay Commerce Message conversations — mirrors demo ConversationRepository.
 */
final class ConversationRepository
{
    public function __construct(private Database $db) {}

    /** @return array<string, mixed>|null */
    public function find(int $channelId, string $externalId): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM #__store_conversations WHERE channel_id = ? AND external_id = ? LIMIT 1',
            [$channelId, $externalId]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findForTenant(int $tenantId, int $id): ?array
    {
        $row = $this->db->query(
            'SELECT cv.*, c.name AS channel_name, c.settings AS channel_settings
             FROM #__store_conversations cv
             LEFT JOIN #__store_channels c ON c.id = cv.channel_id
             WHERE cv.id = ? AND cv.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        )->row;
        return $row !== [] ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?int $channelId = null, int $limit = 100): array
    {
        $params = [$tenantId];
        $sql = 'SELECT cv.*, c.name AS channel_name
                FROM #__store_conversations cv
                LEFT JOIN #__store_channels c ON c.id = cv.channel_id
                WHERE cv.tenant_id = ?';
        if ($channelId !== null && $channelId > 0) {
            $sql .= ' AND cv.channel_id = ?';
            $params[] = $channelId;
        }
        $order = ' ORDER BY cv.priority DESC, cv.due_at IS NULL ASC, cv.due_at ASC, cv.latest_date DESC, cv.id DESC';
        $limitSql = ' LIMIT ' . max(1, min(200, $limit));
        try {
            return $this->db->query($sql . $order . $limitSql, $params)->rows;
        } catch (\Throwable) {
            return $this->db->query(
                $sql . ' ORDER BY cv.latest_date DESC, cv.id DESC' . $limitSql,
                $params
            )->rows;
        }
    }

    public function assign(int $tenantId, int $id, ?int $userId, ?string $dueAt = null, int $priority = 0): void
    {
        try {
            $this->db->query(
                'UPDATE #__store_conversations
                 SET assigned_user_id=?, due_at=?, priority=?
                 WHERE id=? AND tenant_id=?',
                [
                    $userId !== null && $userId > 0 ? $userId : null,
                    $dueAt !== null && $dueAt !== '' ? $dueAt : null,
                    max(0, min(9, $priority)),
                    $id,
                    $tenantId,
                ]
            );
        } catch (\Throwable) {
            // Columns may be missing until migration 034.
        }
    }

    public function countUnread(int $tenantId): int
    {
        return (int) $this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_conversations
             WHERE tenant_id = ? AND unread_count > 0',
            [$tenantId]
        )->row['c'];
    }

    /**
     * @param array<string, mixed> $conv
     */
    public function upsert(int $tenantId, int $channelId, array $conv): string
    {
        $externalId = trim((string) ($conv['conversationId'] ?? ''));
        if ($externalId === '' || $tenantId <= 0 || $channelId <= 0) {
            return 'skipped';
        }

        $latest = is_array($conv['latestMessage'] ?? null) ? $conv['latestMessage'] : [];
        $incomingBody = mb_substr((string) ($latest['messageBody'] ?? ''), 0, 1000);
        $incomingSender = (string) ($latest['senderUsername'] ?? '');
        $incomingMsgId = (string) ($latest['messageId'] ?? '');
        $incomingDate = $this->parseDate((string) ($latest['createdDate'] ?? ''));

        $existing = $this->find($channelId, $externalId);
        $data = [
            'tenant_id'         => $tenantId,
            'channel_id'        => $channelId,
            'external_id'       => $externalId,
            'conversation_type' => (string) ($conv['conversationType'] ?? 'FROM_MEMBERS'),
            'status'            => (string) ($conv['conversationStatus'] ?? 'ACTIVE'),
            'reference_id'      => (string) ($conv['referenceId'] ?? ''),
            'reference_type'    => (string) ($conv['referenceType'] ?? ''),
            'unread_count'      => (int) ($conv['unreadCount'] ?? 0),
            'latest_message_id' => $incomingMsgId,
            'latest_body'       => $incomingBody,
            'latest_sender'     => $incomingSender,
            'latest_date'       => $incomingDate,
            'synced_at'         => date('Y-m-d H:i:s'),
        ];

        if ($existing === null) {
            $this->db->query(
                'INSERT INTO #__store_conversations
                 (tenant_id, channel_id, external_id, conversation_type, status, reference_id, reference_type,
                  unread_count, latest_message_id, latest_body, latest_sender, latest_date, synced_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $data['tenant_id'], $data['channel_id'], $data['external_id'], $data['conversation_type'],
                    $data['status'], $data['reference_id'], $data['reference_type'], $data['unread_count'],
                    $data['latest_message_id'], $data['latest_body'], $data['latest_sender'],
                    $data['latest_date'], $data['synced_at'],
                ]
            );
            return 'created';
        }

        if ($incomingBody === '' && trim((string) ($existing['latest_body'] ?? '')) !== '') {
            $data['latest_body'] = (string) $existing['latest_body'];
        }
        if ($incomingSender === '' && trim((string) ($existing['latest_sender'] ?? '')) !== '') {
            $data['latest_sender'] = (string) $existing['latest_sender'];
        }
        if ($incomingMsgId === '' && trim((string) ($existing['latest_message_id'] ?? '')) !== '') {
            $data['latest_message_id'] = (string) $existing['latest_message_id'];
        }
        if ($incomingDate === null && !empty($existing['latest_date'])) {
            $data['latest_date'] = (string) $existing['latest_date'];
        }

        // Don't resurrect unread after a local mark-read when eBay still lags.
        // Only adopt channel unread when there is newer activity (or eBay says 0).
        $ebayUnread = (int) ($conv['unreadCount'] ?? 0);
        $localUnread = (int) ($existing['unread_count'] ?? 0);
        $existingLatestId = trim((string) ($existing['latest_message_id'] ?? ''));
        $sameLatest = $incomingMsgId !== '' && $existingLatestId !== '' && $incomingMsgId === $existingLatestId;
        if ($ebayUnread <= 0) {
            $data['unread_count'] = 0;
        } elseif ($sameLatest && $localUnread === 0) {
            $data['unread_count'] = 0;
        } else {
            $data['unread_count'] = $ebayUnread;
        }

        $this->db->query(
            'UPDATE #__store_conversations
             SET tenant_id=?, conversation_type=?, status=?, reference_id=?, reference_type=?,
                 unread_count=?, latest_message_id=?, latest_body=?, latest_sender=?, latest_date=?, synced_at=?
             WHERE id=?',
            [
                $tenantId, $data['conversation_type'], $data['status'], $data['reference_id'],
                $data['reference_type'], $data['unread_count'], $data['latest_message_id'],
                $data['latest_body'], $data['latest_sender'], $data['latest_date'],
                $data['synced_at'], (int) $existing['id'],
            ]
        );
        return 'updated';
    }

    /**
     * @param array<string, mixed> $msg
     */
    public function upsertMessage(int $tenantId, int $channelId, string $conversationId, array $msg): string
    {
        $externalId = trim((string) ($msg['messageId'] ?? ''));
        if ($externalId === '' || $tenantId <= 0 || $channelId <= 0 || $conversationId === '') {
            return 'skipped';
        }

        $incomingBody = (string) ($msg['messageBody'] ?? '');
        $existing = $this->db->query(
            'SELECT id, body, sender, recipient, created_date FROM #__store_conversation_messages
             WHERE channel_id = ? AND external_id = ? LIMIT 1',
            [$channelId, $externalId]
        )->row;

        $sender = (string) ($msg['senderUsername'] ?? '');
        $recipient = (string) ($msg['recipientUsername'] ?? '');
        $created = $this->parseDate((string) ($msg['createdDate'] ?? ''));
        $read = !empty($msg['readStatus']) ? 1 : 0;
        $synced = date('Y-m-d H:i:s');

        if ($existing === []) {
            $this->db->query(
                'INSERT INTO #__store_conversation_messages
                 (tenant_id, channel_id, conversation_id, external_id, sender, recipient, body, read_status, created_date, synced_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$tenantId, $channelId, $conversationId, $externalId, $sender, $recipient, $incomingBody, $read, $created, $synced]
            );
            return 'created';
        }

        if ($incomingBody === '' && trim((string) ($existing['body'] ?? '')) !== '') {
            $incomingBody = (string) $existing['body'];
        }
        if ($sender === '' && trim((string) ($existing['sender'] ?? '')) !== '') {
            $sender = (string) $existing['sender'];
        }
        if ($recipient === '' && trim((string) ($existing['recipient'] ?? '')) !== '') {
            $recipient = (string) $existing['recipient'];
        }
        if ($created === null && !empty($existing['created_date'])) {
            $created = (string) $existing['created_date'];
        }

        $this->db->query(
            'UPDATE #__store_conversation_messages
             SET tenant_id=?, conversation_id=?, sender=?, recipient=?, body=?, read_status=?, created_date=?, synced_at=?
             WHERE id=?',
            [$tenantId, $conversationId, $sender, $recipient, $incomingBody, $read, $created, $synced, (int) $existing['id']]
        );
        return 'updated';
    }

    /** @return list<array<string, mixed>> */
    public function messagesFor(int $channelId, string $conversationId, int $tenantId): array
    {
        return $this->db->query(
            'SELECT * FROM #__store_conversation_messages
             WHERE channel_id = ? AND conversation_id = ? AND tenant_id = ?
             ORDER BY created_date ASC, id ASC',
            [$channelId, $conversationId, $tenantId]
        )->rows;
    }

    public function markReadLocally(int $channelId, string $externalId, int $tenantId): void
    {
        $this->db->query(
            'UPDATE #__store_conversations SET unread_count = 0
             WHERE channel_id = ? AND external_id = ? AND tenant_id = ?',
            [$channelId, $externalId, $tenantId]
        );
        $this->db->query(
            'UPDATE #__store_conversation_messages SET read_status = 1
             WHERE channel_id = ? AND conversation_id = ? AND tenant_id = ?',
            [$channelId, $externalId, $tenantId]
        );
    }

    private function parseDate(string $iso): ?string
    {
        if ($iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }
}
