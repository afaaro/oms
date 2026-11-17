-- Product Attribute Manager: definable attributes + product values

CREATE TABLE IF NOT EXISTS `store_attributes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `name` varchar(128) NOT NULL,
  `code` varchar(64) NOT NULL DEFAULT '',
  `sort_order` int NOT NULL DEFAULT 0,
  `show_on_variants` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_attributes_tenant_code` (`tenant_id`, `code`),
  KEY `store_attributes_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `store_attribute_values` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `attribute_id` int unsigned NOT NULL,
  `value` varchar(255) NOT NULL,
  `sort_order` int NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `store_attribute_values_attr` (`attribute_id`),
  KEY `store_attribute_values_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `store_product_attributes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  `attribute_id` int unsigned NOT NULL,
  `attribute_value_id` int unsigned DEFAULT NULL,
  `value_text` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `store_product_attributes_uniq` (`product_id`, `attribute_id`),
  KEY `store_product_attributes_tenant` (`tenant_id`),
  KEY `store_product_attributes_attr` (`attribute_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed Size / Thickness for every tenant
INSERT INTO `store_attributes` (`tenant_id`, `name`, `code`, `sort_order`, `show_on_variants`, `active`)
SELECT t.id, 'Size', 'size', 10, 1, 1
FROM `store_tenants` t
WHERE NOT EXISTS (
  SELECT 1 FROM `store_attributes` a WHERE a.tenant_id = t.id AND a.code = 'size'
);

INSERT INTO `store_attributes` (`tenant_id`, `name`, `code`, `sort_order`, `show_on_variants`, `active`)
SELECT t.id, 'Thickness', 'thickness', 20, 1, 1
FROM `store_tenants` t
WHERE NOT EXISTS (
  SELECT 1 FROM `store_attributes` a WHERE a.tenant_id = t.id AND a.code = 'thickness'
);

-- Migrate legacy product columns into EAV
INSERT INTO `store_product_attributes` (`tenant_id`, `product_id`, `attribute_id`, `value_text`)
SELECT p.tenant_id, p.id, a.id, p.attr_size
FROM `store_products` p
INNER JOIN `store_attributes` a ON a.tenant_id = p.tenant_id AND a.code = 'size'
WHERE TRIM(p.attr_size) <> ''
  AND NOT EXISTS (
    SELECT 1 FROM `store_product_attributes` pa
    WHERE pa.product_id = p.id AND pa.attribute_id = a.id
  );

INSERT INTO `store_product_attributes` (`tenant_id`, `product_id`, `attribute_id`, `value_text`)
SELECT p.tenant_id, p.id, a.id, p.attr_thickness
FROM `store_products` p
INNER JOIN `store_attributes` a ON a.tenant_id = p.tenant_id AND a.code = 'thickness'
WHERE TRIM(p.attr_thickness) <> ''
  AND NOT EXISTS (
    SELECT 1 FROM `store_product_attributes` pa
    WHERE pa.product_id = p.id AND pa.attribute_id = a.id
  );
