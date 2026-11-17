<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $tenants */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>New support ticket</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('platform/support')) ?>">&lt;&lt; Queue</a>
</div>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>
<form method="post" action="<?= $e($url('platform/support/new')) ?>" class="card sf-panel" style="border-radius:4px;padding:1rem;max-width:640px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <div class="sf-field">
    <label>Tenant</label>
    <select name="tenant_id" required style="width:100%;padding:.5rem">
      <option value="">Select…</option>
      <?php foreach ($tenants as $t): ?>
        <option value="<?= $e((string) $t['id']) ?>"><?= $e((string) $t['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="sf-field"><label>Subject</label><input name="subject" required style="width:100%;padding:.5rem"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div class="sf-field">
      <label>Category</label>
      <select name="category" style="width:100%;padding:.45rem">
        <?php foreach (['general','orders','shipping','integrations','billing','onboarding'] as $c): ?>
          <option value="<?= $e($c) ?>"><?= $e($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field">
      <label>Priority</label>
      <select name="priority" style="width:100%;padding:.45rem">
        <?php foreach (['low','normal','high','urgent'] as $p): ?>
          <option value="<?= $e($p) ?>" <?= $p === 'normal' ? 'selected' : '' ?>><?= $e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="sf-field"><label>Details</label><textarea name="body" rows="6" style="width:100%;padding:.5rem"></textarea></div>
  <button class="btn btn-primary" type="submit">Create ticket</button>
</form>
