<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;

final class InsightsApiController extends ApiController
{
    public function dashboard(): void
    {
        if (!$this->requireScope('reports:read')) {
            return;
        }
        $tid = $this->tenantId();
        $ordersToday = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_orders WHERE tenant_id = ? AND DATE(created_at) = CURDATE()',
            [$tid]
        )->row['c'] ?? 0);
        $awaiting = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_orders WHERE tenant_id = ? AND status IN ('awaiting_despatch','printed','processing')",
            [$tid]
        )->row['c'] ?? 0);
        $despatchedToday = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_orders WHERE tenant_id = ? AND status = 'despatched' AND DATE(shipped_at) = CURDATE()",
            [$tid]
        )->row['c'] ?? 0);
        $products = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_products WHERE tenant_id = ? AND active = 1',
            [$tid]
        )->row['c'] ?? 0);
        $channels = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_channels WHERE tenant_id = ? AND active = 1',
            [$tid]
        )->row['c'] ?? 0);
        $returnsOpen = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_returns WHERE tenant_id = ? AND status NOT IN ('closed','refunded','rejected')",
            [$tid]
        )->row['c'] ?? 0);

        $this->ok([
            'orders_today' => $ordersToday,
            'awaiting_despatch' => $awaiting,
            'despatched_today' => $despatchedToday,
            'active_products' => $products,
            'active_channels' => $channels,
            'open_returns' => $returnsOpen,
        ]);
    }

    public function salesSummary(): void
    {
        if (!$this->requireScope('reports:read')) {
            return;
        }
        $days = max(1, min(90, (int) $this->app->request->get('days', 7)));
        $rows = $this->app->db->query(
            "SELECT DATE(created_at) AS day,
                    COUNT(*) AS orders,
                    COALESCE(SUM(total), 0) AS revenue
             FROM #__store_orders
             WHERE tenant_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY day ASC",
            [$this->tenantId(), $days]
        )->rows;
        $this->ok($rows, ['days' => $days]);
    }
}
