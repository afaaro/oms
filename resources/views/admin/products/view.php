<?php
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $product */
/** @var list<array<string,mixed>> $stock */
/** @var string|null $flash */
?>
<div class="sf-page-head">
  <h1><?= $e((string) $product['sku']) ?></h1>
  <div class="spacer"></div>
  <a class="btn btn-primary" href="<?= $e($url('admin/products/edit', ['id' => $product['id']])) ?>">Edit</a>
  <a href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>

<div class="sf-home-grid">
  <div class="sf-card">
    <h2><?= $e((string) $product['name']) ?></h2>
    <p><strong>Brand:</strong> <?= $e((string) ($product['brand'] ?? '')) ?></p>
    <p><strong>Category:</strong> <?= $e((string) ($product['category_name'] ?? '—')) ?></p>
    <p><strong>Barcode:</strong> <?= $e((string) ($product['barcode'] ?? '')) ?></p>
    <p><strong>Price:</strong> <?= $e(number_format((float) $product['price'], 2)) ?> · RRP <?= $e(number_format((float) ($product['rrp'] ?? 0), 2)) ?> · Cost <?= $e(number_format((float) ($product['cost'] ?? 0), 2)) ?></p>
    <p><strong>Weight:</strong> <?= $e((string) ($product['weight_grams'] ?? 0)) ?>g</p>
    <p><strong>Active:</strong> <?= !empty($product['active']) ? 'Yes' : 'No' ?></p>
    <?php if (!empty($product['description'])): ?>
      <p><?= nl2br($e((string) $product['description'])) ?></p>
    <?php endif; ?>
  </div>
  <div class="sf-card">
    <h2>Stock</h2>
    <table class="table table-hover align-middle sf-grid">
      <thead><tr><th>Location</th><th>On hand</th><th>Allocated</th></tr></thead>
      <tbody>
        <?php if ($stock === []): ?>
          <tr><td colspan="3" class="text-muted">No inventory rows</td></tr>
        <?php else: foreach ($stock as $s): ?>
          <tr>
            <td><?= $e((string) ($s['location_code'] ?? '—')) ?> <?= $e((string) ($s['location_name'] ?? '')) ?></td>
            <td><?= $e((string) $s['qty_on_hand']) ?></td>
            <td><?= $e((string) $s['qty_allocated']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
