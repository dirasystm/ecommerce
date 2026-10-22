-- Basemall 031: marketing intelligence — consent per channel, shopping behaviour, recommendations, promotional links,
-- leads, attribution and marketing automation. Adds tables and columns; the one data change copies each customer's
-- existing "deals and offers" choice into the new email-marketing consent (nobody is opted in to anything new).

INSERT IGNORE INTO permissions (slug, description) VALUES
('marketing.view',   'Marketing dashboard, campaign analytics, segments and customer insights (no contact details)'),
('marketing.manage', 'Create campaigns, promotional links and ad creatives; manage automations, recommendations and marketing settings'),
('marketing.leads',  'See and export leads and marketing audiences with contact details');

-- Consent per channel (marketing_opt_in stays for compatibility and mirrors email consent).
ALTER TABLE users
    ADD COLUMN marketing_email     TINYINT(1)  NOT NULL DEFAULT 0 AFTER marketing_opt_in,
    ADD COLUMN marketing_sms       TINYINT(1)  NOT NULL DEFAULT 0 AFTER marketing_email,
    ADD COLUMN personalisation     TINYINT(1)  NOT NULL DEFAULT 1 AFTER marketing_sms,
    ADD COLUMN marketing_frequency VARCHAR(10) NOT NULL DEFAULT 'normal' AFTER personalisation;
UPDATE users SET marketing_email = 1 WHERE marketing_opt_in = 1;

