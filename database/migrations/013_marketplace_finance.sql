-- Waimart 013: commission rules, seller ledger, payouts, disputes.

-- Commission rules. Precedence: seller > category > default. At most one ACTIVE rule per scope target.
-- The rate in force is snapshotted on each order item (order_items.commission_rate / commission_rule_id),
-- so changing or deactivating a rule never alters past orders. Rules are not edited: a change deactivates
-- the old rule and adds a new one, keeping the history.
CREATE TABLE commission_rules (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope         ENUM('default','category','seller') NOT NULL,
    category_id   INT UNSIGNED NULL,
    seller_id     INT UNSIGNED NULL,
    rate          DECIMAL(5,2) NOT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    note          VARCHAR(255) NULL,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_by BIGINT UNSIGNED NULL,
    deactivated_at DATETIME NULL,
    active_key    VARCHAR(40) AS (IF(is_active = 1, CONCAT(scope, ':', COALESCE(category_id, seller_id, 0)), NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cr_active (active_key),
    KEY ix_cr_seller (seller_id),
    KEY ix_cr_category (category_id),
    CONSTRAINT fk_cr_category FOREIGN KEY (category_id) REFERENCES categories (id),
    CONSTRAINT fk_cr_seller   FOREIGN KEY (seller_id)   REFERENCES sellers (id),
    CONSTRAINT fk_cr_creator  FOREIGN KEY (created_by)  REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cr_deact    FOREIGN KEY (deactivated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_cr_rate  CHECK (rate BETWEEN 0 AND 100),
    CONSTRAINT ck_cr_scope CHECK ((scope = 'default'  AND category_id IS NULL     AND seller_id IS NULL)
                               OR (scope = 'category' AND category_id IS NOT NULL AND seller_id IS NULL)
                               OR (scope = 'seller'   AND seller_id IS NOT NULL   AND category_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Decision 4: provisional 10% default until the client confirms the rate. The Official Store pays none.
INSERT INTO commission_rules (scope, rate, note) VALUES ('default', 10.00, 'Provisional default — confirm the business rate');
INSERT INTO commission_rules (scope, seller_id, rate, note) VALUES ('seller', 1, 0.00, 'Waimart Official Store (platform-owned)');

ALTER TABLE order_items
    ADD CONSTRAINT fk_oi_commission_rule FOREIGN KEY (commission_rule_id) REFERENCES commission_rules (id);

-- Payout requests. At most one open (requested/approved) request per seller.
CREATE TABLE seller_payouts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_number    VARCHAR(20) NOT NULL,
    seller_id        INT UNSIGNED NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    status           ENUM('requested','approved','paid','rejected','cancelled') NOT NULL DEFAULT 'requested',
    payout_method_id BIGINT UNSIGNED NULL,
    destination_masked VARCHAR(80) NULL,                     -- snapshot shown to admins and the seller
    requested_by     BIGINT UNSIGNED NULL,
    requested_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by       BIGINT UNSIGNED NULL,
    decided_at       DATETIME NULL,
    decision_note    VARCHAR(500) NULL,                      -- rejection reason
    paid_amount      DECIMAL(12,2) NULL,
    paid_method      ENUM('mpesa','bank','cash','other') NULL,
    paid_reference   VARCHAR(80) NULL,
    paid_by          BIGINT UNSIGNED NULL,
    paid_at          DATETIME NULL,
    open_seller_key  INT UNSIGNED AS (IF(status IN ('requested','approved'), seller_id, NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sp_number (payout_number),
    UNIQUE KEY uq_sp_open (open_seller_key),
    UNIQUE KEY uq_sp_reference (paid_method, paid_reference),
    KEY ix_sp_seller (seller_id, requested_at),
    KEY ix_sp_status (status, requested_at),
    CONSTRAINT fk_sp_seller    FOREIGN KEY (seller_id)        REFERENCES sellers (id),
    CONSTRAINT fk_sp_method    FOREIGN KEY (payout_method_id) REFERENCES seller_payout_methods (id),
    CONSTRAINT fk_sp_requester FOREIGN KEY (requested_by)     REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_sp_decider   FOREIGN KEY (decided_by)       REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_sp_payer     FOREIGN KEY (paid_by)          REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_sp_amount CHECK (amount > 0),
    CONSTRAINT ck_sp_paid   CHECK (status <> 'paid' OR (paid_amount = amount AND paid_reference IS NOT NULL AND paid_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_payout_history (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payout_id      BIGINT UNSIGNED NOT NULL,
    from_status    VARCHAR(20) NULL,
    to_status      VARCHAR(20) NOT NULL,
    actor_user_id  BIGINT UNSIGNED NULL,
    note           VARCHAR(500) NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_sph_payout (payout_id, id),
    CONSTRAINT fk_sph_payout FOREIGN KEY (payout_id)     REFERENCES seller_payouts (id),
    CONSTRAINT fk_sph_actor  FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seller ledger: append-only, signed amounts in four buckets. A seller's balances are SUM(amount) per bucket;
-- nothing stores a balance. Moving money between buckets is two rows (minus one bucket, plus another).
-- posting_key makes every posting exactly-once (e.g. 'sale:so:12', 'release:so:12:available',
-- 'payout:7:request:reserved'); corrections are new rows referencing the original via reverses_entry_id.
CREATE TABLE seller_ledger_entries (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id          INT UNSIGNED NOT NULL,
    entry_type         ENUM('sale','commission','release','refund','commission_reversal','cancellation',
                            'payout_request','payout_return','payout_paid','correction') NOT NULL,
    bucket             ENUM('pending','available','reserved','paid') NOT NULL,
    amount             DECIMAL(12,2) NOT NULL,
    posting_key        VARCHAR(80) NOT NULL,
    seller_order_id    BIGINT UNSIGNED NULL,
    order_item_id      BIGINT UNSIGNED NULL,
    payout_id          BIGINT UNSIGNED NULL,
    refund_id          BIGINT UNSIGNED NULL,
    reverses_entry_id  BIGINT UNSIGNED NULL,
    actor_user_id      BIGINT UNSIGNED NULL,
    note               VARCHAR(500) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sle_posting (posting_key),
    KEY ix_sle_seller (seller_id, bucket, created_at),
    KEY ix_sle_so (seller_order_id),
    KEY ix_sle_payout (payout_id),
    CONSTRAINT fk_sle_seller  FOREIGN KEY (seller_id)         REFERENCES sellers (id),
    CONSTRAINT fk_sle_so      FOREIGN KEY (seller_order_id)   REFERENCES seller_orders (id),
    CONSTRAINT fk_sle_oi      FOREIGN KEY (order_item_id)     REFERENCES order_items (id),
    CONSTRAINT fk_sle_payout  FOREIGN KEY (payout_id)         REFERENCES seller_payouts (id),
    CONSTRAINT fk_sle_refund  FOREIGN KEY (refund_id)         REFERENCES refunds (id),
    CONSTRAINT fk_sle_reverse FOREIGN KEY (reverses_entry_id) REFERENCES seller_ledger_entries (id),
    CONSTRAINT fk_sle_actor   FOREIGN KEY (actor_user_id)     REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_sle_amount CHECK (amount <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Disputes on a seller order, resolved by Waimart staff. An open dispute holds payout eligibility.
CREATE TABLE seller_disputes (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispute_number   VARCHAR(20) NOT NULL,
    order_id         BIGINT UNSIGNED NOT NULL,
    seller_order_id  BIGINT UNSIGNED NOT NULL,
    raised_by        BIGINT UNSIGNED NULL,
    raised_by_role   ENUM('customer','seller','staff') NOT NULL,
    reason           VARCHAR(100) NOT NULL,
    details          VARCHAR(2000) NULL,
    status           ENUM('open','awaiting_seller','awaiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
    resolution       ENUM('no_action','refund_full','refund_partial','return_required','seller_favoured','other') NULL,
    resolution_note  VARCHAR(1000) NULL,
    resolved_by      BIGINT UNSIGNED NULL,
    resolved_at      DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_so_key      BIGINT UNSIGNED AS (IF(status IN ('open','awaiting_seller','awaiting_customer'), seller_order_id, NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sdp_number (dispute_number),
    UNIQUE KEY uq_sdp_open (open_so_key),                    -- one open dispute per seller order
    KEY ix_sdp_status (status, created_at),
    KEY ix_sdp_order (order_id),
    CONSTRAINT fk_sdp_order    FOREIGN KEY (order_id)        REFERENCES orders (id),
    CONSTRAINT fk_sdp_so       FOREIGN KEY (seller_order_id) REFERENCES seller_orders (id),
    CONSTRAINT fk_sdp_raiser   FOREIGN KEY (raised_by)       REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_sdp_resolver FOREIGN KEY (resolved_by)     REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_dispute_messages (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispute_id   BIGINT UNSIGNED NOT NULL,
    author_id    BIGINT UNSIGNED NULL,
    author_role  ENUM('customer','seller','staff') NOT NULL,
    body         VARCHAR(2000) NOT NULL,
    is_internal  TINYINT(1) NOT NULL DEFAULT 0,               -- staff-only note, never shown to customer/seller
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_sdm_dispute (dispute_id, id),
    CONSTRAINT fk_sdm_dispute FOREIGN KEY (dispute_id) REFERENCES seller_disputes (id),
    CONSTRAINT fk_sdm_author  FOREIGN KEY (author_id)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
