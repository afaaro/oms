<?php

declare(strict_types=1);

namespace App\Integrations\Ebay;

use App\Integrations\BaseConnector;

final class EbayConnector extends BaseConnector
{
    private ?string $tokenError = null;

    public function key(): string
    {
        return 'ebay';
    }

    public function label(): string
    {
        return 'eBay';
    }

    public function group(): string
    {
        return 'marketplace';
    }

    public function isConfigured(): bool
    {
        $c = $this->cfg->provider('ebay');
        return trim((string) ($c['client_id'] ?? '')) !== ''
            && trim((string) ($c['client_secret'] ?? '')) !== ''
            && trim((string) ($c['ru_name'] ?? '')) !== '';
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    public function supportsCredentials(): bool
    {
        return false;
    }

    public function authorizationUrl(array $channel, array $settings, string $state): string
    {
        $c = $this->ebayConfig();
        $sandbox = $this->isSandbox($c);
        $host = $sandbox ? 'https://auth.sandbox.ebay.com' : 'https://auth.ebay.com';
        $scopes = $c['scopes'] ?? [];
        if (!is_array($scopes)) {
            $scopes = [];
        }
        // Always request Message API scope (demo EbayOAuth). Existing refresh
        // tokens keep their original scopes until the seller re-authorizes.
        $messageScope = 'https://api.ebay.com/oauth/api_scope/commerce.message';
        if (!in_array($messageScope, $scopes, true)) {
            $scopes[] = $messageScope;
        }
        // RFC3986 so scopes use %20 (eBay rejects + as space in authorize URLs)
        $qs = http_build_query([
            'client_id'     => (string) $c['client_id'],
            'response_type' => 'code',
            'redirect_uri'  => (string) $c['ru_name'],
            'scope'         => implode(' ', array_values(array_unique(array_map('strval', $scopes)))),
            'state'         => $state,
        ], '', '&', PHP_QUERY_RFC3986);
        return $host . '/oauth2/authorize?' . $qs;
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        $code = trim((string) ($query['code'] ?? ''));
        if ($code === '') {
            return ['ok' => false, 'message' => 'eBay did not return an authorization code.'];
        }

        $token = $this->exchangeCode($code);
        if (!$token['ok']) {
            return $token;
        }

        $settings = array_merge($settings, $token['settings'] ?? []);
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'oauth';
        $settings['provider'] = 'ebay';
        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        $cfg = $this->ebayConfig();
        $scopeList = is_array($cfg['scopes'] ?? null) ? $cfg['scopes'] : [];
        $messageScope = 'https://api.ebay.com/oauth/api_scope/commerce.message';
        if (!in_array($messageScope, $scopeList, true)) {
            $scopeList[] = $messageScope;
        }
        $settings['oauth_scopes'] = implode(' ', array_map('strval', $scopeList));
        if (($settings['marketplace'] ?? '') === '') {
            $settings['marketplace'] = 'EBAY_GB';
        }

        $identity = $this->fetchUserId($settings);
        if ($identity !== '') {
            $settings['linked_user'] = $identity;
            $settings['ebay_username'] = $identity;
        } elseif (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? 'ebay');
        }

        return ['ok' => true, 'settings' => $settings, 'message' => 'eBay account linked.'];
    }

