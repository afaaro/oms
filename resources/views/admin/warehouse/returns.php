<?php
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Return order items</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary btn-sm" href="<?= $e($url('admin/warehouse/returns/new')) ?>">New return</a>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<div class="card sf-panel sf-datatable-wrap">
  <table id="returns-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/returns')) ?>"
         data-page-length="25"
         data-order='[[5,"desc"]]'>
    <thead>
      <tr><th>Order</th><th>SKU</th><th>Qty</th><th>Reason</th><th>Status</th><th>Date</th><th>Update</th></tr>
    </thead>
  </table>
</div>
