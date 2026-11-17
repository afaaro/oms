CREATE TABLE IF NOT EXISTS `store_api_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 1,
  `user_id` int unsigned NOT NULL,
  `name` varchar(128) NOT NULL DEFAULT 'default',
  `token_hash` char(64) NOT NULL,
  `token_prefix` varchar(16) NOT NULL DEFAULT '',
  `scopes` varchar(255) NOT NULL DEFAULT '',
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sat_hash` (`token_hash`),
  KEY `sat_tenant` (`tenant_id`),
  KEY `sat_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
