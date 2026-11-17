<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $suppliers */
/** @var string|null $flash */
/** @var string|null $error */
$suppliers = $suppliers ?? [];
?>
<div class="sf-page-head">
  <h1>Purchase order import</h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/purchase-orders/new')) ?>">Create manually</a>
  <a href="<?= $e($url('admin/purchase-orders')) ?>">&lt;&lt; Purchase orders</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="card sf-panel" style="padding:1rem;max-width:48rem">
  <p class="text-muted">Paste CSV lines: <code>SKU,Qty</code> or <code>SKU,Qty,Name,Unit cost</code>. Header row optional. Unknown SKUs are skipped.</p>
  <form method="post" action="<?= $e($url('admin/purchase-orders/import')) ?>">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <div class="sf-field">
      <label>Supplier</label>
      <select name="supplier_id" required>
        <option value="">Select supplier…</option>
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= $e((string) ((int) $s['id'])) ?>"><?= $e((string) ($s['name'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field">
      <label>Reference <span class="text-muted">(optional)</span></label>
      <input name="reference" maxlength="64" placeholder="Auto if blank">
    </div>
    <div class="sf-field">
      <label>CSV</label>
      <textarea name="csv" rows="12" required placeholder="SKU,Qty,Name,Unit cost&#10;ABC-1,10,,2.50&#10;ABC-2,5"></textarea>
    </div>
    <label class="sf-check" style="display:block;margin-bottom:1rem">
      <input type="checkbox" name="status_ordered" value="1"> Create as ordered (not draft)
    </label>
    <button class="btn btn-primary" type="submit">Import purchase order</button>
  </form>
</div>
