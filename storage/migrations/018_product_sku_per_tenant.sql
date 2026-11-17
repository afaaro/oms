-- SKU uniqueness is per tenant (multi-tenant isolation)

ALTER TABLE `store_products` DROP INDEX `store_products_sku`;
ALTER TABLE `store_products` ADD UNIQUE KEY `store_products_tenant_sku` (`tenant_id`, `sku`);
