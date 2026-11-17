<?php
/** @var callable $e */
/** @var callable $url */
/** @var string $view */
/** @var int $unmapped */
/** @var int $mapped */
/** @var string|null $flash */
/** @var string|null $error */
$view = $view ?? 'all';
?>
<div class="sf-page-head">
  <h1>Channel listings</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/products')) ?>">&lt;&lt; Products</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<nav class="sf-views">
  <a class="<?= $view === 'all' ? 'is-active' : '' ?>" href="<?= $e($url('admin/listings')) ?>">All</a>
  <a class="<?= $view === 'unmapped' ? 'is-active' : '' ?>" href="<?= $e($url('admin/listings', ['view' => 'unmapped'])) ?>">Unmapped (<?= $e((string) $unmapped) ?>)</a>
  <a class="<?= $view === 'mapped' ? 'is-active' : '' ?>" href="<?= $e($url('admin/listings', ['view' => 'mapped'])) ?>">Mapped (<?= $e((string) $mapped) ?>)</a>
</nav>

<div class="card sf-panel sf-datatable-wrap">
  <table id="listings-table" class="table table-hover align-middle sf-grid display" style="width:100%"
         data-datatable
         data-ajax="<?= $e($url('api/datatable/listings', ['view' => $view])) ?>"
         data-page-length="25"
         data-order='[[0,"desc"]]'>
    <thead>
      <tr>
        <th>Listing</th>
        <th>Channel</th>
        <th>SKU</th>
        <th>Price</th>
        <th>Qty</th>
        <th>Mapped product</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
  </table>
</div>
