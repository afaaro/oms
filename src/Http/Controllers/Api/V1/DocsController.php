<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;

final class DocsController extends ApiController
{
    public function index(): void
    {
        $base = $this->app->url('api/v1');
        $this->ok([
            'name'    => 'StoreFeeder OMS API',
            'version' => 'v1',
            'auth'    => [
                'header' => 'Authorization: Bearer sf_…',
                'alt'    => ['X-Api-Key: sf_…'],
                'note'   => 'Query-string tokens are disabled. Use HTTPS in production. Cookie/session API calls also require CSRF (_token or X-CSRF-TOKEN).',
                'issue'  => $base . '/auth/token (POST username, password, name?)',
            ],
            'endpoints' => [
                'GET  /health' => 'Public uptime ping (no auth; 200/503 JSON)',
                'GET  /platform/health' => 'Platform staff health UI',
                'GET  /platform/health/json' => 'Platform staff full health JSON',
                'GET  /api/v1' => 'This catalog',
                'POST /api/v1/auth/token' => 'Issue API token',
                'GET  /api/v1/me' => 'Current user + tenant',
                'GET  /api/v1/orders' => 'List orders (?status,&view,&q,&page)',
                'GET  /api/v1/orders/view?id=' => 'Order detail + items',
                'POST /api/v1/orders' => 'Create order',
                'PATCH /api/v1/orders/status' => 'Update order status',
                'POST /api/v1/orders/despatch' => 'Bulk despatch order_ids[]',
                'GET  /api/v1/products' => 'List products',
                'GET  /api/v1/products/view?id=' => 'Product + stock',
                'POST /api/v1/products' => 'Create product',
                'PATCH /api/v1/products' => 'Update product',
                'GET  /api/v1/customers' => 'List customers',
                'GET  /api/v1/customers/view?id=' => 'Customer + orders',
                'GET  /api/v1/channels' => 'List integrations (?type=marketplace|courier|webstore, ?provider=bandq|dpd|…)',
                'GET  /api/v1/channels/view?id=' => 'Channel detail',
                'GET  /api/v1/channels/catalog' => 'All providers (B&Q, Royal Mail, Evri, ParcelForce, DPD, …)',
                'GET  /api/v1/channels/connectors' => 'Connector status (oauth/credentials/configured)',
                'POST /api/v1/channels/connect' => 'Link channel (oauth URL or api_key/api_secret)',
                'POST /api/v1/channels/test' => 'Test channel connection',
                'POST /api/v1/channels/sync' => 'Import orders (?days=all|1-729; default all ≈ 2 years on eBay)',
                'POST /api/v1/channels/unlink' => 'Unlink channel',
                'POST /api/v1/channels/toggle' => 'Enable/disable channel',
                'GET  /api/v1/couriers' => 'List courier channels (Royal Mail, Evri, ParcelForce, DPD, …)',
                'GET  /api/v1/couriers/royal-mail/auth' => 'Royal Mail Auth API help',
                'POST /api/v1/couriers/royal-mail/auth' => 'Royal Mail Auth — access_token (client_credentials)',
                'POST /api/v1/couriers/royal-mail/test' => 'Royal Mail Auth + API probe',
                'GET  /api/v1/couriers/evri/auth' => 'Evri Auth API help',
                'POST /api/v1/couriers/evri/auth' => 'Evri Auth — access_token (client_credentials)',
                'POST /api/v1/couriers/evri/test' => 'Evri Auth + API probe',
                'GET  /api/v1/couriers/parcelforce/auth' => 'ParcelForce ExpressLink Auth help',
                'POST /api/v1/couriers/parcelforce/auth' => 'ParcelForce Auth — store ExpressLink username/password',
                'POST /api/v1/couriers/parcelforce/test' => 'ParcelForce credential check',
                'GET  /api/v1/couriers/dpd/auth' => 'DPD Auth API help',
                'POST /api/v1/couriers/dpd/auth' => 'DPD Auth — login geoSession',
                'POST /api/v1/couriers/dpd/test' => 'DPD Auth probe',
                'GET  /api/v1/marketplaces' => 'List marketplace channels (?provider=bandq)',
                'GET  /api/v1/inventory' => 'Inventory levels',
                'POST /api/v1/inventory' => 'Set qty by sku or product_id',
                'GET  /api/v1/listings' => 'Channel listings (?view=unmapped|mapped)',
                'POST /api/v1/listings/map' => 'Map listing to product',
                'GET  /api/v1/categories' => 'Categories',
                'GET  /api/v1/suppliers' => 'Suppliers',
                'GET  /api/v1/purchase-orders' => 'Purchase orders',
                'GET  /api/v1/warehouses' => 'Warehouses',
                'GET  /api/v1/stock-locations' => 'Stock locations',
                'GET  /api/v1/returns' => 'Returns',
                'GET  /api/v1/returns/view?id=' => 'Return detail',
                'PATCH /api/v1/returns/status' => 'Update return status',
                'GET  /api/v1/dropships' => 'Dropships',
                'GET  /api/v1/shipping/methods' => 'Shipping methods',
                'GET  /api/v1/shipping/regions' => 'Shipping regions',
                'GET  /api/v1/shipping/rules' => 'Shipping rules',
                'POST /api/v1/shipping/test' => 'Test shipping rules',
                'POST /api/v1/warehouse/pickwave' => 'Create pickwave (order_ids[])',
                'POST /api/v1/orders/despatch' => 'Despatch orders',
                'GET  /api/v1/insights/dashboard' => 'Dashboard snapshot',
                'GET  /api/v1/insights/sales' => 'Sales summary (?days=7)',
                'GET  /api/v1/messages' => 'Buyer messages from all integrations (?status,&provider,&channel_id)',
                'GET  /api/v1/messages/view?id=' => 'Message detail',
                'POST /api/v1/messages/sync' => 'Pull messages from linked marketplaces/webstores',
                'PATCH /api/v1/messages/status' => 'Update message status',
            ],
        ]);
    }

    public function me(): void
    {
        $user = $this->app->auth->user();
        $tenant = $this->app->tenant->tenant();
        $this->ok([
            'user' => [
                'id'           => (int) ($user['id'] ?? 0),
                'email'        => $user['email'] ?? null,
                'display_name' => $user['display_name'] ?? null,
                'is_platform'  => !empty($user['is_platform']),
            ],
            'tenant' => $tenant ? [
                'id'   => (int) $tenant['id'],
                'name' => $tenant['name'],
                'slug' => $tenant['slug'] ?? null,
                'plan' => $tenant['plan'] ?? null,
            ] : null,
        ]);
    }
}
