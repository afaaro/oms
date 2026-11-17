<?php
/** Preserve core fields when saving a non-general product section. */
/** @var callable $e */
/** @var array<string,mixed> $p */
/** @var callable $val */
?>
<input type="hidden" name="name" value="<?= $e($val('name')) ?>">
<input type="hidden" name="brand" value="<?= $e($val('brand')) ?>">
<input type="hidden" name="category_id" value="<?= $e($val('category_id')) ?>">
<?php if (($section ?? '') !== 'description'): ?>
  <input type="hidden" name="description" value="<?= $e($val('description')) ?>">
<?php endif; ?>
<input type="hidden" name="barcode" value="<?= $e($val('barcode')) ?>">
<input type="hidden" name="upc" value="<?= $e($val('upc')) ?>">
<?php if (($section ?? '') !== 'images'): ?>
  <input type="hidden" name="image" value="<?= $e($val('image')) ?>">
<?php endif; ?>
<?php if (($section ?? '') !== 'shipping'): ?>
  <input type="hidden" name="warehouse" value="<?= $e($val('warehouse', 'Default')) ?>">
  <input type="hidden" name="pick_bin" value="<?= $e($val('pick_bin')) ?>">
  <input type="hidden" name="weight_kg" value="<?= $e(number_format(((int) ($p['weight_grams'] ?? 0)) / 1000, 3, '.', '')) ?>">
  <input type="hidden" name="length_mm" value="<?= $e($val('length_mm', '0')) ?>">
  <input type="hidden" name="width_mm" value="<?= $e($val('width_mm', '0')) ?>">
  <input type="hidden" name="height_mm" value="<?= $e($val('height_mm', '0')) ?>">
<?php endif; ?>
<input type="hidden" name="price" value="<?= $e($val('price', '0')) ?>">
<input type="hidden" name="rrp" value="<?= $e($val('rrp', '0')) ?>">
<input type="hidden" name="cost" value="<?= $e($val('cost', '0')) ?>">
<input type="hidden" name="supplier_id" value="<?= $e($val('supplier_id')) ?>">
<input type="hidden" name="supplier_sku" value="<?= $e($val('supplier_sku')) ?>">
<input type="hidden" name="supplier_name" value="<?= $e($val('supplier_name')) ?>">
<input type="hidden" name="tax_class" value="<?= $e($val('tax_class', '20')) ?>">
<?php if (!empty($p['price_includes_vat'])): ?><input type="hidden" name="price_includes_vat" value="1"><?php endif; ?>
<?php if (($section ?? '') !== 'notes'): ?>
  <input type="hidden" name="notes" value="<?= $e($val('notes')) ?>">
<?php endif; ?>
<?php if (!empty($p['active'])): ?><input type="hidden" name="active" value="1"><?php endif; ?>
<?php
  /** @var int $inventoryQty */
  $invHidden = isset($inventoryQty) ? (int) $inventoryQty : (int) ($p['inventory_qty'] ?? 0);
?>
<input type="hidden" name="inventory_qty" value="<?= $e((string) $invHidden) ?>">
