-- Ecommerce 027: order journey and arrival estimates (Delivery settings). Additive.
-- Working days (Monday to Saturday) used for the estimated arrival / ready-for-collection dates on order tracking.

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('dispatch_lead_days',    '1'),
('pickup_ready_min_days', '1'),
('pickup_ready_max_days', '3');
