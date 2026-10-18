-- Waimart 010: marketplace sellers — stores, membership, applications, private documents, payout details.
-- Additive only: no existing table or row is changed here except settings/permissions reference data.

-- A seller (store) exists only once an application is approved. The platform's own shop is the
-- "Waimart Official Store" (is_platform = 1, at most one), created below with a fixed id of 1.
CREATE TABLE sellers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(120) NOT NULL,                 -- public store name
    slug               VARCHAR(80)  NOT NULL,                 -- /store/{slug}
    legal_name         VARCHAR(160) NULL,
    business_type      ENUM('individual','registered_business') NOT NULL DEFAULT 'individual',
    description        VARCHAR(2000) NULL,
    logo_path          VARCHAR(255) NULL,
    public_email       VARCHAR(190) NULL,
    public_phone       VARCHAR(15)  NULL,
    show_contacts      TINYINT(1)   NOT NULL DEFAULT 0,       -- seller chooses to publish contacts
    county_id          SMALLINT UNSIGNED NULL,
    town               VARCHAR(100) NULL,
    status             ENUM('active','suspended','closed') NOT NULL DEFAULT 'active',
    is_platform        TINYINT(1)   NOT NULL DEFAULT 0,
    is_verified        TINYINT(1)   NOT NULL DEFAULT 0,       -- "verified seller" badge
    allow_promotions   TINYINT(1)   NOT NULL DEFAULT 0,       -- opted in to Waimart flash sales
    suspended_at       DATETIME     NULL,
    suspension_reason  VARCHAR(500) NULL,
    approved_at        DATETIME     NULL,
    approved_by        BIGINT UNSIGNED NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    platform_key       TINYINT(1) AS (IF(is_platform = 1, 1, NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_seller_slug (slug),
    UNIQUE KEY uq_seller_name (name),
    UNIQUE KEY uq_seller_platform (platform_key),
    KEY ix_seller_status (status),
    CONSTRAINT fk_seller_county   FOREIGN KEY (county_id)   REFERENCES counties (id),
    CONSTRAINT fk_seller_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_seller_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sellers (id, name, slug, legal_name, business_type, description, status, is_platform, is_verified, allow_promotions, approved_at)
VALUES (1, 'Waimart Official Store', 'waimart-official', 'Waimart', 'registered_business',
        'Products sold and shipped by Waimart.', 'active', 1, 1, 1, NOW());

-- Which users act for which seller. Seller roles are separate from staff roles: they never grant admin permissions.
CREATE TABLE seller_users (
    seller_id   INT UNSIGNED    NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    role        ENUM('owner','manager','staff') NOT NULL DEFAULT 'owner',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by  BIGINT UNSIGNED NULL,
    PRIMARY KEY (seller_id, user_id),
    UNIQUE KEY uq_su_user (user_id),                          -- a user acts for at most one seller
    CONSTRAINT fk_su_seller  FOREIGN KEY (seller_id)  REFERENCES sellers (id),
    CONSTRAINT fk_su_user    FOREIGN KEY (user_id)    REFERENCES users (id),
    CONSTRAINT fk_su_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_applications (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_number    VARCHAR(20)  NOT NULL,
    user_id               BIGINT UNSIGNED NOT NULL,           -- the applicant's account
    seller_id             INT UNSIGNED NULL,                  -- set when approved
    status                ENUM('draft','submitted','under_review','approved','rejected') NOT NULL DEFAULT 'draft',
    store_name            VARCHAR(120) NOT NULL,
    store_slug            VARCHAR(80)  NOT NULL,
    contact_name          VARCHAR(160) NOT NULL,
    email                 VARCHAR(190) NOT NULL,
    phone                 VARCHAR(15)  NOT NULL,
    business_type         ENUM('individual','registered_business') NOT NULL,
    legal_name            VARCHAR(160) NULL,
    business_description  VARCHAR(2000) NOT NULL,
    county_id             SMALLINT UNSIGNED NOT NULL,
    town                  VARCHAR(100) NOT NULL,
    business_address      VARCHAR(255) NULL,
    registration_number   VARCHAR(60)  NULL,
    category_ids          VARCHAR(500) NULL,                  -- comma-separated category ids
    payout_method         ENUM('mpesa','bank') NULL,
    payout_details_enc    TEXT NULL,                          -- AES-256-GCM (Crypto), never stored in clear
    payout_masked         VARCHAR(80) NULL,                   -- e.g. "M-Pesa 07•• ••• 678"
    terms_version         VARCHAR(20)  NULL,
    terms_accepted_at     DATETIME     NULL,
    submitted_at          DATETIME     NULL,
    reviewed_by           BIGINT UNSIGNED NULL,
    reviewed_at           DATETIME     NULL,
    decision_reason       VARCHAR(1000) NULL,                 -- shown to the applicant on rejection
    resubmission_allowed  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_user_key         BIGINT UNSIGNED AS (IF(status IN ('draft','submitted','under_review'), user_id, NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sa_number (application_number),
    UNIQUE KEY uq_sa_open_user (open_user_key),               -- one open application per applicant
    KEY ix_sa_status (status, submitted_at),
    KEY ix_sa_user (user_id),
    KEY ix_sa_slug (store_slug),
    CONSTRAINT fk_sa_user     FOREIGN KEY (user_id)     REFERENCES users (id),
    CONSTRAINT fk_sa_seller   FOREIGN KEY (seller_id)   REFERENCES sellers (id),
    CONSTRAINT fk_sa_county   FOREIGN KEY (county_id)   REFERENCES counties (id),
    CONSTRAINT fk_sa_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seller_application_history (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  BIGINT UNSIGNED NOT NULL,
    from_status     VARCHAR(20) NULL,
    to_status       VARCHAR(20) NOT NULL,
    actor_user_id   BIGINT UNSIGNED NULL,
    note            VARCHAR(1000) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_sah_app (application_id, id),
    CONSTRAINT fk_sah_app   FOREIGN KEY (application_id) REFERENCES seller_applications (id),
    CONSTRAINT fk_sah_actor FOREIGN KEY (actor_user_id)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Private verification documents: files live in storage/private/seller-docs (outside the web root)
-- under random names; only metadata is here. Downloads go through an authorised controller.
CREATE TABLE seller_documents (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id  BIGINT UNSIGNED NOT NULL,
    seller_id       INT UNSIGNED NULL,
    doc_type        VARCHAR(40)  NOT NULL,
    original_name   VARCHAR(160) NOT NULL,
    stored_name     CHAR(40)     NOT NULL,
    mime_type       VARCHAR(60)  NOT NULL,
    size_bytes      INT UNSIGNED NOT NULL,
    sha256          CHAR(64)     NOT NULL,
    status          ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
    uploaded_by     BIGINT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sd_stored (stored_name),
    KEY ix_sd_app (application_id),
    KEY ix_sd_seller (seller_id),
    CONSTRAINT fk_sd_app      FOREIGN KEY (application_id) REFERENCES seller_applications (id),
    CONSTRAINT fk_sd_seller   FOREIGN KEY (seller_id)      REFERENCES sellers (id),
    CONSTRAINT fk_sd_uploader FOREIGN KEY (uploaded_by)    REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_sd_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Payout destinations, encrypted at rest. Changes add a new row (history kept); one active per seller.
CREATE TABLE seller_payout_methods (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id     INT UNSIGNED NOT NULL,
    method        ENUM('mpesa','bank') NOT NULL,
    details_enc   TEXT NOT NULL,
    masked        VARCHAR(80) NOT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deactivated_at DATETIME NULL,
    active_key    INT UNSIGNED AS (IF(is_active = 1, seller_id, NULL)) PERSISTENT,
    PRIMARY KEY (id),
    UNIQUE KEY uq_spm_active (active_key),
    KEY ix_spm_seller (seller_id),
    CONSTRAINT fk_spm_seller  FOREIGN KEY (seller_id)  REFERENCES sellers (id),
    CONSTRAINT fk_spm_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin permissions for the marketplace (super_admin implicitly has all).
INSERT INTO permissions (slug, description) VALUES
('sellers.view',        'View seller applications, stores and seller orders'),
('sellers.manage',      'Approve, reject, suspend and reactivate sellers; edit stores'),
('products.moderate',   'Approve or reject seller products'),
('commissions.manage',  'Change marketplace commission rules'),
('payouts.view',        'View seller balances, ledgers and payout requests'),
('payouts.manage',      'Approve, reject and record seller payouts'),
('disputes.manage',     'Resolve seller order disputes');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'manager' AND p.slug IN ('sellers.view','sellers.manage','products.moderate','payouts.view','disputes.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'finance' AND p.slug IN ('sellers.view','payouts.view','payouts.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'support' AND p.slug IN ('sellers.view','disputes.manage');

-- Business decisions of 18 Oct 2026 (docs/MARKETPLACE_PLAN.md §7) and conservative defaults; all editable in Admin.
INSERT INTO settings (setting_key, setting_value) VALUES
('marketplace_delivery_handler',     'platform'),           -- platform | seller   (decision 1: Waimart delivers)
('marketplace_delivery_fee_policy',  'per_order'),          -- per_order | per_seller (decision 2)
('marketplace_cod_policy',           'pickup_and_platform'),-- pickup_and_platform | all (decision 3)
('marketplace_commission_provisional','1'),                 -- decision 4: 10% until the client confirms
('product_moderation',               'required'),           -- required | none
('payout_min_amount',                '500'),
('payout_hold_days',                 '7'),
('payout_requires_fulfilment',       '1'),
('seller_terms_version',             '1'),
('seller_required_documents',        '{"individual":["national_id"],"registered_business":["national_id"],"optional":["business_registration"]}');
