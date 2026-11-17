<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $message */
/** @var list<array<string,mixed>> $orders */
/** @var array{item_id:string,url:string}|null $listing */
/** @var string|null $flash */
/** @var string|null $error */
$st = (string) ($message['status'] ?? 'open');
$orders = $orders ?? [];
$listing = $listing ?? null;
$buyer = trim((string) ($message['buyer_name'] ?? '')) ?: 'Buyer';
$folder = (($message['folder'] ?? 'message') === 'mail') ? 'mail' : 'message';
$receivedAt = trim((string) ($message['received_at'] ?? ''));
$displayDate = $receivedAt;
$ts = $receivedAt !== '' ? strtotime($receivedAt) : false;
if ($ts !== false) {
    $displayDate = date('j M Y H:i', $ts);
}
$looksLikeHtml = static function (string $content): bool {
    return (bool) preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $content);
};
$statusBadge = static function (string $s): string {
    return match ($s) {
        'refunded' => 'badge-err',
        'despatched' => 'badge-ok',
        'cancelled' => '',
        'on_hold', 'awaiting_payment' => 'badge-warn',
        default => 'badge-info',
    };
};

$rawBody = trim((string) ($message['body'] ?? ''));
$subject = trim((string) ($message['subject'] ?? ''));
if ($rawBody === '') {
    $rawBody = $subject;
}
$cssLeftover = (bool) preg_match('/url-prefix\s*\(|@(font-face|media)|!important/', $rawBody);
$useHtmlFrame = $looksLikeHtml($rawBody) && !$cssLeftover;
$direction = strtolower((string) ($message['direction'] ?? 'inbound'));
$isMine = $direction === 'outbound';

$thread = [];
if (!$useHtmlFrame) {
    $thread = \App\Integrations\MessageImporter::threadParts($rawBody, $buyer);
    if ($thread === []) {
        $bodyText = \App\Integrations\MessageImporter::readable($rawBody);
        if ($bodyText === '' || preg_match('/url-prefix\s*\(|@(font-face|media)|:focus|:hover|!important/', $bodyText)) {
            $bodyText = $subject;
        }
        $thread = [['sender' => $buyer, 'body' => $bodyText, 'mine' => $isMine]];
    }
}
?>
<div class="sf-page-head">
  <h1><?= $e($subject !== '' ? $subject : 'Message') ?></h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/messages', ['folder' => $folder])) ?>">&lt;&lt; <?= $folder === 'mail' ? 'Mail inbox' : 'Messages' ?></a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-home-grid">
  <div class="sf-card">
    <h2>Summary</h2>
    <p><strong>Channel:</strong> <?= $e((string) ($message['channel_name'] ?: $message['provider'] ?: '—')) ?></p>
    <p><strong>Status:</strong> <span class="badge <?= $st === 'unread' ? 'badge-info' : ($st === 'replied' ? 'badge-ok' : ($st === 'open' ? 'badge-warn' : '')) ?>"><?= $e(ucfirst($st)) ?></span></p>
    <p><strong>Received:</strong> <?= $e($displayDate !== '' ? $displayDate : '—') ?></p>
    <?php if (!empty($message['external_id'])): ?>
      <p><strong>External ID:</strong> <?= $e((string) $message['external_id']) ?></p>
    <?php endif; ?>
    <?php if ($listing !== null): ?>
      <p><strong>Listing:</strong>
        <code><?= $e($listing['item_id']) ?></code>
        <a href="<?= $e($listing['url']) ?>" target="_blank" rel="noopener">View listing</a>
      </p>
    <?php endif; ?>
  </div>
  <div class="sf-card">
    <h2>Buyer</h2>
    <p><strong><?= $e($buyer) ?></strong></p>
    <?php if (!empty($message['buyer_email'])): ?>
      <p><?= $e((string) $message['buyer_email']) ?></p>
    <?php endif; ?>
    <p class="text-muted mb-0"><?= $e(ucfirst($direction)) ?> message</p>
  </div>
</div>

