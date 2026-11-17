<?php
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Suppliers</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/suppliers/new')) ?>">Create supplier</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card sf-panel sf-datatable-wrap">
  <table id="suppliers-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/suppliers')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr>
        <th>Name</th>
        <th>Code</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Active</th>
      </tr>
    </thead>
  </table>
</div>
