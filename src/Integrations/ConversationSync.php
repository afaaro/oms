<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;
use App\Integrations\Ebay\EbayConnector;
use App\Store\ConversationRepository;

/**
 * Sync eBay Commerce Message conversations — mirrors demo ConversationSyncService.
 */
final class ConversationSync
{
    public function __construct(private App $app) {}

    /**
     * @return array{channels:int,created:int,updated:int,messages:int,errors:list<string>}
     */
    public function syncAll(int $tenantId, int $maxPages = 5, int $prefetchThreads = 10): array
    {
        $channels = $this->app->db->query(
            "SELECT * FROM #__store_channels
             WHERE tenant_id = ? AND type IN ('marketplace','webstore') AND active = 1
             ORDER BY name",
            [$tenantId]
        )->rows;

        $repo = new ConversationRepository($this->app->db);
        $registry = new ConnectorRegistry($this->app);
        $created = 0;
        $updated = 0;
        $messages = 0;
        $touched = 0;
        $errors = [];

        foreach ($channels as $channel) {
            $settings = $this->decodeSettings($channel['settings'] ?? null);
            if (($settings['linked'] ?? '0') !== '1') {
                continue;
            }
            $connector = $registry->forChannel($channel, $settings);
            if (!$connector instanceof EbayConnector) {
                continue;
            }
            $touched++;
            try {
                $result = $this->syncChannel(
                    $connector,
                    $repo,
                    $tenantId,
                    (int) $channel['id'],
                    $settings,
                    'FROM_MEMBERS',
                    $maxPages,
                    $prefetchThreads,
                );
                $created += $result['created'];
                $updated += $result['updated'];
                $messages += $result['messages'];
                if ($result['settings'] !== $settings) {
                    $this->persistSettings((int) $channel['id'], $tenantId, $result['settings']);
                }
            } catch (\Throwable $e) {
                $errors[] = ($channel['code'] ?? '?') . ': ' . $e->getMessage();
            }
        }

        return compact('touched', 'created', 'updated', 'messages', 'errors') + ['channels' => $touched];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{created:int,updated:int,messages:int,pages:int,settings:array<string,mixed>}
     */
    public function syncChannel(
        EbayConnector $connector,
        ConversationRepository $repo,
        int $tenantId,
        int $channelId,
        array $settings,
        string $type = 'FROM_MEMBERS',
        int $maxPages = 5,
        int $prefetchThreads = 10,
    ): array {
        $created = 0;
        $updated = 0;
        $messages = 0;
        $pages = 0;
        $offset = 0;
        $threadsFetched = 0;
        $maxPages = max(1, min(20, $maxPages));

        for ($page = 0; $page < $maxPages; $page++) {
            $result = $connector->fetchConversations($settings, 50, $offset, $type);
            $settings = $result['settings'];
            $conversations = $result['conversations'];
            if ($conversations === []) {
                break;
            }
            $pages++;

            foreach ($conversations as $conv) {
                if (!is_array($conv)) {
                    continue;
                }
                $status = $repo->upsert($tenantId, $channelId, $conv);
                if ($status === 'created') {
                    $created++;
                } elseif ($status === 'updated') {
                    $updated++;
                }

                if ($prefetchThreads <= 0 || $threadsFetched >= $prefetchThreads) {
                    continue;
                }
                $convId = trim((string) ($conv['conversationId'] ?? ''));
                $convType = (string) ($conv['conversationType'] ?? $type);
                if ($convId === '') {
                    continue;
                }
                try {
                    $thread = $connector->fetchConversation($settings, $convId, $convType);
                    $settings = $thread['settings'];
                    $threadsFetched++;
                    foreach ($thread['messages'] as $m) {
                        if (!is_array($m)) {
                            continue;
                        }
                        if ($repo->upsertMessage($tenantId, $channelId, $convId, $m) !== 'skipped') {
                            $messages++;
                        }
                    }
                } catch (\Throwable) {
                    // Prefetch is best-effort; open/refresh loads the full thread.
                }
            }

            $next = $result['next'];
            if ($next === null) {
                break;
            }
            $offset = $next;
        }

        return compact('created', 'updated', 'messages', 'pages') + ['settings' => $settings];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{messages:int,settings:array<string,mixed>}
     */
    public function syncThread(
        EbayConnector $connector,
        ConversationRepository $repo,
        int $tenantId,
        int $channelId,
        array $settings,
        string $conversationId,
        string $conversationType = 'FROM_MEMBERS',
    ): array {
        $thread = $connector->fetchConversation($settings, $conversationId, $conversationType);
        $settings = $thread['settings'];
        $count = 0;
        $latest = null;
        foreach ($thread['messages'] as $m) {
            if (!is_array($m)) {
                continue;
            }
            if ($repo->upsertMessage($tenantId, $channelId, $conversationId, $m) !== 'skipped') {
                $count++;
            }
            $latest = $this->newerMessage($latest, $m);
        }

        if ($latest !== null && trim((string) ($latest['messageId'] ?? '')) !== '') {
            $existing = $repo->find($channelId, $conversationId);
            if ($existing !== null) {
                $repo->upsert($tenantId, $channelId, [
                    'conversationId'     => $conversationId,
                    'conversationType'   => (string) ($existing['conversation_type'] ?? $conversationType),
                    'conversationStatus' => (string) ($existing['status'] ?? 'ACTIVE'),
                    'referenceId'        => (string) ($existing['reference_id'] ?? ''),
                    'referenceType'      => (string) ($existing['reference_type'] ?? ''),
                    'unreadCount'        => (int) ($existing['unread_count'] ?? 0),
                    'latestMessage'      => $latest,
                ]);
            }
        }

        return ['messages' => $count, 'settings' => $settings];
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function newerMessage(?array $current, array $candidate): array
    {
        if ($current === null) {
            return $candidate;
        }
        $a = strtotime((string) ($current['createdDate'] ?? '')) ?: 0;
        $b = strtotime((string) ($candidate['createdDate'] ?? '')) ?: 0;
        return $b >= $a ? $candidate : $current;
    }

    /** @param array<string, mixed> $settings */
    private function persistSettings(int $channelId, int $tenantId, array $settings): void
    {
        $this->app->db->query(
            'UPDATE #__store_channels SET settings = ? WHERE id = ? AND tenant_id = ?',
            [json_encode($settings, JSON_UNESCAPED_UNICODE), $channelId, $tenantId]
        );
    }

    /** @return array<string, string> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? array_map('strval', $decoded) : [];
        }
        return is_array($raw) ? array_map('strval', $raw) : [];
    }
}
