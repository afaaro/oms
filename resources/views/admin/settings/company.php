<?php
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $tenant */
?>
<div class="sf-page-head">
  <h1>Company</h1>
</div>
<div class="sf-card" style="max-width:640px">
  <h2><?= $e((string) ($tenant['name'] ?? 'Company')) ?></h2>
  <p><strong>Plan:</strong> <?= $e((string) ($tenant['plan'] ?? 'standard')) ?></p>
  <p><strong>Status:</strong> <span class="badge"><?= $e((string) ($tenant['status'] ?? 'active')) ?></span></p>
  <p><strong>Contact:</strong> <?= $e((string) ($tenant['contact_email'] ?? '')) ?></p>
  <p class="text-muted">Company profile is managed by StoreFeeder platform staff. Contact support to change billing or legal entity details.</p>
  <div class="sf-quick-actions" style="margin-top:1rem">
    <a href="<?= $e($url('admin/settings/channels')) ?>">Integrations</a>
    <a href="<?= $e($url('admin/settings/warehouses')) ?>">Warehouses</a>
    <a href="<?= $e($url('admin/settings/shipping')) ?>">Shipping</a>
  </div>
</div>
