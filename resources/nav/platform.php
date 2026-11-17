<?php

declare(strict_types=1);

return [
    'primary' => [
        ['key' => 'home', 'label' => 'Home', 'url' => 'platform'],
        ['key' => 'tenants', 'label' => 'Tenants', 'url' => 'platform/tenants'],
        ['key' => 'support', 'label' => 'Support', 'url' => 'platform/support'],
        ['key' => 'billing', 'label' => 'Billing', 'url' => 'platform/billing'],
        ['key' => 'marketplace-apps', 'label' => 'Marketplace Apps', 'url' => 'platform/marketplace-apps'],
        ['key' => 'health', 'label' => 'Health', 'url' => 'platform/health'],
        ['key' => 'database', 'label' => 'Database', 'url' => 'platform/database'],
        ['key' => 'logs', 'label' => 'Logs', 'url' => 'platform/logs'],
    ],
    'sections' => [
        'home' => [
            ['label' => 'Platform overview', 'url' => 'platform'],
            ['label' => 'Open tickets', 'url' => 'platform/support'],
            ['label' => 'Marketplace Apps', 'url' => 'platform/marketplace-apps'],
            ['label' => 'Health', 'url' => 'platform/health'],
            ['label' => 'Database / migrations', 'url' => 'platform/database'],
            ['label' => 'System logs', 'url' => 'platform/logs'],
        ],
        'marketplace-apps' => [
            ['label' => 'Marketplace Apps', 'url' => 'platform/marketplace-apps'],
            ['label' => 'System logs', 'url' => 'platform/logs'],
        ],
        'health' => [
            ['label' => 'Health checks', 'url' => 'platform/health'],
            ['label' => 'JSON report', 'url' => 'platform/health/json'],
            ['label' => 'Public ping', 'url' => 'health'],
            ['label' => 'Database / migrations', 'url' => 'platform/database'],
            ['label' => 'System logs', 'url' => 'platform/logs'],
        ],
        'tenants' => [
            ['label' => 'All tenants', 'url' => 'platform/tenants'],
            ['label' => 'New tenant', 'url' => 'platform/tenants/new'],
        ],
        'support' => [
            ['label' => 'Open queue', 'url' => 'platform/support'],
            ['label' => 'All tickets', 'url' => 'platform/support', 'query' => ['status' => 'all']],
            ['label' => 'New ticket', 'url' => 'platform/support/new'],
        ],
        'billing' => [
            ['label' => 'Plans & accounts', 'url' => 'platform/billing'],
        ],
        'database' => [
            ['label' => 'Migrations', 'url' => 'platform/database'],
        ],
        'logs' => [
            ['label' => 'System logs', 'url' => 'platform/logs'],
            ['label' => 'Database / migrations', 'url' => 'platform/database'],
        ],
    ],
];
