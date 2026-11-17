<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed>|null $order */
/** @var list<array<string,mixed>> $items */
/** @var int $orderId */
/** @var string $orderRef */
/** @var string|null $flash */
/** @var string|null $error */
$order = $order ?? null;
$items = $items ?? [];
$orderId = (int) ($orderId ?? 0);
$orderRef = (string) ($orderRef ?? '');
?>
<div class="sf-page-head">
  <h1>New return</h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary btn-sm" href="<?= $e($url('admin/warehouse/returns')) ?>">&lt;&lt; Returns</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-header">Find order</div>
  <div class="card-body">
    <form method="get" action="<?= $e($url('admin/warehouse/returns/new')) ?>" class="row g-2 align-items-end">
      <div class="col-auto">
        <label class="form-label" for="order_ref">Order ID or external ref</label>
        <input class="form-control form-control-sm" id="order_ref" name="order_ref" value="<?= $e($orderRef) ?>" placeholder="e.g. 12345" required>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary btn-sm" type="submit">Load lines</button>
      </div>
    </form>
  </div>
</div>

<?php if ($order !== null): ?>
  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Order #<?= $e((string) $order['id']) ?>
        · <?= $e((string) ($order['external_order_id'] ?? '')) ?>
        · <span class="badge text-bg-secondary"><?= $e((string) ($order['status'] ?? '')) ?></span>
      </span>
      <a href="<?= $e($url('admin/orders/view', ['id' => $order['id']])) ?>">View order</a>
    </div>
    <div class="card-body">
      <p class="text-muted mb-3"><?= $e((string) ($order['shipping_name'] ?? '')) ?> · <?= $e((string) ($order['buyer_email'] ?? '')) ?></p>
      <form method="post" action="<?= $e($url('admin/warehouse/returns/create')) ?>">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="order_id" value="<?= $e((string) $order['id']) ?>">
        <div class="table-responsive mb-3">
          <table class="table table-sm table-bordered align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th scope="col" style="width:2.5rem"></th>
                <th scope="col">SKU</th>
                <th scope="col">Name</th>
                <th scope="col" class="text-end">Ordered</th>
                <th scope="col" class="text-end">Returnable</th>
                <th scope="col" style="width:7rem">Return qty</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($items === []): ?>
                <tr><td colspan="6" class="text-muted">No line items on this order.</td></tr>
              <?php else: foreach ($items as $i => $it):
                $returnable = (int) ($it['qty_returnable'] ?? $it['qty'] ?? 0);
                $lineId = (int) ($it['id'] ?? 0);
              ?>
                <tr>
                  <td class="text-center">
                    <?php if ($returnable > 0): ?>
                      <input class="form-check-input" type="checkbox" name="line_id[<?= $e((string) $i) ?>]" value="<?= $e((string) $lineId) ?>"
                             <?= $returnable > 0 ? 'checked' : '' ?>
                             onchange="var q=this.closest('tr').querySelector('input[type=number]'); if(q){ q.disabled=!this.checked; if(this.checked && (!q.value||q.value==='0')) q.value=q.max; }">
                    <?php endif; ?>
                  </td>
                  <td class="text-nowrap"><?= $e((string) ($it['sku'] ?? '')) ?></td>
                  <td><?= $e((string) ($it['name'] ?? '')) ?></td>
                  <td class="text-end"><?= $e((string) ((int) ($it['qty'] ?? 0))) ?></td>
                  <td class="text-end"><?= $e((string) $returnable) ?></td>
                  <td>
                    <?php if ($returnable > 0): ?>
                      <input class="form-control form-control-sm" type="number" name="line_qty[<?= $e((string) $i) ?>]"
                             min="1" max="<?= $e((string) $returnable) ?>" value="<?= $e((string) $returnable) ?>">
                    <?php else: ?>
                      <span class="text-muted">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="row g-3 align-items-end">
          <div class="col-md-6">
            <label class="form-label" for="return-reason">Reason</label>
            <input class="form-control form-control-sm" id="return-reason" name="reason" placeholder="Customer return" maxlength="255">
          </div>
          <div class="col-auto">
            <button class="btn btn-primary btn-sm" type="submit" <?= $items === [] ? 'disabled' : '' ?>>Create return</button>
          </div>
        </div>
      </form>
    </div>
  </div>
<?php elseif ($orderRef !== '' || $orderId > 0): ?>
  <div class="alert alert-warning">No order found for that reference.</div>
<?php endif; ?>