<div class="sf-card">
  <h2>Message</h2>
  <?php if ($useHtmlFrame): ?>
    <div class="sf-msg-thread sf-msg-thread-plain">
      <article class="sf-msg-bubble<?= $isMine ? ' is-mine' : '' ?>">
        <header>
          <strong><?= $e($buyer) ?></strong>
          <?php if ($displayDate !== ''): ?><time><?= $e($displayDate) ?></time><?php endif; ?>
        </header>
        <div class="sf-msg-bubble-body sf-msg-body-html">
          <iframe
            class="sf-msg-frame"
            sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
            referrerpolicy="no-referrer"
            loading="lazy"
            title="Message body"
            srcdoc="<?= htmlspecialchars($rawBody, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            onload="try{var d=this.contentDocument;if(d&&d.body){this.style.height=Math.max(240,d.body.scrollHeight+24)+'px'}}catch(e){}"
          ></iframe>
        </div>
      </article>
    </div>
  <?php else: ?>
    <div class="sf-msg-thread sf-msg-thread-plain">
      <?php foreach ($thread as $i => $part): ?>
        <?php
          $partSender = trim((string) ($part['sender'] ?? $buyer)) ?: $buyer;
          $partBody = trim((string) ($part['body'] ?? ''));
          $partMine = !empty($part['mine']);
          $partTime = $i === 0 ? $displayDate : '';
          if ($partBody === '') {
              continue;
          }
        ?>
        <article class="sf-msg-bubble<?= $partMine ? ' is-mine' : '' ?>">
          <header>
            <strong><?= $e($partSender) ?></strong>
            <?php if ($partTime !== ''): ?><time><?= $e($partTime) ?></time><?php endif; ?>
          </header>
          <div class="sf-msg-bubble-body"><?= nl2br($e($partBody), false) ?></div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="sf-card">
  <h2>Linked orders</h2>
  <?php if ($orders === []): ?>
    <p class="text-muted mb-0"><?= $listing !== null ? 'No local order for this listing yet.' : 'No order matched this message.' ?></p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle sf-grid mb-0">
        <thead>
          <tr>
            <th>Order</th>
            <th>Status</th>
            <th>Total</th>
            <th>Placed</th>
            <th>Items</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <?php
              $oid = (int) $order['id'];
              $ost = (string) ($order['status'] ?? '');
              $isRefunded = $ost === 'refunded';
              $items = is_array($order['items'] ?? null) ? $order['items'] : [];
              $itemPreview = [];
              foreach (array_slice($items, 0, 2) as $item) {
                  $itemPreview[] = ((int) ($item['qty'] ?? 0)) . ' × ' . (string) ($item['name'] ?? '');
              }
            ?>
            <tr>
              <td>
                <a href="<?= $e($url('admin/orders/view', ['id' => $oid])) ?>">
                  <?= $e((string) ($order['external_order_id'] ?? ('#' . $oid))) ?>
                </a>
              </td>
              <td><span class="badge <?= $e($statusBadge($ost)) ?>"><?= $e(str_replace('_', ' ', $ost)) ?></span></td>
              <td class="text-nowrap">
                <?= $e(number_format((float) ($order['total'] ?? 0), 2)) ?>
                <?= $e((string) ($order['currency'] ?? 'GBP')) ?>
              </td>
              <td class="text-muted text-nowrap"><?= $e((string) ($order['created_at'] ?? '')) ?></td>
              <td style="white-space:normal;min-width:12rem"><?= $e($itemPreview !== [] ? implode(', ', $itemPreview) : '—') ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('admin/orders/view', ['id' => $oid])) ?>">View</a>
                <?php if (!$isRefunded): ?>
                  <form method="post" action="<?= $e($url('admin/messages/refund')) ?>" style="display:inline;margin:0" onsubmit="return confirm('Refund order <?= $e((string) $order['external_order_id']) ?>?');">
                    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                    <input type="hidden" name="message_id" value="<?= (int) $message['id'] ?>">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Refund</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="sf-card">
  <h2>Update status</h2>
  <form method="post" action="<?= $e($url('admin/messages/status')) ?>" style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:end;max-width:28rem">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int) $message['id'] ?>">
    <div class="sf-field mb-0">
      <label>Status</label>
      <select name="status">
        <?php foreach (['unread','open','replied','closed'] as $s): ?>
          <option value="<?= $e($s) ?>" <?= $st === $s ? 'selected' : '' ?>><?= $e(ucfirst($s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-primary" type="submit">Save</button>
  </form>
</div>

<?php if (!empty($canMailReply)): ?>
<div class="sf-card">
  <h2>Reply (eBay inquiry)</h2>
  <form method="post" action="<?= $e($url('admin/messages/mail-reply')) ?>">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= (int) $message['id'] ?>">
    <?php if (!empty($templates)): ?>
      <div class="sf-field">
        <label>Template</label>
        <select id="mail-template">
          <option value="">Insert template…</option>
          <?php foreach ($templates as $tpl): ?>
            <option value="<?= $e((string) $tpl['body']) ?>"><?= $e((string) $tpl['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="sf-field"><label>Message</label><textarea name="body" id="mail-reply-body" rows="4" required></textarea></div>
    <button class="btn btn-primary" type="submit">Send reply</button>
  </form>
</div>
<script>
(function () {
  var sel = document.getElementById('mail-template');
  var body = document.getElementById('mail-reply-body');
  if (!sel || !body) return;
  sel.addEventListener('change', function () {
    if (sel.value) body.value = sel.value;
    sel.selectedIndex = 0;
  });
})();
</script>
<?php else: ?>
<div class="sf-card">
  <h2>Reply</h2>
  <p class="text-muted mb-0">This mail row is read-only. Use Conversations for FROM_MEMBERS threads, or Disputes → Cases for structured eBay inquiries.</p>
</div>
<?php endif; ?>
