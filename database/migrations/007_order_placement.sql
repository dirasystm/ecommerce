-- Waimart 007: order placement settings and lookup support.

INSERT INTO settings (setting_key, setting_value) VALUES
('reservation_minutes_cod', '240')        -- COD orders wait for staff confirmation; M-Pesa uses reservation_minutes (30)
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Guest "track order" lookups are by order number + phone.
ALTER TABLE orders
    ADD KEY ix_order_number_phone (order_number, customer_phone);

-- Claims: at most one pending claim per user/order pair at a time is enforced in code;
-- index for the lookup.
ALTER TABLE order_claims
    ADD KEY ix_oc_user_order (user_id, order_id, verified_at);
