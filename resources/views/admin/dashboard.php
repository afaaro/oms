<?php
/** @var callable $e */
/** @var callable $url */
/** @var string $firstName */
/** @var int $ordersToday */
/** @var int $readyToPick */
/** @var int $despatchedToday */
/** @var int $backOrders */
/** @var int $productsCount */
/** @var int $priorityOrders */
?>
<h1 class="sf-greeting">Hi <?= $e($firstName) ?>, welcome to StoreFeeder</h1>

<div class="sf-stats">
  <a class="sf-stat" href="<?= $e($url('admin/orders')) ?>">
    <div class="label">Orders today</div>
    <div class="value"><?= $e((string) $ordersToday) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/orders', ['view' => 'ready'])) ?>">
    <div class="label">Ready to pick</div>
    <div class="value"><?= $e((string) $readyToPick) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/orders')) ?>">
    <div class="label">Despatched today</div>
    <div class="value"><?= $e((string) $despatchedToday) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/orders', ['view' => 'back'])) ?>">
    <div class="label">Total back orders</div>
    <div class="value"><?= $e((string) $backOrders) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/products')) ?>">
    <div class="label">Active products</div>
    <div class="value"><?= $e((string) $productsCount) ?></div>
  </a>
  <a class="sf-stat" href="<?= $e($url('admin/orders', ['view' => 'priority'])) ?>">
    <div class="label">Priority orders</div>
    <div class="value"><?= $e((string) $priorityOrders) ?></div>
  </a>
</div>

<div class="sf-quick-actions">
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/orders')) ?>">Manage orders</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/products')) ?>">Manage products</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/picking')) ?>">Create a pickwave</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/warehouse/despatch')) ?>">Despatch orders</a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/reports')) ?>">Insights</a>
</div>

<div class="sf-home-grid">
  <div>
    <div class="sf-card">
      <h2>System status</h2>
      <p style="margin:0;color:#359e2e;font-weight:700">All systems operational</p>
    </div>
    <div class="sf-card">
      <h2>Latest knowledgebase articles</h2>
      <ul>
        <li><a href="#">Changing shipping methods after despatch</a></li>
        <li><a href="#">Setting up Amazon marketplace channels</a></li>
        <li><a href="#">Pickwave best practices</a></li>
      </ul>
    </div>
  </div>
  <div>
    <div class="sf-card">
      <h2>Notifications</h2>
      <ul>
        <li><strong>Warehouse</strong><div class="text-muted">Despatch creates labels — print from Warehouse → Despatch or the order page.</div></li>
        <li><strong>Manifests</strong><div class="text-muted">Build and close carrier manifests under Warehouse → Manifests.</div></li>
        <li><strong>Tip</strong><div class="text-muted">Print invoices and packing slips from the order detail page.</div></li>
      </ul>
    </div>
  </div>
</div>
