<?php
/** @var \App\Core\App $app */
/** @var callable $e */
/** @var callable $url */
/** @var array<string,mixed>|null $product */
/** @var list<array<string,mixed>> $categories */
/** @var list<array<string,mixed>> $warehouses */
/** @var list<string> $brands */
/** @var list<array<string,mixed>> $variants */
/** @var list<array<string,mixed>> $linkCandidates */
/** @var list<array<string,mixed>> $listings */
/** @var list<array<string,mixed>> $channels */
/** @var list<array<string,mixed>> $suppliers */
/** @var list<array{id:int,name:string,code:string,show_on_variants:int,values:list<string>}> $attributeDefs */
/** @var list<array{id:int,name:string,code:string,values:list<string>}> $variantAttrDefs */
/** @var list<array{id:int,name:string,code:string}> $variantAttrCandidates */
/** @var array<string,string> $productAttrs */
/** @var int $inventoryQty */
/** @var int $inventoryAllocated */
/** @var int $inventoryAvailable */
/** @var bool $hasVariants */
/** @var string $section */
/** @var string|null $flash */
/** @var string|null $error */
$editing = is_array($product);
$p = $product ?? [];
$section = $section ?? 'general';
$listings = $listings ?? [];
$channels = $channels ?? [];
$suppliers = $suppliers ?? [];
$attributeDefs = $attributeDefs ?? [];
$productAttrs = $productAttrs ?? [];
$variantAttrDefs = $variantAttrDefs ?? [];
$variantAttrCandidates = $variantAttrCandidates ?? [];
$rootProductId = (int) (($p['parent_id'] ?? 0) ?: ($p['id'] ?? 0));
$hasVariants = !empty($hasVariants);
/** @var list<array{id:int,code:string,name:string}> $stockLocations */
$stockLocations = $stockLocations ?? [];
$action = $editing ? $url('admin/products/save') : $url('admin/products/new');
$val = static fn(string $k, string $d = ''): string => (string) ($p[$k] ?? $d);
$weightKg = number_format(((int) ($p['weight_grams'] ?? 0)) / 1000, 3, '.', '');
$area = 0.0;
$l = (int) ($p['length_mm'] ?? 0);
$w = (int) ($p['width_mm'] ?? 0);
if ($l > 0 && $w > 0) {
    $area = round(($l * $w) / 1_000_000, 2);
}
$productId = (int) ($p['id'] ?? 0);
$secUrl = static function (string $s) use ($url, $productId, $editing): string {
    if (!$editing) {
        return $url('admin/products/new');
    }
    return $url('admin/products/edit', ['id' => $productId, 'section' => $s]);
};
$navItems = [
    'general'     => 'General',
    'description' => 'Description',
    'images'      => 'Images',
    'shipping'    => 'Shipping & Handling',
    'listings'    => 'Listings',
    'notes'       => 'Notes',
];
$listedByChannel = [];
foreach ($listings as $li) {
    $listedByChannel[(int) ($li['id'] ?? 0)] = true;
}
?>
<div class="sf-page-head">
  <h1>Product</h1>
  <div class="spacer"></div>
  <?php if ($editing): ?>
    <button class="btn btn-primary" type="submit" form="product-form">Save</button>
  <?php endif; ?>
  <a class="btn btn-outline-secondary" href="<?= $e($url('admin/products')) ?>">&lt;&lt; Back to Product Manager</a>
</div>
<?php if ($editing): ?>
  <h2 style="margin:.25rem 0 1rem;font-size:1.15rem"><?= $e($val('name')) ?></h2>
<?php endif; ?>
<?php if (!empty($flash)): ?><div class="alert alert-success py-2"><?= $e((string) $flash) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger py-2"><?= $e((string) $error) ?></div><?php endif; ?>
<datalist id="sf-pick-bins">
  <?php foreach ($stockLocations as $loc): ?>
    <option value="<?= $e((string) ($loc['code'] ?? '')) ?>"><?= $e((string) (($loc['name'] ?? '') !== '' && ($loc['name'] ?? '') !== ($loc['code'] ?? '') ? ($loc['code'] . ' — ' . $loc['name']) : ($loc['code'] ?? ''))) ?></option>
  <?php endforeach; ?>
</datalist>

