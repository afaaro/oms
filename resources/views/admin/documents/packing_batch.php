<?php
/** @var callable $e */
/** @var list<array> $bundles */
/** @var string|null $waveReference */
$total = count($bundles);
$waveReference = isset($waveReference) ? (string) $waveReference : '';
foreach ($bundles as $i => $bundle):
  $order = $bundle['order'];
  $items = $bundle['items'];
  $address = $bundle['address'];
  $company = $bundle['company'];
  $isLast = ($i === $total - 1);
?>
<article class="sf-print-sheet<?= $isLast ? '' : ' sf-page-break' ?>">
  <div class="meta">
    <div>
      <h1>Packing slip</h1>
      <div class="muted"><?= $e((string) ($company['name'] ?? 'StoreFeeder')) ?></div>
      <?php if ($waveReference !== ''): ?>
        <div class="muted">Wave <?= $e($waveReference) ?> · <?= $e((string) ($i + 1)) ?>/<?= $e((string) $total) ?></div>
      <?php endif; ?>
    </div>
    <div style="text-align:right">
      <div><strong>Order #<?= $e((string) $order['id']) ?></strong></div>
      <div class="muted"><?= $e((string) ($order['external_order_id'] ?? '')) ?></div>
      <div class="muted"><?= $e((string) ($bundle['channel']['name'] ?? '')) ?></div>
    </div>
  </div>

  <div style="margin:1rem 0" class="addr">
    <div class="muted" style="font-size:11px">SHIP TO</div>
    <strong><?= $e((string) ($order['shipping_name'] ?? '')) ?></strong><br>
    <?= $e((string) ($address['line1'] ?? '')) ?><br>
    <?= $e(trim((string) ($address['city'] ?? '') . ' ' . (string) ($address['postcode'] ?? ''))) ?><br>
    <?= $e((string) ($address['country'] ?? 'GB')) ?>
  </div>

  <table>
    <thead><tr><th>SKU</th><th>Item</th><th>Qty</th><th>✓ Packed</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= $e((string) ($it['sku'] ?? '')) ?></td>
          <td><?= $e((string) ($it['name'] ?? '')) ?></td>
          <td><?= $e((string) ($it['qty'] ?? 1)) ?></td>
          <td style="width:4rem">☐</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($order['special_instructions']) || !empty($order['notes'])): ?>
    <p style="margin-top:1rem"><strong>Notes:</strong> <?= $e((string) ($order['special_instructions'] ?: $order['notes'])) ?></p>
  <?php endif; ?>
</article>
<?php endforeach; ?>
