-- Variant attribute labels (Size / Thickness) + pick bin

ALTER TABLE `store_products` ADD COLUMN `attr_size` varchar(128) NOT NULL DEFAULT '' AFTER `parent_id`;
ALTER TABLE `store_products` ADD COLUMN `attr_thickness` varchar(64) NOT NULL DEFAULT '' AFTER `attr_size`;
ALTER TABLE `store_products` ADD COLUMN `pick_bin` varchar(64) NOT NULL DEFAULT '' AFTER `warehouse`;
