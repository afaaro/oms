-- Link products to a primary supplier

ALTER TABLE `store_products` ADD COLUMN `supplier_id` int unsigned DEFAULT NULL AFTER `supplier_name`;
ALTER TABLE `store_products` ADD KEY `store_products_supplier` (`supplier_id`);
