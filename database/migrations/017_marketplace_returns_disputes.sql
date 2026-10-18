-- Waimart 017: returns per seller part, refunds linked to disputes.
-- Additive for data: no row changes. The open-return rule moves from "one per order" to "one per seller part", so
-- returning one seller's items never blocks or touches another seller's part. Returns recorded before this
-- migration (no seller part) keep the old per-order rule.

ALTER TABLE return_requests DROP INDEX uq_rr_open_order;
ALTER TABLE return_requests DROP COLUMN open_order_key;
ALTER TABLE return_requests
    ADD COLUMN open_order_key BIGINT UNSIGNED AS (IF(status IN ('requested','approved','received') AND seller_order_id IS NULL, order_id, NULL)) PERSISTENT,
    ADD COLUMN open_so_key    BIGINT UNSIGNED AS (IF(status IN ('requested','approved','received'), seller_order_id, NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_rr_open_order (open_order_key),
    ADD UNIQUE KEY uq_rr_open_so (open_so_key);

ALTER TABLE refunds
    ADD COLUMN dispute_id BIGINT UNSIGNED NULL AFTER return_id,
    ADD KEY ix_ref_dispute (dispute_id),
    ADD CONSTRAINT fk_ref_dispute FOREIGN KEY (dispute_id) REFERENCES seller_disputes (id);
