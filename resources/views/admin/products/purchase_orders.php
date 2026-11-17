<?php
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Purchase orders</h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/suppliers')) ?>">Suppliers</a>
  <a class="btn btn-primary" href="<?= $e($url('admin/purchase-orders/new')) ?>">Create PO</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card sf-panel sf-datatable-wrap">
  <table id="po-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/purchase-orders')) ?>"
         data-page-length="25"
         data-order='[[0,"desc"]]'>
    <thead>
      <tr>
        <th>Reference</th>
        <th>Supplier</th>
        <th>Status</th>
        <th>Total</th>
        <th>Expected</th>
      </tr>
    </thead>
  </table>
</div>
