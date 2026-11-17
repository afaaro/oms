<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class ListingsController extends Controller
{
    public function index(): void
    {
        $tenantId = $this->app->tenant->id();
        echo $this->view('admin/products/listings', [
            'title'      => 'Channel listings',
            'navSection' => 'products',
            'view'       => (string) $this->app->request->get('view', 'all'),
            'unmapped'   => (int) ($this->app->db->query(
                'SELECT COUNT(*) AS c FROM #__store_listings WHERE tenant_id=? AND mapped=0',
                [$tenantId]
            )->row['c'] ?? 0),
            'mapped'     => (int) ($this->app->db->query(
                'SELECT COUNT(*) AS c FROM #__store_listings WHERE tenant_id=? AND mapped=1',
                [$tenantId]
            )->row['c'] ?? 0),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function map(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $listing = $this->app->db->query(
            'SELECT l.*, c.name AS channel_name
             FROM #__store_listings l
             LEFT JOIN #__store_channels c ON c.id = l.channel_id
             WHERE l.id = ? AND l.tenant_id = ?
             LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($listing === []) {
            http_response_code(404);
            echo 'Listing not found';
            return;
        }

        $products = $this->app->db->query(
            'SELECT id, sku, name FROM #__store_products WHERE tenant_id = ? AND active = 1 ORDER BY sku LIMIT 500',
            [$this->app->tenant->id()]
        )->rows;

        echo $this->view('admin/products/listing_map', [
            'title'      => 'Map listing',
            'navSection' => 'products',
            'listing'    => $listing,
            'products'   => $products,
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function saveMap(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/listings');
        }
        $id = (int) $this->app->request->post('id', 0);
        $productId = (int) $this->app->request->post('product_id', 0);
        if ($productId <= 0) {
            $this->app->session->flash('error', 'Select a product to map.');
            $this->redirect('admin/listings/map', ['id' => $id]);
        }

        $product = $this->app->db->query(
            'SELECT id, sku, name FROM #__store_products WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$productId, $this->app->tenant->id()]
        )->row;
        if ($product === []) {
            $this->app->session->flash('error', 'Product not found.');
            $this->redirect('admin/listings/map', ['id' => $id]);
        }

        $this->app->db->query(
            'UPDATE #__store_listings
             SET product_id = ?, mapped = 1, sku = COALESCE(NULLIF(sku,\'\'), ?), title = COALESCE(NULLIF(title,\'\'), ?)
             WHERE id = ? AND tenant_id = ?',
            [
                $productId,
                (string) $product['sku'],
                (string) $product['name'],
                $id,
                $this->app->tenant->id(),
            ]
        );
        $this->app->session->flash('success', 'Listing mapped to ' . $product['sku'] . '.');
        $this->redirect('admin/listings', ['view' => 'mapped']);
    }

    public function unmap(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/listings');
        }
        $id = (int) $this->app->request->post('id', 0);
        $this->app->db->query(
            'UPDATE #__store_listings SET product_id = NULL, mapped = 0 WHERE id = ? AND tenant_id = ?',
            [$id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', 'Listing unmapped.');
        $this->redirect('admin/listings', ['view' => 'unmapped']);
    }

    public function createProduct(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/listings');
        }
        $id = (int) $this->app->request->post('id', 0);
        $listing = $this->app->db->query(
            'SELECT * FROM #__store_listings WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        if ($listing === []) {
            $this->redirect('admin/listings');
        }

        $sku = trim((string) ($listing['sku'] ?: ('LIST-' . $id)));
        $exists = $this->app->db->query(
            'SELECT id FROM #__store_products WHERE tenant_id = ? AND sku = ? LIMIT 1',
            [$this->app->tenant->id(), $sku]
        )->row;
        if ($exists !== []) {
            $sku .= '-' . $id;
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__store_products
             (tenant_id, sku, name, brand, description, barcode, warehouse, price, rrp, cost, weight_grams, active, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)',
            [
                $this->app->tenant->id(),
                $sku,
                (string) ($listing['title'] ?: $sku),
                '',
                'Created from channel listing ' . (string) $listing['external_id'],
                '',
                'Default',
                (float) ($listing['price'] ?? 0),
                (float) ($listing['price'] ?? 0),
                0,
                0,
                '',
            ]
        );
        $productId = (int) ($ins->insert_id ?: 0);
        $this->app->db->query(
            'UPDATE #__store_listings SET product_id = ?, mapped = 1, sku = ? WHERE id = ? AND tenant_id = ?',
            [$productId, $sku, $id, $this->app->tenant->id()]
        );
        $this->app->session->flash('success', "Product {$sku} created and mapped.");
        $this->redirect('admin/products/view', ['id' => $productId]);
    }
}
