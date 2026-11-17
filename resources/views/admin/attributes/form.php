<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed>|null $attribute */
/** @var list<array<string,mixed>> $values */
/** @var string|null $flash */
/** @var string|null $error */
$editing = is_array($attribute);
$a = $attribute ?? [];
$val = static fn(string $k, string $d = ''): string => (string) ($a[$k] ?? $d);
$action = $editing ? $url('admin/attributes/save') : $url('admin/attributes/new');
$locked = $editing && in_array($val('code'), ['size', 'thickness'], true);
?>
<div class="sf-page-head d-flex align-items-center gap-2 flex-wrap">
  <h1 class="mb-0"><?= $editing ? 'Edit attribute' : 'New attribute' ?></h1>
  <div class="spacer"></div>
  <button class="btn btn-primary" type="submit" form="attribute-form"><?= $editing ? 'Save' : 'Create' ?></button>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/attributes')) ?>">&lt;&lt; Attribute Manager</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<p class="text-muted mb-3">Size and Thickness already exist — open them from Attribute Manager instead of creating again.</p>

<form method="post" action="<?= $e($action) ?>" id="attribute-form" class="card mb-3" style="max-width:720px">
  <div class="card-body">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $e($val('id')) ?>"><?php endif; ?>

    <div class="row g-3">
      <div class="col-12">
        <label class="form-label">Name *</label>
        <input class="form-control" name="name" required value="<?= $e($val('name')) ?>" placeholder="e.g. Size">
      </div>
      <div class="col-md-6">
        <label class="form-label">Code</label>
        <?php if ($locked): ?>
          <input class="form-control" type="text" value="<?= $e($val('code')) ?>" readonly>
          <input type="hidden" name="code" value="<?= $e($val('code')) ?>">
        <?php else: ?>
          <input class="form-control" name="code" value="<?= $e($val('code')) ?>" placeholder="auto from name">
        <?php endif; ?>
        <div class="form-text">Used internally (size, thickness, colour…). Leave blank to auto-generate.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label">Sort order</label>
        <input class="form-control" name="sort_order" type="number" step="1" value="<?= $e($val('sort_order', '0')) ?>">
      </div>
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="show_on_variants" name="show_on_variants" value="1"
                 <?= $editing && !empty($a['show_on_variants']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="show_on_variants">Suggested for variants</label>
        </div>
      </div>
      <div class="col-md-6">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="attr_active" name="active" value="1"
                 <?= !$editing || !empty($a['active']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="attr_active">Active</label>
        </div>
      </div>
    </div>

    <div class="mt-4 d-flex gap-2 flex-wrap align-items-center">
      <button class="btn btn-primary" type="submit"><?= $editing ? 'Save attribute' : 'Create attribute' ?></button>
      <?php if ($editing && !$locked): ?>
        <button class="btn btn-outline-danger" type="submit" form="delete-attribute-form"
                onclick="return confirm('Delete this attribute and its product values?')">Delete attribute</button>
      <?php elseif ($editing): ?>
        <span class="text-muted small">System attribute — cannot delete</span>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php if ($editing && !$locked): ?>
<form method="post" action="<?= $e($url('admin/attributes/delete')) ?>" id="delete-attribute-form">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
</form>
<?php endif; ?>

<?php if ($editing): ?>
<section class="card" style="max-width:720px">
  <div class="card-header">Suggested values</div>
  <div class="card-body">
    <p class="text-muted small mb-3">Optional presets shown when editing product variants (free text still allowed).</p>

    <form method="post" action="<?= $e($url('admin/attributes/value/add')) ?>" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="attribute_id" value="<?= $e($val('id')) ?>">
      <div class="col-md-7">
        <label class="form-label">New value</label>
        <input class="form-control" name="value" required placeholder="e.g. 8mm">
      </div>
      <div class="col-md-2">
        <label class="form-label">Sort</label>
        <input class="form-control" name="sort_order" type="number" value="0">
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary w-100" type="submit">Add value</button>
      </div>
    </form>

    <?php if ($values === []): ?>
      <p class="text-muted mb-0">No suggested values yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Value</th><th style="width:80px">Sort</th><th style="width:70px"></th></tr>
          </thead>
          <tbody>
            <?php foreach ($values as $v): ?>
              <tr>
                <td><?= $e((string) $v['value']) ?></td>
                <td><?= $e((string) ($v['sort_order'] ?? 0)) ?></td>
                <td>
                  <form method="post" action="<?= $e($url('admin/attributes/value/delete')) ?>" class="m-0">
                    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                    <input type="hidden" name="attribute_id" value="<?= $e($val('id')) ?>">
                    <input type="hidden" name="value_id" value="<?= $e((string) $v['id']) ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit" title="Remove">×</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
