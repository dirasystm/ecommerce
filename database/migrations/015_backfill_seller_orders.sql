-- Waimart 015: seller sub-orders for orders placed before the marketplace (all Waimart Official Store items).
-- Back-filled rows are marked is_legacy = 1: no commission (order_items.commission_rate stays NULL), no ledger
-- entries, so historical reports are unchanged. Order, item, payment and inventory rows are not modified, apart
-- from linking each item to its seller order. Only orders without seller orders are touched (safe to re-run logic).

INSERT INTO seller_orders (order_id, seller_id, sub_number, status, items_subtotal, discount_share, seller_discount,
                           commission_base, commission_total, seller_net, delivery_fee_share, is_legacy,
                           accepted_at, dispatched_at, dispatched_by, delivered_at, delivered_by, ready_at, ready_by,
                           collected_at, collected_by, cancelled_at, cancel_reason, earnings_status, created_at)
SELECT o.id, oi.seller_id,
       CONCAT(o.order_number, '-', DENSE_RANK() OVER (PARTITION BY o.id ORDER BY MIN(oi.id))),
       CASE o.status
           WHEN 'awaiting_payment' THEN 'pending_acceptance' WHEN 'awaiting_verification' THEN 'pending_acceptance'
           WHEN 'pending_confirmation' THEN 'pending_acceptance' WHEN 'requires_resolution' THEN 'pending_acceptance'
           WHEN 'confirmed' THEN 'accepted' WHEN 'cancelled' THEN 'cancelled' WHEN 'expired' THEN 'cancelled'
           WHEN 'return_requested' THEN IF(o.fulfilment_method = 'pickup', 'collected', 'delivered')
           WHEN 'returned' THEN IF(o.fulfilment_method = 'pickup', 'collected', 'delivered')
           ELSE o.status END,
       SUM(oi.line_total),
       IF(o.subtotal > 0, ROUND(o.discount_total * SUM(oi.line_total) / o.subtotal, 2), 0),
       0, 0, 0, SUM(oi.line_total), 0, 1,
       o.confirmed_at, f.dispatched_at, f.dispatched_by, f.delivered_at, f.delivered_by, f.ready_at, f.ready_by,
       f.collected_at, f.collected_by,
       IF(o.status IN ('cancelled','expired'), COALESCE(o.cancelled_at, o.updated_at), NULL),
       IF(o.status = 'expired', 'Order expired before confirmation', o.cancel_reason),
       'not_accrued', o.placed_at
  FROM orders o
  JOIN order_items oi ON oi.order_id = o.id
  LEFT JOIN order_fulfilment f ON f.order_id = o.id
 WHERE NOT EXISTS (SELECT 1 FROM seller_orders x WHERE x.order_id = o.id)
 GROUP BY o.id, oi.seller_id;

UPDATE order_items oi
  JOIN seller_orders so ON so.order_id = oi.order_id AND so.seller_id = oi.seller_id
   SET oi.seller_order_id = so.id
 WHERE oi.seller_order_id IS NULL;

INSERT INTO seller_order_history (seller_order_id, from_status, to_status, actor_type, note, created_at)
SELECT so.id, NULL, so.status, 'system', 'Created from a pre-marketplace order', NOW()
  FROM seller_orders so
 WHERE so.is_legacy = 1 AND NOT EXISTS (SELECT 1 FROM seller_order_history h WHERE h.seller_order_id = so.id);

-- From now on every order item belongs to a seller order.
ALTER TABLE order_items MODIFY seller_order_id BIGINT UNSIGNED NOT NULL;
