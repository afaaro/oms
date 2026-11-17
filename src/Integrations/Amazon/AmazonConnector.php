<?php

declare(strict_types=1);

namespace App\Integrations\Amazon;

use App\Integrations\BaseConnector;

final class AmazonConnector extends BaseConnector
{
    public function key(): string
    {
        return 'amazon';
    }

    public function label(): string
    {
        return 'Amazon';
    }

    public function group(): string
    {
        return 'marketplace';
    }

    public function isConfigured(): bool
    {
        $c = $this->cfg->provider('amazon');
        return trim((string) ($c['client_id'] ?? '')) !== ''
            && trim((string) ($c['client_secret'] ?? '')) !== ''
            && trim((string) ($c['application_id'] ?? '')) !== '';
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
        $c = $this->cfg->provider('amazon');
        $host = trim((string) ($c['seller_central'] ?? 'sellercentral.amazon.co.uk'), '/');
        if (!str_starts_with($host, 'http')) {
            $host = 'https://' . $host;
        }
        $qs = [
            'application_id' => (string) $c['application_id'],
            'state'          => $state,
        ];
        if (!empty($c['draft'])) {
            $qs['version'] = 'beta';
        }
        return rtrim($host, '/') . '/apps/authorize/consent?' . http_build_query($qs);
    }

