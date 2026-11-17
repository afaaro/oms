<?php
/** @var callable $e */
/** @var array $manifest */
/** @var list<array> $shipments */
/** @var array $company */
?>
<article class="sf-print-sheet">
  <div class="meta">
    <div>
      <h1>Courier manifest</h1>
      <div class="muted"><?= $e((string) ($company['name'] ?? 'StoreFeeder')) ?></div>
    </div>
    <div style="text-align:right">
      <div><strong><?= $e((string) ($manifest['reference'] ?? '')) ?></strong></div>
      <div>Carrier: <?= $e((string) ($manifest['carrier_code'] ?? '')) ?></div>
      <div>Status: <?= $e((string) ($manifest['status'] ?? '')) ?></div>
      <div class="muted">Created <?= $e((string) ($manifest['created_at'] ?? '')) ?></div>
      <?php if (!empty($manifest['closed_at'])): ?>
        <div class="muted">Closed <?= $e((string) $manifest['closed_at']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Order</th>
        <th>Name</th>
        <th>Tracking</th>
        <th>Service</th>
        <th>Weight</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($shipments === []): ?>
        <tr><td colspan="6" class="muted">No shipments on this manifest.</td></tr>
      <?php else: foreach ($shipments as $i => $s): ?>
        <tr>
          <td><?= $e((string) ($i + 1)) ?></td>
          <td>#<?= $e((string) ($s['order_id'] ?? '')) ?><br><span class="muted"><?= $e((string) ($s['external_order_id'] ?? '')) ?></span></td>
          <td><?= $e((string) ($s['shipping_name'] ?? '')) ?></td>
          <td><?= $e((string) ($s['tracking_number'] ?? '')) ?></td>
          <td><?= $e((string) ($s['service_code'] ?? '')) ?></td>
          <td><?= $e(number_format((float) ($s['weight_kg'] ?? 0), 3)) ?> kg</td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <p style="margin-top:1rem"><strong>Total parcels:</strong> <?= $e((string) count($shipments)) ?></p>
  <?php if (!empty($manifest['notes'])): ?>
    <p class="muted">Notes: <?= $e((string) $manifest['notes']) ?></p>
  <?php endif; ?>
</article>
