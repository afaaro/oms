-- Packaging sizes, and an optional size on each shipping method.

CREATE TABLE IF NOT EXISTS `store_packaging_sizes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `name` varchar(64) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sps_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `store_shipping_methods`
  ADD COLUMN `packaging_size_id` int unsigned DEFAULT NULL AFTER `service_code`;
