<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
$tab = $tab ?? 'overview';
?>
<div class="sf-page-head">
  <h1>Disputes</h1>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-tabs" style="margin-bottom:1rem">
  <a class="<?= $tab === 'overview' ? 'is-active' : '' ?>" href="<?= $e($url('admin/disputes')) ?>">Overview</a>
  <a class="<?= $tab === 'cases' ? 'is-active' : '' ?>" href="<?= $e($url('admin/disputes', ['tab' => 'cases'])) ?>">Cases</a>
</div>

<p class="text-muted">Cancelled, refunded, on-hold and attention-required orders.</p>
<div class="sf-card sf-datatable-wrap sf-orders-grid-wrap">
  <div class="table-responsive">
    <table id="disputes-table" class="table table-hover align-middle sf-grid sf-orders-grid display mb-0" style="width:100%"
           data-datatable
           data-ajax="<?= $e($url('api/datatable/orders', ['view' => 'disputes'])) ?>"
           data-page-length="25"
           data-order='[[4,"desc"]]'
           data-scroll-x="1"
           data-col-menu="1"
           data-sf-keys='["flags","order","channel","skus","date","postcode","email","total","packaging","packages","shipping","status","weight","pickwave","notes"]'>
      <thead>
        <tr>
<?php
$selectable = false;
require __DIR__ . '/_orders_table_head.php';
?>
        </tr>
      </thead>
    </table>
  </div>
</div>
