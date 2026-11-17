<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\Database;

final class SupportCaseRepository
{
    public function __construct(private Database $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTenant(
        int $tenantId,
        int $channelId = 0,
        string $status = '',
        string $kind = '',
        int $limit = 100
    ): array {
        $params = [$tenantId];
        $sql = 'SELECT sc.*, c.name AS channel_name
                FROM #__store_support_cases sc
                LEFT JOIN #__store_channels c ON c.id = sc.channel_id
                WHERE sc.tenant_id = ?';
        if ($channelId > 0) {
            $sql .= ' AND sc.channel_id = ?';
            $params[] = $channelId;
        }
        if ($status !== '') {
            $sql .= ' AND sc.status = ?';
            $params[] = $status;
        }
        if ($kind !== '') {
            $sql .= ' AND sc.kind = ?';
            $params[] = $kind;
        }
        $sql .= ' ORDER BY COALESCE(sc.last_modified_date, sc.creation_date) DESC, sc.id DESC
                  LIMIT ' . max(1, min(500, $limit));
        return $this->db->query($sql, $params)->rows;
    }

    /** @return array<string,mixed>|null */
    public function findById(int $tenantId, int $id): ?array
    {
        $row = $this->db->query(
            'SELECT sc.*, c.name AS channel_name, c.code AS channel_code, c.settings AS channel_settings, c.type AS channel_type
             FROM #__store_support_cases sc
             LEFT JOIN #__store_channels c ON c.id = sc.channel_id
             WHERE sc.id = ? AND sc.tenant_id = ? LIMIT 1',
            [$id, $tenantId]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $tenantId, int $channelId, string $kind, string $externalId): ?array
    {
        $row = $this->db->query(
            'SELECT * FROM #__store_support_cases
             WHERE tenant_id=? AND channel_id=? AND kind=? AND external_id=? LIMIT 1',
            [$tenantId, $channelId, $kind, $externalId]
        )->row;
        return $row !== [] ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return 'created'|'updated'
     */
    public function upsert(int $tenantId, int $channelId, string $kind, string $externalId, array $data): string
    {
        $existing = $this->find($tenantId, $channelId, $kind, $externalId);
        $summary = $this->encodeJson($data['summary'] ?? []);
        $detail = array_key_exists('detail', $data) ? $this->encodeJson($data['detail']) : null;
        $caseType = (string) ($data['case_type'] ?? '');
        $status = (string) ($data['status'] ?? '');
        $itemId = (string) ($data['item_id'] ?? '');
        $transactionId = (string) ($data['transaction_id'] ?? '');
        $orderId = (string) ($data['order_id'] ?? '');
        $buyerLogin = (string) ($data['buyer_login'] ?? '');
        $claimAmount = $data['claim_amount'] ?? null;
        $claimCurrency = (string) ($data['claim_currency'] ?? '');
        $itemTitle = mb_substr((string) ($data['item_title'] ?? ''), 0, 255);
        $due = $data['seller_response_due'] ?? null;
        $created = $data['creation_date'] ?? null;
        $modified = $data['last_modified_date'] ?? null;
        $syncedAt = date('Y-m-d H:i:s');

        if ($existing !== null) {
            $sets = 'case_type=?, status=?, item_id=?, transaction_id=?, order_id=?, buyer_login=?,
                     claim_amount=?, claim_currency=?, item_title=?, seller_response_due=?,
                     creation_date=?, last_modified_date=?, summary_json=?, synced_at=?';
            $bind = [
                $caseType, $status, $itemId, $transactionId, $orderId, $buyerLogin,
                $claimAmount, $claimCurrency, $itemTitle, $due, $created, $modified,
                $summary, $syncedAt,
            ];
            if ($detail !== null) {
                $sets .= ', detail_json=?';
                $bind[] = $detail;
            }
            $oldDue = (string) ($existing['seller_response_due'] ?? '');
            $newDue = (string) ($due ?? '');
            if ($oldDue !== $newDue) {
                $sets .= ', alerted_at=NULL';
            }
            $bind[] = (int) $existing['id'];
            $bind[] = $tenantId;
            $this->db->query(
                'UPDATE #__store_support_cases SET ' . $sets . ' WHERE id=? AND tenant_id=?',
                $bind
            );
            return 'updated';
        }

        $this->db->query(
            'INSERT INTO #__store_support_cases
             (tenant_id, channel_id, external_id, kind, case_type, status, item_id, transaction_id,
              order_id, buyer_login, claim_amount, claim_currency, item_title, seller_response_due,
              creation_date, last_modified_date, summary_json, detail_json, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId, $channelId, $externalId, $kind,
                $caseType, $status, $itemId, $transactionId, $orderId, $buyerLogin,
                $claimAmount, $claimCurrency, $itemTitle, $due, $created, $modified,
                $summary, $detail ?? '{}', $syncedAt,
            ]
        );
        return 'created';
    }

    public function setAssignee(int $tenantId, int $caseId, ?int $userId): void
    {
        $this->db->query(
            'UPDATE #__store_support_cases SET assigned_user_id=? WHERE id=? AND tenant_id=?',
            [$userId !== null && $userId > 0 ? $userId : null, $caseId, $tenantId]
        );
    }

    private function encodeJson(mixed $data): string
    {
        if (is_string($data)) {
            return $data !== '' ? $data : '{}';
        }
        $json = json_encode(is_array($data) ? $data : [], JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }
}
