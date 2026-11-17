-- An order may only appear once on open pickwaves (enforce uniqueness per tenant/order)
-- Clean duplicates first: keep the lowest pickwave_id link per order
DELETE po FROM `store_pickwave_orders` po
INNER JOIN `store_pickwave_orders` po2
  ON po.tenant_id = po2.tenant_id
 AND po.order_id = po2.order_id
 AND po.pickwave_id > po2.pickwave_id;

ALTER TABLE `store_pickwave_orders`
  ADD UNIQUE KEY `store_pickwave_orders_tenant_order` (`tenant_id`, `order_id`);
