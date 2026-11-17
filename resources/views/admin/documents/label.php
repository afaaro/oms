<?php
/** @var callable $e */
/** @var array $bundle */
$order = $bundle['order'];
$address = $bundle['address'];
$shipment = $bundle['shipment'];
$company = $bundle['company'];
$tracking = (string) (($shipment['tracking_number'] ?? null) ?: ($order['tracking_number'] ?? ''));
$carrier = (string) (($shipment['carrier_code'] ?? null) ?: ($order['carrier_code'] ?? ''));
$service = (string) (($shipment['service_code'] ?? null) ?: ($order['mapped_shipping_method'] ?? $order['shipping_method'] ?? ''));
?>
<article class="sf-label-sheet">
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
      <?php if (!empty($address['line2'])): ?><?= $e((string) $address['line2']) ?><br><?php endif; ?>
      <?= $e(trim((string) ($address['city'] ?? '') . ' ' . (string) ($address['postcode'] ?? ''))) ?><br>
      <?= $e((string) ($address['country'] ?? 'GB')) ?>
    </div>
  </div>

  <?php if ($service !== ''): ?>
    <div style="margin-bottom:.75rem"><strong>Service:</strong> <?= $e($service) ?></div>
  <?php endif; ?>

  <?php if ($tracking !== ''): ?>
    <div class="barcode"><?= $e($tracking) ?></div>
    <div class="tracking"><?= $e($tracking) ?></div>
  <?php else: ?>
    <p class="muted">No tracking number yet — despatch the order to create a label.</p>
  <?php endif; ?>

  <div class="muted" style="margin-top:1.25rem;font-size:11px;border-top:1px dashed #bbb;padding-top:.5rem">
    Wt <?= $e(number_format((float) ($order['order_weight_kg'] ?? 0), 3)) ?> kg
    · Pkgs <?= $e((string) ($order['package_count'] ?? 1)) ?>
    · <?= $e(date('d/m/Y H:i')) ?>
  </div>
</article>
