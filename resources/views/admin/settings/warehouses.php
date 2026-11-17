<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
?>
<div class="sf-page-head"><h1>Warehouses</h1></div>
<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>

<form method="post" action="<?= $e($url('admin/settings/warehouses')) ?>" class="card sf-panel sf-panel-solo" style="padding:1rem;margin-bottom:1rem">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;align-items:end">
    <div class="sf-field" style="margin:0"><label>Name</label><input name="name" required></div>
    <div class="sf-field" style="margin:0"><label>Code</label><input name="code"></div>
    <div class="sf-field" style="margin:0"><label>Company</label><input name="company"></div>
    <div class="sf-field" style="margin:0"><label>Address 1</label><input name="address_line1"></div>
    <div class="sf-field" style="margin:0"><label>Address 2</label><input name="address_line2"></div>
    <div class="sf-field" style="margin:0"><label>City</label><input name="city"></div>
    <div class="sf-field" style="margin:0"><label>County</label><input name="county"></div>
    <div class="sf-field" style="margin:0"><label>Country</label><input name="country" value="GB"></div>
    <div class="sf-field" style="margin:0"><label>Postcode</label><input name="postcode"></div>
  </div>
  <p style="margin:.85rem 0 0"><button class="btn btn-primary" type="submit">+ Add Warehouse</button></p>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="warehouses-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/warehouses')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr><th>Name</th><th>Code</th><th>Default</th><th>Active</th></tr>
    </thead>
  </table>
</div>
