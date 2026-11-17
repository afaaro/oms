<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $pickwave */
/** @var list<array<string,mixed>> $orders */
/** @var array{required:int,confirmed:int,lines_done:int,lines_total:int,complete:bool}|null $scanSummary */
/** @var string|null $flash */
/** @var string|null $error */
$pwId = (int) ($pickwave['id'] ?? 0);
$open = ((string) ($pickwave['status'] ?? '')) === 'open';
$scanSummary = $scanSummary ?? ['required' => 0, 'confirmed' => 0, 'lines_done' => 0, 'lines_total' => 0, 'complete' => true];
$scanIncomplete = ((int) ($scanSummary['lines_total'] ?? 0) > 0) && empty($scanSummary['complete']);
$openOrderIds = [];
$trackedIds = [];
foreach ($orders as $o) {
    $oid = (int) $o['id'];
    $st = (string) ($o['status'] ?? '');
    if (!in_array($st, ['despatched', 'cancelled', 'refunded'], true)) {
        $openOrderIds[] = $oid;
    }
    if (trim((string) ($o['tracking_number'] ?? '')) !== '') {
        $trackedIds[] = $oid;
    }
}
$labelIds = implode(',', $trackedIds);
?>
<div class="sf-page-head">
  <h1>Pickwave <?= $e((string) ($pickwave['reference'] ?? '')) ?></h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/picklist', ['id' => $pwId, 'print' => '1'])) ?>">Pick list</a>
  <?php if ($open): ?>
    <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/scan', ['id' => $pwId])) ?>">Scan</a>
  <?php endif; ?>
  <?php if ($openOrderIds !== []): ?>
    <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/packinglist', ['id' => $pwId, 'print' => '1'])) ?>">Packing list</a>
  <?php endif; ?>
  <?php if ($open): ?>
    <form method="post" action="<?= $e($url('admin/warehouse/pickwaves/close')) ?>" style="display:inline;margin:0">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= $e((string) $pwId) ?>">
      <button class="btn btn-outline-secondary" type="submit">Close pickwave</button>
    </form>
  <?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves')) ?>">&lt;&lt; Pickwaves</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card sf-panel mb-3" style="padding:1rem">
  <div style="display:flex;flex-wrap:wrap;gap:1.5rem;margin-bottom:1rem">
    <div><span class="text-muted">Status</span><br><strong><?= $e((string) ($pickwave['status'] ?? '')) ?></strong></div>
    <div><span class="text-muted">Orders</span><br><strong><?= $e((string) ((int) ($pickwave['order_count'] ?? 0))) ?></strong></div>
    <div><span class="text-muted">Created</span><br><?= $e((string) ($pickwave['created_at'] ?? '')) ?></div>
    <div><span class="text-muted">By</span><br><?= $e((string) ($pickwave['created_by_name'] ?? '—')) ?></div>
  </div>
  <?php if ($open): ?>
    <form method="post" action="<?= $e($url('admin/warehouse/pickwaves/rename')) ?>" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:end;margin:0 0 1rem">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= $e((string) $pwId) ?>">
      <div class="sf-field" style="margin:0;flex:1;min-width:14rem">
        <label>Name</label>
        <input name="pickwave_name" required maxlength="64" value="<?= $e((string) ($pickwave['reference'] ?? '')) ?>">
      </div>
      <button class="btn btn-outline-secondary" type="submit">Rename</button>
    </form>

    <?php if ($scanIncomplete): ?>
      <p class="text-muted mb-2">Scan progress: <?= $e((string) ((int) $scanSummary['confirmed'])) ?>/<?= $e((string) ((int) $scanSummary['required'])) ?> units confirmed.</p>
    <?php elseif (!empty($scanSummary['complete'])): ?>
      <p class="text-muted mb-2">Scan progress: complete.</p>
    <?php endif; ?>
    <div class="sf-pick-toolbar" style="margin:0;padding:0;border:0;box-shadow:none;background:transparent">
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/picklist', ['id' => $pwId, 'print' => '1'])) ?>">Pick list</a>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/scan', ['id' => $pwId])) ?>">Scan</a>
      <form method="post" action="<?= $e($url('admin/warehouse/pickwaves/mark-printed')) ?>" style="display:inline;margin:0">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= $e((string) $pwId) ?>">
        <button class="btn btn-outline-secondary" type="submit" <?= $openOrderIds === [] ? 'disabled' : '' ?>>Mark printed</button>
      </form>
      <?php if ($labelIds !== ''): ?>
        <a class="btn btn-outline-secondary" href="<?= $e($url('admin/documents/labels', ['ids' => $labelIds, 'print' => '1'])) ?>">Print labels</a>
      <?php endif; ?>
      <?php if ($openOrderIds !== []): ?>
        <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/packinglist', ['id' => $pwId, 'print' => '1'])) ?>">Packing list</a>
      <?php endif; ?>
    </div>

    <form method="post" action="<?= $e($url('admin/warehouse/pickwaves/despatch')) ?>" class="sf-pick-toolbar sf-panel" style="margin-top:1rem">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= $e((string) $pwId) ?>">
      <div class="sf-pick-meta"><strong>Despatch batch</strong></div>
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
      <button class="btn btn-primary" type="submit" <?= $openOrderIds === [] ? 'disabled' : '' ?>
              onclick="return confirm(<?= json_encode(
                  $scanIncomplete
                    ? 'Scan is incomplete (' . (int) $scanSummary['confirmed'] . '/' . (int) $scanSummary['required'] . ' units). Despatch anyway?'
                    : 'Despatch all open orders on this pickwave?',
                  JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
              ) ?>);">Despatch + labels</button>
    </form>
  <?php endif; ?>
</div>

<div class="card sf-panel">
  <table class="table table-hover align-middle sf-grid mb-0">
    <thead>
      <tr>
        <th>Order</th>
        <th>Channel</th>
        <th>Customer</th>
        <th>Email</th>
        <th>Total</th>
        <th>Status</th>
        <?php if ($open): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if ($orders === []): ?>
        <tr><td colspan="<?= $open ? '7' : '6' ?>" class="text-muted p-3">No orders on this pickwave.</td></tr>
      <?php else: foreach ($orders as $o):
        $oid = (int) $o['id'];
        $st = (string) ($o['status'] ?? '');
        $canRemove = $open && !in_array($st, ['despatched', 'cancelled', 'refunded'], true);
      ?>
        <tr>
          <td>
            <a href="<?= $e($url('admin/orders/view', ['id' => $oid])) ?>">
              <?= $e((string) ($o['external_order_id'] !== '' ? $o['external_order_id'] : '#' . $oid)) ?>
            </a>
          </td>
          <td><?= $e((string) ($o['channel_name'] ?? $o['channel_code'] ?? '—')) ?></td>
          <td><?= $e((string) ($o['shipping_name'] ?? $o['buyer_username'] ?? '—')) ?></td>
          <td><?= $e((string) ($o['buyer_email'] ?? '')) ?></td>
          <td><?= $e(number_format((float) ($o['total'] ?? 0), 2)) ?></td>
          <td><span class="badge"><?= $e($st) ?></span></td>
          <?php if ($open): ?>
            <td class="text-end">
              <?php if ($canRemove): ?>
                <form method="post" action="<?= $e($url('admin/warehouse/pickwaves/remove-order')) ?>" style="display:inline;margin:0"
                      onsubmit="return confirm('Remove this order from the pickwave? It will return to Picking.');">
                  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                  <input type="hidden" name="pickwave_id" value="<?= $e((string) $pwId) ?>">
                  <input type="hidden" name="order_id" value="<?= $e((string) $oid) ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                </form>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
