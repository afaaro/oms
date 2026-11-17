<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $manifests */
/** @var list<array<string,mixed>> $pending */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Manifests</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<section class="card sf-panel" style="padding:1rem;margin-bottom:1rem">
  <h3 style="margin:0 0 .75rem">Create / update open manifest</h3>
  <p class="text-muted" style="margin-top:0">Attaches all unmanifested labelled shipments for the chosen carrier.</p>
  <?php if ($pending !== []): ?>
    <p style="margin:.5rem 0">Pending:
      <?php foreach ($pending as $p): ?>
        <span class="badge" style="margin-right:.35rem"><?= $e((string) $p['carrier_code']) ?> · <?= $e((string) $p['c']) ?></span>
      <?php endforeach; ?>
    </p>
  <?php else: ?>
    <p class="text-muted">No unmanifested shipments waiting.</p>
  <?php endif; ?>
  <form method="post" action="<?= $e($url('admin/warehouse/manifests/create')) ?>" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:end">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <div class="sf-field" style="margin:0">
      <label>Carrier code</label>
      <input name="carrier_code" required placeholder="royal_mail" value="<?= $e((string) (($pending[0]['carrier_code'] ?? 'royal_mail'))) ?>" style="min-width:10rem">
    </div>
    <div class="sf-field" style="margin:0;flex:1">
      <label>Notes</label>
      <input name="notes" placeholder="Optional" style="width:100%">
    </div>
    <button class="btn btn-primary" type="submit">Build manifest</button>
  </form>
</section>

<div class="card sf-panel" style="overflow-x:auto">
  <table class="table table-hover align-middle sf-grid">
    <thead>
      <tr>
        <th>ID</th>
        <th>Reference</th>
        <th>Carrier</th>
        <th>Shipments</th>
        <th>Status</th>
        <th>Created</th>
        <th>Closed</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($manifests === []): ?>
        <tr><td colspan="8" class="text-muted">No manifests yet — despatch orders, then build a manifest.</td></tr>
      <?php else: foreach ($manifests as $m): ?>
        <tr>
          <td><?= $e((string) $m['id']) ?></td>
          <td>
            <?= $e((string) $m['reference']) ?>
            <?php if (preg_match('/(?:RM|DPD|PF) manifest: (\S+)/', (string) ($m['notes'] ?? ''), $rmMatch)): ?>
              <div class="text-muted small"><?= $e($rmMatch[1]) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $e((string) $m['carrier_code']) ?></td>
          <td><?= $e((string) $m['shipment_count']) ?></td>
          <td><span class="badge"><?= $e((string) $m['status']) ?></span></td>
          <td><?= $e((string) $m['created_at']) ?></td>
          <td><?= $e((string) ($m['closed_at'] ?? '—')) ?></td>
          <td style="white-space:nowrap">
            <a class="btn btn-outline-secondary" href="<?= $e($url('admin/documents/manifest', ['id' => $m['id'], 'print' => '1'])) ?>" target="_blank" rel="noopener">Print</a>
            <?php if (($m['status'] ?? '') === 'open'): ?>
              <form method="post" action="<?= $e($url('admin/warehouse/manifests/close')) ?>" style="display:inline">
                <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= $e((string) $m['id']) ?>">
                <button class="btn btn-primary" type="submit">Close</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
