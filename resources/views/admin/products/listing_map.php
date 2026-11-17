<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $listing */
/** @var list<array<string,mixed>> $products */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>Map listing</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/listings', ['view' => 'unmapped'])) ?>">&lt;&lt; Listings</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<div class="sf-card" style="margin-bottom:1rem;max-width:720px">
  <p><strong><?= $e((string) $listing['title']) ?></strong></p>
  <p class="text-muted"><?= $e((string) ($listing['channel_name'] ?? '')) ?> · <?= $e((string) $listing['external_id']) ?> · SKU <?= $e((string) $listing['sku']) ?></p>
  <p>Price <?= $e(number_format((float) $listing['price'], 2)) ?> · Qty <?= $e((string) $listing['qty']) ?></p>
</div>

<form method="post" action="<?= $e($url('admin/listings/map')) ?>" class="card sf-panel sf-panel-solo" style="padding:1.1rem;max-width:720px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $listing['id']) ?>">
  <div class="sf-field">
    <label>Map to product</label>
    <select name="product_id" required>
      <option value="">Select product…</option>
      <?php foreach ($products as $p): ?>
        <option value="<?= $e((string) $p['id']) ?>" <?= (int) ($listing['product_id'] ?? 0) === (int) $p['id'] ? 'selected' : '' ?>>
          <?= $e((string) $p['sku'] . ' — ' . (string) $p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-primary" type="submit">Save mapping</button>
</form>

<form method="post" action="<?= $e($url('admin/listings/create-product')) ?>" style="margin-top:1rem;max-width:720px">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $listing['id']) ?>">
  <button class="btn btn-green" type="submit">Create new product from listing</button>
</form>
