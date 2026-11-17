<?php
/** @var callable $e */
/** @var callable $url */
/** @var int $methods */
/** @var int $regions */
/** @var int $rules */
?>
<div class="sf-page-head">
  <h1>Shipping Configuration</h1>
</div>
<div class="sf-stats" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <a class="sf-stat" href="<?= $e($url('admin/settings/shipping/methods')) ?>">
    <div class="label">Shipping methods</div>
    <div class="value"><?= $e((string) $methods) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/settings/shipping/regions')) ?>">
    <div class="label">Shipping regions</div>
    <div class="value"><?= $e((string) $regions) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/settings/shipping/rules')) ?>">
    <div class="label">Shipping rules</div>
    <div class="value"><?= $e((string) $rules) ?></div>
  </a>
</div>
<div class="sf-quick-actions">
  <a href="<?= $e($url('admin/settings/shipping/methods')) ?>">Shipping Methods</a>
  <a href="<?= $e($url('admin/settings/shipping/regions')) ?>">Shipping Regions</a>
  <a href="<?= $e($url('admin/settings/shipping/rules')) ?>">Shipping Rules</a>
  <a href="<?= $e($url('admin/settings/shipping/tester')) ?>">Shipping Rules Tester</a>
  <a href="<?= $e($url('admin/settings/shipping/packaging')) ?>">Packaging sizes</a>
</div>
