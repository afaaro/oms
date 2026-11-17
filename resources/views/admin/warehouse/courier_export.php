<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array{carrier_code:string,c:int}> $carriers */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Courier file export</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/warehouse')) ?>">&lt;&lt; Warehouse</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card sf-panel" style="padding:1rem;max-width:640px">
  <p style="margin-top:0">Download a CSV of labelled / despatched shipments for courier upload or archive.</p>
  <form method="get" action="<?= $e($url('admin/warehouse/courier-export/download')) ?>" style="display:grid;gap:.85rem">
    <div class="sf-field">
      <label>Carrier</label>
      <select name="carrier">
        <option value="">All carriers</option>
        <?php foreach ($carriers as $c): ?>
          <option value="<?= $e((string) $c['carrier_code']) ?>">
            <?= $e((string) $c['carrier_code']) ?> (<?= $e((string) $c['c']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem">
      <div class="sf-field"><label>From date</label><input type="date" name="from" value="<?= $e(date('Y-m-d', strtotime('-7 days'))) ?>"></div>
      <div class="sf-field"><label>To date</label><input type="date" name="to" value="<?= $e(date('Y-m-d')) ?>"></div>
    </div>
    <div>
      <button class="btn btn-primary" type="submit">Download CSV</button>
      <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/manifests')) ?>">Manifests</a>
    </div>
  </form>
</div>
