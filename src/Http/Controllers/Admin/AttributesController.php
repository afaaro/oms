<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Throwable;

final class AttributesController extends Controller
{
    public static function registerRoutes(\App\Core\Router $router): void
    {
        $router->get('admin/attributes', [self::class, 'index'], true);
        $router->get('admin/attributes/new', [self::class, 'create'], true);
        $router->post('admin/attributes/new', [self::class, 'store'], true);
        $router->get('admin/attributes/edit', [self::class, 'edit'], true);
        $router->post('admin/attributes/save', [self::class, 'save'], true);
        $router->post('admin/attributes/delete', [self::class, 'delete'], true);
        $router->post('admin/attributes/value/add', [self::class, 'addValue'], true);
        $router->post('admin/attributes/value/delete', [self::class, 'deleteValue'], true);
    }

    public function index(): void
    {
        $this->ensureDefaults();
        echo $this->view('admin/attributes/index', [
            'title'      => 'Product Attribute Manager',
            'navSection' => 'products',
            'attributes' => $this->listAttributes(),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function listAttributes(): array
    {
        $tenantId = $this->app->tenant->id();
        return $this->app->db->query(
            "SELECT a.id, a.name, a.code, a.sort_order, a.show_on_variants, a.active, a.created_at,
                    (SELECT COUNT(*) FROM #__store_attribute_values v
                     WHERE v.attribute_id = a.id AND v.tenant_id = a.tenant_id) AS value_count,
                    (SELECT COUNT(*) FROM #__store_product_attributes pa
                     WHERE pa.attribute_id = a.id AND pa.tenant_id = a.tenant_id
                       AND TRIM(pa.value_text) <> '') AS product_count
             FROM #__store_attributes a
             WHERE a.tenant_id=?
             ORDER BY a.sort_order ASC, a.name ASC",
            [$tenantId]
        )->rows;
    }

    public function create(): void
    {
        echo $this->view('admin/attributes/form', [
            'title'      => 'New attribute',
            'navSection' => 'products',
            'attribute'  => null,
            'values'     => [],
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function store(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed. Please try again.');
            $this->redirect('admin/attributes/new');
        }

        $tenantId = $this->app->tenant->id();
        if ($tenantId <= 0) {
            $this->app->session->flash('error', 'No active tenant — cannot save attributes.');
            $this->redirect('admin/attributes/new');
        }

        $name = trim((string) $this->app->request->post('name', ''));
        if ($name === '') {
            $this->app->session->flash('error', 'Attribute name is required.');
            $this->redirect('admin/attributes/new');
        }

        // If an active attribute with this name already exists, open it instead of
        // creating Size_2 / Size_3 duplicates.
        $existing = $this->findByName($name);
        if ($existing !== null) {
            $this->app->session->flash('success', 'Attribute "' . $name . '" already exists — opened for editing.');
            $this->redirect('admin/attributes/edit', ['id' => (int) $existing['id']]);
        }

        $postedCode = trim((string) $this->app->request->post('code', ''));
        $code = $this->uniqueCode($this->normalizeCode($postedCode, $name));

        try {
            $ins = $this->app->db->query(
                'INSERT INTO #__store_attributes
                 (tenant_id, name, code, sort_order, show_on_variants, active)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $tenantId,
                    $name,
                    $code,
                    (int) $this->app->request->post('sort_order', 0),
                    $this->app->request->post('show_on_variants', '') !== '' ? 1 : 0,
                    $this->app->request->post('active', '') !== '' ? 1 : 0,
                ]
            );
            $id = (int) ($ins->insert_id ?: 0);
            if ($id <= 0) {
                $row = $this->app->db->query(
                    'SELECT id FROM #__store_attributes WHERE tenant_id=? AND code=? ORDER BY id DESC LIMIT 1',
                    [$tenantId, $code]
                )->row;
                $id = (int) ($row['id'] ?? 0);
            }
        } catch (Throwable $e) {
            $this->app->session->flash('error', 'Could not create attribute: ' . $e->getMessage());
            $this->redirect('admin/attributes/new');
        }

        if ($id <= 0) {
            $this->app->session->flash('error', 'Attribute was created but could not be opened. Check Attribute Manager.');
            $this->redirect('admin/attributes');
        }

        $this->app->session->flash('success', 'Attribute created.');
        $this->redirect('admin/attributes/edit', ['id' => $id]);
    }

    public function edit(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $attribute = $this->find($id);
        if ($attribute === null) {
            http_response_code(404);
            echo $this->view('admin/error', [
                'title'      => 'Not found',
                'navSection' => 'products',
                'message'    => 'Attribute not found.',
                'user'       => $this->app->auth->user(),
            ]);
            return;
        }

        echo $this->view('admin/attributes/form', [
            'title'      => (string) $attribute['name'],
            'navSection' => 'products',
            'attribute'  => $attribute,
            'values'     => $this->valuesFor($id),
            'flash'      => $this->app->session->flash('success'),
            'error'      => $this->app->session->flash('error'),
            'user'       => $this->app->auth->user(),
        ]);
    }

    public function save(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed. Please try again.');
            $this->redirect('admin/attributes');
        }

        $id = (int) $this->app->request->post('id', 0);
        $attribute = $this->find($id);
        if ($attribute === null) {
            $this->app->session->flash('error', 'Attribute not found.');
            $this->redirect('admin/attributes');
        }

        $name = trim((string) $this->app->request->post('name', ''));
        if ($name === '') {
            $this->app->session->flash('error', 'Attribute name is required.');
            $this->redirect('admin/attributes/edit', ['id' => $id]);
        }

        $existingCode = (string) ($attribute['code'] ?? '');
        $locked = in_array($existingCode, ['size', 'thickness'], true);
        if ($locked) {
            $code = $existingCode;
        } else {
            $postedCode = trim((string) $this->app->request->post('code', ''));
            $code = $this->normalizeCode($postedCode !== '' ? $postedCode : $existingCode, $name);
            if ($this->codeExists($code, $id)) {
                $code = $this->uniqueCode($code, $id);
            }
        }

        try {
            $this->app->db->query(
                'UPDATE #__store_attributes
                 SET name=?, code=?, sort_order=?, show_on_variants=?, active=?
                 WHERE id=? AND tenant_id=?',
                [
                    $name,
                    $code,
                    (int) $this->app->request->post('sort_order', 0),
                    $this->app->request->post('show_on_variants', '') !== '' ? 1 : 0,
                    $this->app->request->post('active', '') !== '' ? 1 : 0,
                    $id,
                    $this->app->tenant->id(),
                ]
            );
        } catch (Throwable $e) {
            $this->app->session->flash('error', 'Could not save attribute: ' . $e->getMessage());
            $this->redirect('admin/attributes/edit', ['id' => $id]);
        }

        $this->app->session->flash('success', 'Attribute saved.');
        $this->redirect('admin/attributes/edit', ['id' => $id]);
    }

    public function delete(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed. Please try again.');
            $this->redirect('admin/attributes');
        }
        $id = (int) $this->app->request->post('id', 0);
        $attribute = $this->find($id);
        if ($attribute === null) {
            $this->redirect('admin/attributes');
        }
        if (in_array((string) ($attribute['code'] ?? ''), ['size', 'thickness'], true)) {
            $this->app->session->flash('error', 'Size and Thickness cannot be deleted.');
            $this->redirect('admin/attributes/edit', ['id' => $id]);
        }

        $tenantId = $this->app->tenant->id();
        $this->app->db->query(
            'DELETE FROM #__store_product_variant_attributes WHERE tenant_id=? AND attribute_id=?',
            [$tenantId, $id]
        );
        $this->app->db->query(
            'DELETE FROM #__store_product_attributes WHERE tenant_id=? AND attribute_id=?',
            [$tenantId, $id]
        );
        $this->app->db->query(
            'DELETE FROM #__store_attribute_values WHERE tenant_id=? AND attribute_id=?',
            [$tenantId, $id]
        );
        $this->app->db->query(
            'DELETE FROM #__store_attributes WHERE id=? AND tenant_id=?',
            [$id, $tenantId]
        );
        $this->app->session->flash('success', 'Attribute deleted.');
        $this->redirect('admin/attributes');
    }

    public function addValue(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed. Please try again.');
            $this->redirect('admin/attributes');
        }
        $attributeId = (int) $this->app->request->post('attribute_id', 0);
        if ($this->find($attributeId) === null) {
            $this->app->session->flash('error', 'Attribute not found.');
            $this->redirect('admin/attributes');
        }
        $value = trim((string) $this->app->request->post('value', ''));
        if ($value === '') {
            $this->app->session->flash('error', 'Value is required.');
            $this->redirect('admin/attributes/edit', ['id' => $attributeId]);
        }

        $exists = $this->app->db->query(
            'SELECT id FROM #__store_attribute_values
             WHERE tenant_id=? AND attribute_id=? AND `value`=? LIMIT 1',
            [$this->app->tenant->id(), $attributeId, $value]
        )->row;
        if ($exists !== []) {
            $this->app->session->flash('error', 'That value already exists.');
            $this->redirect('admin/attributes/edit', ['id' => $attributeId]);
        }

        try {
            $this->app->db->query(
                'INSERT INTO #__store_attribute_values
                 (tenant_id, attribute_id, `value`, sort_order, active)
                 VALUES (?, ?, ?, ?, 1)',
                [
                    $this->app->tenant->id(),
                    $attributeId,
                    $value,
                    (int) $this->app->request->post('sort_order', 0),
                ]
            );
        } catch (Throwable $e) {
            $this->app->session->flash('error', 'Could not add value: ' . $e->getMessage());
            $this->redirect('admin/attributes/edit', ['id' => $attributeId]);
        }

        $this->app->session->flash('success', 'Value added.');
        $this->redirect('admin/attributes/edit', ['id' => $attributeId]);
    }

    public function deleteValue(): void
    {
        if (!$this->app->session->validateCsrf((string) $this->app->request->post('_token', ''))) {
            $this->app->session->flash('error', 'Security check failed. Please try again.');
            $this->redirect('admin/attributes');
        }
        $valueId = (int) $this->app->request->post('value_id', 0);
        $attributeId = (int) $this->app->request->post('attribute_id', 0);
        $this->app->db->query(
            'DELETE FROM #__store_attribute_values WHERE id=? AND tenant_id=? AND attribute_id=?',
            [$valueId, $this->app->tenant->id(), $attributeId]
        );
        $this->app->db->query(
            'UPDATE #__store_product_attributes SET attribute_value_id=NULL
             WHERE tenant_id=? AND attribute_value_id=?',
            [$this->app->tenant->id(), $valueId]
        );
        $this->app->session->flash('success', 'Value removed.');
        $this->redirect('admin/attributes/edit', ['id' => $attributeId]);
    }

    /** Ensure Size / Thickness exist for the active tenant. */
    private function ensureDefaults(): void
    {
        $tenantId = $this->app->tenant->id();
        if ($tenantId <= 0) {
            return;
        }
        $defaults = [
            ['Size', 'size', 10],
            ['Thickness', 'thickness', 20],
        ];
        foreach ($defaults as [$name, $code, $sort]) {
            $exists = $this->app->db->query(
                'SELECT id FROM #__store_attributes WHERE tenant_id=? AND code=? LIMIT 1',
                [$tenantId, $code]
            )->row;
            if ($exists !== []) {
                // Reactivate system attributes if they were soft-disabled.
                $this->app->db->query(
                    'UPDATE #__store_attributes SET active=1, name=? WHERE id=? AND tenant_id=?',
                    [$name, (int) $exists['id'], $tenantId]
                );
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

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->app->db->query(
            'SELECT * FROM #__store_attributes WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function findByName(string $name): ?array
    {
        $row = $this->app->db->query(
            'SELECT * FROM #__store_attributes
             WHERE tenant_id=? AND LOWER(name)=LOWER(?) AND active=1
             ORDER BY id ASC LIMIT 1',
            [$this->app->tenant->id(), $name]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    private function valuesFor(int $attributeId): array
    {
        return $this->app->db->query(
            'SELECT id, `value`, sort_order, active, created_at
             FROM #__store_attribute_values
             WHERE tenant_id=? AND attribute_id=?
             ORDER BY sort_order ASC, `value` ASC',
            [$this->app->tenant->id(), $attributeId]
        )->rows;
    }

    private function normalizeCode(string $code, string $name): string
    {
        $raw = trim($code) !== '' ? $code : $name;
        $raw = strtolower(trim($raw));
        $raw = preg_replace('/[^a-z0-9_]+/', '_', $raw) ?? '';
        $raw = trim($raw, '_');
        if ($raw === '') {
            $raw = 'attr';
        }
        return substr($raw, 0, 64);
    }

    private function uniqueCode(string $code, int $excludeId = 0): string
    {
        $code = $this->normalizeCode($code, 'attr');
        if (!$this->codeExists($code, $excludeId)) {
            return $code;
        }
        for ($i = 2; $i < 1000; $i++) {
            $suffix = '_' . $i;
            $candidate = substr($code, 0, max(1, 64 - strlen($suffix))) . $suffix;
            if (!$this->codeExists($candidate, $excludeId)) {
                return $candidate;
            }
        }
        return substr($code, 0, 50) . '_' . bin2hex(random_bytes(4));
    }

    private function codeExists(string $code, int $excludeId = 0): bool
    {
        $row = $this->app->db->query(
            'SELECT id FROM #__store_attributes WHERE tenant_id=? AND code=? AND id<>? LIMIT 1',
            [$this->app->tenant->id(), $code, $excludeId]
        )->row;
        return $row !== [];
    }
}
