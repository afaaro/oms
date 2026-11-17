<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

final class ProductsController extends Controller
{
    /** Always register variant routes (safe if routes.php is outdated on the host). */
    public static function registerRoutes(\App\Core\Router $router): void
    {
        $router->post('admin/products/variant/link', [self::class, 'linkVariant'], true);
        $router->post('admin/products/variant/unlink', [self::class, 'unlinkVariant'], true);
        $router->post('admin/products/variant/new', [self::class, 'createVariant'], true);
        $router->post('admin/products/variant/attribute/attach', [self::class, 'attachVariantAttribute'], true);
        $router->post('admin/products/variant/attribute/detach', [self::class, 'detachVariantAttribute'], true);
        $router->get('admin/products/variant/new', [self::class, 'createVariantGet'], true);
        $router->get('admin/products/variant/link', [self::class, 'createVariantGet'], true);
        $router->get('admin/products/variant/unlink', [self::class, 'createVariantGet'], true);
    }

    public function index(): void
    {
        echo $this->view('admin/products/index', [
            'title'      => 'Product Manager',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function create(): void
    {
        echo $this->view('admin/products/form', [
            'title'         => 'Create product',
            'navSection'    => 'products',
            'product'       => null,
            'categories'    => $this->categories(),
            'warehouses'    => $this->warehouses(),
            'brands'        => $this->brands(),
            'suppliers'     => $this->suppliers(),
            'attributeDefs' => $this->attributeDefs(),
            'productAttrs'  => [],
            'variantAttrDefs' => [],
            'variantAttrCandidates' => [],
            'inventoryQty'  => 0,
            'inventoryAllocated' => 0,
            'inventoryAvailable' => 0,
            'hasVariants'   => false,
            'variants'      => [],
            'linkCandidates'=> [],
            'listings'      => [],
            'channels'      => $this->channels(),
            'stockLocations'=> (new \App\Store\StockLocationService($this->app))->activeLocations(),
            'section'       => 'general',
            'error'         => $this->app->session->flash('error'),
            'flash'         => $this->app->session->flash('success'),
            'user'          => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products/new');
        }
        $data = $this->postedProduct(false);
        if ($data['sku'] === '' || $data['name'] === '') {
            $this->app->session->flash('error', 'SKU and name are required.');
            $this->redirect('admin/products/new');
        }
        $exists = $this->app->db->query(
            'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
            [$this->app->tenant->id(), $data['sku']]
        )->row;
        if ($exists !== []) {
            $this->app->session->flash('error', 'SKU already exists.');
            $this->redirect('admin/products/new');
        }

        $parentId = (int) $this->app->request->post('parent_id', 0);
        if ($parentId > 0 && $this->find($parentId) === null) {
            $parentId = 0;
        }

        $cols = array_keys($data);
        if ($parentId > 0) {
            $cols[] = 'parent_id';
        }
        $placeholders = implode(', ', array_fill(0, count($cols) + 1, '?'));
        $bind = array_merge([$this->app->tenant->id()], array_values($data));
        if ($parentId > 0) {
            $bind[] = $parentId;
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__store_products (tenant_id, ' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')',
            $bind
        );
        $id = (int) ($ins->insert_id ?: 0);
        if ($id > 0) {
            $this->setInventoryQty($id, (int) $this->app->request->post('inventory_qty', 0));
            (new \App\Store\StockLocationService($this->app))->ensureInventoryRow($this->app->tenant->id(), $id);
        }
        $this->app->session->flash('success', 'Product created.');
        $this->redirect('admin/products/edit', ['id' => $id]);
    }

    public function show(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $this->redirect('admin/products/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $product = $this->find($id);
        if ($product === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'products',
                'message'    => 'Product not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        $section = (string) $this->app->request->get('section', 'general');
        $allowed = ['general', 'description', 'images', 'shipping', 'listings', 'notes'];
        if (!in_array($section, $allowed, true)) {
            $section = 'general';
        }

        // Auto-link cut sizes (e.g. 3.7m draws from 1m) before rendering Inv.
        $rootId = (int) ($product['parent_id'] ?? 0) > 0
            ? (int) $product['parent_id']
            : $id;
        if ($this->variantCount($rootId) > 0) {
            $this->autoLinkInventorySources($rootId);
            $this->setInventoryQty($rootId, $this->variantsInventorySum($rootId));
            $product = $this->find($id) ?? $product;
        }

        $inventoryShared = (int) ($product['inventory_source_id'] ?? 0) > 0;
        $inventorySourceSku = '';
        if ($inventoryShared) {
            $src = $this->find((int) $product['inventory_source_id']);
            $inventorySourceSku = (string) ($src['sku'] ?? '');
        }
        $invQty = $this->inventoryQty($id);
        $invAlloc = $this->inventoryAllocated($id);

        echo $this->view('admin/products/form', [
            'title'         => (string) ($product['name'] ?? 'Product'),
            'navSection'    => 'products',
            'product'       => $product,
            'categories'    => $this->categories(),
            'warehouses'    => $this->warehouses(),
            'brands'        => $this->brands(),
            'suppliers'     => $this->suppliers((int) ($product['supplier_id'] ?? 0)),
            'attributeDefs' => $this->attributeDefs(),
            'productAttrs'  => $this->productAttributeMap($id),
            'variantAttrDefs' => $this->variantAttributeDefs($rootId),
            'variantAttrCandidates' => $this->variantAttributeCandidates($rootId),
            'inventoryQty'  => $invQty,
            'inventoryAllocated' => $invAlloc,
            'inventoryAvailable' => max(0, $invQty - $invAlloc),
            'inventoryShared' => $inventoryShared,
            'inventorySourceSku' => $inventorySourceSku,
            'hasVariants'   => $this->variantCount($id) > 0,
            'variants'      => $this->variantsFor($product),
            'linkCandidates'=> $this->linkCandidates($id),
            'listings'      => $this->listingsFor($id),
            'channels'      => $this->channels(),
            'stockLocations'=> (new \App\Store\StockLocationService($this->app))->activeLocations(),
            'section'       => $section,
            'error'         => $this->app->session->flash('error'),
            'flash'         => $this->app->session->flash('success'),
            'user'          => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products');
        }

        // Variant actions can post here when host routes.php is missing dedicated paths.
        $action = (string) $this->app->request->post('_action', '');
        if ($action === 'variant_new') {
            $this->createVariant();
            return;
        }
        if ($action === 'variant_link') {
            $this->linkVariant();
            return;
        }
        if ($action === 'variant_unlink') {
            $this->unlinkVariant();
            return;
        }
        if ($action === 'variant_attr_attach') {
            $this->attachVariantAttribute();
            return;
        }
        if ($action === 'variant_attr_detach') {
            $this->detachVariantAttribute();
            return;
        }

        $id = (int) $this->app->request->post('id', 0);
        if ($this->find($id) === null) {
            $this->app->session->flash('error', 'Product not found.');
            $this->redirect('admin/products');
        }

        $data = $this->postedProduct(true);
        if ($data['name'] === '') {
            $this->app->session->flash('error', 'Product name is required.');
            $this->redirect('admin/products/edit', ['id' => $id]);
        }

        $sets = [];
        $bind = [];
        foreach ($data as $col => $val) {
            $sets[] = "{$col}=?";
            $bind[] = $val;
        }
        $bind[] = $id;
        $bind[] = $this->app->tenant->id();
        $this->app->db->query(
            'UPDATE #__store_products SET ' . implode(', ', $sets) . ' WHERE id=? AND tenant_id=?',
            $bind
        );

        $this->propagateToVariants($id, $data);
        $this->saveVariantGrid($id);

        $product = $this->find($id);
        $parentId = (int) ($product['parent_id'] ?? 0);
        $variantCount = $this->variantCount($id);

        if ($parentId > 0) {
            // Editing a variant: only write stock if this SKU owns inventory.
            $sourceId = (int) ($product['inventory_source_id'] ?? 0);
            if ($sourceId <= 0 && $this->app->request->post('inventory_qty', null) !== null) {
                $this->setInventoryQty($id, (int) $this->app->request->post('inventory_qty', 0));
            }
            $this->syncParentInventoryFromVariants($parentId);
        } elseif ($variantCount > 0) {
            // Parent with variants: inventory is always the sum of variant stock.
            $this->syncParentInventoryFromVariants($id);
        } elseif ($this->app->request->post('inventory_qty', null) !== null) {
            $this->setInventoryQty($id, (int) $this->app->request->post('inventory_qty', 0));
        }

        (new \App\Store\StockLocationService($this->app))->ensureInventoryRow($this->app->tenant->id(), $id);

        $section = (string) $this->app->request->post('section', 'general');
        $this->app->session->flash('success', 'Product saved.');
        $this->redirect('admin/products/edit', ['id' => $id, 'section' => $section]);
    }

    public function linkVariant(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products');
        }
        $parentId = (int) $this->app->request->post('parent_id', 0);
        $childId = (int) $this->app->request->post('child_id', 0);
        $parent = $this->find($parentId);
        $child = $this->find($childId);
        if ($parent === null || $child === null || $parentId === $childId) {
            $this->app->session->flash('error', 'Could not link product.');
            $this->redirect('admin/products/edit', ['id' => $parentId ?: $childId]);
        }
        // Always attach under the root parent.
        $rootId = (int) ($parent['parent_id'] ?? 0) > 0 ? (int) $parent['parent_id'] : $parentId;
        if ((int) ($child['parent_id'] ?? 0) === $rootId) {
            $this->app->session->flash('success', 'Product is already linked.');
            $this->redirect('admin/products/edit', ['id' => $parentId]);
        }
        $this->app->db->query(
            'UPDATE #__store_products SET parent_id=? WHERE id=? AND tenant_id=?',
            [$rootId, $childId, $this->app->tenant->id()]
        );
        $this->syncParentInventoryFromVariants($rootId);
        $this->app->session->flash('success', 'Product linked as variant.');
        $this->redirect('admin/products/edit', ['id' => $parentId]);
    }

    public function unlinkVariant(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products');
        }
        $productId = (int) $this->app->request->post('product_id', 0);
        $returnId = (int) $this->app->request->post('return_id', $productId);
        $child = $this->find($productId);
        $oldParent = (int) ($child['parent_id'] ?? 0);
        $this->app->db->query(
            'UPDATE #__store_products SET parent_id=NULL WHERE id=? AND tenant_id=?',
            [$productId, $this->app->tenant->id()]
        );
        if ($oldParent > 0) {
            $this->syncParentInventoryFromVariants($oldParent);
        }
        $this->app->session->flash('success', 'Variant unlinked.');
        $this->redirect('admin/products/edit', ['id' => $returnId]);
    }

    /** GET fallback when a variant form URL is opened/refreshed directly. */
    public function createVariantGet(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        if ($id <= 0) {
            $id = (int) $this->app->request->get('parent_id', 0);
        }
        $this->app->session->flash('error', 'Use Add New Variant / Add Existing Product on the product page.');
        if ($id > 0 && $this->find($id) !== null) {
            $this->redirect('admin/products/edit', ['id' => $id]);
        }
        $this->redirect('admin/products');
    }

    public function createVariant(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products');
        }
        $parentId = (int) $this->app->request->post('parent_id', 0);
        $parent = $this->find($parentId);
        if ($parent === null) {
            $this->app->session->flash('error', 'Parent product not found.');
            $this->redirect('admin/products');
        }
        $rootId = (int) ($parent['parent_id'] ?? 0) > 0 ? (int) $parent['parent_id'] : $parentId;

        $sku = trim((string) $this->app->request->post('sku', ''));
        $name = trim((string) $this->app->request->post('name', ''));
        if ($sku === '' || $name === '') {
            $this->app->session->flash('error', 'Variant SKU and name are required.');
            $this->redirect('admin/products/edit', ['id' => $parentId]);
        }
        $exists = $this->app->db->query(
            'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
            [$this->app->tenant->id(), $sku]
        )->row;
        if ($exists !== []) {
            $this->app->session->flash('error', 'SKU already exists.');
            $this->redirect('admin/products/edit', ['id' => $parentId]);
        }

        $ins = $this->app->db->query(
            "INSERT INTO #__store_products
             (tenant_id, parent_id, sku, name, brand, category_id, description, barcode, upc, warehouse,
              price, rrp, cost, tax_class, price_includes_vat, weight_grams, length_mm, width_mm, height_mm,
              image, active, notes, supplier_sku, supplier_name, supplier_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, '', ?, ?, ?)",
            [
                $this->app->tenant->id(),
                $rootId,
                $sku,
                $name,
                (string) ($parent['brand'] ?? ''),
                $parent['category_id'] ?? null,
                (string) ($parent['description'] ?? ''),
                '',
                '',
                (string) ($parent['warehouse'] ?? 'Default'),
                (float) ($parent['price'] ?? 0),
                (float) ($parent['rrp'] ?? 0),
                (float) ($parent['cost'] ?? 0),
                (string) ($parent['tax_class'] ?? '20'),
                (int) ($parent['price_includes_vat'] ?? 0),
                (int) ($parent['weight_grams'] ?? 0),
                (int) ($parent['length_mm'] ?? 0),
                (int) ($parent['width_mm'] ?? 0),
                (int) ($parent['height_mm'] ?? 0),
                (string) ($parent['image'] ?? ''),
                (string) ($parent['supplier_sku'] ?? ''),
                (string) ($parent['supplier_name'] ?? ''),
                !empty($parent['supplier_id']) ? (int) $parent['supplier_id'] : null,
            ]
        );
        $id = (int) ($ins->insert_id ?: 0);
        // Link cut sizes to the base SKU; never absolute-set qty on the new row
        // (that would overwrite shared base stock once inventory_source_id is set).
        $this->syncParentInventoryFromVariants($rootId);
        $this->app->session->flash('success', 'Variant created.');
        $this->redirect('admin/products/edit', ['id' => $rootId, 'section' => 'general']);
    }

    public function attachVariantAttribute(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products');
        }
        $parentId = (int) $this->app->request->post('parent_id', 0);
        $attributeId = (int) $this->app->request->post('attribute_id', 0);
        $parent = $this->find($parentId);
        if ($parent === null) {
            $this->app->session->flash('error', 'Product not found.');
            $this->redirect('admin/products');
        }
        $rootId = (int) ($parent['parent_id'] ?? 0) > 0 ? (int) $parent['parent_id'] : $parentId;
        if ($attributeId <= 0) {
            $this->app->session->flash('error', 'Choose an attribute from the list first.');
            $this->redirect('admin/products/edit', ['id' => $rootId, 'section' => 'general']);
        }
        $attr = $this->app->db->query(
            'SELECT id, name FROM #__store_attributes WHERE id=? AND tenant_id=? AND active=1 LIMIT 1',
            [$attributeId, $this->app->tenant->id()]
        )->row;
        if ($attr === []) {
            $this->app->session->flash('error', 'Attribute not found.');
            $this->redirect('admin/products/edit', ['id' => $rootId]);
        }

        $exists = $this->app->db->query(
            'SELECT id FROM #__store_product_variant_attributes
             WHERE tenant_id=? AND product_id=? AND attribute_id=? LIMIT 1',
            [$this->app->tenant->id(), $rootId, $attributeId]
        )->row;
        if ($exists === []) {
            $sort = (int) ($this->app->db->query(
                'SELECT COALESCE(MAX(sort_order),0)+10 AS s FROM #__store_product_variant_attributes
                 WHERE tenant_id=? AND product_id=?',
                [$this->app->tenant->id(), $rootId]
            )->row['s'] ?? 10);
            $this->app->db->query(
                'INSERT INTO #__store_product_variant_attributes
                 (tenant_id, product_id, attribute_id, sort_order) VALUES (?, ?, ?, ?)',
                [$this->app->tenant->id(), $rootId, $attributeId, $sort]
            );
        }
        $this->app->session->flash('success', 'Variant attribute added.');
        $this->redirect('admin/products/edit', ['id' => $rootId, 'section' => 'general']);
    }

    public function detachVariantAttribute(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->redirect('admin/products');
        }
        $parentId = (int) $this->app->request->post('parent_id', 0);
        $attributeId = (int) $this->app->request->post('attribute_id', 0);
        $parent = $this->find($parentId);
        $rootId = $parent !== null && (int) ($parent['parent_id'] ?? 0) > 0
            ? (int) $parent['parent_id']
            : $parentId;
        if ($rootId > 0 && $attributeId > 0) {
            $this->app->db->query(
                'DELETE FROM #__store_product_variant_attributes
                 WHERE tenant_id=? AND product_id=? AND attribute_id=?',
                [$this->app->tenant->id(), $rootId, $attributeId]
            );
            // Mark initialized so Size is not auto-seeded again after remove.
            $this->app->db->query(
                'UPDATE #__store_products SET variant_attrs_initialized=1 WHERE id=? AND tenant_id=?',
                [$rootId, $this->app->tenant->id()]
            );
        }
        $this->app->session->flash('success', 'Variant attribute removed.');
        $this->redirect('admin/products/edit', ['id' => $rootId > 0 ? $rootId : $parentId, 'section' => 'general']);
    }

    public function importExport(): void
    {
        echo $this->view('admin/products/import_export', [
            'title'      => 'Product import export',
            'navSection' => 'products',
            'flash'      => $this->app->session->flash('success'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT * FROM #__store_products WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @param array<string,mixed> $product @return list<array<string,mixed>> */
    private function variantsFor(array $product): array
    {
        $id = (int) $product['id'];
        $parentId = (int) ($product['parent_id'] ?? 0);
        $rootId = $parentId > 0 ? $parentId : $id;
        $rows = $this->app->db->query(
            "SELECT p.id, p.sku, p.name, p.price, p.weight_grams, p.pick_bin, p.attr_size, p.attr_thickness,
                    p.length_mm, p.width_mm, p.height_mm, p.parent_id,
                    p.inventory_source_id, p.inventory_unit_factor,
                    src.sku AS inventory_source_sku,
                    COALESCE(own.qty_on_hand, 0) AS own_qty_on_hand,
                    COALESCE(src_inv.qty_on_hand, 0) AS source_qty_on_hand
             FROM #__store_products p
             LEFT JOIN #__store_inventory own ON own.product_id = p.id AND own.tenant_id = p.tenant_id
             LEFT JOIN #__store_products src ON src.id = p.inventory_source_id AND src.tenant_id = p.tenant_id
             LEFT JOIN #__store_inventory src_inv ON src_inv.product_id = src.id AND src_inv.tenant_id = p.tenant_id
             WHERE p.tenant_id=? AND (p.id=? OR p.parent_id=?)
             ORDER BY p.parent_id IS NULL DESC, p.sku ASC",
            [$this->app->tenant->id(), $rootId, $rootId]
        )->rows;

        $attrMaps = $this->productAttributeMapsForParent($rootId);

        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['id'] === $id) {
                continue;
            }
            $vid = (int) $r['id'];
            $sourceId = (int) ($r['inventory_source_id'] ?? 0);
            $factor = (float) ($r['inventory_unit_factor'] ?? 1);
            if ($factor <= 0) {
                $factor = 1.0;
            }
            if ($sourceId > 0) {
                $sourceQty = (int) ($r['source_qty_on_hand'] ?? 0);
                $r['qty_on_hand'] = (int) floor($sourceQty / $factor);
                $r['inventory_shared'] = true;
            } else {
                $r['qty_on_hand'] = (int) ($r['own_qty_on_hand'] ?? 0);
                $r['inventory_shared'] = false;
            }
            $r['attrs'] = $attrMaps[$vid] ?? [];
            // Legacy fallbacks when EAV empty.
            if (($r['attrs']['size'] ?? '') === '' && trim((string) ($r['attr_size'] ?? '')) !== '') {
                $r['attrs']['size'] = (string) $r['attr_size'];
            }
            if (($r['attrs']['thickness'] ?? '') === '' && trim((string) ($r['attr_thickness'] ?? '')) !== '') {
                $r['attrs']['thickness'] = (string) $r['attr_thickness'];
            }
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Active attributes with optional suggested values.
     *
     * @return list<array{id:int,name:string,code:string,show_on_variants:int,values:list<string>}>
     */
    private function attributeDefs(): array
    {
        $this->ensureDefaultAttributes();
        $tenantId = $this->app->tenant->id();
        $rows = $this->app->db->query(
            'SELECT id, name, code, show_on_variants
             FROM #__store_attributes
             WHERE tenant_id=? AND active=1
             ORDER BY sort_order ASC, name ASC',
            [$tenantId]
        )->rows;
        $out = [];
        $seenNames = [];
        foreach ($rows as $row) {
            $nameKey = strtolower(trim((string) $row['name']));
            if ($nameKey !== '' && isset($seenNames[$nameKey])) {
                continue; // hide duplicate names (e.g. Size / Size)
            }
            if ($nameKey !== '') {
                $seenNames[$nameKey] = true;
            }
            $aid = (int) $row['id'];
            $vals = $this->app->db->query(
                'SELECT `value` FROM #__store_attribute_values
                 WHERE tenant_id=? AND attribute_id=? AND active=1
                 ORDER BY sort_order ASC, `value` ASC',
                [$tenantId, $aid]
            )->rows;
            $out[] = [
                'id' => $aid,
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
                'show_on_variants' => (int) ($row['show_on_variants'] ?? 0),
                'values' => array_values(array_map(
                    static fn(array $v): string => (string) $v['value'],
                    $vals
                )),
            ];
        }
        return $out;
    }

    /**
     * Attribute columns attached to this parent product's variant grid.
     *
     * @return list<array{id:int,name:string,code:string,show_on_variants:int,values:list<string>}>
     */
    private function variantAttributeDefs(int $parentId): array
    {
        if ($parentId <= 0) {
            return [];
        }
        $this->ensureVariantAttributeColumns($parentId);
        $tenantId = $this->app->tenant->id();
        $rows = $this->app->db->query(
            'SELECT a.id, a.name, a.code, a.show_on_variants
             FROM #__store_product_variant_attributes pva
             INNER JOIN #__store_attributes a ON a.id = pva.attribute_id AND a.tenant_id = pva.tenant_id
             WHERE pva.tenant_id=? AND pva.product_id=? AND a.active=1
             ORDER BY pva.sort_order ASC, a.name ASC',
            [$tenantId, $parentId]
        )->rows;
        $out = [];
        foreach ($rows as $row) {
            $aid = (int) $row['id'];
            $vals = $this->app->db->query(
                'SELECT `value` FROM #__store_attribute_values
                 WHERE tenant_id=? AND attribute_id=? AND active=1
                 ORDER BY sort_order ASC, `value` ASC',
                [$tenantId, $aid]
            )->rows;
            $out[] = [
                'id' => $aid,
                'name' => (string) $row['name'],
                'code' => (string) $row['code'],
                'show_on_variants' => 1,
                'values' => array_values(array_map(
                    static fn(array $v): string => (string) $v['value'],
                    $vals
                )),
            ];
        }
        return $out;
    }

    /**
     * Attributes not yet on this product's variant grid (for "+ New Variant Attribute").
     *
     * @return list<array{id:int,name:string,code:string}>
     */
    private function variantAttributeCandidates(int $parentId): array
    {
        $all = $this->attributeDefs();
        if ($parentId <= 0) {
            return $all;
        }
        $attached = $this->app->db->query(
            'SELECT attribute_id FROM #__store_product_variant_attributes
             WHERE tenant_id=? AND product_id=?',
            [$this->app->tenant->id(), $parentId]
        )->rows;
        $have = [];
        foreach ($attached as $row) {
            $have[(int) $row['attribute_id']] = true;
        }
        return array_values(array_filter(
            $all,
            static fn(array $a): bool => !isset($have[(int) $a['id']])
        ));
    }

    /**
     * One-time seed of Size onto parents that have variants and have never
     * had variant-attribute columns configured. Does NOT re-attach after
     * the user removes a column.
     */
    private function ensureVariantAttributeColumns(int $parentId): void
    {
        if ($parentId <= 0 || $this->variantCount($parentId) <= 0) {
            return;
        }
        $tenantId = $this->app->tenant->id();

        // If this parent was already initialized (even with zero columns after
        // a remove), a marker row or any history means do not auto-add again.
        $initialized = $this->app->db->query(
            'SELECT id FROM #__store_products WHERE id=? AND tenant_id=? AND variant_attrs_initialized=1 LIMIT 1',
            [$parentId, $tenantId]
        )->row;
        if ($initialized !== []) {
            return;
        }

        $count = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_product_variant_attributes
             WHERE tenant_id=? AND product_id=?',
            [$tenantId, $parentId]
        )->row['c'] ?? 0);

        if ($count === 0) {
            $size = $this->app->db->query(
                "SELECT id FROM #__store_attributes
                 WHERE tenant_id=? AND active=1 AND (code='size' OR LOWER(name)='size')
                 ORDER BY CASE WHEN code='size' THEN 0 ELSE 1 END, id ASC LIMIT 1",
                [$tenantId]
            )->row;
            if ($size !== []) {
                $this->app->db->query(
                    'INSERT INTO #__store_product_variant_attributes
                     (tenant_id, product_id, attribute_id, sort_order) VALUES (?, ?, ?, 10)',
                    [$tenantId, $parentId, (int) $size['id']]
                );
            }
        }

        $this->app->db->query(
            'UPDATE #__store_products SET variant_attrs_initialized=1 WHERE id=? AND tenant_id=?',
            [$parentId, $tenantId]
        );
    }

    /** @return array<string,string> code => value_text */
    private function productAttributeMap(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }
        $rows = $this->app->db->query(
            'SELECT a.code, pa.value_text
             FROM #__store_product_attributes pa
             INNER JOIN #__store_attributes a ON a.id = pa.attribute_id AND a.tenant_id = pa.tenant_id
             WHERE pa.tenant_id=? AND pa.product_id=?',
            [$this->app->tenant->id(), $productId]
        )->rows;
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['code']] = (string) ($row['value_text'] ?? '');
        }
        return $map;
    }

    /**
     * @return array<int, array<string,string>> product_id => [code => value]
     */
    private function productAttributeMapsForParent(int $rootId): array
    {
        $rows = $this->app->db->query(
            'SELECT pa.product_id, a.code, pa.value_text
             FROM #__store_product_attributes pa
             INNER JOIN #__store_attributes a ON a.id = pa.attribute_id AND a.tenant_id = pa.tenant_id
             INNER JOIN #__store_products p ON p.id = pa.product_id AND p.tenant_id = pa.tenant_id
             WHERE pa.tenant_id=? AND (p.id=? OR p.parent_id=?)',
            [$this->app->tenant->id(), $rootId, $rootId]
        )->rows;
        $maps = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            $maps[$pid][(string) $row['code']] = (string) ($row['value_text'] ?? '');
        }
        return $maps;
    }

