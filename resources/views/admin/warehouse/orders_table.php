<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string $heading */
/** @var string $ajaxView */
/** @var bool $actions */
/** @var string $actionMode */
/** @var string|null $flash */
$actions = !empty($actions);
$actionMode = $actionMode ?? 'pick';
$selectable = $actions && $actionMode === 'pick';
$dateCol = $selectable ? 5 : 4;
?>
<div class="sf-page-head">
  <h1><?= $e($heading) ?></h1>
  <div class="spacer"></div>
  <?php if (!empty($selectable)): ?>
    <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves')) ?>">View pickwaves</a>
  <?php endif; ?>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<?php if ($selectable): ?>
<form id="pickwave-form" method="post" action="<?= $e($url('admin/warehouse/pickwave')) ?>" class="sf-pick-toolbar sf-panel">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div id="pickwave-ids"></div>
  <div class="sf-pick-meta">
    <strong id="pick-selected-count">0</strong> orders selected
  </div>
  <label class="sf-field" style="margin:0;min-width:12rem">
    <span class="text-muted" style="font-size:.75rem">Pickwave name (optional)</span>
    <input name="pickwave_name" type="text" maxlength="64" placeholder="e.g. Morning wave" style="min-width:12rem">
  </label>
  <button class="btn btn-primary" type="submit" id="btn-create-pickwave" disabled>Create pickwave</button>
  <button class="btn" type="submit" formaction="<?= $e($url('admin/warehouse/mark-printed')) ?>" id="btn-mark-printed" disabled>Mark printed</button>
  <button class="btn btn-outline-secondary" type="button" id="btn-clear-selection">Clear</button>
</form>
<?php endif; ?>

<div class="card sf-panel sf-datatable-wrap sf-orders-grid-wrap">
  <table id="warehouse-orders-table" class="table table-hover align-middle sf-grid sf-orders-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/orders', ['view' => $ajaxView, 'select' => $selectable ? '1' : ''])) ?>"
         data-page-length="25"
         data-order='[[<?= (int) $dateCol ?>,"desc"]]'
         <?= $selectable ? 'data-select="1"' : '' ?>
         data-scroll-x="1"
         data-col-menu="1"
         data-sf-keys='["flags","order","channel","skus","date","postcode","email","total","packaging","packages","shipping","status","weight","pickwave","notes"]'>
    <thead>
      <tr>
<?php require dirname(__DIR__) . '/orders/_orders_table_head.php'; ?>
      </tr>
    </thead>
  </table>
</div>
