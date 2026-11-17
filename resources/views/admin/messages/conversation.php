<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $conversation */
/** @var list<array<string,mixed>> $messages */
/** @var string $sellerUsername */
/** @var string $buyerUsername */
/** @var list<array<string,mixed>> $orders */
/** @var array{item_id:string,url:string}|null $listing */
/** @var string|null $autoFetchError */
/** @var string|null $flash */
/** @var string|null $error */

$looksLikeHtml = static function (string $content): bool {
    return (bool) preg_match('/<\/?[a-z][a-z0-9]*\b[^>]*>/i', $content);
};
$statusBadge = static function (string $s): string {
    return match ($s) {
        'refunded', 'cancelled' => 'badge-err',
        'despatched' => 'badge-ok',
        'on_hold', 'awaiting_payment' => 'badge-warn',
        default => 'badge-info',
    };
};
$orders = $orders ?? [];
$listing = $listing ?? null;
$users = $users ?? [];
$templates = $templates ?? [];
$sellerUsername = trim((string) ($sellerUsername ?? ''));
$buyerUsername = trim((string) ($buyerUsername ?? ''));
$externalId = (string) ($conversation['external_id'] ?? '');
$channelName = trim((string) ($conversation['channel_name'] ?? 'Channel'));
$convType = trim((string) ($conversation['conversation_type'] ?? 'FROM_MEMBERS'));
$convStatus = strtoupper(trim((string) ($conversation['status'] ?? 'ACTIVE')));
$referenceId = trim((string) ($conversation['reference_id'] ?? ''));
$latestDate = trim((string) ($conversation['latest_date'] ?? $conversation['updated_at'] ?? ''));
$latestTs = $latestDate !== '' ? strtotime($latestDate) : false;
$latestLabel = $latestTs !== false ? date('d/m/Y H:i', $latestTs) : ($latestDate !== '' ? $latestDate : '—');
$priority = (int) ($conversation['priority'] ?? 0);
$dueAt = $conversation['due_at'] ? substr((string) $conversation['due_at'], 0, 10) : '';
?>
<div class="sf-page-head sf-cv-head">
  <div>
    <h1><?= $e($buyerUsername !== '' ? $buyerUsername : 'Conversation') ?></h1>
    <div class="sf-cv-sub">
      <span class="channel-pill"><span class="channel-dot ebay">e</span> <?= $e($channelName) ?></span>
      <span class="badge"><?= $e($convStatus) ?></span>
      <?php if ($convType !== ''): ?><span class="text-muted"><?= $e(str_replace('_', ' ', $convType)) ?></span><?php endif; ?>
      <span class="text-muted">Updated <?= $e($latestLabel) ?></span>
    </div>
  </div>
  <div class="spacer"></div>
  <div class="sf-cv-actions">
    <form method="post" action="<?= $e($url('admin/messages/refresh')) ?>" class="d-inline m-0">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= (int) $conversation['id'] ?>">
      <button class="btn btn-outline-secondary" type="submit">Refresh</button>
    </form>
    <a class="btn btn-outline-secondary" href="<?= $e($url('admin/messages', ['folder' => 'message'])) ?>">&lt;&lt; Messages</a>
  </div>
</div>

