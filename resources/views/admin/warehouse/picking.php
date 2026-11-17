<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string $tab */
/** @var array<string,int> $stats */
/** @var list<array<string,mixed>> $pickwaves */
/** @var string $company */
/** @var string|null $flash */
/** @var string|null $error */
$tab = (string) ($tab ?? 'open');
if (!in_array($tab, ['open', 'recent'], true)) {
    $tab = 'open';
}
$stats = $stats ?? [];
$pickwaves = $pickwaves ?? [];
$fmt = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
};
$stat = static function (array $stats, string $key): int {
    return (int) ($stats[$key] ?? 0);
};
$wavesLabel = $tab === 'recent' ? 'Recent completed' : 'Open';
?>
<div class="sf-page-head">
  <h1>Picking</h1>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-picking">
  <aside class="sf-picking-nav card sf-panel">
    <a href="<?= $e($url('admin/warehouse')) ?>">Overview</a>
    <a class="<?= $tab === 'open' ? 'is-active' : '' ?>" href="<?= $e($url('admin/warehouse/picking', ['tab' => 'open'])) ?>">Open Pickwaves</a>
    <a href="<?= $e($url('admin/warehouse/pickwaves', ['status' => 'completed'])) ?>">Completed Pickwaves</a>
  </aside>

  <div class="sf-picking-main">
    <section class="sf-picking-top">
      <table class="sf-picking-stats">
        <thead><tr><th colspan="2">Stats</th></tr></thead>
        <tbody>
          <tr><td>Total Outstanding Orders</td><td><?= $e((string) $stat($stats, 'outstanding')) ?></td></tr>
          <tr><td>Total Open Pickwaves</td><td><?= $e((string) $stat($stats, 'open_waves')) ?></td></tr>
          <tr><td>Orders Picked Today</td><td><?= $e((string) $stat($stats, 'picked_today')) ?></td></tr>
          <tr><td>Orders Picked Last 48 Hours</td><td><?= $e((string) $stat($stats, 'picked_48h')) ?></td></tr>
          <tr><td>Orders Picked Last Week</td><td><?= $e((string) $stat($stats, 'picked_week')) ?></td></tr>
          <tr><td>Avg. Orders Per Picker</td><td><?= $e((string) $stat($stats, 'avg_orders')) ?></td></tr>
          <tr><td>Avg. Time To Pick (minutes)</td><td><?= $e((string) $stat($stats, 'avg_minutes')) ?></td></tr>
          <tr><td>Orders Remaining To Pick</td><td><?= $e((string) $stat($stats, 'remaining')) ?></td></tr>
        </tbody>
      </table>

      <div class="card sf-panel sf-picking-waves">
        <div class="sf-picking-tabs">
          <a class="<?= $tab === 'open' ? 'is-active' : '' ?>" href="<?= $e($url('admin/warehouse/picking', ['tab' => 'open'])) ?>">Open Pickwaves</a>
          <a class="<?= $tab === 'recent' ? 'is-active' : '' ?>" href="<?= $e($url('admin/warehouse/picking', ['tab' => 'recent'])) ?>">Completed Pickwaves</a>
        </div>
        <?php if ($tab === 'recent'): ?>
          <p class="sf-picking-waves-note text-muted mb-2">Showing the 10 most recent completed pickwaves. <a href="<?= $e($url('admin/warehouse/pickwaves', ['status' => 'completed'])) ?>">View all completed</a></p>
        <?php endif; ?>
        <div class="table-responsive">
          <table class="table table-hover align-middle sf-grid mb-0">
            <thead>
              <tr>
                <th>Pickwave ID</th>
                <th>Despatched/Total Orders</th>
                <th>Assigned</th>
                <th>Warehouse</th>
                <th>Picker</th>
                <th>Tag</th>
                <th>Started On</th>
                <th>Picked On</th>
                <th>Picking List</th>
                <th>Quick Despatch</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($pickwaves === []): ?>
                <tr>
                  <td colspan="10" class="text-center text-muted py-3">
                    There are no <?= $e($wavesLabel) ?> Pickwaves to display.
                  </td>
                </tr>
              <?php else: foreach ($pickwaves as $pw): ?>
                <?php $pwId = (int) $pw['id']; ?>
                <tr>
                  <td>
                    <a href="<?= $e($url('admin/warehouse/pickwaves/view', ['id' => $pwId])) ?>">
                      <strong><?= $e((string) $pw['reference']) ?></strong>
                    </a>
                  </td>
                  <td><?= $e((string) ((int) ($pw['despatched_count'] ?? 0))) ?>/<?= $e((string) ((int) ($pw['order_count'] ?? 0))) ?></td>
                  <td><?= $e((string) (($pw['created_by_name'] ?? '') !== '' ? $pw['created_by_name'] : '—')) ?></td>
                  <td><?= $e((string) (($pw['warehouse_name'] ?? '') !== '' ? $pw['warehouse_name'] : 'Default')) ?></td>
                  <td>—</td>
                  <td><?= $e((string) (($pw['notes'] ?? '') !== '' ? $pw['notes'] : '—')) ?></td>
                  <td><?= $e($fmt((string) ($pw['created_at'] ?? ''))) ?></td>
                  <td><?= $e($fmt((string) ($pw['closed_at'] ?? ''))) ?></td>
                  <td><a href="<?= $e($url('admin/warehouse/pickwaves/picklist', ['id' => $pwId, 'print' => '1'])) ?>">Pick list</a></td>
                  <td><a href="<?= $e($url('admin/warehouse/pickwaves/view', ['id' => $pwId])) ?>">Despatch</a></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <h2 class="sf-picking-orders-title">Outstanding Orders</h2>
    <form id="pickwave-form" method="post" action="<?= $e($url('admin/warehouse/pickwave')) ?>" class="sf-picking-create">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <div id="pickwave-ids"></div>
      <label>
        Create pickwave
        <input name="pickwave_name" type="text" maxlength="64" placeholder="">
      </label>
      <button class="btn btn-sm" type="submit" id="btn-create-pickwave" disabled>Confirm</button>
      <span class="sf-picking-selected"><strong id="pick-selected-count">0</strong> selected</span>
      <button class="btn btn-sm btn-outline-secondary" type="button" id="btn-clear-selection">Clear</button>
    </form>

    <div class="card sf-panel sf-datatable-wrap">
      <table id="picking-orders-table" class="table table-hover align-middle sf-grid display" style="width:100%"
             data-datatable
             data-scroll-x="1"
             data-ajax="<?= $e($url('api/datatable/orders', ['view' => 'picking', 'layout' => 'picking', 'select' => '1'])) ?>"
             data-sf-keys='["select","warehouse","company","channel","flags","order","channel_ref","date","ship_by","skus","brand","package_size","packages","weight","total","method","postcode"]'
             data-page-length="25"
             data-order='[[7,"desc"]]'
             data-select="1"
             data-col-menu="1">
        <thead>
          <tr>
            <th class="sf-col-check"><input type="checkbox" id="sf-select-page" title="Select page" aria-label="Select all on page"></th>
            <th>Warehouse</th>
            <th>Company Identity</th>
            <th>Channel</th>
            <th>Order Flags</th>
            <th>Order Number</th>
            <th>Channel Order Ref</th>
            <th>Order Date</th>
            <th>Ship By Date</th>
            <th>Product SKUs</th>
            <th>Brand</th>
            <th>Package Size</th>
            <th>Packages</th>
            <th>Order Weight</th>
            <th>Total</th>
            <th>Shipping Method</th>
            <th>Postcode</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>
</div>
