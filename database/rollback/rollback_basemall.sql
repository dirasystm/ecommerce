-- ROLLBACK of the Basemall rebrand migrations (018 onwards; currently 018–023). Run only after taking a backup:
--   php bin/backup.php
--   C:\xampp\mysql\bin\mysql.exe -u root waimart < database\rollback\rollback_basemall.sql
-- Restores the Waimart values only where they still hold the shipped Basemall values. Orders numbered BM- stay
-- valid (the code that reads them is not removed by this script; only roll back the code together with it).

-- 023 (payments, delivery, messages): payment methods, wording and status names go back to the code of that version.
-- The SMTP password (if one was saved) is dropped with the secrets table: put it back in config.local.php first.
DROP TABLE payment_methods;
DROP TABLE secrets;
DROP TABLE message_templates;
DROP TABLE order_status_labels;
DELETE FROM settings WHERE setting_key IN ('delivery_home_enabled', 'delivery_pickup_enabled', 'free_delivery_threshold', 'delivery_checkout_note',
    'pickup_checkout_note', 'mail_from_name', 'mail_from_email', 'mail_reply_to', 'mail_driver', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username');
DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE p.slug IN ('payments.configure', 'secrets.manage', 'messages.manage');
DELETE FROM permissions WHERE slug IN ('payments.configure', 'secrets.manage', 'messages.manage');

-- 022 (content management): pages, menus and homepage sections move back into the templates of that code version.
DROP TABLE content_page_revisions;
DROP TABLE content_pages;
DROP TABLE menu_items;
DROP TABLE menus;
DROP TABLE home_sections;
DELETE FROM settings WHERE setting_key = 'footer_note';

-- 021 (flag format): nothing to undo — the code reads both '1'/'0' and 'required'/'none'.

-- 020 (unique phone): back to a plain index.
-- (ix_users_staff is rebuilt too, so both indexes return in their original order and the schema matches exactly.)
ALTER TABLE users DROP INDEX uq_users_phone, DROP INDEX ix_users_staff, ADD KEY ix_users_phone (phone), ADD KEY ix_users_staff (is_staff, status);

-- 019 (grouped settings): the settings it added.
DELETE FROM settings WHERE setting_key IN ('store_description', 'products_per_page', 'whatsapp_number', 'business_address', 'postal_address',
    'business_hours', 'legal_name', 'social_facebook', 'social_instagram', 'social_x', 'social_tiktok', 'social_youtube', 'social_linkedin',
    'seo_title', 'seo_description', 'seo_noindex', 'allow_registration', 'maintenance_mode', 'maintenance_message', 'announcement_enabled',
    'announcement_text', 'announcement_link');

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

DELETE FROM schema_migrations WHERE filename IN ('023_payments_delivery_messages.sql', '022_content_management.sql', '021_normalise_setting_flags.sql', '020_unique_user_phone.sql', '019_settings_groups.sql', '018_basemall_brand.sql');
