<?php

declare(strict_types=1);

namespace App\Integrations\Evri;

use App\Integrations\BaseConnector;

/**
 * Evri Business API OAuth2 client_credentials.
 * POST https://api.business.evri.com/oauth/token
 */
final class EvriConnector extends BaseConnector
{
    public const TOKEN_URL = 'https://api.business.evri.com/oauth/token';
    public const API_BASE  = 'https://api.business.evri.com';

    public function key(): string
    {
        return 'evri';
    }

    public function label(): string
    {
        return 'Evri';
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
        $p = $this->platform();
        $clientId = trim((string) ($settings['api_key'] ?? $p['client_id'] ?? ''));
        $clientSecret = trim((string) ($settings['api_secret'] ?? $p['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            return ['ok' => false, 'message' => 'Evri Client ID and Client Secret are required.'];
        }

        $res = $this->http->request(
            'POST',
            self::TOKEN_URL,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
            ]
        );

        $access = (string) ($res['json']['access_token'] ?? '');
        if ($res['status'] < 200 || $res['status'] >= 300 || $access === '') {
            return [
                'ok'      => false,
                'message' => 'Evri Auth failed HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 240),
            ];
        }

        $expiresIn = (int) ($res['json']['expires_in'] ?? 3600);
        $settings['api_key'] = $clientId;
        $settings['api_secret'] = $clientSecret;
        $settings['access_token'] = $access;
        $settings['token_type'] = (string) ($res['json']['token_type'] ?? 'Bearer');
        $settings['token_expires_at'] = (string) (time() + $expiresIn);
        $settings['auth_mode'] = 'oauth_client_credentials';
        $settings['provider'] = 'evri';
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['last_connected_at'] = date('c');

        return [
            'ok'       => true,
            'message'  => 'Evri access token issued.',
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
            $fallback = parent::connectCredentials($channel, $settings);
            if (!empty($fallback['ok'])) {
                $fallback['message'] = 'Evri credentials saved. Auth note: ' . (string) ($auth['message'] ?? '');
                return $fallback;
            }
            return $auth;
        }
        $settings = $auth['settings'] ?? $settings;
        if (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? 'evri');
        }
        return ['ok' => true, 'settings' => $settings, 'message' => 'Evri linked — access token obtained from Auth API.'];
    }

