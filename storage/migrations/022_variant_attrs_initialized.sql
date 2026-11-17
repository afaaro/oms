-- Remember that variant-attribute columns were initialized for a parent product
-- so removing Size does not cause it to be auto-added again on the next load.

ALTER TABLE `store_products`
  ADD COLUMN `variant_attrs_initialized` tinyint(1) NOT NULL DEFAULT 0 AFTER `inventory_unit_factor`;

-- Parents that already have variant-attribute columns are initialized.
UPDATE `store_products` p
SET p.variant_attrs_initialized = 1
WHERE EXISTS (
  SELECT 1 FROM `store_product_variant_attributes` pva
  WHERE pva.product_id = p.id AND pva.tenant_id = p.tenant_id
);