    private function saveProductAttributes(int $productId): void
    {
        if ($productId <= 0) {
            return;
        }
        $posted = $this->app->request->post('attr', []);
        if (!is_array($posted)) {
            $posted = [];
        }
        // Also accept legacy field names on non-general sections.
        if ($posted === []) {
            $size = trim((string) $this->app->request->post('attr_size', ''));
            $thickness = trim((string) $this->app->request->post('attr_thickness', ''));
            if ($size !== '' || $thickness !== '') {
                $posted = [];
                foreach ($this->attributeDefs() as $def) {
                    if ($def['code'] === 'size') {
                        $posted[(string) $def['id']] = $size;
                    }
                    if ($def['code'] === 'thickness') {
                        $posted[(string) $def['id']] = $thickness;
                    }
                }
            }
        }

        $tenantId = $this->app->tenant->id();
        $defs = $this->attributeDefs();
        $legacy = ['size' => '', 'thickness' => ''];

        foreach ($defs as $def) {
            $aid = (int) $def['id'];
            $code = (string) $def['code'];
            $text = trim((string) ($posted[$aid] ?? $posted[$code] ?? ''));
            if (isset($legacy[$code])) {
                $legacy[$code] = $text;
            }

            $existing = $this->app->db->query(
                'SELECT id FROM #__store_product_attributes
                 WHERE tenant_id=? AND product_id=? AND attribute_id=? LIMIT 1',
                [$tenantId, $productId, $aid]
            )->row;

            if ($text === '') {
                if ($existing !== []) {
                    $this->app->db->query(
                        'DELETE FROM #__store_product_attributes WHERE id=? AND tenant_id=?',
                        [(int) $existing['id'], $tenantId]
                    );
                }
                continue;
            }

            $valueId = null;
            foreach ($def['values'] as $preset) {
                if (strcasecmp($preset, $text) === 0) {
                    $hit = $this->app->db->query(
                        'SELECT id FROM #__store_attribute_values
                         WHERE tenant_id=? AND attribute_id=? AND value=? LIMIT 1',
                        [$tenantId, $aid, $preset]
                    )->row;
                    if ($hit !== []) {
                        $valueId = (int) $hit['id'];
                    }
                    break;
                }
            }

            if ($existing === []) {
                $this->app->db->query(
                    'INSERT INTO #__store_product_attributes
                     (tenant_id, product_id, attribute_id, attribute_value_id, value_text)
                     VALUES (?, ?, ?, ?, ?)',
                    [$tenantId, $productId, $aid, $valueId, $text]
                );
            } else {
                $this->app->db->query(
                    'UPDATE #__store_product_attributes
                     SET attribute_value_id=?, value_text=?
                     WHERE id=? AND tenant_id=?',
                    [$valueId, $text, (int) $existing['id'], $tenantId]
                );
            }
        }

        // Keep legacy columns in sync for older queries/exports.
        $this->app->db->query(
            'UPDATE #__store_products SET attr_size=?, attr_thickness=? WHERE id=? AND tenant_id=?',
            [$legacy['size'], $legacy['thickness'], $productId, $tenantId]
        );
    }

