<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;

final class CatalogApiController extends ApiController
{
    public function products(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $tenantId = $this->tenantId();
        $params = [$tenantId];
        $where = 'p.tenant_id = ?';
        $q = trim((string) $this->app->request->get('q', ''));
        if ($q !== '') {
            $where .= ' AND (p.sku LIKE ? OR p.name LIKE ? OR p.brand LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $total = (int) ($this->app->db->query("SELECT COUNT(*) AS c FROM #__store_products p WHERE {$where}", $params)->row['c'] ?? 0);
        $rows = $this->app->db->query(
            "SELECT p.id, p.sku, p.name, p.brand, p.price, p.rrp, p.cost, p.active, p.category_id, p.barcode, p.weight_grams
             FROM #__store_products p WHERE {$where}
             ORDER BY p.sku ASC LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;
        $this->ok($rows, $this->paginationMeta($total));
    }

    public function productShow(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $id = (int) $this->app->request->get('id', 0);
        $product = $this->app->db->query(
            'SELECT * FROM #__store_products WHERE id = ? AND tenant_id = ? LIMIT 1',
            [$id, $this->tenantId()]
        )->row;
        if ($product === []) {
            $this->fail('Product not found', 404);
            return;
        }
        $stock = $this->app->db->query(
            'SELECT i.qty_on_hand, i.qty_allocated, l.code AS location_code, l.name AS location_name
             FROM #__store_inventory i
             LEFT JOIN #__store_stock_locations l ON l.id = i.location_id
             WHERE i.product_id = ? AND i.tenant_id = ?',
            [$id, $this->tenantId()]
        )->rows;
        $this->ok(['product' => $product, 'stock' => $stock]);
    }

    public function productStore(): void
    {
        if (!$this->requireScope('catalog:write')) {
            return;
        }
        $sku = trim((string) $this->app->request->input('sku', ''));
        $name = trim((string) $this->app->request->input('name', ''));
        if ($sku === '' || $name === '') {
            $this->fail('sku and name are required', 422);
            return;
        }
        $exists = $this->app->db->query('SELECT id FROM #__store_products WHERE sku = ? LIMIT 1', [$sku])->row;
        if ($exists !== []) {
            $this->fail('SKU already exists', 409);
            return;
        }

        $categoryId = (int) $this->app->request->input('category_id', 0);
        $categoryId = $categoryId > 0 ? $categoryId : null;

        if ($categoryId !== null) {
            $category = $this->app->db->query(
                'SELECT id FROM #__store_categories
                WHERE id = ? AND tenant_id = ?
                LIMIT 1',
                [$categoryId, $this->tenantId()]
            )->row;

            if ($category === []) {
                $this->fail('Category not found', 404);
                return;
            }
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__store_products
             (tenant_id, sku, name, brand, category_id, description, barcode, warehouse, price, rrp, cost, weight_grams, active, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->tenantId(),
                $sku,
                $name,
                trim((string) $this->app->request->input('brand', '')),
                $categoryId,
                trim((string) $this->app->request->input('description', '')),
                trim((string) $this->app->request->input('barcode', '')),
                'Default',
                (float) $this->app->request->input('price', 0),
                (float) $this->app->request->input('rrp', 0),
                (float) $this->app->request->input('cost', 0),
                (int) $this->app->request->input('weight_grams', 0),
                $this->app->request->input('active', 1) ? 1 : 0,
                '',
            ]
        );
        $this->ok(['id' => (int) ($ins->insert_id ?: 0), 'sku' => $sku], [], 201);
    }

    public function productUpdate(): void
    {
        if (!$this->requireScope('catalog:write')) {
            return;
        }
        $id = (int) $this->app->request->input('id', 0);
        if ($id <= 0) {
            $this->fail('id required', 422);
            return;
        }

        $categoryId = (int) $this->app->request->input('category_id', 0);
        $categoryId = $categoryId > 0 ? $categoryId : null;

        if ($categoryId !== null) {
            $category = $this->app->db->query(
                'SELECT id FROM #__store_categories
                WHERE id = ? AND tenant_id = ?
                LIMIT 1',
                [$categoryId, $this->tenantId()]
            )->row;

            if ($category === []) {
                $this->fail('Category not found', 404);
                return;
            }
        }

        $this->app->db->query(
            'UPDATE #__store_products
             SET name=?, brand=?, category_id=?, description=?, barcode=?, price=?, rrp=?, cost=?, weight_grams=?, active=?
             WHERE id=? AND tenant_id=?',
            [
                trim((string) $this->app->request->input('name', '')),
                trim((string) $this->app->request->input('brand', '')),
                $categoryId,
                trim((string) $this->app->request->input('description', '')),
                trim((string) $this->app->request->input('barcode', '')),
                (float) $this->app->request->input('price', 0),
                (float) $this->app->request->input('rrp', 0),
                (float) $this->app->request->input('cost', 0),
                (int) $this->app->request->input('weight_grams', 0),
                $this->app->request->input('active', 1) ? 1 : 0,
                $id,
                $this->tenantId(),
            ]
        );
        $this->ok(['id' => $id]);
    }

    public function inventory(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $params = [$this->tenantId()];
        $where = 'i.tenant_id = ?';
        $sku = trim((string) $this->app->request->get('sku', ''));
        if ($sku !== '') {
            $where .= ' AND p.sku = ?';
            $params[] = $sku;
        }
        $total = (int) ($this->app->db->query(
            "SELECT COUNT(*) AS c FROM #__store_inventory i
             LEFT JOIN #__store_products p ON p.id = i.product_id WHERE {$where}",
            $params
        )->row['c'] ?? 0);
        $rows = $this->app->db->query(
            "SELECT i.id, i.product_id, p.sku, p.name, i.qty_on_hand, i.qty_allocated, l.code AS location_code, i.updated_at
             FROM #__store_inventory i
             LEFT JOIN #__store_products p ON p.id = i.product_id
             LEFT JOIN #__store_stock_locations l ON l.id = i.location_id
             WHERE {$where}
             ORDER BY p.sku ASC
             LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;
        $this->ok($rows, $this->paginationMeta($total));
    }

    public function inventorySet(): void
    {
        if (!$this->requireScope('catalog:write')) {
            return;
        }

        $sku = trim((string) $this->app->request->input('sku', ''));
        $productId = (int) $this->app->request->input('product_id', 0);
        $locationId = (int) $this->app->request->input('location_id', 0);
        $qty = (int) $this->app->request->input(
            'qty_on_hand',
            $this->app->request->input('qty', 0)
        );

        $tenantId = $this->tenantId();

        if ($productId <= 0 && $sku !== '') {
            $p = $this->app->db->query(
                'SELECT id FROM #__store_products
                WHERE tenant_id = ? AND sku = ?
                LIMIT 1',
                [$tenantId, $sku]
            )->row;

            $productId = (int) ($p['id'] ?? 0);
        }

        if ($productId <= 0 || $locationId <= 0) {
            $this->fail('product_id (or sku) and location_id are required', 422);
            return;
        }

        $product = $this->app->db->query(
            'SELECT id FROM #__store_products
            WHERE id = ? AND tenant_id = ?
            LIMIT 1',
            [$productId, $tenantId]
        )->row;

        if ($product === []) {
            $this->fail('Product not found', 404);
            return;
        }

        $location = $this->app->db->query(
            'SELECT id FROM #__store_stock_locations
            WHERE id = ? AND tenant_id = ?
            LIMIT 1',
            [$locationId, $tenantId]
        )->row;

        if ($location === []) {
            $this->fail('Stock location not found', 404);
            return;
        }

        $inv = $this->app->db->query(
            'SELECT id FROM #__store_inventory
            WHERE tenant_id = ? AND product_id = ? AND location_id = ?
            LIMIT 1',
            [$tenantId, $productId, $locationId]
        )->row;

        if ($inv === []) {
            $this->app->db->query(
                'INSERT INTO #__store_inventory
                (tenant_id, product_id, location_id, qty_on_hand, updated_at)
                VALUES (?, ?, ?, ?, NOW())',
                [$tenantId, $productId, $locationId, $qty]
            );
        } else {
            $this->app->db->query(
                'UPDATE #__store_inventory
                SET qty_on_hand = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ?',
                [$qty, $inv['id'], $tenantId]
            );
        }

        $this->ok([
            'product_id' => $productId,
            'location_id' => $locationId,
            'qty_on_hand' => $qty,
        ]);
    }

    public function listings(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $params = [$this->tenantId()];
        $where = 'l.tenant_id = ?';
        $view = (string) $this->app->request->get('view', 'all');
        if ($view === 'unmapped') {
            $where .= ' AND l.mapped = 0';
        } elseif ($view === 'mapped') {
            $where .= ' AND l.mapped = 1';
        }
        $total = (int) ($this->app->db->query("SELECT COUNT(*) AS c FROM #__store_listings l WHERE {$where}", $params)->row['c'] ?? 0);
        $rows = $this->app->db->query(
            "SELECT l.*, c.name AS channel_name, p.sku AS product_sku
             FROM #__store_listings l
             LEFT JOIN #__store_channels c ON c.id = l.channel_id
             LEFT JOIN #__store_products p ON p.id = l.product_id
             WHERE {$where}
             ORDER BY l.id DESC
             LIMIT {$this->perPage()} OFFSET {$this->offset()}",
            $params
        )->rows;
        $this->ok($rows, $this->paginationMeta($total));
    }

    public function listingMap(): void
    {
        if (!$this->requireScope('catalog:write')) {
            return;
        }

        $id = (int) $this->app->request->input('id', 0);
        $productId = (int) $this->app->request->input('product_id', 0);
        $tenantId = $this->tenantId();

        if ($id <= 0 || $productId <= 0) {
            $this->fail('id and product_id required', 422);
            return;
        }

        $listing = $this->app->db->query(
            'SELECT id
            FROM #__store_listings
            WHERE id = ? AND tenant_id = ?
            LIMIT 1',
            [$id, $tenantId]
        )->row;

        if ($listing === []) {
            $this->fail('Listing not found', 404);
            return;
        }

        $product = $this->app->db->query(
            'SELECT id
            FROM #__store_products
            WHERE id = ? AND tenant_id = ?
            LIMIT 1',
            [$productId, $tenantId]
        )->row;

        if ($product === []) {
            $this->fail('Product not found', 404);
            return;
        }

        $this->app->db->query(
            'UPDATE #__store_listings
            SET product_id = ?, mapped = 1
            WHERE id = ? AND tenant_id = ?',
            [$productId, $id, $tenantId]
        );

        $this->ok([
            'id' => $id,
            'product_id' => $productId,
            'mapped' => true,
        ]);
    }

    public function categories(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT id, name, parent_id, active, created_at FROM #__store_categories WHERE tenant_id = ? ORDER BY name',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }

    public function suppliers(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT id, name, code, email, phone, active FROM #__store_suppliers WHERE tenant_id = ? ORDER BY name',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }

    public function purchaseOrders(): void
    {
        if (!$this->requireScope('catalog:read')) {
            return;
        }
        $rows = $this->app->db->query(
            'SELECT po.id, po.reference, po.status, po.total, po.expected_at, s.name AS supplier_name
             FROM #__store_purchase_orders po
             LEFT JOIN #__store_suppliers s ON s.id = po.supplier_id AND s.tenant_id = po.tenant_id
             WHERE po.tenant_id = ?
             ORDER BY po.id DESC
             LIMIT 100',
            [$this->tenantId()]
        )->rows;
        $this->ok($rows);
    }
}
