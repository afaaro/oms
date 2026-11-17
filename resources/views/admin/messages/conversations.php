<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $rows */
/** @var list<array<string,mixed>> $channels */
/** @var int $channelId */
/** @var array{mail:int,message:int} $unread */
/** @var string|null $flash */
/** @var string|null $error */

$fmtWhen = static function (?string $raw): string {
    $raw = (string) $raw;
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw !== '' ? $raw : '—';
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
    return date('d/m/Y H:i', $ts);
};
?>
<div class="sf-page-head">
  <h1>Messages<?= ((int) ($unread['message'] ?? 0)) > 0 ? ' <span class="badge badge-info">' . (int) $unread['message'] . ' unread</span>' : '' ?></h1>
  <div class="spacer"></div>
  <form method="post" action="<?= $e($url('admin/messages/sync')) ?>" class="d-inline m-0">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="folder" value="message">
    <button class="btn btn-primary" type="submit">Sync conversations</button>
  </form>
</div>

<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<nav class="sf-views">
  <a href="<?= $e($url('admin/messages', ['folder' => 'mail'])) ?>">Mail inbox<?= ((int) ($unread['mail'] ?? 0)) > 0 ? ' (' . (int) $unread['mail'] . ')' : '' ?></a>
  <a class="is-active" href="<?= $e($url('admin/messages', ['folder' => 'message'])) ?>">Messages<?= ((int) ($unread['message'] ?? 0)) > 0 ? ' (' . (int) $unread['message'] . ')' : '' ?></a>
</nav>

<?php if (count($channels) > 1): ?>
  <form method="get" action="<?= $e($url('admin/messages')) ?>" class="sf-toolbar mt-2">
    <input type="hidden" name="folder" value="message">
    <label class="text-muted d-flex align-items-center gap-2 m-0">
      Channel
      <select name="channel_id" onchange="this.form.submit()" class="sf-oe-select" style="width:auto;min-width:12rem">
        <option value="0">All channels</option>
        <?php foreach ($channels as $ch): ?>
          <option value="<?= (int) $ch['id'] ?>" <?= $channelId === (int) $ch['id'] ? 'selected' : '' ?>><?= $e((string) $ch['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
<?php endif; ?>

<div class="sf-card sf-cv-list mt-3">
  <div class="table-responsive">
    <table class="table table-hover align-middle sf-grid sf-messages-grid mb-0">
      <thead>
        <tr>
          <th>When</th>
          <th>Channel</th>
          <th>From</th>
          <th class="sf-msg-col">Latest message</th>
          <th>Reference</th>
          <th>Unread</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr>
          <td colspan="6" class="text-muted sf-msg-empty">
            No conversations yet. Sync linked eBay channels to pull buyer message threads.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($rows as $row): ?>
          <?php
            $unreadCount = (int) ($row['unread_count'] ?? 0);
            $preview = trim((string) ($row['latest_body'] ?? ''));
            if (strlen($preview) > 140) {
                $preview = substr($preview, 0, 137) . '…';
            }
            $href = $url('admin/messages/conversation', ['id' => (int) $row['id']]);
          ?>
          <tr class="<?= $unreadCount > 0 ? 'sf-msg-unread' : '' ?> sf-cv-row" data-href="<?= $e($href) ?>">
            <td class="text-muted text-nowrap"><?= $e($fmtWhen(isset($row['latest_date']) ? (string) $row['latest_date'] : null)) ?></td>
            <td class="text-nowrap">
              <span class="channel-pill"><span class="channel-dot ebay">e</span> <?= $e((string) ($row['channel_name'] ?? 'Channel')) ?></span>
            </td>
            <td class="text-nowrap"><strong><?= $e((string) ($row['latest_sender'] ?? '—')) ?></strong></td>
            <td class="sf-msg-col">
              <a href="<?= $e($href) ?>"><?= $e($preview !== '' ? $preview : ('Conversation ' . (string) $row['external_id'])) ?></a>
            </td>
            <td class="text-muted text-nowrap"><?= $e((string) ($row['reference_id'] ?? '') ?: '—') ?></td>
            <td class="text-nowrap"><?= $unreadCount > 0 ? '<span class="badge badge-info">' . $unreadCount . '</span>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
