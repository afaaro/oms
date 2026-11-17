<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $cases */
/** @var list<array<string,mixed>> $channels */
$cases = $cases ?? [];
$channels = $channels ?? [];
$channelId = (int) ($channelId ?? 0);
$kind = (string) ($kind ?? '');
$status = (string) ($status ?? '');
$tab = 'cases';
?>
<div class="sf-page-head">
  <h1>Support cases</h1>
  <div class="spacer"></div>
  <form method="post" action="<?= $e($url('admin/disputes/sync-cases')) ?>" style="display:inline;margin:0">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="days" value="90">
    <button class="btn btn-primary" type="submit">Sync eBay cases</button>
  </form>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-tabs" style="margin-bottom:1rem">
  <a href="<?= $e($url('admin/disputes')) ?>">Overview</a>
  <a class="is-active" href="<?= $e($url('admin/disputes', ['tab' => 'cases'])) ?>">Cases</a>
</div>

<form method="get" action="<?= $e($url('admin/disputes')) ?>" class="sf-toolbar">
  <input type="hidden" name="tab" value="cases">
  <select name="channel_id">
    <option value="0">All channels</option>
    <?php foreach ($channels as $ch): ?>
      <option value="<?= (int) $ch['id'] ?>" <?= $channelId === (int) $ch['id'] ? 'selected' : '' ?>><?= $e((string) $ch['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="kind">
    <option value="">All kinds</option>
    <?php foreach (['case','inquiry'] as $k): ?>
      <option value="<?= $e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= $e($k) ?></option>
    <?php endforeach; ?>
  </select>
  <input name="status" value="<?= $e($status) ?>" placeholder="Status filter">
  <button class="btn btn-outline-secondary" type="submit">Filter</button>
</form>

<div class="sf-card">
  <div class="table-responsive">
    <table class="table table-hover align-middle sf-grid mb-0">
      <thead>
        <tr>
          <th>Kind</th>
          <th>External</th>
          <th>Channel</th>
          <th>Buyer</th>
          <th>Status</th>
          <th>Order</th>
          <th>Due</th>
          <th>Title</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($cases === []): ?>
          <tr><td colspan="8" class="text-muted">No cases yet. Sync eBay cases to pull Post-Order data.</td></tr>
        <?php else: foreach ($cases as $c): ?>
          <tr>
            <td><?= $e((string) ($c['kind'] ?? '')) ?></td>
            <td><a href="<?= $e($url('admin/disputes/case', ['id' => (int) $c['id']])) ?>"><?= $e((string) ($c['external_id'] ?? '')) ?></a></td>
            <td><?= $e((string) ($c['channel_name'] ?? '')) ?></td>
            <td><?= $e((string) ($c['buyer_login'] ?? '')) ?></td>
            <td><span class="badge"><?= $e((string) ($c['status'] ?? '')) ?></span></td>
            <td><?= $e((string) ($c['order_id'] ?? '')) ?></td>
            <td><?= $e((string) ($c['seller_response_due'] ?? '')) ?></td>
            <td style="white-space:normal;min-width:12rem"><?= $e((string) ($c['item_title'] ?? '')) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
