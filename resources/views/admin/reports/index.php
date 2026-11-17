<?php
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $byChannel */
/** @var list<array<string,mixed>> $byStatus */
/** @var list<array<string,mixed>> $topProducts */
/** @var int $despatchedToday */
?>
<div class="sf-page-head">
  <h1>Insights</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/reports/scheduled')) ?>">Scheduled reports</a>
  <a href="<?= $e($url('admin/reports/exports')) ?>">Export history</a>
</div>
<div class="sf-stats" style="grid-template-columns:repeat(3,minmax(0,1fr))">
  <div class="sf-stat">
    <div class="label">Despatched today</div>
    <div class="value"><?= $e((string) $despatchedToday) ?></div>
  </div>
  <div class="sf-stat">
    <div class="label">Channels with orders</div>
    <div class="value"><?= $e((string) count($byChannel)) ?></div>
  </div>
  <div class="sf-stat">
    <div class="label">Status buckets</div>
    <div class="value"><?= $e((string) count($byStatus)) ?></div>
  </div>
</div>

<div class="sf-home-grid">
  <div class="sf-card">
    <h2>Orders by channel</h2>
    <table class="table table-hover align-middle sf-grid">
      <thead><tr><th>Channel</th><th>Orders</th><th>Revenue</th></tr></thead>
      <tbody>
        <?php foreach ($byChannel as $r): ?>
          <tr>
            <td><?= $e((string) $r['channel_name']) ?></td>
            <td><?= $e((string) $r['order_count']) ?></td>
            <td><?= $e(number_format((float) ($r['revenue'] ?? 0), 2)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="sf-card">
    <h2>Orders by status</h2>
    <table class="table table-hover align-middle sf-grid">
      <thead><tr><th>Status</th><th>Count</th></tr></thead>
      <tbody>
        <?php foreach ($byStatus as $r): ?>
          <tr>
            <td><span class="badge"><?= $e((string) $r['status']) ?></span></td>
            <td><?= $e((string) $r['c']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="sf-card">
  <h2>Top selling products</h2>
  <table class="table table-hover align-middle sf-grid">
    <thead><tr><th>SKU</th><th>Name</th><th>Units</th><th>Revenue</th></tr></thead>
    <tbody>
      <?php foreach ($topProducts as $p): ?>
        <tr>
          <td><?= $e((string) $p['sku']) ?></td>
          <td><?= $e((string) $p['name']) ?></td>
          <td><?= $e((string) ($p['units'] ?? 0)) ?></td>
          <td><?= $e(number_format((float) ($p['revenue'] ?? 0), 2)) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
