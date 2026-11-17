<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Bulk inventory update</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<form method="post" action="<?= $e($url('admin/inventory/bulk')) ?>" class="card sf-panel sf-panel-solo" style="padding:1.1rem;max-width:720px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <p class="text-muted">Paste CSV lines as <code>SKU,qty</code> (header optional).</p>
  <div class="sf-field"><label>CSV</label><textarea name="csv" rows="12" placeholder="sku,qty&#10;ABC-001,25"></textarea></div>
  <button class="btn btn-primary" type="submit">Update stock</button>
</form>
