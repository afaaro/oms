<?php
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $pickwaves */
/** @var string $status */
/** @var string|null $flash */
/** @var string|null $error */
$status = (string) ($status ?? 'completed');
if (!in_array($status, ['all', 'open', 'completed'], true)) {
    $status = 'completed';
}
$pickwaves = $pickwaves ?? [];
$fmt = static function (?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
};
?>
<div class="sf-page-head">
  <h1>Pickwaves</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/warehouse/picking')) ?>">Create from Picking</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<nav class="sf-views">
  <a class="<?= $status === 'completed' ? 'is-active' : '' ?>" href="<?= $e($url('admin/warehouse/pickwaves', ['status' => 'completed'])) ?>">Completed</a>
  <a class="<?= $status === 'open' ? 'is-active' : '' ?>" href="<?= $e($url('admin/warehouse/pickwaves', ['status' => 'open'])) ?>">Open</a>
  <a class="<?= $status === 'all' ? 'is-active' : '' ?>" href="<?= $e($url('admin/warehouse/pickwaves', ['status' => 'all'])) ?>">All</a>
</nav>

<p class="text-muted mb-3">
  <?php if ($status === 'completed'): ?>
    All completed pickwaves.
  <?php elseif ($status === 'open'): ?>
    Open pickwaves ready to pick or despatch.
  <?php else: ?>
    All pickwaves. Create new ones from <a href="<?= $e($url('admin/warehouse/picking')) ?>">Picking</a>.
  <?php endif; ?>
</p>

<div class="card sf-panel">
  <div class="table-responsive">
    <table class="table table-hover align-middle sf-grid mb-0">
      <thead>
        <tr>
          <th>Pickwave ID</th>
          <th>Status</th>
          <th>Despatched/Total</th>
          <th>Warehouse</th>
          <th>Created by</th>
          <th>Started On</th>
          <th>Picked On</th>
          <th>Notes</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($pickwaves === []): ?>
          <tr><td colspan="9" class="text-muted p-3">No pickwaves to display.</td></tr>
        <?php else: foreach ($pickwaves as $pw): ?>
          <?php
            $pwId = (int) $pw['id'];
            $pwStatus = (string) ($pw['status'] ?? '');
            $badge = $pwStatus === 'closed' ? 'badge badge-ok' : 'badge badge-info';
            $statusLabel = $pwStatus === 'closed' ? 'Completed' : ucfirst($pwStatus !== '' ? $pwStatus : 'open');
          ?>
          <tr>
            <td>
              <a href="<?= $e($url('admin/warehouse/pickwaves/view', ['id' => $pwId])) ?>">
                <strong><?= $e((string) $pw['reference']) ?></strong>
              </a>
            </td>
            <td><span class="<?= $e($badge) ?>"><?= $e($statusLabel) ?></span></td>
            <td><?= $e((string) ((int) ($pw['despatched_count'] ?? 0))) ?>/<?= $e((string) ((int) ($pw['order_count'] ?? 0))) ?></td>
            <td><?= $e((string) (($pw['warehouse_name'] ?? '') !== '' ? $pw['warehouse_name'] : 'Default')) ?></td>
            <td><?= $e((string) (($pw['created_by_name'] ?? '') !== '' ? $pw['created_by_name'] : '—')) ?></td>
            <td><?= $e($fmt((string) ($pw['created_at'] ?? ''))) ?></td>
            <td><?= $e($fmt((string) ($pw['closed_at'] ?? ''))) ?></td>
            <td><?= $e((string) (($pw['notes'] ?? '') !== '' ? $pw['notes'] : '—')) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/picklist', ['id' => $pwId, 'print' => '1'])) ?>">Pick list</a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/packinglist', ['id' => $pwId, 'print' => '1'])) ?>">Packing list</a>
              <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/view', ['id' => $pwId])) ?>">Open</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