-- Every consent change, with where it came from and the wording version shown (append-only history).
CREATE TABLE marketing_consents (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NULL,
    lead_id         INT UNSIGNED    NULL,
    channel         VARCHAR(20)     NOT NULL,          -- email | sms | personalisation
    granted         TINYINT(1)      NOT NULL,
    source          VARCHAR(40)     NOT NULL,          -- registration | preferences | newsletter | unsubscribe_link | admin | cookie_banner
    wording_version VARCHAR(20)     NOT NULL DEFAULT 'v1',
    ip_address      VARCHAR(45)     NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_mc_user (user_id, channel, created_at),
    KEY ix_mc_lead (lead_id, channel, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Shopping behaviour (pseudonymous visitor id for guests; never contact details, tokens or payment data).
CREATE TABLE shop_events (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    occurred_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    visitor_id    CHAR(32)         NULL,
    user_id       BIGINT UNSIGNED  NULL,
    event         VARCHAR(30)      NOT NULL,
    product_id    INT UNSIGNED     NULL,
    seller_id     INT UNSIGNED     NULL,
    category_id   INT UNSIGNED     NULL,
    brand_id      INT UNSIGNED     NULL,
    order_id      BIGINT UNSIGNED  NULL,
    link_id       INT UNSIGNED     NULL,             -- last-touch promotional link within the attribution window
    first_link_id INT UNSIGNED     NULL,             -- first-touch promotional link within the window
    placement     VARCHAR(30)      NULL,             -- recommendation placement, when the action came from one
    query         VARCHAR(100)     NULL,
    amount        DECIMAL(12,2)    NULL,
    PRIMARY KEY (id),
    KEY ix_se_user (user_id, occurred_at),
    KEY ix_se_visitor (visitor_id, occurred_at),
    KEY ix_se_event (event, occurred_at),
    KEY ix_se_product (product_id, event),
    KEY ix_se_link (link_id, event, occurred_at),
    KEY ix_se_first_link (first_link_id, event),
    KEY ix_se_order (order_id, event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Recommendation placements shown per day (counted, not one row per product shown).
CREATE TABLE rec_impressions (
    day       DATE         NOT NULL,
    placement VARCHAR(30)  NOT NULL,
    shown     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, placement)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Frequently bought together", rebuilt nightly from paid orders.
CREATE TABLE product_pairs (
    product_id INT UNSIGNED NOT NULL,
    paired_id  INT UNSIGNED NOT NULL,
    orders     INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, paired_id),
    KEY ix_pp_rank (product_id, orders)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wishlist price-drop alerts compare against the price when saved and the last alerted price (real prices only).
ALTER TABLE wishlist_items
    ADD COLUMN price_at_add     DECIMAL(12,2) NULL AFTER product_id,
    ADD COLUMN last_alert_price DECIMAL(12,2) NULL AFTER price_at_add;

-- Campaigns and promotional links (Creative Studio).
CREATE TABLE marketing_campaigns (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    name        VARCHAR(120)    NOT NULL,
    objective   VARCHAR(30)     NOT NULL,            -- sales | traffic | leads | awareness | reengagement
    channel     VARCHAR(30)     NOT NULL,            -- social | messaging | email | sms | offline | partner | other
    platform    VARCHAR(30)     NULL,                -- facebook | instagram | whatsapp | x | tiktok | ...
    segment     VARCHAR(40)     NULL,
    starts_on   DATE            NULL,
    ends_on     DATE            NULL,
    cost        DECIMAL(12,2)   NULL,                -- actual amount spent, entered by the admin (never estimated)
    status      VARCHAR(20)     NOT NULL DEFAULT 'active',
    notes       VARCHAR(1000)   NULL,
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_mcmp_status (status, created_at),
    CONSTRAINT fk_mcmp_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_links (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    campaign_id      INT UNSIGNED    NOT NULL,
    code             VARCHAR(12)     NOT NULL,
    variant          VARCHAR(20)     NOT NULL DEFAULT 'A',
    destination_type VARCHAR(20)     NOT NULL,       -- product | category | brand | store | search | home | page
    destination_id   INT UNSIGNED    NULL,
    destination_path VARCHAR(255)    NOT NULL,       -- always an internal path, validated
    headline         VARCHAR(120)    NOT NULL,
    description      VARCHAR(500)    NULL,
    cta              VARCHAR(40)     NOT NULL DEFAULT 'Shop now',
    image_path       VARCHAR(255)    NULL,
    coupon_code      VARCHAR(40)     NULL,
    utm_source       VARCHAR(40)     NULL,
    utm_medium       VARCHAR(40)     NULL,
    is_active        TINYINT(1)      NOT NULL DEFAULT 1,
    expires_at       DATETIME        NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cl_code (code),
    KEY ix_cl_campaign (campaign_id),
    CONSTRAINT fk_cl_campaign FOREIGN KEY (campaign_id) REFERENCES marketing_campaigns (id),
    CONSTRAINT fk_cl_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clicks on promotional links (a click is a visit, never proof of who someone is).
CREATE TABLE campaign_clicks (
    id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    link_id       INT UNSIGNED     NOT NULL,
    visitor_id    CHAR(32)         NULL,
    user_id       BIGINT UNSIGNED  NULL,
    occurred_at   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_unique     TINYINT(1)       NOT NULL DEFAULT 1,  -- first click from this browser on this link in 30 days
    is_bot        TINYINT(1)       NOT NULL DEFAULT 0,
    via           VARCHAR(10)      NOT NULL DEFAULT 'link', -- link | qr
    referrer_host VARCHAR(100)     NULL,
    PRIMARY KEY (id),
    KEY ix_cc_link (link_id, occurred_at),
    KEY ix_cc_visitor (visitor_id, link_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leads: people who gave contact details or have an account (never created from a click alone).
CREATE TABLE marketing_leads (
    id                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id              BIGINT UNSIGNED NULL,
    visitor_id           CHAR(32)        NULL,
    email                VARCHAR(190)    NULL,
    phone                VARCHAR(15)     NULL,
    name                 VARCHAR(120)    NULL,
    source               VARCHAR(30)     NOT NULL,     -- newsletter | registration | checkout
    first_link_id        INT UNSIGNED    NULL,
    last_link_id         INT UNSIGNED    NULL,
    interest_category_id INT UNSIGNED    NULL,
    consent_email        TINYINT(1)      NOT NULL DEFAULT 0,
    consent_sms          TINYINT(1)      NOT NULL DEFAULT 0,
    first_seen_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ml_user (user_id),
    UNIQUE KEY uq_ml_email (email),
    KEY ix_ml_phone (phone),
    KEY ix_ml_seen (last_seen_at),
    CONSTRAINT fk_ml_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Automations (each switched on by an admin) and their message queue.
CREATE TABLE marketing_automations (
    code         VARCHAR(30)     NOT NULL,
    is_enabled   TINYINT(1)      NOT NULL DEFAULT 0,
    channels     VARCHAR(20)     NOT NULL DEFAULT 'email',
    delay_hours  INT UNSIGNED    NOT NULL,
    delay2_hours INT UNSIGNED    NULL,
    delay3_hours INT UNSIGNED    NULL,
    updated_by   BIGINT UNSIGNED NULL,
    updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO marketing_automations (code, is_enabled, channels, delay_hours, delay2_hours, delay3_hours) VALUES
('welcome',       0, 'email',     0,    NULL, NULL),
('cart_recovery', 0, 'email',     3,    24,   NULL),
('post_purchase', 0, 'email',     72,   NULL, NULL),
('review_request',0, 'email',     48,   NULL, NULL),
('reengage',      0, 'email',     720,  1440, 2160),
('price_drop',    0, 'email,sms', 0,    NULL, NULL),
('discovery',     0, 'email',     48,   NULL, NULL);

CREATE TABLE marketing_messages (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    automation      VARCHAR(30)     NOT NULL,
    trigger_key     VARCHAR(100)    NOT NULL,          -- the event it answers; with automation + channel it is unique
    channel         VARCHAR(10)     NOT NULL,
    user_id         BIGINT UNSIGNED NULL,
    lead_id         INT UNSIGNED    NULL,
    status          VARCHAR(15)     NOT NULL DEFAULT 'queued', -- queued | sending | sent | failed | suppressed | cancelled
    reason          VARCHAR(160)    NULL,
    payload         VARCHAR(2000)   NULL,              -- ids only (products, cart, order), never contact details
    scheduled_at    DATETIME        NOT NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    notification_id BIGINT UNSIGNED NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at         DATETIME        NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mm_once (automation, channel, trigger_key),
    KEY ix_mm_due (status, scheduled_at),
    KEY ix_mm_user (user_id, sent_at),
    KEY ix_mm_lead (lead_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Settings (all editable under Marketing → Settings).
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('tracking_enabled', '1'), ('tracking_consent_mode', 'opt_in'), ('tracking_retention_days', '180'), ('guest_history_days', '30'),
('recs_enabled', '1'), ('recs_home', '1'), ('recs_similar', '1'), ('recs_bought_together', '1'), ('recs_cart', '1'),
('recs_account', '1'), ('recs_confirmation', '1'), ('recs_email', '1'),
('recs_weight_view', '1'), ('recs_weight_search', '2'), ('recs_weight_cart', '3'), ('recs_weight_wishlist', '4'),
('recs_weight_purchase', '5'), ('recs_half_life_days', '14'),
('marketing_paused', '0'), ('marketing_max_per_day', '1'), ('marketing_max_per_week', '3'),
('marketing_quiet_start', '20:00'), ('marketing_quiet_end', '08:00'),
('segment_new_days', '30'), ('segment_inactive_days', '60'), ('segment_high_value', '20000'), ('segment_frequent_views', '10'),
('attribution_window_days', '30'), ('price_drop_min_percent', '5');
