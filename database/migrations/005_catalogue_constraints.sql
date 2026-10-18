-- Waimart 005: catalogue integrity for admin management.

-- At most one default variant per product (generated unique key, like active reservations).
ALTER TABLE product_variants
    ADD COLUMN default_product_key BIGINT UNSIGNED AS (IF(is_default = 1, product_id, NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_var_default (default_product_key);

-- Listing sort / filter support.
ALTER TABLE products
    ADD KEY ix_prod_created (is_active, created_at);

-- Reason codes for manual stock changes (receipts and adjustments), for auditability.
ALTER TABLE stock_movements
    ADD COLUMN reason VARCHAR(40) NULL AFTER actor_user_id;
