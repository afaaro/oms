<?php

declare(strict_types=1);

namespace App\Integrations\ParcelForce;

use App\Integrations\BaseConnector;

/**
 * ParcelForce ExpressLink — SOAP username/password auth (no public OAuth token URL).
 * Auth validates credentials are present and stores them for ExpressLink requests.
 */
final class ParcelForceConnector extends BaseConnector
{
    public const WSDL_HINT = 'ExpressLink WSDL supplied by ParcelForce (host locally)';
    public const TEST_HINT = 'https://expresslink-test.parcelforce.net/ (legacy; WSDL now local)';

    public function key(): string
    {
        return 'parcelforce';
    }

    public function label(): string
    {
        return 'ParcelForce';
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
     * ExpressLink uses per-request Authentication { UserName, Password }.
     * This "auth" call validates + stores credentials and returns an auth payload for clients.
     *
     * @param array<string, string> $settings
     * @return array{ok:bool, message:string, settings?:array<string,string>, token?:array<string,mixed>}
     */
    public function authenticate(array $settings): array
    {
        $p = $this->platform();
        $user = trim((string) ($settings['api_key'] ?? $p['client_id'] ?? ''));
        $pass = trim((string) ($settings['api_secret'] ?? $p['client_secret'] ?? ''));
        $account = trim((string) ($settings['account_number'] ?? $p['account_number'] ?? ''));
        $dept = trim((string) ($settings['department_id'] ?? $p['department_id'] ?? ''));

        if ($user === '' || $pass === '') {
            return ['ok' => false, 'message' => 'ParcelForce ExpressLink username and password are required.'];
        }

        $settings['api_key'] = $user;
        $settings['api_secret'] = $pass;
        if ($account !== '') {
            $settings['account_number'] = $account;
        }
        if ($dept !== '') {
            $settings['department_id'] = $dept;
        }
        $settings['auth_mode'] = 'expresslink';
        $settings['provider'] = 'parcelforce';
        $settings['linked'] = '1';
        $settings['connection_status'] = 'linked';
        $settings['last_connected_at'] = date('c');
        // No bearer token — SOAP auth node on each call
        $settings['access_token'] = base64_encode($user . ':' . substr($pass, 0, 1) . '***');
        $settings['token_type'] = 'ExpressLink';
        $settings['token_expires_at'] = '0';

        return [
            'ok'       => true,
            'message'  => 'ParcelForce ExpressLink credentials accepted and stored.',
            'settings' => $settings,
            'token'    => [
                'auth_style'     => 'soap_authentication_node',
                'username'       => $user,
                'password_set'   => true,
                'account_number' => $account !== '' ? $account : null,
                'department_id'  => $dept !== '' ? $dept : null,
                'wsdl'           => self::WSDL_HINT,
                'note'           => 'ExpressLink sends UserName/Password in each SOAP Authentication element — no OAuth token URL.',
            ],
        ];
    }

    public function connectCredentials(array $channel, array $settings): array
    {
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            return $auth;
        }
        $settings = $auth['settings'] ?? $settings;
        if (($settings['linked_user'] ?? '') === '') {
            $settings['linked_user'] = (string) ($channel['code'] ?? 'parcelforce');
        }
        return ['ok' => true, 'settings' => $settings, 'message' => 'ParcelForce linked — ExpressLink credentials stored.'];
    }

    public function testConnection(array $settings): array
    {
        $auth = $this->authenticate($settings);
        if (empty($auth['ok'])) {
            return ['ok' => false, 'message' => (string) ($auth['message'] ?? 'Auth failed')];
        }
        return [
            'ok'       => true,
            'soft'     => true,
            'message'  => 'ParcelForce ExpressLink credentials present (SOAP live call needs local WSDL from ParcelForce).',
            'settings' => $auth['settings'] ?? $settings,
        ];
    }

