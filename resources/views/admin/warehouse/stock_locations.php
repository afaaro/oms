<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Stock locations</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/warehouse/stock-locations')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;display:flex;gap:1rem;align-items:end;max-width:640px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field" style="margin:0"><label>Code</label><input name="code" required placeholder="A-01-01"></div>
  <div class="sf-field" style="flex:1;margin:0"><label>Name</label><input name="name" placeholder="Aisle A bay 1"></div>
  <button class="btn btn-primary" type="submit">Add</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="stock-locations-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/stock-locations')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr><th>Code</th><th>Name</th><th>Qty on hand</th><th>Active</th></tr>
    </thead>
  </table>
</div>
