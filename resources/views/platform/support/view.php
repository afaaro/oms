<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $ticket */
/** @var list<array<string,mixed>> $messages */
?>
<div class="sf-page-head">
  <h1>Ticket #<?= $e((string) $ticket['id']) ?></h1>
  <div class="spacer"></div>
  <a class="btn" href="<?= $e($url('platform/tenants/impersonate', ['id' => $ticket['tenant_id']])) ?>" style="background:#fff;color:#1557c0">Open tenant</a>
  <a href="<?= $e($url('platform/support')) ?>" style="margin-left:.5rem">&lt;&lt; Queue</a>
</div>

<div class="card sf-panel" style="border-radius:4px;padding:1rem;margin-bottom:1rem">
  <div style="display:flex;gap:1.5rem;flex-wrap:wrap;margin-bottom:.75rem">
    <div><span class="text-muted">Tenant</span><br><strong><?= $e((string) $ticket['tenant_name']) ?></strong></div>
    <div><span class="text-muted">Category</span><br><?= $e((string) $ticket['category']) ?></div>
    <div><span class="text-muted">Priority</span><br><span class="badge"><?= $e((string) $ticket['priority']) ?></span></div>
    <div><span class="text-muted">Status</span><br><?= $e((string) $ticket['status']) ?></div>
  </div>
  <h2 style="margin:0 0 .5rem;font-size:1.1rem"><?= $e((string) $ticket['subject']) ?></h2>
</div>

<form method="post" action="<?= $e($url('platform/support/update')) ?>" class="card sf-panel" style="border-radius:4px;padding:1rem;margin-bottom:1rem;display:flex;gap:.75rem;align-items:end;flex-wrap:wrap">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $ticket['id']) ?>">
  <div class="sf-field" style="margin:0">
    <label>Status</label>
    <select name="status" style="padding:.45rem">
      <?php foreach (['open','pending','resolved','closed'] as $s): ?>
        <option value="<?= $e($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="sf-field" style="margin:0">
    <label>Priority</label>
    <select name="priority" style="padding:.45rem">
      <?php foreach (['low','normal','high','urgent'] as $p): ?>
        <option value="<?= $e($p) ?>" <?= $ticket['priority'] === $p ? 'selected' : '' ?>><?= $e($p) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary" type="submit">Update</button>
</form>

<?php foreach ($messages as $m): ?>
  <div class="card sf-panel" style="border-radius:4px;padding:1rem;margin-bottom:.75rem;border-left:4px solid <?= $m['is_internal'] ? '#f0ad4e' : '#1557c0' ?>">
    <div class="text-muted" style="margin-bottom:.35rem">
      <?= $e((string) ($m['author_name'] ?: 'Staff')) ?>
      · <?= $e((string) $m['created_at']) ?>
      <?php if ($m['is_internal']): ?><span class="badge">internal note</span><?php endif; ?>
    </div>
    <div style="white-space:pre-wrap"><?= $e((string) $m['body']) ?></div>
  </div>
<?php endforeach; ?>

<form method="post" action="<?= $e($url('platform/support/reply')) ?>" class="card sf-panel" style="border-radius:4px;padding:1rem;margin-top:1rem">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $ticket['id']) ?>">
  <div class="sf-field">
    <label>Reply / note</label>
    <textarea name="body" rows="4" required style="width:100%;padding:.5rem"></textarea>
  </div>
  <label style="display:flex;gap:.4rem;align-items:center;margin-bottom:.75rem">
    <input type="checkbox" name="is_internal" value="1"> Internal note (not visible to merchant)
  </label>
  <button class="btn btn-primary" type="submit">Post</button>
</form>
