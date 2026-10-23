-- Ecommerce 030: businesses apply to become pickup stations; a super admin verifies and approves or rejects. Additive.
-- An approved application creates an ordinary pickup point (pickup_points), which then appears at checkout.

INSERT IGNORE INTO permissions (slug, description) VALUES
('pickup_points.approve', 'Verify, approve or reject businesses that apply to become pickup stations');

CREATE TABLE pickup_station_applications (
    id                  INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    reference           VARCHAR(12)       NOT NULL,
    status              VARCHAR(20)       NOT NULL DEFAULT 'pending',     -- pending | approved | rejected
    business_name       VARCHAR(120)      NOT NULL,
    business_type       VARCHAR(40)       NOT NULL,
    registration_number VARCHAR(60)       NULL,
    contact_name        VARCHAR(120)      NOT NULL,
    phone               VARCHAR(15)       NOT NULL,
    email               VARCHAR(190)      NOT NULL,
    county_id           SMALLINT UNSIGNED NOT NULL,
    town                VARCHAR(100)      NOT NULL,
    address             VARCHAR(255)      NOT NULL,
    landmark            VARCHAR(255)      NULL,
    latitude            DECIMAL(10,7)     NULL,
    longitude           DECIMAL(10,7)     NULL,
    map_url             VARCHAR(500)      NULL,
    opening_hours       VARCHAR(500)      NOT NULL,
    storage_space       VARCHAR(20)       NOT NULL,                       -- small | medium | large
    daily_capacity      SMALLINT UNSIGNED NULL,
    secure_storage      TINYINT(1)        NOT NULL DEFAULT 0,
    accepts_cod         TINYINT(1)        NOT NULL DEFAULT 0,
    notes               VARCHAR(1000)     NULL,
    photo_stored_name   CHAR(40)          NULL,
    photo_mime          VARCHAR(40)       NULL,
    decision_note       VARCHAR(1000)     NULL,
    reviewed_by         BIGINT UNSIGNED   NULL,
    reviewed_at         DATETIME          NULL,
    pickup_point_id     INT UNSIGNED      NULL,
    ip_address          VARCHAR(45)       NULL,
    created_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_psa_reference (reference),
    KEY ix_psa_status (status, created_at),
    KEY ix_psa_phone (phone),
    CONSTRAINT fk_psa_county FOREIGN KEY (county_id) REFERENCES counties (id),
    CONSTRAINT fk_psa_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_psa_pickup_point FOREIGN KEY (pickup_point_id) REFERENCES pickup_points (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Become a pickup station" in the footer, after "Sell on {store}".
INSERT INTO menu_items (menu_id, parent_id, label, link, sort_order, is_visible, new_tab)
SELECT m.id, NULL, 'Become a pickup station', '/become-a-pickup-station',
       COALESCE((SELECT i.sort_order FROM menu_items i WHERE i.menu_id = m.id AND i.link = '/sell' LIMIT 1), 100) + 1, 1, 0
  FROM menus m
 WHERE m.slug = 'footer_col_3'
   AND NOT EXISTS (SELECT 1 FROM menu_items x WHERE x.menu_id = m.id AND x.link = '/become-a-pickup-station');
