<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * Legacy admin URL — Marketplace Apps are global and live under Platform.
 */
final class MarketplaceAppsController extends Controller
{
    public function index(): void
    {
        if ($this->app->auth->isPlatform()) {
            $this->redirect('platform/marketplace-apps');
        }
        $this->app->session->flash(
            'error',
            'Marketplace Apps are global credentials managed under Platform. Ask StoreFeeder staff if an app needs configuring.'
        );
        $this->redirect('admin/settings/channels');
    }

    public function save(): void
    {
        $this->index();
    }
}
