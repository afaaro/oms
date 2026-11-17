<?php

declare(strict_types=1);

namespace App\Integrations\Shopify;

use App\Integrations\BaseConnector;

final class ShopifyConnector extends BaseConnector
{
    public function key(): string
    {
        return 'shopify';
    }

    public function label(): string
    {
        return 'Shopify';
    }

    public function group(): string
    {
        return 'webstore';
    }

    public function isConfigured(): bool
    {
        $c = $this->cfg->provider('shopify');
        return trim((string) ($c['client_id'] ?? '')) !== ''
            && trim((string) ($c['client_secret'] ?? '')) !== '';
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    public function supportsCredentials(): bool
    {
        // Custom apps can also use Admin API access token pasted as api_key
        return true;
    }

    public function authorizationUrl(array $channel, array $settings, string $state): string
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        if ($shop === '') {
            throw new \RuntimeException('Enter the Shopify store URL (e.g. your-shop.myshopify.com) before connecting.');
        }
        $c = $this->cfg->provider('shopify');
        $redirect = $this->app->url((string) ($c['redirect_path'] ?? 'integrations/oauth/callback'));
        $qs = http_build_query([
            'client_id'    => (string) $c['client_id'],
            'scope'        => (string) ($c['scopes'] ?? 'read_orders,write_orders'),
            'redirect_uri' => $redirect,
            'state'        => $state,
        ]);
        return 'https://' . $shop . '/admin/oauth/authorize?' . $qs;
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        $code = (string) ($query['code'] ?? '');
        $shop = $this->normalizeShop((string) ($query['shop'] ?? $settings['store_url'] ?? ''));
        if ($code === '' || $shop === '') {
            return ['ok' => false, 'message' => 'Shopify callback missing code or shop.'];
        }

        $c = $this->cfg->provider('shopify');
        $res = $this->http->request(
            'POST',
            'https://' . $shop . '/admin/oauth/access_token',
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            [
                'client_id'     => (string) $c['client_id'],
                'client_secret' => (string) $c['client_secret'],
                'code'          => $code,
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return [
                'ok'      => false,
                'message' => 'Shopify token exchange failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 240),
            ];
        }

        $settings['access_token'] = (string) ($res['json']['access_token'] ?? '');
        $settings['store_url'] = $shop;
        $settings['api_key'] = (string) ($c['client_id'] ?? '');
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'oauth';
        $settings['provider'] = 'shopify';
        $settings['linked_user'] = $shop;
        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        $settings['token_expires_at'] = '0'; // offline tokens don't expire the same way

        return ['ok' => true, 'settings' => $settings, 'message' => 'Shopify store linked.'];
    }

    public function testConnection(array $settings): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? '');
        if ($shop === '' || $token === '') {
            return ['ok' => false, 'message' => 'Shopify not linked.'];
        }
        $res = $this->http->request(
            'GET',
            'https://' . $shop . '/admin/api/2024-01/shop.json',
            [
                'X-Shopify-Access-Token' => $token,
                'Accept'                 => 'application/json',
            ]
        );
        if ($res['status'] >= 200 && $res['status'] < 300) {
            $name = (string) ($res['json']['shop']['name'] ?? $shop);
            return ['ok' => true, 'message' => 'Connected to Shopify store: ' . $name];
        }
        return ['ok' => false, 'message' => 'Shopify API HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 200)];
    }

