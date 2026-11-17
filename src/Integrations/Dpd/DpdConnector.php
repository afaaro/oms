<?php

declare(strict_types=1);

namespace App\Integrations\Dpd;

use App\Integrations\BaseConnector;

/**
 * DPD UK GeoSession login auth.
 * POST https://api.dpd.co.uk/user/?action=login (Basic username:password)
 */
final class DpdConnector extends BaseConnector
{
    public const LOGIN_URL = 'https://api.dpd.co.uk/user/?action=login';
    public const API_BASE  = 'https://api.dpd.co.uk';

    public function key(): string
    {
        return 'dpd';
    }

    public function label(): string
    {
        return 'DPD';
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
     * @param array<string, string> $settings
     * @return array{ok:bool, message:string, settings?:array<string,string>, token?:array<string,mixed>}
     */
    public function authenticate(array $settings): array
    {
        [$user, $pass, $account] = $this->creds($settings);
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'message' => 'DPD username and password are required (api_key / api_secret).'];
        }

        $headers = [
            'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ];
        if ($account !== '') {
            $headers['GEOClient'] = 'account/' . $account;
        }

        $res = $this->http->request('POST', self::LOGIN_URL, $headers, '');
        $session = (string) ($res['json']['data']['geoSession'] ?? $res['json']['geoSession'] ?? '');
        if ($res['status'] < 200 || $res['status'] >= 300 || $session === '') {
            return [
                'ok'      => false,
                'message' => 'DPD Auth failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 240),
            ];
        }

        $settings['api_key'] = $user;
        $settings['api_secret'] = $pass;
        if ($account !== '') {
            $settings['account_number'] = $account;
        }
        $settings['access_token'] = $session; // geoSession
        $settings['token_type'] = 'GeoSession';
        $settings['token_expires_at'] = (string) (time() + 3600);
        $settings['auth_mode'] = 'geo_session';
        $settings['provider'] = 'dpd';
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['last_connected_at'] = date('c');

        return [
            'ok'       => true,
            'message'  => 'DPD geoSession issued.',
            'settings' => $settings,
            'token'    => [
                'geo_session' => $session,
                'token_type'  => 'GeoSession',
                'expires_in'  => 3600,
                'auth_url'    => self::LOGIN_URL,
                'account'     => $account !== '' ? $account : null,
            ],
        ];
    }

