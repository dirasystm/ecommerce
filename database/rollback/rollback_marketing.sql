-- ROLLBACK of Marketing Intelligence (migrations 031–033). Run only after taking a backup, and roll back the code
-- together with it (the marketing code reads these tables):
--   php bin/backup.php
--   C:\xampp\mysql\bin\mysql.exe -u root waimart < database\rollback\rollback_marketing.sql
-- Run it BEFORE rollback_basemall.sql if both are needed. Removes the shopping activity, campaigns, links, clicks,
-- leads, consent history and marketing messages. Orders, customers, products and the wishlist itself are kept.
-- Each customer's email-marketing choice is kept in marketing_opt_in (it was mirrored there all along).

-- 033 (role grants) and 031 (permissions): role links go with the permissions (ON DELETE CASCADE).
DELETE FROM role_permissions WHERE permission_id IN (SELECT id FROM permissions WHERE slug IN ('marketing.view', 'marketing.manage', 'marketing.leads'));
DELETE FROM permissions WHERE slug IN ('marketing.view', 'marketing.manage', 'marketing.leads');

-- 032 (homepage "Recommended for you").
DELETE FROM home_sections WHERE type = 'recommended';
ALTER TABLE home_sections MODIFY type ENUM('hero','quick_categories','flash_sale','top_deals','shop_by_category','featured',
    'category_rails','category_row','values','text') NOT NULL;

-- 031: marketing tables (children first), edited marketing message wording, settings and added columns.
DROP TABLE IF EXISTS marketing_messages;
DROP TABLE IF EXISTS marketing_automations;
DROP TABLE IF EXISTS campaign_clicks;
DROP TABLE IF EXISTS marketing_leads;
DROP TABLE IF EXISTS campaign_links;
DROP TABLE IF EXISTS marketing_campaigns;
DROP TABLE IF EXISTS product_pairs;
DROP TABLE IF EXISTS rec_impressions;
DROP TABLE IF EXISTS shop_events;
DROP TABLE IF EXISTS marketing_consents;
DELETE FROM message_templates WHERE code IN ('mkt_welcome', 'mkt_cart', 'mkt_post_purchase', 'mkt_review', 'mkt_reengage', 'mkt_price_drop', 'mkt_discovery');
DELETE FROM settings WHERE setting_key IN ('tracking_enabled', 'tracking_consent_mode', 'tracking_retention_days', 'guest_history_days',
    'recs_enabled', 'recs_home', 'recs_similar', 'recs_bought_together', 'recs_cart', 'recs_account', 'recs_confirmation', 'recs_email',
    'recs_weight_view', 'recs_weight_search', 'recs_weight_cart', 'recs_weight_wishlist', 'recs_weight_purchase', 'recs_half_life_days',
    'marketing_paused', 'marketing_max_per_day', 'marketing_max_per_week', 'marketing_quiet_start', 'marketing_quiet_end',
    'segment_new_days', 'segment_inactive_days', 'segment_high_value', 'segment_frequent_views', 'attribution_window_days', 'price_drop_min_percent');
ALTER TABLE wishlist_items DROP COLUMN IF EXISTS last_alert_price, DROP COLUMN IF EXISTS price_at_add;
UPDATE users SET marketing_opt_in = marketing_email;
ALTER TABLE users DROP COLUMN IF EXISTS marketing_frequency, DROP COLUMN IF EXISTS personalisation, DROP COLUMN IF EXISTS marketing_sms, DROP COLUMN IF EXISTS marketing_email;

DELETE FROM schema_migrations WHERE filename IN ('033_marketing_roles.sql', '032_home_recommended.sql', '031_marketing_intelligence.sql');
