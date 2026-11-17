<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
?>
<div class="sf-page-head">
  <h1>Export history</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/reports')) ?>">&lt;&lt; Insights</a>
</div>

<form method="post" action="<?= $e($url('admin/reports/exports')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;display:flex;gap:1rem;align-items:end;max-width:560px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field" style="flex:1;margin:0"><label>Export name</label><input name="name" value="Orders export"></div>
  <button class="btn btn-primary" type="submit">Run export</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="report-jobs-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/report-jobs', ['type' => 'export'])) ?>"
         data-page-length="25"
         data-order='[[5,"desc"]]'>
    <thead>
      <tr><th>Name</th><th>Type</th><th>Schedule</th><th>Status</th><th>File</th><th>Created</th></tr>
    </thead>
  </table>
</div>
