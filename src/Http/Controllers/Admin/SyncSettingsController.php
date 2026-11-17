<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\SyncScheduler;

final class SyncSettingsController extends Controller
{
    public function index(): void
    {
        $scheduler = new SyncScheduler($this->app);
        $tenantId = $this->app->tenant->id();
        $token = $scheduler->cronToken();
        echo $this->view('admin/settings/sync', [
            'title'      => 'Automatic sync',
            'navSection' => 'settings',
            'user'       => $this->app->auth->user(),
            'settings'   => $scheduler->settingsFor($tenantId),
            'runs'       => $scheduler->runsFor($tenantId),
            'cronUrl'    => $this->app->url('cron/sync', ['token' => $token]),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/sync');
        }
        (new SyncScheduler($this->app))->save($this->app->tenant->id(), [
            'enabled'             => $this->app->request->post('enabled', ''),
            'interval_minutes'    => $this->app->request->post('interval_minutes', '10'),
            'order_days'          => $this->app->request->post('order_days', '2'),
            'sync_orders'         => $this->app->request->post('sync_orders', ''),
            'sync_mail'           => $this->app->request->post('sync_mail', ''),
            'sync_conversations'  => $this->app->request->post('sync_conversations', ''),
        ]);
        $this->app->session->flash('success', 'Automatic sync settings saved.');
        $this->redirect('admin/settings/sync');
    }

    public function runNow(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/sync');
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        $result = (new SyncScheduler($this->app))->run($this->app->tenant->id(), 'manual', true);
        if (!empty($result['locked'])) {
            $this->app->session->flash('error', 'A sync is already running.');
            $this->redirect('admin/settings/sync');
        }
        $row = $result['tenants'][0] ?? ['status' => 'error', 'summary' => 'No result.'];
        $msg = 'Sync ' . ($row['status'] ?? 'error') . ': ' . ($row['summary'] ?? '');
        $this->app->session->flash(($row['status'] ?? '') === 'ok' ? 'success' : 'error', $msg);
        $this->redirect('admin/settings/sync');
    }

    public function regenerateToken(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/settings/sync');
        }
        (new SyncScheduler($this->app))->cronToken(true);
        $this->app->session->flash('success', 'Cron token regenerated. Update the scheduled URL.');
        $this->redirect('admin/settings/sync');
    }
}
