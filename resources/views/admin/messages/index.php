<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $messages */
/** @var array{mail:int,message:int} $unread */
/** @var string $folder */
/** @var string $status */
/** @var string $provider */
/** @var string|null $flash */
/** @var string|null $error */

$fmtWhen = static function (string $raw): string {
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }
    $diff = time() - $ts;
    if ($diff < 3600) {
        return max(1, (int) floor($diff / 60)) . 'm ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . 'h ago';
    }
    if ($diff < 86400 * 7) {
        return (int) floor($diff / 86400) . 'd ago';
    }
    return date('d M Y', $ts);
};

$statusClass = static function (string $s): string {
    return match ($s) {
        'unread' => 'badge-info',
        'open' => 'badge-warn',
        'replied' => 'badge-ok',
        'closed' => '',
        default => '',
    };
};
?>
<?php
$folder = ($folder ?? 'message') === 'mail' ? 'mail' : 'message';
$unreadMail = (int) ($unread['mail'] ?? 0);
$unreadMessages = (int) ($unread['message'] ?? 0);
$listQuery = static function (array $extra = []) use ($folder, $status, $provider): array {
    $query = array_merge(['folder' => $folder], $extra);
    if ($status !== '' && !array_key_exists('status', $extra)) {
        $query['status'] = $status;
    }
    if ($provider !== '' && !array_key_exists('provider', $extra)) {
        $query['provider'] = $provider;
    }
    return array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
};
?>
<div class="sf-page-head">
  <h1><?= $folder === 'mail' ? 'Mail inbox' : 'Messages' ?><?php $count = $folder === 'mail' ? $unreadMail : $unreadMessages; ?><?= $count > 0 ? ' <span class="badge badge-info">' . $count . ' unread</span>' : '' ?></h1>
  <div class="spacer"></div>
  <form method="post" action="<?= $e($url('admin/messages/sync')) ?>" style="display:inline;margin:0">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="folder" value="<?= $e($folder) ?>">
    <button class="btn btn-primary" type="submit"><?= $folder === 'mail' ? 'Sync mail' : 'Sync conversations' ?></button>
  </form>
</div>

<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<nav class="sf-views">
  <a class="<?= $folder === 'mail' ? 'is-active' : '' ?>" href="<?= $e($url('admin/messages', ['folder' => 'mail'])) ?>">Mail inbox<?= $unreadMail > 0 ? ' (' . $unreadMail . ')' : '' ?></a>
  <a class="<?= $folder === 'message' ? 'is-active' : '' ?>" href="<?= $e($url('admin/messages', ['folder' => 'message'])) ?>">Messages<?= $unreadMessages > 0 ? ' (' . $unreadMessages . ')' : '' ?></a>
</nav>

<nav class="sf-views">
  <a class="<?= $status === '' ? 'is-active' : '' ?>" href="<?= $e($url('admin/messages', $listQuery(['status' => null]))) ?>">All</a>
  <?php foreach (['unread' => 'Unread', 'open' => 'Open', 'replied' => 'Replied', 'closed' => 'Closed'] as $sk => $sl): ?>
    <a class="<?= $status === $sk ? 'is-active' : '' ?>" href="<?= $e($url('admin/messages', $listQuery(['status' => $sk]))) ?>"><?= $e($sl) ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" action="<?= $e($url('admin/messages')) ?>" class="sf-toolbar">
  <input type="hidden" name="folder" value="<?= $e($folder) ?>">
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= $e($status) ?>"><?php endif; ?>
  <label class="text-muted" style="display:flex;align-items:center;gap:.5rem;margin:0">
    Channel
    <input type="search" name="provider" value="<?= $e($provider) ?>" placeholder="ebay, amazon, bandq…" style="min-width:180px">
  </label>
  <button class="btn" type="submit">Filter</button>
  <?php if ($provider !== '' || $status !== ''): ?>
    <a class="btn btn-outline-secondary" href="<?= $e($url('admin/messages', ['folder' => $folder])) ?>">Clear</a>
  <?php endif; ?>
</form>

<div class="sf-card">
  <h2><?= $folder === 'mail' ? 'Mail' : 'Messages' ?></h2>
  <div class="table-responsive">
    <table class="table table-hover align-middle sf-grid sf-messages-grid mb-0">
      <thead>
        <tr>
          <th>When</th>
          <th>Channel</th>
          <th>Buyer</th>
          <th class="sf-msg-col">Message</th>
          <th>Order</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($messages === []): ?>
        <tr>
          <td colspan="6" class="text-muted sf-msg-empty">
            No <?= $folder === 'mail' ? 'mail' : 'messages' ?><?= $status !== '' || $provider !== '' ? ' for this filter' : '' ?>.
            Sync linked channels to pull <?= $folder === 'mail' ? 'eBay mail' : 'buyer messages' ?>.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($messages as $m): ?>
          <?php
            $st = (string) ($m['status'] ?? 'open');
            $isUnread = $st === 'unread';
            $when = (string) ($m['received_at'] ?? $m['created_at'] ?? '');
            $subject = trim((string) ($m['subject'] ?? '')) ?: '(no subject)';
            $preview = trim(preg_replace('/\s+/', ' ', \App\Integrations\MessageImporter::readable((string) ($m['body'] ?? ''))) ?? '');
            if (strlen($preview) > 110) {
                $preview = substr($preview, 0, 107) . '…';
            }
            $ch = (string) ($m['channel_name'] ?: $m['provider'] ?: '—');
            $order = (string) ($m['order_external_id'] ?? '');
          ?>
          <tr class="<?= $isUnread ? 'sf-msg-unread' : '' ?>">
            <td class="text-muted text-nowrap"><?= $e($fmtWhen($when)) ?></td>
            <td class="text-nowrap"><span class="badge"><?= $e($ch) ?></span></td>
            <td class="text-nowrap"><?= $e((string) ($m['buyer_name'] ?? '—')) ?></td>
            <td class="sf-msg-col">
              <a href="<?= $e($url('admin/messages/view', ['id' => (int) $m['id']])) ?>"><?= $e($subject) ?></a>
              <?php if ($preview !== ''): ?>
                <div class="text-muted mt-1"><?= $e($preview) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-muted text-nowrap"><?php if ($order !== ''): ?><span title="Linked order"><?= $e($order) ?></span><?php else: ?>—<?php endif; ?></td>
            <td class="text-nowrap"><span class="badge <?= $e($statusClass($st)) ?>"><?= $e(ucfirst($st)) ?></span></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
