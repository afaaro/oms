<?php
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $customer */
/** @var list<array<string,mixed>> $orders */
?>
<div class="sf-page-head">
  <h1><?= $e((string) $customer['name']) ?></h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/customers')) ?>">&lt;&lt; Customers</a>
</div>

<div class="sf-card">
  <p><strong>Email:</strong> <?= $e((string) $customer['email']) ?></p>
  <p><strong>Phone:</strong> <?= $e((string) ($customer['phone'] ?? '')) ?></p>
  <p><strong>Postcode:</strong> <?= $e((string) ($customer['postcode'] ?? '')) ?></p>
  <p><strong>Created:</strong> <?= $e((string) ($customer['created_at'] ?? '')) ?></p>
</div>

<div class="sf-card">
  <h2>Orders</h2>
  <table class="table table-hover align-middle sf-grid">
    <thead><tr><th>ID</th><th>External</th><th>Channel</th><th>Status</th><th>Total</th><th>Date</th></tr></thead>
    <tbody>
      <?php if ($orders === []): ?>
        <tr><td colspan="6" class="text-muted">No matching orders</td></tr>
      <?php else: foreach ($orders as $o): ?>
        <tr>
          <td><a href="<?= $e($url('admin/orders/view', ['id' => $o['id']])) ?>"><?= $e((string) $o['id']) ?></a></td>
          <td><?= $e((string) $o['external_order_id']) ?></td>
          <td><?= $e((string) ($o['channel_name'] ?? '')) ?></td>
          <td><span class="badge"><?= $e((string) $o['status']) ?></span></td>
          <td><?= $e(number_format((float) $o['total'], 2) . ' ' . (string) $o['currency']) ?></td>
          <td><?= $e((string) $o['created_at']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
