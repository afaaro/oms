<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\Database;

final class CustomerRepository
{
    public function __construct(private Database $db) {}

    public function count(int $tenantId): int
    {
        return (int) ($this->db->query(
            'SELECT COUNT(*) AS c FROM #__store_customers WHERE tenant_id = ?',
            [$tenantId]
        )->row['c'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(int $tenantId, string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $row = $this->db->query(
            'SELECT * FROM #__store_customers WHERE tenant_id = ? AND LOWER(email) = LOWER(?) LIMIT 1',
            [$tenantId, $email]
        )->row;
        return $row !== [] ? $row : null;
    }

    /**
     * Upsert a customer from order buyer / ship-to fields.
     *
     * @param array<string, mixed> $order
     */
    public function upsertFromOrder(int $tenantId, int $channelId, array $order): ?int
    {
        $parsed = $this->parseOrderBuyer($order);
        if ($parsed === null) {
            return null;
        }

        $existing = $this->findByEmail($tenantId, $parsed['email']);
        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->db->query(
                'UPDATE #__store_customers
                 SET name = IF(name = "" OR name IS NULL, ?, name),
                     phone = IF(phone = "" OR phone IS NULL, ?, phone),
                     postcode = IF(postcode = "" OR postcode IS NULL, ?, postcode),
                     channel_id = COALESCE(channel_id, ?)
                 WHERE id = ? AND tenant_id = ?',
                [
                    $parsed['name'],
                    $parsed['phone'],
                    $parsed['postcode'],
                    $channelId > 0 ? $channelId : null,
                    $id,
                    $tenantId,
                ]
            );
            return $id;
        }

        $ins = $this->db->query(
            'INSERT INTO #__store_customers (tenant_id, name, email, phone, postcode, channel_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $parsed['name'],
                $parsed['email'],
                $parsed['phone'],
                $parsed['postcode'],
                $channelId > 0 ? $channelId : null,
            ]
        );
        return (int) ($ins->insert_id ?: 0) ?: null;
    }

    /**
     * Build customers from recent orders (demo CustomerRepository::syncFromOrders).
     *
     * @return int Number of new customers created
     */
    public function syncFromOrders(int $tenantId, int $limit = 10000): int
    {
        $rows = $this->db->query(
            'SELECT o.id, o.channel_id, o.buyer_username, o.buyer_email, o.shipping_name,
                    o.shipping_address, o.created_at
             FROM #__store_orders o
             WHERE o.tenant_id = ?
             ORDER BY o.id DESC
             LIMIT ' . max(1, min(20000, $limit)),
            [$tenantId]
        )->rows;

        $created = 0;
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $parsed = $this->parseOrderBuyer($row);
            if ($parsed === null) {
                continue;
            }
            $key = strtolower($parsed['email']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->findByEmail($tenantId, $parsed['email']) !== null) {
                continue;
            }
            try {
                $channelId = (int) ($row['channel_id'] ?? 0);
                $ins = $this->db->query(
                    'INSERT INTO #__store_customers (tenant_id, name, email, phone, postcode, channel_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $tenantId,
                        $parsed['name'],
                        $parsed['email'],
                        $parsed['phone'],
                        $parsed['postcode'],
                        $channelId > 0 ? $channelId : null,
                        (string) ($row['created_at'] ?? date('Y-m-d H:i:s')),
                    ]
                );
                if ((int) ($ins->insert_id ?: 0) > 0) {
                    $created++;
                }
            } catch (\Throwable) {
                // skip duplicates / bad rows
            }
        }

        return $created;
    }

    /**
     * @param array<string, mixed> $order
     * @return array{name:string,email:string,phone:string,postcode:string}|null
     */
    private function parseOrderBuyer(array $order): ?array
    {
        $username = trim((string) ($order['buyer_username'] ?? ''));
        $email = trim((string) ($order['buyer_email'] ?? ''));
        $name = trim((string) ($order['shipping_name'] ?? ''));
        $addr = [];
        $raw = $order['shipping_address'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $addr = $decoded;
            }
        } elseif (is_array($raw)) {
            $addr = $raw;
        }

        if ($name === '') {
            $name = trim((string) ($addr['fullName'] ?? $addr['name'] ?? ''));
        }
        if ($email === '') {
            $email = trim((string) ($addr['email'] ?? ''));
        }
        if ($email === '' && $username !== '') {
            $email = $username;
        }
        $phoneRaw = $addr['phone'] ?? $addr['primaryPhone'] ?? '';
        if (is_array($phoneRaw)) {
            $phoneRaw = $phoneRaw['number'] ?? $phoneRaw['phoneNumber'] ?? '';
        }
        $phone = trim((string) $phoneRaw);
        $postcode = trim((string) ($addr['postcode'] ?? $addr['postalCode'] ?? ''));

        if ($email === '') {
            $key = strtolower($name . '|' . $postcode . '|' . trim((string) ($addr['line1'] ?? $addr['addressLine1'] ?? '')));
            if ($name === '' || $key === '||' || $key === '|') {
                return null;
            }
            $email = 'buyer:' . substr(sha1($key), 0, 16) . '@local';
        }
        if ($name === '') {
            $name = $username !== '' ? $username : $email;
        }

        return [
            'name'     => mb_substr($name, 0, 255),
            'email'    => mb_substr($email, 0, 255),
            'phone'    => mb_substr($phone, 0, 64),
            'postcode' => mb_substr($postcode, 0, 32),
        ];
    }
}
