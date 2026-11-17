<?php
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $rows */
/** @var array<string,int> $byPlan */
?>
<div class="sf-page-head">
  <h1>Billing &amp; plans</h1>
</div>

<div class="sf-stats">
  <?php foreach (['standard','professional','enterprise'] as $plan): ?>
    <div class="sf-stat">
      <div class="label"><?= $e(ucfirst($plan)) ?></div>
      <div class="value"><?= $e((string) ($byPlan[$plan] ?? 0)) ?></div>
      <span class="text-muted">active accounts</span>
    </div>
  <?php endforeach; ?>
</div>

<p class="text-muted" style="margin:1rem 0">Plan changes and invoices are managed here for StoreFeeder customers. Connect Stripe/Xero later; this screen is the ops view.</p>

<div class="card sf-panel">
  <table class="table table-hover align-middle sf-grid">
    <thead>
      <tr><th>Company</th><th>Plan</th><th>Status</th><th>Contact</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $e((string) $r['name']) ?></td>
          <td><?= $e((string) $r['plan']) ?></td>
          <td><span class="badge"><?= $e((string) $r['status']) ?></span></td>
          <td><?= $e((string) ($r['contact_email'] ?? '')) ?></td>
          <td><a href="<?= $e($url('platform/tenants/view', ['id' => $r['id']])) ?>">Manage</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
