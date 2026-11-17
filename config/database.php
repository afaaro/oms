<?php

declare(strict_types=1);

/**
 * Dedicated StoreFeeder database (not the community demo DB).
 * On IONOS, set SF_DB_* env vars or edit the defaults below.
 */
return [
    'hostname' => getenv('SF_DB_HOST') ?: 'localhost',
    'port'     => (int) (getenv('SF_DB_PORT') ?: 3306),
    'username' => getenv('SF_DB_USER') ?: 'root',
    'password' => getenv('SF_DB_PASS') !== false ? (string) getenv('SF_DB_PASS') : '',
    'database' => getenv('SF_DB_NAME') ?: 'storefeeder',
    'charset'  => 'utf8mb4',
    'prefix'   => '',
];
