-- Basemall 034: abandoned cart recovery. Server-side cart activity (so closing the browser needs no event), the
-- browser a guest cart belongs to (to reach guests who signed up for the newsletter), per-reminder recovery links,
-- clicks and recovered orders. Adds columns and settings only.

ALTER TABLE carts
    ADD COLUMN last_activity_at DATETIME NULL AFTER updated_at,
    ADD COLUMN visitor_id       CHAR(32) NULL AFTER token_hash,
    ADD KEY ix_cart_activity (last_activity_at),
    ADD KEY ix_cart_visitor (visitor_id);
UPDATE carts SET last_activity_at = updated_at WHERE last_activity_at IS NULL;

ALTER TABLE marketing_messages
    ADD COLUMN link_token_hash    CHAR(64)        NULL AFTER notification_id,
    ADD COLUMN clicked_at         DATETIME        NULL AFTER sent_at,
    ADD COLUMN recovered_order_id BIGINT UNSIGNED NULL AFTER clicked_at,
    ADD COLUMN recovered_amount   DECIMAL(12,2)   NULL AFTER recovered_order_id,
    ADD UNIQUE KEY uq_mm_link (link_token_hash),
    ADD KEY ix_mm_auto_sent (automation, sent_at);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('cart_abandon_minutes', '60'), ('cart_reminder_link_days', '7'), ('cart_recovery_guests', '1'), ('marketing_max_attempts', '3');

-- The first cart reminder now follows cart_abandon_minutes; the automation's own first delay is kept at 1 hour.
UPDATE marketing_automations SET delay_hours = 1 WHERE code = 'cart_recovery';
