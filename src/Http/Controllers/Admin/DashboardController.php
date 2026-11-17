<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->app->tenant->id();
        $user = $this->app->auth->user();
        $first = trim((string) ($user['display_name'] ?? ''));
        if ($first === '') {
            $first = (string) ($user['email'] ?? 'there');
        } else {
            $first = explode(' ', $first)[0];
        }

        $count = static function ($app, string $sql, array $params = []): int {
            return (int) ($app->db->query($sql, $params)->row['c'] ?? 0);
        };

        $ordersToday = $count(
            $this->app,
            'SELECT COUNT(*) AS c FROM #__store_orders
             WHERE tenant_id = ? AND DATE(created_at) = CURDATE()',
            [$tenantId]
        );
        $ready = $count(
            $this->app,
            "SELECT COUNT(*) AS c FROM #__store_orders o
             WHERE o.tenant_id = ? AND o.status IN ('awaiting_despatch','printed','processing')
               AND NOT EXISTS (
                 SELECT 1 FROM #__store_pickwave_orders po
                 WHERE po.order_id = o.id AND po.tenant_id = o.tenant_id
               )",
            [$tenantId]
        );
        $despatchedToday = $count(
            $this->app,
            "SELECT COUNT(*) AS c FROM #__store_orders
             WHERE tenant_id = ? AND status = 'despatched'
               AND DATE(COALESCE(shipped_at, updated_at)) = CURDATE()",
            [$tenantId]
        );
        $backOrders = $count(
            $this->app,
            "SELECT COUNT(*) AS c FROM #__store_orders
             WHERE tenant_id = ? AND status = 'on_hold'",
            [$tenantId]
        );
        $products = $count(
            $this->app,
            'SELECT COUNT(*) AS c FROM #__store_products WHERE tenant_id = ? AND active = 1',
            [$tenantId]
        );
        $priority = $count(
            $this->app,
            'SELECT COUNT(*) AS c FROM #__store_orders WHERE tenant_id = ? AND is_priority = 1
             AND status NOT IN (\'despatched\',\'cancelled\',\'refunded\')',
            [$tenantId]
        );

        echo $this->view('admin/dashboard', [
            'title'            => 'Home',
            'navSection'       => 'home',
            'firstName'        => $first,
            'ordersToday'      => $ordersToday,
            'readyToPick'      => $ready,
            'despatchedToday'  => $despatchedToday,
            'backOrders'       => $backOrders,
            'productsCount'    => $products,
            'priorityOrders'   => $priority,
            'user'             => $user,
        ]);
    }
}
