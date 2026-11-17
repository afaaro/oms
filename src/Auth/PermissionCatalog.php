<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * StoreFeeder-shaped permission trees (actions + pages + mobile).
 */
final class PermissionCatalog
{
    /**
     * Action capabilities (business verbs).
     *
     * @return array<string, list<array{key:string,label:string}>>
     */
    public static function actionTree(): array
    {
        return [
            'Products' => [
                ['key' => 'products.supplier_cost.view', 'label' => 'Supplier cost — Allow view'],
                ['key' => 'products.supplier_name.view', 'label' => 'Supplier Name — Allow View'],
                ['key' => 'products.delete', 'label' => 'Allow delete'],
                ['key' => 'products.discontinue', 'label' => 'Allow discontinue / reintroduce'],
                ['key' => 'products.archive', 'label' => 'Allow archive / unarchive'],
                ['key' => 'products.export', 'label' => 'Allow export'],
            ],
            'Orders' => [
                ['key' => 'orders.delete', 'label' => 'Allow delete'],
                ['key' => 'orders.alter_shipping', 'label' => 'Allow alter shipping method'],
                ['key' => 'orders.cancel', 'label' => 'Allow cancel'],
                ['key' => 'orders.create', 'label' => 'Allow create'],
                ['key' => 'orders.despatch', 'label' => 'Allow despatch'],
                ['key' => 'orders.despatch_single', 'label' => 'Allow single despatch'],
                ['key' => 'orders.despatch_bulk', 'label' => 'Allow bulk despatch'],
                ['key' => 'orders.export', 'label' => 'Allow export'],
                ['key' => 'orders.return', 'label' => 'Allow return/exchange'],
                ['key' => 'orders.edit_lines', 'label' => 'Allow edit order lines'],
                ['key' => 'orders.override_status', 'label' => 'Allow override order status'],
                ['key' => 'orders.view_profit', 'label' => 'View profit'],
                ['key' => 'orders.mark_collected', 'label' => 'Allow mark as collected'],
            ],
            'Purchase Orders' => [
                ['key' => 'po.create', 'label' => 'Allow create'],
                ['key' => 'po.delete', 'label' => 'Allow delete'],
                ['key' => 'po.cancel', 'label' => 'Allow cancel'],
                ['key' => 'po.send', 'label' => 'Allow send'],
            ],
            'Listings' => [
                ['key' => 'listings.redownload', 'label' => 'Allow redownload / delete'],
                ['key' => 'listings.create', 'label' => 'Allow create'],
                ['key' => 'listings.edit', 'label' => 'View/Edit'],
                ['key' => 'listings.ebay_promotions', 'label' => 'eBay promotions'],
            ],
            'Reports' => [
                ['key' => 'reports.inventory', 'label' => 'Inventory and stock'],
                ['key' => 'reports.operational', 'label' => 'Operational insights'],
                ['key' => 'reports.sales', 'label' => 'Sales and revenue'],
                ['key' => 'reports.supplier', 'label' => 'Supplier management'],
            ],
            'Deliveries' => [
                ['key' => 'deliveries.manage', 'label' => 'Manage deliveries'],
            ],
            'Warehouse' => [
                ['key' => 'warehouse.stocktake_approvals', 'label' => 'Allow view stock take approvals'],
                ['key' => 'warehouse.reassign_picker', 'label' => 'Allow reassign picker'],
            ],
            'Dashboards' => [
                ['key' => 'dashboards.warehouse', 'label' => 'Warehouse'],
                ['key' => 'dashboards.picking', 'label' => 'Picking & packing'],
                ['key' => 'dashboards.product_health', 'label' => 'Product health'],
            ],
        ];
    }

