<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;
use App\Integrations\ConversationSync;
use App\Integrations\MessageSync;
use App\Integrations\OrderSync;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Decides when a tenant is due and pulls orders, mail, and conversations.
 */
final class SyncScheduler
{
    /** @var list<int> */
    public const INTERVALS = [5, 10, 15, 30, 60];

    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(private App $app) {}

    /**
     * @return array{
     *   enabled:int,
     *   interval_minutes:int,
     *   order_days:int,
     *   sync_orders:int,
     *   sync_mail:int,
     *   sync_conversations:int,
     *   last_started_at:?string,
     *   last_finished_at:?string,
     *   last_status:string,
     *   last_summary:string
     * }
     */
    public function settingsFor(int $tenantId): array
    {
        $row = $this->app->db->query(
            'SELECT * FROM #__store_sync_settings WHERE tenant_id=? LIMIT 1',
            [$tenantId]
        )->row;

        return $this->normalise($row);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(int $tenantId, array $input): void
    {
        $current = $this->settingsFor($tenantId);
        $interval = (int) ($input['interval_minutes'] ?? $current['interval_minutes']);
        if (!in_array($interval, self::INTERVALS, true)) {
            $interval = 10;
        }
        $orderDays = max(1, min(7, (int) ($input['order_days'] ?? $current['order_days'])));
        $enabled = !empty($input['enabled']) ? 1 : 0;
        $syncOrders = !empty($input['sync_orders']) ? 1 : 0;
        $syncMail = !empty($input['sync_mail']) ? 1 : 0;
        $syncConv = !empty($input['sync_conversations']) ? 1 : 0;

        $this->app->db->query(
            'INSERT INTO #__store_sync_settings
                (tenant_id, enabled, interval_minutes, order_days, sync_orders, sync_mail, sync_conversations)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled=VALUES(enabled),
                interval_minutes=VALUES(interval_minutes),
                order_days=VALUES(order_days),
                sync_orders=VALUES(sync_orders),
                sync_mail=VALUES(sync_mail),
                sync_conversations=VALUES(sync_conversations)',
            [$tenantId, $enabled, $interval, $orderDays, $syncOrders, $syncMail, $syncConv]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runsFor(int $tenantId, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        return $this->app->db->query(
            'SELECT * FROM #__store_sync_runs WHERE tenant_id=? ORDER BY id DESC LIMIT ' . $limit,
            [$tenantId]
        )->rows;
    }

    public function cronToken(bool $rotate = false): string
    {
        $row = $this->app->db->query('SELECT token FROM #__store_sync_cron WHERE id=1 LIMIT 1')->row;
        $existing = trim((string) ($row['token'] ?? ''));
        if ($existing !== '' && !$rotate) {
            return $existing;
        }
        $token = bin2hex(random_bytes(24));
        if ($row === []) {
            $this->app->db->query('INSERT INTO #__store_sync_cron (id, token) VALUES (1, ?)', [$token]);
        } else {
            $this->app->db->query('UPDATE #__store_sync_cron SET token=? WHERE id=1', [$token]);
        }
        return $token;
    }

    public function tokenMatches(string $given): bool
    {
        $given = trim($given);
        if ($given === '') {
            return false;
        }
        $row = $this->app->db->query('SELECT token FROM #__store_sync_cron WHERE id=1 LIMIT 1')->row;
        $stored = trim((string) ($row['token'] ?? ''));
        if ($stored === '') {
            return false;
        }
        return hash_equals($stored, $given);
    }

    /**
     * @return array{ok:bool, locked:bool, tenants:list<array<string, mixed>>}
     */
    public function run(?int $onlyTenant, string $trigger, bool $force): array
    {
        if (!$this->acquireLock()) {
            return [
                'ok'      => false,
                'locked'  => true,
                'tenants' => [],
            ];
        }

        try {
            $tenants = $this->tenantIds($onlyTenant);
            $out = [];
            $ok = true;
            foreach ($tenants as $tenantId) {
                $result = $this->runTenant($tenantId, $trigger, $force);
                if (($result['status'] ?? '') === 'error') {
                    $ok = false;
                }
                $out[] = $result;
            }
            return [
                'ok'      => $ok,
                'locked'  => false,
                'tenants' => $out,
            ];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runTenant(int $tenantId, string $trigger, bool $force): array
    {
        $settings = $this->settingsFor($tenantId);
        if (!$force && (int) $settings['enabled'] !== 1) {
            return [
                'tenant_id' => $tenantId,
                'status'    => 'skipped',
                'summary'   => 'disabled',
            ];
        }
        if (!$force && !$this->isDue($settings)) {
            return [
                'tenant_id' => $tenantId,
                'status'    => 'skipped',
                'summary'   => 'not due',
            ];
        }

        $started = date('Y-m-d H:i:s');
        $this->touchStart($tenantId, $settings, $started);
        $runId = (int) $this->app->db->query(
            'INSERT INTO #__store_sync_runs (tenant_id, `trigger`, status, summary, started_at) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $trigger, 'running', '', $started]
        )->insert_id;

        $parts = [];
        $errors = [];
        try {
            $this->app->tenant->setActive($tenantId);
            if ((int) $settings['sync_orders'] === 1) {
                $since = new DateTimeImmutable(
                    '-' . (int) $settings['order_days'] . ' days',
                    new DateTimeZone('UTC')
                );
                $orders = (new OrderSync($this->app))->syncTenant($tenantId, $since);
                $parts[] = sprintf(
                    'orders: channels %d, read %d, imported %d, updated %d',
                    $orders['channels'],
                    $orders['read'],
                    $orders['imported'],
                    $orders['updated']
                );
                foreach ($orders['errors'] as $err) {
                    $errors[] = 'orders: ' . $err;
                }
            }
            if ((int) $settings['sync_mail'] === 1) {
                $mail = (new MessageSync($this->app))->syncAll($tenantId, 14);
                $parts[] = sprintf(
                    'mail: channels %d, imported %d, updated %d',
                    $mail['channels'] ?? 0,
                    $mail['imported'] ?? 0,
                    $mail['updated'] ?? 0
                );
                foreach (array_slice($mail['errors'] ?? [], 0, 5) as $err) {
                    $errors[] = 'mail: ' . $err;
                }
            }
            if ((int) $settings['sync_conversations'] === 1) {
                $conv = (new ConversationSync($this->app))->syncAll($tenantId, 5, 10);
                $parts[] = sprintf(
                    'conversations: channels %d, created %d, updated %d, messages %d',
                    $conv['channels'] ?? 0,
                    $conv['created'] ?? 0,
                    $conv['updated'] ?? 0,
                    $conv['messages'] ?? 0
                );
                foreach (array_slice($conv['errors'] ?? [], 0, 5) as $err) {
                    $errors[] = 'conversations: ' . $err;
                }
            }
            if ($parts === []) {
                $parts[] = 'nothing selected';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $this->app->logger?->exception($e, 'sync', ['tenant_id' => $tenantId, 'trigger' => $trigger]);
        }

        $status = $errors === [] ? 'ok' : 'error';
        $summary = implode('; ', $parts);
        if ($errors !== []) {
            $summary .= '. Errors: ' . implode('; ', array_slice($errors, 0, 3));
        }
        $summary = mb_substr($summary, 0, 500);
        $finished = date('Y-m-d H:i:s');

        $this->app->db->query(
            'UPDATE #__store_sync_runs SET status=?, summary=?, finished_at=? WHERE id=?',
            [$status, $summary, $finished, $runId]
        );
        $this->app->db->query(
            'UPDATE #__store_sync_settings
             SET last_finished_at=?, last_status=?, last_summary=?
             WHERE tenant_id=?',
            [$finished, $status, $summary, $tenantId]
        );
        $this->app->logger?->info($summary, ['tenant_id' => $tenantId, 'status' => $status], 'sync');

        return [
            'tenant_id' => $tenantId,
            'status'    => $status,
            'summary'   => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function isDue(array $settings): bool
    {
        $last = trim((string) ($settings['last_started_at'] ?? ''));
        if ($last === '') {
            return true;
        }
        $started = strtotime($last);
        if ($started === false) {
            return true;
        }
        $interval = max(1, (int) $settings['interval_minutes']) * 60;
        return (time() - $started) >= $interval;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function touchStart(int $tenantId, array $settings, string $started): void
    {
        $this->app->db->query(
            'INSERT INTO #__store_sync_settings
                (tenant_id, enabled, interval_minutes, order_days, sync_orders, sync_mail, sync_conversations, last_started_at, last_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_started_at=VALUES(last_started_at), last_status=VALUES(last_status)',
            [
                $tenantId,
                (int) $settings['enabled'],
                (int) $settings['interval_minutes'],
                (int) $settings['order_days'],
                (int) $settings['sync_orders'],
                (int) $settings['sync_mail'],
                (int) $settings['sync_conversations'],
                $started,
                'running',
            ]
        );
    }

    /**
     * @return list<int>
     */
    private function tenantIds(?int $onlyTenant): array
    {
        if ($onlyTenant !== null && $onlyTenant > 0) {
            return [$onlyTenant];
        }
        $rows = $this->app->db->query(
            "SELECT id FROM #__store_tenants
             WHERE status IN ('active','open','trial') OR status='' OR status IS NULL
             ORDER BY id ASC"
        )->rows;
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   enabled:int,
     *   interval_minutes:int,
     *   order_days:int,
     *   sync_orders:int,
     *   sync_mail:int,
     *   sync_conversations:int,
     *   last_started_at:?string,
     *   last_finished_at:?string,
     *   last_status:string,
     *   last_summary:string
     * }
     */
    private function normalise(array $row): array
    {
        $interval = (int) ($row['interval_minutes'] ?? 10);
        if (!in_array($interval, self::INTERVALS, true)) {
            $interval = 10;
        }
        $days = (int) ($row['order_days'] ?? 2);
        if ($days < 1 || $days > 7) {
            $days = 2;
        }
        return [
            'enabled'             => array_key_exists('enabled', $row) ? (int) $row['enabled'] : 1,
            'interval_minutes'    => $interval,
            'order_days'          => $days,
            'sync_orders'         => array_key_exists('sync_orders', $row) ? (int) $row['sync_orders'] : 1,
            'sync_mail'           => array_key_exists('sync_mail', $row) ? (int) $row['sync_mail'] : 1,
            'sync_conversations'  => array_key_exists('sync_conversations', $row) ? (int) $row['sync_conversations'] : 1,
            'last_started_at'     => isset($row['last_started_at']) && $row['last_started_at'] !== null
                ? (string) $row['last_started_at'] : null,
            'last_finished_at'    => isset($row['last_finished_at']) && $row['last_finished_at'] !== null
                ? (string) $row['last_finished_at'] : null,
            'last_status'         => (string) ($row['last_status'] ?? ''),
            'last_summary'        => (string) ($row['last_summary'] ?? ''),
        ];
    }

    private function acquireLock(): bool
    {
        $dir = $this->app->basePath . '/storage/locks';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $handle = fopen($dir . '/sync.lock', 'c');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        return true;
    }

    private function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }
}
