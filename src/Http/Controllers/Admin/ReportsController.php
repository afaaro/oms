<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\OrderExportService;

final class ReportsController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->app->tenant->id();

        $byChannel = $this->app->db->query(
            "SELECT COALESCE(c.name, 'Unknown') AS channel_name, COUNT(*) AS order_count,
                    SUM(o.total) AS revenue
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id
             WHERE o.tenant_id = ?
             GROUP BY c.name
             ORDER BY order_count DESC",
            [$tenantId]
        )->rows;

        $byStatus = $this->app->db->query(
            "SELECT status, COUNT(*) AS c
             FROM #__store_orders
             WHERE tenant_id = ?
             GROUP BY status
             ORDER BY c DESC",
            [$tenantId]
        )->rows;

        $topProducts = $this->app->db->query(
            "SELECT oi.sku, oi.name, SUM(oi.qty) AS units, SUM(oi.line_total) AS revenue
             FROM #__store_order_items oi
             INNER JOIN #__store_orders o ON o.id = oi.order_id
             WHERE o.tenant_id = ?
             GROUP BY oi.sku, oi.name
             ORDER BY units DESC
             LIMIT 8",
            [$tenantId]
        )->rows;

        $despatchedToday = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_orders
             WHERE tenant_id = ? AND status = 'despatched'
               AND DATE(COALESCE(shipped_at, updated_at)) = CURDATE()",
            [$tenantId]
        )->row['c'] ?? 0);

        echo $this->view('admin/reports/index', [
            'title'           => 'Insights',
            'navSection'      => 'insights',
            'byChannel'       => $byChannel,
            'byStatus'        => $byStatus,
            'topProducts'     => $topProducts,
            'despatchedToday' => $despatchedToday,
            'user'            => $this->app->auth->user(),
        ]);
    }

    public function scheduled(): void
    {
        echo $this->view('admin/reports/scheduled', [
            'title'      => 'Scheduled reports',
            'navSection' => 'insights',
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function storeScheduled(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/reports/scheduled');
        }
        $name = trim((string) $this->app->request->post('name', ''));
        $schedule = trim((string) $this->app->request->post('schedule', 'daily'));
        if ($name !== '') {
            $this->app->db->query(
                'INSERT INTO #__store_report_jobs (tenant_id, name, type, schedule, status) VALUES (?, ?, ?, ?, ?)',
                [$this->app->tenant->id(), $name, 'scheduled', $schedule, 'queued']
            );
            $this->app->session->flash('success', 'Scheduled report created.');
        }
        $this->redirect('admin/reports/scheduled');
    }

    public function exports(): void
    {
        echo $this->view('admin/reports/exports', [
            'title'      => 'Export history',
            'navSection' => 'insights',
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function runExport(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/reports/exports');
        }
        $name = trim((string) $this->app->request->post('name', 'Orders export'));
        if ($name === '') {
            $name = 'Orders export';
        }
        $tenantId = $this->app->tenant->id();
        try {
            $path = (new OrderExportService($this->app))->write($tenantId);
        } catch (\Throwable $e) {
            $this->app->session->flash('error', 'Export failed: ' . $e->getMessage());
            $this->redirect('admin/reports/exports');
        }
        $this->app->db->query(
            "INSERT INTO #__store_report_jobs (tenant_id, name, type, schedule, status, file_path, completed_at)
             VALUES (?, ?, 'export', 'manual', 'completed', ?, NOW())",
            [$tenantId, $name, $path]
        );
        $this->app->session->flash('success', 'Orders export is ready to download.');
        $this->redirect('admin/reports/exports');
    }

    public function downloadExport(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $tenantId = $this->app->tenant->id();
        $job = $this->app->db->query(
            "SELECT * FROM #__store_report_jobs WHERE id=? AND tenant_id=? AND status='completed' LIMIT 1",
            [$id, $tenantId]
        )->row;
        $relative = (string) ($job['file_path'] ?? '');
        $file = $job === [] ? null : (new OrderExportService($this->app))->absolutePath($tenantId, $relative);
        if ($file === null) {
            $this->app->session->flash('error', 'Export file not found.');
            $this->redirect('admin/reports/exports');
        }
        $name = basename($file);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }
}
