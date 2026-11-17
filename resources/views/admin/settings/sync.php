<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string, mixed> $settings */
/** @var list<array<string, mixed>> $runs */
/** @var string $cronUrl */
/** @var string|null $flash */
/** @var string|null $error */

$on = static function (string $key) use ($settings): bool {
    return (int) ($settings[$key] ?? 0) === 1;
};
?>
<div class="sf-page-head">
  <h1>Automatic sync</h1>
</div>

<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2"><?= $e((string) $flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2"><?= $e((string) $error) ?></div>
<?php endif; ?>

<p class="text-muted" style="margin-top:0">
  Pull recent orders, mail, and conversations for this company. Hit the cron URL every minute
  (IONOS cron or Windows Task Scheduler). The interval below decides when a fetch actually runs.
</p>

<form method="post" action="<?= $e($url('admin/settings/sync')) ?>" class="card sf-panel" style="padding:1rem;margin-bottom:1rem;max-width:720px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <label class="sf-switch-row">
    <span>Enabled</span>
    <input type="checkbox" name="enabled" value="1" <?= $on('enabled') ? 'checked' : '' ?>>
  </label>
  <div class="sf-field" style="margin-top:.75rem;max-width:16rem">
    <label>Interval</label>
    <select name="interval_minutes">
      <?php foreach ([5, 10, 15, 30, 60] as $minutes): ?>
        <option value="<?= $minutes ?>" <?= (int) $settings['interval_minutes'] === $minutes ? 'selected' : '' ?>>
          Every <?= $minutes ?> minutes
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="sf-field" style="margin-top:.75rem;max-width:16rem">
    <label>Order lookback (days)</label>
    <input type="number" name="order_days" min="1" max="7" value="<?= (int) $settings['order_days'] ?>">
  </div>
  <div style="margin-top:.75rem">
    <label class="sf-switch-row"><span>Orders</span><input type="checkbox" name="sync_orders" value="1" <?= $on('sync_orders') ? 'checked' : '' ?>></label>
    <label class="sf-switch-row"><span>Mail</span><input type="checkbox" name="sync_mail" value="1" <?= $on('sync_mail') ? 'checked' : '' ?>></label>
    <label class="sf-switch-row"><span>Conversations</span><input type="checkbox" name="sync_conversations" value="1" <?= $on('sync_conversations') ? 'checked' : '' ?>></label>
  </div>
  <div style="margin-top:1rem;display:flex;gap:.5rem;align-items:center">
    <button class="btn btn-primary" type="submit">Save</button>
  </div>
</form>

<form method="post" action="<?= $e($url('admin/settings/sync/run')) ?>" style="margin:0 0 1rem">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <button class="btn" type="submit">Run now</button>
</form>

<section class="card sf-panel" style="padding:1rem;margin-bottom:1rem;max-width:720px">
  <h2 style="font-size:1rem;margin-top:0">Last run</h2>
  <p style="margin:.25rem 0">Status: <?= $e((string) ($settings['last_status'] !== '' ? $settings['last_status'] : 'never')) ?></p>
  <p style="margin:.25rem 0">Started: <?= $e((string) ($settings['last_started_at'] ?? '—')) ?></p>
  <p style="margin:.25rem 0">Finished: <?= $e((string) ($settings['last_finished_at'] ?? '—')) ?></p>
  <p style="margin:.25rem 0">Summary: <?= $e((string) ($settings['last_summary'] !== '' ? $settings['last_summary'] : '—')) ?></p>
</section>

<section class="card sf-panel" style="padding:1rem;margin-bottom:1rem;max-width:720px">
  <h2 style="font-size:1rem;margin-top:0">Cron URL</h2>
  <p class="text-muted">One platform token. Call it every minute. A second call inside the interval reports not due.</p>
  <input type="text" readonly value="<?= $e($cronUrl) ?>" style="width:100%" onclick="this.select()">
  <p style="margin:.75rem 0 0"><code>php bin/sync.php</code> <span class="text-muted">(--force ignores the interval, --tenant=ID limits one company)</span></p>
  <form method="post" action="<?= $e($url('admin/settings/sync/token')) ?>" style="margin:.75rem 0 0">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <button class="btn" type="submit">Regenerate token</button>
  </form>
</section>

<section class="card sf-panel" style="padding:1rem;max-width:960px">
  <h2 style="font-size:1rem;margin-top:0">Recent runs</h2>
  <?php if ($runs === []): ?>
    <p class="text-muted" style="margin:0">No runs yet.</p>
  <?php else: ?>
    <table class="table" style="margin:0">
      <thead>
        <tr>
          <th>Started</th>
          <th>Finished</th>
          <th>Trigger</th>
          <th>Status</th>
          <th>Summary</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($runs as $run): ?>
          <tr>
            <td><?= $e((string) ($run['started_at'] ?? '')) ?></td>
            <td><?= $e((string) ($run['finished_at'] ?? '')) ?></td>
            <td><?= $e((string) ($run['trigger'] ?? '')) ?></td>
            <td><?= $e((string) ($run['status'] ?? '')) ?></td>
            <td><?= $e((string) ($run['summary'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
