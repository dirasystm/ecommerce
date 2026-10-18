-- Waimart 008: returns refund decision, review moderation notes.

ALTER TABLE return_requests
    ADD COLUMN refund_amount DECIMAL(12,2) NULL AFTER decision_note,
    ADD COLUMN completion_note VARCHAR(500) NULL AFTER refund_amount,
    ADD CONSTRAINT ck_rr_refund CHECK (refund_amount IS NULL OR refund_amount >= 0);

-- At most one open (not yet completed/rejected/cancelled) return per order.
ALTER TABLE return_requests
    ADD COLUMN open_order_key BIGINT UNSIGNED AS (IF(status IN ('requested','approved','received'), order_id, NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_rr_open_order (open_order_key);

ALTER TABLE reviews
    ADD COLUMN moderation_note VARCHAR(255) NULL AFTER moderated_at;
