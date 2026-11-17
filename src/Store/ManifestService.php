<?php

declare(strict_types=1);

namespace App\Store;

use App\Core\App;

final class ManifestService
{
    public function __construct(private App $app) {}

    /** @return list<array<string,mixed>> */
    public function list(int $tenantId, int $limit = 50): array
    {
        return $this->app->db->query(
            'SELECT * FROM #__store_manifests WHERE tenant_id=? ORDER BY id DESC LIMIT ' . max(1, $limit),
            [$tenantId]
        )->rows;
    }

    /**
     * Create (or reopen) an open manifest for a carrier and attach unmanifested labelled shipments.
     *
     * @return array{ok:bool, manifest_id?:int, attached?:int, message:string}
     */
    public function createAndAttach(string $carrierCode, string $notes = ''): array
    {
        $tenantId = $this->app->tenant->id();
        $carrierCode = strtolower(trim($carrierCode));
        if ($carrierCode === '') {
            return ['ok' => false, 'message' => 'Carrier code is required.'];
        }

        $open = $this->app->db->query(
            "SELECT id FROM #__store_manifests
             WHERE tenant_id=? AND carrier_code=? AND status='open'
             ORDER BY id DESC LIMIT 1",
            [$tenantId, $carrierCode]
        )->row;

        if ($open !== []) {
            $manifestId = (int) $open['id'];
        } else {
            $ref = 'MAN-' . strtoupper(substr($carrierCode, 0, 4)) . '-' . date('Ymd-His');
            $ins = $this->app->db->query(
                "INSERT INTO #__store_manifests (tenant_id, reference, carrier_code, status, notes)
                 VALUES (?, ?, ?, 'open', ?)",
                [$tenantId, $ref, $carrierCode, $notes]
            );
            $manifestId = (int) ($ins->insert_id ?: 0);
        }

        if ($manifestId <= 0) {
            return ['ok' => false, 'message' => 'Could not create manifest.'];
        }

        $pending = $this->app->db->query(
            "SELECT id FROM #__store_shipments
             WHERE tenant_id=? AND carrier_code=? AND manifest_id IS NULL
               AND status IN ('labelled','despatched','created')
               AND tracking_number <> ''",
            [$tenantId, $carrierCode]
        )->rows;

        $attached = 0;
        foreach ($pending as $row) {
            $this->app->db->query(
                "UPDATE #__store_shipments SET manifest_id=?, status='manifested' WHERE id=? AND tenant_id=?",
                [$manifestId, (int) $row['id'], $tenantId]
            );
            $attached++;
        }

        $count = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_shipments WHERE manifest_id=? AND tenant_id=?',
            [$manifestId, $tenantId]
        )->row['c'] ?? 0);

        $this->app->db->query(
            'UPDATE #__store_manifests SET shipment_count=?, notes=IF(?<>\'\', ?, notes) WHERE id=? AND tenant_id=?',
            [$count, $notes, $notes, $manifestId, $tenantId]
        );

        return [
            'ok'          => true,
            'manifest_id' => $manifestId,
            'attached'    => $attached,
            'message'     => "Manifest #{$manifestId}: attached {$attached} shipment(s) ({$count} total).",
        ];
    }

    /** @return array{ok:bool, message:string} */
    public function close(int $manifestId): array
    {
        $tenantId = $this->app->tenant->id();
        $row = $this->app->db->query(
            'SELECT * FROM #__store_manifests WHERE id=? AND tenant_id=? LIMIT 1',
            [$manifestId, $tenantId]
        )->row;
        if ($row === []) {
            return ['ok' => false, 'message' => 'Manifest not found.'];
        }
        if ((string) ($row['status'] ?? '') === 'closed') {
            return ['ok' => false, 'message' => 'Manifest already closed.'];
        }

        $count = (int) ($this->app->db->query(
            'SELECT COUNT(*) AS c FROM #__store_shipments WHERE manifest_id=? AND tenant_id=?',
            [$manifestId, $tenantId]
        )->row['c'] ?? 0);

        $notes = (string) ($row['notes'] ?? '');
        $rmNote = '';
        $carrierCode = (string) ($row['carrier_code'] ?? '');
        $royalMail = new RoyalMailOps($this->app);
        $courier = new CourierOps($this->app);
        $provider = $courier->provider($carrierCode);
        if ($royalMail->isRoyalMail($carrierCode)) {
            $rm = $royalMail->createManifest();
            if (empty($rm['ok'])) {
                return ['ok' => false, 'message' => (string) ($rm['message'] ?? 'Royal Mail manifest failed.')];
            }
            $number = trim((string) ($rm['manifest_number'] ?? ''));
            if ($number !== '') {
                $notes = $this->appendCarrierNote($notes, 'RM', $number);
                $rmNote = ' Royal Mail manifest ' . $number . '.';
            }
        } elseif ($provider === 'evri') {
            $rmNote = ' Evri has no carrier manifest; closed locally.';
        } elseif ($provider === 'dpd' || $provider === 'parcelforce') {
            $carrier = $courier->createManifest($provider);
            if (empty($carrier['ok'])) {
                return ['ok' => false, 'message' => (string) ($carrier['message'] ?? 'Carrier manifest failed.')];
            }
            $number = trim((string) ($carrier['manifest_number'] ?? ''));
            $label = $provider === 'dpd' ? 'DPD' : 'PF';
            if ($number !== '') {
                $notes = $this->appendCarrierNote($notes, $label, $number);
            }
            $rmNote = ' ' . (string) ($carrier['message'] ?? '');
        }

        $this->app->db->query(
            "UPDATE #__store_manifests
             SET status='closed', closed_at=NOW(), shipment_count=?, notes=?
             WHERE id=? AND tenant_id=?",
            [$count, substr($notes, 0, 500), $manifestId, $tenantId]
        );

        return ['ok' => true, 'message' => "Manifest #{$manifestId} closed with {$count} shipment(s)." . $rmNote];
    }

    private function appendCarrierNote(string $notes, string $label, string $number): string
    {
        $notes = trim((string) (preg_replace('/\n?' . preg_quote($label, '/') . ' manifest: \S+/', '', $notes) ?? $notes));
        $line = $label . ' manifest: ' . $number;
        $combined = $notes === '' ? $line : $notes . "\n" . $line;
        return strlen($combined) <= 500 ? $combined : substr($combined, 0, 500);
    }

    /** @return array<string,mixed>|null */
    public function get(int $manifestId): ?array
    {
        $row = $this->app->db->query(
            'SELECT * FROM #__store_manifests WHERE id=? AND tenant_id=? LIMIT 1',
            [$manifestId, $this->app->tenant->id()]
        )->row;
        return $row !== [] ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function shipments(int $manifestId): array
    {
        return $this->app->db->query(
            "SELECT s.*, o.external_order_id, o.shipping_name, o.buyer_email, o.total, o.currency
             FROM #__store_shipments s
             INNER JOIN #__store_orders o ON o.id = s.order_id AND o.tenant_id = s.tenant_id
             WHERE s.manifest_id=? AND s.tenant_id=?
             ORDER BY s.id ASC",
            [$manifestId, $this->app->tenant->id()]
        )->rows;
    }

    /** @return list<array{carrier_code:string,c:int}> */
    public function pendingByCarrier(): array
    {
        return $this->app->db->query(
            "SELECT carrier_code, COUNT(*) AS c
             FROM #__store_shipments
             WHERE tenant_id=? AND manifest_id IS NULL AND tracking_number <> ''
             GROUP BY carrier_code
             ORDER BY carrier_code",
            [$this->app->tenant->id()]
        )->rows;
    }
}
