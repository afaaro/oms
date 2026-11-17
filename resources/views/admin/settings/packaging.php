<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var list<array<string,mixed>> $sizes */
$sizes = $sizes ?? [];
?>
<div class="sf-page-head">
  <h1>Packaging sizes</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/settings/shipping')) ?>">&lt;&lt; Shipping Configuration</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/settings/shipping/packaging')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;display:flex;gap:.75rem;align-items:end;max-width:32rem">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field" style="flex:1;margin:0"><label>Name</label><input name="name" required placeholder="Parcel" maxlength="64"></div>
  <button class="btn btn-primary" type="submit">Add size</button>
</form>

<div class="card sf-panel" style="overflow-x:auto">
  <table class="table table-hover align-middle sf-grid mb-0">
    <thead><tr><th>Name</th></tr></thead>
    <tbody>
      <?php if ($sizes === []): ?>
        <tr><td class="text-muted">No packaging sizes yet. Add Parcel or Large Letter, then attach one to a shipping method.</td></tr>
      <?php else: foreach ($sizes as $size): ?>
        <tr><td><?= $e((string) $size['name']) ?></td></tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
