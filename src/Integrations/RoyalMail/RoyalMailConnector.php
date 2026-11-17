<?php

declare(strict_types=1);

namespace App\Integrations\RoyalMail;

use App\Integrations\BaseConnector;

/**
 * Royal Mail Pro Shipping / Click & Drop style OAuth2 client-credentials auth.
 * Token endpoint: https://authentication.proshipping.net/connect/token
 */
final class RoyalMailConnector extends BaseConnector
{
    public const TOKEN_URL = 'https://authentication.proshipping.net/connect/token';
    public const API_BASE  = 'https://api.proshipping.net';

    public function key(): string
    {
        return 'royal_mail';
    }

    public function label(): string
    {
        return 'Royal Mail';
    }

    public function group(): string
    {
        return 'courier';
    }

    public function isConfigured(): bool
    {
        $c = $this->platform();
        return trim((string) ($c['client_id'] ?? '')) !== ''
            && trim((string) ($c['client_secret'] ?? '')) !== '';
    }

    public function supportsCredentials(): bool
    {
        return true;
    }

    /**
     * Exchange client_id + client_secret for a Bearer access token.
     *
     * @param array<string, string> $settings
     * @return array{ok:bool, message:string, settings?:array<string,string>, token?:array<string,mixed>}
     */
    public function authenticate(array $settings): array
    {
        $clientId = trim((string) ($settings['api_key'] ?? ''));
        $clientSecret = trim((string) ($settings['api_secret'] ?? ''));
        $platform = $this->platform();
        if ($clientId === '') {
            $clientId = trim((string) ($platform['client_id'] ?? ''));
        }
        if ($clientSecret === '') {
            $clientSecret = trim((string) ($platform['client_secret'] ?? ''));
        }
        if ($clientId === '' || $clientSecret === '') {
            return [
                'ok'      => false,
                'message' => 'Royal Mail Client ID and Client Secret are required (channel api_key/api_secret or Marketplace Apps).',
            ];
        }

        $res = $this->http->request(
            'POST',
            self::TOKEN_URL,
            [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Accept'        => 'application/json',
            ],
            ['grant_type' => 'client_credentials']
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return [
                'ok'      => false,
                'message' => 'Royal Mail Auth failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 240),
            ];
        }

        $access = (string) ($res['json']['access_token'] ?? '');
        if ($access === '') {
            return ['ok' => false, 'message' => 'Royal Mail Auth response missing access_token.'];
        }

        $expiresIn = (int) ($res['json']['expires_in'] ?? 3600);
        $settings['api_key'] = $clientId;
        $settings['api_secret'] = $clientSecret;
        $settings['access_token'] = $access;
        $settings['token_type'] = (string) ($res['json']['token_type'] ?? 'Bearer');
        $settings['token_expires_at'] = (string) (time() + $expiresIn);
        $settings['auth_mode'] = 'oauth_client_credentials';
        $settings['provider'] = 'royal_mail';
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['last_connected_at'] = date('c');

        return [
            'ok'       => true,
            'message'  => 'Royal Mail access token issued.',
            'settings' => $settings,
            'token'    => [
                'access_token' => $access,
                'token_type'   => $settings['token_type'],
                'expires_in'   => $expiresIn,
                'expires_at'   => (int) $settings['token_expires_at'],
                'auth_url'     => self::TOKEN_URL,
            ],
        ];
    }

    public function connectCredentials(array $channel, array $settings): array
    {
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            // Still allow storing keys if live auth fails (sandbox / not yet live)
            $fallback = parent::connectCredentials($channel, $settings);
            if (!empty($fallback['ok'])) {
                $fallback['message'] = 'Royal Mail credentials saved. Auth note: ' . (string) ($auth['message'] ?? '');
                return $fallback;
            }
            return $auth;
        }

