<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Shipping Regions</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/shipping')) ?>">&lt;&lt; Shipping Configuration</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/settings/shipping/regions')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field" style="margin:0"><label>Name</label><input name="name" required placeholder="UK Mainland"></div>
  <div class="sf-field" style="margin:0"><label>Countries</label><input name="countries" value="GB" placeholder="GB,IE"></div>
  <div class="sf-field" style="margin:0;min-width:220px"><label>Postcode prefixes</label><input name="postcode_prefixes" placeholder="IV,KW,HS"></div>
  <button class="btn btn-primary" type="submit">Add region</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="shipping-regions-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/shipping-regions')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr><th>Name</th><th>Countries</th><th>Postcode prefixes</th><th>Active</th></tr>
    </thead>
  </table>
</div>
