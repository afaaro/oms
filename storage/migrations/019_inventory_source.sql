-- Cut-length / shared stock: longer sizes draw inventory from a base SKU

ALTER TABLE `store_products` ADD COLUMN `inventory_source_id` int unsigned DEFAULT NULL AFTER `supplier_id`;
ALTER TABLE `store_products` ADD COLUMN `inventory_unit_factor` decimal(12,4) NOT NULL DEFAULT 1.0000 AFTER `inventory_source_id`;
ALTER TABLE `store_products` ADD KEY `store_products_inv_source` (`inventory_source_id`);
