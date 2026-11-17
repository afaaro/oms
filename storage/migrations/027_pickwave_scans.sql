CREATE TABLE IF NOT EXISTS `store_pickwave_scans` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `pickwave_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL DEFAULT 0,
  `sku` varchar(64) NOT NULL DEFAULT '',
  `qty_confirmed` int unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `spws_wave_sku` (`pickwave_id`,`tenant_id`,`sku`),
  KEY `spws_wave` (`pickwave_id`,`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
