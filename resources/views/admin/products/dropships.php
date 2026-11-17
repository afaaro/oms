<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Drop shipments</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<div class="card sf-panel sf-datatable-wrap">
  <table id="dropships-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/dropships')) ?>"
         data-sf-keys='["reference","order","supplier","status","created","actions"]'
         data-page-length="25"
         data-order='[[4,"desc"]]'>
    <thead>
      <tr>
        <th>Reference</th>
        <th>Order</th>
        <th>Supplier</th>
        <th>Status</th>
        <th>Created</th>
        <th>Actions</th>
      </tr>
    </thead>
  </table>
</div>
