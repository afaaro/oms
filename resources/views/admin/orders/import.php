<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var string|null $flash */
/** @var string|null $error */
?>
<div class="sf-page-head">
  <h1>CSV order import</h1>
  <div class="spacer"></div>
  <a href="<?= $e($url('admin/orders')) ?>">&lt;&lt; Orders</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<div class="sf-card">
  <p class="text-muted" style="margin-top:0">Paste CSV rows: <code>external_order_id,email,total,shipping_method,name</code></p>
  <form method="post" action="<?= $e($url('admin/orders/import')) ?>">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <div class="sf-field">
      <label>CSV</label>
      <textarea name="csv" rows="10" placeholder="external_order_id,email,total,shipping_method,name&#10;CSV-1001,buyer@example.com,19.99,Royal Mail 48,Buyer Name"></textarea>
    </div>
    <button class="btn btn-primary" type="submit">Import orders</button>
  </form>
</div>
