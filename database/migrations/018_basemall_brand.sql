-- Basemall 018: the store's identity becomes settings-driven, starting as Basemall.
-- Values are only changed where they still hold the shipped Waimart defaults, so anything an administrator has
-- customised is left alone. Historical records (orders, messages already sent, audit entries) are not touched.

-- Name and tagline.
UPDATE settings SET setting_value = 'Basemall' WHERE setting_key = 'store_name' AND setting_value IN ('Waimart', '');
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('store_tagline',      'Everything You Need. One Marketplace.'),
-- Colours sampled from the supplied logo (green #01623C/#004C2F, orange #FC7502/#FE5D00), matching the brief.
('brand_primary',      '#005B3D'),
('brand_secondary',    '#FF6A00'),
-- Brand images: the files generated from the supplied logo; replaceable from Admin → Settings → Branding.
('brand_logo_header',  'assets/brand/logo-wordmark.png'),
('brand_logo_icon',    'assets/brand/logo-icon.png'),
('brand_logo_full',    'assets/brand/logo-full.png'),
('brand_favicon',      'assets/brand/favicon-32.png'),
('brand_icon_180',     'assets/brand/apple-touch-icon.png'),
('brand_icon_192',     'assets/brand/icon-192.png'),
('brand_icon_512',     'assets/brand/icon-512.png'),
('brand_og_image',     'assets/brand/og-image.png'),
-- New orders are numbered BM-XXXXXXXX; existing WM- numbers keep working everywhere.
('order_number_prefix', 'BM');

-- Store addresses that changed keep working: an old slug redirects (301) to the store's current page.
CREATE TABLE seller_slug_aliases (
    slug       VARCHAR(80)  NOT NULL,
    seller_id  INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (slug),
    KEY ix_ssa_seller (seller_id),
    CONSTRAINT fk_ssa_seller FOREIGN KEY (seller_id) REFERENCES sellers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The platform's own store: Waimart Official Store → Basemall Official Store (only if still at the shipped values).
INSERT INTO seller_slug_aliases (slug, seller_id)
SELECT slug, id FROM sellers WHERE is_platform = 1 AND slug = 'waimart-official';
UPDATE sellers SET name = 'Basemall Official Store', slug = 'basemall-official'
 WHERE is_platform = 1 AND name = 'Waimart Official Store' AND slug = 'waimart-official';
UPDATE sellers SET legal_name = 'Basemall' WHERE is_platform = 1 AND legal_name = 'Waimart';
UPDATE sellers SET description = 'Products sold and shipped by Basemall.'
 WHERE is_platform = 1 AND description = 'Products sold and shipped by Waimart.';
UPDATE commission_rules SET note = 'Basemall Official Store (platform-owned)'
 WHERE scope = 'seller' AND seller_id = 1 AND note = 'Waimart Official Store (platform-owned)' AND is_active = 1;
