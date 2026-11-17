<?php
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $tokens */
/** @var string $docsUrl */
/** @var string|null $issued */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>API Tokens</h1>
  <p class="text-muted">Authenticate against the REST API with <code>Authorization: Bearer sf_…</code> or <code>X-Api-Key</code>.</p>
</div>

<?php if ($flash): ?>
  <div class="sf-flash sf-flash-ok"><?= $e($flash) ?></div>
<?php endif; ?>
<?php if ($error): ?>
  <div class="sf-flash sf-flash-err"><?= $e($error) ?></div>
<?php endif; ?>

<?php if ($issued): ?>
  <div class="card sf-panel" style="border-left:4px solid var(--sf-accent,#f8991d);margin-bottom:1.25rem;">
    <strong>New token (copy now)</strong>
    <p style="font-family:ui-monospace,monospace;word-break:break-all;margin:.5rem 0 0;"><?= $e($issued) ?></p>
  </div>
<?php endif; ?>

<div class="card sf-panel" style="margin-bottom:1.25rem;">
  <h2 style="margin-top:0;font-size:1.05rem;">Create token</h2>
  <form method="post" action="<?= $e($url('admin/settings/api')) ?>" class="sf-form-row" style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <label>Name
      <input type="text" name="name" value="api" maxlength="128" required>
    </label>
    <button type="submit" class="btn btn-primary">Issue token</button>
  </form>
  <p class="text-muted" style="margin:.75rem 0 0;">
    Endpoint catalog: <a href="<?= $e($docsUrl) ?>" target="_blank" rel="noopener"><?= $e($docsUrl) ?></a>
  </p>
</div>

<div class="sf-card">
  <h2>Tokens</h2>
  <div class="table-responsive">
    <table class="table table-hover align-middle sf-grid mb-0">
      <thead>
        <tr>
          <th>Name</th>
          <th>Prefix</th>
          <th>User</th>
          <th>Last used</th>
          <th>Created</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php if ($tokens === []): ?>
        <tr><td colspan="7" class="text-muted">No tokens yet.</td></tr>
      <?php else: ?>
        <?php foreach ($tokens as $t): ?>
          <?php $revoked = !empty($t['revoked_at']); ?>
          <tr>
            <td><?= $e((string) $t['name']) ?></td>
            <td><code><?= $e((string) $t['token_prefix']) ?>…</code></td>
            <td><?= $e((string) ($t['display_name'] ?: $t['email'] ?: '')) ?></td>
            <td class="text-nowrap"><?= $e((string) ($t['last_used_at'] ?? '—')) ?></td>
            <td class="text-nowrap"><?= $e((string) ($t['created_at'] ?? '')) ?></td>
            <td><?= $revoked ? 'Revoked' : 'Active' ?></td>
            <td class="text-nowrap">
              <?php if (!$revoked): ?>
                <form method="post" action="<?= $e($url('admin/settings/api/revoke')) ?>" style="display:inline;" onsubmit="return confirm('Revoke this token?');">
                  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary">Revoke</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
