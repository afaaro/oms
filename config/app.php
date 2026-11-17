<?php

declare(strict_types=1);

$env = getenv('SF_ENV') ?: 'local';
$isProduction = $env === 'production';
$envValue = static function (string $name, string $default): string {
    $value = getenv($name);

    return $value !== false ? $value : $default;
};

return [
    'name'         => 'ORM',
    'env'          => $env,
    'debug'        => $envValue('SF_DEBUG', $isProduction ? '0' : '1') === '1',
    'url'          => $envValue('SF_URL', ''),
    'url_mode'     => $envValue('SF_URL_MODE', 'pretty'),
    'timezone'     => 'Europe/London',
    'default_home' => 'admin/orders',
    'login_route'  => 'login',
    'session'      => [
        'name'     => 'sf_session',
        'lifetime' => 1800,
    ],
    'security'     => [
        'force_https'   => $envValue('SF_FORCE_HTTPS', $isProduction ? '1' : '0') === '1',
        'cookie_secure' => $envValue('SF_COOKIE_SECURE', $isProduction ? '1' : '0') === '1',
        'https_only'          => true,
        'verify_ssl'          => true,
        'csp'                 => "default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.datatables.net; style-src 'self' 'unsafe-inline' https://cdn.datatables.net; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
        'login_max_attempts'  => 10,
        'login_decay_seconds' => 300,
    ],
];
