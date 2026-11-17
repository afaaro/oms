<?php
/** @var callable $e */
/** @var array $bundle */
$order = $bundle['order'];
$items = $bundle['items'];
$address = $bundle['address'];
$billing = $bundle['billing'];
$company = $bundle['company'];
$currency = (string) ($order['currency'] ?? 'GBP');
?>
<article class="sf-print-sheet">
  <div class="meta">
    <div>
      <h1>Invoice</h1>
      <div class="muted"><?= $e((string) ($company['name'] ?? 'StoreFeeder')) ?></div>
      <?php if (!empty($company['email'])): ?><div class="muted"><?= $e((string) $company['email']) ?></div><?php endif; ?>
    </div>
    <div style="text-align:right">
      <div><strong>Invoice #<?= $e((string) $order['id']) ?></strong></div>
      <div class="muted">Order <?= $e((string) ($order['external_order_id'] ?? $order['id'])) ?></div>
      <div class="muted"><?= $e((string) ($order['created_at'] ?? '')) ?></div>
      <div class="muted"><?= $e((string) ($bundle['channel']['name'] ?? '')) ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin:1.25rem 0">
    <div>
      <div class="muted" style="font-size:11px">BILL TO</div>
      <div class="addr">
        <strong><?= $e((string) ($order['shipping_name'] ?? '')) ?></strong><br>
        <?= $e((string) ($order['buyer_email'] ?? '')) ?><br>
        <?= $e((string) ($billing['line1'] ?? $address['line1'] ?? '')) ?><br>
        <?= $e(trim((string) ($billing['city'] ?? $address['city'] ?? '') . ' ' . (string) ($billing['postcode'] ?? $address['postcode'] ?? ''))) ?><br>
        <?= $e((string) ($billing['country'] ?? $address['country'] ?? 'GB')) ?>
      </div>
    </div>
    <div>
      <div class="muted" style="font-size:11px">SHIP TO</div>
      <div class="addr">
        <strong><?= $e((string) ($order['shipping_name'] ?? '')) ?></strong><br>
        <?= $e((string) ($address['line1'] ?? '')) ?><br>
        <?= $e(trim((string) ($address['city'] ?? '') . ' ' . (string) ($address['postcode'] ?? ''))) ?><br>
        <?= $e((string) ($address['country'] ?? 'GB')) ?>
      </div>
    </div>
  </div>

  <table>
    <thead>
      <tr><th>SKU</th><th>Item</th><th>Qty</th><th>Unit</th><th>Line</th></tr>
    </thead>
    <tbody>
      <?php if ($items === []): ?>
        <tr><td colspan="5" class="muted">No line items</td></tr>
      <?php else: foreach ($items as $it): ?>
        <tr>
          <td><?= $e((string) ($it['sku'] ?? '')) ?></td>
          <td><?= $e((string) ($it['name'] ?? '')) ?></td>
          <td><?= $e((string) ($it['qty'] ?? 1)) ?></td>
          <td><?= $e(number_format((float) ($it['unit_price'] ?? 0), 2) . ' ' . $currency) ?></td>
          <td><?= $e(number_format((float) ($it['line_total'] ?? 0), 2) . ' ' . $currency) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>

  <div style="margin-top:1rem;text-align:right">
    <div>Shipping: <?= $e(number_format((float) ($order['shipping_cost'] ?? 0), 2) . ' ' . $currency) ?></div>
    <div style="font-size:18px;font-weight:700;margin-top:.35rem">
      Total: <?= $e(number_format((float) ($order['total'] ?? 0), 2) . ' ' . $currency) ?>
    </div>
  </div>
</article>
