<?php

declare(strict_types=1);

use App\Core\Router;
use App\Http\Controllers\Admin\ApiTokensController;
use App\Http\Controllers\Admin\AttributesController;
use App\Http\Controllers\Admin\CatalogExtraController;
use App\Http\Controllers\Admin\ChannelsController;
use App\Http\Controllers\Admin\AppearanceController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\MarketplaceAppsController;
use App\Http\Controllers\Admin\MessagesController;
use App\Http\Controllers\Integrations\EbayAccountDeletionController;
use App\Http\Controllers\Integrations\OAuthController;
use App\Http\Controllers\Admin\CustomersController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DocumentsController;
use App\Http\Controllers\Admin\DisputesController;
use App\Http\Controllers\Admin\ListingsController;
use App\Http\Controllers\Admin\MotoController;
use App\Http\Controllers\Admin\OrderImportController;
use App\Http\Controllers\Admin\OrderRulesController;
use App\Http\Controllers\Admin\OrdersController;
use App\Http\Controllers\Admin\ReplyTemplatesController;
use App\Http\Controllers\Admin\ProductsController;
use App\Http\Controllers\Admin\PurchaseOrdersController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SettingsUsersController;
use App\Http\Controllers\Admin\ShippingController;
use App\Http\Controllers\Admin\SyncSettingsController;
use App\Http\Controllers\Admin\SuppliersController;
use App\Http\Controllers\Admin\WarehouseOpsController;
use App\Http\Controllers\Admin\WarehousesController;
use App\Http\Controllers\Api\DataTableController;
use App\Http\Controllers\Api\V1\AuthApiController;
use App\Http\Controllers\Api\V1\CatalogApiController;
use App\Http\Controllers\Api\V1\ChannelsApiController;
use App\Http\Controllers\Api\V1\CouriersApiController;
use App\Http\Controllers\Api\V1\CustomersApiController;
use App\Http\Controllers\Api\V1\DocsController as ApiDocsController;
use App\Http\Controllers\Api\V1\InsightsApiController;
use App\Http\Controllers\Api\V1\MessagesApiController;
use App\Http\Controllers\Api\V1\OrdersApiController;
use App\Http\Controllers\Api\V1\ShippingApiController;
use App\Http\Controllers\Api\V1\WarehouseApiController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Platform\BillingController as PlatformBillingController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\Platform\DatabaseController as PlatformDatabaseController;
use App\Http\Controllers\Platform\HealthController as PlatformHealthController;
use App\Http\Controllers\Platform\LogsController as PlatformLogsController;
use App\Http\Controllers\Platform\MarketplaceAppsController as PlatformMarketplaceAppsController;
use App\Http\Controllers\Platform\SupportController as PlatformSupportController;
use App\Http\Controllers\Platform\TenantsController as PlatformTenantsController;