    /**
     * Page / nav visibility keys.
     *
     * @return array<string, list<array{key:string,label:string}>>
     */
    public static function pageTree(): array
    {
        return [
            'Home' => [
                ['key' => 'page.home', 'label' => 'Dashboard'],
            ],
            'Orders' => [
                ['key' => 'page.orders', 'label' => 'Manage Orders'],
                ['key' => 'page.orders.moto', 'label' => 'MOTO (Phone Order Entry)'],
                ['key' => 'page.orders.epos', 'label' => 'EPOS Order Entry'],
                ['key' => 'page.orders.import', 'label' => 'CSV Order Import'],
                ['key' => 'page.disputes', 'label' => 'Disputes Overview'],
            ],
            'Products' => [
                ['key' => 'page.products', 'label' => 'Manage Products'],
                ['key' => 'page.purchase_orders', 'label' => 'Purchase Orders'],
                ['key' => 'page.products.import', 'label' => 'Product Import Export'],
                ['key' => 'page.drop_shipments', 'label' => 'Drop Shipments'],
                ['key' => 'page.inventory.bulk', 'label' => 'Bulk Inventory Update'],
                ['key' => 'page.suppliers', 'label' => 'Suppliers'],
                ['key' => 'page.attributes', 'label' => 'Product Attribute Manager'],
                ['key' => 'page.categories', 'label' => 'Category Manager'],
                ['key' => 'page.po.import', 'label' => 'Purchase Order Import'],
            ],
            'Customers' => [
                ['key' => 'page.customers', 'label' => 'Manage Customers'],
            ],
            'Warehouse' => [
                ['key' => 'page.picking', 'label' => 'Picking'],
                ['key' => 'page.despatch', 'label' => 'Despatch Order / Pickwave'],
                ['key' => 'page.deliveries', 'label' => 'Deliveries'],
                ['key' => 'page.deliveries.calendar', 'label' => 'Deliveries Calendar'],
                ['key' => 'page.manifests', 'label' => 'Manifests'],
                ['key' => 'page.courier_export', 'label' => 'Courier File Export'],
                ['key' => 'page.stock_locations', 'label' => 'Stock Location Overview'],
                ['key' => 'page.stocktake_approval', 'label' => 'Stock Take Approval'],
                ['key' => 'page.returns', 'label' => 'Return Order Items'],
            ],
            'Reports' => [
                ['key' => 'page.reports', 'label' => 'Reports hub'],
                ['key' => 'page.reports.insights', 'label' => 'Insights'],
            ],
            'Settings' => [
                ['key' => 'page.settings.users', 'label' => 'Users'],
                ['key' => 'page.settings.channels', 'label' => 'Integration Manager'],
                ['key' => 'page.settings.shipping', 'label' => 'Shipping Configuration'],
                ['key' => 'page.settings.warehouses', 'label' => 'Warehouse Management'],
            ],
        ];
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    public static function mobileTree(): array
    {
        return [
            ['key' => 'mobile.product_maintenance', 'label' => 'Product maintenance'],
            ['key' => 'mobile.pickwaves', 'label' => 'Pickwaves'],
            ['key' => 'mobile.deliveries', 'label' => 'Deliveries'],
            ['key' => 'mobile.orders', 'label' => 'Orders'],
            ['key' => 'mobile.stocktake', 'label' => 'Stock take'],
            ['key' => 'mobile.stocktake_approval', 'label' => 'Stock take approval'],
        ];
    }

    /** @return list<string> */
    public static function allActionKeys(): array
    {
        $keys = [];
        foreach (self::actionTree() as $items) {
            foreach ($items as $item) {
                $keys[] = $item['key'];
            }
        }
        return $keys;
    }

    /** @return list<string> */
    public static function allPageKeys(): array
    {
        $keys = [];
        foreach (self::pageTree() as $items) {
            foreach ($items as $item) {
                $keys[] = $item['key'];
            }
        }
        return $keys;
    }

    /** Map route path → page permission key */
    public static function pageKeyForRoute(string $route): ?string
    {
        $route = trim($route, '/');
        $map = [
            'admin' => 'page.home',
            'admin/orders' => 'page.orders',
            'admin/orders/moto' => 'page.orders.moto',
            'admin/orders/import' => 'page.orders.import',
            'admin/disputes' => 'page.disputes',
            'admin/products' => 'page.products',
            'admin/attributes' => 'page.attributes',
            'admin/categories' => 'page.categories',
            'admin/purchase-orders' => 'page.purchase_orders',
            'admin/customers' => 'page.customers',
            'admin/warehouse' => 'page.picking',
            'admin/warehouse/picking' => 'page.picking',
            'admin/warehouse/deliveries' => 'page.deliveries',
            'admin/reports' => 'page.reports',
            'admin/settings/users' => 'page.settings.users',
            'admin/settings/channels' => 'page.settings.channels',
            'admin/settings/warehouses' => 'page.settings.warehouses',
            'admin/settings/shipping' => 'page.settings.shipping',
        ];
        return $map[$route] ?? null;
    }
}
