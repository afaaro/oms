<?php

declare(strict_types=1);

return [
    'name'         => 'StoreFeeder',
    'env'          => getenv('SF_ENV') ?: 'local',
    'debug'        => (getenv('SF_DEBUG') ?: '1') === '1',
    'url'          => getenv('SF_URL') ?: '',
    'url_mode'     => getenv('SF_URL_MODE') ?: 'pretty',
    'timezone'     => 'Europe/London',
    'default_home' => 'admin/orders',
    'login_route'  => 'login',
    'session'      => [
        'name'     => 'sf_session',
        'lifetime' => 1800,
    ],
    'security'     => [
        'force_https'         => (getenv('SF_FORCE_HTTPS') ?: '0') === '1',
        'cookie_secure'       => (getenv('SF_COOKIE_SECURE') ?: '0') === '1',
        'https_only'          => true,
        'verify_ssl'          => true,
        'csp'                 => "default-src 'self'; script-src 'self' 'unsafe-inline' https://code.jquery.com https://cdn.datatables.net; style-src 'self' 'unsafe-inline' https://cdn.datatables.net; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'",
        'login_max_attempts'  => 10,
        'login_decay_seconds' => 300,
    ],
];
