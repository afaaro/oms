<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $channels */
/** @var list<array<string,mixed>> $products */
/** @var string|null $error */
$products = $products ?? [];
?>
<div class="sf-page-head">
  <h1>Create order</h1>
  <div class="spacer"></div>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/orders')) ?>">&lt;&lt; Orders</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/orders/moto')) ?>" id="moto-form">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">

  <section class="sf-int-block">
    <header>Customer &amp; shipping</header>
    <div class="sf-int-block-body">
      <div class="sf-field">
        <label>Channel</label>
        <select name="channel_id" required>
          <option value="">Select…</option>
          <?php foreach ($channels as $c): ?>
            <option value="<?= $e((string) $c['id']) ?>"><?= $e((string) $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div class="sf-field"><label>Customer name</label><input name="shipping_name" required></div>
        <div class="sf-field"><label>Email</label><input name="buyer_email" type="email" required></div>
        <div class="sf-field"><label>Shipping cost</label><input name="shipping_cost" id="moto-shipping" type="number" step="0.01" value="0.00"></div>
        <div class="sf-field"><label>Shipping method</label><input name="shipping_method" value="Collection"></div>
        <div class="sf-field">
          <label>Status</label>
          <select name="status">
            <option value="awaiting_despatch">awaiting_despatch</option>
            <option value="awaiting_payment">awaiting_payment</option>
            <option value="processing">processing</option>
          </select>
        </div>
        <div class="sf-field"><label>Order total (auto)</label><input name="total" id="moto-total" type="number" step="0.01" value="0.00" readonly></div>
      </div>
      <div class="sf-field"><label>Address line</label><input name="line1"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div class="sf-field"><label>City</label><input name="city"></div>
        <div class="sf-field"><label>Postcode</label><input name="postcode"></div>
      </div>
    </div>
  </section>

  <section class="sf-int-block">
    <header>Products</header>
    <div class="sf-int-block-body">
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;align-items:center">
        <button type="button" class="btn btn-primary" id="moto-add-selected">+ Add selected products</button>
        <input type="search" id="moto-product-filter" placeholder="Filter SKU / name…" style="min-width:220px;margin-left:auto">
      </div>

      <div style="overflow-x:auto;max-height:280px;margin-bottom:1rem;border:1px solid var(--sf-line);border-radius:4px">
        <table class="table table-hover align-middle sf-grid mb-0" id="moto-product-picker">
          <thead>
            <tr>
              <th><input type="checkbox" id="moto-pick-all" title="Select page"></th>
              <th>SKU</th>
              <th>Name</th>
              <th>Price</th>
              <th>Stock</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($products === []): ?>
              <tr><td colspan="5" class="text-muted p-3">No active products — create products first.</td></tr>
            <?php else: foreach ($products as $p): ?>
              <tr class="moto-product-row"
                  data-search="<?= $e(strtolower(($p['sku'] ?? '') . ' ' . ($p['name'] ?? ''))) ?>">
                <td>
                  <input type="checkbox" class="moto-pick"
                         data-id="<?= $e((string) ((int) $p['id'])) ?>"
                         data-sku="<?= $e((string) $p['sku']) ?>"
                         data-name="<?= $e((string) $p['name']) ?>"
                         data-price="<?= $e((string) ($p['price'] ?? 0)) ?>">
                </td>
                <td><?= $e((string) $p['sku']) ?></td>
                <td><?= $e((string) $p['name']) ?></td>
                <td><?= $e(number_format((float) ($p['price'] ?? 0), 2)) ?></td>
                <td><?= $e((string) ((int) ($p['qty_on_hand'] ?? 0))) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin:0 0 .5rem;font-size:1rem">Order lines</h3>
      <div style="overflow-x:auto">
        <table class="table table-hover align-middle sf-grid" id="moto-lines">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Name</th>
              <th>Qty</th>
              <th>Unit price</th>
              <th>Line total</th>
              <th></th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <p class="text-muted mb-0 mt-2">Select products above, then add them. Qty and price can be edited on each line.</p>
    </div>
  </section>

  <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem">
    <a class="btn btn-outline-secondary" href="<?= $e($url('admin/orders')) ?>">Cancel</a>
    <button class="btn btn-primary" type="submit">Create order</button>
  </div>
</form>

<script>
(function () {
  var linesBody = document.querySelector('#moto-lines tbody');
  var filter = document.getElementById('moto-product-filter');
  var totalEl = document.getElementById('moto-total');
  var shippingEl = document.getElementById('moto-shipping');
  var pickAll = document.getElementById('moto-pick-all');

  function money(n) { return (Math.round(n * 100) / 100).toFixed(2); }

  function recalc() {
    var sum = 0;
    linesBody.querySelectorAll('tr').forEach(function (tr) {
      var qty = parseFloat(tr.querySelector('.moto-qty').value) || 0;
      var price = parseFloat(tr.querySelector('.moto-price').value) || 0;
      var line = qty * price;
      tr.querySelector('.moto-line-total').textContent = money(line);
      sum += line;
    });
    var ship = parseFloat(shippingEl.value) || 0;
    totalEl.value = money(sum + ship);
  }

  function addLine(id, sku, name, price) {
    if (linesBody.querySelector('tr[data-sku="' + CSS.escape(sku) + '"]')) {
      return;
    }
    var tr = document.createElement('tr');
    tr.dataset.sku = sku;
    tr.innerHTML =
      '<td>' + sku +
        '<input type="hidden" name="line_product_id[]" value="' + id + '">' +
        '<input type="hidden" name="line_sku[]" value="' + sku.replace(/"/g, '&quot;') + '">' +
        '<input type="hidden" name="line_name[]" value="' + String(name).replace(/"/g, '&quot;') + '">' +
      '</td>' +
      '<td>' + name + '</td>' +
      '<td><input class="form-control form-control-sm moto-qty" name="line_qty[]" type="number" min="1" value="1" style="width:5rem"></td>' +
      '<td><input class="form-control form-control-sm moto-price" name="line_price[]" type="number" step="0.01" min="0" value="' + money(price) + '" style="width:7rem"></td>' +
      '<td class="moto-line-total">' + money(price) + '</td>' +
      '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger moto-remove">Remove</button></td>';
    linesBody.appendChild(tr);
  }

  document.getElementById('moto-add-selected').addEventListener('click', function () {
    document.querySelectorAll('.moto-pick:checked').forEach(function (cb) {
      addLine(cb.dataset.id || '0', cb.dataset.sku || '', cb.dataset.name || '', parseFloat(cb.dataset.price) || 0);
      cb.checked = false;
    });
    if (pickAll) pickAll.checked = false;
    recalc();
  });

  linesBody.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('moto-remove')) {
      ev.target.closest('tr').remove();
      recalc();
    }
  });
  linesBody.addEventListener('input', function (ev) {
    if (ev.target.classList.contains('moto-qty') || ev.target.classList.contains('moto-price')) {
      recalc();
    }
  });
  shippingEl.addEventListener('input', recalc);

  if (pickAll) {
    pickAll.addEventListener('change', function () {
      document.querySelectorAll('.moto-product-row:not([hidden]) .moto-pick').forEach(function (cb) {
        cb.checked = pickAll.checked;
      });
    });
  }

  if (filter) {
    filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      document.querySelectorAll('.moto-product-row').forEach(function (tr) {
        var hay = tr.getAttribute('data-search') || '';
        tr.hidden = q !== '' && hay.indexOf(q) === -1;
      });
    });
  }
})();
</script>