    public function testConnection(array $settings): array
    {
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            return ['ok' => false, 'message' => (string) ($auth['message'] ?? 'Auth failed')];
        }
        $settings = $auth['settings'] ?? $settings;
        $res = $this->http->request('GET', self::API_BASE . '/api/customers', [
            'Authorization' => 'Bearer ' . $settings['access_token'],
            'Accept'        => 'application/json',
        ]);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'Evri Auth + API OK.', 'settings' => $settings];
        }
        return [
            'ok'       => true,
            'soft'     => true,
            'message'  => 'Evri Auth token valid (API probe HTTP ' . $res['status'] . ').',
            'settings' => $settings,
        ];
    }

    /**
     * Evri Classic API: POST /api/parcels then GET /api/labels/{barcode}.
     *
     * Service codes: STANDARD, POSTABLE, NEXT_DAY (also NDAY / NEXTDAY).
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
        $settings = $this->ensureAccessToken($settings);
        if (($settings['access_token'] ?? '') === '') {
            return [
                'ok'       => false,
                'message'  => (string) ($settings['_auth_error'] ?? 'Evri auth failed.'),
                'settings' => $this->publicSettings($settings),
            ];
        }

        $code = strtoupper(trim((string) ($payload['service_code'] ?? '')));
        if ($code === '') {
            return [
                'ok'       => false,
                'message'  => 'Set a default service code (STANDARD, POSTABLE, or NEXT_DAY).',
                'settings' => $this->publicSettings($settings),
            ];
        }
        $known = ['STANDARD', 'POSTABLE', 'NEXT_DAY', 'NEXTDAY', 'NDAY'];
        if (!in_array($code, $known, true)) {
            return [
                'ok'       => false,
                'message'  => 'Evri service code must be STANDARD, POSTABLE, or NEXT_DAY.',
                'settings' => $this->publicSettings($settings),
            ];
        }

        $addr = is_array($payload['address'] ?? null) ? $payload['address'] : [];
        $line1 = $this->clip((string) ($addr['line1'] ?? ''), 32);
        $postcode = $this->clip((string) ($addr['postcode'] ?? ''), 10);
        $contact = trim((string) ($addr['contact_name'] ?? ''));
        if ($line1 === '' || $postcode === '' || $contact === '') {
            return [
                'ok'       => false,
                'message'  => 'Evri label needs contact name, line1 and postcode.',
                'settings' => $this->publicSettings($settings),
            ];
        }

        $nameParts = preg_split('/\s+/', $contact, 2) ?: [];
        $first = $this->clip((string) ($nameParts[0] ?? ''), 32);
        $last = $this->clip((string) ($nameParts[1] ?? $first), 32);
        if ($last === '') {
            $last = 'Customer';
        }
        if (count($nameParts) < 2) {
            $first = '';
        }

        $weight = (float) ($payload['weight_kg'] ?? 0);
        if ($weight <= 0) {
            $weight = 0.5;
        }
        $reference = $this->clip(trim((string) ($payload['reference'] ?? '')), 20);
        if ($reference === '') {
            $reference = 'SF' . date('ymdHis');
        }
        $prefix = trim((string) ($settings['client_uid'] ?? ''));
        $clientUid = $prefix !== '' ? $this->clip($prefix . '-' . $reference, 240) : $reference;
        $clientUid .= '-' . bin2hex(random_bytes(3));

        $description = trim((string) ($payload['description'] ?? 'Goods'));
        if ($description === '') {
            $description = 'Goods';
        }
        $nextDay = in_array($code, ['NEXT_DAY', 'NEXTDAY', 'NDAY'], true);
        $deliveryAddress = [
            'line1'    => $line1,
            'postcode' => $postcode,
        ];
        $line2 = $this->clip((string) ($addr['line2'] ?? ''), 32);
        $city = $this->clip((string) ($addr['city'] ?? ''), 32);
        if ($line2 !== '') {
            $deliveryAddress['line2'] = $line2;
        }
        if ($city !== '') {
            $deliveryAddress[$line2 !== '' ? 'line3' : 'line2'] = $city;
        }

        $delivery = [
            'deliveryAddress' => $deliveryAddress,
            'lastName'        => $last,
            'nextDay'         => $nextDay,
        ];
        if ($first !== '') {
            $delivery['firstName'] = $first;
        }
        $email = $this->clip((string) ($addr['email'] ?? ''), 80);
        $phone = $this->clip((string) ($addr['phone'] ?? ''), 15);
        if ($email !== '') {
            $delivery['email'] = $email;
        }
        if ($phone !== '') {
            $delivery['telephone'] = $phone;
        }

        $body = [
            'parcels' => [[
                'clientUID'       => $clientUid,
                'parcelDetails'   => [
                    'weightKg'                   => round($weight, 3),
                    'itemDescription'            => $this->clip($description, 255),
                    'deliveryReference'          => $reference,
                    'estimatedParcelValuePounds' => round(max(0, (float) ($payload['total'] ?? 0)), 2),
                    'type'                       => $code === 'POSTABLE' ? 'POSTABLE' : 'STANDARD',
                ],
                'deliveryDetails' => $delivery,
            ]],
        ];

        $res = $this->http->request(
            'POST',
            self::API_BASE . '/api/parcels',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/vnd.myhermes.parcelsummaries-v1+json',
                'Content-Type'  => 'application/json',
            ],
            $body
        );

        if ($res['status'] < 200 || $res['status'] >= 300) {
            return [
                'ok'       => false,
                'message'  => 'Evri create parcel failed: ' . $this->snippet($res),
                'settings' => $this->publicSettings($settings),
            ];
        }

        $summaries = is_array($res['json']['parcelSummaries'] ?? null) ? $res['json']['parcelSummaries'] : [];
        $firstParcel = is_array($summaries[0] ?? null) ? $summaries[0] : [];
        $status = strtoupper((string) ($firstParcel['status'] ?? ''));
        $barcode = trim((string) ($firstParcel['barcode'] ?? ''));
        if ($status === 'INVALID' || $barcode === '') {
            $errors = is_array($firstParcel['errors'] ?? null) ? $firstParcel['errors'] : [];
            $parts = [];
            foreach (array_slice($errors, 0, 3) as $err) {
                if (!is_array($err)) {
                    continue;
                }
                $parts[] = trim((string) ($err['error_description'] ?? $err['error'] ?? ''));
            }
            $detail = implode('; ', array_filter($parts));
            return [
                'ok'       => false,
                'message'  => 'Evri parcel was not created' . ($detail !== '' ? ': ' . $detail : '.'),
                'settings' => $this->publicSettings($settings),
            ];
        }

        $label = $this->http->request(
            'GET',
            self::API_BASE . '/api/labels/' . rawurlencode($barcode) . '?format=DEFAULT',
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/pdf',
            ]
        );
        $labelB64 = '';
        if ($label['status'] >= 200 && $label['status'] < 300) {
            $labelB64 = $this->pdfBase64((string) $label['body']);
        }
        if ($labelB64 === '') {
            return [
                'ok'       => false,
                'message'  => 'Evri label download failed: ' . $this->snippet($label),
                'settings' => $this->publicSettings($settings),
            ];
        }

        return [
            'ok'             => true,
            'message'        => 'Evri label created.',
            'tracking'       => $barcode,
            'consignment_id' => $barcode,
            'label_base64'   => $labelB64,
            'label_format'   => 'PDF',
            'tracking_url'   => 'https://www.evri.com/track-a-parcel?trackingNumber=' . rawurlencode($barcode),
            'settings'       => $this->publicSettings($settings),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function voidShipment(array $settings, string $barcode): array
    {
        $settings = $this->ensureAccessToken($settings);
        $barcode = trim($barcode);
        if (($settings['access_token'] ?? '') === '') {
            return ['ok' => false, 'message' => (string) ($settings['_auth_error'] ?? 'Evri auth failed.'), 'settings' => $this->publicSettings($settings)];
        }
        if ($barcode === '') {
            return ['ok' => false, 'message' => 'Evri void needs the parcel barcode.', 'settings' => $this->publicSettings($settings)];
        }
        $res = $this->http->request(
            'DELETE',
            self::API_BASE . '/api/parcels/' . rawurlencode($barcode),
            [
                'Authorization' => 'Bearer ' . $settings['access_token'],
                'Accept'        => 'application/json',
            ]
        );
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return ['ok' => true, 'message' => 'Evri parcel ' . $barcode . ' cancelled.', 'settings' => $this->publicSettings($settings)];
        }
        return [
            'ok'       => false,
            'message'  => 'Evri void failed: ' . $this->snippet($res),
            'settings' => $this->publicSettings($settings),
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

    /** @param array<string, string> $settings @return array<string, string> */
    private function publicSettings(array $settings): array
    {
        unset($settings['_auth_error']);
        return $settings;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        if ($max <= 0 || strlen($value) <= $max) {
            return $value;
        }
        return substr($value, 0, $max);
    }

    /** @param array{status:int, body:string, json:array<string,mixed>|null, error:?string} $res */
    private function snippet(array $res): string
    {
        if (is_array($res['json'])) {
            $msg = (string) ($res['json']['error_description'] ?? $res['json']['message'] ?? $res['json']['error'] ?? '');
            if ($msg !== '') {
                return 'HTTP ' . $res['status'] . ' — ' . substr($msg, 0, 240);
            }
        }
        $body = substr(trim((string) ($res['body'] ?? '')), 0, 240);
        $err = (string) ($res['error'] ?? '');
        return 'HTTP ' . $res['status'] . ($body !== '' ? ': ' . $body : ($err !== '' ? ': ' . $err : ''));
    }

    private function pdfBase64(string $body): string
    {
        if (str_starts_with($body, '%PDF')) {
            return base64_encode($body);
        }
        $trim = trim($body);
        if ($trim !== '' && base64_decode($trim, true) !== false && str_starts_with((string) base64_decode($trim, true), '%PDF')) {
            return $trim;
        }
        return '';
    }
}
