-- Which attribute columns appear on a parent product's variant grid

CREATE TABLE IF NOT EXISTS `store_product_variant_attributes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `attribute_id` int unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_pva_product_attr` (`product_id`, `attribute_id`),
  KEY `store_pva_tenant` (`tenant_id`),
  KEY `store_pva_attr` (`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deactivate duplicate attribute names per tenant (keep lowest id)
UPDATE `store_attributes` a
INNER JOIN `store_attributes` b
  ON b.tenant_id = a.tenant_id
 AND LOWER(b.name) = LOWER(a.name)
 AND b.id < a.id
SET a.active = 0
WHERE a.active = 1;
