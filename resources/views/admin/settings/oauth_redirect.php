<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string $provider */
/** @var string $authorizeUrl */
/** @var int $channelId */
/** @var string $callbackUrl */
$provider = (string) ($provider ?? 'Marketplace');
$authorizeUrl = (string) ($authorizeUrl ?? '');
$channelId = (int) ($channelId ?? 0);
$callbackUrl = (string) ($callbackUrl ?? '');
?>
<div class="sf-page-head">
  <h1>Connecting <?= $e($provider) ?></h1>
</div>

<div class="alert alert-info py-2">
  Redirecting you to <?= $e($provider) ?> to authorise StoreFeeder…
</div>

<p style="margin:1.25rem 0">
  <a class="btn btn-green" id="sf-oauth-continue" href="<?= $e($authorizeUrl) ?>">Continue to <?= $e($provider) ?></a>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/settings/channels/edit', ['id' => $channelId])) ?>">Cancel</a>
</p>

<p class="text-muted" style="max-width:42rem">
  After you approve access, <?= $e($provider) ?> must send you back to:<br>
  <code><?= $e($callbackUrl) ?></code><br>
  For eBay, that URL must match the RuName <strong>Auth Accepted URL</strong> in the developer portal.
</p>

<script>
  (function () {
    var href = <?= json_encode($authorizeUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (href) {
      window.setTimeout(function () { window.location.href = href; }, 400);
    }
  })();
</script>
