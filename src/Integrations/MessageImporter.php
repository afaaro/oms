<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\Database;

final class MessageImporter
{
    public function __construct(private Database $db) {}

    /**
     * @param list<array<string, mixed>> $messages
     * @return array{imported:int, updated:int, skipped:int}
     */
    public function import(int $tenantId, ?int $channelId, string $provider, array $messages): array
    {
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($messages as $msg) {
            $ext = trim((string) ($msg['external_id'] ?? ''));
            if ($ext === '') {
                $skipped++;
                continue;
            }

            $receivedRaw = (string) ($msg['received_at'] ?? '');
            $receivedTs = $receivedRaw !== '' ? strtotime($receivedRaw) : false;
            $received = $receivedTs !== false ? date('Y-m-d H:i:s', $receivedTs) : date('Y-m-d H:i:s');

            $prov = $provider !== '' ? $provider : (string) ($msg['provider'] ?? '');
            $thread = (string) ($msg['thread_id'] ?? '');
            $orderExt = (string) ($msg['order_external_id'] ?? '');
            $buyerName = (string) ($msg['buyer_name'] ?? '');
            $buyerEmail = (string) ($msg['buyer_email'] ?? '');
            $subject = (string) ($msg['subject'] ?? '');
            $body = (string) ($msg['body'] ?? '');
            $direction = (string) ($msg['direction'] ?? 'inbound');
            $status = (string) ($msg['status'] ?? 'unread');
            $folder = $this->folderFor($msg);
            $raw = isset($msg['raw']) ? json_encode($msg['raw'], JSON_UNESCAPED_UNICODE) : null;

            $exists = $this->db->query(
                'SELECT id FROM #__store_buyer_messages WHERE tenant_id = ? AND external_id = ? LIMIT 1',
                [$tenantId, $ext]
            )->row;

            if ($exists === []) {
                $this->db->query(
                    'INSERT INTO #__store_buyer_messages
                     (tenant_id, channel_id, provider, folder, external_id, thread_id, order_external_id, buyer_name, buyer_email,
                      subject, body, direction, status, received_at, synced_at, raw_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)',
                    [
                        $tenantId, $channelId, $prov, $folder, $ext, $thread, $orderExt, $buyerName, $buyerEmail,
                        $subject, $body, $direction, $status, $received, $raw,
                    ]
                );
                $imported++;
            } else {
                $this->db->query(
                    'UPDATE #__store_buyer_messages
                     SET channel_id=?, provider=?, folder=?, thread_id=?, order_external_id=?, buyer_name=?, buyer_email=?,
                         subject=?, body=?, direction=?, status=?, received_at=?, synced_at=NOW(), raw_json=?
                     WHERE id=?',
                    [
                        $channelId, $prov, $folder, $thread, $orderExt, $buyerName, $buyerEmail,
                        $subject, $body, $direction, $status, $received, $raw, (int) $exists['id'],
                    ]
                );
                $updated++;
            }
        }

        return compact('imported', 'updated', 'skipped');
    }