    private function ensureDefaultAttributes(): void
    {
        $tenantId = $this->app->tenant->id();
        foreach ([['Size', 'size', 10], ['Thickness', 'thickness', 20]] as [$name, $code, $sort]) {
            $exists = $this->app->db->query(
                'SELECT id FROM #__store_attributes WHERE tenant_id=? AND code=? LIMIT 1',
                [$tenantId, $code]
            )->row;
            if ($exists !== []) {
                continue;
            }
            $this->app->db->query(
                'INSERT INTO #__store_attributes
                 (tenant_id, name, code, sort_order, show_on_variants, active)
                 VALUES (?, ?, ?, ?, 1, 1)',
                [$tenantId, $name, $code, $sort]
            );
        }
    }

    /** @return list<array<string,mixed>> */
    private function linkCandidates(int $excludeId): array
    {
        return $this->app->db->query(
            "SELECT id, sku, name FROM #__store_products
             WHERE tenant_id=? AND id<>? AND parent_id IS NULL AND active=1
             ORDER BY sku ASC LIMIT 200",
            [$this->app->tenant->id(), $excludeId]
        )->rows;
    }

    private function inventoryQty(int $productId): int
    {
        if ($this->variantCount($productId) > 0) {
            return $this->variantsInventorySum($productId);
        }
        $product = $this->find($productId);
        if ($product !== null) {
            $sourceId = (int) ($product['inventory_source_id'] ?? 0);
            $factor = (float) ($product['inventory_unit_factor'] ?? 1);
            if ($factor <= 0) {
                $factor = 1.0;
            }
            if ($sourceId > 0) {
                $sourceQty = (int) ($this->app->db->query(
                    'SELECT COALESCE(SUM(qty_on_hand),0) AS c FROM #__store_inventory WHERE tenant_id=? AND product_id=?',
                    [$this->app->tenant->id(), $sourceId]
                )->row['c'] ?? 0);
                return (int) floor($sourceQty / $factor);
            }
        }
        return (int) ($this->app->db->query(
            'SELECT COALESCE(SUM(qty_on_hand),0) AS c FROM #__store_inventory WHERE tenant_id=? AND product_id=?',
            [$this->app->tenant->id(), $productId]
        )->row['c'] ?? 0);
    }

