<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $rules */
/** @var list<string> $types */
/** @var list<string> $actions */
/** @var string|null $flash */
/** @var string|null $error */
$rules = $rules ?? [];
$types = $types ?? [];
$actions = $actions ?? [];
?>
<div class="sf-page-head">
  <h1>Order rules</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/orders')) ?>">&lt;&lt; Orders</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<p class="text-muted">After sync, matching orders are put on hold or flagged for attention.</p>

<div class="sf-card">
  <h2>Add rule</h2>
  <form method="post" action="<?= $e($url('admin/orders/rules')) ?>" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto auto;gap:1rem;align-items:end">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <div class="sf-field mb-0"><label>Name</label><input name="name" placeholder="e.g. High value hold"></div>
    <div class="sf-field mb-0">
      <label>Type</label>
      <select name="rule_type">
        <?php foreach ($types as $t): ?>
          <option value="<?= $e($t) ?>"><?= $e(str_replace('_', ' ', $t)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field mb-0">
      <label>Action</label>
      <select name="action">
        <?php foreach ($actions as $a): ?>
          <option value="<?= $e($a) ?>"><?= $e($a) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field mb-0"><label>Threshold</label><input name="threshold" type="number" step="0.01" value="0" title="Used for high_value"></div>
    <label class="sf-check" style="padding-bottom:.35rem"><input type="checkbox" name="active" value="1" checked> Active</label>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
</div>

<div class="sf-card">
  <h2>Rules</h2>
  <div class="table-responsive">
    <table class="table table-hover align-middle sf-grid mb-0">
      <thead><tr><th>Name</th><th>Type</th><th>Action</th><th>Threshold</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <?php if ($rules === []): ?>
          <tr><td colspan="6" class="text-muted">No rules yet.</td></tr>
        <?php else: foreach ($rules as $rule): ?>
          <tr>
            <td>
              <form method="post" action="<?= $e($url('admin/orders/rules')) ?>" style="display:flex;gap:.5rem;align-items:center;margin:0">
                <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                <input name="name" value="<?= $e((string) $rule['name']) ?>" style="min-width:10rem">
            </td>
            <td>
                <select name="rule_type">
                  <?php foreach ($types as $t): ?>
                    <option value="<?= $e($t) ?>" <?= ((string) $rule['rule_type'] === $t) ? 'selected' : '' ?>><?= $e($t) ?></option>
                  <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="action">
                  <?php foreach ($actions as $a): ?>
                    <option value="<?= $e($a) ?>" <?= ((string) $rule['action'] === $a) ? 'selected' : '' ?>><?= $e($a) ?></option>
                  <?php endforeach; ?>
                </select>
            </td>
            <td><input name="threshold" type="number" step="0.01" value="<?= $e((string) $rule['threshold']) ?>" style="width:6rem"></td>
            <td><input type="checkbox" name="active" value="1" <?= !empty($rule['active']) ? 'checked' : '' ?>></td>
            <td class="text-nowrap">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Update</button>
              </form>
              <form method="post" action="<?= $e($url('admin/orders/rules/delete')) ?>" style="display:inline;margin:0" onsubmit="return confirm('Delete this rule?');">
                <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                <input type="hidden" name="id" value="<?= (int) $rule['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
