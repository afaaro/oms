<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Despatch order / pickwave</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<form id="despatch-form" method="post" action="<?= $e($url('admin/warehouse/despatch')) ?>" class="sf-pick-toolbar sf-panel">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div id="despatch-ids"></div>
  <div class="sf-pick-meta">
    <strong id="pick-selected-count">0</strong> orders selected
  </div>
  <div class="sf-field" style="margin:0">
    <label>Tracking prefix</label>
    <input name="tracking_prefix" value="RM" style="width:6rem">
  </div>
  <div class="sf-field" style="margin:0">
    <label>Carrier</label>
    <input name="carrier_code" value="royal_mail" style="width:9rem">
  </div>
  <label class="sf-check" style="margin:0;padding-bottom:.35rem">
    <input type="checkbox" name="apply_shipping_rules" value="1" checked> Apply shipping rules
  </label>
  <label class="sf-check" style="margin:0;padding-bottom:.35rem">
    <input type="checkbox" name="buy_rm_label" value="1" checked> Buy carrier label
  </label>
  <label class="sf-check" style="margin:0;padding-bottom:.35rem">
    <input type="checkbox" name="print_labels" value="1" checked> Open labels after despatch
  </label>
  <button class="btn btn-primary" type="submit" id="btn-despatch-selected" disabled>Despatch + labels</button>
  <button class="btn btn-outline-secondary" type="button" id="btn-clear-selection">Clear</button>
</form>

<div class="card sf-panel sf-datatable-wrap sf-orders-grid-wrap">
  <table id="warehouse-orders-table" class="table table-hover align-middle sf-grid sf-orders-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/orders', ['view' => 'despatch', 'select' => '1'])) ?>"
         data-page-length="25"
         data-order='[[5,"desc"]]'
         data-select="1"
         data-scroll-x="1"
         data-col-menu="1"
         data-sf-keys='["flags","order","channel","skus","date","postcode","email","total","packaging","packages","shipping","status","weight","pickwave","notes"]'>
    <thead>
      <tr>
<?php
$selectable = true;
require dirname(__DIR__) . '/orders/_orders_table_head.php';
?>
      </tr>
    </thead>
  </table>
</div>