    private function inventoryAllocated(int $productId): int
    {
        if ($this->variantCount($productId) > 0) {
            return $this->variantsAllocatedSum($productId);
        }
        $product = $this->find($productId);
        if ($product !== null) {
            $sourceId = (int) ($product['inventory_source_id'] ?? 0);
            $factor = (float) ($product['inventory_unit_factor'] ?? 1);
            if ($factor <= 0) {
                $factor = 1.0;
            }
            if ($sourceId > 0) {
                $sourceAlloc = (int) ($this->app->db->query(
                    'SELECT COALESCE(SUM(qty_allocated),0) AS c FROM #__store_inventory WHERE tenant_id=? AND product_id=?',
                    [$this->app->tenant->id(), $sourceId]
                )->row['c'] ?? 0);
                return (int) floor($sourceAlloc / $factor);
            }
        }
        return (int) ($this->app->db->query(
            'SELECT COALESCE(SUM(qty_allocated),0) AS c FROM #__store_inventory WHERE tenant_id=? AND product_id=?',
            [$this->app->tenant->id(), $productId]
        )->row['c'] ?? 0);
    }

    private function variantsAllocatedSum(int $parentId): int
    {
        return (int) ($this->app->db->query(
            "SELECT COALESCE(SUM(i.qty_allocated), 0) AS c
             FROM #__store_products p
             LEFT JOIN #__store_inventory i ON i.product_id = p.id AND i.tenant_id = p.tenant_id
             WHERE p.tenant_id=? AND p.parent_id=?
               AND (p.inventory_source_id IS NULL OR p.inventory_source_id = 0)",
            [$this->app->tenant->id(), $parentId]
        )->row['c'] ?? 0);
    }

