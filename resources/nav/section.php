<?php

declare(strict_types=1);

return [
    'primary' => [
        ['key' => 'home', 'label' => 'Home', 'url' => 'admin', 'icon' => 'home'],
        ['key' => 'messages', 'label' => 'Messages', 'url' => 'admin/messages', 'icon' => 'messages'],
        ['key' => 'orders', 'label' => 'Orders', 'url' => 'admin/orders', 'icon' => 'orders'],
        ['key' => 'products', 'label' => 'Products', 'url' => 'admin/products', 'icon' => 'products'],
        ['key' => 'customers', 'label' => 'Customers', 'url' => 'admin/customers', 'icon' => 'customers'],
        ['key' => 'warehouse', 'label' => 'Warehouse', 'url' => 'admin/warehouse', 'icon' => 'warehouse'],
        ['key' => 'insights', 'label' => 'Insights', 'url' => 'admin/reports', 'icon' => 'insights'],
    ],
    'sections' => [
        'messages' => [
            ['label' => 'Messages', 'url' => 'admin/messages', 'query' => ['folder' => 'message']],
            ['label' => 'Mail inbox', 'url' => 'admin/messages', 'query' => ['folder' => 'mail']],
            ['label' => 'Reply templates', 'url' => 'admin/messages/templates'],
        ],
        'orders' => [
            ['label' => 'Manage orders', 'url' => 'admin/orders'],
            ['label' => 'Create order', 'url' => 'admin/orders/moto'],
            ['label' => 'CSV order import', 'url' => 'admin/orders/import'],
            ['label' => 'Order rules', 'url' => 'admin/orders/rules'],
            ['label' => 'Disputes overview', 'url' => 'admin/disputes'],
        ],
        'products' => [
            ['label' => 'Manage products', 'url' => 'admin/products'],
            ['label' => 'Purchase orders', 'url' => 'admin/purchase-orders'],
            ['label' => 'Product import export', 'url' => 'admin/products/import-export'],
            ['label' => 'Drop shipments', 'url' => 'admin/dropships'],
            ['label' => 'Bulk inventory update', 'url' => 'admin/inventory/bulk'],
            ['label' => 'Suppliers', 'url' => 'admin/suppliers'],
            ['label' => 'Product attribute manager', 'url' => 'admin/attributes'],
            ['label' => 'Category manager', 'url' => 'admin/categories'],
            ['label' => 'Channel listings', 'url' => 'admin/listings'],
            ['label' => 'Purchase order import', 'url' => 'admin/purchase-orders/import'],
        ],
        'customers' => [
            ['label' => 'Manage customers', 'url' => 'admin/customers'],
        ],
        'warehouse' => [
            ['label' => 'Picking', 'url' => 'admin/warehouse/picking'],
            ['label' => 'Pickwaves', 'url' => 'admin/warehouse/pickwaves'],
            ['label' => 'Despatch order / pickwave', 'url' => 'admin/warehouse/despatch'],
            ['label' => 'Deliveries', 'url' => 'admin/warehouse/deliveries'],
            ['label' => 'Manifests', 'url' => 'admin/warehouse/manifests'],
            ['label' => 'Courier file export', 'url' => 'admin/warehouse/courier-export'],
            ['label' => 'Stock locations', 'url' => 'admin/warehouse/stock-locations'],
            ['label' => 'Return order items', 'url' => 'admin/warehouse/returns'],
        ],
        'insights' => [
            ['label' => 'Insights', 'url' => 'admin/reports'],
            ['label' => 'Scheduled reports', 'url' => 'admin/reports/scheduled'],
            ['label' => 'Export history', 'url' => 'admin/reports/exports'],
        ],
        'settings' => [
            [
                'label' => 'Company',
                'items' => [
                    ['label' => 'Company', 'url' => 'admin/settings/company'],
                    ['label' => 'Users', 'url' => 'admin/settings/users'],
                ],
            ],
            [
                'label' => 'Integration Manager',
                'items' => [
                    ['label' => 'Channels', 'url' => 'admin/settings/channels'],
                    ['label' => 'Automatic sync', 'url' => 'admin/settings/sync'],
                ],
            ],
            [
                'label' => 'Shipping Configuration',
                'items' => [
                    ['label' => 'Overview', 'url' => 'admin/settings/shipping'],
                    ['label' => 'Shipping Methods', 'url' => 'admin/settings/shipping/methods'],
                    ['label' => 'Shipping Regions', 'url' => 'admin/settings/shipping/regions'],
                    ['label' => 'Shipping Rules', 'url' => 'admin/settings/shipping/rules'],
                    ['label' => 'Shipping Rules Tester', 'url' => 'admin/settings/shipping/tester'],
                    ['label' => 'Packaging sizes', 'url' => 'admin/settings/shipping/packaging'],
                ],
            ],
            [
                'label' => 'Security',
                'items' => [
                    ['label' => 'API Tokens', 'url' => 'admin/settings/api'],
                ],
            ],
            [
                'label' => 'Warehouse Management',
                'items' => [
                    ['label' => 'Warehouses', 'url' => 'admin/settings/warehouses'],
                ],
            ],
            [
                'label' => 'Misc',
                'items' => [
                    ['label' => 'Appearance', 'url' => 'admin/settings/appearance'],
                ],
            ],
        ],
    ],
];
