<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $json = null;

    public function method(): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $override = strtoupper((string) ($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ''));
        if ($method === 'POST' && in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $override;
        }
        $postOverride = strtoupper((string) ($_POST['_method'] ?? ''));
        if ($method === 'POST' && in_array($postOverride, ['PUT', 'PATCH', 'DELETE'], true)) {
            return $postOverride;
        }
        return $method;
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }


    public function route(): string
    {
        // Query-string routing takes priority when explicitly supplied.
        $fromQuery = trim((string) ($_GET['route'] ?? ''), '/');

        if ($fromQuery !== '') {
            return $fromQuery;
        }

        // Use the original requested URL, not REDIRECT_URL.
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');

        $path = str_replace('\\', '/', $path);

        // Remove the application base directory if installed in a subdirectory.
        $scriptName = str_replace(
            '\\',
            '/',
            (string) ($_SERVER['SCRIPT_NAME'] ?? '')
        );

        $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

        if ($base === '.' || $base === '/') {
            $base = '';
        }

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        } elseif ($base !== '' && $path === $base) {
            $path = '/';
        }

        $path = trim($path, '/');

        // Remove front-controller prefixes if present.
        foreach (['index.php'] as $script) {
            if (strcasecmp($path, $script) === 0) {
                return '';
            }

            $prefix = $script . '/';

            if (strncasecmp($path, $prefix, strlen($prefix)) === 0) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }

        return trim($path, '/');
    }


    /**
     * Demo-compatible input lookup (system/library/request.php).
     * - get('code') / get('id', 0) — StoreFeeder style (key, default)
     * - get('code', 'string', '') — demo style (key, type, default)
     */
    public function get(string $key, mixed $typeOrDefault = null, mixed $default = null): mixed
    {
        return $this->readInput(
            $_GET[$key] ?? $_POST[$key] ?? ($this->json()[$key] ?? null),
            $typeOrDefault,
            $default,
            func_num_args()
        );
    }

    public function post(string $key, mixed $typeOrDefault = null, mixed $default = null): mixed
    {
        $json = $this->json();
        $value = array_key_exists($key, $json) ? $json[$key] : ($_POST[$key] ?? null);
        return $this->readInput($value, $typeOrDefault, $default, func_num_args());
    }

    public function input(string $key, mixed $typeOrDefault = null, mixed $default = null): mixed
    {
        $json = $this->json();
        $value = array_key_exists($key, $json)
            ? $json[$key]
            : ($_POST[$key] ?? $_GET[$key] ?? null);
        return $this->readInput($value, $typeOrDefault, $default, func_num_args());
    }

    /**
     * @param mixed $value
     * @param mixed $typeOrDefault
     * @param mixed $default
     */
    private function readInput(mixed $value, mixed $typeOrDefault, mixed $default, int $argCount): mixed
    {
        $types = ['string', 'int', 'float', 'bool', 'email', 'url', 'alpha', 'alnum', 'slug', 'raw'];
        if ($argCount >= 3 && is_string($typeOrDefault) && in_array($typeOrDefault, $types, true)) {
            return $this->cast($value, $typeOrDefault, $default);
        }
        if ($value === null || $value === '') {
            return $argCount >= 2 ? $typeOrDefault : null;
        }
        return $value;
    }

    private function cast(mixed $value, string $type, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $raw = is_string($value)
            ? str_replace("\0", '', mb_substr($value, 0, 65535))
            : $value;

        return match ($type) {
            'int'   => filter_var($raw, FILTER_VALIDATE_INT) !== false ? (int) $raw : $default,
            'float' => filter_var($raw, FILTER_VALIDATE_FLOAT) !== false ? (float) $raw : $default,
            'bool'  => filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
            'email' => filter_var($raw, FILTER_VALIDATE_EMAIL) !== false ? (string) $raw : $default,
            'url'   => filter_var($raw, FILTER_VALIDATE_URL) !== false ? (string) $raw : $default,
            'alpha' => preg_match('/^[a-zA-Z]+$/', (string) $raw) ? (string) $raw : $default,
            'alnum' => preg_match('/^[a-zA-Z0-9]+$/', (string) $raw) ? (string) $raw : $default,
            'slug'  => preg_match('/^[a-zA-Z0-9_-]+$/', (string) $raw) ? (string) $raw : $default,
            'raw'   => $raw,
            default => is_string($raw) ? trim(strip_tags($raw)) : $raw,
        };
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** @return array<string, mixed> */
    public function allPost(): array
    {
        $json = $this->json();
        return $json !== [] ? $json : $_POST;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            return $this->json = [];
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $this->json = [];
        }
        $decoded = json_decode($raw, true);
        return $this->json = is_array($decoded) ? $decoded : [];
    }

    /**
     * API credentials from Authorization Bearer or X-Api-Key only (never query string).
     */
    public function bearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        // Apache/CGI often strips Authorization — recover from apache_request_headers().
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }
        if (preg_match('/^\s*Bearer\s+(\S+)/i', $header, $m)) {
            return $m[1];
        }
        $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
        if ($key === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'X-Api-Key') === 0) {
                    $key = (string) $value;
                    break;
                }
            }
        }
        return trim($key);
    }

    public function csrfToken(): string
    {
        $fromPost = (string) $this->post('_token', '');
        if ($fromPost !== '') {
            return $fromPost;
        }
        $header = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if ($header !== '') {
            return $header;
        }
        $alt = (string) ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? '');
        return $alt;
    }

    public function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return true;
        }
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return $xhr === 'xmlhttprequest';
    }

    public function header(string $name, string $default = ''): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $hName => $value) {
                if (strcasecmp((string) $hName, $name) === 0) {
                    return (string) $value;
                }
            }
        }
        return $default;
    }
}
