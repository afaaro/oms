<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var list<array<string,mixed>> $suppliers */
/** @var list<array<string,mixed>> $warehouses */
/** @var list<array<string,mixed>> $products */
/** @var string|null $error */
$refDefault = (string) random_int(100000000, 999999999);
$dueDefault = date('Y-m-d', strtotime('+7 days'));
$defaultWh = null;
foreach ($warehouses as $w) {
    if (!empty($w['is_default'])) {
        $defaultWh = $w;
        break;
    }
}
if ($defaultWh === null && $warehouses !== []) {
    $defaultWh = $warehouses[0];
}
?>
<div class="sf-page-head">
  <h1>P.O. &gt; Create Purchase Order</h1>
  <div class="spacer"></div>
  <button class="btn btn-primary" type="submit" form="po-form">Save</button>
  <a href="<?= $e($url('admin/purchase-orders')) ?>">&lt;&lt; Back to Overview</a>
</div>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>

<?php if ($suppliers === []): ?>
  <div class="sf-card">
    <p style="margin:0">Create a supplier before raising a purchase order.</p>
    <p style="margin:.75rem 0 0"><a class="btn btn-primary" href="<?= $e($url('admin/suppliers/new')) ?>">Create supplier</a></p>
  </div>
<?php else: ?>
<form method="post" action="<?= $e($url('admin/purchase-orders/new')) ?>" id="po-form">
  <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
  <input type="hidden" name="status" value="draft">
  <input type="hidden" name="currency" id="po-currency" value="GBP">

  <div class="sf-po-layout">
    <div class="sf-po-main">
      <section class="sf-int-block">
        <header>Purchase Order Information</header>
        <div class="sf-int-block-body">
          <div class="sf-po-fields">
            <div class="sf-field"><label>Purchase Order Reference</label><input name="reference" value="<?= $e($refDefault) ?>" required></div>
            <div class="sf-field">
              <label>Delivery Cost</label>
              <div style="display:flex;gap:.5rem;align-items:center">
                <input name="delivery_cost" type="number" step="0.01" min="0" value="0.00" style="flex:1">
                <label class="sf-check" style="margin:0;white-space:nowrap"><input type="checkbox" name="include_delivery_cost" value="1"> Include</label>
              </div>
            </div>
            <div class="sf-field"><label>Due Date</label><input type="date" name="expected_at" value="<?= $e($dueDefault) ?>"></div>
            <div class="sf-field"><label>Date Sent</label><input type="date" name="date_sent"></div>
            <div class="sf-field"><label>Paid On</label><input type="date" name="paid_on"></div>
            <div class="sf-field"><label>Payment Reference</label><input name="payment_reference"></div>
            <div class="sf-field"><label>Payment Confirmed By</label><input name="payment_confirmed_by"></div>
            <div class="sf-field"><label>Status</label><input value="Draft" readonly></div>
          </div>
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
                  <?= $defaultWh && (int) $defaultWh['id'] === (int) $w['id'] ? 'selected' : '' ?>>
                  <?= $e((string) $w['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sf-po-fields">
            <div class="sf-field"><label>Company</label><input name="delivery_company" id="del-company" value="<?= $e((string) (($defaultWh['company'] ?? '') !== '' ? ($defaultWh['company'] ?? '') : ($defaultWh['name'] ?? ''))) ?>"></div>
            <div class="sf-field"><label>Address 1</label><input name="delivery_line1" id="del-line1" value="<?= $e((string) ($defaultWh['address_line1'] ?? '')) ?>"></div>
            <div class="sf-field"><label>Address 2</label><input name="delivery_line2" id="del-line2" value="<?= $e((string) ($defaultWh['address_line2'] ?? '')) ?>"></div>
            <div class="sf-field"><label>City</label><input name="delivery_city" id="del-city" value="<?= $e((string) ($defaultWh['city'] ?? '')) ?>"></div>
            <div class="sf-field"><label>Country</label><input name="delivery_country" id="del-country" value="<?= $e((string) ($defaultWh['country'] ?? 'GB')) ?>"></div>
            <div class="sf-field"><label>Postcode</label><input name="delivery_postcode" id="del-postcode" value="<?= $e((string) ($defaultWh['postcode'] ?? '')) ?>"></div>
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
              <option value="">Select Supplier</option>
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
                  data-change="<?= $e((string) ($s['po_change_status'] ?? '')) ?>">
                  <?= $e((string) $s['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sf-po-fields sf-po-fields-1">
            <div class="sf-field"><label>Company</label><input id="sup-company" readonly></div>
            <div class="sf-field"><label>Address 1</label><input id="sup-line1" readonly></div>
            <div class="sf-field"><label>Address 2</label><input id="sup-line2" readonly></div>
            <div class="sf-field"><label>City</label><input id="sup-city" readonly></div>
            <div class="sf-field"><label>Country</label><input id="sup-country" readonly></div>
            <div class="sf-field"><label>Postcode</label><input id="sup-postcode" readonly></div>
          </div>
        </div>
      </section>

      <section class="sf-int-block" style="margin-top:1rem">
        <header>Supplier Details</header>
        <div class="sf-int-block-body">
          <div class="sf-po-fields sf-po-fields-1">
            <div class="sf-field"><label>Reference</label><input id="sup-ref" readonly></div>
            <div class="sf-field"><label>Supplier Code</label><input id="sup-code" readonly></div>
            <div class="sf-field"><label>Phone</label><input id="sup-phone" readonly></div>
            <div class="sf-field"><label>Email</label><input id="sup-email" readonly></div>
            <div class="sf-field"><label>Lead Time</label><input id="sup-lead" readonly></div>
            <div class="sf-field"><label>Minimum Order Value</label><input id="sup-min" readonly></div>
            <div class="sf-field">
              <label>Purchase Order Change To Status</label>
              <select name="change_to_status" id="po-change-status">
                <option value="">(Please Select)</option>
                <?php foreach (['ordered', 'partial', 'received', 'cancelled'] as $st): ?>
                  <option value="<?= $e($st) ?>"><?= $e($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="sf-field">
              <label class="sf-check" style="display:flex;gap:.5rem;align-items:center;margin:0">
                <input type="checkbox" name="palletized" id="po-palletized" value="1"> Palletized Delivery
              </label>
              <input name="estimated_pallets" id="po-pallets" type="number" min="0" value="0" placeholder="Estimated Pallet Count" style="margin-top:.4rem">
            </div>
            <div class="sf-field"><label>Purchase Order Notes</label><textarea name="notes" rows="4"></textarea></div>
          </div>
        </div>
      </section>
    </aside>
  </div>

  <section class="sf-int-block" style="margin-top:1rem">
    <header>Products</header>
    <div class="sf-int-block-body">
      <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;align-items:center">
        <button type="button" class="btn btn-primary" id="po-add-selected" style="background:#2e7d32;border-color:#2e7d32">+ Add Selected Products</button>
        <button type="button" class="btn btn-outline-secondary" id="po-clear-filter">Clear Filtering</button>
        <input type="search" id="po-product-filter" placeholder="Filter SKU / name…" style="min-width:220px;margin-left:auto">
      </div>

      <div style="overflow-x:auto;max-height:280px;margin-bottom:1rem;border:1px solid #e5e7eb;border-radius:4px">
        <table class="table table-hover align-middle sf-grid" id="po-product-picker">
          <thead>
            <tr>
              <th><input type="checkbox" id="po-pick-all"></th>
              <th>SKU</th>
              <th>Product Name</th>
              <th>Supplier SKU</th>
              <th>EAN</th>
              <th>Cost</th>
              <th>Inventory</th>
              <th>Allocated</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr class="po-product-row"
                  data-supplier-id="<?= $e((string) ($p['supplier_id'] ?? '')) ?>"
                  data-supplier-name="<?= $e(strtolower((string) ($p['supplier_name'] ?? ''))) ?>"
                  data-search="<?= $e(strtolower(($p['sku'] ?? '') . ' ' . ($p['name'] ?? '') . ' ' . ($p['supplier_sku'] ?? ''))) ?>">
                <td><input type="checkbox" class="po-pick" data-sku="<?= $e((string) $p['sku']) ?>" data-name="<?= $e((string) $p['name']) ?>" data-cost="<?= $e((string) ($p['cost'] ?? 0)) ?>"></td>
                <td><?= $e((string) $p['sku']) ?></td>
                <td><?= $e((string) $p['name']) ?></td>
                <td><?= $e((string) ($p['supplier_sku'] ?? '')) ?></td>
                <td><?= $e((string) ($p['barcode'] ?? '')) ?></td>
                <td><?= $e(number_format((float) ($p['cost'] ?? 0), 2)) ?></td>
                <td><?= $e((string) ($p['qty_on_hand'] ?? 0)) ?></td>
                <td><?= $e((string) ($p['qty_allocated'] ?? 0)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($products === []): ?>
              <tr id="po-no-products"><td colspan="8" class="text-muted">No active products — create products first.</td></tr>
            <?php else: ?>
              <tr id="po-no-supplier-products" style="display:none"><td colspan="8" class="text-muted">No products linked to this supplier. Set the supplier on the product first.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <h3 style="margin:0 0 .5rem;font-size:1rem">Lines on this PO</h3>
      <div style="overflow-x:auto">
        <table class="table table-hover align-middle sf-grid" id="po-lines">
          <thead>
            <tr><th>SKU</th><th>Name</th><th>Units</th><th>Single Price</th><th></th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
      <p class="text-muted" style="margin:.75rem 0 0">Draft POs can be saved without lines; add products before marking ordered / receiving.</p>
    </div>
  </section>
</form>

<style>
.sf-po-layout { display:grid; grid-template-columns: 1.35fr .9fr; gap:1rem; align-items:start; }
.sf-po-fields { display:grid; grid-template-columns:1fr 1fr; gap:.75rem 1rem; }
.sf-po-fields-1 { grid-template-columns:1fr; }
@media (max-width: 980px) {
  .sf-po-layout { grid-template-columns:1fr; }
  .sf-po-fields { grid-template-columns:1fr; }
}
</style>
<script>
(function () {
  var supplier = document.getElementById('po-supplier');
  var warehouse = document.getElementById('po-warehouse');
  var currency = document.getElementById('po-currency');
  var linesBody = document.querySelector('#po-lines tbody');

  function fill(prefix, opt) {
    if (!opt) return;
    var map = {
      company: 'company', line1: 'line1', line2: 'line2', city: 'city',
      country: 'country', postcode: 'postcode', ref: 'ref', code: 'code',
      phone: 'phone', email: 'email', lead: 'lead', min: 'min'
    };
    Object.keys(map).forEach(function (k) {
      var el = document.getElementById(prefix + '-' + k);
      if (el) el.value = opt.getAttribute('data-' + map[k]) || '';
    });
  }

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
    var pickAll = document.getElementById('po-pick-all');
    if (pickAll) pickAll.checked = false;
  }

  function onSupplier() {
    var opt = supplier.options[supplier.selectedIndex];
    fill('sup', opt);
    if (currency) currency.value = opt.getAttribute('data-currency') || 'GBP';
    var change = document.getElementById('po-change-status');
    var preferred = opt.getAttribute('data-change') || '';
    if (change && preferred) change.value = preferred;
    var due = document.querySelector('[name=expected_at]');
    var lead = parseInt(opt.getAttribute('data-lead') || '0', 10);
    if (due && lead > 0) {
      var d = new Date();
      d.setDate(d.getDate() + lead);
      due.value = d.toISOString().slice(0, 10);
    }
    applyProductFilter();
  }

  function onWarehouse() {
    var opt = warehouse.options[warehouse.selectedIndex];
    if (!opt || !opt.value) return;
    document.getElementById('del-company').value = opt.getAttribute('data-company') || '';
    document.getElementById('del-line1').value = opt.getAttribute('data-line1') || '';
    document.getElementById('del-line2').value = opt.getAttribute('data-line2') || '';
    document.getElementById('del-city').value = opt.getAttribute('data-city') || '';
    document.getElementById('del-country').value = opt.getAttribute('data-country') || 'GB';
    document.getElementById('del-postcode').value = opt.getAttribute('data-postcode') || '';
  }

  function addLine(sku, name, cost, qty) {
    qty = qty || 1;
    var existing = linesBody.querySelector('input[name="line_sku[]"][value="' + CSS.escape(sku) + '"]');
    if (existing) {
      var qtyInput = existing.closest('tr').querySelector('input[name="line_qty[]"]');
      qtyInput.value = String(parseInt(qtyInput.value || '0', 10) + qty);
      return;
    }
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input name="line_sku[]" value="' + sku.replace(/"/g, '&quot;') + '" readonly style="width:100%"></td>' +
      '<td><input name="line_name[]" value="' + name.replace(/"/g, '&quot;') + '" style="width:100%"></td>' +
      '<td><input name="line_qty[]" type="number" min="1" value="' + qty + '" style="width:5rem"></td>' +
      '<td><input name="line_cost[]" type="number" step="0.01" min="0" value="' + cost + '" style="width:7rem"></td>' +
      '<td><button type="button" class="btn btn-outline-secondary po-remove-line">Remove</button></td>';
    linesBody.appendChild(tr);
    tr.querySelector('.po-remove-line').addEventListener('click', function () { tr.remove(); });
  }

  var filter = document.getElementById('po-product-filter');
  supplier.addEventListener('change', onSupplier);
  warehouse.addEventListener('change', onWarehouse);
  onWarehouse();
  applyProductFilter();

  document.getElementById('po-add-selected').addEventListener('click', function () {
    document.querySelectorAll('.po-pick:checked').forEach(function (cb) {
      addLine(cb.getAttribute('data-sku'), cb.getAttribute('data-name'), cb.getAttribute('data-cost') || '0', 1);
      cb.checked = false;
    });
    document.getElementById('po-pick-all').checked = false;
  });

  document.getElementById('po-pick-all').addEventListener('change', function () {
    var on = this.checked;
    document.querySelectorAll('#po-product-picker tbody tr.po-product-row').forEach(function (tr) {
      if (tr.style.display === 'none') return;
      var cb = tr.querySelector('.po-pick');
      if (cb) cb.checked = on;
    });
  });

  filter.addEventListener('input', applyProductFilter);
  document.getElementById('po-clear-filter').addEventListener('click', function () {
    filter.value = '';
    applyProductFilter();
  });
})();
</script>
<?php endif; ?>
