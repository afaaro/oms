-- Product parent/variant linking

ALTER TABLE `store_products` ADD COLUMN `parent_id` int unsigned DEFAULT NULL AFTER `tenant_id`;
ALTER TABLE `store_products` ADD KEY `store_products_parent` (`parent_id`);
