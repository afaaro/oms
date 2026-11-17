<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

final class DashboardController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();

        $tenants = (int) ($this->app->db->query('SELECT COUNT(*) AS c FROM #__store_tenants')->row['c'] ?? 0);
        $active = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_tenants WHERE status = 'active'"
        )->row['c'] ?? 0);
        $openTickets = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__support_tickets WHERE status IN ('open','pending')"
        )->row['c'] ?? 0);
        $recent = $this->app->db->query(
            "SELECT t.id, t.subject, t.status, t.priority, t.created_at, n.name AS tenant_name
             FROM #__support_tickets t
             INNER JOIN #__store_tenants n ON n.id = t.tenant_id
             ORDER BY t.id DESC
             LIMIT 8"
        )->rows;

        echo $this->platformView('platform/dashboard', [
            'title'       => 'Platform',
            'navSection'  => 'home',
            'tenants'     => $tenants,
            'active'      => $active,
            'openTickets' => $openTickets,
            'recent'      => $recent,
        ]);
    }
}
