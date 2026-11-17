<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

final class BillingController extends PlatformController
{
    public function index(): void
    {
        $this->guardPlatform();
        $rows = $this->app->db->query(
            'SELECT id, name, plan, status, contact_email FROM #__store_tenants ORDER BY name'
        )->rows;

        $byPlan = ['standard' => 0, 'professional' => 0, 'enterprise' => 0];
        foreach ($rows as $r) {
            if (($r['status'] ?? '') !== 'active') {
                continue;
            }
            $plan = (string) ($r['plan'] ?? 'standard');
            if (!isset($byPlan[$plan])) {
                $byPlan[$plan] = 0;
            }
            $byPlan[$plan]++;
        }

        echo $this->platformView('platform/billing', [
            'title'      => 'Billing',
            'navSection' => 'billing',
            'rows'       => $rows,
            'byPlan'     => $byPlan,
        ]);
    }
}
