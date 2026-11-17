<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array{name:string,path:string,size:int,mtime:int}> $files */
/** @var string $activeFile */
/** @var string $content */
/** @var bool $loggerReady */
/** @var string $loggerError */
/** @var string $logDir */
/** @var list<array<string,mixed>> $deletions */
/** @var string|null $flash */
/** @var string|null $error */
$files = $files ?? [];
$deletions = $deletions ?? [];
$activeFile = (string) ($activeFile ?? '');
?>
<div class="sf-page-head">
  <h1>System logs</h1>
  <div class="spacer"></div>
  <form method="post" action="<?= $e($url('platform/logs/test')) ?>" style="display:inline;margin:0">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <button class="btn btn-outline-secondary" type="submit">Write test log</button>
  </form>
  <?php if ($files !== []): ?>
    <form method="post" action="<?= $e($url('platform/logs/clear')) ?>" style="display:inline;margin:0"
          onsubmit="return confirm('Delete ALL log files? This cannot be undone.');">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <button class="btn btn-outline-danger" type="submit">Clear all logs</button>
    </form>
  <?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= $e($url('platform/database')) ?>">Database</a>
  <a href="<?= $e($url('platform')) ?>">&lt;&lt; Platform</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<?php if (empty($loggerReady)): ?>
  <div class="alert alert-warning">
    Logger is not writable.
    <div class="small mt-1"><code><?= $e((string) $logDir) ?></code></div>
    <?php if ($loggerError !== ''): ?><div class="small mt-1"><?= $e($loggerError) ?></div><?php endif; ?>
    <div class="small mt-1">Grant Modify on <code>storage/logs</code> for the web server / PHP user.</div>
  </div>
<?php else: ?>
  <p class="text-muted mb-3">Platform staff only. Writing to <code><?= $e((string) $logDir) ?></code></p>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-3">
    <div class="card sf-panel">
      <div class="card-body p-2">
        <div class="fw-semibold mb-2 px-1">Log files</div>
        <?php if ($files === []): ?>
          <p class="text-muted small px-1 mb-0">No log files yet. Click “Write test log”.</p>
        <?php else: foreach ($files as $f): ?>
          <div class="d-flex align-items-start gap-1 px-1 py-1 <?= $activeFile === $f['name'] ? 'bg-light' : '' ?>">
            <a class="flex-grow-1 text-decoration-none <?= $activeFile === $f['name'] ? 'fw-semibold' : '' ?>"
               href="<?= $e($url('platform/logs', ['file' => $f['name']])) ?>">
              <?= $e($f['name']) ?>
              <span class="text-muted small d-block"><?= $e(number_format($f['size'] / 1024, 1)) ?> KB · <?= $e(date('Y-m-d H:i', $f['mtime'])) ?></span>
            </a>
            <form method="post" action="<?= $e($url('platform/logs/delete')) ?>" style="margin:0"
                  onsubmit="return confirm('Delete <?= $e($f['name']) ?>?');">
              <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
              <input type="hidden" name="file" value="<?= $e($f['name']) ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-1" type="submit" title="Delete">×</button>
            </form>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-9">
    <div class="card sf-panel mb-3">
      <div class="card-body">
        <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
          <div class="fw-semibold"><?= $e($activeFile !== '' ? $activeFile : 'Log output') ?></div>
          <?php if ($activeFile !== ''): ?>
            <form method="post" action="<?= $e($url('platform/logs/delete')) ?>" style="margin:0"
                  onsubmit="return confirm('Delete <?= $e($activeFile) ?>?');">
              <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
              <input type="hidden" name="file" value="<?= $e($activeFile) ?>">
              <button class="btn btn-sm btn-outline-danger" type="submit">Delete this file</button>
            </form>
          <?php endif; ?>
        </div>
        <pre class="mb-0 p-3 bg-dark text-light rounded" style="max-height:28rem;overflow:auto;font-size:12px;white-space:pre-wrap"><?= $e($content !== '' ? $content : '(empty)') ?></pre>
      </div>
    </div>

    <div class="card sf-panel">
      <div class="card-body">
        <div class="fw-semibold mb-2">eBay account deletions</div>
        <?php if ($deletions === []): ?>
          <p class="text-muted mb-0">No deletion notifications recorded yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
              <thead>
                <tr>
                  <th>When</th>
                  <th>Username</th>
                  <th>User ID</th>
                  <th class="text-end">Orders</th>
                  <th class="text-end">Messages</th>
                  <th class="text-end">Customers</th>
                  <th>Notification</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($deletions as $d): ?>
                  <tr>
                    <td><?= $e((string) ($d['processed_at'] ?? '')) ?></td>
                    <td><code><?= $e((string) ($d['ebay_username'] ?? '')) ?></code></td>
                    <td><code><?= $e((string) ($d['ebay_user_id'] ?? '')) ?></code></td>
                    <td class="text-end"><?= $e((string) ((int) ($d['orders_redacted'] ?? 0))) ?></td>
                    <td class="text-end"><?= $e((string) ((int) ($d['messages_redacted'] ?? 0))) ?></td>
                    <td class="text-end"><?= $e((string) ((int) ($d['customers_redacted'] ?? 0))) ?></td>
                    <td class="small text-muted"><?= $e((string) ($d['notification_id'] ?? '')) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
