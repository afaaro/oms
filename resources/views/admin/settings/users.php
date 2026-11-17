<?php
/** @var callable $e */
/** @var callable $url */
?>
<div class="sf-page-head">
  <h1>Users</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/settings/users/new')) ?>">New User</a>
</div>
<div class="card sf-panel sf-datatable-wrap">
  <table id="users-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/users')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Last Logged In</th>
        <th>Role</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
  </table>
</div>
