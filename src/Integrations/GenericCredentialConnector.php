<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Core\App;

/**
 * API-key / secret based connector used by most couriers and several marketplaces.
 */
final class GenericCredentialConnector extends BaseConnector
{
    /**
     * @param array{test_url?:string, test_method?:string, auth_header?:string, orders?:bool} $options
     */
    public function __construct(
        App $app,
        private string $providerKey,
        private string $providerLabel,
        private string $providerGroup,
        private array $options = [],
    ) {
        parent::__construct($app);
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return $this->providerLabel;
    }

    public function group(): string
    {
        return $this->providerGroup;
    }

    public function testConnection(array $settings): array
    {
        $key = trim((string) ($settings['api_key'] ?? ''));
        $secret = trim((string) ($settings['api_secret'] ?? ''));
        if ($key === '' && $secret === '') {
            return ['ok' => false, 'message' => $this->label() . ' has no API credentials.'];
        }

        $testUrl = (string) ($this->options['test_url'] ?? '');
        if ($testUrl === '') {
            return [
                'ok'      => true,
                'soft'    => true,
                'message' => $this->label() . ' credentials saved. Live API probe not configured for this provider.',
            ];
        }

        $headers = ['Accept' => 'application/json'];
        $authMode = (string) ($this->options['auth_header'] ?? 'basic');
        if ($authMode === 'basic') {
            $headers['Authorization'] = 'Basic ' . base64_encode($key . ':' . $secret);
        } elseif ($authMode === 'bearer') {
            $headers['Authorization'] = 'Bearer ' . ($secret !== '' ? $secret : $key);
        } elseif ($authMode === 'key_secret_headers') {
            $headers['X-API-Key'] = $key;
            $headers['X-API-Secret'] = $secret;
        } elseif ($authMode === 'woo') {
            // WooCommerce query auth fallback handled in URL below
        } elseif ($authMode === 'royal_mail') {
            $headers['Authorization'] = 'Bearer ' . ($secret !== '' ? $secret : $key);
            $headers['X-IBM-Client-Id'] = $key;
        } elseif ($authMode === 'onbuy') {
            $headers['Authorization'] = $secret;
            $headers['Content-Type'] = 'application/json';
        }

        $method = strtoupper((string) ($this->options['test_method'] ?? 'GET'));
        $url = $testUrl;
        if ($authMode === 'woo') {
            $store = rtrim((string) ($settings['store_url'] ?? ''), '/');
            if ($store === '') {
                return ['ok' => false, 'message' => 'WooCommerce store URL required.'];
            }
            $url = $store . '/wp-json/wc/v3/system_status?' . http_build_query([
                'consumer_key'    => $key,
                'consumer_secret' => $secret,
            ]);
        }

        $res = $this->http->request($method, $url, $headers);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => $this->label() . ' API reachable (HTTP ' . $res['status'] . ').'];
        }
        if ($res['status'] === 401 || $res['status'] === 403) {
            return ['ok' => false, 'message' => $this->label() . ' rejected credentials (HTTP ' . $res['status'] . ').'];
        }

        // Many courier sandboxes are IP-allowlisted — treat non-auth errors as soft OK when keys exist
        return [
            'ok'      => true,
            'soft'    => true,
            'message' => $this->label() . ' credentials stored (probe HTTP ' . $res['status'] . ').',
        ];
    }

    public function fetchOrders(array $settings, \DateTimeInterface $since): array
    {
        if (empty($this->options['orders'])) {
            return [];
        }

        // Provider-specific light importers
        return match ($this->providerKey) {
            'woocommerce' => $this->fetchWooOrders($settings, $since),
            'onbuy'       => $this->fetchOnBuyOrders($settings, $since),
            default       => [],
        };
    }

    /** @param array<string, string> $settings @return list<array<string, mixed>> */
    private function fetchWooOrders(array $settings, \DateTimeInterface $since): array
    {
        $store = rtrim((string) ($settings['store_url'] ?? ''), '/');
        $key = (string) ($settings['api_key'] ?? '');
        $secret = (string) ($settings['api_secret'] ?? '');
        if ($store === '' || $key === '') {
            return [];
        }
        $url = $store . '/wp-json/wc/v3/orders?' . http_build_query([
            'consumer_key'    => $key,
            'consumer_secret' => $secret,
            'after'           => $since->format('c'),
            'per_page'        => 50,
        ]);
        $res = $this->http->request('GET', $url, ['Accept' => 'application/json']);
        $rows = $res['json'] ?? null;
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $o) {
            if (!is_array($o)) {
                continue;
            }
            $ship = $o['shipping'] ?? [];
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
            $out[] = [
                'external_order_id' => (string) ($o['number'] ?? $o['id'] ?? ''),
                'status'            => ($o['status'] ?? '') === 'completed' ? 'despatched' : 'awaiting_despatch',
                'buyer_email'       => (string) ($o['billing']['email'] ?? 'woo@buyer.local'),
                'shipping_name'     => trim((string) (($ship['first_name'] ?? '') . ' ' . ($ship['last_name'] ?? ''))) ?: 'Woo Buyer',
                'total'             => (float) ($o['total'] ?? 0),
                'currency'          => (string) ($o['currency'] ?? 'GBP'),
                'shipping_method'   => 'WooCommerce',
                'shipping_address'  => [
                    'line1'    => (string) ($ship['address_1'] ?? ''),
                    'city'     => (string) ($ship['city'] ?? ''),
                    'postcode' => (string) ($ship['postcode'] ?? ''),
                    'country'  => (string) ($ship['country'] ?? 'GB'),
                ],
                'items'             => $items,
                'raw_provider'      => 'woocommerce',
            ];
        }
        return $out;
    }

    /** @param array<string, string> $settings @return list<array<string, mixed>> */
    private function fetchOnBuyOrders(array $settings, \DateTimeInterface $since): array
    {
        // OnBuy requires a site-specific access token exchange; return empty until token flow configured.
        unset($settings, $since);
        return [];
    }
}
