<?php
/** @var callable $e */
/** @var callable $url */
/** @var list<array> $bundles */
$total = count($bundles);
foreach ($bundles as $i => $bundle):
  $order = $bundle['order'];
  $address = $bundle['address'];
  $shipment = $bundle['shipment'];
  $company = $bundle['company'];
  $tracking = (string) (($shipment['tracking_number'] ?? null) ?: ($order['tracking_number'] ?? ''));
  $carrier = (string) (($shipment['carrier_code'] ?? null) ?: ($order['carrier_code'] ?? ''));
  $service = (string) (($shipment['service_code'] ?? null) ?: ($order['mapped_shipping_method'] ?? $order['shipping_method'] ?? ''));
  $isLast = ($i === $total - 1);
  $hasPdf = is_array($shipment)
    && trim((string) ($shipment['label_path'] ?? '')) !== ''
    && strtolower((string) ($shipment['label_format'] ?? '')) === 'pdf'
    && (int) ($shipment['id'] ?? 0) > 0;
?>
<?php if ($hasPdf): ?>
  <div class="sf-label-sheet<?= $isLast ? '' : ' sf-page-break' ?>" style="padding:0;height:100vh">
    <iframe
      src="<?= $e($url('admin/documents/label', ['shipment_id' => (int) $shipment['id']])) ?>"
      title="Carrier label #<?= $e((string) $order['id']) ?>"
      style="width:100%;height:100%;border:0"></iframe>
  </div>
<?php else: ?>
<article class="sf-label-sheet<?= $isLast ? '' : ' sf-page-break' ?>">
  <div class="meta">
    <div>
      <strong><?= $e((string) ($company['name'] ?? 'StoreFeeder')) ?></strong><br>
      <span class="muted"><?= $e(strtoupper($carrier !== '' ? $carrier : 'COURIER')) ?></span>
    </div>
    <div style="text-align:right">
      <div>Order #<?= $e((string) $order['id']) ?></div>
      <div class="muted"><?= $e((string) ($order['external_order_id'] ?? '')) ?></div>
    </div>
  </div>
  <div style="border:2px solid #111;padding:.65rem;margin:.5rem 0 1rem">
    <div class="muted" style="font-size:11px;margin-bottom:.25rem">DELIVER TO</div>
    <div class="addr">
      <strong><?= $e((string) ($order['shipping_name'] ?? '')) ?></strong><br>
      <?= $e((string) ($address['line1'] ?? '')) ?><br>
      <?= $e(trim((string) ($address['city'] ?? '') . ' ' . (string) ($address['postcode'] ?? ''))) ?><br>
      <?= $e((string) ($address['country'] ?? 'GB')) ?>
    </div>
  </div>
  <?php if ($service !== ''): ?><div style="margin-bottom:.75rem"><strong>Service:</strong> <?= $e($service) ?></div><?php endif; ?>
  <?php if ($tracking !== ''): ?>
    <div class="barcode"><?= $e($tracking) ?></div>
    <div class="tracking"><?= $e($tracking) ?></div>
  <?php endif; ?>
</article>
<?php endif; ?>
<?php endforeach; ?>
