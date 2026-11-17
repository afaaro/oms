<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string $view */
/** @var string|null $flash */
/** @var string|null $error */
$view = $view ?? 'all';
$dateCol = 5; // Order Date (after checkbox)
?>
<div class="sf-page-head">
  <h1>Orders</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/orders/moto')) ?>">+ New Order</a>
</div>

<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<nav class="sf-views">
  <a class="<?= $view === 'all' ? 'is-active' : '' ?>" href="<?= $e($url('admin/orders')) ?>">All</a>
  <a class="<?= $view === 'ready' ? 'is-active' : '' ?>" href="<?= $e($url('admin/orders', ['view' => 'ready'])) ?>">Ready to pick</a>
  <a class="<?= $view === 'priority' ? 'is-active' : '' ?>" href="<?= $e($url('admin/orders', ['view' => 'priority'])) ?>">Priority</a>
  <a class="<?= $view === 'today' ? 'is-active' : '' ?>" href="<?= $e($url('admin/orders', ['view' => 'today'])) ?>">Placed today</a>
  <a class="<?= $view === 'back' ? 'is-active' : '' ?>" href="<?= $e($url('admin/orders', ['view' => 'back'])) ?>">Back orders</a>
</nav>

<form id="orders-bulk-form" method="post" action="<?= $e($url('admin/orders/bulk')) ?>" class="sf-pick-toolbar sf-panel">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="return_view" value="<?= $e($view) ?>">
  <input type="hidden" name="bulk_action" id="orders-bulk-action" value="">
  <div id="orders-bulk-ids"></div>
  <div class="sf-pick-meta">
    <strong id="pick-selected-count">0</strong> orders selected
  </div>
  <button class="btn sf-bulk-btn" type="submit" data-action="printed" disabled>Mark printed</button>
  <button class="btn sf-bulk-btn" type="submit" data-action="despatch" disabled>Despatch</button>
  <button class="btn sf-bulk-btn" type="submit" data-action="hold" disabled>On hold</button>
  <button class="btn sf-bulk-btn" type="submit" data-action="release" disabled>Release hold</button>
  <button class="btn btn-outline-secondary" type="button" id="btn-clear-selection">Clear</button>
</form>

<div class="sf-card sf-datatable-wrap sf-orders-grid-wrap">
  <div class="table-responsive">
    <table id="orders-table" class="table table-hover align-middle sf-grid sf-orders-grid display mb-0" style="width:100%"
           data-datatable
           data-ajax="<?= $e($url('api/datatable/orders', ['view' => $view, 'select' => '1'])) ?>"
           data-page-length="25"
           data-order='[[<?= (int) $dateCol ?>,"desc"]]'
           data-select="1"
           data-scroll-x="1"
           data-col-menu="1"
           data-sf-keys='["flags","order","channel","skus","date","postcode","email","total","packaging","packages","shipping","status","weight","pickwave","notes"]'>
      <thead>
        <tr>
<?php
$selectable = true;
require __DIR__ . '/_orders_table_head.php';
?>
        </tr>
      </thead>
    </table>
  </div>
</div>
