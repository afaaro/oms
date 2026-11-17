<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Category manager</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/categories')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;display:flex;gap:1rem;align-items:end;max-width:560px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field" style="flex:1;margin:0"><label>New category</label><input name="name" required></div>
  <button class="btn btn-primary" type="submit">Add</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="categories-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/categories')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr><th>Name</th><th>Products</th><th>Active</th><th>Created</th></tr>
    </thead>
  </table>
</div>
