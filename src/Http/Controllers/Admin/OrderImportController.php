<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class OrderImportController extends Controller
{
    public function index(): void
    {
        echo $this->view('admin/orders/import', [
            'title'      => 'CSV order import',
            'navSection' => 'orders',
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/orders/import');
        }

        $csv = trim((string) $this->app->request->post('csv', ''));
        if ($csv === '') {
            $this->app->session->flash('error', 'Paste CSV rows first.');
            $this->redirect('admin/orders/import');
        }

        $channel = $this->app->db->query(
            'SELECT id FROM #__store_channels WHERE tenant_id = ? AND active = 1 ORDER BY id LIMIT 1',
            [$this->app->tenant->id()]
        )->row;
        $channelId = (int) ($channel['id'] ?? 0);
        if ($channelId <= 0) {
            $this->app->session->flash('error', 'Create a channel before importing.');
            $this->redirect('admin/orders/import');
        }

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $imported = 0;
        foreach ($lines as $i => $line) {
            $line = trim($line);
            if ($line === '' || $i === 0 && stripos($line, 'external_order_id') !== false) {
                continue;
            }
            $parts = str_getcsv($line);
            if (count($parts) < 3) {
                continue;
            }
            [$ext, $email, $total] = [$parts[0], $parts[1], (float) $parts[2]];
            $method = $parts[3] ?? 'Royal Mail 48';
            $name = $parts[4] ?? $email;
            $this->app->db->query(
                'INSERT INTO #__store_orders
                 (tenant_id, channel_id, external_order_id, status, buyer_email, shipping_name, total,
                  currency, shipping_address, shipping_method, warehouse, is_paid)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [
                    $this->app->tenant->id(),
                    $channelId,
                    trim($ext),
                    'awaiting_despatch',
                    trim($email),
                    trim($name),
                    $total,
                    'GBP',
                    json_encode(['line1' => 'Imported', 'city' => '', 'postcode' => '', 'country' => 'GB']),
                    trim($method),
                    'Default',
                ]
            );
            $imported++;
        }

        $this->app->session->flash('success', "Imported {$imported} orders.");
        $this->redirect('admin/orders');
    }
}
