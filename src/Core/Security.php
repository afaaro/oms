<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Request hardening: HTTPS detection, security headers, CSRF helpers, auth rate limits.
 */
final class Security
{
    public function __construct(private App $app) {}

    public function isHttps(): bool
    {
        if (isset($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off' && $_SERVER['HTTPS'] !== '') {
            return true;
        }

        return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    }

    public function enforceHttps(): void
    {
        $force = (bool) $this->app->config->get('app.security.force_https', false);
        if (!$force || $this->isHttps()) {
            return;
        }
        $baseUrl = (string) $this->app->config->get('app.url', '');
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            throw new \RuntimeException('A valid app.url must be configured for HTTPS enforcement.');
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }

    public function sendHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Cross-Origin-Opener-Policy: same-origin');

        if ($this->isHttps()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }

        $csp = (string) $this->app->config->get(
            'app.security.csp',
            "default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.datatables.net; style-src 'self' 'unsafe-inline' https://cdn.datatables.net; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"
        );
        if ($csp !== '') {
            header('Content-Security-Policy: ' . $csp);
        }
    }

    /**
     * CSRF for browser/session mutations. Skipped when the request carries an API key/Bearer token
     * on /api/* routes (token auth is not cookie-CSRF vulnerable the same way).
     */
    public function assertCsrfIfRequired(string $path, string $method): void
    {
        $method = strtoupper($method);
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }

        // Public webhooks / marketplace callbacks (no browser session CSRF).
        $csrfExempt = [
            'integrations/ebay/account-deletion',
            'integrations/oauth/callback',
            'api/v1/auth/token',
            'cron/sync',
        ];
        if (in_array($path, $csrfExempt, true)) {
            return;
        }

        $isApi = str_starts_with($path, 'api/');
        $hasApiCredential = $this->app->request->bearerToken() !== '';
        // Bearer/API-key calls skip CSRF; password token issue is rate-limited instead
        if ($isApi && $hasApiCredential) {
            return;
        }

        $token = $this->app->request->csrfToken();
        if ($this->app->session->validateCsrf($token)) {
            return;
        }

        http_response_code(419);
        if ($isApi || $this->app->request->wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'error'   => 'CSRF token mismatch',
                'message' => 'Send a valid _token field or X-CSRF-TOKEN header.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $this->app->session->flash('error', 'Security check failed. Please reload and try again.');
        $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($referer !== '' && str_starts_with($referer, $this->app->baseUrl())) {
            header('Location: ' . $referer);
            exit;
        }
        $this->app->redirect((string) $this->app->config->get('app.default_home', 'admin/orders'));
    }

    /**
     * Simple IP+action rate limit (file-backed). Returns true when blocked.
     */
    public function tooManyAttempts(string $action, int $maxAttempts = 10, int $decaySeconds = 300): bool
    {
        $ip = preg_replace('/[^a-zA-Z0-9.:_-]/', '', $this->app->request->ip()) ?: 'unknown';
        $dir = $this->app->basePath . '/storage/rate_limit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $file = $dir . '/' . hash('sha256', $action . '|' . $ip) . '.json';
        $now = time();
        $data = ['count' => 0, 'start' => $now];
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        $start = (int) ($data['start'] ?? $now);
        $count = (int) ($data['count'] ?? 0);
        if ($now - $start > $decaySeconds) {
            $start = $now;
            $count = 0;
        }
        $count++;
        @file_put_contents($file, json_encode(['count' => $count, 'start' => $start], JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $count > $maxAttempts;
    }

    public function clearAttempts(string $action): void
    {
        $ip = preg_replace('/[^a-zA-Z0-9.:_-]/', '', $this->app->request->ip()) ?: 'unknown';
        $file = $this->app->basePath . '/storage/rate_limit/' . hash('sha256', $action . '|' . $ip) . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }
}
