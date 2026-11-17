<?php
/** @var callable $e */
/** @var callable $url */
/** @var int $tenants */
/** @var int $active */
/** @var int $openTickets */
/** @var list<array<string,mixed>> $recent */
?>
<div class="sf-page-head">
  <h1>Platform overview</h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('platform/tenants/new')) ?>">+ New Tenant</a>
</div>

<div class="sf-stats">
  <div class="sf-stat">
    <div class="label">Tenants</div>
    <div class="value"><?= $e((string) $tenants) ?></div>
    <a href="<?= $e($url('platform/tenants')) ?>"><?= $e((string) $active) ?> active</a>
  </div>
  <div class="sf-stat">
    <div class="label">Open support tickets</div>
    <div class="value"><?= $e((string) $openTickets) ?></div>
    <a href="<?= $e($url('platform/support')) ?>">Go to queue</a>
  </div>
  <div class="sf-stat">
    <div class="label">Service</div>
    <div class="value" style="font-size:1rem;margin-top:.6rem">You are StoreFeeder</div>
    <span class="text-muted">Impersonate a tenant to fix live issues</span>
  </div>
  <a class="sf-stat" href="<?= $e($url('platform/marketplace-apps')) ?>">
    <div class="label">Marketplace Apps</div>
    <div class="value" style="font-size:1rem;margin-top:.6rem">Global credentials</div>
    <span class="text-muted">eBay, Amazon, couriers, …</span>
  </a>
  <a class="sf-stat" href="<?= $e($url('platform/health')) ?>">
    <div class="label">Health</div>
    <div class="value" style="font-size:1rem;margin-top:.6rem">System checks</div>
    <span class="text-muted">DB, migrations, storage, PHP</span>
  </a>
  <a class="sf-stat" href="<?= $e($url('platform/database')) ?>">
    <div class="label">Database</div>
    <div class="value" style="font-size:1rem;margin-top:.6rem">Migrations</div>
    <span class="text-muted">Apply SQL schema updates</span>
  </a>
</div>

<h2 style="margin:1.5rem 0 .75rem;font-size:1.05rem">Recent tickets</h2>
<div class="card sf-panel" style="border-radius:4px">
  <table class="table table-hover align-middle sf-grid">
    <thead>
      <tr><th>ID</th><th>Tenant</th><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th></tr>
    </thead>
    <tbody>
      <?php if ($recent === []): ?>
        <tr><td colspan="6" class="text-muted">No tickets yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $t): ?>
        <tr>
          <td><a href="<?= $e($url('platform/support/view', ['id' => $t['id']])) ?>">#<?= $e((string) $t['id']) ?></a></td>
          <td><?= $e((string) $t['tenant_name']) ?></td>
          <td><?= $e((string) $t['subject']) ?></td>
          <td><span class="badge"><?= $e((string) $t['priority']) ?></span></td>
          <td><?= $e((string) $t['status']) ?></td>
          <td><?= $e((string) $t['created_at']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
