<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $attributes */
/** @var string|null $flash */
/** @var string|null $error */
$attributes = $attributes ?? [];
?>
<div class="sf-page-head d-flex align-items-center gap-2 flex-wrap">
  <h1 class="mb-0">Product Attribute Manager</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/attributes/new')) ?>">New Attribute</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<p class="text-muted mb-3">Define attributes such as Size and Thickness, then attach them on product variant grids.</p>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover table-striped sf-grid mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Code</th>
            <th>Values</th>
            <th>Products</th>
            <th>On variants</th>
            <th>Active</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($attributes === []): ?>
            <tr><td colspan="7" class="text-muted p-3">No attributes yet. Click <strong>New Attribute</strong> to create one.</td></tr>
          <?php else: foreach ($attributes as $a):
            $aid = (int) ($a['id'] ?? 0);
            $acode = (string) ($a['code'] ?? '');
            $locked = in_array($acode, ['size', 'thickness'], true);
          ?>
            <tr>
              <td>
                <a href="<?= $e($url('admin/attributes/edit', ['id' => $aid])) ?>">
                  <strong><?= $e((string) $a['name']) ?></strong>
                </a>
              </td>
              <td><code><?= $e($acode) ?></code></td>
              <td><?= $e((string) ((int) ($a['value_count'] ?? 0))) ?></td>
              <td><?= $e((string) ((int) ($a['product_count'] ?? 0))) ?></td>
              <td><?= !empty($a['show_on_variants']) ? 'Yes' : 'No' ?></td>
              <td>
                <?php if (!empty($a['active'])): ?>
                  <span class="badge text-bg-success">Yes</span>
                <?php else: ?>
                  <span class="badge text-bg-secondary">No</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('admin/attributes/edit', ['id' => $aid])) ?>">Edit</a>
                <?php if (!$locked): ?>
                  <button class="btn btn-sm btn-outline-danger" type="submit" form="delete-attr-<?= $e((string) $aid) ?>"
                          onclick="return confirm('Delete this attribute and its product values?')">Delete</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php foreach ($attributes as $a):
  $aid = (int) ($a['id'] ?? 0);
  $acode = (string) ($a['code'] ?? '');
  if ($aid <= 0 || in_array($acode, ['size', 'thickness'], true)) {
      continue;
  }
?>
  <form method="post" action="<?= $e($url('admin/attributes/delete')) ?>" id="delete-attr-<?= $e((string) $aid) ?>">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= $e((string) $aid) ?>">
  </form>
<?php endforeach; ?>