        $settings = $auth['settings'] ?? $settings;
        if (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? 'royal_mail');
        }

        return [
            'ok'       => true,
            'settings' => $settings,
            'message'  => 'Royal Mail linked — access token obtained from Auth API.',
        ];
    }

    public function testConnection(array $settings): array
    {
        $ensured = $this->ensureAccessToken($settings);
        if (($ensured['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => (string) ($ensured['_auth_error'] ?? 'Auth failed')];
        }
        $settings = $ensured;

        // Light probe against Pro Shipping API (carriers list is a common read endpoint)
        $res = $this->http->request('GET', self::API_BASE . '/v4/reference/carriers', [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Accept'        => 'application/json',
        ]);

        if ($res['status'] >= 200 && $res['status'] < 300) {
            return [
                'ok'       => true,
                'message'  => 'Royal Mail Auth + API OK.',
                'settings' => $settings,
            ];
        }

        // Token valid even if reference path differs by account
        if (($settings['access_token'] ?? '') !== '') {
            return [
                'ok'       => true,
                'soft'     => true,
                'message'  => 'Royal Mail Auth token valid (API probe HTTP ' . $res['status'] . ').',
                'settings' => $settings,
            ];
        }

        return ['ok' => false, 'message' => 'Royal Mail API HTTP ' . $res['status']];
    }

    /**
     * Create a Royal Mail Pro Shipping consignment and return tracking + PDF label.
     *
     * @param array<string, string> $settings Channel / platform credentials
     * @param array<string, mixed> $payload
     *   reference, service_code, weight_kg, package_count, currency, total, description,
     *   address{contact_name,phone,email,line1,line2,city,postcode,country,company},
     *   items[{sku,name,qty,value,weight}]
     * @return array{
     *   ok:bool, message:string, tracking?:string, consignment_id?:string,
     *   label_base64?:string, label_format?:string, tracking_url?:string,
     *   settings?:array<string,string>
     * }
     */
    public function createLabel(array $settings, array $payload): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'message'  => (string) ($settings['_auth_error'] ?? 'Royal Mail auth failed.'),
                'settings' => $settings,
            ];
        }

        $accountId = trim((string) ($settings['shipping_account_id'] ?? ''));
        if ($accountId === '') {
            return [
                'ok'       => false,
                'message'  => 'Royal Mail channel needs Shipping Account ID (Pro Shipping).',
                'settings' => $settings,
            ];
        }

        $serviceCode = strtoupper(trim((string) ($payload['service_code'] ?? '')));
        if ($serviceCode === '') {
            $serviceCode = strtoupper(trim((string) ($settings['default_service_code'] ?? 'CRL1')));
        }
        if ($serviceCode === '') {
            $serviceCode = 'CRL1';
        }

        $addr = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $line1 = trim((string) ($addr['line1'] ?? ''));
        $town = trim((string) ($addr['city'] ?? ''));
        $postcode = trim((string) ($addr['postcode'] ?? ''));
        $country = strtoupper(trim((string) ($addr['country'] ?? 'GB')));
        if ($country === '') {
            $country = 'GB';
        }
        $contact = trim((string) ($addr['contact_name'] ?? ''));
        if ($line1 === '' || $town === '' || $postcode === '' || $contact === '') {
            return [
                'ok'       => false,
                'message'  => 'Royal Mail label needs contact name, line1, town and postcode.',
                'settings' => $settings,
            ];
        }

        $weight = (float) ($payload['weight_kg'] ?? 0);
        if ($weight <= 0) {
            $weight = 0.5;
        }
        $packages = max(1, (int) ($payload['package_count'] ?? 1));
        $reference = substr(trim((string) ($payload['reference'] ?? '')), 0, 40);
        $description = trim((string) ($payload['description'] ?? 'Goods'));
        if ($description === '') {
            $description = 'Goods';
        }
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'GBP')));
        if ($currency === '') {
            $currency = 'GBP';
        }

        $destAddress = [
            'ContactName'  => $contact,
            'Line1'        => $line1,
            'Town'         => $town,
            'Postcode'     => $postcode,
            'CountryCode'  => $country,
        ];
        $line2 = trim((string) ($addr['line2'] ?? ''));
        if ($line2 !== '') {
            $destAddress['Line2'] = $line2;
        }
        $phone = trim((string) ($addr['phone'] ?? ''));
        if ($phone !== '') {
            $destAddress['ContactPhone'] = $phone;
        }
        $email = trim((string) ($addr['email'] ?? ''));
        if ($email !== '') {
            $destAddress['ContactEmail'] = $email;
        }
        $company = trim((string) ($addr['company'] ?? ''));
        if ($company !== '') {
            $destAddress['CompanyName'] = $company;
        }

        $shipper = [
            'ShippingAccountId' => $accountId,
            'Reference1'        => $reference !== '' ? $reference : ('SF' . date('YmdHis')),
        ];
        $locationId = trim((string) ($settings['shipping_location_id'] ?? ''));
        if ($locationId !== '') {
            $shipper['ShippingLocationId'] = $locationId;
        }

        $pkgList = [];
        $perWeight = round($weight / $packages, 3);
        for ($i = 1; $i <= $packages; $i++) {
            $pkgList[] = [
                'PackageType'       => 'Parcel',
                'PackageOccurrence' => $i,
                'DeclaredWeight'    => max(0.01, $perWeight),
            ];
        }

        $body = [
            'ShipmentInformation' => [
                'Action'                   => 'Process',
                'LabelFormat'              => 'PDF',
                'ReturnLabelsAtPackageLevel' => false,
                'ContentType'              => 'NDX',
                'ServiceCode'              => $serviceCode,
                'DescriptionOfGoods'       => substr($description, 0, 70),
                'ShipmentDate'             => date('Y-m-d'),
                'CurrencyCode'             => $currency,
                'WeightUnitOfMeasure'      => 'KG',
                'DimensionsUnitOfMeasure'  => 'CM',
            ],
            'Shipper'     => $shipper,
            'Destination' => ['Address' => $destAddress],
            'Packages'    => $pkgList,
        ];

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $rmItems = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $rmItems[] = [
                'SkuCode'     => substr(trim((string) ($item['sku'] ?? 'SKU')), 0, 30) ?: 'SKU',
                'Quantity'    => max(1, (int) ($item['qty'] ?? 1)),
                'Description' => substr(trim((string) ($item['name'] ?? 'Item')), 0, 70) ?: 'Item',
                'Value'       => round((float) ($item['value'] ?? 0), 2),
                'Weight'      => max(0.01, (float) ($item['weight'] ?? 0.1)),
                'CountryOfOrigin' => 'GB',
            ];
        }
        if ($rmItems !== []) {
            $body['Items'] = $rmItems;
        }

        $res = $this->http->request(
            'POST',
            self::API_BASE . '/v4/shipments/rm',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            $body
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            $detail = $this->formatApiError($res);
            return [
                'ok'       => false,
                'message'  => 'Royal Mail create shipment failed: ' . $detail,
                'settings' => $settings,
            ];
        }

        $json = $res['json'];
        $packagesOut = is_array($json['Packages'] ?? null) ? $json['Packages'] : [];
        $first = is_array($packagesOut[0] ?? null) ? $packagesOut[0] : [];
        $tracking = trim((string) ($first['TrackingNumber'] ?? $json['TrackingNumber'] ?? ''));
        $shipmentId = trim((string) ($first['ShipmentId'] ?? $json['ShipmentId'] ?? ''));
        $trackingUrl = trim((string) ($first['CarrierTrackingUrl'] ?? ''));
        $labelB64 = trim((string) ($json['Labels'] ?? $json['Label'] ?? ''));
        $labelFormat = strtoupper(trim((string) ($json['LabelFormat'] ?? 'PDF')));

        // Some accounts return id without label — fetch printLabel as fallback.
        if ($labelB64 === '' && $shipmentId !== '') {
            $print = $this->http->request(
                'GET',
                self::API_BASE . '/v4/shipments/printLabel/rm/' . rawurlencode($shipmentId) . '?labelFormat=PDF',
                [
                    'Authorization' => 'Bearer ' . $settings['access_token'],
                    'Accept'        => 'application/json',
                ]
            );
            if ($print['status'] >= 200 && $print['status'] < 300 && is_array($print['json'])) {
                $labelB64 = trim((string) ($print['json']['Label'] ?? $print['json']['Labels'] ?? ''));
                if ($tracking === '') {
                    $tracking = trim((string) ($print['json']['TrackingNumber'] ?? ''));
                }
                if ($trackingUrl === '') {
                    $trackingUrl = trim((string) ($print['json']['CarrierTrackingUrl'] ?? ''));
                }
                $labelFormat = 'PDF';
            }
        }

        if ($tracking === '') {
            return [
                'ok'       => false,
                'message'  => 'Royal Mail response missing tracking number.',
                'settings' => $settings,
            ];
        }

        return [
            'ok'             => true,
            'message'        => 'Royal Mail label created.',
            'tracking'       => $tracking,
            'consignment_id' => $shipmentId,
            'label_base64'   => $labelB64,
            'label_format'   => $labelFormat !== '' ? $labelFormat : 'PDF',
            'tracking_url'   => $trackingUrl,
            'settings'       => $settings,
        ];
    }

    /**
     * Cancel a Pro Shipping shipment. Does not change local order or stock.
     *
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function voidShipment(array $settings, string $shipmentId): array
    {
        $settings = $this->ensureAccessToken($settings);
        $shipmentId = trim($shipmentId);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'message'  => (string) ($settings['_auth_error'] ?? 'Royal Mail auth failed.'),
                'settings' => $settings,
            ];
        }
        if ($shipmentId === '') {
            return ['ok' => false, 'message' => 'Royal Mail void needs the shipment id.', 'settings' => $settings];
        }

        $res = $this->http->request(
            'PUT',
            self::API_BASE . '/v4/shipments/status',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            [
                'Status'      => 'Cancel',
                'Reason'      => 'Order Cancelled',
                'ShipmentIds' => [$shipmentId],
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'Royal Mail void failed: ' . $this->formatApiError($res),
                'settings' => $settings,
            ];
        }
        if (is_array($res['json'])) {
            $errors = $res['json']['Errors'] ?? $res['json']['errors'] ?? null;
            if (is_array($errors) && $errors !== []) {
                return [
                    'ok'       => false,
                    'message'  => 'Royal Mail void failed: ' . $this->formatApiError($res),
                    'settings' => $settings,
                ];
            }
        }

        return [
            'ok'       => true,
            'message'  => 'Royal Mail shipment ' . $shipmentId . ' cancelled.',
            'settings' => $settings,
        ];
    }

    /**
     * Close the day's Royal Mail shipments for this shipping account.
     *
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,manifest_number:string,settings:array<string,mixed>}
     */
    public function createManifest(array $settings): array
    {
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok'              => false,
                'message'         => (string) ($settings['_auth_error'] ?? 'Royal Mail auth failed.'),
                'manifest_number' => '',
                'settings'        => $settings,
            ];
        }

        $accountId = trim((string) ($settings['shipping_account_id'] ?? ''));
        if ($accountId === '') {
            return [
                'ok'              => false,
                'message'         => 'Royal Mail channel needs Shipping Account ID (Pro Shipping).',
                'manifest_number' => '',
                'settings'        => $settings,
            ];
        }

        $body = [
            'ShippingAccountId' => $accountId,
            'Async'             => false,
        ];
        $locationId = trim((string) ($settings['shipping_location_id'] ?? ''));
        if ($locationId !== '') {
            $body['ShippingLocationId'] = $locationId;
        }

        $res = $this->http->request(
            'POST',
            self::API_BASE . '/v4/manifests',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ],
            $body
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            return [
                'ok'              => false,
                'message'         => 'Royal Mail manifest failed: ' . $this->formatApiError($res),
                'manifest_number' => '',
                'settings'        => $settings,
            ];
        }

        $json = $res['json'];
        $number = trim((string) ($json['ManifestNumber'] ?? ''));
        if ($number === '' && is_array($json['Manifests'][0] ?? null)) {
            $number = trim((string) ($json['Manifests'][0]['ManifestNumber'] ?? ''));
        }
        if ($number === '') {
            $number = trim((string) ($json['ManifestId'] ?? ''));
        }

        return [
            'ok'              => true,
            'message'         => $number !== ''
                ? 'Royal Mail manifest ' . $number . ' created.'
                : 'Royal Mail manifest created.',
            'manifest_number' => $number,
            'settings'        => $settings,
        ];
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    private function ensureAccessToken(array $settings): array
    {
        unset($settings['_auth_error']);
        $expires = (int) ($settings['token_expires_at'] ?? 0);
        if (($settings['access_token'] ?? '') !== '' && $expires >= time() + 30) {
            return $settings;
        }
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            $settings['_auth_error'] = (string) ($auth['message'] ?? 'Auth failed');
            return $settings;
        }
        /** @var array<string, string> $merged */
        $merged = $auth['settings'] ?? $settings;
        return $merged;
    }

    /**
     * @param array{status:int, body:string, json:array<string,mixed>|null, error:?string} $res
     */
    private function formatApiError(array $res): string
    {
        if (is_array($res['json'])) {
            $errors = $res['json']['Errors'] ?? $res['json']['errors'] ?? null;
            if (is_array($errors) && $errors !== []) {
                $parts = [];
                foreach (array_slice($errors, 0, 3) as $err) {
                    if (!is_array($err)) {
                        continue;
                    }
                    $msg = (string) ($err['Message'] ?? $err['message'] ?? $err['Cause'] ?? '');
                    $cause = (string) ($err['Cause'] ?? '');
                    $parts[] = trim($cause !== '' && $msg !== '' ? $cause . ': ' . $msg : ($msg ?: $cause));
                }
                $joined = implode('; ', array_filter($parts));
                if ($joined !== '') {
                    return 'HTTP ' . $res['status'] . ' — ' . $joined;
                }
            }
            $msg = (string) ($res['json']['Message'] ?? $res['json']['message'] ?? '');
            if ($msg !== '') {
                return 'HTTP ' . $res['status'] . ' — ' . substr($msg, 0, 240);
            }
        }
        $body = substr((string) ($res['body'] ?? ''), 0, 240);
        $err = (string) ($res['error'] ?? '');
        return 'HTTP ' . $res['status'] . ($body !== '' ? ': ' . $body : ($err !== '' ? ': ' . $err : ''));
    }
}
