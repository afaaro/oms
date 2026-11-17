<?php

declare(strict_types=1);

namespace App\Integrations\Ebay;

use App\Core\App;
use App\Integrations\HttpClient;
use App\Integrations\IntegrationConfig;
use App\Support\ErrorHandler;
use App\Support\Logger;

/**
 * eBay Marketplace Account Deletion/Closure notifications.
 * @see https://developer.ebay.com/develop/guides/marketplace-user-account-deletion
 */
final class EbayAccountDeletionService
{
    private const PUBLIC_KEY_URL = 'https://api.ebay.com/commerce/notification/v1/public_key/';
    private const TOKEN_URL = 'https://api.ebay.com/identity/v1/oauth2/token';
    private const KEY_CACHE_TTL = 3600;

    public function __construct(private App $app) {}

    public function verificationToken(): string
    {
        return trim((string) (new IntegrationConfig($this->app))->get('ebay', 'deletion_verification_token', ''));
    }

    /**
     * Verify X-EBAY-SIGNATURE against the raw POST body (eBay Event Notification).
     * Returns false on missing header, bad signature, or key-fetch failure.
     */
    public function verifySignature(string $rawBody, string $signatureHeader): bool
    {
        $signatureHeader = trim($signatureHeader);
        if ($signatureHeader === '' || $rawBody === '') {
            $this->logger()->warning('eBay deletion signature missing header or body', [], 'ebay_account_deletion');
            return false;
        }

        $decodedJson = base64_decode($signatureHeader, true);
        if ($decodedJson === false || $decodedJson === '') {
            $this->logger()->warning('eBay deletion signature header not valid base64', [], 'ebay_account_deletion');
            return false;
        }

        $sig = json_decode($decodedJson, true);
        if (!is_array($sig)) {
            $this->logger()->warning('eBay deletion signature header JSON decode failed', [], 'ebay_account_deletion');
            return false;
        }

        $kid = trim((string) ($sig['kid'] ?? ''));
        $signatureB64 = trim((string) ($sig['signature'] ?? ''));
        if ($kid === '' || $signatureB64 === '') {
            $this->logger()->warning('eBay deletion signature missing kid/signature', [], 'ebay_account_deletion');
            return false;
        }

        $binarySig = base64_decode($signatureB64, true);
        if ($binarySig === false || $binarySig === '') {
            $this->logger()->warning('eBay deletion signature blob not valid base64', ['kid' => $kid], 'ebay_account_deletion');
            return false;
        }

        $publicKeyPem = $this->publicKeyPem($kid);
        if ($publicKeyPem === null) {
            $this->logger()->warning('eBay deletion public key fetch failed', ['kid' => $kid], 'ebay_account_deletion');
            return false;
        }

        // Prefer raw body; fall back to re-encoded JSON (matches eBay PHP SDK behaviour).
        $candidates = [$rawBody];
        $parsed = json_decode($rawBody, true);
        if (is_array($parsed)) {
            $candidates[] = (string) json_encode($parsed);
            $candidates[] = (string) json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach (array_unique($candidates) as $message) {
            $ok = openssl_verify($message, $binarySig, $publicKeyPem, OPENSSL_ALGO_SHA1);
            if ($ok === 1) {
                return true;
            }
        }

        $this->logger()->warning(
            'eBay deletion signature mismatch',
            ['kid' => $kid, 'openssl_error' => openssl_error_string() ?: ''],
            'ebay_account_deletion'
        );
        return false;
    }

    /**
     * Read X-EBAY-SIGNATURE from the current request (header casing varies).
     */
    public function signatureHeaderFromRequest(): string
    {
        $candidates = [
            $_SERVER['HTTP_X_EBAY_SIGNATURE'] ?? '',
            $_SERVER['REDIRECT_HTTP_X_EBAY_SIGNATURE'] ?? '',
        ];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'X-EBAY-SIGNATURE') === 0) {
                    $candidates[] = (string) $value;
                }
            }
        }
        foreach ($candidates as $v) {
            $v = trim((string) $v);
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    private function publicKeyPem(string $kid): ?string
    {
        $cached = $this->readKeyCache($kid);
        if (is_array($cached) && trim((string) ($cached['key'] ?? '')) !== '') {
            return $this->formatPem((string) $cached['key']);
        }

        $token = $this->applicationAccessToken();
        if ($token === '') {
            return null;
        }

        $httpsOnly = (bool) $this->app->config->get('app.security.https_only', true);
        $verifySsl = (bool) $this->app->config->get('app.security.verify_ssl', true);
        $http = new HttpClient($httpsOnly, $verifySsl);
        $res = $http->request(
            'GET',
            self::PUBLIC_KEY_URL . rawurlencode($kid),
            [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            $this->logger()->warning(
                'eBay getPublicKey HTTP ' . $res['status'],
                ['kid' => $kid, 'body' => substr((string) ($res['body'] ?? ''), 0, 200)],
                'ebay_account_deletion'
            );
            return null;
        }

        $key = trim((string) ($res['json']['key'] ?? ''));
        if ($key === '') {
            return null;
        }

        $this->writeKeyCache($kid, [
            'key'       => $key,
            'algorithm' => (string) ($res['json']['algorithm'] ?? ''),
            'digest'    => (string) ($res['json']['digest'] ?? ''),
            'cached_at' => time(),
        ]);

        return $this->formatPem($key);
    }

    private function applicationAccessToken(): string
    {
        $cfg = (new IntegrationConfig($this->app))->provider('ebay');
        $clientId = trim((string) ($cfg['client_id'] ?? ''));
        $clientSecret = trim((string) ($cfg['client_secret'] ?? ''));
        if ($clientId === '' || $clientSecret === '') {
            $this->logger()->warning(
                'eBay app credentials missing for signature verify (Marketplace Apps)',
                [],
                'ebay_account_deletion'
            );
            return '';
        }

        $cacheFile = $this->cacheDir() . '/ebay_app_token.json';
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)
                && (string) ($cached['access_token'] ?? '') !== ''
                && (int) ($cached['expires_at'] ?? 0) > time() + 60
            ) {
                return (string) $cached['access_token'];
            }
        }

        $httpsOnly = (bool) $this->app->config->get('app.security.https_only', true);
        $verifySsl = (bool) $this->app->config->get('app.security.verify_ssl', true);
        $http = new HttpClient($httpsOnly, $verifySsl);
        $res = $http->request(
            'POST',
            self::TOKEN_URL,
            [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            [
                'grant_type' => 'client_credentials',
                'scope'      => 'https://api.ebay.com/oauth/api_scope',
            ]
        );

        if ($res['status'] < 200 || $res['status'] >= 300 || !is_array($res['json'])) {
            $this->logger()->warning(
                'eBay app token HTTP ' . $res['status'],
                ['body' => substr((string) ($res['body'] ?? ''), 0, 200)],
                'ebay_account_deletion'
            );
            return '';
        }

        $access = trim((string) ($res['json']['access_token'] ?? ''));
        if ($access === '') {
            return '';
        }

        $expiresIn = (int) ($res['json']['expires_in'] ?? 7200);
        @file_put_contents($cacheFile, json_encode([
            'access_token' => $access,
            'expires_at'   => time() + max(60, $expiresIn),
        ], JSON_UNESCAPED_UNICODE));

        return $access;
    }

    private function formatPem(string $key): string
    {
        $key = trim($key);
        if (str_contains($key, 'BEGIN PUBLIC KEY')) {
            if (preg_match('/-----BEGIN PUBLIC KEY-----(.+)-----END PUBLIC KEY-----/s', $key, $m)) {
                $body = preg_replace('/\s+/', '', $m[1]) ?? '';
                return "-----BEGIN PUBLIC KEY-----\n"
                    . trim(chunk_split($body, 64, "\n"))
                    . "\n-----END PUBLIC KEY-----";
            }
            return $key;
        }
        $body = preg_replace('/\s+/', '', $key) ?? '';
        return "-----BEGIN PUBLIC KEY-----\n"
            . trim(chunk_split($body, 64, "\n"))
            . "\n-----END PUBLIC KEY-----";
    }

    /** @return array<string,mixed>|null */
    private function readKeyCache(string $kid): ?array
    {
        $file = $this->keyCachePath($kid);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return null;
        }
        $cachedAt = (int) ($data['cached_at'] ?? 0);
        if ($cachedAt > 0 && (time() - $cachedAt) > self::KEY_CACHE_TTL) {
            return null;
        }
        return $data;
    }

    /** @param array<string,mixed> $data */
    private function writeKeyCache(string $kid, array $data): void
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($this->keyCachePath($kid), json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function keyCachePath(string $kid): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $kid) ?: 'key';
        return $this->cacheDir() . '/ebay_pubkey_' . $safe . '.json';
    }

    private function cacheDir(): string
    {
        return rtrim(str_replace('\\', '/', $this->app->basePath), '/') . '/storage/cache';
    }

    /**
     * Exact HTTPS URL registered in the eBay Developer Portal.
     */
    public function endpointUrl(): string
    {
        $cfg = new IntegrationConfig($this->app);
        $override = trim((string) $cfg->get('ebay', 'deletion_endpoint', ''));
        if ($override !== '') {
            return rtrim($override, '/');
        }
        return rtrim($this->app->url('integrations/ebay/account-deletion'), '/');
    }

    public function challengeResponse(string $challengeCode): string
    {
        $token = $this->verificationToken();
        $endpoint = $this->endpointUrl();
        $hash = hash_init('sha256');
        hash_update($hash, $challengeCode);
        hash_update($hash, $token);
        hash_update($hash, $endpoint);
        return hash_final($hash);
    }

    /**
     * Redact PII for the notified eBay user across all tenants.
     *
     * @param array<string, mixed> $payload Full notification JSON
     * @return array{ok:bool, notification_id:string, orders:int, messages:int, customers:int, message:string}
     */
    public function processNotification(array $payload): array
    {
        $notification = is_array($payload['notification'] ?? null) ? $payload['notification'] : [];
        $data = is_array($notification['data'] ?? null) ? $notification['data'] : [];
        $notificationId = trim((string) ($notification['notificationId'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $userId = trim((string) ($data['userId'] ?? ''));
        $eias = trim((string) ($data['eiasToken'] ?? ''));

        if ($notificationId !== '') {
            $exists = $this->app->db->query(
                'SELECT id FROM #__store_ebay_account_deletions WHERE notification_id=? LIMIT 1',
                [$notificationId]
            )->row;
            if ($exists !== []) {
                return [
                    'ok'              => true,
                    'notification_id' => $notificationId,
                    'orders'          => 0,
                    'messages'        => 0,
                    'customers'       => 0,
                    'message'         => 'Already processed.',
                ];
            }
        }

        $orders = 0;
        $messages = 0;
        $customers = 0;

        if ($username !== '') {
            $orders += $this->redactOrdersByUsername($username);
            $messages += $this->redactMessagesByUsername($username);
        }

        // Also match orders where buyer_email was filled with the eBay username (legacy import).
        if ($username !== '') {
            $orders += $this->redactOrdersByEmail($username);
            $customers += $this->redactCustomersByEmail($username);
        }

        $this->app->db->query(
            'INSERT INTO #__store_ebay_account_deletions
             (notification_id, ebay_username, ebay_user_id, eias_token, orders_redacted, messages_redacted, customers_redacted, payload)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $notificationId !== '' ? $notificationId : ('local-' . bin2hex(random_bytes(8))),
                $username,
                $userId,
                mb_substr($eias, 0, 255),
                $orders,
                $messages,
                $customers,
                json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]
        );

        $this->logger()->info(
            sprintf(
                'eBay account deletion processed username=%s userId=%s orders=%d messages=%d customers=%d',
                $username,
                $userId,
                $orders,
                $messages,
                $customers
            ),
            [
                'notification_id' => $notificationId,
                'username'        => $username,
                'user_id'         => $userId,
                'orders'          => $orders,
                'messages'        => $messages,
                'customers'       => $customers,
            ],
            'ebay_account_deletion'
        );

        return [
            'ok'              => true,
            'notification_id' => $notificationId,
            'orders'          => $orders,
            'messages'        => $messages,
            'customers'       => $customers,
            'message'         => 'PII redacted.',
        ];
    }

    private function redactOrdersByUsername(string $username): int
    {
        $count = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_orders WHERE LOWER(buyer_username) = LOWER(?)',
            [$username]
        )->row['c'] ?? 0);
        if ($count <= 0) {
            return 0;
        }
        $this->app->db->query(
            "UPDATE #__store_orders
             SET buyer_username = '',
                 buyer_email = IF(buyer_email = ? OR buyer_email = '' OR buyer_email LIKE ?, 'redacted@deleted.local', buyer_email),
                 shipping_name = 'REDACTED',
                 shipping_address = JSON_OBJECT('line1','','city','','postcode','','country',''),
                 notes = '',
                 special_instructions = ''
             WHERE LOWER(buyer_username) = LOWER(?)",
            [$username, $username . '@%', $username]
        );
        return $count;
    }

    private function redactOrdersByEmail(string $username): int
    {
        $count = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_orders
             WHERE (buyer_email = ? OR LOWER(buyer_email) = LOWER(?) OR buyer_email = CONCAT(?, '@ebay.buyer'))
               AND shipping_name <> 'REDACTED'",
            [$username, $username, $username]
        )->row['c'] ?? 0);
        if ($count <= 0) {
            return 0;
        }
        $this->app->db->query(
            "UPDATE #__store_orders
             SET buyer_username = '',
                 buyer_email = 'redacted@deleted.local',
                 shipping_name = 'REDACTED',
                 shipping_address = JSON_OBJECT('line1','','city','','postcode','','country',''),
                 notes = '',
                 special_instructions = ''
             WHERE (buyer_email = ? OR LOWER(buyer_email) = LOWER(?) OR buyer_email = CONCAT(?, '@ebay.buyer'))
               AND shipping_name <> 'REDACTED'",
            [$username, $username, $username]
        );
        return $count;
    }

    private function redactMessagesByUsername(string $username): int
    {
        $count = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_buyer_messages WHERE LOWER(buyer_name) = LOWER(?)',
            [$username]
        )->row['c'] ?? 0);
        if ($count <= 0) {
            return 0;
        }
        $this->app->db->query(
            "UPDATE #__store_buyer_messages
             SET buyer_name = 'REDACTED',
                 buyer_email = '',
                 body = '[redacted - eBay account deletion]',
                 subject = IF(subject='', subject, 'REDACTED')
             WHERE LOWER(buyer_name) = LOWER(?)",
            [$username]
        );
        return $count;
    }

    private function redactCustomersByEmail(string $emailOrUsername): int
    {
        $count = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_customers
             WHERE email = ? OR LOWER(email) = LOWER(?) OR LOWER(name) = LOWER(?)",
            [$emailOrUsername, $emailOrUsername, $emailOrUsername]
        )->row['c'] ?? 0);
        if ($count <= 0) {
            return 0;
        }
        $this->app->db->query(
            "UPDATE #__store_customers
             SET name = 'REDACTED',
                 email = CONCAT('redacted+', id, '@deleted.local'),
                 phone = '',
                 postcode = ''
             WHERE email = ? OR LOWER(email) = LOWER(?) OR LOWER(name) = LOWER(?)",
            [$emailOrUsername, $emailOrUsername, $emailOrUsername]
        );
        return $count;
    }

    private function logger(): Logger
    {
        if ($this->app->logger instanceof Logger) {
            return $this->app->logger;
        }
        $handler = ErrorHandler::instance();
        if ($handler !== null) {
            return $handler->logger();
        }
        return new Logger($this->app->basePath);
    }
}
