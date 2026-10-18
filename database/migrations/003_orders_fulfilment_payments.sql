-- Waimart 003: delivery zones, pickup points, carts, coupons, orders & snapshots, stock reservations,
-- stock movement ledger, payments, COD collection, resolutions, returns, refunds, reviews, guest access.

CREATE TABLE delivery_zones (
    id                      INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                    VARCHAR(100) NOT NULL,
    description             VARCHAR(255) NULL,
    fee                     DECIMAL(12,2) NOT NULL,
    free_delivery_threshold DECIMAL(12,2) NULL,          -- NULL = never free
    min_days                TINYINT UNSIGNED NOT NULL,
    max_days                TINYINT UNSIGNED NOT NULL,
    cod_allowed             TINYINT(1) NOT NULL DEFAULT 1,
    is_active               TINYINT(1) NOT NULL DEFAULT 1,
    sort_order              INT NOT NULL DEFAULT 0,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_zone_name (name),
    CONSTRAINT ck_zone_fee CHECK (fee >= 0),
    CONSTRAINT ck_zone_days CHECK (max_days >= min_days)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- town = '' means the whole county. A county/town pair belongs to at most one zone.
CREATE TABLE delivery_zone_locations (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    zone_id   INT UNSIGNED NOT NULL,
    county_id SMALLINT UNSIGNED NOT NULL,
    town      VARCHAR(100) NOT NULL DEFAULT '',
    UNIQUE KEY uq_dzl_location (county_id, town),
    KEY ix_dzl_zone (zone_id),
    CONSTRAINT fk_dzl_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones(id) ON DELETE CASCADE,
    CONSTRAINT fk_dzl_county FOREIGN KEY (county_id) REFERENCES counties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pickup_points (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code            VARCHAR(20) NOT NULL,                 -- unique public identifier, e.g. NBO-CBD-01
    name            VARCHAR(120) NOT NULL,
    county_id       SMALLINT UNSIGNED NOT NULL,
    town            VARCHAR(100) NOT NULL,
    address         VARCHAR(255) NOT NULL,
    latitude        DECIMAL(10,7) NULL,
    longitude       DECIMAL(10,7) NULL,
    map_url         VARCHAR(500) NULL,
    phone           VARCHAR(15) NULL,
    email           VARCHAR(190) NULL,
    opening_hours   VARCHAR(500) NOT NULL,
    instructions    VARCHAR(1000) NULL,
    pickup_fee      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cod_allowed     TINYINT(1) NOT NULL DEFAULT 1,
    max_order_value DECIMAL(12,2) NULL,                   -- optional restriction
    daily_capacity  SMALLINT UNSIGNED NULL,               -- optional: max new orders per day
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pp_code (code),
    KEY ix_pp_active (is_active, county_id),
    CONSTRAINT fk_pp_county FOREIGN KEY (county_id) REFERENCES counties(id),
    CONSTRAINT ck_pp_fee CHECK (pickup_fee >= 0),
    CONSTRAINT ck_pp_lat CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
    CONSTRAINT ck_pp_lng CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carts never reserve stock (D4). Guest carts are found by a hashed cookie token.
CREATE TABLE carts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NULL,
    token_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cart_user (user_id),
    UNIQUE KEY uq_cart_token (token_hash),
    KEY ix_cart_updated (updated_at),
    CONSTRAINT fk_cart_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cart_items (
    cart_id    BIGINT UNSIGNED NOT NULL,
    variant_id BIGINT UNSIGNED NOT NULL,
    quantity   SMALLINT UNSIGNED NOT NULL,
    added_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cart_id, variant_id),
    CONSTRAINT fk_ci_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    CONSTRAINT fk_ci_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    CONSTRAINT ck_ci_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE coupons (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(40) NOT NULL,
    description        VARCHAR(255) NULL,
    discount_type      ENUM('percent','fixed') NOT NULL,
    discount_value     DECIMAL(12,2) NOT NULL,
    min_order_amount   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    max_discount       DECIMAL(12,2) NULL,
    starts_at          DATETIME NULL,
    ends_at            DATETIME NULL,
    usage_limit        INT UNSIGNED NULL,
    used_count         INT UNSIGNED NOT NULL DEFAULT 0,
    per_customer_limit INT UNSIGNED NOT NULL DEFAULT 1,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupon_code (code),
    CONSTRAINT ck_coupon_value CHECK (discount_value > 0),
    CONSTRAINT ck_coupon_pct CHECK (discount_type <> 'percent' OR discount_value <= 100),
    CONSTRAINT ck_coupon_usage CHECK (usage_limit IS NULL OR used_count <= usage_limit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE orders (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_number        VARCHAR(20) NOT NULL,              -- random, non-sequential (WM-XXXXXXXX)
    idempotency_key     CHAR(43) NOT NULL,                 -- C2: duplicate-submission protection
    user_id             BIGINT UNSIGNED NULL,              -- NULL = guest order
    status              ENUM('awaiting_payment','awaiting_verification','pending_confirmation','confirmed',
                             'processing','packed','dispatched','out_for_delivery','delivered',
                             'ready_for_collection','collected','cancelled','expired','requires_resolution',
                             'return_requested','returned') NOT NULL,
    payment_method      ENUM('cod','mpesa_phone','mpesa_till','mpesa_paybill') NOT NULL,
    payment_status      ENUM('unpaid','awaiting_verification','verified','rejected','cod_pending',
                             'cod_collected','refund_due','refunded') NOT NULL,
    fulfilment_method   ENUM('delivery','pickup') NOT NULL,
    customer_name       VARCHAR(160) NOT NULL,
    customer_phone      VARCHAR(15) NOT NULL,
    customer_email      VARCHAR(190) NULL,
    subtotal            DECIMAL(12,2) NOT NULL,
    discount_total      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    delivery_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    grand_total         DECIMAL(12,2) NOT NULL,
    coupon_code         VARCHAR(40) NULL,
    customer_notes      VARCHAR(500) NULL,
    requires_resolution TINYINT(1) NOT NULL DEFAULT 0,
    placed_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    confirmed_at        DATETIME NULL,
    cancelled_at        DATETIME NULL,
    cancel_reason       VARCHAR(255) NULL,
    completed_at        DATETIME NULL,                     -- delivered or collected
    ip_address          VARCHAR(45) NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_number (order_number),
    UNIQUE KEY uq_order_idem (idempotency_key),
    KEY ix_order_user (user_id, placed_at),
    KEY ix_order_status (status, placed_at),
    KEY ix_order_payment (payment_status, placed_at),
    KEY ix_order_phone (customer_phone),
    KEY ix_order_resolution (requires_resolution),
    CONSTRAINT fk_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT ck_order_totals CHECK (subtotal >= 0 AND discount_total >= 0 AND delivery_fee >= 0 AND grand_total >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of what was bought, at the price paid (E: historical preservation).
CREATE TABLE order_items (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id      BIGINT UNSIGNED NOT NULL,
    product_id    BIGINT UNSIGNED NOT NULL,
    variant_id    BIGINT UNSIGNED NOT NULL,
    sku           VARCHAR(64) NOT NULL,
    product_name  VARCHAR(200) NOT NULL,
    variant_name  VARCHAR(120) NOT NULL,
    image_path    VARCHAR(255) NULL,
    unit_price    DECIMAL(12,2) NOT NULL,
    quantity      SMALLINT UNSIGNED NOT NULL,
    line_total    DECIMAL(12,2) NOT NULL,
    fulfilled_qty SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    returned_qty  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_oi_variant (order_id, variant_id),
    KEY ix_oi_variant (variant_id),
    KEY ix_oi_product (product_id),
    CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_oi_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_oi_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT ck_oi_qty CHECK (quantity > 0),
    CONSTRAINT ck_oi_fulfilled CHECK (fulfilled_qty <= quantity),
    CONSTRAINT ck_oi_returned CHECK (returned_qty <= fulfilled_qty)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery address or pickup-point snapshot, plus collection / delivery confirmation.
CREATE TABLE order_fulfilment (
    order_id                BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    method                  ENUM('delivery','pickup') NOT NULL,
    -- delivery snapshot
    county_name             VARCHAR(60) NULL,
    town                    VARCHAR(100) NULL,
    location                VARCHAR(255) NULL,
    delivery_instructions   VARCHAR(500) NULL,
    zone_id                 INT UNSIGNED NULL,
    zone_name               VARCHAR(100) NULL,
    eta_min_days            TINYINT UNSIGNED NULL,
    eta_max_days            TINYINT UNSIGNED NULL,
    -- pickup snapshot
    pickup_point_id         INT UNSIGNED NULL,
    pickup_code             VARCHAR(20) NULL,
    pickup_name             VARCHAR(120) NULL,
    pickup_county           VARCHAR(60) NULL,
    pickup_town             VARCHAR(100) NULL,
    pickup_address          VARCHAR(255) NULL,
    pickup_map_url          VARCHAR(500) NULL,
    pickup_phone            VARCHAR(15) NULL,
    pickup_hours            VARCHAR(500) NULL,
    pickup_instructions     VARCHAR(1000) NULL,
    -- collection credential (B4): hashed for verification, encrypted for re-display to the owner
    collection_code_hash    CHAR(64) NULL,
    collection_code_enc     VARCHAR(255) NULL,
    collection_code_status  ENUM('none','active','used','invalidated') NOT NULL DEFAULT 'none',
    ready_at                DATETIME NULL,
    ready_by                BIGINT UNSIGNED NULL,
    collected_at            DATETIME NULL,
    collected_by            BIGINT UNSIGNED NULL,
    collection_outcome      VARCHAR(255) NULL,
    -- delivery confirmation (B5)
    dispatched_at           DATETIME NULL,
    dispatched_by           BIGINT UNSIGNED NULL,
    delivered_at            DATETIME NULL,
    delivered_by            BIGINT UNSIGNED NULL,
    delivery_confirmation   VARCHAR(255) NULL,
    UNIQUE KEY uq_of_code_hash (collection_code_hash),
    KEY ix_of_pickup (pickup_point_id),
    CONSTRAINT fk_of_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_of_zone FOREIGN KEY (zone_id) REFERENCES delivery_zones(id) ON DELETE SET NULL,
    CONSTRAINT fk_of_pp FOREIGN KEY (pickup_point_id) REFERENCES pickup_points(id) ON DELETE SET NULL,
    CONSTRAINT fk_of_ready_by FOREIGN KEY (ready_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_of_collected_by FOREIGN KEY (collected_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_of_dispatched_by FOREIGN KEY (dispatched_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_of_delivered_by FOREIGN KEY (delivered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id      BIGINT UNSIGNED NOT NULL,
    from_status   VARCHAR(30) NULL,
    to_status     VARCHAR(30) NOT NULL,
    actor_type    ENUM('customer','staff','system') NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    note          VARCHAR(500) NULL,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_osh_order (order_id, created_at),
    CONSTRAINT fk_osh_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_osh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- D1: guest access tokens. Only a keyed hash is stored.
CREATE TABLE order_access_tokens (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id   BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_oat_hash (token_hash),
    KEY ix_oat_order (order_id),
    CONSTRAINT fk_oat_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- D3: verified guest-to-account linking.
CREATE TABLE order_claims (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NOT NULL,
    order_id    BIGINT UNSIGNED NOT NULL,
    channel     ENUM('email','sms') NOT NULL,
    code_hash   CHAR(64) NOT NULL,
    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    verified_at DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_oc_user (user_id),
    KEY ix_oc_order (order_id),
    CONSTRAINT fk_oc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_oc_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- C3: reservation ledger. At most one ACTIVE reservation per order item (generated unique key).
CREATE TABLE stock_reservations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id        BIGINT UNSIGNED NOT NULL,
    order_item_id   BIGINT UNSIGNED NOT NULL,
    variant_id      BIGINT UNSIGNED NOT NULL,
    quantity        INT UNSIGNED NOT NULL,
    status          ENUM('active','released','expired','consumed') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at      DATETIME NULL,                         -- NULL = held until fulfilment (order confirmed)
    released_at     DATETIME NULL,
    release_reason  VARCHAR(100) NULL,
    consumed_at     DATETIME NULL,
    active_item_key BIGINT UNSIGNED AS (IF(status = 'active', order_item_id, NULL)) PERSISTENT,
    UNIQUE KEY uq_sr_active_item (active_item_key),
    KEY ix_sr_due (status, expires_at),
    KEY ix_sr_order (order_id, status),
    KEY ix_sr_variant (variant_id, status),
    CONSTRAINT fk_sr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sr_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sr_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT ck_sr_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

-- Append-only stock ledger (A6). Each reservation transition is recorded exactly once.
CREATE TABLE stock_movements (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    variant_id     BIGINT UNSIGNED NOT NULL,
    movement_type  ENUM('receipt','adjustment','reserve','release','expire','consume',
                        'return_restock','return_writeoff') NOT NULL,
    on_hand_delta  INT NOT NULL DEFAULT 0,
    reserved_delta INT NOT NULL DEFAULT 0,
    on_hand_after  INT NOT NULL,
    reserved_after INT NOT NULL,
    reservation_id BIGINT UNSIGNED NULL,
    return_item_id BIGINT UNSIGNED NULL,
    order_id       BIGINT UNSIGNED NULL,
    actor_type     ENUM('customer','staff','system') NOT NULL,
    actor_user_id  BIGINT UNSIGNED NULL,
    note           VARCHAR(255) NULL,
    created_at     DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_sm_reservation (reservation_id, movement_type),
    UNIQUE KEY uq_sm_return (return_item_id, movement_type),
    KEY ix_sm_variant (variant_id, created_at),
    KEY ix_sm_order (order_id),
    CONSTRAINT fk_sm_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_res FOREIGN KEY (reservation_id) REFERENCES stock_reservations(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_sm_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manual M-Pesa submissions. A transaction code can be used once across all orders.
CREATE TABLE payment_submissions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id         BIGINT UNSIGNED NOT NULL,
    method           ENUM('mpesa_phone','mpesa_till','mpesa_paybill') NOT NULL,
    transaction_code VARCHAR(20) NOT NULL,
    payer_phone      VARCHAR(15) NOT NULL,
    payer_name       VARCHAR(160) NULL,
    amount_claimed   DECIMAL(12,2) NOT NULL,
    status           ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
    submitted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address       VARCHAR(45) NULL,
    UNIQUE KEY uq_ps_code (transaction_code),
    KEY ix_ps_order (order_id),
    KEY ix_ps_status (status, submitted_at),
    CONSTRAINT fk_ps_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT ck_ps_amount CHECK (amount_claimed > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_verifications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    submission_id    BIGINT UNSIGNED NOT NULL,
    order_id         BIGINT UNSIGNED NOT NULL,
    decision         ENUM('verified','rejected') NOT NULL,
    amount_confirmed DECIMAL(12,2) NULL,
    actor_user_id    BIGINT UNSIGNED NOT NULL,
    note             VARCHAR(500) NULL,
    created_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY uq_pv_submission (submission_id),             -- one decision per submission
    KEY ix_pv_order (order_id),
    CONSTRAINT fk_pv_sub FOREIGN KEY (submission_id) REFERENCES payment_submissions(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pv_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pv_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- COD cash collection, recorded separately from delivery status.
CREATE TABLE cod_collections (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id         BIGINT UNSIGNED NOT NULL,
    amount_expected  DECIMAL(12,2) NOT NULL,
    amount_collected DECIMAL(12,2) NOT NULL,
    collected_by     BIGINT UNSIGNED NOT NULL,
    collected_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note             VARCHAR(500) NULL,
    UNIQUE KEY uq_cod_order (order_id),
    CONSTRAINT fk_cod_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cod_actor FOREIGN KEY (collected_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT ck_cod_amount CHECK (amount_collected >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C5: late payment verification after reservation expiry, and other cases needing staff.
CREATE TABLE order_resolutions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id    BIGINT UNSIGNED NOT NULL,
    reason      ENUM('payment_after_expiry','payment_amount_mismatch','other') NOT NULL,
    details     VARCHAR(1000) NULL,
    status      ENUM('open','resolved') NOT NULL DEFAULT 'open',
    outcome     ENUM('re_reserved','cancelled_refund_due','cancelled','other') NULL,
    opened_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    resolved_by BIGINT UNSIGNED NULL,
    note        VARCHAR(1000) NULL,
    KEY ix_res_order (order_id),
    KEY ix_res_status (status, opened_at),
    CONSTRAINT fk_res_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_res_actor FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE return_requests (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    return_number VARCHAR(20) NOT NULL,
    order_id      BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NULL,
    status        ENUM('requested','approved','rejected','received','completed','cancelled') NOT NULL DEFAULT 'requested',
    reason        VARCHAR(100) NOT NULL,
    details       VARCHAR(1000) NULL,
    requested_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by    BIGINT UNSIGNED NULL,
    decided_at    DATETIME NULL,
    decision_note VARCHAR(500) NULL,
    received_by   BIGINT UNSIGNED NULL,
    received_at   DATETIME NULL,
    completed_at  DATETIME NULL,
    UNIQUE KEY uq_rr_number (return_number),
    KEY ix_rr_order (order_id),
    KEY ix_rr_status (status, requested_at),
    CONSTRAINT fk_rr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rr_decided FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_rr_received FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE return_items (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    return_id     BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    quantity      SMALLINT UNSIGNED NOT NULL,
    inspection    ENUM('pending','restock','write_off') NOT NULL DEFAULT 'pending',
    inspected_by  BIGINT UNSIGNED NULL,
    inspected_at  DATETIME NULL,
    note          VARCHAR(500) NULL,
    UNIQUE KEY uq_ri_item (return_id, order_item_id),
    CONSTRAINT fk_ri_return FOREIGN KEY (return_id) REFERENCES return_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_ri_oi FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ri_inspector FOREIGN KEY (inspected_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT ck_ri_qty CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE stock_movements
    ADD CONSTRAINT fk_sm_return_item FOREIGN KEY (return_item_id) REFERENCES return_items(id) ON DELETE RESTRICT;

CREATE TABLE refunds (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id   BIGINT UNSIGNED NOT NULL,
    return_id  BIGINT UNSIGNED NULL,
    amount     DECIMAL(12,2) NOT NULL,
    reason     VARCHAR(255) NOT NULL,
    method     ENUM('mpesa','cash','bank','other') NULL,
    status     ENUM('due','paid','cancelled') NOT NULL DEFAULT 'due',
    reference  VARCHAR(60) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_by    BIGINT UNSIGNED NULL,
    paid_at    DATETIME NULL,
    note       VARCHAR(500) NULL,
    KEY ix_ref_order (order_id),
    KEY ix_ref_status (status, created_at),
    CONSTRAINT fk_ref_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_ref_return FOREIGN KEY (return_id) REFERENCES return_requests(id) ON DELETE SET NULL,
    CONSTRAINT fk_ref_created FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ref_paid FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT ck_ref_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_redemptions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    coupon_id       INT UNSIGNED NOT NULL,
    order_id        BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NULL,
    customer_phone  VARCHAR(15) NOT NULL,
    discount_amount DECIMAL(12,2) NOT NULL,
    status          ENUM('active','voided') NOT NULL DEFAULT 'active',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cr_order (order_id),
    KEY ix_cr_coupon (coupon_id, status),
    KEY ix_cr_phone (coupon_id, customer_phone),
    CONSTRAINT fk_cr_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cr_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Verified-purchase reviews: one review per purchased order item.
CREATE TABLE reviews (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id    BIGINT UNSIGNED NOT NULL,
    user_id       BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    rating        TINYINT UNSIGNED NOT NULL,
    title         VARCHAR(120) NULL,
    body          TEXT NULL,
    status        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    moderated_by  BIGINT UNSIGNED NULL,
    moderated_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rev_item (order_item_id),
    KEY ix_rev_product (product_id, status, created_at),
    KEY ix_rev_user (user_id),
    CONSTRAINT fk_rev_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_oi FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rev_mod FOREIGN KEY (moderated_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT ck_rev_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE notifications
    ADD CONSTRAINT fk_notif_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE SET NULL;
