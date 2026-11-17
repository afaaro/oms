<?php
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Tenants</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('platform/tenants/new')) ?>">+ New Tenant</a>
</div>
<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>
<div class="card sf-panel sf-datatable-wrap">
  <table id="tenants-table" class="table table-hover align-middle sf-grid display nowrap" style="width:100%"
         data-datatable
         data-scroll-x="1"
         data-ajax="<?= $e($url('api/datatable/tenants')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr>
        <th>Company</th>
        <th>Plan</th>
        <th>Status</th>
        <th>Users</th>
        <th>Open tickets</th>
        <th>Contact</th>
        <th></th>
      </tr>
    </thead>
  </table>
</div>
