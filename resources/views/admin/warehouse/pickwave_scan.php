<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $pickwave */
/** @var list<array<string,mixed>> $lines */
/** @var array{required:int,confirmed:int,lines_done:int,lines_total:int,complete:bool} $summary */
/** @var string|null $flash */
/** @var string|null $error */
$pwId = (int) ($pickwave['id'] ?? 0);
$open = ((string) ($pickwave['status'] ?? '')) === 'open';
?>
<div class="sf-page-head">
  <h1>Scan · <?= $e((string) ($pickwave['reference'] ?? '')) ?></h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/picklist', ['id' => $pwId, 'print' => '1'])) ?>">Pick list</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/pickwaves/view', ['id' => $pwId])) ?>">&lt;&lt; Pickwave</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card sf-panel mb-3" style="padding:1rem">
  <p class="mb-2">
    <strong><?= $e((string) ((int) ($summary['confirmed'] ?? 0))) ?></strong>
    / <?= $e((string) ((int) ($summary['required'] ?? 0))) ?> units ·
    <?= $e((string) ((int) ($summary['lines_done'] ?? 0))) ?>
    / <?= $e((string) ((int) ($summary['lines_total'] ?? 0))) ?> SKUs
    <?php if (!empty($summary['complete'])): ?>
      <span class="badge bg-success">Complete</span>
    <?php endif; ?>
  </p>
  <?php if ($open): ?>
    <form method="post" action="<?= $e($url('admin/warehouse/pickwaves/scan')) ?>" class="d-flex flex-wrap gap-2 align-items-end" style="margin:0">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= $e((string) $pwId) ?>">
      <div class="sf-field" style="margin:0;flex:1;min-width:16rem">
        <label>Scan SKU / barcode</label>
        <input name="code" id="scan-code" autofocus autocomplete="off" placeholder="Scan or type then Enter">
      </div>
      <button class="btn btn-primary" type="submit">Confirm</button>
    </form>
  <?php else: ?>
    <p class="text-muted mb-0">Pickwave is closed — scanning disabled.</p>
  <?php endif; ?>
</div>

<div class="card sf-panel">
  <table class="table table-hover align-middle sf-grid mb-0">
    <thead>
      <tr>
        <th>Bin</th>
        <th>SKU</th>
        <th>Name</th>
        <th class="text-end">Need</th>
        <th class="text-end">Done</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($lines === []): ?>
        <tr><td colspan="6" class="text-muted p-3">No open pick lines.</td></tr>
      <?php else: foreach ($lines as $line):
        $need = (int) ($line['qty'] ?? 0);
        $got = (int) ($line['qty_confirmed'] ?? 0);
        $done = $need > 0 && $got >= $need;
      ?>
        <tr class="<?= $done ? 'table-success' : '' ?>">
          <td><?= $e(($line['pick_bin'] ?? '') !== '' ? (string) $line['pick_bin'] : '—') ?></td>
          <td><code><?= $e((string) ($line['sku'] ?? '')) ?></code></td>
          <td><?= $e((string) ($line['name'] ?? '')) ?></td>
          <td class="text-end"><?= $e((string) $need) ?></td>
          <td class="text-end"><strong><?= $e((string) $got) ?></strong></td>
          <td><?= $done ? '✓' : '' ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<script>
(function () {
  var el = document.getElementById('scan-code');
  if (el) { el.focus(); el.select(); }
})();
</script>