<div class="sf-product-shell<?= $editing ? '' : ' is-create' ?>">
  <?php if ($editing): ?>
  <aside class="sf-product-side">
    <nav class="sf-product-side-nav">
      <?php foreach ($navItems as $key => $label): ?>
        <a class="<?= $section === $key ? 'is-active' : '' ?>" href="<?= $e($secUrl($key)) ?>"><?= $e($label) ?></a>
      <?php endforeach; ?>
    </nav>
  </aside>
  <?php endif; ?>

  <div class="sf-product-main">
    <form method="post" action="<?= $e($action) ?>" id="product-form">
      <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
      <input type="hidden" name="section" value="<?= $e($section) ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= $e($val('id')) ?>"><?php endif; ?>

      <?php if (!$editing || $section === 'general'): ?>

        <?php if ($editing): ?>
        <section class="sf-int-block" style="margin-bottom:1rem">
          <header>Product Listings Overview</header>
          <div class="sf-int-block-body">
            <div class="sf-listing-overview">
              <?php
                $byChannel = [];
                foreach ($listings as $li) {
                    $byChannel[(string) ($li['channel_name'] ?? $li['channel_code'] ?? '')] = $li;
                }
                foreach ($channels as $ch):
                  $name = (string) ($ch['name'] ?? '');
                  $row = $byChannel[$name] ?? null;
                  $active = $row && (($row['status'] ?? '') === 'active' || !empty($row['mapped']));
              ?>
                <div class="sf-listing-chip <?= $active ? 'is-on' : 'is-off' ?>">
                  <strong><?= $e($name) ?></strong>
                  <span><?= $active ? 'Active' : '—' ?></span>
                </div>
              <?php endforeach; ?>
              <?php if ($channels === []): ?>
                <p class="text-muted" style="margin:0">No channels configured yet.</p>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="card mb-3">
          <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <span>Product Variants</span>
            <?php if ($editing && empty($p['parent_id'])): ?>
              <div class="d-flex gap-2 align-items-center flex-wrap">
                <?php if ($variantAttrCandidates !== []): ?>
                  <select class="form-select form-select-sm" name="attribute_id" id="attach-variant-attr-select" form="attach-variant-attr-form" required style="min-width:140px;width:auto">
                    <option value="">Attribute…</option>
                    <?php foreach ($variantAttrCandidates as $cand): ?>
                      <option value="<?= $e((string) $cand['id']) ?>"><?= $e((string) $cand['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-sm btn-success" type="submit" form="attach-variant-attr-form"
                          onclick="var s=document.getElementById('attach-variant-attr-select'); if(!s||!s.value){alert('Choose an attribute first.'); return false;}">+ New Variant Attribute</button>
                <?php else: ?>
                  <a class="btn btn-sm btn-success" href="<?= $e($url('admin/attributes/new')) ?>">+ New Variant Attribute</a>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="card-body">
            <?php if (!empty($p['parent_id'])): ?>
              <p class="text-muted mb-3">This product is a variant of
                <a href="<?= $e($url('admin/products/edit', ['id' => $p['parent_id']])) ?>">parent #<?= $e((string) $p['parent_id']) ?></a>.
              </p>
            <?php endif; ?>
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th scope="col" class="text-nowrap" style="min-width:9rem">SKU</th>
                    <th scope="col" style="min-width:12rem">Name</th>
                    <th scope="col" class="text-nowrap" style="width:6.5rem">Price</th>
                    <th scope="col" class="text-nowrap" style="width:6.5rem">Inv</th>
                    <th scope="col" class="text-nowrap" style="width:6.5rem">kg</th>
                    <th scope="col" class="text-nowrap" style="width:6.5rem">Bin</th>
                    <?php foreach ($variantAttrDefs as $ad): ?>
                      <th scope="col" class="text-nowrap" style="min-width:10rem" title="<?= $e((string) $ad['code']) ?>">
                        <span class="d-inline-flex align-items-center gap-1">
                          <?= $e((string) $ad['name']) ?>
                          <?php if ($editing && empty($p['parent_id'])): ?>
                            <button class="btn btn-sm btn-outline-danger py-0 px-1 lh-1" type="submit" form="detach-attr-<?= $e((string) $ad['id']) ?>"
                                    title="Remove attribute column" aria-label="Remove attribute">×</button>
                          <?php endif; ?>
                        </span>
                      </th>
                    <?php endforeach; ?>
                    <th scope="col" class="text-center" style="width:3rem"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $variantColspan = 7 + count($variantAttrDefs);
                    if ($variants === []):
                  ?>
                    <tr><td colspan="<?= $e((string) $variantColspan) ?>" class="text-muted">No linked variants yet.</td></tr>
                  <?php else: foreach ($variants as $v):
                    $vkg = number_format(((int) ($v['weight_grams'] ?? 0)) / 1000, 3, '.', '');
                    $vid = (string) $v['id'];
                    $vsku = (string) ($v['sku'] ?? '');
                    $vname = (string) ($v['name'] ?? '');
                    $vattrs = is_array($v['attrs'] ?? null) ? $v['attrs'] : [];
                  ?>
                    <tr>
                      <td class="text-nowrap" style="max-width:14rem">
                        <a class="d-inline-block text-truncate mw-100" style="max-width:14rem" href="<?= $e($url('admin/products/edit', ['id' => $v['id']])) ?>" title="<?= $e($vsku) ?>"><?= $e($vsku) ?></a>
                      </td>
                      <td style="min-width:12rem;max-width:20rem" title="<?= $e($vname) ?>">
                        <span class="d-inline-block text-truncate mw-100" style="max-width:20rem"><?= $e($vname === $vsku ? '' : $vname) ?></span>
                      </td>
                      <td style="width:6.5rem">
                        <input class="form-control form-control-sm" name="variant_price[<?= $e($vid) ?>]" type="number" step="0.01" value="<?= $e((string) ($v['price'] ?? 0)) ?>">
                      </td>
                      <td style="width:6.5rem">
                        <?php if (!empty($v['inventory_shared'])): ?>
                          <input class="form-control form-control-sm" type="number" min="0" step="1" value="<?= $e((string) ((int) ($v['qty_on_hand'] ?? 0))) ?>" readonly title="Shared stock from <?= $e((string) ($v['inventory_source_sku'] ?? 'base SKU')) ?>">
                        <?php else: ?>
                          <input class="form-control form-control-sm" name="variant_qty[<?= $e($vid) ?>]" type="number" min="0" step="1" value="<?= $e((string) ((int) ($v['qty_on_hand'] ?? 0))) ?>">
                        <?php endif; ?>
                      </td>
                      <td style="width:6.5rem">
                        <input class="form-control form-control-sm" name="variant_weight_kg[<?= $e($vid) ?>]" type="number" step="0.001" value="<?= $e($vkg === '0.000' ? '' : $vkg) ?>">
                      </td>
                      <td style="width:6.5rem">
                        <input class="form-control form-control-sm" name="variant_pick_bin[<?= $e($vid) ?>]" list="sf-pick-bins" value="<?= $e((string) ($v['pick_bin'] ?? '')) ?>" autocomplete="off">
                      </td>
                      <?php foreach ($variantAttrDefs as $ad):
                        $aid = (string) $ad['id'];
                        $acode = (string) $ad['code'];
                        $aval = (string) ($vattrs[$acode] ?? '');
                        $listId = 'vattr-' . $vid . '-' . $aid;
                      ?>
                        <td style="min-width:10rem">
                          <input class="form-control form-control-sm" name="variant_attr[<?= $e($vid) ?>][<?= $e($aid) ?>]" value="<?= $e($aval) ?>" list="<?= $e($listId) ?>" placeholder="<?= $e((string) $ad['name']) ?>">
                          <?php if (!empty($ad['values'])): ?>
                            <datalist id="<?= $e($listId) ?>">
                              <?php foreach ($ad['values'] as $opt): ?>
                                <option value="<?= $e((string) $opt) ?>"></option>
                              <?php endforeach; ?>
                            </datalist>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                      <td class="text-center">
                        <button class="btn btn-sm btn-outline-danger" form="unlink-<?= $e($vid) ?>" type="submit" title="Unlink" aria-label="Unlink variant">×</button>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
            <div class="row g-3 mt-1 justify-content-end align-items-end">
              <div class="col-auto">
                <label class="form-label" for="link-variant-child">Add Existing Product</label>
                <div class="input-group input-group-sm">
                  <select class="form-select" id="link-variant-child" name="child_id" form="link-variant-form" style="min-width:14rem">
                    <option value="">—</option>
                    <?php foreach ($linkCandidates as $c): ?>
                      <option value="<?= $e((string) $c['id']) ?>"><?= $e((string) $c['sku']) ?> — <?= $e((string) $c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="btn btn-primary" form="link-variant-form" type="submit">Add Existing Product</button>
                </div>
              </div>
              <div class="col-auto">
                <label class="form-label" for="new-variant-sku">New SKU</label>
                <input class="form-control form-control-sm" id="new-variant-sku" name="sku" form="new-variant-form" required placeholder="VARIANT-SKU" style="min-width:10rem">
              </div>
              <div class="col-auto">
                <label class="form-label" for="new-variant-name">New name</label>
                <div class="input-group input-group-sm">
                  <input class="form-control" id="new-variant-name" name="name" form="new-variant-form" required placeholder="Variant name" style="min-width:12rem">
                  <button class="btn btn-primary" form="new-variant-form" type="submit">Add New Variant</button>
                </div>
              </div>
            </div>
          </div>
        </section>
        <?php endif; ?>

        <section class="sf-int-block">
          <header style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
            <span>Product Information</span>
            <?php if ($editing): ?>
              <label class="sf-switch-row" style="margin:0;color:#fff">
                <span>All fields — update variant products</span>
                <input type="checkbox" name="update_all_variants" value="1">
              </label>
            <?php endif; ?>
          </header>
          <div class="sf-int-block-body">
            <div class="sf-product-layout">
              <div class="sf-product-fields">
                <div class="sf-field" style="grid-column:1/-1">
                  <label>Product name *</label>
                  <input name="name" required value="<?= $e($val('name')) ?>">
                </div>
                <div class="sf-field">
                  <label>SKU *</label>
                  <input name="sku" required <?= $editing ? 'readonly' : '' ?> value="<?= $e($val('sku')) ?>">
                </div>
                <div class="sf-field">
                  <label class="form-label">Inventory</label>
                  <?php
                    $inventoryAllocated = (int) ($inventoryAllocated ?? 0);
                    $inventoryAvailable = (int) ($inventoryAvailable ?? max(0, (int) ($inventoryQty ?? 0) - $inventoryAllocated));
                  ?>
                  <?php if ($hasVariants): ?>
                    <input class="form-control" name="inventory_qty" type="number" min="0" step="1" value="<?= $e((string) $inventoryQty) ?>" readonly title="Sum of variant inventory">
                    <div class="form-text">Sum of base variant stock (packs/cuts share the smallest unit SKU)</div>
                  <?php elseif (!empty($inventoryShared)): ?>
                    <input class="form-control" type="number" min="0" step="1" value="<?= $e((string) $inventoryQty) ?>" readonly title="From <?= $e((string) ($inventorySourceSku ?? 'base SKU')) ?>">
                    <div class="form-text">From <?= $e((string) ($inventorySourceSku !== '' ? $inventorySourceSku : 'base SKU')) ?></div>
                  <?php else: ?>
                    <input class="form-control" name="inventory_qty" type="number" min="0" step="1" value="<?= $editing ? $e((string) $inventoryQty) : '0' ?>">
                  <?php endif; ?>
                  <?php if ($editing): ?>
                    <div class="form-text">Allocated <?= $e((string) $inventoryAllocated) ?> · Available <?= $e((string) $inventoryAvailable) ?></div>
                  <?php endif; ?>
                </div>
                <div class="sf-field">
                  <label>Price *</label>
                  <div class="sf-field-with-flag">
                    <input name="price" type="number" step="0.01" min="0" required value="<?= $e($val('price', '0.00')) ?>">
                    <?php if ($editing): ?><label class="sf-mini-flag"><input type="checkbox" name="update_variants_price" value="1"> Update variant products</label><?php endif; ?>
                  </div>
                </div>
                <div class="sf-field">
                  <label>RRP</label>
                  <div class="sf-field-with-flag">
                    <input name="rrp" type="number" step="0.01" min="0" value="<?= $e($val('rrp', '0.00')) ?>">
                    <?php if ($editing): ?><label class="sf-mini-flag"><input type="checkbox" name="update_variants_rrp" value="1"> Update variant products</label><?php endif; ?>
                  </div>
                </div>
                <div class="sf-field"><label>Cost</label><input name="cost" type="number" step="0.01" min="0" value="<?= $e($val('cost', '0.00')) ?>"></div>
                <div class="sf-field">
                  <label>Tax class</label>
                  <div class="sf-field-with-flag">
                    <select name="tax_class">
                      <?php foreach (['0', '5', '20'] as $tax): ?>
                        <option value="<?= $e($tax) ?>" <?= $val('tax_class', '20') === $tax ? 'selected' : '' ?>><?= $e($tax) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($editing): ?><label class="sf-mini-flag"><input type="checkbox" name="update_variants_tax" value="1"> Update variant products</label><?php endif; ?>
                  </div>
                </div>
                <div class="sf-field">
                  <label>Price includes VAT</label>
                  <label class="sf-switch-row" style="margin:0">
                    <span><?= !empty($p['price_includes_vat']) ? 'Yes' : 'No' ?></span>
                    <input type="checkbox" name="price_includes_vat" value="1" <?= !empty($p['price_includes_vat']) ? 'checked' : '' ?>
                           onchange="this.previousElementSibling.textContent=this.checked?'Yes':'No'">
                  </label>
                </div>
                <div class="sf-field">
                  <label>Weight</label>
                  <div style="display:flex;gap:.4rem;align-items:center">
                    <input name="weight_kg" type="number" step="0.001" min="0" value="<?= $e($weightKg === '0.000' ? '' : $weightKg) ?>" style="flex:1">
                    <span class="text-muted">kg</span>
                  </div>
                </div>
                <div class="sf-field" style="grid-column:1/-1">
                  <label>Dimensions</label>
                  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem">
                    <div><span class="text-muted" style="font-size:.8rem">Length</span><div style="display:flex;gap:.35rem;align-items:center"><input name="length_mm" type="number" min="0" value="<?= $e($val('length_mm', '0')) ?>"><span class="text-muted">mm</span></div></div>
                    <div><span class="text-muted" style="font-size:.8rem">Width</span><div style="display:flex;gap:.35rem;align-items:center"><input name="width_mm" type="number" min="0" value="<?= $e($val('width_mm', '0')) ?>"><span class="text-muted">mm</span></div></div>
                    <div><span class="text-muted" style="font-size:.8rem">Height / Depth</span><div style="display:flex;gap:.35rem;align-items:center"><input name="height_mm" type="number" min="0" value="<?= $e($val('height_mm', '0')) ?>"><span class="text-muted">mm</span></div></div>
                  </div>
                  <div style="margin-top:.35rem" class="text-muted" style="font-size:.85rem">Area ≈ <?= $area > 0 ? $e((string) $area) . ' m²' : '—' ?></div>
                  <?php if ($editing): ?><label class="sf-mini-flag"><input type="checkbox" name="update_variants_dims" value="1"> Update variant products</label><?php endif; ?>
                </div>
                <div class="sf-field">
                  <label>Warehouse</label>
                  <select name="warehouse">
                    <?php
                      $whVal = $val('warehouse', 'Default');
                      $found = false;
                      foreach ($warehouses as $wh):
                        $label = (string) $wh['name'];
                        $sel = $whVal === $label;
                        if ($sel) $found = true;
                    ?>
                      <option value="<?= $e($label) ?>" <?= $sel ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                    <?php if (!$found && $whVal !== ''): ?>
                      <option value="<?= $e($whVal) ?>" selected><?= $e($whVal) ?></option>
                    <?php endif; ?>
                  </select>
                </div>
                <div class="sf-field">
                  <label>Pick Bin</label>
                  <input name="pick_bin" list="sf-pick-bins" value="<?= $e($val('pick_bin')) ?>" placeholder="e.g. A-01" autocomplete="off">
                </div>
                <div class="sf-field">
                  <label>Brand</label>
                  <input name="brand" list="brand-list" value="<?= $e($val('brand')) ?>">
                  <datalist id="brand-list"><?php foreach ($brands as $b): ?><option value="<?= $e($b) ?>"><?php endforeach; ?></datalist>
                  <?php if ($editing): ?><label class="sf-mini-flag"><input type="checkbox" name="update_variants_brand" value="1"> Update variant products</label><?php endif; ?>
                </div>
                <div class="sf-field">
                  <label>Category</label>
                  <select name="category_id">
                    <option value="">—</option>
                    <?php foreach ($categories as $c): ?>
                      <option value="<?= $e((string) $c['id']) ?>" <?= (int) $val('category_id') === (int) $c['id'] ? 'selected' : '' ?>><?= $e((string) $c['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="sf-field">
                  <label>Supplier</label>
                  <select name="supplier_id" id="product-supplier-id">
                    <option value="">—</option>
                    <?php foreach ($suppliers as $s): ?>
                      <option value="<?= $e((string) $s['id']) ?>"
                              data-name="<?= $e((string) ($s['name'] ?? '')) ?>"
                              <?= (int) $val('supplier_id') === (int) $s['id'] ? 'selected' : '' ?>>
                        <?= $e((string) ($s['name'] ?? '')) ?><?= !empty($s['code']) ? ' (' . $e((string) $s['code']) . ')' : '' ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <input type="hidden" name="supplier_name" id="product-supplier-name" value="<?= $e($val('supplier_name')) ?>">
                </div>
                <div class="sf-field">
                  <label>Supplier SKU</label>
                  <input name="supplier_sku" value="<?= $e($val('supplier_sku')) ?>" placeholder="Supplier product code">
                </div>
                <div class="sf-field">
                  <label>EAN</label>
                  <div style="display:flex;gap:.4rem;align-items:center">
                    <input name="barcode" id="ean-field" value="<?= $e($val('barcode')) ?>" style="flex:1">
                    <button type="button" class="btn btn-outline-secondary" id="ean-autofill">Autofill</button>
                  </div>
                </div>
                <div class="sf-field"><label>UPC</label><input name="upc" value="<?= $e($val('upc')) ?>"></div>
                <?php if (!$editing): ?>
                  <div class="sf-field" style="grid-column:1/-1"><label>Description</label><textarea name="description" rows="4"><?= $e($val('description')) ?></textarea></div>
                <?php endif; ?>
                <label class="sf-switch-row" style="grid-column:1/-1"><span>Active</span><input type="checkbox" name="active" value="1" <?= !$editing || !empty($p['active']) ? 'checked' : '' ?>></label>
              </div>
              <aside class="sf-product-image">
                <div class="text-muted" style="margin-bottom:.35rem">Default Image</div>
                <div class="sf-product-thumb">
                  <?php if ($val('image') !== ''): ?>
                    <img src="<?= $e($val('image')) ?>" alt="" style="max-width:100%;max-height:220px;object-fit:contain">
                  <?php else: ?>
                    <div class="text-muted" style="padding:2rem;text-align:center">No image</div>
                  <?php endif; ?>
                </div>
                <div class="sf-field" style="margin-top:.75rem"><label>Image URL</label><input name="image" value="<?= $e($val('image')) ?>"></div>
              </aside>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:1.25rem;flex-wrap:wrap;gap:.75rem">
              <span class="text-muted">* Required field</span>
              <div style="display:flex;gap:.5rem">
                <a class="btn btn-outline-secondary" href="<?= $e($url('admin/products')) ?>">Cancel</a>
                <button class="btn btn-primary" type="submit">Save</button>
              </div>
            </div>
          </div>
        </section>

        <!-- Keep other section fields in DOM when on general so they aren't wiped -->
        <?php if ($editing): ?>
          <input type="hidden" name="description" value="<?= $e($val('description')) ?>">
          <input type="hidden" name="notes" value="<?= $e($val('notes')) ?>">
        <?php endif; ?>

      <?php elseif ($section === 'description'): ?>
        <section class="sf-int-block">
          <header>Product Description</header>
          <div class="sf-int-block-body">
            <div class="sf-field"><label>Description</label><textarea name="description" rows="16"><?= $e($val('description')) ?></textarea></div>
            <!-- preserve other fields -->
            <?php include __DIR__ . '/_product_hidden_core.php'; ?>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
              <a class="btn btn-outline-secondary" href="<?= $e($url('admin/products')) ?>">Cancel</a>
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </div>
        </section>

      <?php elseif ($section === 'images'): ?>
        <section class="sf-int-block">
          <header>Images</header>
          <div class="sf-int-block-body">
            <div class="sf-product-thumb" style="max-width:320px;margin-bottom:1rem">
              <?php if ($val('image') !== ''): ?>
                <img src="<?= $e($val('image')) ?>" alt="" style="max-width:100%;max-height:260px;object-fit:contain">
              <?php else: ?>
                <div class="text-muted" style="padding:2rem;text-align:center">No image</div>
              <?php endif; ?>
            </div>
            <div class="sf-field"><label>Default image URL</label><input name="image" value="<?= $e($val('image')) ?>" placeholder="https://…"></div>
            <?php include __DIR__ . '/_product_hidden_core.php'; ?>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </div>
        </section>

      <?php elseif ($section === 'shipping'): ?>
        <section class="sf-int-block">
          <header>Shipping &amp; Handling</header>
          <div class="sf-int-block-body">
            <div class="sf-product-fields">
              <div class="sf-field">
                <label>Weight</label>
                <div style="display:flex;gap:.4rem;align-items:center">
                  <input name="weight_kg" type="number" step="0.001" min="0" value="<?= $e($weightKg === '0.000' ? '' : $weightKg) ?>" style="flex:1">
                  <span class="text-muted">kg</span>
                </div>
              </div>
              <div class="sf-field">
                <label>Pick Bin</label>
                <input name="pick_bin" list="sf-pick-bins" value="<?= $e($val('pick_bin')) ?>" placeholder="e.g. A-01" autocomplete="off">
              </div>
              <div class="sf-field"><label>Length (mm)</label><input name="length_mm" type="number" min="0" value="<?= $e($val('length_mm', '0')) ?>"></div>
              <div class="sf-field"><label>Width (mm)</label><input name="width_mm" type="number" min="0" value="<?= $e($val('width_mm', '0')) ?>"></div>
              <div class="sf-field"><label>Height / Depth (mm)</label><input name="height_mm" type="number" min="0" value="<?= $e($val('height_mm', '0')) ?>"></div>
              <div class="sf-field">
                <label>Warehouse</label>
                <select name="warehouse">
                  <?php foreach ($warehouses as $wh): ?>
                    <option value="<?= $e((string) $wh['name']) ?>" <?= $val('warehouse') === (string) $wh['name'] ? 'selected' : '' ?>><?= $e((string) $wh['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
            <?php include __DIR__ . '/_product_hidden_core.php'; ?>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </div>
        </section>

      <?php elseif ($section === 'listings'): ?>
        <section class="sf-int-block">
          <header>Listings</header>
          <div class="sf-int-block-body">
            <div style="overflow-x:auto">
              <table class="table table-hover align-middle sf-grid">
                <thead><tr><th>Channel</th><th>SKU</th><th>Title</th><th>Status</th><th>Mapped</th></tr></thead>
                <tbody>
                  <?php if ($listings === []): ?>
                    <tr><td colspan="5" class="text-muted">No listings mapped to this product yet. Map them under Channel listings.</td></tr>
                  <?php else: foreach ($listings as $li): ?>
                    <tr>
                      <td><?= $e((string) ($li['channel_name'] ?? '')) ?></td>
                      <td><?= $e((string) ($li['sku'] ?? '')) ?></td>
                      <td><?= $e((string) ($li['title'] ?? '')) ?></td>
                      <td><span class="badge"><?= $e((string) ($li['status'] ?? '')) ?></span></td>
                      <td><?= !empty($li['mapped']) ? 'Yes' : 'No' ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
            <p style="margin:1rem 0 0"><a class="btn btn-outline-secondary" href="<?= $e($url('admin/listings')) ?>">Open Channel listings</a></p>
            <?php include __DIR__ . '/_product_hidden_core.php'; ?>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </div>
        </section>

      <?php else: ?>
        <section class="sf-int-block">
          <header>Notes</header>
          <div class="sf-int-block-body">
            <div class="sf-field"><label>Internal notes</label><textarea name="notes" rows="10"><?= $e($val('notes')) ?></textarea></div>
            <?php include __DIR__ . '/_product_hidden_core.php'; ?>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </form>

    <?php if ($editing): ?>
      <form method="post" action="<?= $e($url('admin/products/save')) ?>" id="link-variant-form">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="_action" value="variant_link">
        <input type="hidden" name="parent_id" value="<?= $e($val('id')) ?>">
        <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
      </form>
      <form method="post" action="<?= $e($url('admin/products/save')) ?>" id="new-variant-form">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="_action" value="variant_new">
        <input type="hidden" name="parent_id" value="<?= $e($val('id')) ?>">
        <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
      </form>
      <form method="post" action="<?= $e($url('admin/products/save')) ?>" id="attach-variant-attr-form">
        <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
        <input type="hidden" name="_action" value="variant_attr_attach">
        <input type="hidden" name="parent_id" value="<?= $e((string) $rootProductId) ?>">
        <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
      </form>
      <?php foreach ($variantAttrDefs as $ad): ?>
        <form method="post" action="<?= $e($url('admin/products/save')) ?>" id="detach-attr-<?= $e((string) $ad['id']) ?>"
              onsubmit="return confirm('Remove this attribute column from the variant grid?');">
          <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
          <input type="hidden" name="_action" value="variant_attr_detach">
          <input type="hidden" name="parent_id" value="<?= $e((string) $rootProductId) ?>">
          <input type="hidden" name="attribute_id" value="<?= $e((string) $ad['id']) ?>">
          <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
        </form>
      <?php endforeach; ?>
      <?php foreach ($variants as $v): ?>
        <form method="post" action="<?= $e($url('admin/products/save')) ?>" id="unlink-<?= $e((string) $v['id']) ?>" onsubmit="return confirm('Unlink this variant?');">
          <input type="hidden" name="_token" value="<?= $e($app->session->csrfToken()) ?>">
          <input type="hidden" name="_action" value="variant_unlink">
          <input type="hidden" name="product_id" value="<?= $e((string) $v['id']) ?>">
          <input type="hidden" name="return_id" value="<?= $e($val('id')) ?>">
          <input type="hidden" name="id" value="<?= $e($val('id')) ?>">
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<style>
.sf-product-shell { display:grid; grid-template-columns: 200px minmax(0,1fr); gap:1rem; align-items:start; }
.sf-product-shell.is-create { grid-template-columns: 1fr; }
.sf-product-main { min-width:0; }
.sf-product-side-nav { display:grid; gap:.15rem; background:#fff; border:1px solid #e5e7eb; border-radius:4px; padding:.4rem; }
.sf-product-side-nav a { padding:.55rem .7rem; border-radius:3px; text-decoration:none; color:#334155; font-size:.92rem; }
.sf-product-side-nav a.is-active, .sf-product-side-nav a:hover { background:#e8f1fb; color:#1557c0; }
.sf-product-layout { display:grid; grid-template-columns: minmax(0,1.4fr) minmax(0,.7fr); gap:1.25rem; align-items:start; }
.sf-product-fields { display:grid; grid-template-columns:1fr 1fr; gap:.85rem 1.1rem; }
.sf-field-with-flag { display:grid; gap:.35rem; }
.sf-mini-flag { font-size:.8rem; color:#555; display:flex; gap:.35rem; align-items:center; }
.sf-product-thumb { border:1px solid #e5e7eb; border-radius:4px; background:#f8fafc; min-height:160px; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.sf-listing-overview { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:.6rem; }
.sf-listing-chip { border:1px solid #e5e7eb; border-radius:4px; padding:.55rem .65rem; display:flex; justify-content:space-between; gap:.5rem; font-size:.86rem; }
.sf-listing-chip.is-on { background:#e7f6ec; border-color:#b7e0c2; }
.sf-listing-chip.is-off { background:#f8fafc; color:#64748b; }
.sf-variants-wrap { width:100%; max-width:100%; overflow-x:auto; }
.sf-variants-table { width:100%; table-layout:fixed; font-size:.84rem; }
.sf-variants-table th,
.sf-variants-table td {
  padding:.35rem .3rem; vertical-align:middle;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.sf-variants-table .sf-v-sku { width:16%; }
.sf-variants-table .sf-v-name { width:22%; color:#64748b; }
.sf-variants-table .sf-v-price { width:8%; }
.sf-variants-table .sf-v-inv { width:5%; text-align:right; }
.sf-variants-table .sf-v-kg { width:7%; }
.sf-variants-table .sf-v-bin { width:7%; }
.sf-variants-table .sf-v-size { width:14%; }
.sf-variants-table .sf-v-thick { width:10%; }
.sf-variants-table .sf-v-act { width:2.25rem; text-align:center; white-space:nowrap; }
.sf-variants-table .sf-v-sku a {
  display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.sf-variants-table input {
  width:100%; min-width:0; max-width:100%; box-sizing:border-box;
  padding:.2rem .3rem; font-size:.82rem;
}
.sf-variants-table .sf-v-act .btn {
  padding:.1rem .35rem; min-width:1.5rem; line-height:1.15; font-size:1rem;
}
@media (max-width: 980px) {
  .sf-product-shell, .sf-product-layout, .sf-product-fields { grid-template-columns:1fr; }
}
</style>
<script>
(function () {
  var btn = document.getElementById('ean-autofill');
  var field = document.getElementById('ean-field');
  if (btn && field) {
    btn.addEventListener('click', function () {
      if (field.value.trim() !== '') return;
      var sku = document.querySelector('[name=sku]');
      var base = (sku && sku.value ? sku.value : String(Date.now())).replace(/\D/g, '');
      while (base.length < 12) base += '0';
      base = base.slice(0, 12);
      var sum = 0;
      for (var i = 0; i < 12; i++) {
        var n = parseInt(base.charAt(i), 10);
        sum += (i % 2 === 0) ? n : n * 3;
      }
      field.value = base + String((10 - (sum % 10)) % 10);
    });
  }

  var supplierSelect = document.getElementById('product-supplier-id');
  var supplierName = document.getElementById('product-supplier-name');
  if (supplierSelect && supplierName) {
    supplierSelect.addEventListener('change', function () {
      var opt = supplierSelect.options[supplierSelect.selectedIndex];
      var name = opt ? (opt.getAttribute('data-name') || '') : '';
      if (name !== '') supplierName.value = name;
      if (!supplierSelect.value) supplierName.value = '';
    });
  }
})();
</script>
