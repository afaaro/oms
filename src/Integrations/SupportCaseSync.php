<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;
use App\Integrations\Ebay\EbayConnector;
use App\Store\SupportCaseRepository;

/**
 * Pull eBay Post-Order cases / inquiries into store_support_cases.
 */
final class SupportCaseSync
{
    private const CLOSED = [
        'CLOSED', 'CS_CLOSED', 'OTHER', 'CLOSED_WITH_ESCALATION',
    ];

    public function __construct(private App $app) {}

    /**
     * @return array{channels:int,created:int,updated:int,cases:int,inquiries:int,details:int,errors:list<string>}
     */
    public function syncAll(int $tenantId, int $lookbackDays = 90): array
    {
        $channels = $this->app->db->query(
            "SELECT * FROM #__store_channels
             WHERE tenant_id=? AND type IN ('marketplace','webstore') AND active=1
             ORDER BY name",
            [$tenantId]
        )->rows;

        $totals = [
            'channels'  => 0,
            'created'   => 0,
            'updated'   => 0,
            'cases'     => 0,
            'inquiries' => 0,
            'details'   => 0,
            'errors'    => [],
        ];
        $repo = new SupportCaseRepository($this->app->db);
        $registry = new ConnectorRegistry($this->app);

        foreach ($channels as $channel) {
            $settings = $this->decodeSettings($channel['settings'] ?? null);
            if (($settings['linked'] ?? '0') !== '1') {
                continue;
            }
            $connector = $registry->forChannel($channel, $settings);
            if (!$connector instanceof EbayConnector) {
                continue;
            }
            $totals['channels']++;
            try {
                $stats = $this->syncChannel(
                    $connector,
                    $repo,
                    $tenantId,
                    (int) $channel['id'],
                    $settings,
                    $lookbackDays
                );
                foreach (['created', 'updated', 'cases', 'inquiries', 'details'] as $k) {
                    $totals[$k] += (int) ($stats[$k] ?? 0);
                }
                if (!empty($stats['settings']) && is_array($stats['settings'])) {
                    $this->persistSettings((int) $channel['id'], $tenantId, $stats['settings']);
                }
                foreach ($stats['errors'] ?? [] as $err) {
                    $totals['errors'][] = ($channel['code'] ?? '?') . ': ' . $err;
                }
            } catch (\Throwable $e) {
                $totals['errors'][] = ($channel['code'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        return $totals;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:list<string>,settings:array<string,mixed>}
     */
    public function syncChannel(
        EbayConnector $connector,
        SupportCaseRepository $repo,
        int $tenantId,
        int $channelId,
        array $settings,
        int $lookbackDays = 90
    ): array {
        $stats = [
            'created' => 0, 'updated' => 0, 'cases' => 0, 'inquiries' => 0,
            'details' => 0, 'errors' => [], 'settings' => $settings,
        ];

        foreach (['OPEN', 'WAITING_CS', 'WAITING_DELIVERY', 'ON_HOLD', 'CLOSED', 'CS_CLOSED'] as $status) {
            try {
                $this->pullCases($connector, $repo, $tenantId, $channelId, $settings, $status, $lookbackDays, $stats);
            } catch (\Throwable $e) {
                $stats['errors'][] = 'cases/' . $status . ': ' . $e->getMessage();
            }
        }
        foreach (['OPEN', 'WAITING_SELLER_RESPONSE', 'WAITING_BUYER_RESPONSE', 'CLOSED', 'CLOSED_WITH_ESCALATION', 'PENDING'] as $status) {
            try {
                $this->pullInquiries($connector, $repo, $tenantId, $channelId, $settings, $status, $lookbackDays, $stats);
            } catch (\Throwable $e) {
                $stats['errors'][] = 'inquiry/' . $status . ': ' . $e->getMessage();
            }
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:list<string>,settings:array<string,mixed>} $stats
     */
    private function pullCases(
        EbayConnector $connector,
        SupportCaseRepository $repo,
        int $tenantId,
        int $channelId,
        array &$settings,
        string $status,
        int $lookbackDays,
        array &$stats
    ): void {
        $offset = 0;
        $limit = 50;
        for ($page = 0; $page < 10; $page++) {
            $result = $connector->searchSupportCases($settings, $status, $limit, $offset, $lookbackDays);
            $settings = $result['settings'];
            $stats['settings'] = $settings;
            $members = $result['members'];
            if ($members === []) {
                break;
            }
            foreach ($members as $row) {
                $this->ingest($connector, $repo, $tenantId, $channelId, $settings, 'case', $row, $stats);
            }
            if (count($members) < $limit) {
                break;
            }
            $offset += $limit;
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @param array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:list<string>,settings:array<string,mixed>} $stats
     */
    private function pullInquiries(
        EbayConnector $connector,
        SupportCaseRepository $repo,
        int $tenantId,
        int $channelId,
        array &$settings,
        string $status,
        int $lookbackDays,
        array &$stats
    ): void {
        $offset = 0;
        $limit = 50;
        for ($page = 0; $page < 10; $page++) {
            $result = $connector->searchSupportInquiries($settings, $status, $limit, $offset, $lookbackDays);
            $settings = $result['settings'];
            $stats['settings'] = $settings;
            $members = $result['members'];
            if ($members === []) {
                break;
            }
            foreach ($members as $row) {
                $this->ingest($connector, $repo, $tenantId, $channelId, $settings, 'inquiry', $row, $stats);
            }
            if (count($members) < $limit) {
                break;
            }
            $offset += $limit;
        }
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $row
     * @param array{created:int,updated:int,cases:int,inquiries:int,details:int,errors:list<string>,settings:array<string,mixed>} $stats
     */
    private function ingest(
        EbayConnector $connector,
        SupportCaseRepository $repo,
        int $tenantId,
        int $channelId,
        array &$settings,
        string $kind,
        array $row,
        array &$stats
    ): void {
        $mapped = $kind === 'inquiry' ? $this->mapInquiry($row) : $this->mapCase($row);
        $ext = (string) ($mapped['external_id'] ?? '');
        if ($ext === '') {
            return;
        }
        $status = strtoupper((string) ($mapped['status'] ?? ''));
        if (!in_array($status, self::CLOSED, true)) {
            try {
                $detail = $kind === 'inquiry'
                    ? $connector->getSupportInquiry($settings, $ext)
                    : $connector->getSupportCase($settings, $ext);
                $settings = $detail['settings'];
                $stats['settings'] = $settings;
                $payload = is_array($detail['payload'] ?? null) ? $detail['payload'] : [];
                $rich = $kind === 'inquiry' ? $this->mapInquiry($payload) : $this->mapCase($payload);
                $rich['summary'] = $row;
                $rich['detail'] = $payload;
                $mapped = $rich;
                $stats['details']++;
            } catch (\Throwable $e) {
                $stats['errors'][] = $kind . '/' . $ext . ': ' . $e->getMessage();
            }
        }
        $action = $repo->upsert($tenantId, $channelId, $kind, $ext, $mapped);
        $stats[$action]++;
        $stats[$kind === 'inquiry' ? 'inquiries' : 'cases']++;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapCase(array $row): array
    {
        $caseId = (string) ($row['caseId'] ?? $row['case_id'] ?? $row['id'] ?? '');
        $details = is_array($row['caseDetails'] ?? null) ? $row['caseDetails'] : $row;
        $item = is_array($details['itemDetails'] ?? null) ? $details['itemDetails'] : [];
        $claim = is_array($details['claimAmount'] ?? null) ? $details['claimAmount'] : [];
        return [
            'external_id'         => $caseId,
            'case_type'           => (string) ($row['caseType'] ?? $details['caseType'] ?? 'CASE'),
            'status'              => (string) ($row['caseStatusEnum'] ?? $details['caseStatusEnum'] ?? $row['status'] ?? $details['status'] ?? ''),
            'item_id'             => (string) ($item['itemId'] ?? $details['itemId'] ?? $row['itemId'] ?? ''),
            'transaction_id'      => (string) ($item['transactionId'] ?? $details['transactionId'] ?? $row['transactionId'] ?? ''),
            'order_id'            => (string) ($details['legacyOrderId'] ?? $details['orderId'] ?? ''),
            'buyer_login'         => $this->loginName($details['buyer'] ?? $row['buyer'] ?? ''),
            'claim_amount'        => isset($claim['value']) ? (float) $claim['value'] : null,
            'claim_currency'      => (string) ($claim['currency'] ?? ''),
            'item_title'          => (string) ($item['itemTitle'] ?? $details['itemTitle'] ?? ''),
            'seller_response_due' => $this->parseDate($details['respondByDate'] ?? $row['respondByDate'] ?? $details['sellerResponseDueDate'] ?? $row['sellerResponseDueDate'] ?? null),
            'creation_date'       => $this->parseDate($details['creationDate'] ?? $row['creationDate'] ?? null),
            'last_modified_date'  => $this->parseDate($details['lastModifiedDate'] ?? $row['lastModifiedDate'] ?? null),
            'summary'             => $row,
        ];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function mapInquiry(array $row): array
    {
        $id = (string) ($row['inquiryId'] ?? $row['inquiry_id'] ?? $row['id'] ?? '');
        $details = is_array($row['inquiryDetails'] ?? null) ? $row['inquiryDetails'] : $row;
        $item = is_array($details['itemDetails'] ?? null) ? $details['itemDetails'] : [];
        $claim = is_array($details['claimAmount'] ?? null) ? $details['claimAmount'] : [];
        return [
            'external_id'         => $id,
            'case_type'           => (string) ($row['inquiryType'] ?? $details['inquiryType'] ?? 'ITEM_NOT_RECEIVED'),
            'status'              => (string) ($row['inquiryStatusEnum'] ?? $details['inquiryStatusEnum'] ?? $row['status'] ?? $details['status'] ?? ''),
            'item_id'             => (string) ($item['itemId'] ?? $details['itemId'] ?? $row['itemId'] ?? ''),
            'transaction_id'      => (string) ($item['transactionId'] ?? $details['transactionId'] ?? $row['transactionId'] ?? ''),
            'order_id'            => (string) ($details['legacyOrderId'] ?? $details['orderId'] ?? ''),
            'buyer_login'         => $this->loginName($details['buyer'] ?? $row['buyer'] ?? ''),
            'claim_amount'        => isset($claim['value']) ? (float) $claim['value'] : null,
            'claim_currency'      => (string) ($claim['currency'] ?? ''),
            'item_title'          => (string) ($item['itemTitle'] ?? $details['itemTitle'] ?? ''),
            'seller_response_due' => $this->parseDate($details['respondByDate'] ?? $row['respondByDate'] ?? $details['sellerResponseDueDate'] ?? $row['sellerResponseDueDate'] ?? null),
            'creation_date'       => $this->parseDate($details['creationDate'] ?? $row['creationDate'] ?? null),
            'last_modified_date'  => $this->parseDate($details['lastModifiedDate'] ?? $row['lastModifiedDate'] ?? null),
            'summary'             => $row,
        ];
    }

    private function loginName(mixed $buyer): string
    {
        if (is_string($buyer) || is_numeric($buyer)) {
            return trim((string) $buyer);
        }
        if (is_array($buyer)) {
            return trim((string) ($buyer['loginName'] ?? $buyer['username'] ?? $buyer['name'] ?? ''));
        }
        return '';
    }

    private function parseDate(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $raw = $raw['value'] ?? $raw['formattedValue'] ?? null;
        }
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $ts = strtotime($raw);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
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

    /** @param array<string,mixed> $settings */
    private function persistSettings(int $channelId, int $tenantId, array $settings): void
    {
        $this->app->db->query(
            'UPDATE #__store_channels SET settings=? WHERE id=? AND tenant_id=?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
        );
    }
}
