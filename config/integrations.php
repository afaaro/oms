<?php

declare(strict_types=1);

/**
 * Developer / carrier app credentials for every Integration Manager provider.
 * Fill via Platform → Marketplace Apps (writes storage/integrations.secrets.json).
 */
return [
    'ebay' => [
        'enabled' => true,
        'sandbox' => true,
        'client_id' => '',
        'client_secret' => '',
        'ru_name' => '',
        // Marketplace Account Deletion (Developer Portal → Notifications)
        'deletion_verification_token' => '',
        'deletion_endpoint' => '', // blank = auto from app URL
        'scopes' => [
            'https://api.ebay.com/oauth/api_scope',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment',
            'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
            'https://api.ebay.com/oauth/api_scope/sell.inventory',
            'https://api.ebay.com/oauth/api_scope/sell.inventory.readonly',
            'https://api.ebay.com/oauth/api_scope/commerce.identity.readonly',
            'https://api.ebay.com/oauth/api_scope/commerce.message',
        ],
    ],
    'amazon' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '',
        'application_id' => '',
        'seller_central' => 'sellercentral.amazon.co.uk',
        'redirect_path' => 'integrations/oauth/callback',
        'marketplace_id' => 'A1F83G8C2ARO7P',
        'region' => 'eu',
        'draft' => true,
    ],
    'amazon_buy_shipping' => [
        'enabled' => true,
        // Falls back to amazon.* when empty
        'client_id' => '',
        'client_secret' => '',
        'application_id' => '',
    ],
    'etsy' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '',
        'redirect_path' => 'integrations/oauth/callback',
        'scopes' => 'listings_r listings_w shops_r transactions_r transactions_w',
    ],
    'tiktok' => [
        'enabled' => true,
        'client_id' => '', // app_key
        'client_secret' => '', // app_secret
        'redirect_path' => 'integrations/oauth/callback',
    ],
    'temu' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '',
    ],
    'onbuy' => [
        'enabled' => true,
        'client_id' => '', // consumer key
        'client_secret' => '', // secret key
        'site_id' => '2000', // UK
    ],
    'bandq' => [
        'enabled' => true,
        'client_id' => '', // B&Q / Kingfisher marketplace API key
        'client_secret' => '',
        'supplier_id' => '',
    ],
    'shopify' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '',
        'scopes' => 'read_orders,write_orders,read_products,write_inventory,read_fulfillments,write_fulfillments',
        'redirect_path' => 'integrations/oauth/callback',
    ],
    'woocommerce' => [
        'enabled' => true,
        'client_id' => '', // consumer key
        'client_secret' => '',
    ],
    'magento' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '', // access token often stored as secret
    ],
    'bigcommerce' => [
        'enabled' => true,
        'client_id' => '',
        'client_secret' => '',
        'store_hash' => '',
    ],
    'royal_mail' => [
        'enabled' => true,
        'client_id' => '', // Pro Shipping / Click & Drop API Client ID
        'client_secret' => '', // API Client Secret
        'account_number' => '',
        'token_url' => 'https://authentication.proshipping.net/connect/token',
        'api_base' => 'https://api.proshipping.net',
    ],
    'parcelforce' => [
        'enabled' => true,
        'client_id' => '', // Contract / username
        'client_secret' => '', // password / API key
        'account_number' => '',
        'department_id' => '',
    ],
    'dpd' => [
        'enabled' => true,
        'client_id' => '', // username / geoSession user
        'client_secret' => '', // password
        'account_number' => '',
    ],
    'evri' => [
        'enabled' => true,
        'client_id' => '', // client id / hermes clientId
        'client_secret' => '', // client secret / API key
        'client_name' => '',
    ],
    'moto' => ['enabled' => true],
    'csv' => ['enabled' => true],
];
