<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $case */
/** @var array<string,mixed> $detail */
$case = $case ?? [];
$detail = $detail ?? [];
$kind = (string) ($case['kind'] ?? 'case');
?>
<div class="sf-page-head">
  <h1><?= $e(ucfirst($kind)) ?> <?= $e((string) ($case['external_id'] ?? '')) ?></h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/disputes', ['tab' => 'cases'])) ?>">&lt;&lt; Cases</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-home-grid">
  <div class="sf-card">
    <h2>Summary</h2>
    <p><strong>Channel:</strong> <?= $e((string) ($case['channel_name'] ?? '')) ?></p>
    <p><strong>Status:</strong> <span class="badge"><?= $e((string) ($case['status'] ?? '')) ?></span></p>
    <p><strong>Type:</strong> <?= $e((string) ($case['case_type'] ?? '')) ?></p>
    <p><strong>Buyer:</strong> <?= $e((string) ($case['buyer_login'] ?? '')) ?></p>
    <p><strong>Order:</strong> <?= $e((string) ($case['order_id'] ?? '')) ?></p>
    <p><strong>Item:</strong> <?= $e((string) ($case['item_title'] ?? '')) ?> (<?= $e((string) ($case['item_id'] ?? '')) ?>)</p>
    <p><strong>Claim:</strong> <?= $e((string) ($case['claim_amount'] ?? '') . ' ' . (string) ($case['claim_currency'] ?? '')) ?></p>
    <p><strong>Respond by:</strong> <?= $e((string) ($case['seller_response_due'] ?? '—')) ?></p>
  </div>
  <div class="sf-card">
    <h2>Actions</h2>
    <?php if ($kind === 'inquiry'): ?>
      <form method="post" action="<?= $e($url('admin/disputes/case/reply')) ?>">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="id" value="<?= (int) $case['id'] ?>">
        <div class="sf-field"><label>Reply to inquiry</label><textarea name="body" rows="4" required></textarea></div>
        <button class="btn btn-primary" type="submit">Send reply</button>
      </form>
    <?php endif; ?>
    <form method="post" action="<?= $e($url('admin/disputes/case/refund')) ?>" style="margin-top:1rem" onsubmit="return confirm('Issue channel refund for this <?= $e($kind) ?>?');">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="id" value="<?= (int) $case['id'] ?>">
      <div class="sf-field"><label>Refund comments</label><input name="comments" value=""></div>
      <button class="btn btn-outline-danger" type="submit">Issue channel refund</button>
    </form>
  </div>
</div>

<?php if ($detail !== []): ?>
<div class="sf-card">
  <h2>Detail payload</h2>
  <pre style="white-space:pre-wrap;max-height:28rem;overflow:auto;font-size:.85rem"><?= $e(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '') ?></pre>
</div>
<?php endif; ?>