<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-cv-layout">
  <div class="sf-cv-main">
    <section class="sf-cv-card sf-cv-thread-card">
      <div class="sf-cv-card-h">
        <h2>Thread</h2>
        <span class="text-muted"><?= $e((string) count($messages)) ?> message<?= count($messages) === 1 ? '' : 's' ?></span>
      </div>
      <div class="sf-msg-thread sf-cv-thread">
        <?php if ($messages === []): ?>
          <p class="text-muted sf-cv-empty">
            <?= $autoFetchError !== null
                ? 'Could not load messages from eBay: ' . $e((string) $autoFetchError)
                : 'No messages in this conversation yet.' ?>
          </p>
        <?php else: ?>
          <?php foreach ($messages as $m): ?>
            <?php
              $sender = trim((string) ($m['sender'] ?? ''));
              $body = (string) ($m['body'] ?? '');
              $created = (string) ($m['created_date'] ?? '');
              $ts = $created !== '' ? strtotime($created) : false;
              $displayDate = $ts !== false ? date('j M Y H:i', $ts) : $created;
              $isMine = $sellerUsername !== '' && strcasecmp($sender, $sellerUsername) === 0;
              $useHtml = $looksLikeHtml($body) && !preg_match('/url-prefix\s*\(|@(font-face|media)|!important/', $body);
            ?>
            <article class="sf-msg-bubble<?= $isMine ? ' is-mine' : '' ?>">
              <header>
                <strong><?= $e($sender !== '' ? $sender : '—') ?><?= $isMine ? ' <span class="sf-cv-you">You</span>' : '' ?></strong>
                <?php if ($displayDate !== ''): ?><time><?= $e($displayDate) ?></time><?php endif; ?>
              </header>
              <?php if ($useHtml): ?>
                <div class="sf-msg-bubble-body sf-msg-body-html">
                  <iframe
                    class="sf-msg-frame"
                    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    referrerpolicy="no-referrer"
                    loading="lazy"
                    title="Message body"
                    srcdoc="<?= htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    onload="try{var d=this.contentDocument;if(d&&d.body){this.style.height=Math.max(120,d.body.scrollHeight+16)+'px'}}catch(e){}"
                  ></iframe>
                </div>
              <?php else: ?>
                <div class="sf-msg-bubble-body"><?= nl2br($e($body), false) ?></div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="sf-cv-card sf-cv-reply">
      <div class="sf-cv-card-h"><h2>Reply</h2></div>
      <form method="post" action="<?= $e($url('admin/messages/reply')) ?>" id="conv-reply-form" class="sf-cv-reply-form">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $conversation['id'] ?>">
        <?php if ($templates !== []): ?>
          <div class="sf-field mb-2">
            <label>Template</label>
            <select id="conv-template">
              <option value="">Insert template…</option>
              <?php foreach ($templates as $tpl): ?>
                <option value="<?= $e((string) $tpl['body']) ?>"><?= $e((string) $tpl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="sf-field mb-2">
          <label>Message</label>
          <textarea name="body" id="conv-reply-body" rows="5" maxlength="2000" required placeholder="Type your reply…"></textarea>
        </div>
        <div class="sf-cv-reply-bar">
          <span class="text-muted">Max 2000 characters</span>
          <button class="btn btn-primary" type="submit">Send reply</button>
        </div>
      </form>
    </section>
  </div>

  <aside class="sf-cv-sidebar">
    <section class="sf-cv-card">
      <div class="sf-cv-card-h"><h2>Details</h2></div>
      <dl class="sf-cv-dl">
        <div><dt>Buyer</dt><dd><strong><?= $e($buyerUsername !== '' ? $buyerUsername : '—') ?></strong></dd></div>
        <?php if ($sellerUsername !== ''): ?>
          <div><dt>Seller</dt><dd><?= $e($sellerUsername) ?></dd></div>
        <?php endif; ?>
        <div><dt>Channel</dt><dd><?= $e($channelName) ?></dd></div>
        <div><dt>Status</dt><dd><span class="badge"><?= $e($convStatus) ?></span></dd></div>
        <?php if ($externalId !== ''): ?>
          <div><dt>External ID</dt><dd><code><?= $e($externalId) ?></code></dd></div>
        <?php endif; ?>
        <?php if ($referenceId !== ''): ?>
          <div><dt>Reference</dt><dd><?= $e($referenceId) ?></dd></div>
        <?php endif; ?>
        <?php if ($listing !== null): ?>
          <div>
            <dt>Listing</dt>
            <dd>
              <code><?= $e($listing['item_id']) ?></code>
              <a href="<?= $e($listing['url']) ?>" target="_blank" rel="noopener">View</a>
            </dd>
          </div>
        <?php endif; ?>
      </dl>
    </section>

    <section class="sf-cv-card">
      <div class="sf-cv-card-h"><h2>Assignment</h2></div>
      <form method="post" action="<?= $e($url('admin/messages/assign')) ?>" class="sf-cv-assign">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $conversation['id'] ?>">
        <div class="sf-field">
          <label>Assignee</label>
          <select name="assigned_user_id">
            <option value="0">Unassigned</option>
            <?php foreach ($users as $u): ?>
              <option value="<?= (int) $u['id'] ?>" <?= ((int) ($conversation['assigned_user_id'] ?? 0) === (int) $u['id']) ? 'selected' : '' ?>>
                <?= $e((string) (($u['display_name'] ?? '') !== '' ? $u['display_name'] : $u['email'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="sf-cv-assign-row">
          <div class="sf-field mb-0">
            <label>Due</label>
            <input type="date" name="due_at" value="<?= $e($dueAt) ?>">
          </div>
          <div class="sf-field mb-0">
            <label>Priority</label>
            <input type="number" name="priority" min="0" max="9" value="<?= $priority ?>">
          </div>
        </div>
        <button class="btn btn-outline-secondary w-100" type="submit">Save assignment</button>
      </form>
    </section>

    <section class="sf-cv-card">
      <div class="sf-cv-card-h">
        <h2>Linked orders</h2>
        <span class="text-muted"><?= count($orders) ?></span>
      </div>
      <?php if ($orders === []): ?>
        <p class="text-muted sf-cv-empty mb-0">
          <?= $buyerUsername === '' ? 'No matching order found from this thread yet.' : 'No local orders for this buyer.' ?>
        </p>
      <?php else: ?>
        <div class="sf-cv-orders">
          <?php foreach ($orders as $order): ?>
            <?php
              $oid = (int) $order['id'];
              $ost = (string) ($order['status'] ?? '');
              $items = is_array($order['items'] ?? null) ? $order['items'] : [];
              $itemPreview = [];
              foreach (array_slice($items, 0, 2) as $item) {
                  $sku = trim((string) ($item['sku'] ?? ''));
                  $name = trim((string) ($item['name'] ?? ''));
                  $label = $sku !== '' ? $sku : $name;
                  if ($label !== '') {
                      $itemPreview[] = ((int) ($item['qty'] ?? 0)) . ' × ' . $label;
                  }
              }
              $placed = (string) ($order['created_at'] ?? '');
              $placedTs = $placed !== '' ? strtotime($placed) : false;
              $placedLabel = $placedTs !== false ? date('d/m/Y H:i', $placedTs) : $placed;
            ?>
            <article class="sf-cv-order">
              <div class="sf-cv-order-top">
                <a href="<?= $e($url('admin/orders/view', ['id' => $oid])) ?>">
                  <?= $e((string) ($order['external_order_id'] ?? ('#' . $oid))) ?>
                </a>
                <span class="badge <?= $e($statusBadge($ost)) ?>"><?= $e(str_replace('_', ' ', $ost)) ?></span>
              </div>
              <div class="sf-cv-order-meta">
                <?= $e(number_format((float) ($order['total'] ?? 0), 2) . ' ' . (string) ($order['currency'] ?? 'GBP')) ?>
                · <?= $e($placedLabel !== '' ? $placedLabel : '—') ?>
              </div>
              <?php if ($itemPreview !== []): ?>
                <div class="sf-cv-order-items"><?= $e(implode(', ', $itemPreview)) ?></div>
              <?php endif; ?>
              <div class="sf-cv-order-actions">
                <a class="btn btn-sm btn-outline-secondary" href="<?= $e($url('admin/orders/view', ['id' => $oid])) ?>">View order</a>
                <?php if ($ost !== 'refunded'): ?>
                  <form method="post" action="<?= $e($url('admin/messages/refund')) ?>" class="d-inline m-0" onsubmit="return confirm('Refund order <?= $e((string) ($order['external_order_id'] ?? $oid)) ?>?');">
                    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
                    <input type="hidden" name="conversation_id" value="<?= (int) $conversation['id'] ?>">
                    <input type="hidden" name="order_id" value="<?= $oid ?>">
                    <button class="btn btn-sm btn-outline-danger" type="submit">Refund</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </aside>
</div>

<script>
(function () {
  var sel = document.getElementById('conv-template');
  var body = document.getElementById('conv-reply-body');
  if (!sel || !body) return;
  sel.addEventListener('change', function () {
    if (sel.value) body.value = sel.value;
    sel.selectedIndex = 0;
  });
})();
</script>
