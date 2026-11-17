<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed> $po */
/** @var list<array<string,mixed>> $items */
/** @var list<array<string,mixed>> $suppliers */
/** @var list<array<string,mixed>> $warehouses */
/** @var list<array<string,mixed>> $products */
/** @var string|null $flash */
/** @var string|null $error */
$status = (string) ($po['status'] ?? 'draft');
$canEditLines = in_array($status, ['draft', 'ordered'], true);
$canReceive = in_array($status, ['draft', 'ordered', 'partial'], true);
$currency = (string) ($po['currency'] ?? 'GBP');
$v = static fn(string $k, string $d = ''): string => (string) ($po[$k] ?? $d);
?>
<div class="sf-page-head">
  <h1>P.O. &gt; <?= $e($v('reference', (string) $po['id'])) ?></h1>
  <div class="spacer"></div>
  <span class="badge"><?= $e($status) ?></span>
  <button class="btn btn-primary" type="submit" form="po-form">Save</button>
  <a href="<?= $e($url('admin/purchase-orders')) ?>">&lt;&lt; Back to Overview</a>
</div>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<form method="post" action="<?= $e($url('admin/purchase-orders/save')) ?>" id="po-form">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="id" value="<?= $e((string) $po['id']) ?>">
  <input type="hidden" name="currency" id="po-currency" value="<?= $e($currency) ?>">

  <div class="sf-po-layout">
    <div class="sf-po-main">
      <section class="sf-int-block">
        <header>Purchase Order Information</header>
        <div class="sf-int-block-body">
          <div class="sf-po-fields">
            <div class="sf-field"><label>Purchase Order Reference</label><input name="reference" value="<?= $e($v('reference')) ?>" required></div>
            <div class="sf-field">
              <label>Delivery Cost</label>
              <div style="display:flex;gap:.5rem;align-items:center">
                <input name="delivery_cost" type="number" step="0.01" min="0" value="<?= $e($v('delivery_cost', '0.00')) ?>" style="flex:1">
                <label class="sf-check" style="margin:0;white-space:nowrap"><input type="checkbox" name="include_delivery_cost" value="1" <?= !empty($po['include_delivery_cost']) ? 'checked' : '' ?>> Include</label>
              </div>
            </div>
            <div class="sf-field"><label>Due Date</label><input type="date" name="expected_at" value="<?= $e($v('expected_at')) ?>"></div>
            <div class="sf-field"><label>Date Sent</label><input type="date" name="date_sent" value="<?= $e($v('date_sent')) ?>"></div>
            <div class="sf-field"><label>Paid On</label><input type="date" name="paid_on" value="<?= $e($v('paid_on')) ?>"></div>
            <div class="sf-field"><label>Payment Reference</label><input name="payment_reference" value="<?= $e($v('payment_reference')) ?>"></div>
            <div class="sf-field"><label>Payment Confirmed By</label><input name="payment_confirmed_by" value="<?= $e($v('payment_confirmed_by')) ?>"></div>
            <div class="sf-field">
              <label>Status</label>
              <select name="status">
                <?php foreach (['draft', 'ordered', 'partial', 'received', 'cancelled'] as $s): ?>
                  <option value="<?= $e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= $e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p class="text-muted" style="margin:.75rem 0 0">
            Total <?= $e(number_format((float) ($po['total'] ?? 0), 2) . ' ' . $currency) ?>
            <?php if (!empty($po['ordered_at'])): ?> · Ordered <?= $e($v('ordered_at')) ?><?php endif; ?>
            <?php if (!empty($po['received_at'])): ?> · Received <?= $e($v('received_at')) ?><?php endif; ?>
          </p>
        </div>
      </section>

      <section class="sf-int-block" style="margin-top:1rem">
        <header>Delivery Address</header>
        <div class="sf-int-block-body">
          <div class="sf-field" style="margin-bottom:.85rem">
            <label>Select Warehouse</label>
            <select name="warehouse_id" id="po-warehouse">
              <option value="">—</option>
              <?php foreach ($warehouses as $w): ?>
                <option value="<?= $e((string) $w['id']) ?>"
                  data-company="<?= $e((string) (($w['company'] ?? '') !== '' ? $w['company'] : $w['name'])) ?>"
                  data-line1="<?= $e((string) ($w['address_line1'] ?? '')) ?>"
                  data-line2="<?= $e((string) ($w['address_line2'] ?? '')) ?>"
                  data-city="<?= $e((string) ($w['city'] ?? '')) ?>"
                  data-country="<?= $e((string) ($w['country'] ?? 'GB')) ?>"
                  data-postcode="<?= $e((string) ($w['postcode'] ?? '')) ?>"
                  <?= (int) $v('warehouse_id') === (int) $w['id'] ? 'selected' : '' ?>>
                  <?= $e((string) $w['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sf-po-fields">
            <div class="sf-field"><label>Company</label><input name="delivery_company" id="del-company" value="<?= $e($v('delivery_company')) ?>"></div>
            <div class="sf-field"><label>Address 1</label><input name="delivery_line1" id="del-line1" value="<?= $e($v('delivery_line1')) ?>"></div>
            <div class="sf-field"><label>Address 2</label><input name="delivery_line2" id="del-line2" value="<?= $e($v('delivery_line2')) ?>"></div>
            <div class="sf-field"><label>City</label><input name="delivery_city" id="del-city" value="<?= $e($v('delivery_city')) ?>"></div>
            <div class="sf-field"><label>Country</label><input name="delivery_country" id="del-country" value="<?= $e($v('delivery_country', 'GB')) ?>"></div>
            <div class="sf-field"><label>Postcode</label><input name="delivery_postcode" id="del-postcode" value="<?= $e($v('delivery_postcode')) ?>"></div>
          </div>
        </div>
      </section>
    </div>

    <aside class="sf-po-side">
      <section class="sf-int-block">
        <header>Supplier Address</header>
        <div class="sf-int-block-body">
          <div class="sf-field" style="margin-bottom:.85rem">
            <label>Select Supplier</label>
            <select name="supplier_id" id="po-supplier" required>
              <?php foreach ($suppliers as $s): ?>
                <option value="<?= $e((string) $s['id']) ?>"
                  data-company="<?= $e((string) $s['name']) ?>"
                  data-line1="<?= $e((string) ($s['address_line1'] ?? '')) ?>"
                  data-line2="<?= $e((string) ($s['address_line2'] ?? '')) ?>"
                  data-city="<?= $e((string) ($s['city'] ?? '')) ?>"
                  data-country="<?= $e((string) ($s['country'] ?? 'GB')) ?>"
                  data-postcode="<?= $e((string) ($s['postcode'] ?? '')) ?>"
                  data-ref="<?= $e((string) ($s['supplier_reference'] ?? '')) ?>"
                  data-code="<?= $e((string) ($s['code'] ?? '')) ?>"
                  data-phone="<?= $e((string) ($s['phone'] ?? '')) ?>"
                  data-email="<?= $e((string) ($s['email'] ?? '')) ?>"
                  data-lead="<?= $e((string) ($s['lead_time_days'] ?? '0')) ?>"
                  data-min="<?= $e((string) ($s['min_order_value'] ?? '0')) ?>"
                  data-currency="<?= $e((string) ($s['currency'] ?? 'GBP')) ?>"
                  <?= (int) $v('supplier_id') === (int) $s['id'] ? 'selected' : '' ?>>
                  <?= $e((string) $s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sf-po-fields sf-po-fields-1">
            <div class="sf-field"><label>Company</label><input id="sup-company" value="<?= $e($v('supplier_name')) ?>" readonly></div>
            <div class="sf-field"><label>Address 1</label><input id="sup-line1" value="<?= $e($v('supplier_line1')) ?>" readonly></div>
            <div class="sf-field"><label>Address 2</label><input id="sup-line2" value="<?= $e($v('supplier_line2')) ?>" readonly></div>
            <div class="sf-field"><label>City</label><input id="sup-city" value="<?= $e($v('supplier_city')) ?>" readonly></div>
            <div class="sf-field"><label>Country</label><input id="sup-country" value="<?= $e($v('supplier_country')) ?>" readonly></div>
            <div class="sf-field"><label>Postcode</label><input id="sup-postcode" value="<?= $e($v('supplier_postcode')) ?>" readonly></div>
          </div>
        </div>
      </section>

      <section class="sf-int-block" style="margin-top:1rem">
        <header>Supplier Details</header>
        <div class="sf-int-block-body">
          <div class="sf-po-fields sf-po-fields-1">
            <div class="sf-field"><label>Reference</label><input id="sup-ref" value="<?= $e($v('supplier_reference')) ?>" readonly></div>
            <div class="sf-field"><label>Supplier Code</label><input id="sup-code" value="<?= $e($v('supplier_code')) ?>" readonly></div>
            <div class="sf-field"><label>Phone</label><input id="sup-phone" value="<?= $e($v('supplier_phone')) ?>" readonly></div>
            <div class="sf-field"><label>Email</label><input id="sup-email" value="<?= $e($v('supplier_email')) ?>" readonly></div>
            <div class="sf-field"><label>Lead Time</label><input id="sup-lead" value="<?= $e($v('lead_time_days')) ?>" readonly></div>
            <div class="sf-field"><label>Minimum Order Value</label><input id="sup-min" value="<?= $e($v('min_order_value')) ?>" readonly></div>
            <div class="sf-field">
              <label>Purchase Order Change To Status</label>
              <select name="change_to_status">
                <option value="">(Please Select)</option>
                <?php foreach (['ordered', 'partial', 'received', 'cancelled'] as $st): ?>
                  <option value="<?= $e($st) ?>" <?= $v('change_to_status') === $st ? 'selected' : '' ?>><?= $e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sf-field">
              <label class="sf-check" style="display:flex;gap:.5rem;align-items:center;margin:0">
                <input type="checkbox" name="palletized" value="1" <?= !empty($po['palletized']) ? 'checked' : '' ?>> Palletized Delivery
              </label>
              <input name="estimated_pallets" type="number" min="0" value="<?= $e($v('estimated_pallets', '0')) ?>" placeholder="Estimated Pallet Count" style="margin-top:.4rem">
            </div>
            <div class="sf-field"><label>Purchase Order Notes</label><textarea name="notes" rows="4"><?= $e($v('notes')) ?></textarea></div>
          </div>
        </div>
      </section>
    </aside>
  </div>

  <section class="sf-int-block" style="margin-top:1rem">
    <header>Products</header>
    <div class="sf-int-block-body">
      <?php if ($canEditLines): ?>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;align-items:center">
          <button type="button" class="btn btn-primary" id="po-add-selected" style="background:#2e7d32;border-color:#2e7d32">+ Add Selected Products</button>
          <input type="search" id="po-product-filter" placeholder="Filter SKU / name…" style="min-width:220px;margin-left:auto">
        </div>
        <div style="overflow-x:auto;max-height:220px;margin-bottom:1rem;border:1px solid #e5e7eb;border-radius:4px">
          <table class="table table-hover align-middle sf-grid" id="po-product-picker">
            <thead><tr><th></th><th>SKU</th><th>Name</th><th>Cost</th><th>Inventory</th></tr></thead>
            <tbody>
              <?php foreach ($products as $p): ?>
                <tr class="po-product-row"
                    data-supplier-id="<?= $e((string) ($p['supplier_id'] ?? '')) ?>"
                    data-supplier-name="<?= $e(strtolower((string) ($p['supplier_name'] ?? ''))) ?>"
                    data-search="<?= $e(strtolower(($p['sku'] ?? '') . ' ' . ($p['name'] ?? '') . ' ' . ($p['supplier_sku'] ?? ''))) ?>">
                  <td><input type="checkbox" class="po-pick" data-sku="<?= $e((string) $p['sku']) ?>" data-name="<?= $e((string) $p['name']) ?>" data-cost="<?= $e((string) ($p['cost'] ?? 0)) ?>"></td>
                  <td><?= $e((string) $p['sku']) ?></td>
                  <td><?= $e((string) $p['name']) ?></td>
                  <td><?= $e(number_format((float) ($p['cost'] ?? 0), 2)) ?></td>
                  <td><?= $e((string) ($p['qty_on_hand'] ?? 0)) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr id="po-no-supplier-products" style="display:none"><td colspan="5" class="text-muted">No products linked to this supplier.</td></tr>
            </tbody>
          </table>
        </div>
        <div style="overflow-x:auto">
          <table class="table table-hover align-middle sf-grid" id="po-lines">
            <thead><tr><th>SKU</th><th>Name</th><th>Units</th><th>Single Price</th><th>Received</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><input name="line_sku[]" value="<?= $e((string) ($it['sku'] ?? '')) ?>" style="width:100%"></td>
                  <td><input name="line_name[]" value="<?= $e((string) ($it['name'] ?? '')) ?>" style="width:100%"></td>
                  <td><input name="line_qty[]" type="number" min="0" value="<?= $e((string) ($it['qty_ordered'] ?? 1)) ?>" style="width:5rem"></td>
                  <td><input name="line_cost[]" type="number" step="0.01" min="0" value="<?= $e((string) ($it['unit_cost'] ?? 0)) ?>" style="width:7rem"></td>
                  <td><?= $e((string) ($it['qty_received'] ?? 0)) ?></td>
                  <td><button type="button" class="btn btn-outline-secondary po-remove-line">Remove</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="table table-hover align-middle sf-grid">
            <thead><tr><th>SKU</th><th>Name</th><th>Ordered</th><th>Received</th><th>Unit</th><th>Line</th></tr></thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <tr>
                  <td><?= $e((string) ($it['sku'] ?? '')) ?></td>
                  <td><?= $e((string) ($it['name'] ?? '')) ?></td>
                  <td><?= $e((string) ($it['qty_ordered'] ?? 0)) ?></td>
                  <td><?= $e((string) ($it['qty_received'] ?? 0)) ?></td>
                  <td><?= $e(number_format((float) ($it['unit_cost'] ?? 0), 2)) ?></td>
                  <td><?= $e(number_format((float) ($it['line_total'] ?? 0), 2)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>
</form>

<?php if ($canReceive && $items !== []): ?>
<form method="post" action="<?= $e($url('admin/purchase-orders/receive')) ?>" class="sf-int-block" style="margin-top:1rem">
  <header>Receive stock</header>
  <div class="sf-int-block-body">
    <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
    <input type="hidden" name="id" value="<?= $e((string) $po['id']) ?>">
    <div style="overflow-x:auto">
      <table class="table table-hover align-middle sf-grid">
        <thead><tr><th>SKU</th><th>Ordered</th><th>Already received</th><th>Receive now</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it):
            $remaining = max(0, (int) ($it['qty_ordered'] ?? 0) - (int) ($it['qty_received'] ?? 0));
          ?>
            <tr>
              <td><?= $e((string) ($it['sku'] ?? '')) ?><?php if (empty($it['product_id'])): ?> <span class="text-muted">(no product match)</span><?php endif; ?></td>
              <td><?= $e((string) ($it['qty_ordered'] ?? 0)) ?></td>
              <td><?= $e((string) ($it['qty_received'] ?? 0)) ?></td>
              <td>
                <?php if ($remaining > 0): ?>
                  <input type="number" name="receive_qty[<?= $e((string) $it['id']) ?>]" min="0" max="<?= $e((string) $remaining) ?>" value="<?= $e((string) $remaining) ?>" style="width:5rem">
                <?php else: ?>
                  <span class="text-muted">Done</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="margin:1rem 0 0"><button class="btn btn-primary" type="submit">Receive selected qty</button></p>
  </div>
</form>
<?php endif; ?>

<style>
.sf-po-layout { display:grid; grid-template-columns: 1.35fr .9fr; gap:1rem; align-items:start; }
.sf-po-fields { display:grid; grid-template-columns:1fr 1fr; gap:.75rem 1rem; }
.sf-po-fields-1 { grid-template-columns:1fr; }
@media (max-width: 980px) {
  .sf-po-layout { grid-template-columns:1fr; }
  .sf-po-fields { grid-template-columns:1fr; }
}
</style>
<?php if ($canEditLines): ?>
<script>
(function () {
  var supplier = document.getElementById('po-supplier');
  var warehouse = document.getElementById('po-warehouse');
  var currency = document.getElementById('po-currency');
  var linesBody = document.querySelector('#po-lines tbody');

  function fill(prefix, opt) {
    if (!opt) return;
    ['company','line1','line2','city','country','postcode','ref','code','phone','email','lead','min'].forEach(function (k) {
      var el = document.getElementById(prefix + '-' + k);
      if (el) el.value = opt.getAttribute('data-' + k) || '';
    });
  }
  var filter = document.getElementById('po-product-filter');

  function applyProductFilter() {
    var sid = supplier.value || '';
    var opt = supplier.options[supplier.selectedIndex];
    var sname = opt ? (opt.getAttribute('data-company') || '').trim().toLowerCase() : '';
    var q = (filter && filter.value ? filter.value : '').trim().toLowerCase();
    var visible = 0;
    document.querySelectorAll('#po-product-picker tbody tr.po-product-row').forEach(function (tr) {
      var pid = tr.getAttribute('data-supplier-id') || '';
      var pname = (tr.getAttribute('data-supplier-name') || '').trim().toLowerCase();
      var matchSup = false;
      if (sid) {
        matchSup = pid === sid || (pid === '' && pname !== '' && pname === sname);
      }
      var hay = tr.getAttribute('data-search') || '';
      var matchQ = !q || hay.indexOf(q) !== -1;
      var show = matchSup && matchQ;
      tr.style.display = show ? '' : 'none';
      if (show) visible++;
      var cb = tr.querySelector('.po-pick');
      if (cb && !show) cb.checked = false;
    });
    var empty = document.getElementById('po-no-supplier-products');
    if (empty) empty.style.display = sid && visible === 0 ? '' : 'none';
  }

  supplier.addEventListener('change', function () {
    var opt = supplier.options[supplier.selectedIndex];
    fill('sup', opt);
    if (currency) currency.value = opt.getAttribute('data-currency') || 'GBP';
    applyProductFilter();
  });
  warehouse.addEventListener('change', function () {
    var opt = warehouse.options[warehouse.selectedIndex];
    if (!opt || !opt.value) return;
    document.getElementById('del-company').value = opt.getAttribute('data-company') || '';
    document.getElementById('del-line1').value = opt.getAttribute('data-line1') || '';
    document.getElementById('del-line2').value = opt.getAttribute('data-line2') || '';
    document.getElementById('del-city').value = opt.getAttribute('data-city') || '';
    document.getElementById('del-country').value = opt.getAttribute('data-country') || 'GB';
    document.getElementById('del-postcode').value = opt.getAttribute('data-postcode') || '';
  });

  function bindRemove(btn) {
    btn.addEventListener('click', function () { btn.closest('tr').remove(); });
  }
  linesBody.querySelectorAll('.po-remove-line').forEach(bindRemove);

  function addLine(sku, name, cost) {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input name="line_sku[]" value="' + sku.replace(/"/g,'&quot;') + '" style="width:100%"></td>' +
      '<td><input name="line_name[]" value="' + name.replace(/"/g,'&quot;') + '" style="width:100%"></td>' +
      '<td><input name="line_qty[]" type="number" min="0" value="1" style="width:5rem"></td>' +
      '<td><input name="line_cost[]" type="number" step="0.01" min="0" value="' + cost + '" style="width:7rem"></td>' +
      '<td class="text-muted">0</td>' +
      '<td><button type="button" class="btn btn-outline-secondary po-remove-line">Remove</button></td>';
    linesBody.appendChild(tr);
    bindRemove(tr.querySelector('.po-remove-line'));
  }

  var addBtn = document.getElementById('po-add-selected');
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      document.querySelectorAll('.po-pick:checked').forEach(function (cb) {
        addLine(cb.getAttribute('data-sku'), cb.getAttribute('data-name'), cb.getAttribute('data-cost') || '0');
        cb.checked = false;
      });
    });
  }
  if (filter) filter.addEventListener('input', applyProductFilter);
  applyProductFilter();
})();
</script>
<?php endif; ?>
