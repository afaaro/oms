<?php
/** @var callable $e */
/** @var array<string,mixed> $pickwave */
/** @var list<array{product_id:int,sku:string,name:string,pick_bin:string,qty:int,order_count:int}> $lines */
/** @var int $orderCount */
$ref = (string) ($pickwave['reference'] ?? '');
$created = (string) ($pickwave['created_at'] ?? '');
$totalQty = 0;
foreach ($lines as $line) {
    $totalQty += (int) ($line['qty'] ?? 0);
}
?>
<article class="sf-print-sheet">
  <div class="meta">
    <div>
      <h1>Pick list</h1>
      <div class="muted"><?= $e($ref) ?></div>
    </div>
    <div style="text-align:right">
      <div><strong><?= $e((string) $orderCount) ?> order<?= $orderCount === 1 ? '' : 's' ?></strong></div>
      <div class="muted"><?= $e($created) ?></div>
      <div class="muted"><?= $e((string) count($lines)) ?> SKU line<?= count($lines) === 1 ? '' : 's' ?> · <?= $e((string) $totalQty) ?> units</div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th style="width:6rem">Bin</th>
        <th style="width:8rem">SKU</th>
        <th>Name</th>
        <th style="width:4rem;text-align:right">Qty</th>
        <th style="width:5rem;text-align:right">Orders</th>
        <th style="width:4rem">✓</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($lines === []): ?>
        <tr><td colspan="6" class="muted">No open pick lines on this wave.</td></tr>
      <?php else: foreach ($lines as $line): ?>
        <tr>
          <td><strong><?= $e(($line['pick_bin'] ?? '') !== '' ? (string) $line['pick_bin'] : '—') ?></strong></td>
          <td><?= $e((string) ($line['sku'] ?? '')) ?></td>
          <td><?= $e((string) ($line['name'] ?? '')) ?></td>
          <td style="text-align:right"><strong><?= $e((string) ((int) ($line['qty'] ?? 0))) ?></strong></td>
          <td style="text-align:right"><?= $e((string) ((int) ($line['order_count'] ?? 0))) ?></td>
          <td>☐</td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</article>
