<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Shipping Methods</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/shipping')) ?>">&lt;&lt; Shipping Configuration</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/settings/shipping/methods')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;display:flex;gap:.75rem;flex-wrap:wrap;align-items:end">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field" style="margin:0"><label>Name</label><input name="name" required placeholder="Royal Mail 48"></div>
  <div class="sf-field" style="margin:0"><label>Carrier</label><input name="carrier" placeholder="royal_mail"></div>
  <div class="sf-field" style="margin:0"><label>Service code</label><input name="service_code" placeholder="48"></div>
  <div class="sf-field" style="margin:0">
    <label>Package size</label>
    <select name="packaging_size_id">
      <option value="">—</option>
      <?php foreach (($sizes ?? []) as $size): ?>
        <option value="<?= $e((string) $size['id']) ?>"><?= $e((string) $size['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary" type="submit">Add method</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="shipping-methods-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-sf-keys='["name","carrier","service","packaging","active"]'
         data-ajax="<?= $e($url('api/datatable/shipping-methods')) ?>"
         data-page-length="25"
         data-order='[[0,"asc"]]'>
    <thead>
      <tr><th>Name</th><th>Carrier</th><th>Service</th><th>Package</th><th>Active</th></tr>
    </thead>
  </table>
</div>
