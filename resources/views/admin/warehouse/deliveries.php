<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
/** @var string $fulfillment */
$fulfillment = $fulfillment ?? 'all';
$filters = [
    'all'          => 'All',
    'needs_retry'  => 'Needs retry',
    'failed'       => 'Failed',
    'skipped'      => 'Skipped',
    'unsupported'  => 'Unsupported',
    'uploaded'     => 'Uploaded',
    'not_uploaded' => 'Not uploaded',
];
?>
<div class="sf-page-head">
  <h1>Deliveries</h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary btn-sm" href="<?= $e($url('admin/warehouse/manifests')) ?>">Manifests</a>
  <a class="btn btn-outline-secondary btn-sm" href="<?= $e($url('admin/warehouse/courier-export')) ?>">Courier export</a>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<nav class="sf-views">
  <?php foreach ($filters as $key => $label): ?>
    <a class="<?= $fulfillment === $key ? 'is-active' : '' ?>"
       href="<?= $e($url('admin/warehouse/deliveries', $key === 'all' ? [] : ['fulfillment' => $key])) ?>">
      <?= $e($label) ?>
    </a>
  <?php endforeach; ?>
</nav>

<form id="deliveries-bulk-form" method="post" action="<?= $e($url('admin/warehouse/deliveries/retry-fulfillment')) ?>" class="sf-pick-toolbar sf-panel">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="bulk" value="1">
  <div id="orders-bulk-ids"></div>
  <div class="sf-pick-meta">
    <strong id="pick-selected-count">0</strong> selected
  </div>
  <button class="btn btn-primary sf-bulk-btn" type="submit" id="btn-retry-fulfillment" disabled>Retry channel upload</button>
  <button class="btn btn-outline-secondary" type="button" id="btn-clear-selection">Clear</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="shipments-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/shipments', array_filter([
             'fulfillment' => $fulfillment !== 'all' ? $fulfillment : null,
             'select'      => '1',
         ]))) ?>"
         data-sf-keys='["select","order","tracking","carrier","status","channel","manifest","despatched","actions"]'
         data-page-length="25"
         data-order='[[7,"desc"]]'
         data-select="1">
    <thead>
      <tr>
        <th class="sf-col-check"><input type="checkbox" id="sf-select-page" title="Select page" aria-label="Select all on page"></th>
        <th>Order</th>
        <th>Tracking</th>
        <th>Carrier</th>
        <th>Status</th>
        <th>Channel</th>
        <th>Manifest</th>
        <th>Despatched</th>
        <th>Actions</th>
      </tr>
    </thead>
  </table>
</div>