    /**
     * ExpressLink createShipment + printLabel via raw SOAP (no local WSDL required).
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
        $user = trim((string) ($settings['api_key'] ?? ''));
        $pass = trim((string) ($settings['api_secret'] ?? ''));
        $contract = trim((string) ($settings['account_number'] ?? ''));
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'message' => 'ParcelForce username and password are required.', 'settings' => $settings];
        }
        if ($contract === '') {
            return ['ok' => false, 'message' => 'ParcelForce channel needs a contract number.', 'settings' => $settings];
        }

        $service = strtoupper(trim((string) ($payload['service_code'] ?? '')));
        if ($service === '' || preg_match('/^[A-Z0-9]{2,8}$/', $service) !== 1) {
            return [
                'ok'       => false,
                'message'  => 'Set a ParcelForce service code (for example SND) as the default service code.',
                'settings' => $settings,
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
                'message'  => 'ParcelForce label needs contact name, line1, town and postcode.',
                'settings' => $settings,
            ];
        }

        $endpoint = trim((string) ($settings['expresslink_url'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://expresslink.parcelforce.net/ws/';
        }
        if (!str_starts_with(strtolower($endpoint), 'https://')) {
            return ['ok' => false, 'message' => 'ParcelForce ExpressLink URL must be HTTPS.', 'settings' => $settings];
        }

        $packages = max(1, (int) ($payload['package_count'] ?? 1));
        $reference = substr(trim((string) ($payload['reference'] ?? '')), 0, 24);
        $company = trim((string) ($addr['company'] ?? ''));
        $phone = trim((string) ($addr['phone'] ?? ''));
        $email = trim((string) ($addr['email'] ?? ''));
        $line2 = trim((string) ($addr['line2'] ?? ''));
        $dept = trim((string) ($settings['department_id'] ?? ''));

        $contactXml = '<ns:ContactName>' . $this->xml($contact) . '</ns:ContactName>';
        if ($company !== '') {
            $contactXml .= '<ns:BusinessName>' . $this->xml($company) . '</ns:BusinessName>';
        }
        if ($phone !== '') {
            $contactXml .= '<ns:Telephone>' . $this->xml($phone) . '</ns:Telephone>';
        }
        if ($email !== '') {
            $contactXml .= '<ns:EmailAddress>' . $this->xml($email) . '</ns:EmailAddress>';
        }

        $addressXml = '<ns:AddressLine1>' . $this->xml(substr($line1, 0, 35)) . '</ns:AddressLine1>';
        if ($line2 !== '') {
            $addressXml .= '<ns:AddressLine2>' . $this->xml(substr($line2, 0, 35)) . '</ns:AddressLine2>';
        }
        $addressXml .= '<ns:Town>' . $this->xml(substr($town, 0, 30)) . '</ns:Town>'
            . '<ns:Postcode>' . $this->xml($postcode) . '</ns:Postcode>'
            . '<ns:Country>' . $this->xml($country) . '</ns:Country>';

        $deptXml = $dept !== '' ? '<ns:DepartmentId>' . $this->xml($dept) . '</ns:DepartmentId>' : '';
        $refXml = $reference !== '' ? '<ns:ReferenceNumber1>' . $this->xml($reference) . '</ns:ReferenceNumber1>' : '';

        $inner = '<ns:CreateShipmentRequest>'
            . $this->authXml($user, $pass)
            . '<ns:RequestedShipment>'
            . '<ns:ShipmentType>DELIVERY</ns:ShipmentType>'
            . '<ns:ContractNumber>' . $this->xml($contract) . '</ns:ContractNumber>'
            . $deptXml
            . '<ns:ServiceCode>' . $this->xml($service) . '</ns:ServiceCode>'
            . '<ns:ShippingDate>' . date('Y-m-d') . '</ns:ShippingDate>'
            . $refXml
            . '<ns:RecipientContact>' . $contactXml . '</ns:RecipientContact>'
            . '<ns:RecipientAddress>' . $addressXml . '</ns:RecipientAddress>'
            . '<ns:TotalNumberOfParcels>' . $packages . '</ns:TotalNumberOfParcels>'
            . '</ns:RequestedShipment>'
            . '</ns:CreateShipmentRequest>';

        $created = $this->soap($endpoint, 'createShipment', $inner);
        if ($created['ok'] === false) {
            return ['ok' => false, 'message' => $created['message'], 'settings' => $settings];
        }

        $shipmentNumber = $this->xmlValue($created['xml'], 'ShipmentNumber');
        if ($shipmentNumber === '') {
            $alert = $this->alertMessage($created['xml']);
            return [
                'ok'       => false,
                'message'  => 'ParcelForce createShipment did not return a shipment number' . ($alert !== '' ? ': ' . $alert : '.'),
                'settings' => $settings,
            ];
        }

        $printInner = '<ns:PrintLabelRequest>'
            . $this->authXml($user, $pass)
            . '<ns:ShipmentNumber>' . $this->xml($shipmentNumber) . '</ns:ShipmentNumber>'
            . '</ns:PrintLabelRequest>';
        $printed = $this->soap($endpoint, 'printLabel', $printInner);
        if ($printed['ok'] === false) {
            return [
                'ok'       => false,
                'message'  => 'ParcelForce shipment ' . $shipmentNumber . ' created but label failed: ' . $printed['message'],
                'settings' => $settings,
            ];
        }
        $labelB64 = preg_replace('/\s+/', '', $this->xmlValue($printed['xml'], 'Data')) ?? '';
        $raw = $labelB64 !== '' ? base64_decode($labelB64, true) : false;
        if ($raw === false || !str_starts_with($raw, '%PDF')) {
            $alert = $this->alertMessage($printed['xml']);
            return [
                'ok'       => false,
                'message'  => 'ParcelForce printLabel did not return a PDF' . ($alert !== '' ? ': ' . $alert : '.'),
                'settings' => $settings,
            ];
        }

        return [
            'ok'             => true,
            'message'        => 'ParcelForce label created.',
            'tracking'       => $shipmentNumber,
            'consignment_id' => $shipmentNumber,
            'label_base64'   => $labelB64,
            'label_format'   => 'PDF',
            'tracking_url'   => 'https://www.parcelforce.com/track-trace?trackNumber=' . rawurlencode($shipmentNumber),
            'settings'       => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,settings:array<string,mixed>}
     */
    public function voidShipment(array $settings, string $shipmentNumber): array
    {
        $shipmentNumber = trim($shipmentNumber);
        $user = trim((string) ($settings['api_key'] ?? ''));
        $pass = trim((string) ($settings['api_secret'] ?? ''));
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'message' => 'ParcelForce username and password are required to void.', 'settings' => $settings];
        }
        if ($shipmentNumber === '') {
            return ['ok' => false, 'message' => 'ParcelForce void needs the shipment number.', 'settings' => $settings];
        }
        $endpoint = $this->endpoint($settings);
        if ($endpoint === null) {
            return ['ok' => false, 'message' => 'ParcelForce ExpressLink URL must be HTTPS.', 'settings' => $settings];
        }
        $inner = '<ns:CancelShipmentRequest>' . $this->authXml($user, $pass)
            . '<ns:ShipmentNumber>' . $this->xml($shipmentNumber) . '</ns:ShipmentNumber>'
            . '</ns:CancelShipmentRequest>';
        $res = $this->soap($endpoint, 'cancelShipment', $inner);
        if ($res['ok'] === false) {
            return ['ok' => false, 'message' => 'ParcelForce void failed: ' . $res['message'], 'settings' => $settings];
        }
        $alert = $this->alertMessage($res['xml']);
        if ($alert !== '' && stripos($alert, 'ERROR') !== false) {
            return ['ok' => false, 'message' => 'ParcelForce void failed: ' . $alert, 'settings' => $settings];
        }
        return ['ok' => true, 'message' => 'ParcelForce shipment ' . $shipmentNumber . ' cancelled.', 'settings' => $settings];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array{ok:bool,message:string,manifest_number:string,settings:array<string,mixed>}
     */
    public function createManifest(array $settings): array
    {
        $user = trim((string) ($settings['api_key'] ?? ''));
        $pass = trim((string) ($settings['api_secret'] ?? ''));
        if ($user === '' || $pass === '') {
            return ['ok' => false, 'message' => 'ParcelForce username and password are required.', 'manifest_number' => '', 'settings' => $settings];
        }
        $endpoint = $this->endpoint($settings);
        if ($endpoint === null) {
            return ['ok' => false, 'message' => 'ParcelForce ExpressLink URL must be HTTPS.', 'manifest_number' => '', 'settings' => $settings];
        }
        $inner = '<ns:CreateManifestRequest>' . $this->authXml($user, $pass) . '</ns:CreateManifestRequest>';
        $res = $this->soap($endpoint, 'createManifest', $inner);
        if ($res['ok'] === false) {
            return ['ok' => false, 'message' => 'ParcelForce manifest failed: ' . $res['message'], 'manifest_number' => '', 'settings' => $settings];
        }
        $number = $this->xmlValue($res['xml'], 'ManifestNumber');
        $alert = $this->alertMessage($res['xml']);
        if ($number === '' && $alert !== '') {
            return ['ok' => false, 'message' => 'ParcelForce manifest failed: ' . $alert, 'manifest_number' => '', 'settings' => $settings];
        }
        return [
            'ok' => true,
            'message' => $number !== '' ? 'ParcelForce manifest ' . $number . ' created.' : 'ParcelForce manifest created.',
            'manifest_number' => $number,
            'settings' => $settings,
        ];
    }

    /** @param array<string,mixed> $settings */
    private function endpoint(array $settings): ?string
    {
        $endpoint = trim((string) ($settings['expresslink_url'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'https://expresslink.parcelforce.net/ws/';
        }
        if (!str_starts_with(strtolower($endpoint), 'https://')) {
            return null;
        }
        return $endpoint;
    }

    private function authXml(string $user, string $pass): string
    {
        return '<ns:Authentication><ns:UserName>' . $this->xml($user) . '</ns:UserName><ns:Password>'
            . $this->xml($pass) . '</ns:Password></ns:Authentication>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @return array{ok:bool, message:string, xml:string}
     */
    private function soap(string $endpoint, string $action, string $bodyInner): array
    {
        $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ns="http://www.parcelforce.net/ws/ship/v14">'
            . '<soapenv:Header/>'
            . '<soapenv:Body>' . $bodyInner . '</soapenv:Body>'
            . '</soapenv:Envelope>';

        $res = $this->http->request(
            'POST',
            $endpoint,
            [
                'Content-Type' => 'text/xml; charset=utf-8',
                'SOAPAction'   => $action,
                'Accept'       => 'text/xml',
            ],
            $envelope
        );
        $xml = (string) ($res['body'] ?? '');
        if ($res['status'] < 200 || $res['status'] >= 300 || $xml === '') {
            $fault = $this->xmlValue($xml, 'faultstring');
            return [
                'ok'      => false,
                'message' => 'ParcelForce ' . $action . ' failed'
                    . ($fault !== '' ? ': ' . $fault : ': HTTP ' . $res['status'] . ' ' . substr($xml, 0, 180)),
                'xml'     => $xml,
            ];
        }
        $fault = $this->xmlValue($xml, 'faultstring');
        if ($fault !== '') {
            return ['ok' => false, 'message' => 'ParcelForce ' . $action . ' fault: ' . $fault, 'xml' => $xml];
        }
        return ['ok' => true, 'message' => 'OK', 'xml' => $xml];
    }

    private function xmlValue(string $xml, string $localName): string
    {
        if (trim($xml) === '') {
            return '';
        }
        $doc = new \DOMDocument();
        if (@$doc->loadXML($xml) === false) {
            return '';
        }
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query('//*[local-name()="' . $localName . '"]');
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        return trim((string) $nodes->item(0)?->textContent);
    }

    private function alertMessage(string $xml): string
    {
        if (trim($xml) === '') {
            return '';
        }
        $doc = new \DOMDocument();
        if (@$doc->loadXML($xml) === false) {
            return '';
        }
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query('//*[local-name()="Alert"]');
        if ($nodes === false) {
            return '';
        }
        $parts = [];
        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $type = '';
            $code = '';
            $message = '';
            foreach ($node->childNodes as $child) {
                if (!$child instanceof \DOMElement) {
                    continue;
                }
                $name = $child->localName ?? '';
                $text = trim($child->textContent);
                if ($name === 'Type') {
                    $type = $text;
                } elseif ($name === 'Code') {
                    $code = $text;
                } elseif ($name === 'Message') {
                    $message = $text;
                }
            }
            if ($message === '' && $code === '') {
                continue;
            }
            $parts[] = trim(($type !== '' ? $type . ' ' : '') . ($code !== '' ? $code . ': ' : '') . $message);
            if (count($parts) >= 3) {
                break;
            }
        }
        return substr(implode('; ', $parts), 0, 240);
    }
}