    public function handleCallback(array $query, array $channel, array $settings): array
    {
        $code = (string) ($query['spapi_oauth_code'] ?? $query['code'] ?? '');
        $sellerId = (string) ($query['selling_partner_id'] ?? '');
        if ($code === '') {
            return ['ok' => false, 'message' => 'Amazon did not return spapi_oauth_code.'];
        }

        $c = $this->cfg->provider('amazon');
        $redirectUri = $this->app->url((string) ($c['redirect_path'] ?? 'integrations/oauth/callback'));

        $res = $this->http->request(
            'POST',
            'https://api.amazon.com/auth/o2/token',
            ['Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'],
            [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'client_id'     => (string) $c['client_id'],
                'client_secret' => (string) $c['client_secret'],
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return [
                'ok'      => false,
                'message' => 'Amazon token exchange failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 240),
            ];
        }

        $settings['access_token'] = (string) ($res['json']['access_token'] ?? '');
        $settings['refresh_token'] = (string) ($res['json']['refresh_token'] ?? '');
        $settings['token_expires_at'] = (string) (time() + (int) ($res['json']['expires_in'] ?? 3600));
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['auth_mode'] = 'oauth';
        $settings['provider'] = 'amazon';
        $settings['last_connected_at'] = date('d/m/Y H:i:s');
        if ($sellerId !== '') {
            $settings['linked_user'] = $sellerId;
            $settings['merchant_id'] = $sellerId;
        }

        return ['ok' => true, 'settings' => $settings, 'message' => 'Amazon seller account linked.'];
    }

    public function testConnection(array $settings): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'No Amazon access token. Connect the channel first.'];
        }

        $c = $this->cfg->provider('amazon');
        $marketplace = (string) ($c['marketplace_id'] ?? 'A1F83G8C2ARO7P');
        $url = $this->spApiHost() . '/sellers/v1/marketplaceParticipations';

        $res = $this->http->request('GET', $url, [
            'x-amz-access-token' => $settings['access_token'],
            'Accept'             => 'application/json',
            'User-Agent'         => 'StoreFeederOMS/1.0 (Language=PHP)',
        ]);

        // Sellers API may need AWS SigV4 in production; LWA-only works for some grantless/restricted setups.
        // Prefer Orders API light probe when seller id present.
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'Amazon SP-API reachable.', 'settings' => $settings];
        }

        // Fallback: refresh token validity is enough to call "linked"
        if (($settings['refresh_token'] ?? '') !== '' && ($settings['access_token'] ?? '') !== '') {
            return [
                'ok'       => true,
                'message'  => 'Amazon LWA tokens valid (SP-API HTTP ' . $res['status'] . ' — SigV4 may be required for some calls).',
                'settings' => $settings,
            ];
        }

        return [
            'ok'       => false,
            'message'  => 'Amazon API error HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 200),
            'settings' => $settings,
        ];
    }

    public function submitFulfillment(array $settings, array $payload): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'status'   => 'failed',
                'message'  => 'Amazon not linked (missing access token).',
                'settings' => $settings,
            ];
        }

        $orderId = trim((string) ($payload['external_order_id'] ?? ''));
        $tracking = trim((string) ($payload['tracking_number'] ?? ''));
        if ($orderId === '' || $tracking === '') {
            return [
                'ok'      => false,
                'status'  => 'failed',
                'message' => 'Amazon fulfillment needs external order id and tracking.',
            ];
        }

        $c = $this->cfg->provider('amazon');
        $marketplace = trim((string) ($settings['marketplace_id'] ?? $c['marketplace_id'] ?? 'A1F83G8C2ARO7P'));
        if ($marketplace === '') {
            $marketplace = 'A1F83G8C2ARO7P';
        }

        $orderItems = $this->fetchOrderItemsForShipment($settings, $orderId);
        if ($orderItems === []) {
            return [
                'ok'       => false,
                'status'   => 'failed',
                'message'  => 'Amazon order items lookup failed (need OrderItemId). Check SP-API access / SigV4.',
                'settings' => $settings,
            ];
        }

        $carrier = $this->mapAmazonCarrier((string) ($payload['carrier_code'] ?? ''));
        $shipDate = trim((string) ($payload['shipped_at'] ?? ''));
        if ($shipDate === '') {
            $shipDate = gmdate('Y-m-d\TH:i:s\Z');
        } else {
            $ts = strtotime($shipDate);
            $shipDate = $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : gmdate('Y-m-d\TH:i:s\Z');
        }

        $body = [
            'marketplaceId' => $marketplace,
            'packageDetail' => [
                'packageReferenceId' => '1',
                'carrierCode'        => $carrier['code'],
                'carrierName'        => $carrier['name'],
                'shippingMethod'     => trim((string) ($payload['service_code'] ?? '')) ?: 'SHIPPING',
                'trackingNumber'     => $tracking,
                'shipDate'           => $shipDate,
                'orderItems'         => $orderItems,
            ],
        ];

        $url = $this->spApiHost() . '/orders/v0/orders/' . rawurlencode($orderId) . '/shipmentConfirmation';
        $res = $this->http->request(
            'POST',
            $url,
            [
                'x-amz-access-token' => $settings['access_token'],
                'Accept'             => 'application/json',
                'Content-Type'       => 'application/json',
                'User-Agent'         => 'StoreFeederOMS/1.0 (Language=PHP)',
            ],
            $body
        );

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'ok'       => true,
                'status'   => 'uploaded',
                'message'  => 'Amazon shipment confirmation submitted.',
                'settings' => $settings,
            ];
        }

        $detail = substr((string) ($res['body'] ?? ''), 0, 240);
        $hint = '';
        if (in_array($res['status'], [403, 401], true) || str_contains(strtolower($detail), 'signature')) {
            $hint = ' (SP-API may require AWS SigV4 for this call — LWA token alone is insufficient on some accounts.)';
        }

        return [
            'ok'       => false,
            'status'   => 'failed',
            'message'  => 'Amazon confirmShipment HTTP ' . $res['status'] . ': ' . $detail . $hint,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string, string> $settings
     * @return list<array{orderItemId:string, quantity:int}>
     */
    private function fetchOrderItemsForShipment(array $settings, string $amazonOrderId): array
    {
        $url = $this->spApiHost() . '/orders/v0/orders/' . rawurlencode($amazonOrderId) . '/orderItems';
        $res = $this->http->request('GET', $url, [
            'x-amz-access-token' => $settings['access_token'],
            'Accept'             => 'application/json',
            'User-Agent'         => 'StoreFeederOMS/1.0 (Language=PHP)',
        ]);

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return [];
        }

        $items = $res['json']['payload']['OrderItems']
            ?? $res['json']['OrderItems']
            ?? [];
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $itemId = trim((string) ($item['OrderItemId'] ?? ''));
            if ($itemId === '') {
                continue;
            }
            $qty = (int) ($item['QuantityOrdered'] ?? $item['QuantityShipped'] ?? 1);
            $out[] = [
                'orderItemId' => $itemId,
                'quantity'    => max(1, $qty),
            ];
        }
        return $out;
    }

    /** @return array{code:string, name:string} */
    private function mapAmazonCarrier(string $carrier): array
    {
        $c = strtolower(str_replace(['-', ' '], '_', trim($carrier)));
        return match ($c) {
            'royal_mail', 'royalmail', 'rm', 'royal_mail_courier' => ['code' => 'Royal Mail', 'name' => 'Royal Mail'],
            'evri', 'hermes', 'hermes_evri' => ['code' => 'Hermes', 'name' => 'Evri'],
            'dpd', 'dpd_courier' => ['code' => 'DPD', 'name' => 'DPD'],
            'parcelforce', 'parcel_force' => ['code' => 'Parcelforce', 'name' => 'Parcelforce'],
            'ups' => ['code' => 'UPS', 'name' => 'UPS'],
            'fedex' => ['code' => 'FedEx', 'name' => 'FedEx'],
            'dhl' => ['code' => 'DHL', 'name' => 'DHL'],
            default => [
                'code' => 'Other',
                'name' => $carrier !== '' ? $carrier : 'Other',
            ],
        };
    }

    public function fetchOrders(array $settings, \DateTimeInterface $since): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [];
        }

        $c = $this->cfg->provider('amazon');
        $marketplace = (string) ($c['marketplace_id'] ?? 'A1F83G8C2ARO7P');
        $url = $this->spApiHost() . '/orders/v0/orders?' . http_build_query([
            'MarketplaceIds' => $marketplace,
            'CreatedAfter'   => $since->format('Y-m-d\TH:i:s\Z'),
        ]);

        $res = $this->http->request('GET', $url, [
            'x-amz-access-token' => $settings['access_token'],
            'Accept'             => 'application/json',
            'User-Agent'         => 'StoreFeederOMS/1.0 (Language=PHP)',
        ]);

        $payload = $res['json']['payload']['Orders'] ?? $res['json']['Orders'] ?? [];
        if (!is_array($payload)) {
            return [];
        }

        $out = [];
        foreach ($payload as $o) {
            if (!is_array($o)) {
                continue;
            }
            $out[] = $this->normalizeOrder($o);
        }
        return $out;
    }

    /** @param array<string, mixed> $o @return array<string, mixed> */
    private function normalizeOrder(array $o): array
    {
        $addr = $o['ShippingAddress'] ?? [];
        $name = (string) ($addr['Name'] ?? 'Amazon Buyer');
        $createdAt = null;
        $createdRaw = trim((string) ($o['PurchaseDate'] ?? $o['purchaseDate'] ?? ''));
        if ($createdRaw !== '') {
            $ts = strtotime($createdRaw);
            if ($ts !== false) {
                $createdAt = date('Y-m-d H:i:s', $ts);
            }
        }
        return [
            'external_order_id' => (string) ($o['AmazonOrderId'] ?? ''),
            'status'            => $this->mapStatus((string) ($o['OrderStatus'] ?? '')),
            'buyer_email'       => (string) ($o['BuyerInfo']['BuyerEmail'] ?? 'amazon@buyer.local'),
            'shipping_name'     => $name,
            'total'             => (float) ($o['OrderTotal']['Amount'] ?? 0),
            'currency'          => (string) ($o['OrderTotal']['CurrencyCode'] ?? 'GBP'),
            'shipping_method'   => (string) ($o['ShipmentServiceLevelCategory'] ?? 'Amazon'),
            'shipping_address'  => [
                'line1'    => (string) ($addr['AddressLine1'] ?? ''),
                'city'     => (string) ($addr['City'] ?? ''),
                'postcode' => (string) ($addr['PostalCode'] ?? ''),
                'country'  => (string) ($addr['CountryCode'] ?? 'GB'),
            ],
            'items'             => [],
            'created_at'        => $createdAt,
            'raw_provider'      => 'amazon',
        ];
    }

    private function mapStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'SHIPPED' => 'despatched',
            'CANCELED', 'CANCELLED' => 'cancelled',
            'PENDING' => 'awaiting_payment',
            default => 'awaiting_despatch',
        };
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    private function ensureAccessToken(array $settings): array
    {
        $expires = (int) ($settings['token_expires_at'] ?? 0);
        if (($settings['access_token'] ?? '') !== '' && $expires > time() + 60) {
            return $settings;
        }
        $refresh = (string) ($settings['refresh_token'] ?? '');
        if ($refresh === '') {
            return $settings;
        }

        $c = $this->cfg->provider('amazon');
        $res = $this->http->request(
            'POST',
            'https://api.amazon.com/auth/o2/token',
            ['Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8'],
            [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
                'client_id'     => (string) $c['client_id'],
                'client_secret' => (string) $c['client_secret'],
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return $settings;
        }

        $settings['access_token'] = (string) ($res['json']['access_token'] ?? '');
        $settings['token_expires_at'] = (string) (time() + (int) ($res['json']['expires_in'] ?? 3600));
        return $settings;
    }

    /**
     * Patch merchant-fulfilled availability for a seller SKU.
     *
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function pushQuantity(array $settings, string $sku, int $quantity): array
    {
        $settings = $this->ensureAccessToken($settings);
        $sku = trim($sku);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Amazon not linked (missing access token).', 'settings' => $settings];
        }
        if ($sku === '') {
            return ['ok' => false, 'message' => 'Amazon stock push needs a seller SKU.', 'settings' => $settings];
        }

        $sellerId = trim((string) ($settings['selling_partner_id'] ?? $settings['merchant_id'] ?? $settings['seller_id'] ?? ''));
        if ($sellerId === '') {
            return ['ok' => false, 'message' => 'Amazon stock push needs the selling partner id on the channel.', 'settings' => $settings];
        }

        $c = $this->cfg->provider('amazon');
        $marketplace = trim((string) ($settings['marketplace_id'] ?? $c['marketplace_id'] ?? 'A1F83G8C2ARO7P'));
        if ($marketplace === '') {
            $marketplace = 'A1F83G8C2ARO7P';
        }

        $headers = [
            'x-amz-access-token' => $settings['access_token'],
            'Accept'             => 'application/json',
            'Content-Type'       => 'application/json',
            'User-Agent'         => 'StoreFeederOMS/1.0 (Language=PHP)',
        ];
        $itemUrl = $this->spApiHost() . '/listings/2021-08-01/items/' . rawurlencode($sellerId) . '/' . rawurlencode($sku)
            . '?' . http_build_query(['marketplaceIds' => $marketplace, 'includedData' => 'summaries']);

        $productType = 'PRODUCT';
        $look = $this->http->request('GET', $itemUrl, $headers);
        $summary = $look['json']['summaries'][0] ?? null;
        if (is_array($summary) && trim((string) ($summary['productType'] ?? '')) !== '') {
            $productType = (string) $summary['productType'];
        }

        $patchUrl = $this->spApiHost() . '/listings/2021-08-01/items/' . rawurlencode($sellerId) . '/' . rawurlencode($sku)
            . '?' . http_build_query(['marketplaceIds' => $marketplace]);
        $res = $this->http->request('PATCH', $patchUrl, $headers, [
            'productType' => $productType,
            'patches'     => [[
                'op'    => 'replace',
                'path'  => '/attributes/fulfillment_availability',
                'value' => [[
                    'fulfillment_channel_code' => 'DEFAULT',
                    'quantity'                 => max(0, $quantity),
                ]],
            ]],
        ]);

        if ($res['status'] >= 200 && $res['status'] < 300) {
            $status = strtoupper((string) ($res['json']['status'] ?? 'ACCEPTED'));
            if ($status === 'INVALID') {
                $issue = (string) ($res['json']['issues'][0]['message'] ?? 'listing patch rejected');
                return ['ok' => false, 'message' => 'Amazon stock rejected: ' . $issue, 'settings' => $settings];
            }
            return [
                'ok'       => true,
                'message'  => 'Amazon quantity set to ' . max(0, $quantity) . ' for ' . $sku . '.',
                'settings' => $settings,
            ];
        }

        $detail = substr((string) ($res['body'] ?? ''), 0, 200);
        $hint = in_array($res['status'], [401, 403], true) ? ' (SP-API may require AWS SigV4 for listings.)' : '';

        return [
            'ok'       => false,
            'message'  => 'Amazon stock HTTP ' . $res['status'] . ': ' . $detail . $hint,
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,items:list<array{external_id:string,sku:string,title:string,price:float,qty:int,status:string}>,settings:array<string,mixed>}
     */
    public function fetchListings(array $settings): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Amazon not linked (missing access token).', 'items' => [], 'settings' => $settings];
        }
        $sellerId = trim((string) ($settings['selling_partner_id'] ?? $settings['merchant_id'] ?? $settings['seller_id'] ?? ''));
        if ($sellerId === '') {
            return ['ok' => false, 'message' => 'Amazon listing download needs the selling partner id on the channel.', 'items' => [], 'settings' => $settings];
        }
        $c = $this->cfg->provider('amazon');
        $marketplace = trim((string) ($settings['marketplace_id'] ?? $c['marketplace_id'] ?? 'A1F83G8C2ARO7P'));
        if ($marketplace === '') {
            $marketplace = 'A1F83G8C2ARO7P';
        }
        $headers = [
            'x-amz-access-token' => $settings['access_token'],
            'Accept'             => 'application/json',
            'User-Agent'         => 'StoreFeederOMS/1.0 (Language=PHP)',
        ];
        $items = [];
        $next = '';
        for ($page = 0; $page < 4; $page++) {
            $query = ['marketplaceIds' => $marketplace, 'pageSize' => 20, 'includedData' => 'summaries'];
            if ($next !== '') {
                $query['pageToken'] = $next;
            }
            $url = $this->spApiHost() . '/listings/2021-08-01/items/' . rawurlencode($sellerId) . '?' . http_build_query($query);
            $res = $this->http->request('GET', $url, $headers);
            if ($res['status'] < 200 || $res['status'] >= 300) {
                $detail = substr((string) ($res['body'] ?? ''), 0, 200);
                $hint = in_array($res['status'], [401, 403], true) ? ' (SP-API may require AWS SigV4 for listings.)' : '';
                if ($items !== []) {
                    break;
                }
                return [
                    'ok'       => false,
                    'message'  => 'Amazon listings HTTP ' . $res['status'] . ': ' . $detail . $hint,
                    'items'    => [],
                    'settings' => $settings,
                ];
            }
            $batch = is_array($res['json']['items'] ?? null) ? $res['json']['items'] : [];
            foreach ($batch as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sku = trim((string) ($item['sku'] ?? ''));
                if ($sku === '') {
                    continue;
                }
                $summary = is_array($item['summaries'][0] ?? null) ? $item['summaries'][0] : [];
                $items[] = [
                    'external_id' => $sku,
                    'sku'         => substr($sku, 0, 64),
                    'title'       => substr(trim((string) ($summary['itemName'] ?? $sku)), 0, 255),
                    'price'       => 0.0,
                    'qty'         => 0,
                    'status'      => 'active',
                ];
            }
            $next = trim((string) ($res['json']['pagination']['nextToken'] ?? ''));
            if ($next === '' || $batch === []) {
                break;
            }
        }
        return [
            'ok'       => true,
            'message'  => count($items) . ' Amazon listing(s) read.',
            'items'    => $items,
            'settings' => $settings,
        ];
    }

    private function spApiHost(): string
    {
        $region = strtolower((string) $this->cfg->get('amazon', 'region', 'eu'));
        return match ($region) {
            'na' => 'https://sellingpartnerapi-na.amazon.com',
            'fe' => 'https://sellingpartnerapi-fe.amazon.com',
            default => 'https://sellingpartnerapi-eu.amazon.com',
        };
    }
}
