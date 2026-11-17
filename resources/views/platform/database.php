<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array{database:string,host:string,driver:string,tables:int,pending:int,applied:int} $info */
/** @var list<array{file:string,size:int,mtime:string,applied:bool,applied_at:?string}> $migrations */
/** @var string|null $flash */
/** @var string|null $error */
/** @var array{ok?:bool,message?:string,ran?:list<string>,skipped?:list<string>,errors?:list<string>}|null $lastResult */
?>
<div class="sf-page-head">
  <h1>Database</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('platform')) ?>">&lt;&lt; Platform</a>
</div>

<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-stats">
  <div class="sf-stat">
    <div class="label">Database</div>
    <div class="value" style="font-size:1.05rem;margin-top:.55rem"><?= $e($info['database'] !== '' ? $info['database'] : '—') ?></div>
    <span class="text-muted"><?= $e($info['host'] !== '' ? $info['host'] : 'local') ?></span>
  </div>
  <div class="sf-stat">
    <div class="label">Tables</div>
    <div class="value"><?= $e((string) $info['tables']) ?></div>
    <span class="text-muted"><?= $e($info['driver']) ?></span>
  </div>
  <div class="sf-stat">
    <div class="label">Migrations</div>
    <div class="value"><?= $e((string) $info['applied']) ?>/<?= $e((string) ($info['applied'] + $info['pending'])) ?></div>
    <span class="text-muted"><?= $info['pending'] > 0 ? $e((string) $info['pending']) . ' pending' : 'up to date' ?></span>
  </div>
</div>

<section class="card sf-panel" style="padding:1rem;margin:1rem 0">
  <h3 style="margin:0 0 .5rem">Run migrations</h3>
  <p class="text-muted" style="margin-top:0">Applies <code>storage/migrations/*.sql</code>. Duplicate table/column errors are ignored. Platform staff only.</p>
  <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
    <form method="post" action="<?= $e($url('platform/database/migrate')) ?>">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="mode" value="pending">
      <button class="btn btn-primary" type="submit" <?= $info['pending'] <= 0 ? 'disabled' : '' ?>>Run pending</button>
    </form>
    <form method="post" action="<?= $e($url('platform/database/migrate')) ?>" onsubmit="return confirm('Re-apply ALL migration files? Safe for CREATE IF NOT EXISTS / duplicate columns, but still platform-only.');">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="mode" value="all">
      <button class="btn btn-outline-secondary" type="submit">Re-run all</button>
    </form>
    <form method="post" action="<?= $e($url('platform/database/migrate')) ?>" onsubmit="return confirm('Mark all SQL files as applied without running them? Use after CLI migrate on a fresh deploy.');">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="mode" value="sync">
      <button class="btn btn-outline-secondary" type="submit">Sync log only</button>
    </form>
  </div>
</section>

<?php if (is_array($lastResult)): ?>
<section class="card sf-panel" style="padding:1rem;margin-bottom:1rem">
  <h3 style="margin:0 0 .5rem">Last run</h3>
  <?php if (!empty($lastResult['ran'])): ?>
    <p style="margin:.25rem 0"><strong>Applied:</strong> <?= $e(implode(', ', $lastResult['ran'])) ?></p>
  <?php endif; ?>
  <?php if (!empty($lastResult['skipped'])): ?>
    <p class="text-muted" style="margin:.25rem 0"><strong>Skipped:</strong> <?= $e(implode(', ', $lastResult['skipped'])) ?></p>
  <?php endif; ?>
  <?php if (!empty($lastResult['errors'])): ?>
    <p style="margin:.25rem 0;color:#b42318"><strong>Errors:</strong> <?= $e(implode(' | ', $lastResult['errors'])) ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<div class="card sf-panel" style="overflow-x:auto">
  <table class="table table-hover align-middle sf-grid">
    <thead>
      <tr>
        <th>File</th>
        <th>Status</th>
        <th>Applied at</th>
        <th>Modified</th>
        <th>Size</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($migrations === []): ?>
        <tr><td colspan="6" class="text-muted">No migration files in storage/migrations.</td></tr>
      <?php else: foreach ($migrations as $m): ?>
        <tr>
          <td><code><?= $e($m['file']) ?></code></td>
          <td>
            <?php if ($m['applied']): ?>
              <span class="badge" style="background:#e7f6ec;color:#1e6b3a">applied</span>
            <?php else: ?>
              <span class="badge" style="background:#fff4e5;color:#9a6700">pending</span>
            <?php endif; ?>
          </td>
          <td><?= $e((string) ($m['applied_at'] ?? '—')) ?></td>
          <td><?= $e($m['mtime']) ?></td>
          <td><?= $e((string) $m['size']) ?> B</td>
          <td>
            <form method="post" action="<?= $e($url('platform/database/migrate')) ?>" style="display:inline" onsubmit="return confirm('Run <?= $e($m['file']) ?> now?');">
              <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
              <input type="hidden" name="mode" value="file">
              <input type="hidden" name="file" value="<?= $e($m['file']) ?>">
              <button class="btn btn-outline-secondary" type="submit">Run</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
