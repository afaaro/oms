<?php
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Product import export</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<div class="sf-home-grid">
  <div class="sf-card">
    <h2>Export</h2>
    <p class="text-muted">Download the current product catalogue as CSV via Insights export history, or use bulk inventory for stock-only updates.</p>
    <a class="btn btn-primary" href="<?= $e($url('admin/reports/exports')) ?>">Open export history</a>
  </div>
  <div class="sf-card">
    <h2>Import</h2>
    <p class="text-muted">Create products individually, or update on-hand quantities in bulk.</p>
    <div class="sf-quick-actions" style="margin-top:1rem">
      <a href="<?= $e($url('admin/products/new')) ?>">Create product</a>
      <a href="<?= $e($url('admin/inventory/bulk')) ?>">Bulk inventory</a>
    </div>
  </div>
</div>