    public function submitFulfillment(array $settings, array $payload): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? $settings['api_key'] ?? '');
        if ($shop === '' || $token === '') {
            return [
                'ok'     => false,
                'status' => 'failed',
                'message'=> 'Shopify not linked (missing store or token).',
            ];
        }

        $external = trim((string) ($payload['external_order_id'] ?? ''));
        $tracking = trim((string) ($payload['tracking_number'] ?? ''));
        if ($external === '' || $tracking === '') {
            return [
                'ok'     => false,
                'status' => 'failed',
                'message'=> 'Shopify fulfillment needs external order id and tracking.',
            ];
        }

        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
        ];

        $orderId = $this->resolveShopifyOrderId($shop, $headers, $external);
        if ($orderId === '') {
            return [
                'ok'     => false,
                'status' => 'failed',
                'message'=> 'Shopify order not found for ' . $external . '.',
            ];
        }

        $foUrl = 'https://' . $shop . '/admin/api/2024-01/orders/' . rawurlencode($orderId) . '/fulfillment_orders.json';
        $foRes = $this->http->request('GET', $foUrl, $headers);
        $fulfillmentOrders = is_array($foRes['json']['fulfillment_orders'] ?? null)
            ? $foRes['json']['fulfillment_orders']
            : [];

        $lineItemsByFo = [];
        foreach ($fulfillmentOrders as $fo) {
            if (!is_array($fo)) {
                continue;
            }
            $foId = (int) ($fo['id'] ?? 0);
            $foStatus = strtolower((string) ($fo['status'] ?? ''));
            if ($foId <= 0 || !in_array($foStatus, ['open', 'in_progress', 'scheduled'], true)) {
                continue;
            }
            $items = [];
            foreach (($fo['line_items'] ?? []) as $li) {
                if (!is_array($li)) {
                    continue;
                }
                $liId = (int) ($li['id'] ?? 0);
                $qty = max(0, (int) ($li['fulfillable_quantity'] ?? $li['quantity'] ?? 0));
                if ($liId > 0 && $qty > 0) {
                    $items[] = ['id' => $liId, 'quantity' => $qty];
                }
            }
            if ($items !== []) {
                $lineItemsByFo[] = [
                    'fulfillment_order_id' => $foId,
                    'fulfillment_order_line_items' => $items,
                ];
            }
        }

        $carrier = trim((string) ($payload['carrier_code'] ?? ''));
        if ($lineItemsByFo !== []) {
            $body = [
                'fulfillment' => [
                    'line_items_by_fulfillment_order' => $lineItemsByFo,
                    'tracking_info' => [
                        'number'  => $tracking,
                        'company' => $carrier !== '' ? $carrier : 'Other',
                    ],
                    'notify_customer' => true,
                ],
            ];
            $res = $this->http->request(
                'POST',
                'https://' . $shop . '/admin/api/2024-01/fulfillments.json',
                $headers,
                $body
            );
        } else {
            // Legacy fallback when fulfillment orders API returns nothing usable.
            $body = [
                'fulfillment' => [
                    'tracking_number'  => $tracking,
                    'tracking_company' => $carrier !== '' ? $carrier : 'Other',
                    'notify_customer'  => true,
                ],
            ];
            $res = $this->http->request(
                'POST',
                'https://' . $shop . '/admin/api/2024-01/orders/' . rawurlencode($orderId) . '/fulfillments.json',
                $headers,
                $body
            );
        }

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'ok'     => true,
                'status' => 'uploaded',
                'message'=> 'Shopify fulfillment created.',
            ];
        }

        return [
            'ok'     => false,
            'status' => 'failed',
            'message'=> 'Shopify fulfillment HTTP ' . $res['status'] . ': ' . substr((string) ($res['error'] ?: $res['body']), 0, 240),
        ];
    }

    public function cancelOrder(array $settings, array $payload): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? $settings['api_key'] ?? '');
        if ($shop === '' || $token === '') {
            return ['ok' => false, 'message' => 'Shopify not linked.'];
        }
        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
        ];
        $external = trim((string) ($payload['external_order_id'] ?? ''));
        $orderId = $this->resolveShopifyOrderId($shop, $headers, $external);
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Shopify order not found.'];
        }
        $res = $this->http->request(
            'POST',
            'https://' . $shop . '/admin/api/2024-01/orders/' . rawurlencode($orderId) . '/cancel.json',
            $headers,
            ['reason' => 'other', 'email' => true]
        );
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'Shopify order cancelled.'];
        }
        return [
            'ok'      => false,
            'message' => 'Shopify cancel HTTP ' . $res['status'] . ': ' . substr((string) $res['body'], 0, 200),
        ];
    }

    public function issueRefund(array $settings, array $payload): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? $settings['api_key'] ?? '');
        if ($shop === '' || $token === '') {
            return ['ok' => false, 'message' => 'Shopify not linked.'];
        }
        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
        ];
        $external = trim((string) ($payload['external_order_id'] ?? ''));
        $orderId = $this->resolveShopifyOrderId($shop, $headers, $external);
        if ($orderId === '') {
            return ['ok' => false, 'message' => 'Shopify order not found.'];
        }
        $amount = round((float) ($payload['amount'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'GBP'))) ?: 'GBP';
        $body = [
            'refund' => [
                'note' => trim((string) ($payload['comments'] ?? 'Refund from StoreFeeder')),
                'notify' => true,
                'transactions' => $amount > 0 ? [[
                    'kind' => 'refund',
                    'amount' => (string) $amount,
                    'currency' => $currency,
                ]] : [],
            ],
        ];
        $res = $this->http->request(
            'POST',
            'https://' . $shop . '/admin/api/2024-01/orders/' . rawurlencode($orderId) . '/refunds.json',
            $headers,
            $body
        );
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'Shopify refund created.'];
        }
        return [
            'ok'      => false,
            'message' => 'Shopify refund HTTP ' . $res['status'] . ': ' . substr((string) $res['body'], 0, 200),
        ];
    }

    /** @param array<string, string> $headers */
    private function resolveShopifyOrderId(string $shop, array $headers, string $external): string
    {
        if (ctype_digit($external)) {
            return $external;
        }

        $name = $external;
        if (!str_starts_with($name, '#')) {
            // StoreFeeder may store "#1001" or "1001"
        }
        $url = 'https://' . $shop . '/admin/api/2024-01/orders.json?' . http_build_query([
            'name'   => $name,
            'status' => 'any',
            'limit'  => 1,
        ]);
        $res = $this->http->request('GET', $url, $headers);
        $orders = $res['json']['orders'] ?? [];
        if (is_array($orders) && isset($orders[0]) && is_array($orders[0])) {
            return (string) ($orders[0]['id'] ?? '');
        }

        // Retry without leading #
        $alt = ltrim($name, '#');
        if ($alt !== $name) {
            $url = 'https://' . $shop . '/admin/api/2024-01/orders.json?' . http_build_query([
                'name'   => '#' . $alt,
                'status' => 'any',
                'limit'  => 1,
            ]);
            $res = $this->http->request('GET', $url, $headers);
            $orders = $res['json']['orders'] ?? [];
            if (is_array($orders) && isset($orders[0]) && is_array($orders[0])) {
                return (string) ($orders[0]['id'] ?? '');
            }
        }

        return '';
    }

    public function fetchOrders(array $settings, \DateTimeInterface $since): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? '');
        if ($shop === '' || $token === '') {
            return [];
        }

        $url = 'https://' . $shop . '/admin/api/2024-01/orders.json?' . http_build_query([
            'status'         => 'any',
            'updated_at_min' => $since->format('c'),
            'limit'          => 50,
        ]);
        $res = $this->http->request('GET', $url, [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
        ]);
        $orders = $res['json']['orders'] ?? [];
        if (!is_array($orders)) {
            return [];
        }

        $out = [];
        foreach ($orders as $o) {
            if (!is_array($o)) {
                continue;
            }
            $ship = $o['shipping_address'] ?? [];
            $items = [];
            foreach (($o['line_items'] ?? []) as $li) {
                if (!is_array($li)) {
                    continue;
                }
                $items[] = [
                    'sku'        => (string) ($li['sku'] ?? ''),
                    'name'       => (string) ($li['name'] ?? ''),
                    'qty'        => max(1, (int) ($li['quantity'] ?? 1)),
                    'unit_price' => (float) ($li['price'] ?? 0),
                ];
            }
            $createdAt = null;
            $createdRaw = trim((string) ($o['created_at'] ?? $o['processed_at'] ?? ''));
            if ($createdRaw !== '') {
                $ts = strtotime($createdRaw);
                if ($ts !== false) {
                    $createdAt = date('Y-m-d H:i:s', $ts);
                }
            }
            $out[] = [
                'external_order_id' => (string) ($o['name'] ?? $o['id'] ?? ''),
                'status'            => !empty($o['cancelled_at']) ? 'cancelled' : (!empty($o['fulfillment_status']) && $o['fulfillment_status'] === 'fulfilled' ? 'despatched' : 'awaiting_despatch'),
                'buyer_email'       => (string) ($o['email'] ?? 'shopify@buyer.local'),
                'shipping_name'     => trim((string) (($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? ''))) ?: 'Shopify Buyer',
                'total'             => (float) ($o['total_price'] ?? 0),
                'currency'          => (string) ($o['currency'] ?? 'GBP'),
                'shipping_method'   => (string) (($o['shipping_lines'][0]['title'] ?? 'Shopify')),
                'shipping_address'  => [
                    'line1'    => (string) ($ship['address1'] ?? ''),
                    'city'     => (string) ($ship['city'] ?? ''),
                    'postcode' => (string) ($ship['zip'] ?? ''),
                    'country'  => (string) ($ship['country_code'] ?? 'GB'),
                ],
                'items'             => $items,
                'created_at'        => $createdAt,
                'raw_provider'      => 'shopify',
            ];
        }
        return $out;
    }

    /**
     * Set available quantity for a SKU at the store's first location (or inventory_location_id).
     *
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function pushQuantity(array $settings, string $sku, int $quantity, string $externalId = ''): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? $settings['api_key'] ?? '');
        $sku = trim($sku);
        if ($shop === '' || $token === '') {
            return ['ok' => false, 'message' => 'Shopify not linked (missing store or token).', 'settings' => $settings];
        }
        if ($sku === '' && trim($externalId) === '') {
            return ['ok' => false, 'message' => 'Shopify stock push needs a SKU or variant id.', 'settings' => $settings];
        }

        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
            'Content-Type'           => 'application/json',
        ];
        $api = 'https://' . $shop . '/admin/api/2024-01';

        $inventoryItemId = 0;
        $variantId = ctype_digit(trim($externalId)) ? trim($externalId) : '';
        if ($variantId !== '') {
            $variant = $this->http->request('GET', $api . '/variants/' . $variantId . '.json', $headers);
            $inventoryItemId = (int) ($variant['json']['variant']['inventory_item_id'] ?? 0);
        }
        if ($inventoryItemId <= 0 && $sku !== '') {
            $safeSku = str_replace(['"', '\\'], '', $sku);
            $gql = $this->http->request('POST', $api . '/graphql.json', $headers, [
                'query'     => 'query ($q: String!) { productVariants(first: 1, query: $q) { nodes { inventoryItem { id } } } }',
                'variables' => ['q' => 'sku:' . $safeSku],
            ]);
            $gid = (string) ($gql['json']['data']['productVariants']['nodes'][0]['inventoryItem']['id'] ?? '');
            if (preg_match('/(\d+)$/', $gid, $m)) {
                $inventoryItemId = (int) $m[1];
            }
        }
        if ($inventoryItemId <= 0) {
            return ['ok' => false, 'message' => 'Shopify variant not found for ' . ($sku !== '' ? $sku : $externalId) . '.', 'settings' => $settings];
        }

        $locationId = (int) ($settings['inventory_location_id'] ?? 0);
        if ($locationId <= 0) {
            $locs = $this->http->request('GET', $api . '/locations.json', $headers);
            foreach (($locs['json']['locations'] ?? []) as $loc) {
                if (!is_array($loc) || !empty($loc['legacy'])) {
                    continue;
                }
                $locationId = (int) ($loc['id'] ?? 0);
                if ($locationId > 0) {
                    break;
                }
            }
            if ($locationId > 0) {
                $settings['inventory_location_id'] = (string) $locationId;
            }
        }
        if ($locationId <= 0) {
            return ['ok' => false, 'message' => 'Shopify has no inventory location.', 'settings' => $settings];
        }

        $set = $this->http->request('POST', $api . '/inventory_levels/set.json', $headers, [
            'location_id'       => $locationId,
            'inventory_item_id' => $inventoryItemId,
            'available'         => max(0, $quantity),
        ]);
        if ($set['status'] >= 200 && $set['status'] < 300) {
            return [
                'ok'       => true,
                'message'  => 'Shopify quantity set to ' . max(0, $quantity) . ' for ' . ($sku !== '' ? $sku : $externalId) . '.',
                'settings' => $settings,
            ];
        }

        return [
            'ok'       => false,
            'message'  => 'Shopify stock HTTP ' . $set['status'] . ': ' . substr((string) ($set['body'] ?? ''), 0, 200),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,items:list<array{external_id:string,sku:string,title:string,price:float,qty:int,status:string}>,settings:array<string,mixed>}
     */
    public function fetchListings(array $settings): array
    {
        $shop = $this->normalizeShop((string) ($settings['store_url'] ?? ''));
        $token = (string) ($settings['access_token'] ?? $settings['api_key'] ?? '');
        if ($shop === '' || $token === '') {
            return ['ok' => false, 'message' => 'Shopify not linked (missing store or token).', 'items' => [], 'settings' => $settings];
        }
        $headers = [
            'X-Shopify-Access-Token' => $token,
            'Accept'                 => 'application/json',
        ];
        $items = [];
        $res = $this->http->request('GET', 'https://' . $shop . '/admin/api/2024-01/products.json?limit=50', $headers);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'Shopify listings HTTP ' . $res['status'] . ': ' . substr((string) ($res['body'] ?? ''), 0, 200),
                'items'    => [],
                'settings' => $settings,
            ];
        }
        $products = is_array($res['json']['products'] ?? null) ? $res['json']['products'] : [];
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $title = trim((string) ($product['title'] ?? ''));
            foreach (($product['variants'] ?? []) as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $sku = trim((string) ($variant['sku'] ?? ''));
                $variantId = trim((string) ($variant['id'] ?? ''));
                if ($sku === '' && $variantId === '') {
                    continue;
                }
                $items[] = [
                    'external_id' => $variantId !== '' ? $variantId : $sku,
                    'sku'         => substr($sku, 0, 64),
                    'title'       => substr($title !== '' ? $title : $sku, 0, 255),
                    'price'       => (float) ($variant['price'] ?? 0),
                    'qty'         => (int) ($variant['inventory_quantity'] ?? 0),
                    'status'      => 'active',
                ];
            }
        }
        return [
            'ok'       => true,
            'message'  => count($items) . ' Shopify listing(s) read.',
            'items'    => $items,
            'settings' => $settings,
        ];
    }

    private function normalizeShop(string $raw): string
    {
        $raw = trim(strtolower($raw));
        $raw = preg_replace('#^https?://#', '', $raw) ?? $raw;
        $raw = explode('/', $raw)[0] ?? $raw;
        $raw = rtrim($raw, '/');
        if ($raw === '') {
            return '';
        }
        if (!str_contains($raw, '.')) {
            $raw .= '.myshopify.com';
        }
        return $raw;
    }
}
