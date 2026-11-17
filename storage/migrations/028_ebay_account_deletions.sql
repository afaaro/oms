CREATE TABLE IF NOT EXISTS `store_ebay_account_deletions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `notification_id` varchar(64) NOT NULL DEFAULT '',
  `ebay_username` varchar(128) NOT NULL DEFAULT '',
  `ebay_user_id` varchar(128) NOT NULL DEFAULT '',
  `eias_token` varchar(255) NOT NULL DEFAULT '',
  `orders_redacted` int unsigned NOT NULL DEFAULT 0,
  `messages_redacted` int unsigned NOT NULL DEFAULT 0,
  `customers_redacted` int unsigned NOT NULL DEFAULT 0,
  `payload` json DEFAULT NULL,
  `processed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sead_notification` (`notification_id`),
  KEY `sead_username` (`ebay_username`),
  KEY `sead_user_id` (`ebay_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
