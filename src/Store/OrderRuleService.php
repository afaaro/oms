<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

/**
 * Apply configurable order rules (hold / attention) after sync or create.
 */
final class OrderRuleService
{
    public const TYPES = ['unpaid', 'high_value', 'bad_address', 'unmapped'];
    public const ACTIONS = ['hold', 'attention'];

    public function __construct(private App $app) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function list(int $tenantId = 0): array
    {
        $tenantId = $tenantId > 0 ? $tenantId : $this->app->tenant->id();
        return $this->app->db->query(
            'SELECT * FROM #__store_order_rules WHERE tenant_id=? ORDER BY id ASC',
            [$tenantId]
        )->rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->app->db->query(
            'SELECT * FROM #__store_order_rules WHERE id=? AND tenant_id=? LIMIT 1',
            [$id, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /**
     * @param array{name?:string,rule_type?:string,action?:string,threshold?:float|int|string,active?:int|bool} $data
     */
    public function save(array $data, int $id = 0): int
    {
        $tenantId = $this->app->tenant->id();
        $name = trim((string) ($data['name'] ?? ''));
        $type = strtolower(trim((string) ($data['rule_type'] ?? 'unpaid')));
        $action = strtolower(trim((string) ($data['action'] ?? 'hold')));
        if (!in_array($type, self::TYPES, true)) {
            $type = 'unpaid';
        }
        if (!in_array($action, self::ACTIONS, true)) {
            $action = 'hold';
        }
        $threshold = round((float) ($data['threshold'] ?? 0), 2);
        $active = !empty($data['active']) ? 1 : 0;
        if ($name === '') {
            $name = ucfirst(str_replace('_', ' ', $type));
        }

        if ($id > 0) {
            $this->app->db->query(
                'UPDATE #__store_order_rules
                 SET name=?, rule_type=?, action=?, threshold=?, active=?
                 WHERE id=? AND tenant_id=?',
                [$name, $type, $action, $threshold, $active, $id, $tenantId]
            );
            return $id;
        }

        $ins = $this->app->db->query(
            'INSERT INTO #__store_order_rules (tenant_id, name, rule_type, action, threshold, active)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $name, $type, $action, $threshold, $active]
        );
        return (int) ($ins->insert_id ?: 0);
    }

    public function delete(int $id): bool
    {
        $this->app->db->query(
            'DELETE FROM #__store_order_rules WHERE id=? AND tenant_id=?',
            [$id, $this->app->tenant->id()]
        );
        return true;
    }

    /**
     * @return array{applied:list<string>}
     */
    public function applyToOrder(int $orderId): array
    {
        $tenantId = $this->app->tenant->id();
        $order = $this->app->db->query(
            'SELECT * FROM #__store_orders WHERE id=? AND tenant_id=? LIMIT 1',
            [$orderId, $tenantId]
        )->row;
        if ($order === []) {
            return ['applied' => []];
        }

        $rules = $this->app->db->query(
            'SELECT * FROM #__store_order_rules WHERE tenant_id=? AND active=1 ORDER BY id ASC',
            [$tenantId]
        )->rows;

        $applied = [];
        $hold = false;
        $attention = false;

        foreach ($rules as $rule) {
            if (!$this->matches($order, $rule, $tenantId)) {
                continue;
            }
            $action = (string) ($rule['action'] ?? 'hold');
            $label = (string) ($rule['name'] ?? $rule['rule_type'] ?? 'rule');
            $applied[] = $label;
            if ($action === 'attention') {
                $attention = true;
            } else {
                $hold = true;
            }
        }

        if ($hold || $attention) {
            $sets = [];
            $bind = [];
            if ($hold) {
                $sets[] = 'on_hold=1';
                if (!in_array((string) ($order['status'] ?? ''), ['despatched', 'cancelled', 'refunded'], true)) {
                    $sets[] = "status='on_hold'";
                }
            }
            if ($attention) {
                $sets[] = 'attention_required=1';
            }
            $bind[] = $orderId;
            $bind[] = $tenantId;
            $this->app->db->query(
                'UPDATE #__store_orders SET ' . implode(', ', $sets) . ' WHERE id=? AND tenant_id=?',
                $bind
            );
        }

        return ['applied' => $applied];
    }

    /** @param array<string,mixed> $order @param array<string,mixed> $rule */
    private function matches(array $order, array $rule, int $tenantId): bool
    {
        $type = (string) ($rule['rule_type'] ?? '');
        return match ($type) {
            'unpaid' => (int) ($order['is_paid'] ?? 1) === 0
                || (string) ($order['status'] ?? '') === 'awaiting_payment',
            'high_value' => (float) ($order['total'] ?? 0) >= (float) ($rule['threshold'] ?? 0)
                && (float) ($rule['threshold'] ?? 0) > 0,
            'bad_address' => $this->isBadAddress($order),
            'unmapped' => $this->hasUnmappedLines((int) $order['id'], $tenantId),
            default => false,
        };
    }

    /** @param array<string,mixed> $order */
    private function isBadAddress(array $order): bool
    {
        $raw = $order['shipping_address'] ?? null;
        $addr = [];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $addr = $decoded;
            }
        } elseif (is_array($raw)) {
            $addr = $raw;
        }
        $line1 = trim((string) ($addr['line1'] ?? ''));
        $postcode = trim((string) ($addr['postcode'] ?? ''));
        $name = trim((string) ($order['shipping_name'] ?? ''));
        return $line1 === '' || $postcode === '' || $name === '';
    }

    private function hasUnmappedLines(int $orderId, int $tenantId): bool
    {
        $rows = $this->app->db->query(
            'SELECT sku, product_id FROM #__store_order_items WHERE order_id=?',
            [$orderId]
        )->rows;
        foreach ($rows as $row) {
            if ((int) ($row['product_id'] ?? 0) > 0) {
                continue;
            }
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                return true;
            }
            $prod = $this->app->db->query(
                'SELECT id FROM #__store_products WHERE tenant_id=? AND sku=? LIMIT 1',
                [$tenantId, $sku]
            )->row;
            if ($prod === []) {
                return true;
            }
        }
        return false;
    }
}
