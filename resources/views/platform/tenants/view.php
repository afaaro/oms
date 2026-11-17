<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $tenant */
/** @var list<array<string,mixed>> $users */
/** @var list<array<string,mixed>> $tickets */
/** @var string|null $flash */
/** @var string|null $error */
/** @var bool $isProtected */
$status = (string) ($tenant['status'] ?? 'active');
$isClosed = in_array($status, ['closed', 'cancelled'], true);
$isProtected = !empty($isProtected);
?>
<div class="sf-page-head">
  <h1><?= $e((string) $tenant['name']) ?></h1>
  <div class="spacer"></div>
  <?php if (!$isClosed): ?>
    <a class="btn btn-primary" href="<?= $e($url('platform/tenants/impersonate', ['id' => $tenant['id']])) ?>">Open as tenant</a>
  <?php endif; ?>
  <a href="<?= $e($url('platform/tenants')) ?>" style="margin-left:.5rem">&lt;&lt; Tenants</a>
</div>
<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>
<?php if ($isClosed): ?>
  <div class="alert alert-warning py-2">
    This account is <strong><?= $e($status) ?></strong>. Channels are inactive until reopened.
  </div>
<?php endif; ?>

<form method="post" action="<?= $e($url('platform/tenants/save')) ?>" class="card sf-panel" style="border-radius:4px;padding:1rem;margin-bottom:1rem">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $tenant['id']) ?>">
  <div class="sf-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
    <div class="sf-field"><label>Company name</label><input name="name" value="<?= $e((string) $tenant['name']) ?>" style="width:100%;padding:.5rem;box-sizing:border-box"></div>
    <div class="sf-field"><label>Contact email</label><input name="contact_email" value="<?= $e((string) ($tenant['contact_email'] ?? '')) ?>" style="width:100%;padding:.5rem;box-sizing:border-box"></div>
    <div class="sf-field">
      <label>Plan</label>
      <select name="plan" style="padding:.45rem;width:100%;box-sizing:border-box">
        <?php foreach (['standard','professional','enterprise'] as $p): ?>
          <option value="<?= $e($p) ?>" <?= ($tenant['plan'] ?? '') === $p ? 'selected' : '' ?>><?= $e($p) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="sf-field">
      <label>Status</label>
      <select name="status" style="padding:.45rem;width:100%;box-sizing:border-box">
        <?php foreach (['active','trial','suspended','cancelled','closed'] as $s): ?>
          <option value="<?= $e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="sf-field">
    <label>Internal support notes</label>
    <textarea name="support_notes" rows="3" style="width:100%;padding:.5rem"><?= $e((string) ($tenant['support_notes'] ?? '')) ?></textarea>
  </div>
  <button class="btn btn-primary" type="submit">Save tenant</button>
</form>

<section class="card sf-panel sf-danger-zone" style="border-radius:4px;padding:1rem;margin-bottom:1.25rem;border-color:#e2b4b4">
  <h3 style="margin:0 0 .35rem;color:#8b1e1e">Account actions</h3>
  <p class="text-muted" style="margin:0 0 1rem">Close keeps data but blocks access. Delete permanently removes the tenant and all OMS data.</p>
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
    <?php if ($isClosed): ?>
      <form method="post" action="<?= $e($url('platform/tenants/reopen')) ?>" style="margin:0">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>">
        <button class="btn btn-green" type="submit">Reopen account</button>
      </form>
    <?php elseif (!$isProtected): ?>
      <form method="post" action="<?= $e($url('platform/tenants/close')) ?>" style="margin:0" onsubmit="return confirm('Close this account? Channels will be deactivated and API tokens revoked.');">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>">
        <button class="btn btn-outline-secondary" type="submit">Close account</button>
      </form>
    <?php else: ?>
      <span class="text-muted">Primary tenant cannot be closed or deleted.</span>
    <?php endif; ?>
  </div>

  <?php if (!$isProtected): ?>
    <hr style="border:0;border-top:1px solid #e2b4b4;margin:1rem 0">
    <form method="post" action="<?= $e($url('platform/tenants/delete')) ?>" class="sf-delete-tenant" onsubmit="return confirm('Permanently delete this tenant and ALL related data? This cannot be undone.');">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= (int) $tenant['id'] ?>">
      <label class="sf-field" style="max-width:320px;margin:0 0 .75rem">
        Type slug <code><?= $e((string) $tenant['slug']) ?></code> to delete
        <input name="confirm_slug" required autocomplete="off" placeholder="<?= $e((string) $tenant['slug']) ?>" style="width:100%;padding:.5rem;margin-top:.35rem">
      </label>
      <button class="btn btn-danger" type="submit">Delete tenant permanently</button>
    </form>
  <?php endif; ?>
</section>

<div class="sf-form-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
  <div style="min-width:0">
    <h3>Users on this account</h3>
    <div class="card sf-panel" style="border-radius:4px;overflow-x:auto;-webkit-overflow-scrolling:touch">
      <table class="table table-hover align-middle sf-grid">
        <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
        <tbody>
          <?php if ($users === []): ?><tr><td colspan="3" class="text-muted">No users linked.</td></tr><?php endif; ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= $e((string) ($u['display_name'] ?: $u['email'])) ?></td>
              <td><?= $e((string) $u['email']) ?></td>
              <td><?= $e((string) $u['role']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div style="min-width:0">
    <h3>Support tickets</h3>
    <div class="card sf-panel" style="border-radius:4px;overflow-x:auto;-webkit-overflow-scrolling:touch">
      <table class="table table-hover align-middle sf-grid">
        <thead><tr><th>Subject</th><th>Status</th></tr></thead>
        <tbody>
          <?php if ($tickets === []): ?><tr><td colspan="2" class="text-muted">No tickets.</td></tr><?php endif; ?>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><a href="<?= $e($url('platform/support/view', ['id' => $t['id']])) ?>"><?= $e((string) $t['subject']) ?></a></td>
              <td><?= $e((string) $t['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
