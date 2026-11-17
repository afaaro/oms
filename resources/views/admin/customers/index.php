<?php
/** @var callable $e */
/** @var callable $url */
/** @var string $csrf */
$flash = $flash ?? null;
$error = $error ?? null;
?>
<div class="sf-page-head">
  <h1>Customers</h1>
  <form method="post" action="<?= $e($url('admin/customers/sync')) ?>" style="display:inline">
    <input type="hidden" name="_token" value="<?= $e((string) $csrf) ?>">
    <button class="btn btn-primary" type="submit">Sync from orders</button>
  </form>
</div>

<?php if (!empty($flash)): ?>
  <div class="alert alert-success"><?= $e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger"><?= $e((string) $error) ?></div>
<?php endif; ?>

<div class="card sf-panel sf-datatable-wrap">
  <table id="customers-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/customers')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Postcode</th>
        <th>Created</th>
      </tr>
    </thead>
  </table>
</div>
