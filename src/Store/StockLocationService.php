<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

/**
 * Resolve pick_bin ↔ store_stock_locations and ensure location-scoped inventory rows.
 */
final class StockLocationService
{
    public function __construct(private App $app) {}

    /** @return list<array{id:int,code:string,name:string}> */
    public function activeLocations(): array
    {
        $rows = $this->app->db->query(
            'SELECT id, code, name FROM #__store_stock_locations
             WHERE tenant_id=? AND active=1
             ORDER BY code ASC',
            [$this->app->tenant->id()]
        )->rows;

        return array_map(static function (array $r): array {
            return [
                'id'   => (int) ($r['id'] ?? 0),
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }, $rows);
    }

    public function findIdByCode(int $tenantId, string $code): int
    {
        $code = trim($code);
        if ($code === '') {
            return 0;
        }
        $row = $this->app->db->query(
            'SELECT id FROM #__store_stock_locations
             WHERE tenant_id=? AND code=? AND active=1
             LIMIT 1',
            [$tenantId, $code]
        )->row;
        return $row !== [] ? (int) $row['id'] : 0;
    }

    /**
     * Ensure a stock location exists for this bin code; returns location id or 0.
     */
    public function ensureLocation(int $tenantId, string $code, ?string $name = null): int
    {
        $code = trim($code);
        if ($code === '') {
            return 0;
        }
        $existing = $this->findIdByCode($tenantId, $code);
        if ($existing > 0) {
            return $existing;
        }
        $label = trim((string) $name);
        if ($label === '') {
            $label = $code;
        }
        $ins = $this->app->db->query(
            'INSERT INTO #__store_stock_locations (tenant_id, code, name, active) VALUES (?, ?, ?, 1)',
            [$tenantId, $code, $label]
        );
        return (int) ($ins->insert_id ?: $this->findIdByCode($tenantId, $code));
    }

    public function locationIdForProduct(int $tenantId, int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }
        $row = $this->app->db->query(
            'SELECT pick_bin FROM #__store_products WHERE id=? AND tenant_id=? LIMIT 1',
            [$productId, $tenantId]
        )->row;
        if ($row === []) {
            return 0;
        }
        return $this->findIdByCode($tenantId, (string) ($row['pick_bin'] ?? ''));
    }

    /**
     * Find or create inventory row for product at its pick_bin location (or unlocated).
     */
    public function ensureInventoryRow(int $tenantId, int $productId): int
    {
        if ($productId <= 0) {
            return 0;
        }

        $locationId = $this->locationIdForProduct($tenantId, $productId);

        if ($locationId > 0) {
            $atBin = $this->app->db->query(
                'SELECT id FROM #__store_inventory
                 WHERE tenant_id=? AND product_id=? AND location_id=?
                 ORDER BY id ASC LIMIT 1',
                [$tenantId, $productId, $locationId]
            )->row;
            if ($atBin !== []) {
                return (int) $atBin['id'];
            }

            // Prefer migrating the unlocated / first row onto this bin.
            $unlocated = $this->app->db->query(
                'SELECT id FROM #__store_inventory
                 WHERE tenant_id=? AND product_id=? AND (location_id IS NULL OR location_id=0)
                 ORDER BY id ASC LIMIT 1',
                [$tenantId, $productId]
            )->row;
            if ($unlocated !== []) {
                $this->app->db->query(
                    'UPDATE #__store_inventory SET location_id=? WHERE id=? AND tenant_id=?',
                    [$locationId, (int) $unlocated['id'], $tenantId]
                );
                return (int) $unlocated['id'];
            }

            $any = $this->app->db->query(
                'SELECT id FROM #__store_inventory WHERE tenant_id=? AND product_id=? ORDER BY id ASC LIMIT 1',
                [$tenantId, $productId]
            )->row;
            if ($any !== []) {
                $this->app->db->query(
                    'UPDATE #__store_inventory SET location_id=? WHERE id=? AND tenant_id=?',
                    [$locationId, (int) $any['id'], $tenantId]
                );
                return (int) $any['id'];
            }

            $ins = $this->app->db->query(
                'INSERT INTO #__store_inventory (tenant_id, product_id, location_id, qty_on_hand, qty_allocated)
                 VALUES (?, ?, ?, 0, 0)',
                [$tenantId, $productId, $locationId]
            );
            return (int) ($ins->insert_id ?: 0);
        }

        $inv = $this->app->db->query(
            'SELECT id FROM #__store_inventory
             WHERE tenant_id=? AND product_id=? AND (location_id IS NULL OR location_id=0)
             ORDER BY id ASC LIMIT 1',
            [$tenantId, $productId]
        )->row;
        if ($inv !== []) {
            return (int) $inv['id'];
        }

        $any = $this->app->db->query(
            'SELECT id FROM #__store_inventory WHERE tenant_id=? AND product_id=? ORDER BY id ASC LIMIT 1',
            [$tenantId, $productId]
        )->row;
        if ($any !== []) {
            return (int) $any['id'];
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__store_inventory (tenant_id, product_id, qty_on_hand, qty_allocated) VALUES (?, ?, 0, 0)',
            [$tenantId, $productId]
        );
        return (int) ($ins->insert_id ?: 0);
    }
}
