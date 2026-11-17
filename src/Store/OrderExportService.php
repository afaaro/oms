<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

/**
 * Writes an orders CSV for a tenant under storage/exports/{tenant}/.
 */
final class OrderExportService
{
    public function __construct(private App $app) {}

    /** @return string path relative to storage/ */
    public function write(int $tenantId): string
    {
        if ($tenantId <= 0) {
            throw new \RuntimeException('Export needs a tenant.');
        }

        $rows = $this->app->db->query(
            "SELECT o.id, o.external_order_id, COALESCE(c.name, '') AS channel_name,
                    o.status, o.created_at, o.total, o.currency, o.shipping_method
             FROM #__store_orders o
             LEFT JOIN #__store_channels c ON c.id = o.channel_id AND c.tenant_id = o.tenant_id
             WHERE o.tenant_id = ?
             ORDER BY o.id ASC",
            [$tenantId]
        )->rows;

        $dir = $this->app->basePath . '/storage/exports/' . $tenantId;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create export directory.');
        }

        $relative = 'exports/' . $tenantId . '/orders-' . date('Ymd-His') . '.csv';
        $absolute = $this->app->basePath . '/storage/' . $relative;
        $handle = fopen($absolute, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not write export file.');
        }

        fputcsv($handle, [
            'Order number',
            'Channel reference',
            'Channel',
            'Status',
            'Date',
            'Total',
            'Currency',
            'Shipping method',
        ]);
        foreach ($rows as $row) {
            $created = (string) ($row['created_at'] ?? '');
            $ts = $created !== '' ? strtotime($created) : false;
            fputcsv($handle, [
                (string) ($row['id'] ?? ''),
                (string) ($row['external_order_id'] ?? ''),
                (string) ($row['channel_name'] ?? ''),
                (string) ($row['status'] ?? ''),
                $ts !== false ? date('d/m/Y H:i', $ts) : $created,
                number_format((float) ($row['total'] ?? 0), 2, '.', ''),
                (string) ($row['currency'] ?? ''),
                (string) ($row['shipping_method'] ?? ''),
            ]);
        }
        fclose($handle);

        return $relative;
    }

    public function absolutePath(int $tenantId, string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        $prefix = 'exports/' . $tenantId . '/';
        if ($relative === '' || !str_starts_with($relative, $prefix) || str_contains($relative, '..')) {
            return null;
        }
        $root = realpath($this->app->basePath . '/storage/exports/' . $tenantId);
        $file = realpath($this->app->basePath . '/storage/' . $relative);
        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $fileNorm = str_replace('\\', '/', $file);
        if (!str_starts_with($fileNorm, $root . '/')) {
            return null;
        }
        return $file;
    }
}