    private function variantCount(int $productId): int
    {
        return (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_products WHERE tenant_id=? AND parent_id=?',
            [$this->app->tenant->id(), $productId]
        )->row['c'] ?? 0);
    }

    private function variantsInventorySum(int $parentId): int
    {
        // Only count stock owners (not cut sizes that draw from a base SKU).
        return (int) ($this->app->db->query(
            "SELECT COALESCE(SUM(i.qty_on_hand), 0) AS c
             FROM #__store_products p
             LEFT JOIN #__store_inventory i ON i.product_id = p.id AND i.tenant_id = p.tenant_id
             WHERE p.tenant_id=? AND p.parent_id=?
               AND (p.inventory_source_id IS NULL OR p.inventory_source_id = 0)",
            [$this->app->tenant->id(), $parentId]
        )->row['c'] ?? 0);
    }

    private function setInventoryQty(int $productId, int $qty): void
    {
        if ($productId <= 0) {
            return;
        }
        $product = $this->find($productId);
        if ($product === null) {
            return;
        }
        // Shared cut sizes must never absolute-set the base SKU stock
        // (e.g. creating/saving a 7.4m variant with qty 0 was wiping the 1m row).
        if ((int) ($product['inventory_source_id'] ?? 0) > 0) {
            return;
        }

        $tenantId = $this->app->tenant->id();
        $qty = max(0, $qty);
        $invId = (new \App\Store\StockLocationService($this->app))->ensureInventoryRow($tenantId, $productId);
        if ($invId <= 0) {
            $this->app->db->query(
                'INSERT INTO #__store_inventory (tenant_id, product_id, qty_on_hand, qty_allocated) VALUES (?, ?, ?, 0)',
                [$tenantId, $productId, $qty]
            );
            return;
        }
        $this->app->db->query(
            'UPDATE #__store_inventory SET qty_on_hand=? WHERE id=? AND tenant_id=?',
            [$qty, $invId, $tenantId]
        );
    }

    /** Main SKU inventory = sum of stock-owning variant stock. */
    private function syncParentInventoryFromVariants(int $parentId): void
    {
        if ($parentId <= 0 || $this->find($parentId) === null) {
            return;
        }
        $this->autoLinkInventorySources($parentId);
        $this->setInventoryQty($parentId, $this->variantsInventorySum($parentId));
    }

    /**
     * Link pack/cut variants to the smallest base unit SKU:
     * - 8mm (3.7m x 1.37m) ← 8mm (1m x 1.37m)
     * - 8 x Gripper Sticks ← 1 x Gripper Stick
     */
    private function autoLinkInventorySources(int $parentId): void
    {
        $tenantId = $this->app->tenant->id();
        $siblings = $this->app->db->query(
            'SELECT id, sku, name FROM #__store_products WHERE tenant_id=? AND parent_id=? ORDER BY sku ASC',
            [$tenantId, $parentId]
        )->rows;
        if (count($siblings) < 2) {
            return;
        }

        /** @var array<string, list<array{id:int,units:float}>> $groups */
        $groups = [];
        foreach ($siblings as $row) {
            $parsed = $this->parseSharedInventoryUnit(
                (string) ($row['sku'] ?? ''),
                (string) ($row['name'] ?? '')
            );
            if ($parsed === null) {
                continue;
            }
            $groups[$parsed['group']][] = [
                'id' => (int) $row['id'],
                'units' => $parsed['units'],
            ];
        }

        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            usort($members, static fn(array $a, array $b): int => $a['units'] <=> $b['units']);
            $base = $members[0];
            $baseUnits = $base['units'] > 0 ? $base['units'] : 1.0;

            // Base owns its own stock.
            $this->app->db->query(
                'UPDATE #__store_products SET inventory_source_id=NULL, inventory_unit_factor=1 WHERE id=? AND tenant_id=?',
                [$base['id'], $tenantId]
            );

            for ($i = 1, $n = count($members); $i < $n; $i++) {
                $m = $members[$i];
                $factor = round($m['units'] / $baseUnits, 4);
                if ($factor <= 0) {
                    $factor = 1.0;
                }
                $this->app->db->query(
                    'UPDATE #__store_products SET inventory_source_id=?, inventory_unit_factor=? WHERE id=? AND tenant_id=?',
                    [$base['id'], $factor, $m['id'], $tenantId]
                );
                // Clear duplicate stock on sourced SKUs so only the base holds qty.
                $this->app->db->query(
                    'UPDATE #__store_inventory SET qty_on_hand=0 WHERE tenant_id=? AND product_id=?',
                    [$tenantId, $m['id']]
                );
            }
        }
    }

    /**
     * @return array{group:string,units:float}|null
     */
    private function parseSharedInventoryUnit(string $sku, string $name): ?array
    {
        $cut = $this->parseCutSku($sku);
        if ($cut !== null) {
            return [
                'group' => 'cut:' . strtolower($cut['thickness'] . '|' . number_format($cut['width'], 4, '.', '')),
                'units' => $cut['length'],
            ];
        }

        $pack = $this->parsePackCount($sku, $name);
        if ($pack !== null) {
            return $pack;
        }

        return null;
    }

    /** @return array{thickness:string,length:float,width:float}|null */
    private function parseCutSku(string $sku): ?array
    {
        $sku = trim($sku);
        if (!preg_match(
            '/^(\d+(?:\.\d+)?)\s*mm\s*\(\s*(\d+(?:\.\d+)?)\s*m?\s*[x×]\s*(\d+(?:\.\d+)?)\s*m?\s*\)$/i',
            $sku,
            $m
        )) {
            return null;
        }
        return [
            'thickness' => $m[1] . 'mm',
            'length' => (float) $m[2],
            'width' => (float) $m[3],
        ];
    }

    /**
     * Detect "N x Product" packs from SKU or name.
     * e.g. "8 x Gripper Sticks", "76cm 1 x Gripper Stick"
     *
     * @return array{group:string,units:float}|null
     */
    private function parsePackCount(string $sku, string $name): ?array
    {
        $candidates = [trim($name), trim($sku)];
        foreach ($candidates as $text) {
            if ($text === '') {
                continue;
            }
            // Prefer explicit "N x label" (allow leading size like "76cm ").
            if (!preg_match('~(?:^|[\s\-_./])(\d+(?:\.\d+)?)\s*[x×]\s+(.+)$~i', $text, $m)) {
                continue;
            }
            $units = (float) $m[1];
            if ($units <= 0) {
                continue;
            }
            $label = $this->normalizePackLabel((string) $m[2]);
            if ($label === '') {
                continue;
            }
            return [
                'group' => 'pack:' . $label,
                'units' => $units,
            ];
        }
        return null;
    }

    private function normalizePackLabel(string $label): string
    {
        $label = strtolower(trim($label));
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        // Singularize simple trailing "sticks" / "rods" so 1x and 8x group together.
        $label = preg_replace('/\b([a-z]+?)s\b/i', '$1', $label) ?? $label;
        return $label;
    }

    /** @return list<array<string,mixed>> */
    private function categories(): array
    {
        return $this->app->db->query(
            'SELECT id, name FROM #__store_categories WHERE tenant_id=? ORDER BY name',
            [$this->app->tenant->id()]
        )->rows;
    }

    /** @return list<array<string,mixed>> */
    private function warehouses(): array
    {
        return $this->app->db->query(
            'SELECT id, name, code FROM #__store_warehouses WHERE tenant_id=? AND active=1 ORDER BY is_default DESC, name',
            [$this->app->tenant->id()]
        )->rows;
    }

    /** @return list<string> */
    private function brands(): array
    {
        $rows = $this->app->db->query(
            "SELECT DISTINCT brand FROM #__store_products
             WHERE tenant_id=? AND brand<>'' ORDER BY brand ASC LIMIT 200",
            [$this->app->tenant->id()]
        )->rows;
        return array_values(array_map(static fn(array $r): string => (string) $r['brand'], $rows));
    }

    /** @return array<string, mixed> */
    private function postedProduct(bool $editing): array
    {
        $weightKg = (float) $this->app->request->post('weight_kg', 0);
        $weightGrams = (int) round($weightKg * 1000);
        if ($weightGrams <= 0) {
            $weightGrams = (int) $this->app->request->post('weight_grams', 0);
        }

        $supplierId = (int) $this->app->request->post('supplier_id', 0);
        $supplierName = trim((string) $this->app->request->post('supplier_name', ''));
        if ($supplierId > 0) {
            $sup = $this->app->db->query(
                'SELECT id, name FROM #__store_suppliers WHERE id=? AND tenant_id=? LIMIT 1',
                [$supplierId, $this->app->tenant->id()]
            )->row;
            if ($sup === []) {
                $supplierId = 0;
            } elseif ($supplierName === '') {
                $supplierName = (string) ($sup['name'] ?? '');
            }
        }

        $data = [
            'name'               => trim((string) $this->app->request->post('name', '')),
            'brand'              => trim((string) $this->app->request->post('brand', '')),
            'category_id'        => (int) $this->app->request->post('category_id', 0) ?: null,
            'description'        => trim((string) $this->app->request->post('description', '')),
            'barcode'            => trim((string) $this->app->request->post('barcode', '')),
            'upc'                => trim((string) $this->app->request->post('upc', '')),
            'warehouse'          => trim((string) $this->app->request->post('warehouse', 'Default')) ?: 'Default',
            'price'              => round((float) $this->app->request->post('price', 0), 2),
            'rrp'                => round((float) $this->app->request->post('rrp', 0), 2),
            'cost'               => round((float) $this->app->request->post('cost', 0), 2),
            'tax_class'          => trim((string) $this->app->request->post('tax_class', '20')) ?: '20',
            'price_includes_vat' => $this->app->request->post('price_includes_vat', '') !== '' ? 1 : 0,
            'weight_grams'       => max(0, $weightGrams),
            'length_mm'          => max(0, (int) $this->app->request->post('length_mm', 0)),
            'width_mm'           => max(0, (int) $this->app->request->post('width_mm', 0)),
            'height_mm'          => max(0, (int) $this->app->request->post('height_mm', 0)),
            'image'              => trim((string) $this->app->request->post('image', '')),
            'pick_bin'           => $this->normalizePickBin(trim((string) $this->app->request->post('pick_bin', ''))),
            'active'             => $this->app->request->post('active', '') !== '' ? 1 : 0,
            'notes'              => trim((string) $this->app->request->post('notes', '')),
            'supplier_id'        => $supplierId > 0 ? $supplierId : null,
            'supplier_sku'       => trim((string) $this->app->request->post('supplier_sku', '')),
            'supplier_name'      => $supplierName,
        ];

        if (!$editing) {
            $data = array_merge([
                'sku' => trim((string) $this->app->request->post('sku', '')),
            ], $data);
        }

        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function suppliers(int $includeId = 0): array
    {
        return $this->app->db->query(
            "SELECT id, name, code, currency, lead_time_days, min_order_value, active
             FROM #__store_suppliers
             WHERE tenant_id=? AND (active=1 OR id=?)
             ORDER BY name ASC",
            [$this->app->tenant->id(), $includeId]
        )->rows;
    }

    /** @param array<string,mixed> $data */
    private function propagateToVariants(int $productId, array $data): void
    {
        $updateAll = $this->app->request->post('update_all_variants', '') !== '';
        $map = [
            'price'       => 'update_variants_price',
            'rrp'         => 'update_variants_rrp',
            'tax_class'   => 'update_variants_tax',
            'brand'       => 'update_variants_brand',
            'length_mm'   => 'update_variants_dims',
            'width_mm'    => 'update_variants_dims',
            'height_mm'   => 'update_variants_dims',
            'weight_grams'=> 'update_variants_weight',
            'price_includes_vat' => 'update_variants_vat',
            'warehouse'   => 'update_variants_warehouse',
            'name'        => 'update_all_variants',
        ];

        $sets = [];
        $bind = [];
        foreach ($map as $col => $flag) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            if ($updateAll || $this->app->request->post($flag, '') !== '') {
                $sets[] = "{$col}=?";
                $bind[] = $data[$col];
            }
        }
        if ($sets === []) {
            return;
        }
        $bind[] = $productId;
        $bind[] = $this->app->tenant->id();
        $this->app->db->query(
            'UPDATE #__store_products SET ' . implode(', ', $sets) . ' WHERE parent_id=? AND tenant_id=?',
            $bind
        );
    }

    private function saveVariantGrid(int $parentOrAnyId): void
    {
        $prices = $this->app->request->post('variant_price', []);
        $weights = $this->app->request->post('variant_weight_kg', []);
        $bins = $this->app->request->post('variant_pick_bin', []);
        $variantAttrs = $this->app->request->post('variant_attr', []);
        $legacySizes = $this->app->request->post('variant_attr_size', []);
        $legacyThicknesses = $this->app->request->post('variant_attr_thickness', []);
        $qtys = $this->app->request->post('variant_qty', []);
        if (!is_array($prices)) {
            return;
        }
        if (!is_array($variantAttrs)) {
            $variantAttrs = [];
        }

        $parent = $this->find($parentOrAnyId);
        if ($parent === null) {
            return;
        }
        $rootId = (int) ($parent['parent_id'] ?? 0) > 0
            ? (int) $parent['parent_id']
            : $parentOrAnyId;

        // Link cut sizes to base SKUs before writing stock.
        if ($this->variantCount($rootId) > 0) {
            $this->autoLinkInventorySources($rootId);
        }

        $defs = $this->variantAttributeDefs($rootId);
        $allDefs = $this->attributeDefs();
        $codeToId = [];
        foreach ($allDefs as $def) {
            $codeToId[$def['code']] = (int) $def['id'];
        }

        $tenantId = $this->app->tenant->id();
        foreach ($prices as $vid => $price) {
            $vid = (int) $vid;
            if ($vid <= 0) {
                continue;
            }
            $kg = (float) ($weights[$vid] ?? 0);

            $attrPosted = is_array($variantAttrs[$vid] ?? null) ? $variantAttrs[$vid] : [];
            // Legacy column posts → attribute ids.
            if (is_array($legacySizes) && array_key_exists($vid, $legacySizes) && isset($codeToId['size'])) {
                $attrPosted[$codeToId['size']] = $legacySizes[$vid];
            }
            if (is_array($legacyThicknesses) && array_key_exists($vid, $legacyThicknesses) && isset($codeToId['thickness'])) {
                $attrPosted[$codeToId['thickness']] = $legacyThicknesses[$vid];
            }

            $sizeText = '';
            $thicknessText = '';
            if (isset($codeToId['size'])) {
                $sizeText = trim((string) ($attrPosted[$codeToId['size']] ?? ''));
            }
            if (isset($codeToId['thickness'])) {
                $thicknessText = trim((string) ($attrPosted[$codeToId['thickness']] ?? ''));
            }

            $this->app->db->query(
                "UPDATE #__store_products
                 SET price=?, weight_grams=?, pick_bin=?, attr_size=?, attr_thickness=?
                 WHERE id=? AND tenant_id=?",
                [
                    round((float) $price, 2),
                    max(0, (int) round($kg * 1000)),
                    $this->normalizePickBin(trim((string) ($bins[$vid] ?? ''))),
                    $sizeText,
                    $thicknessText,
                    $vid,
                    $tenantId,
                ]
            );

            // Persist EAV attribute values for attached variant columns.
            $this->upsertVariantAttributes($vid, $attrPosted, $defs);

            (new \App\Store\StockLocationService($this->app))->ensureInventoryRow($tenantId, $vid);

            if (is_array($qtys) && array_key_exists($vid, $qtys)) {
                $variant = $this->find($vid);
                // Only accept direct stock edits on inventory owners (base cuts).
                if ($variant !== null && (int) ($variant['inventory_source_id'] ?? 0) <= 0) {
                    $this->setInventoryQty($vid, (int) $qtys[$vid]);
                }
            }
        }

        if ($this->variantCount($rootId) > 0) {
            $this->syncParentInventoryFromVariants($rootId);
        }
    }

    /**
     * @param array<int|string,mixed> $posted
     * @param list<array{id:int,name:string,code:string,values:list<string>}> $defs
     */
    private function upsertVariantAttributes(int $productId, array $posted, array $defs): void
    {
        $tenantId = $this->app->tenant->id();
        foreach ($defs as $def) {
            $aid = (int) $def['id'];
            $text = trim((string) ($posted[$aid] ?? $posted[$def['code']] ?? ''));
            $existing = $this->app->db->query(
                'SELECT id FROM #__store_product_attributes
                 WHERE tenant_id=? AND product_id=? AND attribute_id=? LIMIT 1',
                [$tenantId, $productId, $aid]
            )->row;
            if ($text === '') {
                if ($existing !== []) {
                    $this->app->db->query(
                        'DELETE FROM #__store_product_attributes WHERE id=? AND tenant_id=?',
                        [(int) $existing['id'], $tenantId]
                    );
                }
                continue;
            }
            if ($existing === []) {
                $this->app->db->query(
                    'INSERT INTO #__store_product_attributes
                     (tenant_id, product_id, attribute_id, value_text)
                     VALUES (?, ?, ?, ?)',
                    [$tenantId, $productId, $aid, $text]
                );
            } else {
                $this->app->db->query(
                    'UPDATE #__store_product_attributes SET value_text=? WHERE id=? AND tenant_id=?',
                    [$text, (int) $existing['id'], $tenantId]
                );
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function listingsFor(int $productId): array
    {
        return $this->app->db->query(
            "SELECT l.id, l.status, l.sku, l.title, l.mapped, c.name AS channel_name, c.code AS channel_code, c.type AS channel_type
             FROM #__store_listings l
             INNER JOIN #__store_channels c ON c.id = l.channel_id AND c.tenant_id = l.tenant_id
             WHERE l.tenant_id=? AND l.product_id=?
             ORDER BY c.name ASC",
            [$this->app->tenant->id(), $productId]
        )->rows;
    }

    /** @return list<array<string,mixed>> */
    private function channels(): array
    {
        return $this->app->db->query(
            "SELECT id, name, code, type, active FROM #__store_channels
             WHERE tenant_id=? ORDER BY name ASC",
            [$this->app->tenant->id()]
        )->rows;
    }

    /** Ensure pick bin exists as a stock location code; returns normalized code. */
    private function normalizePickBin(string $bin): string
    {
        $bin = trim($bin);
        if ($bin === '') {
            return '';
        }
        $bin = mb_substr($bin, 0, 64);
        (new \App\Store\StockLocationService($this->app))->ensureLocation(
            $this->app->tenant->id(),
            $bin,
            $bin
        );
        return $bin;
    }
}