    public function connectCredentials(array $channel, array $settings): array
    {
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            $fallback = parent::connectCredentials($channel, $settings);
            if (!empty($fallback['ok'])) {
                $fallback['message'] = 'DPD credentials saved. Auth note: ' . (string) ($auth['message'] ?? '');
                return $fallback;
            }
            return $auth;
        }
        $settings = $auth['settings'] ?? $settings;
        if (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? 'dpd');
        }
        return ['ok' => true, 'settings' => $settings, 'message' => 'DPD linked — geoSession obtained from Auth API.'];
    }

    public function testConnection(array $settings): array
    {
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            return ['ok' => false, 'message' => (string) ($auth['message'] ?? 'Auth failed')];
        }
        return [
            'ok'       => true,
            'message'  => 'DPD Auth OK (geoSession).',
            'settings' => $auth['settings'] ?? $settings,
        ];
    }

    /**
     * DPD UK: POST /shipping/shipment then GET /shipping/shipment/{id}/label/.
     *
     * @param array<string, string> $settings
     * @param array<string, mixed> $payload
     * @return array{
     *   ok:bool, message:string, tracking?:string, consignment_id?:string,
     *   label_base64?:string, label_format?:string, tracking_url?:string,
     *   settings?:array<string,string>
     * }
     */
    public function createLabel(array $settings, array $payload): array
    {
        $settings = $this->ensureSession($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'message'  => (string) ($settings['_auth_error'] ?? 'DPD auth failed.'),
                'settings' => $this->publicSettings($settings),
            ];
        }

        $account = trim((string) ($settings['account_number'] ?? ''));
        if ($account === '') {
            return [
                'ok'       => false,
                'message'  => 'DPD channel needs an account number (GEOClient).',
                'settings' => $this->publicSettings($settings),
            ];
        }

        $network = trim((string) ($payload['service_code'] ?? ''));
        if ($network === '' || preg_match('/^\d+\^\d+$/', $network) !== 1) {
            return [
                'ok'       => false,
                'message'  => 'Set a DPD network code (for example 1^12) as the default service code.',
                'settings' => $this->publicSettings($settings),
            ];
        }

        $collectPostcode = trim((string) ($settings['collection_postcode'] ?? ''));
        $collectLine = trim((string) ($settings['collection_line1'] ?? ''));
        $collectTown = trim((string) ($settings['collection_city'] ?? ''));
        if ($collectPostcode === '' || $collectLine === '' || $collectTown === '') {
            return [
                'ok'       => false,
                'message'  => 'DPD channel needs a collection address (line 1, city, postcode).',
                'settings' => $this->publicSettings($settings),
            ];
        }

        $addr = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $line1 = trim((string) ($addr['line1'] ?? ''));
        $town = trim((string) ($addr['city'] ?? ''));
        $postcode = trim((string) ($addr['postcode'] ?? ''));
        $contact = trim((string) ($addr['contact_name'] ?? ''));
        $country = strtoupper(trim((string) ($addr['country'] ?? 'GB')));
        if ($country === '') {
            $country = 'GB';
        }
        if ($line1 === '' || $town === '' || $postcode === '' || $contact === '') {
            return [
                'ok'       => false,
                'message'  => 'DPD label needs contact name, line1, town and postcode.',
                'settings' => $this->publicSettings($settings),
            ];
        }

        $weight = (float) ($payload['weight_kg'] ?? 0);
        if ($weight <= 0) {
            $weight = 1.0;
        }
        $packages = max(1, (int) ($payload['package_count'] ?? 1));
        $reference = substr(trim((string) ($payload['reference'] ?? '')), 0, 25);
        if ($reference === '') {
            $reference = 'SF' . date('ymdHis');
        }
        $phone = trim((string) ($addr['phone'] ?? ''));
        $email = trim((string) ($addr['email'] ?? ''));
        $company = trim((string) ($addr['company'] ?? ''));
        $description = trim((string) ($payload['description'] ?? 'Goods'));

        $collectionDate = new \DateTimeImmutable('today');
        if ((int) $collectionDate->format('N') >= 6) {
            $collectionDate = $collectionDate->modify('next monday') ?: $collectionDate;
        }

        $deliveryAddress = [
            'organisation' => $company !== '' ? substr($company, 0, 35) : substr($contact, 0, 35),
            'street'       => substr($line1, 0, 35),
            'locality'     => substr(trim((string) ($addr['line2'] ?? '')), 0, 35),
            'town'         => substr($town, 0, 35),
            'postcode'     => substr($postcode, 0, 8),
            'countryCode'  => $country,
        ];
        $delivery = [
            'contactDetails' => [
                'contactName' => substr($contact, 0, 35),
                'telephone'   => $phone !== '' ? substr($phone, 0, 15) : '00000000000',
            ],
            'address'        => $deliveryAddress,
        ];
        if ($email !== '' || $phone !== '') {
            $delivery['notificationDetails'] = array_filter([
                'email'  => $email !== '' ? substr($email, 0, 50) : null,
                'mobile' => $phone !== '' ? substr($phone, 0, 15) : null,
            ]);
        }

        $body = [
            'jobId'                => null,
            'collectionOnDelivery' => false,
            'invoice'              => null,
            'collectionDate'       => $collectionDate->format('Y-m-d\T09:00:00'),
            'consolidate'          => false,
            'consignment'          => [[
                'consignmentNumber' => null,
                'consignmentRef'    => null,
                'collectionDetails' => [
                    'contactDetails' => [
                        'contactName' => substr(trim((string) ($settings['collection_name'] ?? 'Warehouse')) ?: 'Warehouse', 0, 35),
                        'telephone'   => substr(trim((string) ($settings['collection_phone'] ?? '00000000000')) ?: '00000000000', 0, 15),
                    ],
                    'address'        => [
                        'organisation' => substr(trim((string) ($settings['collection_name'] ?? 'Warehouse')) ?: 'Warehouse', 0, 35),
                        'street'       => substr($collectLine, 0, 35),
                        'town'         => substr($collectTown, 0, 35),
                        'postcode'     => substr($collectPostcode, 0, 8),
                        'countryCode'  => strtoupper(trim((string) ($settings['collection_country'] ?? 'GB')) ?: 'GB'),
                    ],
                ],
                'deliveryDetails'   => $delivery,
                'networkCode'       => $network,
                'numberOfParcels'   => $packages,
                'totalWeight'       => round($weight, 2),
                'shippingRef1'      => $reference,
                'customsValue'      => null,
                'parcelDescription' => substr($description !== '' ? $description : 'Goods', 0, 50),
                'liability'         => false,
                'liabilityValue'    => null,
                'parcel'            => [],
            ]],
        ];

        $headers = [
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'GEOSession'    => (string) $settings['access_token'],
            'GEOClient'     => 'account/' . $account,
        ];
        $res = $this->http->request('POST', self::API_BASE . '/shipping/shipment', $headers, $body);
        $json = is_array($res['json']) ? $res['json'] : [];
        $data = is_array($json['data'] ?? null) ? $json['data'] : [];
        $shipmentId = trim((string) ($data['shipmentId'] ?? ''));
        if ($res['status'] < 200 || $res['status'] >= 300 || $shipmentId === '' || $this->dpdError($json) !== '') {
            $detail = $this->dpdError($json);
            return [
                'ok'       => false,
                'message'  => 'DPD create shipment failed: ' . ($detail !== '' ? $detail : $this->snippet($res)),
                'settings' => $this->publicSettings($settings),
            ];
        }

        $details = is_array($data['consignmentDetail'] ?? null) ? $data['consignmentDetail'] : [];
        $first = is_array($details[0] ?? null) ? $details[0] : [];
        $tracking = trim((string) ($first['consignmentNumber'] ?? ''));
        if ($tracking === '' && is_array($first['parcelNumbers'] ?? null)) {
            $tracking = trim((string) ($first['parcelNumbers'][0] ?? ''));
        }
        if ($tracking === '') {
            $tracking = $shipmentId;
        }

        $labelHeaders = $headers;
        $labelHeaders['Accept'] = 'application/pdf';
        $label = $this->http->request(
            'GET',
            self::API_BASE . '/shipping/shipment/' . rawurlencode($shipmentId) . '/label/',
            $labelHeaders
        );
        $labelB64 = '';
        if ($label['status'] >= 200 && $label['status'] < 300) {
            $labelB64 = $this->pdfBase64((string) $label['body']);
        }
        if ($labelB64 === '') {
            return [
                'ok'       => false,
                'message'  => 'DPD label download failed: ' . $this->snippet($label),
                'settings' => $this->publicSettings($settings),
            ];
        }

        return [
            'ok'             => true,
            'message'        => 'DPD label created.',
            'tracking'       => $tracking,
            'consignment_id' => $shipmentId,
            'label_base64'   => $labelB64,
            'label_format'   => 'PDF',
            'tracking_url'   => 'https://track.dpd.co.uk/search?reference=' . rawurlencode($tracking),
            'settings'       => $this->publicSettings($settings),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function voidShipment(array $settings, string $shipmentId): array
    {
        $settings = $this->ensureSession($settings);
        $shipmentId = trim($shipmentId);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => (string) ($settings['_auth_error'] ?? 'DPD auth failed.'), 'settings' => $this->publicSettings($settings)];
        }
        if ($shipmentId === '') {
            return ['ok' => false, 'message' => 'DPD void needs the shipment id.', 'settings' => $this->publicSettings($settings)];
        }
        $res = $this->http->request(
            'DELETE',
            self::API_BASE . '/shipping/shipment/' . rawurlencode($shipmentId),
            $this->sessionHeaders($settings)
        );
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'DPD shipment ' . $shipmentId . ' cancelled.', 'settings' => $this->publicSettings($settings)];
        }
        return [
            'ok'       => false,
            'message'  => 'DPD void failed: ' . $this->snippet($res),
            'settings' => $this->publicSettings($settings),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,manifest_number:string,settings:array<string,mixed>}
     */
    public function createManifest(array $settings): array
    {
        $settings = $this->ensureSession($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok' => false,
                'message' => (string) ($settings['_auth_error'] ?? 'DPD auth failed.'),
                'manifest_number' => '',
                'settings' => $this->publicSettings($settings),
            ];
        }
        $res = $this->http->request('POST', self::API_BASE . '/shipping/manifest', $this->sessionHeaders($settings), []);
        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok' => false,
                'message' => 'DPD manifest failed: ' . $this->snippet($res),
                'manifest_number' => '',
                'settings' => $this->publicSettings($settings),
            ];
        }
        $number = '';
        if (is_array($res['json'])) {
            $number = trim((string) ($res['json']['data']['manifestId'] ?? $res['json']['manifestId'] ?? $res['json']['data']['manifestNumber'] ?? ''));
        }
        return [
            'ok' => true,
            'message' => $number !== '' ? 'DPD manifest ' . $number . ' created.' : 'DPD manifest created.',
            'manifest_number' => $number,
            'settings' => $this->publicSettings($settings),
        ];
    }

    /** @param array<string,mixed> $settings @return array<string,string> */
    private function sessionHeaders(array $settings): array
    {
        $headers = [
            'GEOSession'   => (string) ($settings['access_token'] ?? ''),
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $account = trim((string) ($settings['account_number'] ?? ''));
        if ($account !== '') {
            $headers['GEOClient'] = 'account/' . $account;
        }
        return $headers;
    }

    /**
     * @param array<string, string> $settings
     * @return array<string, string>
     */
    private function ensureSession(array $settings): array
    {
        unset($settings['_auth_error']);
        $expires = (int) ($settings['token_expires_at'] ?? 0);
        if (($settings['access_token'] ?? '') !== '' && ($settings['token_type'] ?? '') === 'GeoSession' && $expires >= time() + 30) {
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

    /** @param array<string, mixed> $json */
    private function dpdError(array $json): string
    {
        $error = $json['error'] ?? null;
        if ($error === null || $error === '') {
            return '';
        }
        if (is_string($error)) {
            return substr($error, 0, 240);
        }
        if (!is_array($error)) {
            return '';
        }
        $parts = [];
        $list = isset($error['errorMessage']) || isset($error['errorCode']) ? [$error] : $error;
        foreach (array_slice($list, 0, 3) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $msg = trim((string) ($row['errorMessage'] ?? $row['message'] ?? $row['errorAction'] ?? ''));
            $code = trim((string) ($row['errorCode'] ?? ''));
            $parts[] = trim($code !== '' && $msg !== '' ? $code . ': ' . $msg : ($msg ?: $code));
        }
        return substr(implode('; ', array_filter($parts)), 0, 240);
    }

    /** @param array<string, string> $settings @return array<string, string> */
    private function publicSettings(array $settings): array
    {
        unset($settings['_auth_error']);
        return $settings;
    }

    /** @param array{status:int, body:string, json:array<string,mixed>|null, error:?string} $res */
    private function snippet(array $res): string
    {
        $err = is_array($res['json']) ? $this->dpdError($res['json']) : '';
        if ($err !== '') {
            return 'HTTP ' . $res['status'] . ' — ' . $err;
        }
        $body = substr(trim((string) ($res['body'] ?? '')), 0, 240);
        $curl = (string) ($res['error'] ?? '');
        return 'HTTP ' . $res['status'] . ($body !== '' ? ': ' . $body : ($curl !== '' ? ': ' . $curl : ''));
    }

    private function pdfBase64(string $body): string
    {
        if (str_starts_with($body, '%PDF')) {
            return base64_encode($body);
        }
        $trim = trim($body);
        $decoded = base64_decode($trim, true);
        if ($trim !== '' && $decoded !== false && str_starts_with($decoded, '%PDF')) {
            return $trim;
        }
        return '';
    }

    /** @param array<string, string> $settings @return array{0:string,1:string,2:string} */
    private function creds(array $settings): array
    {
        $p = $this->platform();
        $user = trim((string) ($settings['api_key'] ?? $p['client_id'] ?? ''));
        $pass = trim((string) ($settings['api_secret'] ?? $p['client_secret'] ?? ''));
        $account = trim((string) ($settings['account_number'] ?? $p['account_number'] ?? ''));
        return [$user, $pass, $account];
    }
}
