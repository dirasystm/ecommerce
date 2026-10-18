-- Waimart 012: seller suborders and order-item attribution.
-- The parent order (payment, customer, totals, idempotency key) is unchanged. Each parent order gets one
-- seller order per seller represented in it; fulfilment by sellers and Waimart staff is tracked there.
-- Back-filling seller orders for existing orders happens in the phase that starts maintaining them
-- (Phase 6), so no row here can drift out of step with the order workflow in the meantime.

CREATE TABLE seller_orders (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id            BIGINT UNSIGNED NOT NULL,
    seller_id           INT UNSIGNED    NOT NULL,
    sub_number          VARCHAR(24)     NOT NULL,             -- e.g. WM-7K3F9Q2X-1
    status              ENUM('pending_acceptance','accepted','rejected','processing','packed','handed_over',
                             'dispatched','out_for_delivery','delivered','ready_for_collection','collected',
                             'cancelled') NOT NULL DEFAULT 'pending_acceptance',
    items_subtotal      DECIMAL(12,2) NOT NULL,               -- merchandise at the prices charged
    discount_share      DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- coupon allocation (platform-funded by default)
    seller_discount     DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- part of the discount the seller bears
    commission_base     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    commission_total    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    seller_net          DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- earnings before refunds/adjustments
    delivery_fee_share  DECIMAL(12,2) NOT NULL DEFAULT 0.00,  -- 0 under the per-order (platform) fee policy
    is_legacy           TINYINT(1)    NOT NULL DEFAULT 0,     -- back-filled pre-marketplace order: no commission, no ledger
    accepted_at         DATETIME NULL,  accepted_by   BIGINT UNSIGNED NULL,
    rejected_at         DATETIME NULL,  rejected_by   BIGINT UNSIGNED NULL,  reject_reason VARCHAR(500) NULL,
    packed_at           DATETIME NULL,  packed_by     BIGINT UNSIGNED NULL,
    handed_over_at      DATETIME NULL,  handed_over_by BIGINT UNSIGNED NULL,
    dispatched_at       DATETIME NULL,  dispatched_by BIGINT UNSIGNED NULL,
    tracking_reference  VARCHAR(80) NULL,
    dispatch_note       VARCHAR(500) NULL,
    delivered_at        DATETIME NULL,  delivered_by  BIGINT UNSIGNED NULL,
    ready_at            DATETIME NULL,  ready_by      BIGINT UNSIGNED NULL,
    collected_at        DATETIME NULL,  collected_by  BIGINT UNSIGNED NULL,
    cancelled_at        DATETIME NULL,  cancel_reason VARCHAR(500) NULL,
    earnings_status     ENUM('not_accrued','pending','available','reversed') NOT NULL DEFAULT 'not_accrued',
    earnings_eligible_at DATETIME NULL,                       -- when the payout policy is next re-checked
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_so_number (sub_number),
    UNIQUE KEY uq_so_order_seller (order_id, seller_id),
    KEY ix_so_seller (seller_id, status, created_at),
    KEY ix_so_status (status, created_at),
    KEY ix_so_earnings (earnings_status, earnings_eligible_at),
    CONSTRAINT fk_so_order  FOREIGN KEY (order_id)  REFERENCES orders (id),
    CONSTRAINT fk_so_seller FOREIGN KEY (seller_id) REFERENCES sellers (id),
    CONSTRAINT ck_so_money CHECK (items_subtotal >= 0 AND discount_share >= 0 AND seller_discount >= 0
        AND commission_base >= 0 AND commission_total >= 0 AND delivery_fee_share >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_order_history (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_order_id  BIGINT UNSIGNED NOT NULL,
    from_status      VARCHAR(30) NULL,
    to_status        VARCHAR(30) NOT NULL,
    actor_type       ENUM('customer','seller','staff','system') NOT NULL,
    actor_user_id    BIGINT UNSIGNED NULL,
    note             VARCHAR(500) NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_soh_so (seller_order_id, id),
    CONSTRAINT fk_soh_so    FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id),
    CONSTRAINT fk_soh_actor FOREIGN KEY (actor_user_id)   REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order items: owning seller (snapshot at order time), their seller order, and the commission snapshot.
ALTER TABLE order_items
    ADD COLUMN seller_id INT UNSIGNED NULL AFTER order_id,
    ADD COLUMN seller_order_id BIGINT UNSIGNED NULL AFTER seller_id;

UPDATE order_items oi JOIN products p ON p.id = oi.product_id SET oi.seller_id = p.seller_id WHERE oi.seller_id IS NULL;

ALTER TABLE order_items
    MODIFY seller_id INT UNSIGNED NOT NULL,
    ADD COLUMN commission_rate   DECIMAL(5,2)  NULL AFTER line_total,   -- NULL = pre-marketplace item
    ADD COLUMN commission_amount DECIMAL(12,2) NULL AFTER commission_rate,
    ADD COLUMN seller_net_amount DECIMAL(12,2) NULL AFTER commission_amount,
    ADD COLUMN commission_rule_id INT UNSIGNED NULL AFTER seller_net_amount,
    ADD KEY ix_oi_seller (seller_id, order_id),
    ADD KEY ix_oi_so (seller_order_id),
    ADD CONSTRAINT fk_oi_seller FOREIGN KEY (seller_id) REFERENCES sellers (id),
    ADD CONSTRAINT fk_oi_so FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id),
    ADD CONSTRAINT ck_oi_commission CHECK (commission_rate IS NULL OR (commission_rate BETWEEN 0 AND 100)),
    ADD CONSTRAINT ck_oi_commission_amt CHECK (commission_amount IS NULL OR commission_amount >= 0);

-- Returns and refunds can be scoped to one seller order (Phase 9); NULL for pre-marketplace records.
ALTER TABLE return_requests
    ADD COLUMN seller_order_id BIGINT UNSIGNED NULL AFTER order_id,
    ADD KEY ix_rr_so (seller_order_id),
    ADD CONSTRAINT fk_rr_so FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id);

ALTER TABLE refunds
    ADD COLUMN seller_order_id BIGINT UNSIGNED NULL AFTER order_id,
    ADD KEY ix_ref_so (seller_order_id),
    ADD CONSTRAINT fk_ref_so FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id);
