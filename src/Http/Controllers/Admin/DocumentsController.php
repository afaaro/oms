<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Store\DocumentService;
use App\Store\ManifestService;

final class DocumentsController extends Controller
{
    public function label(): void
    {
        $orderId = (int) $this->app->request->get('order_id', 0);
        $shipmentId = (int) $this->app->request->get('shipment_id', 0);
        $docs = new DocumentService($this->app);

        if ($shipmentId > 0) {
            $shipment = $docs->shipment($shipmentId);
            if ($shipment === null) {
                $this->notFound('Shipment not found.');
                return;
            }
            $orderId = (int) $shipment['order_id'];
            if ($this->streamCarrierLabel($shipment)) {
                return;
            }
        }

        $bundle = $docs->orderBundle($orderId);
        if ($bundle === null) {
            $this->notFound('Order not found.');
            return;
        }

        $shipment = $bundle['shipment'] ?? null;
        if (is_array($shipment) && $this->streamCarrierLabel($shipment)) {
            return;
        }

        echo $this->view('admin/documents/label', [
            'title'    => 'Shipping label',
            'bundle'   => $bundle,
            'autoPrint'=> $this->app->request->get('print', '') !== '',
        ], 'layouts/print');
    }

    public function invoice(): void
    {
        $orderId = (int) $this->app->request->get('order_id', 0);
        $docs = new DocumentService($this->app);
        $bundle = $docs->orderBundle($orderId);
        if ($bundle === null) {
            $this->notFound('Order not found.');
            return;
        }
        if ($docs->invoicesDisabled($bundle['channel']['settings'] ?? [])) {
            $this->notFound('Invoice printing is disabled for this channel.');
            return;
        }

        echo $this->view('admin/documents/invoice', [
            'title'     => 'Invoice',
            'bundle'    => $bundle,
            'autoPrint' => $this->app->request->get('print', '') !== '',
        ], 'layouts/print');
    }

    public function packing(): void
    {
        $orderId = (int) $this->app->request->get('order_id', 0);
        $docs = new DocumentService($this->app);
        $bundle = $docs->orderBundle($orderId);
        if ($bundle === null) {
            $this->notFound('Order not found.');
            return;
        }

        echo $this->view('admin/documents/packing', [
            'title'     => 'Packing slip',
            'bundle'    => $bundle,
            'autoPrint' => $this->app->request->get('print', '') !== '',
        ], 'layouts/print');
    }

    public function labelsBatch(): void
    {
        $ids = $this->idsFromRequest();
        $docs = new DocumentService($this->app);
        $bundles = [];
        foreach ($ids as $id) {
            $b = $docs->orderBundle($id);
            if ($b !== null && trim((string) ($b['order']['tracking_number'] ?? '')) !== '') {
                $bundles[] = $b;
            }
        }
        if ($bundles === []) {
            $this->notFound('No labelled / tracked orders selected.');
            return;
        }

        echo $this->view('admin/documents/labels_batch', [
            'title'     => 'Shipping labels',
            'bundles'   => $bundles,
            'autoPrint' => true,
        ], 'layouts/print');
    }

    public function manifestPrint(): void
    {
        $id = (int) $this->app->request->get('id', 0);
        $svc = new ManifestService($this->app);
        $manifest = $svc->get($id);
        if ($manifest === null) {
            $this->notFound('Manifest not found.');
            return;
        }

        echo $this->view('admin/documents/manifest', [
            'title'     => 'Manifest ' . (string) ($manifest['reference'] ?? $id),
            'manifest'  => $manifest,
            'shipments' => $svc->shipments($id),
            'company'   => $this->app->tenant->tenant() ?? [],
            'autoPrint' => $this->app->request->get('print', '') !== '',
        ], 'layouts/print');
    }

    /**
     * Stream a stored carrier PDF label when present.
     *
     * @param array<string,mixed> $shipment
     */
    private function streamCarrierLabel(array $shipment): bool
    {
        $path = trim((string) ($shipment['label_path'] ?? ''));
        $format = strtolower(trim((string) ($shipment['label_format'] ?? '')));
        if ($path === '' || ($format !== '' && $format !== 'pdf')) {
            return false;
        }
        if (str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '\\')) {
            return false;
        }
        $abs = rtrim(str_replace('\\', '/', $this->app->basePath), '/') . '/' . ltrim($path, '/');
        if (!is_file($abs) || !is_readable($abs)) {
            return false;
        }
        $forceHtml = $this->app->request->get('html', '') !== '';
        if ($forceHtml) {
            return false;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="label-' . (int) ($shipment['id'] ?? 0) . '.pdf"');
        header('Content-Length: ' . (string) filesize($abs));
        readfile($abs);
        return true;
    }

    private function notFound(string $message): void
    {
        http_response_code(404);
        echo $this->view('admin/error', [
            'title'      => 'Not found',
            'navSection' => 'warehouse',
            'message'    => $message,
            'user'       => $this->app->auth->user(),
        ]);
    }

    /** @return list<int> */
    private function idsFromRequest(): array
    {
        $raw = $this->app->request->get('ids', '');
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,]+/', (string) $raw) ?: [];
        }
        $ids = [];
        foreach ($parts as $p) {
            $id = (int) $p;
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }
}