    /**
     * Split a cleaned eBay mail body into username + body bubbles
     * (same shape as demo conversation messages).
     *
     * @return list<array{sender:string,body:string,mine:bool}>
     */
    public static function threadParts(string $text, string $defaultSender): array
    {
        $clean = self::readable($text);
        if ($clean === '') {
            return [];
        }

        $defaultSender = trim($defaultSender) !== '' ? trim($defaultSender) : 'Buyer';
        $chunks = preg_split(
            '/(?=New message from:\s*|Your previous message\b)/i',
            $clean,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [$clean];

        $parts = [];
        $seenBodies = [];
        foreach ($chunks as $i => $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $sender = $defaultSender;
            $body = $chunk;
            $mine = false;

            if (preg_match('/^New message from:\s*([A-Za-z0-9._\-]+)\s*(?:\([\d,]+\))?\s*/i', $chunk, $m)) {
                $sender = trim($m[1]) !== '' ? trim($m[1]) : $defaultSender;
                $body = trim(substr($chunk, strlen($m[0])));
            } elseif (preg_match('/^Your previous message\s*/i', $chunk, $m)) {
                $sender = 'You';
                $mine = true;
                $body = trim(substr($chunk, strlen($m[0])));
            }

            // Drop order/legal footer glued onto a bubble.
            $body = preg_split('/\n\n(?=Order status:|Order number:|View order details)/i', $body, 2)[0] ?? $body;
            $body = trim($body);
            if ($body === '') {
                continue;
            }

            $key = strtolower(preg_replace('/\s+/', ' ', $body) ?? $body);
            if (isset($seenBodies[$key])) {
                continue;
            }
            // Prefer the named "New message from" copy over a short teaser duplicate.
            if ($i === 0 && count($chunks) > 1 && strlen($body) < 80) {
                continue;
            }
            $seenBodies[$key] = true;

            $parts[] = [
                'sender' => $sender,
                'body'   => $body,
                'mine'   => $mine,
            ];
        }

        return $parts;
    }

    public static function readable(string $text): string
    {
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $text) ?? $text;
        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $text) ?? $text;
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|tr|li|h[1-6]|table)>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $previous = '';
        while ($previous !== $text) {
            $previous = $text;
            $text = preg_replace('/\{[^{}]*\}/', '', $text) ?? $text;
        }
        $text = self::withoutStylesheet($text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = preg_split("/\n+/", $text) ?: [];
        $kept = [];
        foreach ($lines as $line) {
            $line = trim(self::withoutStylesheet($line));
            if ($line === '' || self::isStylesheet($line)) {
                continue;
            }
            $kept[] = $line;
        }
        $text = trim(implode("\n", $kept));
        if ($text === '' || self::isStylesheet($text)) {
            return '';
        }
        $text = self::breakEbayThread($text);
        return trim($text);
    }

    private static function withoutStylesheet(string $text): string
    {
        $text = preg_replace('/url-prefix\s*\(\s*\)/i', ' ', $text) ?? $text;
        $text = preg_replace('/@media\b[^@{]*/i', ' ', $text) ?? $text;
        $text = preg_replace('/url\([^)]*\)/i', ' ', $text) ?? $text;
        $text = preg_replace('/@[a-zA-Z\-]+/', ' ', $text) ?? $text;
        $text = preg_replace('/[.#][\w\-]+/', ' ', $text) ?? $text;
        $text = preg_replace('/:[a-zA-Z0-9_\-]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\[[^\]]*\]/', ' ', $text) ?? $text;
        $text = preg_replace('/[{};]/', ' ', $text) ?? $text;
        $text = preg_replace('/(?:\b(?:html|body|head|div|span|table|thead|tbody|tfoot|td|tr|th|ul|ol|li|img|style|sup|sub|button|footer)\b\s*,\s*)+/i', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:html|body|head|div|span|table|thead|tbody|tfoot|td|tr|th|ul|ol|li|img|style|sup|sub)\b/i', ' ', $text) ?? $text;
        $text = preg_replace('/\bh[1-6]\b/', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        return trim($text, " \t\n\r,;:-_|");
    }

    private static function breakEbayThread(string $text): string
    {
        // Longest phrases first so "New message from:" is not split by a bare "From:".
        $text = preg_replace(
            '/\s+(?=(?:New message from:|Your previous message|Original message|Order status:|Order number:|View order details|Dear\s+|\[THREAD ID))/i',
            "\n\n",
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\s+(?=(?:^|\n)\s*(?:From|Sent|To|CC|Subject)\s*:)/im',
            "\n\n",
            $text
        ) ?? $text;
        if (!str_contains($text, "\n")) {
            $text = preg_replace('/([.!?])(?=[A-Z])/', "$1\n", $text) ?? $text;
        }
        return $text;
    }

    private static function isStylesheet(string $text): bool
    {
        if (preg_match('/url-prefix\s*\(|@(font-face|media|import)|:focus|:hover|!important|\{|\}/', $text)) {
            return true;
        }
        return !preg_match('/[A-Za-z]{4,}/', $text);
    }

    /** @param array<string, mixed> $msg */
    private function folderFor(array $msg): string
    {
        $folder = (string) ($msg['folder'] ?? '');
        if ($folder === 'mail' || $folder === 'message') {
            return $folder;
        }
        $sender = strtolower(trim((string) ($msg['buyer_name'] ?? '')));
        $blob = strtolower((string) ($msg['subject'] ?? '') . ' ' . (string) ($msg['body'] ?? ''));
        if ($sender === 'ebay' || $sender === 'ebay buyer' || str_contains($sender, 'customer service') || str_contains($blob, 'ebay customer service')) {
            return 'mail';
        }
        return 'message';
    }
}