    public function testConnection(array $settings): array
    {
        $settings = $this->ensureAccessToken($settings);
        if ($this->tokenError !== null) {
            return ['ok' => false, 'message' => $this->tokenError, 'settings' => $settings];
        }
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'No eBay access token. Connect the channel first.'];
        }

        $api = $this->apiHost() . '/sell/fulfillment/v1/order?limit=1';
        $res = $this->http->request('GET', $api, [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'ok'       => true,
                'message'  => 'eBay API reachable (Fulfillment).',
                'settings' => $settings,
            ];
        }

        return [
            'ok'      => false,
            'message' => 'eBay API error HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 200),
            'settings'=> $settings,
        ];
    }

    public function submitFulfillment(array $settings, array $payload): array
    {
        $settings = $this->ensureAccessToken($settings);
        if ($this->tokenError !== null || ($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'status'   => 'failed',
                'message'  => $this->tokenError ?? 'eBay not linked (missing access token).',
                'settings' => $settings,
            ];
        }

        $orderId = trim((string) ($payload['external_order_id'] ?? ''));
        $tracking = trim((string) ($payload['tracking_number'] ?? ''));
        if ($orderId === '' || $tracking === '') {
            return [
                'ok'      => false,
                'status'  => 'failed',
                'message' => 'eBay fulfillment needs external order id and tracking.',
            ];
        }

        $get = $this->http->request(
            'GET',
            $this->apiHost() . '/sell/fulfillment/v1/order/' . rawurlencode($orderId),
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/json',
            ]
        );
        if ($get['status'] < 200 || $get['status'] >= 300 || !is_array($get['json'])) {
            return [
                'ok'       => false,
                'status'   => 'failed',
                'message'  => 'eBay order lookup HTTP ' . $get['status'] . ': ' . substr((string) $get['body'], 0, 200),
                'settings' => $settings,
            ];
        }

        $lineItems = [];
        foreach (($get['json']['lineItems'] ?? []) as $li) {
            if (!is_array($li)) {
                continue;
            }
            $lid = (string) ($li['lineItemId'] ?? '');
            $qty = max(1, (int) ($li['quantity'] ?? 1));
            if ($lid === '') {
                continue;
            }
            $lineItems[] = ['lineItemId' => $lid, 'quantity' => $qty];
        }
        if ($lineItems === []) {
            return [
                'ok'       => false,
                'status'   => 'failed',
                'message'  => 'eBay order has no line items to fulfill.',
                'settings' => $settings,
            ];
        }

        $carrier = $this->mapEbayCarrier((string) ($payload['carrier_code'] ?? ''));
        $body = [
            'lineItems'           => $lineItems,
            'shippingCarrierCode' => $carrier,
            'trackingNumber'      => $tracking,
        ];

        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/shipping_fulfillment',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            $body
        );

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'ok'       => true,
                'status'   => 'uploaded',
                'message'  => 'eBay shipping fulfillment created.',
                'settings' => $settings,
            ];
        }

        return [
            'ok'       => false,
            'status'   => 'failed',
            'message'  => 'eBay fulfillment HTTP ' . $res['status'] . ': ' . substr((string) ($res['error'] ?: $res['body']), 0, 240),
            'settings' => $settings,
        ];
    }

    public function cancelOrder(array $settings, array $payload): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'eBay not linked.', 'settings' => $settings];
        }
        $orderId = trim((string) ($payload['external_order_id'] ?? ''));
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Missing external order id.'];
        }
        $reason = trim((string) ($payload['reason'] ?? 'SELLER_CANCEL'));
        $body = [
            'legacyOrderId' => $orderId,
            'cancelReason'  => $reason !== '' ? strtoupper(str_replace(' ', '_', $reason)) : 'OUT_OF_STOCK_OR_CANNOT_FULFILL',
        ];
        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/post-order/v2/cancellation',
            [
                'Authorization' => 'TOKEN ' . $settings['access_token'],
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            $body
        );
        // Also try Bearer if TOKEN style fails on some accounts.
        if ($res['status'] === 401 || $res['status'] === 403) {
            $res = $this->http->request(
                'POST',
                $this->apiHost() . '/post-order/v2/cancellation',
                [
                    'Authorization' => 'Bearer ' . $settings['access_token'],
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                $body
            );
        }
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'eBay cancellation created.', 'settings' => $settings];
        }
        return [
            'ok'       => false,
            'message'  => 'eBay cancel HTTP ' . $res['status'] . ': ' . substr((string) $res['body'], 0, 200),
            'settings' => $settings,
        ];
    }

    public function issueRefund(array $settings, array $payload): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'eBay not linked.', 'settings' => $settings];
        }
        $orderId = trim((string) ($payload['external_order_id'] ?? ''));
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Missing external order id.'];
        }
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'GBP'))) ?: 'GBP';
        $body = [
            'reasonForRefund' => 'BUYER_CANCEL',
            'comment'         => trim((string) ($payload['comments'] ?? 'Refund issued from StoreFeeder')),
        ];
        if ($amount > 0) {
            $body['orderLevelRefundAmount'] = [
                'value'    => (string) $amount,
                'currency' => $currency,
            ];
        }
        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/sell/fulfillment/v1/order/' . rawurlencode($orderId) . '/issue_refund',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            $body
        );
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'eBay refund issued.', 'settings' => $settings];
        }
        return [
            'ok'       => false,
            'message'  => 'eBay refund HTTP ' . $res['status'] . ': ' . substr((string) $res['body'], 0, 200),
            'settings' => $settings,
        ];
    }

    private function mapEbayCarrier(string $code): string
    {
        $c = strtolower(trim($code));
        return match (true) {
            $c === '' => 'Other',
            str_contains($c, 'royal') => 'Royal Mail',
            str_contains($c, 'evri'), str_contains($c, 'hermes') => 'Hermes',
            str_contains($c, 'dpd') => 'DPD',
            str_contains($c, 'parcel') => 'Parcelforce',
            str_contains($c, 'ups') => 'UPS',
            str_contains($c, 'dhl') => 'DHL',
            str_contains($c, 'fedex') => 'FedEx',
            default => $code !== '' ? $code : 'Other',
        };
    }

    public function fetchOrders(array $settings, \DateTimeInterface $since): array
    {
        $settings = $this->freshSettings($settings);
        // eBay rejects start dates older than exactly 2 years; keep a 1-day buffer.
        $earliest = new \DateTimeImmutable('-2 years +1 day', new \DateTimeZone('UTC'));
        if ($since < $earliest) {
            $since = $earliest;
        }
        // Match demo EbayFulfillmentClient: open-ended creationdate window.
        // A closed lastmodifieddate range was returning 0 orders on live.
        $filter = 'creationdate:[' . $since->format('Y-m-d\TH:i:s.000\Z') . '..]';

        $out = [];
        $offset = 0;
        $limit = 200;
        // eBay getOrders caps at ~10,000 rows per query window.
        $maxPages = 50;
        $url = $this->apiHost() . '/sell/fulfillment/v1/order?' . http_build_query([
            'filter' => $filter,
            'limit'  => $limit,
            'offset' => 0,
        ], '', '&', PHP_QUERY_RFC3986);
        for ($page = 0; $page < $maxPages; $page++) {
            $res = $this->http->request('GET', $url, [
                'Authorization'           => 'Bearer ' . $settings['access_token'],
                'Accept'                  => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId($settings),
            ]);
            $this->assertEbayOk($res, 'eBay order sync');

            $orders = $res['json']['orders'] ?? [];
            if (!is_array($orders) || $orders === []) {
                break;
            }
            foreach ($orders as $o) {
                if (!is_array($o)) {
                    continue;
                }
                $out[] = $this->normalizeOrder($o);
            }

            $count = count($orders);
            $offset += $count;
            $total = (int) ($res['json']['total'] ?? 0);
            if ($count < $limit || ($total > 0 && $offset >= $total)) {
                break;
            }
            $next = $this->ebayNextUrl((string) ($res['json']['next'] ?? ''));
            if ($next !== '') {
                $url = $next;
                continue;
            }
            $url = $this->apiHost() . '/sell/fulfillment/v1/order?' . http_build_query([
                'filter' => $filter,
                'limit'  => $limit,
                'offset' => $offset,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return $out;
    }

    public function fetchMessages(array $settings, \DateTimeInterface $since): array
    {
        $settings = $this->freshSettings($settings);
        $out = $this->fetchInboxMessages($settings, $since);
        try {
            $out = array_merge($out, $this->fetchOpenInquiries($settings, $since));
        } catch (\Throwable $e) {
            if ($out === []) {
                throw $e;
            }
        }
        return $out;
    }

    /**
     * Commerce Message API — conversation list (demo EbayChannelClient::fetchConversations).
     *
     * @param array<string, mixed> $settings
     * @return array{conversations:list<array<string,mixed>>,next:?int,total:int,settings:array<string,mixed>}
     */
    public function fetchConversations(
        array $settings,
        int $limit = 50,
        int $offset = 0,
        string $conversationType = 'FROM_MEMBERS',
    ): array {
        $settings = $this->freshSettings($settings);
        $limit = max(1, min(50, $limit));
        $offset = max(0, $offset);
        if (!in_array($conversationType, ['FROM_EBAY', 'FROM_MEMBERS'], true)) {
            $conversationType = 'FROM_MEMBERS';
        }

        $url = $this->apiHost() . '/commerce/message/v1/conversation?' . http_build_query([
            'conversation_type' => $conversationType,
            'limit'             => $limit,
            'offset'            => $offset,
        ], '', '&', PHP_QUERY_RFC3986);

        $res = $this->http->request('GET', $url, [
            'Authorization'            => 'Bearer ' . $settings['access_token'],
            'Accept'                   => 'application/json',
            'X-EBAY-C-MARKETPLACE-ID'  => $this->marketplaceId($settings),
        ]);
        $this->assertConversationApiOk($res, 'eBay conversation list');

        $conversations = is_array($res['json']['conversations'] ?? null)
            ? array_values(array_filter($res['json']['conversations'], 'is_array'))
            : [];
        foreach ($conversations as $i => $conv) {
            if (isset($conv['latestMessage']) && is_array($conv['latestMessage'])) {
                $conversations[$i]['latestMessage'] = $this->normalizeConversationMessage($conv['latestMessage']);
            }
        }

        $currentOffset = (int) ($res['json']['offset'] ?? $offset);
        $total = (int) ($res['json']['total'] ?? count($conversations));
        $nextUri = trim((string) ($res['json']['next'] ?? ''));
        $next = null;
        if ($nextUri !== '' && $conversations !== []) {
            $qs = parse_url($nextUri, PHP_URL_QUERY);
            if (is_string($qs) && $qs !== '') {
                parse_str($qs, $params);
                if (isset($params['offset']) && is_numeric($params['offset']) && (int) $params['offset'] > $currentOffset) {
                    $next = (int) $params['offset'];
                }
            }
            if ($next === null) {
                $candidate = $currentOffset + count($conversations);
                $next = $candidate > $currentOffset ? $candidate : null;
            }
        }

        return [
            'conversations' => $conversations,
            'next'          => $next,
            'total'         => $total,
            'settings'      => $settings,
        ];
    }

    /**
     * Commerce Message API — full thread (demo EbayChannelClient::fetchConversation).
     *
     * @param array<string, mixed> $settings
     * @return array{messages:list<array<string,mixed>>,settings:array<string,mixed>}
     */
    public function fetchConversation(
        array $settings,
        string $conversationId,
        string $conversationType = 'FROM_MEMBERS',
    ): array {
        $settings = $this->freshSettings($settings);
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            throw new \InvalidArgumentException('Conversation ID is required.');
        }
        if (!in_array($conversationType, ['FROM_EBAY', 'FROM_MEMBERS'], true)) {
            $conversationType = 'FROM_MEMBERS';
        }

        $messages = [];
        $offset = 0;
        $limit = 50;
        for ($guard = 0; $guard < 20; $guard++) {
            $url = $this->apiHost() . '/commerce/message/v1/conversation/' . rawurlencode($conversationId)
                . '?' . http_build_query([
                    'conversation_type' => $conversationType,
                    'limit'             => $limit,
                    'offset'            => $offset,
                ], '', '&', PHP_QUERY_RFC3986);

            $res = $this->http->request('GET', $url, [
                'Authorization'           => 'Bearer ' . $settings['access_token'],
                'Accept'                  => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId($settings),
            ]);
            $this->assertConversationApiOk($res, 'eBay conversation thread');

            $page = is_array($res['json']['messages'] ?? null)
                ? array_values(array_filter($res['json']['messages'], 'is_array'))
                : [];
            foreach ($page as $m) {
                $messages[] = $this->normalizeConversationMessage($m);
            }

            $nextUri = trim((string) ($res['json']['next'] ?? ''));
            if ($nextUri === '' || $page === []) {
                break;
            }
            $nextOffset = $offset + count($page);
            $qs = parse_url($nextUri, PHP_URL_QUERY);
            if (is_string($qs) && $qs !== '') {
                parse_str($qs, $params);
                if (isset($params['offset']) && is_numeric($params['offset'])) {
                    $nextOffset = (int) $params['offset'];
                }
            }
            if ($nextOffset <= $offset) {
                break;
            }
            $offset = $nextOffset;
        }

        return ['messages' => $messages, 'settings' => $settings];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>,payload?:array<string,mixed>}
     */
    public function sendConversationMessage(
        array $settings,
        string $conversationId,
        string $body,
    ): array {
        $settings = $this->freshSettings($settings);
        $conversationId = trim($conversationId);
        $body = trim($body);
        if ($conversationId === '') {
            return ['ok' => false, 'message' => 'Conversation ID is required.', 'settings' => $settings];
        }
        if ($body === '') {
            return ['ok' => false, 'message' => 'Message body cannot be empty.', 'settings' => $settings];
        }
        if (mb_strlen($body) > 2000) {
            return ['ok' => false, 'message' => 'Message body exceeds 2000 characters.', 'settings' => $settings];
        }

        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/commerce/message/v1/send_message',
            [
                'Authorization'           => 'Bearer ' . $settings['access_token'],
                'Accept'                  => 'application/json',
                'Content-Type'            => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId($settings),
            ],
            [
                'conversationId' => $conversationId,
                'messageText'    => $body,
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300) {
            $detail = $this->ebayErrorDetail($res);
            return [
                'ok'       => false,
                'message'  => 'eBay send failed' . ($detail !== '' ? ': ' . $detail : ' (HTTP ' . $res['status'] . ')'),
                'settings' => $settings,
            ];
        }

        return [
            'ok'       => true,
            'message'  => 'Reply sent.',
            'settings' => $settings,
            'payload'  => is_array($res['json']) ? $res['json'] : [],
        ];
    }

    /**
     * Mark conversation read on eBay (Commerce Message update_conversation).
     *
     * @param array<string, mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function markConversationRead(
        array $settings,
        string $conversationId,
        string $conversationType = 'FROM_MEMBERS'
    ): array {
        $settings = $this->freshSettings($settings);
        $conversationId = trim($conversationId);
        if ($conversationId === '') {
            return ['ok' => false, 'message' => 'Conversation ID is required.', 'settings' => $settings];
        }
        if (!in_array($conversationType, ['FROM_EBAY', 'FROM_MEMBERS'], true)) {
            $conversationType = 'FROM_MEMBERS';
        }
        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/commerce/message/v1/update_conversation',
            [
                'Authorization'           => 'Bearer ' . $settings['access_token'],
                'Accept'                  => 'application/json',
                'Content-Type'            => 'application/json',
                'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId($settings),
            ],
            [
                'conversationId'   => $conversationId,
                'conversationType' => $conversationType,
                'read'             => true,
            ]
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'eBay mark-read HTTP ' . $res['status'],
                'settings' => $settings,
            ];
        }
        return ['ok' => true, 'message' => 'Marked read.', 'settings' => $settings];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{members:list<array<string,mixed>>,total:int,settings:array<string,mixed>}
     */
    public function searchSupportCases(
        array $settings,
        string $statusFilter = 'OPEN',
        int $limit = 50,
        int $offset = 0,
        int $lookbackDays = 90
    ): array {
        return $this->postOrderSearch($settings, 'casemanagement', 'case_status_filter', $statusFilter, $limit, $offset, $lookbackDays);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{members:list<array<string,mixed>>,total:int,settings:array<string,mixed>}
     */
    public function searchSupportInquiries(
        array $settings,
        string $statusFilter = 'OPEN',
        int $limit = 50,
        int $offset = 0,
        int $lookbackDays = 90
    ): array {
        return $this->postOrderSearch($settings, 'inquiry', 'inquiry_status', $statusFilter, $limit, $offset, $lookbackDays);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{payload:array<string,mixed>,settings:array<string,mixed>}
     */
    public function getSupportCase(array $settings, string $caseId): array
    {
        return $this->postOrderGet($settings, 'casemanagement', $caseId);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{payload:array<string,mixed>,settings:array<string,mixed>}
     */
    public function getSupportInquiry(array $settings, string $inquiryId): array
    {
        return $this->postOrderGet($settings, 'inquiry', $inquiryId);
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function replySupportInquiry(array $settings, string $inquiryId, string $message): array
    {
        $settings = $this->freshSettings($settings);
        $inquiryId = trim($inquiryId);
        $message = trim($message);
        if ($inquiryId === '' || $message === '') {
            return ['ok' => false, 'message' => 'Inquiry id and message required.', 'settings' => $settings];
        }
        $res = $this->postOrderRequest(
            $settings,
            'POST',
            '/post-order/v2/inquiry/' . rawurlencode($inquiryId) . '/send_message',
            ['message' => ['content' => mb_substr($message, 0, 1000)]]
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'eBay inquiry reply HTTP ' . $res['status'] . ': ' . substr((string) $res['body'], 0, 180),
                'settings' => $settings,
            ];
        }
        return ['ok' => true, 'message' => 'Inquiry reply sent.', 'settings' => $settings];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function issueSupportInquiryRefund(array $settings, string $inquiryId, string $comments = ''): array
    {
        $settings = $this->freshSettings($settings);
        $inquiryId = trim($inquiryId);
        if ($inquiryId === '') {
            return ['ok' => false, 'message' => 'Inquiry id required.', 'settings' => $settings];
        }
        $payload = null;
        $comments = trim($comments);
        if ($comments !== '') {
            $payload = ['comments' => ['content' => $comments]];
        }
        $res = $this->postOrderRequest(
            $settings,
            'POST',
            '/post-order/v2/inquiry/' . rawurlencode($inquiryId) . '/issue_refund',
            $payload
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'eBay inquiry refund HTTP ' . $res['status'],
                'settings' => $settings,
            ];
        }
        return ['ok' => true, 'message' => 'Inquiry refund issued.', 'settings' => $settings];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function issueSupportCaseRefund(array $settings, string $caseId, string $comments = ''): array
    {
        $settings = $this->freshSettings($settings);
        $caseId = trim($caseId);
        if ($caseId === '') {
            return ['ok' => false, 'message' => 'Case id required.', 'settings' => $settings];
        }
        $payload = null;
        $comments = trim($comments);
        if ($comments !== '') {
            $payload = ['content' => $comments];
        }
        $res = $this->postOrderRequest(
            $settings,
            'POST',
            '/post-order/v2/casemanagement/' . rawurlencode($caseId) . '/issue_refund',
            $payload
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'eBay case refund HTTP ' . $res['status'],
                'settings' => $settings,
            ];
        }
        return ['ok' => true, 'message' => 'Case refund issued.', 'settings' => $settings];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{members:list<array<string,mixed>>,total:int,settings:array<string,mixed>}
     */
    private function postOrderSearch(
        array $settings,
        string $resource,
        string $statusKey,
        string $statusFilter,
        int $limit,
        int $offset,
        int $lookbackDays
    ): array {
        $settings = $this->freshSettings($settings);
        $to = gmdate('Y-m-d\TH:i:s\Z');
        $from = gmdate('Y-m-d\TH:i:s\Z', time() - max(1, $lookbackDays) * 86400);
        $dateFromKey = $resource === 'inquiry'
            ? 'inquiry_creation_date_range_from'
            : 'case_creation_date_range_from';
        $dateToKey = $resource === 'inquiry'
            ? 'inquiry_creation_date_range_to'
            : 'case_creation_date_range_to';
        $query = [
            $statusKey   => $statusFilter,
            $dateFromKey => $from,
            $dateToKey   => $to,
            'fieldgroups' => 'DEFAULT',
            'limit'      => (string) max(1, min(200, $limit)),
            'offset'     => (string) max(0, $offset),
        ];
        $res = $this->postOrderRequest(
            $settings,
            'GET',
            '/post-order/v2/' . $resource . '/search?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
        );
        $members = $res['json']['members'] ?? $res['json']['inquiries'] ?? $res['json']['cases'] ?? [];
        if (!is_array($members)) {
            $members = [];
        }
        $members = array_values(array_filter($members, 'is_array'));
        return [
            'members'  => $members,
            'total'    => (int) ($res['json']['total'] ?? $res['json']['paginationOutput']['totalEntries'] ?? count($members)),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{payload:array<string,mixed>,settings:array<string,mixed>}
     */
    private function postOrderGet(array $settings, string $resource, string $id): array
    {
        $settings = $this->freshSettings($settings);
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('External id required.');
        }
        $res = $this->postOrderRequest(
            $settings,
            'GET',
            '/post-order/v2/' . $resource . '/' . rawurlencode($id)
        );
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new \RuntimeException('eBay ' . $resource . ' GET HTTP ' . $res['status']);
        }
        return [
            'payload'  => is_array($res['json']) ? $res['json'] : [],
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed>|null $body
     * @return array{status:int,body:string,json:mixed,error?:string}
     */
    private function postOrderRequest(array $settings, string $method, string $path, ?array $body = null): array
    {
        $headers = [
            'Authorization'           => 'IAF ' . ($settings['access_token'] ?? ''),
            'Accept'                  => 'application/json',
            'Content-Type'            => 'application/json',
            'X-EBAY-C-MARKETPLACE-ID' => $this->marketplaceId($settings),
        ];
        $url = $this->apiHost() . $path;
        $res = $this->http->request($method, $url, $headers, $body);
        if (in_array((int) $res['status'], [401, 403], true)) {
            $headers['Authorization'] = 'TOKEN ' . ($settings['access_token'] ?? '');
            $res = $this->http->request($method, $url, $headers, $body);
        }
        return $res;
    }

    /** @param array<string, mixed> $msg @return array<string, mixed> */
    private function normalizeConversationMessage(array $msg): array
    {
        if (!isset($msg['messageBody']) && isset($msg['messageText'])) {
            $msg['messageBody'] = $msg['messageText'];
        }
        if (!isset($msg['senderUsername'])) {
            $msg['senderUsername'] = $msg['senderUserName'] ?? $msg['sender'] ?? '';
        }
        if (!isset($msg['recipientUsername'])) {
            $msg['recipientUsername'] = $msg['recipientUserName'] ?? $msg['recipient'] ?? '';
        }
        if (!isset($msg['messageId']) && isset($msg['message_id'])) {
            $msg['messageId'] = $msg['message_id'];
        }
        if (!isset($msg['createdDate']) && isset($msg['created_date'])) {
            $msg['createdDate'] = $msg['created_date'];
        }
        return $msg;
    }

    /** @param array<string, mixed> $settings */
    private function marketplaceId(array $settings): string
    {
        $mp = strtoupper(trim((string) ($settings['marketplace'] ?? $settings['marketplace_id'] ?? 'EBAY_GB')));
        return $mp !== '' ? $mp : 'EBAY_GB';
    }

    /**
     * My Messages inbox (buyer mail). Open inquiries are a separate Post-Order feed.
     *
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    private function fetchInboxMessages(array $settings, \DateTimeInterface $since): array
    {
        $until = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $start = $since->format('Y-m-d\TH:i:s.000\Z');
        $end = $until->format('Y-m-d\TH:i:s.000\Z');
        $byId = [];
        $pages = 1;
        for ($page = 1; $page <= $pages && $page <= 10; $page++) {
            $xml = $this->tradingCall($settings, 'GetMyMessages', <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetMyMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <DetailLevel>ReturnHeaders</DetailLevel>
  <FolderID>0</FolderID>
  <StartTime>{$start}</StartTime>
  <EndTime>{$end}</EndTime>
  <Pagination>
    <EntriesPerPage>100</EntriesPerPage>
    <PageNumber>{$page}</PageNumber>
  </Pagination>
</GetMyMessagesRequest>
XML);
            $totalPages = (int) ($xml->PaginationResult->TotalNumberOfPages ?? 1);
            if ($totalPages > $pages) {
                $pages = min(10, $totalPages);
            }
            foreach ($xml->Messages->Message ?? [] as $message) {
                $row = $this->mapEbayMessage($message, $since);
                if ($row === null) {
                    continue;
                }
                $byId[(string) $row['raw']['message_id']] = $row;
            }
        }

        foreach (array_chunk(array_keys($byId), 10) as $chunk) {
            $idXml = '';
            foreach ($chunk as $id) {
                $idXml .= '<MessageID>' . htmlspecialchars((string) $id, ENT_XML1) . '</MessageID>';
            }
            try {
                $xml = $this->tradingCall($settings, 'GetMyMessages', <<<XML
<?xml version="1.0" encoding="utf-8"?>
<GetMyMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">
  <DetailLevel>ReturnMessages</DetailLevel>
  <MessageIDs>{$idXml}</MessageIDs>
</GetMyMessagesRequest>
XML);
            } catch (\Throwable) {
                continue;
            }
            foreach ($xml->Messages->Message ?? [] as $message) {
                $id = trim((string) ($message->MessageID ?? ''));
                if ($id === '' || !isset($byId[$id])) {
                    continue;
                }
                // GetMyMessages returns HTML in Content (demo EbayChannelClient) and
                // optional plain text in Text. Prefer Content so the mail view can
                // render eBay's HTML the same way as the demo module.
                $content = $this->ebayMessageContent($message);
                if ($content !== '') {
                    $byId[$id]['body'] = $content;
                }
            }
        }

        return array_values($byId);
    }

    /** @return array<string, mixed>|null */
    private function mapEbayMessage(\SimpleXMLElement $message, \DateTimeInterface $since): ?array
    {
        $id = trim((string) ($message->MessageID ?? ''));
        if ($id === '') {
            return null;
        }
        $content = $this->ebayMessageContent($message);
        $sender = trim((string) ($message->Sender ?? ''));
        $subject = trim((string) ($message->Subject ?? '')) ?: 'eBay message';
        $read = strtolower((string) ($message->Read ?? 'false')) === 'true';
        $type = strtolower(trim((string) ($message->MessageType ?? '')));
        $buyerMessage = in_array($type, ['asksellerquestion', 'responsetoasq', 'contacttransactionpartner', 'contactebaymember'], true);
        $preview = $content !== '' ? $content : $subject;

        return [
            'external_id'       => 'ebay-msg-' . $id,
            'thread_id'         => trim((string) ($message->ExternalMessageID ?? $id)),
            'order_external_id' => trim((string) ($message->ItemID ?? '')),
            'buyer_name'        => $sender !== '' ? $sender : 'eBay Buyer',
            'buyer_email'       => '',
            'subject'           => $subject,
            'body'              => $preview,
            'direction'         => 'inbound',
            'status'            => $read ? 'open' : 'unread',
            'received_at'       => trim((string) ($message->ReceiveDate ?? $since->format('c'))),
            'provider'          => 'ebay',
            'folder'            => $buyerMessage ? 'message' : $this->mailFolder($sender, $subject, $preview),
            'raw'               => ['message_id' => $id, 'item_id' => trim((string) ($message->ItemID ?? ''))],
        ];
    }

    private function ebayMessageContent(\SimpleXMLElement $message): string
    {
        $content = trim((string) ($message->Content ?? ''));
        if ($content !== '') {
            return $content;
        }
        return trim((string) ($message->Text ?? ''));
    }

    private function mailFolder(string $sender, string $subject, string $body): string
    {
        $sender = strtolower(trim($sender));
        $blob = strtolower($subject . ' ' . $body);
        $system = $sender === 'ebay'
            || $sender === 'ebay buyer'
            || str_contains($sender, 'customer service')
            || str_contains($blob, 'ebay customer service');
        return $system ? 'mail' : 'message';
    }

    private function plainMessageText(string $text): string
    {
        return \App\Integrations\MessageImporter::readable($text);
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<array<string, mixed>>
     */
    private function fetchOpenInquiries(array $settings, \DateTimeInterface $since): array
    {
        $out = [];
        $offset = 0;
        $limit = 200;
        for ($page = 0; $page < 20; $page++) {
            $url = $this->apiHost() . '/post-order/v2/inquiry/search?' . http_build_query([
                'inquiry_status' => 'OPEN',
                'limit'          => $limit,
                'offset'         => $offset,
            ]);
            $res = $this->http->request('GET', $url, [
                'Authorization'           => 'IAF ' . $settings['access_token'],
                'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_UK',
                'Accept'                  => 'application/json',
                'Content-Type'            => 'application/json',
            ]);
            $this->assertEbayOk($res, 'eBay inquiry sync');

            $members = $res['json']['members'] ?? $res['json']['inquiries'] ?? [];
            if (!is_array($members) || $members === []) {
                break;
            }
            foreach ($members as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $id = (string) ($m['inquiryId'] ?? $m['inquiryid'] ?? '');
                if ($id === '') {
                    continue;
                }
                $buyer = $m['buyer'] ?? 'eBay Buyer';
                $buyerName = is_array($buyer)
                    ? (string) ($buyer['username'] ?? $buyer['buyerLoginName'] ?? 'eBay Buyer')
                    : (string) $buyer;
                $order = $m['order'] ?? null;
                $orderId = is_array($order) ? (string) ($order['orderId'] ?? '') : '';
                if ($orderId === '') {
                    $orderId = (string) ($m['itemId'] ?? '');
                }
                $out[] = [
                    'external_id'       => 'ebay-inq-' . $id,
                    'thread_id'         => $id,
                    'order_external_id' => $orderId,
                    'buyer_name'        => $buyerName !== '' ? $buyerName : 'eBay Buyer',
                    'buyer_email'       => '',
                    'subject'           => (string) ($m['inquiryStatusEnum'] ?? 'eBay inquiry'),
                    'body'              => (string) ($m['claimQuantity'] ?? '') !== ''
                        ? 'Inquiry open — qty ' . $m['claimQuantity']
                        : 'Open eBay buyer inquiry',
                    'direction'         => 'inbound',
                    'status'            => 'unread',
                    'received_at'       => (string) ($m['creationDate']['value'] ?? $since->format('c')),
                    'provider'          => 'ebay',
                    'folder'            => 'message',
                    'raw'               => $m,
                ];
            }

            $count = count($members);
            $offset += $count;
            $total = (int) ($res['json']['total'] ?? $res['json']['paginationOutput']['totalEntries'] ?? 0);
            if ($count < $limit || ($total > 0 && $offset >= $total)) {
                break;
            }
        }

        return $out;
    }

    private function tradingCall(array $settings, string $call, string $body): \SimpleXMLElement
    {
        $res = $this->http->request(
            'POST',
            $this->isSandbox($this->ebayConfig()) ? 'https://api.sandbox.ebay.com/ws/api.dll' : 'https://api.ebay.com/ws/api.dll',
            [
                'X-EBAY-API-CALL-NAME'            => $call,
                'X-EBAY-API-SITEID'               => '3',
                'X-EBAY-API-COMPATIBILITY-LEVEL'  => '1219',
                'X-EBAY-API-IAF-TOKEN'            => (string) $settings['access_token'],
                'Content-Type'                    => 'text/xml',
            ],
            $body
        );
        $status = (int) ($res['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            $detail = $this->ebayErrorDetail($res);
            throw new \RuntimeException('eBay message sync HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
        }
        $raw = (string) ($res['body'] ?? '');
        $clean = preg_replace('/xmlns(:[a-z0-9]+)?="[^"]*"/i', '', $raw) ?? $raw;
        $xml = simplexml_load_string($clean, 'SimpleXMLElement', LIBXML_NONET);
        if (!$xml instanceof \SimpleXMLElement) {
            throw new \RuntimeException('eBay message sync returned a response that could not be read.');
        }
        $ack = strtoupper(trim((string) ($xml->Ack ?? '')));
        if ($ack === 'FAILURE') {
            $detail = trim((string) ($xml->Errors->LongMessage ?? $xml->Errors->ShortMessage ?? ''));
            throw new \RuntimeException('eBay message sync' . ($detail !== '' ? ': ' . substr($detail, 0, 240) : ' failed'));
        }
        return $xml;
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function normalizeOrder(array $o): array
    {
        $ship = $o['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [];
        $addr = is_array($ship) ? ($ship['contactAddress'] ?? []) : [];
        $name = is_array($ship) ? (string) ($ship['fullName'] ?? '') : '';
        $username = (string) ($o['buyer']['username'] ?? '');
        $email = (string) ($o['buyer']['buyerRegistrationAddress']['email'] ?? '');
        if ($email === '') {
            $email = $username !== '' ? $username : 'ebay@buyer.local';
        }
        $total = (float) ($o['pricingSummary']['total']['value'] ?? 0);
        $currency = (string) ($o['pricingSummary']['total']['currency'] ?? 'GBP');
        $items = [];
        foreach (($o['lineItems'] ?? []) as $li) {
            if (!is_array($li)) {
                continue;
            }
            $qty = max(1, (int) ($li['quantity'] ?? 1));
            $lineCost = (float) ($li['lineItemCost']['value'] ?? 0);
            // eBay lineItemCost is the line total (unit × qty), not the unit price.
            $unit = $qty > 0 ? round($lineCost / $qty, 4) : $lineCost;
            $sku = trim((string) ($li['sku'] ?? ''));
            if ($sku === '') {
                $sku = trim((string) ($li['legacyVariationId'] ?? ''));
            }
            $items[] = [
                'sku'         => $sku,
                'name'        => (string) ($li['title'] ?? ''),
                'qty'         => $qty,
                'unit_price'  => $unit,
                'listing_id'  => trim((string) ($li['legacyItemId'] ?? '')),
            ];
        }

        return [
            'external_order_id' => (string) ($o['orderId'] ?? ''),
            'status'            => $this->mapStatus((string) ($o['orderFulfillmentStatus'] ?? '')),
            'buyer_username'    => $username,
            'buyer_email'       => $email,
            'shipping_name'     => $name !== '' ? $name : 'eBay Buyer',
            'total'             => $total,
            'currency'          => $currency,
            'shipping_method'   => (string) ($o['fulfillmentStartInstructions'][0]['shippingStep']['shippingServiceCode'] ?? 'eBay'),
            'shipping_address'  => [
                'line1'    => (string) ($addr['addressLine1'] ?? ''),
                'city'     => (string) ($addr['city'] ?? ''),
                'postcode' => (string) ($addr['postalCode'] ?? ''),
                'country'  => (string) ($addr['countryCode'] ?? 'GB'),
                'phone'    => trim((string) (
                    (is_array($ship['primaryPhone'] ?? null)
                        ? ($ship['primaryPhone']['number']
                            ?? $ship['primaryPhone']['phoneNumber']
                            ?? $ship['primaryPhone']['value']
                            ?? '')
                        : ($ship['primaryPhone'] ?? ''))
                )),
            ],
            'items'             => $items,
            'created_at'        => $this->parseOrderDate($o['creationDate'] ?? $o['createdDate'] ?? null),
            'raw_provider'      => 'ebay',
        ];
    }

    private function parseOrderDate(mixed $raw): ?string
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
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function mapStatus(string $fulfillment): string
    {
        return match (strtoupper($fulfillment)) {
            'FULFILLED', 'COMPLETE' => 'despatched',
            'IN_PROGRESS' => 'processing',
            default => 'awaiting_despatch',
        };
    }

    /**
     * Refresh the user token when it is missing or near expiry.
     * A failed refresh is stored on $tokenError and the previous settings are returned.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function ensureAccessToken(array $settings): array
    {
        $this->tokenError = null;
        $expires = (int) ($settings['token_expires_at'] ?? 0);
        if (($settings['access_token'] ?? '') !== '' && $expires > time() + 60) {
            return $settings;
        }
        $refresh = (string) ($settings['refresh_token'] ?? '');
        if ($refresh === '') {
            return $settings;
        }

        $c = $this->ebayConfig();
        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/identity/v1/oauth2/token',
            [
                'Authorization' => 'Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
            ]
        );

        $access = is_array($res['json']) ? (string) ($res['json']['access_token'] ?? '') : '';
        if ($res['status'] < 200 || $res['status'] >= 300 || $access === '') {
            $detail = $this->ebayErrorDetail($res);
            $this->tokenError = 'eBay token refresh failed'
                . ($detail !== '' ? ': ' . $detail : ' (HTTP ' . (int) $res['status'] . ')');
            return $settings;
        }

        $settings['access_token'] = $access;
        if (!empty($res['json']['refresh_token'])) {
            $settings['refresh_token'] = (string) $res['json']['refresh_token'];
        }
        $settings['token_expires_at'] = (string) (time() + (int) ($res['json']['expires_in'] ?? 7200));
        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function freshSettings(array $settings): array
    {
        $settings = $this->ensureAccessToken($settings);
        if ($this->tokenError !== null) {
            throw new \RuntimeException($this->tokenError);
        }
        if (($settings['access_token'] ?? '') === '') {
            throw new \RuntimeException('eBay access token missing. Reconnect the channel.');
        }
        return $settings;
    }

    /** @param array{status:int, body:string, json:array<string,mixed>|null, error:?string} $res */
    private function assertEbayOk(array $res, string $action): void
    {
        $status = (int) ($res['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return;
        }
        $detail = $this->ebayErrorDetail($res);
        throw new \RuntimeException($action . ' HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
    }

    /**
     * Commerce Message API needs the commerce.message OAuth scope (production only).
     * Refreshing an old token never adds new scopes — the channel must be re-linked.
     *
     * @param array{status:int, body:string, json:array<string,mixed>|null, error:?string} $res
     */
    private function assertConversationApiOk(array $res, string $action): void
    {
        $status = (int) ($res['status'] ?? 0);
        if ($status >= 200 && $status < 300) {
            return;
        }
        $detail = $this->ebayErrorDetail($res);
        if ($status === 403 || stripos($detail, 'access denied') !== false || stripos($detail, 'insufficient') !== false) {
            throw new \RuntimeException(
                $action . ' Access denied. Re-link the eBay channel so the token includes '
                . 'commerce.message (Settings → Channels → eBay → Connect). '
                . 'In eBay Developer Portal, enable the Message API / commerce.message scope on this app. '
                . 'This API is production-only (not sandbox).'
                . ($detail !== '' ? ' eBay said: ' . $detail : '')
            );
        }
        throw new \RuntimeException($action . ' HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
    }

    /** @param array{status?:int, body?:string, json?:array<string,mixed>|null, error?:?string} $res */
    private function ebayErrorDetail(array $res): string
    {
        $json = $res['json'] ?? null;
        if (is_array($json)) {
            $first = $json['errors'][0] ?? null;
            if (is_array($first)) {
                $msg = trim((string) ($first['message'] ?? ''));
                if ($msg !== '') {
                    return substr($msg, 0, 240);
                }
            }
            $desc = trim((string) ($json['error_description'] ?? ''));
            if ($desc === '' && isset($json['error']) && is_string($json['error'])) {
                $desc = trim($json['error']);
            }
            if ($desc !== '') {
                return substr($desc, 0, 240);
            }
        }
        $err = trim((string) ($res['error'] ?? ''));
        if ($err !== '') {
            return substr($err, 0, 240);
        }
        return substr(trim((string) ($res['body'] ?? '')), 0, 240);
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,items:list<array{external_id:string,sku:string,title:string,price:float,qty:int,status:string}>,settings:array<string,mixed>}
     */
    public function fetchListings(array $settings): array
    {
        $settings = $this->ensureAccessToken($settings);
        if ($this->tokenError !== null || ($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'message'  => $this->tokenError ?? 'eBay not linked (missing access token).',
                'items'    => [],
                'settings' => $settings,
            ];
        }
        $items = [];
        $offset = 0;
        for ($page = 0; $page < 4; $page++) {
            $res = $this->http->request(
                'GET',
                $this->apiHost() . '/sell/inventory/v1/inventory_item?limit=50&offset=' . $offset,
                [
                    'Authorization' => 'Bearer ' . $settings['access_token'],
                    'Accept'        => 'application/json',
                ]
            );
            if ($res['status'] < 200 || $res['status'] >= 300) {
                if ($items !== []) {
                    break;
                }
                return [
                    'ok'       => false,
                    'message'  => 'eBay listings HTTP ' . $res['status'] . ': ' . substr((string) ($res['body'] ?? ''), 0, 200),
                    'items'    => [],
                    'settings' => $settings,
                ];
            }
            $batch = is_array($res['json']['inventoryItems'] ?? null) ? $res['json']['inventoryItems'] : [];
            foreach ($batch as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $items[] = [
                    'external_id' => $sku,
                    'sku'         => substr($sku, 0, 64),
                    'title'       => substr(trim((string) ($item['product']['title'] ?? $sku)), 0, 255),
                    'price'       => 0.0,
                    'qty'         => (int) ($item['availability']['shipToLocationAvailability']['quantity'] ?? 0),
                    'status'      => 'active',
                ];
            }
            if (count($batch) < 50) {
                break;
            }
            $offset += 50;
        }
        return [
            'ok'       => true,
            'message'  => count($items) . ' eBay listing(s) read.',
            'items'    => $items,
            'settings' => $settings,
        ];
    }

    /** @return array{ok:bool, settings?:array<string,string>, message?:string} */
    private function exchangeCode(string $code): array
    {
        $c = $this->ebayConfig();
        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/identity/v1/oauth2/token',
            [
                'Authorization' => 'Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            [
                'grant_type'   => 'authorization_code',
                'code'         => $code,
                'redirect_uri' => (string) $c['ru_name'],
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            $detail = trim((string) ($res['error'] ?? ''));
            if ($detail === '') {
                $detail = substr((string) $res['body'], 0, 240);
            }
            return [
                'ok'      => false,
                'message' => 'eBay token exchange failed HTTP ' . $res['status'] . ': ' . $detail,
            ];
        }

        $access = (string) ($res['json']['access_token'] ?? '');
        if ($access === '') {
            return ['ok' => false, 'message' => 'eBay token response missing access_token.'];
        }

        return [
            'ok' => true,
            'settings' => [
                'access_token'      => $access,
                'refresh_token'     => (string) ($res['json']['refresh_token'] ?? ''),
                'token_expires_at'  => (string) (time() + (int) ($res['json']['expires_in'] ?? 7200)),
                'token_type'        => (string) ($res['json']['token_type'] ?? 'Bearer'),
            ],
        ];
    }

    /** @param array<string, string> $settings */
    private function fetchUserId(array $settings): string
    {
        if (($settings['access_token'] ?? '') === '') {
            return '';
        }
        $res = $this->http->request('GET', $this->apiHost() . '/commerce/identity/v1/user/', [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Accept'        => 'application/json',
        ]);
        if (!is_array($res['json'])) {
            return '';
        }
        return (string) ($res['json']['username'] ?? $res['json']['userId'] ?? '');
    }

    /** @return array<string, mixed> */
    private function ebayConfig(): array
    {
        return $this->cfg->provider('ebay');
    }

    /** @param array<string, mixed> $c */
    private function isSandbox(array $c): bool
    {
        if (!array_key_exists('sandbox', $c)) {
            return true;
        }
        $v = $c['sandbox'];
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (int) $v === 1;
        }
        $s = strtolower(trim((string) $v));
        return !in_array($s, ['0', 'false', 'no', 'off', ''], true);
    }

    /**
     * Set merchant quantity on an Inventory API SKU. Soft-fails when the SKU is not in eBay inventory.
     *
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function pushQuantity(array $settings, string $sku, int $quantity): array
    {
        $settings = $this->ensureAccessToken($settings);
        $sku = trim($sku);
        if ($this->tokenError !== null || ($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'message'  => $this->tokenError ?? 'eBay not linked (missing access token).',
                'settings' => $settings,
            ];
        }
        if ($sku === '') {
            return ['ok' => false, 'message' => 'eBay stock push needs a listing SKU.', 'settings' => $settings];
        }

        $res = $this->http->request(
            'POST',
            $this->apiHost() . '/sell/inventory/v1/bulk_update_price_quantity',
            [
                'Authorization'    => 'Bearer ' . $settings['access_token'],
                'Content-Type'     => 'application/json',
                'Content-Language' => 'en-GB',
                'Accept'           => 'application/json',
            ],
            [
                'requests' => [[
                    'sku' => $sku,
                    'shipToLocationAvailability' => ['quantity' => max(0, $quantity)],
                ]],
            ]
        );

        $row = is_array($res['json']['responses'][0] ?? null) ? $res['json']['responses'][0] : [];
        $code = (int) ($row['statusCode'] ?? $res['status']);
        if ($res['status'] >= 200 && $res['status'] < 300 && $code >= 200 && $code < 300) {
            return [
                'ok'       => true,
                'message'  => 'eBay quantity set to ' . max(0, $quantity) . ' for ' . $sku . '.',
                'settings' => $settings,
            ];
        }

        $detail = '';
        if (isset($row['errors']) && is_array($row['errors'])) {
            $detail = (string) ($row['errors'][0]['message'] ?? '');
        }
        if ($detail === '') {
            $detail = substr((string) ($res['body'] ?? ''), 0, 200);
        }

        return [
            'ok'       => false,
            'message'  => 'eBay stock HTTP ' . $res['status'] . ': ' . $detail,
            'settings' => $settings,
        ];
    }

    private function ebayNextUrl(string $next): string
    {
        $next = trim($next);
        if (!str_starts_with($next, 'https://')) {
            return '';
        }
        $host = parse_url($next, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), ['api.ebay.com', 'api.sandbox.ebay.com'], true)) {
            return '';
        }
        return $next;
    }

    private function apiHost(): string
    {
        return $this->isSandbox($this->ebayConfig())
            ? 'https://api.sandbox.ebay.com'
            : 'https://api.ebay.com';
    }
}