return static function (Router $router): void {
    $router->match(['GET'], 'login', [LoginController::class, 'show'], false);
    $router->match(['POST'], 'login', [LoginController::class, 'attempt'], false);
    $router->post('logout', [LoginController::class, 'logout'], false);

    // Marketplace OAuth callback — state carries channel/tenant; session preferred but not required
    $router->get('integrations/oauth/callback', [OAuthController::class, 'callback'], false);

    // eBay Marketplace Account Deletion (public; GET challenge + POST notify)
    $router->match(['GET', 'POST'], 'integrations/ebay/account-deletion', [EbayAccountDeletionController::class, 'handle'], false);

    // Public uptime probe (no auth)
    $router->get('health', [HealthController::class, 'ping'], false);

    // Scheduled order and message fetch (token, no session)
    $router->match(['GET', 'POST'], 'cron/sync', [CronController::class, 'sync'], false);

    // Platform
    $router->get('platform', [PlatformDashboardController::class, 'index'], true);
    $router->get('platform/health', [PlatformHealthController::class, 'index'], true);
    $router->get('platform/health/json', [PlatformHealthController::class, 'json'], true);
    $router->get('platform/tenants', [PlatformTenantsController::class, 'index'], true);
    $router->get('platform/tenants/new', [PlatformTenantsController::class, 'create'], true);
    $router->post('platform/tenants/new', [PlatformTenantsController::class, 'store'], true);
    $router->get('platform/tenants/view', [PlatformTenantsController::class, 'show'], true);
    $router->post('platform/tenants/save', [PlatformTenantsController::class, 'save'], true);
    $router->post('platform/tenants/close', [PlatformTenantsController::class, 'close'], true);
    $router->post('platform/tenants/reopen', [PlatformTenantsController::class, 'reopen'], true);
    $router->post('platform/tenants/delete', [PlatformTenantsController::class, 'delete'], true);
    $router->get('platform/tenants/impersonate', [PlatformTenantsController::class, 'impersonate'], true);
    $router->get('platform/tenants/stop-impersonate', [PlatformTenantsController::class, 'stopImpersonate'], true);
    $router->get('platform/support', [PlatformSupportController::class, 'index'], true);
    $router->get('platform/support/new', [PlatformSupportController::class, 'create'], true);
    $router->post('platform/support/new', [PlatformSupportController::class, 'store'], true);
    $router->get('platform/support/view', [PlatformSupportController::class, 'show'], true);
    $router->post('platform/support/update', [PlatformSupportController::class, 'update'], true);
    $router->post('platform/support/reply', [PlatformSupportController::class, 'reply'], true);
    $router->get('platform/billing', [PlatformBillingController::class, 'index'], true);
    $router->get('platform/marketplace-apps', [PlatformMarketplaceAppsController::class, 'index'], true);
    $router->post('platform/marketplace-apps', [PlatformMarketplaceAppsController::class, 'save'], true);
    $router->get('platform/database', [PlatformDatabaseController::class, 'index'], true);
    $router->post('platform/database/migrate', [PlatformDatabaseController::class, 'migrate'], true);
    $router->get('platform/logs', [PlatformLogsController::class, 'index'], true);
    $router->post('platform/logs/test', [PlatformLogsController::class, 'test'], true);
    $router->post('platform/logs/delete', [PlatformLogsController::class, 'delete'], true);
    $router->post('platform/logs/clear', [PlatformLogsController::class, 'clear'], true);

    // Merchant admin — orders
    $router->get('admin', [DashboardController::class, 'index'], true);
    $router->get('admin/orders', [OrdersController::class, 'index'], true);
    $router->get('admin/orders/view', [OrdersController::class, 'show'], true);
    $router->post('admin/orders/status', [OrdersController::class, 'updateStatus'], true);
    $router->post('admin/orders/retry-fulfillment', [OrdersController::class, 'retryFulfillment'], true);
    $router->post('admin/orders/cancel', [OrdersController::class, 'cancelOnChannel'], true);
    $router->post('admin/orders/refund', [OrdersController::class, 'refundOnChannel'], true);
    $router->post('admin/orders/bulk', [OrdersController::class, 'bulk'], true);
    $router->get('admin/orders/moto', [MotoController::class, 'create'], true);
    $router->post('admin/orders/moto', [MotoController::class, 'store'], true);
    $router->get('admin/orders/import', [OrderImportController::class, 'index'], true);
    $router->post('admin/orders/import', [OrderImportController::class, 'store'], true);
    $router->get('admin/orders/rules', [OrderRulesController::class, 'index'], true);
    $router->post('admin/orders/rules', [OrderRulesController::class, 'store'], true);
    $router->post('admin/orders/rules/delete', [OrderRulesController::class, 'delete'], true);
    $router->get('admin/disputes', [DisputesController::class, 'index'], true);
    $router->get('admin/disputes/case', [DisputesController::class, 'showCase'], true);
    $router->post('admin/disputes/sync-cases', [DisputesController::class, 'syncCases'], true);
    $router->post('admin/disputes/case/reply', [DisputesController::class, 'replyCase'], true);
    $router->post('admin/disputes/case/refund', [DisputesController::class, 'refundCase'], true);
    $router->get('admin/messages', [MessagesController::class, 'index'], true);
    $router->get('admin/messages/view', [MessagesController::class, 'show'], true);
    $router->get('admin/messages/conversation', [MessagesController::class, 'conversation'], true);
    $router->post('admin/messages/sync', [MessagesController::class, 'sync'], true);
    $router->post('admin/messages/refresh', [MessagesController::class, 'refresh'], true);
    $router->post('admin/messages/reply', [MessagesController::class, 'reply'], true);
    $router->post('admin/messages/mail-reply', [MessagesController::class, 'mailReply'], true);
    $router->post('admin/messages/status', [MessagesController::class, 'updateStatus'], true);
    $router->post('admin/messages/refund', [MessagesController::class, 'refund'], true);
    $router->post('admin/messages/assign', [MessagesController::class, 'assignConversation'], true);
    $router->get('admin/messages/templates', [ReplyTemplatesController::class, 'index'], true);
    $router->post('admin/messages/templates', [ReplyTemplatesController::class, 'store'], true);
    $router->post('admin/messages/templates/delete', [ReplyTemplatesController::class, 'delete'], true);

    // Products / catalogue
    $router->get('admin/products', [ProductsController::class, 'index'], true);
    $router->get('admin/products/new', [ProductsController::class, 'create'], true);
    $router->post('admin/products/new', [ProductsController::class, 'store'], true);
    $router->get('admin/products/view', [ProductsController::class, 'show'], true);
    $router->get('admin/products/edit', [ProductsController::class, 'edit'], true);
    $router->post('admin/products/save', [ProductsController::class, 'save'], true);
    $router->post('admin/products/variant/link', [ProductsController::class, 'linkVariant'], true);
    $router->post('admin/products/variant/unlink', [ProductsController::class, 'unlinkVariant'], true);
    $router->post('admin/products/variant/new', [ProductsController::class, 'createVariant'], true);
    $router->post('admin/products/variant/attribute/attach', [ProductsController::class, 'attachVariantAttribute'], true);
    $router->post('admin/products/variant/attribute/detach', [ProductsController::class, 'detachVariantAttribute'], true);
    $router->get('admin/products/import-export', [ProductsController::class, 'importExport'], true);
    $router->get('admin/purchase-orders', [PurchaseOrdersController::class, 'index'], true);
    $router->get('admin/purchase-orders/new', [PurchaseOrdersController::class, 'create'], true);
    $router->post('admin/purchase-orders/new', [PurchaseOrdersController::class, 'store'], true);
    $router->get('admin/purchase-orders/view', [PurchaseOrdersController::class, 'show'], true);
    $router->post('admin/purchase-orders/save', [PurchaseOrdersController::class, 'save'], true);
    $router->post('admin/purchase-orders/receive', [PurchaseOrdersController::class, 'receive'], true);
    $router->get('admin/purchase-orders/import', [CatalogExtraController::class, 'purchaseOrderImport'], true);
    $router->post('admin/purchase-orders/import', [CatalogExtraController::class, 'purchaseOrderImportStore'], true);
    $router->get('admin/suppliers', [SuppliersController::class, 'index'], true);
    $router->get('admin/suppliers/new', [SuppliersController::class, 'create'], true);
    $router->post('admin/suppliers/new', [SuppliersController::class, 'store'], true);
    $router->get('admin/suppliers/edit', [SuppliersController::class, 'edit'], true);
    $router->post('admin/suppliers/save', [SuppliersController::class, 'save'], true);
    $router->post('admin/suppliers/delete', [SuppliersController::class, 'delete'], true);
    $router->post('admin/suppliers/template/save', [SuppliersController::class, 'saveTemplate'], true);
    $router->post('admin/suppliers/template/delete', [SuppliersController::class, 'deleteTemplate'], true);
    $router->get('admin/attributes', [AttributesController::class, 'index'], true);
    $router->get('admin/attributes/new', [AttributesController::class, 'create'], true);
    $router->post('admin/attributes/new', [AttributesController::class, 'store'], true);
    $router->get('admin/attributes/edit', [AttributesController::class, 'edit'], true);
    $router->post('admin/attributes/save', [AttributesController::class, 'save'], true);
    $router->post('admin/attributes/delete', [AttributesController::class, 'delete'], true);
    $router->post('admin/attributes/value/add', [AttributesController::class, 'addValue'], true);
    $router->post('admin/attributes/value/delete', [AttributesController::class, 'deleteValue'], true);
    $router->get('admin/categories', [CatalogExtraController::class, 'categories'], true);
    $router->post('admin/categories', [CatalogExtraController::class, 'storeCategory'], true);
    $router->get('admin/dropships', [CatalogExtraController::class, 'dropships'], true);
    $router->post('admin/dropships/status', [CatalogExtraController::class, 'dropshipStatus'], true);
    $router->get('admin/inventory/bulk', [CatalogExtraController::class, 'bulkInventory'], true);
    $router->post('admin/inventory/bulk', [CatalogExtraController::class, 'bulkInventoryStore'], true);

    // Customers
    $router->get('admin/customers', [CustomersController::class, 'index'], true);
    $router->get('admin/customers/view', [CustomersController::class, 'show'], true);
    $router->post('admin/customers/sync', [CustomersController::class, 'sync'], true);

    // Warehouse
    $router->get('admin/warehouse', [WarehouseOpsController::class, 'index'], true);
    $router->get('admin/warehouse/picking', [WarehouseOpsController::class, 'picking'], true);
    $router->get('admin/warehouse/pickwaves', [WarehouseOpsController::class, 'pickwaves'], true);
    $router->get('admin/warehouse/pickwaves/view', [WarehouseOpsController::class, 'pickwaveView'], true);
    $router->get('admin/warehouse/pickwaves/picklist', [WarehouseOpsController::class, 'pickwavePickList'], true);
    $router->get('admin/warehouse/pickwaves/packinglist', [WarehouseOpsController::class, 'pickwavePackingList'], true);
    $router->get('admin/warehouse/pickwaves/scan', [WarehouseOpsController::class, 'pickwaveScan'], true);
    $router->post('admin/warehouse/pickwaves/scan', [WarehouseOpsController::class, 'pickwaveScanConfirm'], true);
    $router->post('admin/warehouse/pickwaves/close', [WarehouseOpsController::class, 'pickwaveClose'], true);
    $router->post('admin/warehouse/pickwaves/remove-order', [WarehouseOpsController::class, 'pickwaveRemoveOrder'], true);
    $router->post('admin/warehouse/pickwaves/rename', [WarehouseOpsController::class, 'pickwaveRename'], true);
    $router->post('admin/warehouse/pickwaves/mark-printed', [WarehouseOpsController::class, 'pickwaveMarkPrinted'], true);
    $router->post('admin/warehouse/pickwaves/despatch', [WarehouseOpsController::class, 'pickwaveDespatch'], true);
    $router->post('admin/warehouse/pickwave', [WarehouseOpsController::class, 'pickwave'], true);
    $router->post('admin/warehouse/mark-printed', [WarehouseOpsController::class, 'markPrinted'], true);
    $router->get('admin/warehouse/despatch', [WarehouseOpsController::class, 'despatch'], true);
    $router->post('admin/warehouse/despatch', [WarehouseOpsController::class, 'markDespatched'], true);
    $router->get('admin/warehouse/deliveries', [WarehouseOpsController::class, 'deliveries'], true);
    $router->post('admin/warehouse/deliveries/retry-fulfillment', [WarehouseOpsController::class, 'retryFulfillment'], true);
    $router->post('admin/warehouse/deliveries/void', [WarehouseOpsController::class, 'voidShipment'], true);
    $router->get('admin/warehouse/manifests', [WarehouseOpsController::class, 'manifests'], true);
    $router->post('admin/warehouse/manifests/create', [WarehouseOpsController::class, 'createManifest'], true);
    $router->post('admin/warehouse/manifests/close', [WarehouseOpsController::class, 'closeManifest'], true);
    $router->get('admin/warehouse/courier-export', [WarehouseOpsController::class, 'courierExport'], true);
    $router->get('admin/warehouse/courier-export/download', [WarehouseOpsController::class, 'courierExportDownload'], true);

    $router->get('admin/documents/label', [DocumentsController::class, 'label'], true);
    $router->get('admin/documents/labels', [DocumentsController::class, 'labelsBatch'], true);
    $router->get('admin/documents/invoice', [DocumentsController::class, 'invoice'], true);
    $router->get('admin/documents/packing', [DocumentsController::class, 'packing'], true);
    $router->get('admin/documents/manifest', [DocumentsController::class, 'manifestPrint'], true);
    $router->get('admin/warehouse/stock-locations', [WarehouseOpsController::class, 'stockLocations'], true);
    $router->post('admin/warehouse/stock-locations', [WarehouseOpsController::class, 'storeLocation'], true);
    $router->get('admin/warehouse/returns', [WarehouseOpsController::class, 'returns'], true);
    $router->get('admin/warehouse/returns/new', [WarehouseOpsController::class, 'returnsNew'], true);
    $router->post('admin/warehouse/returns/create', [WarehouseOpsController::class, 'createReturns'], true);
    $router->post('admin/warehouse/returns/update', [WarehouseOpsController::class, 'updateReturn'], true);

    // Insights
    $router->get('admin/reports', [ReportsController::class, 'index'], true);
    $router->get('admin/reports/scheduled', [ReportsController::class, 'scheduled'], true);
    $router->post('admin/reports/scheduled', [ReportsController::class, 'storeScheduled'], true);
    $router->get('admin/reports/exports', [ReportsController::class, 'exports'], true);
    $router->post('admin/reports/exports', [ReportsController::class, 'runExport'], true);
    $router->get('admin/reports/exports/download', [ReportsController::class, 'downloadExport'], true);

    // Settings
    $router->get('admin/settings/users', [SettingsUsersController::class, 'index'], true);
    $router->get('admin/settings/users/new', [SettingsUsersController::class, 'create'], true);
    $router->post('admin/settings/users/new', [SettingsUsersController::class, 'store'], true);
    $router->get('admin/settings/users/edit', [SettingsUsersController::class, 'edit'], true);
    $router->post('admin/settings/users/save', [SettingsUsersController::class, 'save'], true);
    $router->get('admin/settings/users/clone', [SettingsUsersController::class, 'cloneUser'], true);
    $router->get('admin/settings/channels', [ChannelsController::class, 'index'], true);
    $router->get('admin/settings/channels/new', [ChannelsController::class, 'create'], true);
    $router->post('admin/settings/channels', [ChannelsController::class, 'store'], true);
    $router->get('admin/settings/channels/edit', [ChannelsController::class, 'edit'], true);
    $router->post('admin/settings/channels/save', [ChannelsController::class, 'save'], true);
    $router->post('admin/settings/channels/connect', [ChannelsController::class, 'connect'], true);
    $router->get('admin/settings/channels/oauth', [ChannelsController::class, 'oauthStart'], true);
    $router->post('admin/settings/channels/unlink', [ChannelsController::class, 'unlink'], true);
    $router->post('admin/settings/channels/test', [ChannelsController::class, 'testConnection'], true);
    $router->post('admin/settings/channels/sync', [ChannelsController::class, 'syncOrders'], true);
    $router->post('admin/settings/channels/listings', [ChannelsController::class, 'downloadListings'], true);
    $router->post('admin/settings/channels/toggle', [ChannelsController::class, 'toggle'], true);
    $router->get('admin/settings/marketplace-apps', [MarketplaceAppsController::class, 'index'], true);
    $router->post('admin/settings/marketplace-apps', [MarketplaceAppsController::class, 'save'], true);
    $router->get('admin/settings/warehouses', [WarehousesController::class, 'index'], true);
    $router->post('admin/settings/warehouses', [WarehousesController::class, 'store'], true);
    $router->get('admin/settings/shipping', [ShippingController::class, 'index'], true);
    $router->get('admin/settings/shipping/methods', [ShippingController::class, 'methods'], true);
    $router->post('admin/settings/shipping/methods', [ShippingController::class, 'storeMethod'], true);
    $router->get('admin/settings/shipping/packaging', [ShippingController::class, 'packaging'], true);
    $router->post('admin/settings/shipping/packaging', [ShippingController::class, 'storePackaging'], true);
    $router->get('admin/settings/shipping/regions', [ShippingController::class, 'regions'], true);
    $router->post('admin/settings/shipping/regions', [ShippingController::class, 'storeRegion'], true);
    $router->get('admin/settings/shipping/rules', [ShippingController::class, 'rules'], true);
    $router->post('admin/settings/shipping/rules', [ShippingController::class, 'storeRule'], true);
    $router->get('admin/settings/shipping/tester', [ShippingController::class, 'tester'], true);
    $router->get('admin/settings/company', [CompanyController::class, 'index'], true);
    $router->get('admin/settings/appearance', [AppearanceController::class, 'index'], true);
    $router->post('admin/settings/appearance', [AppearanceController::class, 'save'], true);
    $router->get('admin/settings/sync', [SyncSettingsController::class, 'index'], true);
    $router->post('admin/settings/sync', [SyncSettingsController::class, 'save'], true);
    $router->post('admin/settings/sync/run', [SyncSettingsController::class, 'runNow'], true);
    $router->post('admin/settings/sync/token', [SyncSettingsController::class, 'regenerateToken'], true);
    $router->get('admin/settings/api', [ApiTokensController::class, 'index'], true);
    $router->post('admin/settings/api', [ApiTokensController::class, 'store'], true);
    $router->post('admin/settings/api/revoke', [ApiTokensController::class, 'revoke'], true);

    // Listings
    $router->get('admin/listings', [ListingsController::class, 'index'], true);
    $router->get('admin/listings/map', [ListingsController::class, 'map'], true);
    $router->post('admin/listings/map', [ListingsController::class, 'saveMap'], true);
    $router->post('admin/listings/unmap', [ListingsController::class, 'unmap'], true);
    $router->post('admin/listings/create-product', [ListingsController::class, 'createProduct'], true);

    // REST API v1 (Bearer sf_… token or session)
    $router->get('api/v1', [ApiDocsController::class, 'index'], false);
    $router->post('api/v1/auth/token', [AuthApiController::class, 'token'], false);
    $router->get('api/v1/me', [ApiDocsController::class, 'me'], true);

    $router->get('api/v1/orders', [OrdersApiController::class, 'index'], true);
    $router->get('api/v1/orders/view', [OrdersApiController::class, 'show'], true);
    $router->post('api/v1/orders', [OrdersApiController::class, 'store'], true);
    $router->patch('api/v1/orders/status', [OrdersApiController::class, 'updateStatus'], true);
    $router->post('api/v1/orders/despatch', [OrdersApiController::class, 'despatch'], true);

    $router->get('api/v1/products', [CatalogApiController::class, 'products'], true);
    $router->get('api/v1/products/view', [CatalogApiController::class, 'productShow'], true);
    $router->post('api/v1/products', [CatalogApiController::class, 'productStore'], true);
    $router->patch('api/v1/products', [CatalogApiController::class, 'productUpdate'], true);
    $router->get('api/v1/inventory', [CatalogApiController::class, 'inventory'], true);
    $router->post('api/v1/inventory', [CatalogApiController::class, 'inventorySet'], true);
    $router->get('api/v1/listings', [CatalogApiController::class, 'listings'], true);
    $router->post('api/v1/listings/map', [CatalogApiController::class, 'listingMap'], true);
    $router->get('api/v1/categories', [CatalogApiController::class, 'categories'], true);
    $router->get('api/v1/suppliers', [CatalogApiController::class, 'suppliers'], true);
    $router->get('api/v1/purchase-orders', [CatalogApiController::class, 'purchaseOrders'], true);

    $router->get('api/v1/customers', [CustomersApiController::class, 'index'], true);
    $router->get('api/v1/customers/view', [CustomersApiController::class, 'show'], true);
    $router->post('api/v1/customers', [CustomersApiController::class, 'store'], true);
    $router->patch('api/v1/customers', [CustomersApiController::class, 'update'], true);

    $router->get('api/v1/channels', [ChannelsApiController::class, 'index'], true);
    $router->get('api/v1/channels/view', [ChannelsApiController::class, 'show'], true);
    $router->get('api/v1/channels/catalog', [ChannelsApiController::class, 'catalog'], true);
    $router->get('api/v1/channels/connectors', [ChannelsApiController::class, 'connectors'], true);
    $router->post('api/v1/channels/connect', [ChannelsApiController::class, 'connect'], true);
    $router->post('api/v1/channels/unlink', [ChannelsApiController::class, 'unlink'], true);
    $router->post('api/v1/channels/toggle', [ChannelsApiController::class, 'toggle'], true);
    $router->post('api/v1/channels/test', [ChannelsApiController::class, 'test'], true);
    $router->post('api/v1/channels/sync', [ChannelsApiController::class, 'sync'], true);
    $router->get('api/v1/couriers', [CouriersApiController::class, 'index'], true);
    $router->get('api/v1/couriers/royal-mail/auth', [CouriersApiController::class, 'royalMailAuthInfo'], true);
    $router->post('api/v1/couriers/royal-mail/auth', [CouriersApiController::class, 'royalMailAuth'], true);
    $router->post('api/v1/couriers/royal-mail/test', [CouriersApiController::class, 'royalMailTest'], true);
    $router->get('api/v1/couriers/evri/auth', [CouriersApiController::class, 'evriAuthInfo'], true);
    $router->post('api/v1/couriers/evri/auth', [CouriersApiController::class, 'evriAuth'], true);
    $router->post('api/v1/couriers/evri/test', [CouriersApiController::class, 'evriTest'], true);
    $router->get('api/v1/couriers/parcelforce/auth', [CouriersApiController::class, 'parcelforceAuthInfo'], true);
    $router->post('api/v1/couriers/parcelforce/auth', [CouriersApiController::class, 'parcelforceAuth'], true);
    $router->post('api/v1/couriers/parcelforce/test', [CouriersApiController::class, 'parcelforceTest'], true);
    $router->get('api/v1/couriers/dpd/auth', [CouriersApiController::class, 'dpdAuthInfo'], true);
    $router->post('api/v1/couriers/dpd/auth', [CouriersApiController::class, 'dpdAuth'], true);
    $router->post('api/v1/couriers/dpd/test', [CouriersApiController::class, 'dpdTest'], true);
    $router->get('api/v1/marketplaces', [ChannelsApiController::class, 'marketplaces'], true);

    $router->get('api/v1/shipping/methods', [ShippingApiController::class, 'methods'], true);
    $router->get('api/v1/shipping/regions', [ShippingApiController::class, 'regions'], true);
    $router->get('api/v1/shipping/rules', [ShippingApiController::class, 'rules'], true);
    $router->post('api/v1/shipping/test', [ShippingApiController::class, 'test'], true);

    $router->get('api/v1/warehouses', [WarehouseApiController::class, 'warehouses'], true);
    $router->get('api/v1/stock-locations', [WarehouseApiController::class, 'locations'], true);
    $router->post('api/v1/warehouse/pickwave', [WarehouseApiController::class, 'pickwaveCreate'], true);
    $router->get('api/v1/returns', [WarehouseApiController::class, 'returns'], true);
    $router->get('api/v1/returns/view', [WarehouseApiController::class, 'returnShow'], true);
    $router->patch('api/v1/returns/status', [WarehouseApiController::class, 'returnUpdateStatus'], true);
    $router->get('api/v1/dropships', [WarehouseApiController::class, 'dropships'], true);

    $router->get('api/v1/insights/dashboard', [InsightsApiController::class, 'dashboard'], true);
    $router->get('api/v1/insights/sales', [InsightsApiController::class, 'salesSummary'], true);

    $router->get('api/v1/messages', [MessagesApiController::class, 'index'], true);
    $router->get('api/v1/messages/view', [MessagesApiController::class, 'show'], true);
    $router->post('api/v1/messages/sync', [MessagesApiController::class, 'sync'], true);
    $router->patch('api/v1/messages/status', [MessagesApiController::class, 'updateStatus'], true);
    $router->get('api/v1/messages/conversations', [MessagesApiController::class, 'conversations'], true);
    $router->post('api/v1/messages/conversations/reply', [MessagesApiController::class, 'conversationReply'], true);
    $router->get('api/v1/messages/cases', [MessagesApiController::class, 'cases'], true);

    // DataTables JSON datasets
    $router->get('api/datatable/orders', [DataTableController::class, 'orders'], true);
    $router->get('api/datatable/products', [DataTableController::class, 'products'], true);
    $router->get('api/datatable/products/variants', [DataTableController::class, 'productVariants'], true);
    $router->get('api/datatable/customers', [DataTableController::class, 'customers'], true);
    $router->get('api/datatable/users', [DataTableController::class, 'users'], true);
    $router->get('api/datatable/channels', [DataTableController::class, 'channels'], true);
    $router->get('api/datatable/warehouses', [DataTableController::class, 'warehouses'], true);
    $router->get('api/datatable/tenants', [DataTableController::class, 'tenants'], true);
    $router->get('api/datatable/support', [DataTableController::class, 'support'], true);
    $router->get('api/datatable/suppliers', [DataTableController::class, 'suppliers'], true);
    $router->get('api/datatable/purchase-orders', [DataTableController::class, 'purchaseOrders'], true);
    $router->get('api/datatable/categories', [DataTableController::class, 'categories'], true);
    $router->get('api/datatable/attributes', [DataTableController::class, 'attributes'], true);
    $router->get('api/datatable/stock-locations', [DataTableController::class, 'stockLocations'], true);
    $router->get('api/datatable/returns', [DataTableController::class, 'returns'], true);
    $router->get('api/datatable/shipments', [DataTableController::class, 'shipments'], true);
    $router->get('api/datatable/dropships', [DataTableController::class, 'dropships'], true);
    $router->get('api/datatable/report-jobs', [DataTableController::class, 'reportJobs'], true);
    $router->get('api/datatable/shipping-methods', [DataTableController::class, 'shippingMethods'], true);
    $router->get('api/datatable/shipping-regions', [DataTableController::class, 'shippingRegions'], true);
    $router->get('api/datatable/shipping-rules', [DataTableController::class, 'shippingRules'], true);
    $router->get('api/datatable/listings', [DataTableController::class, 'listings'], true);
};
