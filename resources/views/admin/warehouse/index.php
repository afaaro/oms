<?php
/** @var callable $e */
/** @var callable $url */
/** @var int $ready */
/** @var int $openPickwaves */
/** @var int $despatched */
/** @var int $tracked */
/** @var int $returns */
/** @var string|null $flash */
$openPickwaves = (int) ($openPickwaves ?? 0);
?>
<div class="sf-page-head"><h1>Warehouse</h1></div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<div class="sf-stats" style="grid-template-columns:repeat(5,minmax(0,1fr))">
  <a class="sf-stat" href="<?= $e($url('admin/warehouse/picking')) ?>">
    <div class="label">Ready to pick</div>
    <div class="value"><?= $e((string) $ready) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/warehouse/pickwaves')) ?>">
    <div class="label">Open pickwaves</div>
    <div class="value"><?= $e((string) $openPickwaves) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/warehouse/deliveries')) ?>">
    <div class="label">Despatched</div>
    <div class="value"><?= $e((string) $despatched) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/warehouse/manifests')) ?>">
    <div class="label">With tracking</div>
    <div class="value"><?= $e((string) $tracked) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/warehouse/returns')) ?>">
    <div class="label">Pending returns</div>
    <div class="value"><?= $e((string) ($returns ?? 0)) ?></div>
  </a>
</div>
<div class="sf-quick-actions">
  <a href="<?= $e($url('admin/warehouse/picking')) ?>">Picking</a>
  <a href="<?= $e($url('admin/warehouse/pickwaves')) ?>">Pickwaves</a>
  <a href="<?= $e($url('admin/warehouse/despatch')) ?>">Despatch</a>
  <a href="<?= $e($url('admin/warehouse/deliveries')) ?>">Deliveries</a>
  <a href="<?= $e($url('admin/warehouse/manifests')) ?>">Manifests</a>
  <a href="<?= $e($url('admin/warehouse/stock-locations')) ?>">Stock locations</a>
  <a href="<?= $e($url('admin/warehouse/returns')) ?>">Returns</a>
  <a href="<?= $e($url('admin/warehouse/courier-export')) ?>">Courier file export</a>
</div>
