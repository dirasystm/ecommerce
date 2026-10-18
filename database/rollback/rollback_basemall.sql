-- ROLLBACK of the Basemall rebrand migrations (018 onwards; currently 018). Run only after taking a backup:
--   php bin/backup.php
--   C:\xampp\mysql\bin\mysql.exe -u root waimart < database\rollback\rollback_basemall.sql
-- Restores the Waimart values only where they still hold the shipped Basemall values. Orders numbered BM- stay
-- valid (the code that reads them is not removed by this script; only roll back the code together with it).

-- 018
UPDATE settings SET setting_value = 'Waimart' WHERE setting_key = 'store_name' AND setting_value = 'Basemall';
DELETE FROM settings WHERE setting_key IN ('store_tagline', 'brand_primary', 'brand_secondary', 'brand_logo_header', 'brand_logo_icon',
    'brand_logo_full', 'brand_favicon', 'brand_icon_180', 'brand_icon_192', 'brand_icon_512', 'brand_og_image', 'order_number_prefix');
UPDATE sellers SET name = 'Waimart Official Store', slug = 'waimart-official'
 WHERE is_platform = 1 AND name = 'Basemall Official Store' AND slug = 'basemall-official';
UPDATE sellers SET legal_name = 'Waimart' WHERE is_platform = 1 AND legal_name = 'Basemall';
UPDATE sellers SET description = 'Products sold and shipped by Waimart.'
 WHERE is_platform = 1 AND description = 'Products sold and shipped by Basemall.';
UPDATE commission_rules SET note = 'Waimart Official Store (platform-owned)'
 WHERE scope = 'seller' AND seller_id = 1 AND note = 'Basemall Official Store (platform-owned)' AND is_active = 1;
DROP TABLE seller_slug_aliases;

DELETE FROM schema_migrations WHERE filename IN ('018_basemall_brand.sql');
