<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $methods */
/** @var list<array<string,mixed>> $regions */
/** @var list<array<string,mixed>> $channels */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1>Shipping Rules</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/shipping')) ?>">&lt;&lt; Shipping Configuration</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/settings/shipping/rules')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem">
    <div class="sf-field" style="margin:0"><label>Rule name</label><input name="name" required></div>
    <div class="sf-field" style="margin:0"><label>Priority</label><input name="priority" type="number" value="100"></div>
    <div class="sf-field" style="margin:0"><label>Match channel shipping text</label><input name="match_shipping_method" placeholder="Royal Mail 48"></div>
    <div class="sf-field" style="margin:0">
      <label>Channel</label>
      <select name="channel_id"><option value="">Any</option>
        <?php foreach ($channels as $c): ?><option value="<?= $e((string) $c['id']) ?>"><?= $e((string) $c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field" style="margin:0">
      <label>Region</label>
      <select name="region_id"><option value="">Any</option>
        <?php foreach ($regions as $r): ?><option value="<?= $e((string) $r['id']) ?>"><?= $e((string) $r['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field" style="margin:0">
      <label>Mapped method</label>
      <select name="method_id"><option value="">—</option>
        <?php foreach ($methods as $m): ?><option value="<?= $e((string) $m['id']) ?>"><?= $e((string) $m['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field" style="margin:0"><label>Weight min (g)</label><input name="weight_min_g" type="number" value="0"></div>
    <div class="sf-field" style="margin:0"><label>Weight max (g)</label><input name="weight_max_g" type="number" value="0"><div class="text-muted">0 = no max</div></div>
  </div>
  <button class="btn btn-primary" type="submit" style="margin-top:.85rem">Add rule</button>
</form>

<div class="card sf-panel sf-datatable-wrap">
  <table id="shipping-rules-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/shipping-rules')) ?>"
         data-page-length="25"
         data-order='[[1,"asc"]]'>
    <thead>
      <tr><th>Name</th><th>Priority</th><th>Channel</th><th>Region</th><th>Method</th><th>Match text</th><th>Weight</th><th>Active</th></tr>
    </thead>
  </table>
</div>
