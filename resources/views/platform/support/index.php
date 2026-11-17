<?php
/** @var callable $e */
/** @var callable $url */
/** @var string $status */
?>
<div class="sf-page-head">
  <h1>Support desk</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('platform/support/new')) ?>">+ New Ticket</a>
</div>

<nav class="sf-views">
  <?php foreach (['open' => 'Open queue', 'all' => 'All', 'pending' => 'Pending', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $k => $lab): ?>
    <a class="<?= $status === $k ? 'is-active' : '' ?>" href="<?= $e($url('platform/support', ['status' => $k])) ?>"><?= $e($lab) ?></a>
  <?php endforeach; ?>
</nav>

<div class="card sf-panel sf-datatable-wrap">
  <table id="support-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/support', ['status' => $status])) ?>"
         data-page-length="25"
         data-order='[[0,"desc"]]'>
    <thead>
      <tr>
        <th>ID</th>
        <th>Tenant</th>
        <th>Subject</th>
        <th>Category</th>
        <th>Priority</th>
        <th>Status</th>
        <th>Updated</th>
      </tr>
    </thead>
  </table>
</div>
