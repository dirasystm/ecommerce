-- EMERGENCY ROLLBACK of the marketplace migrations (010 onwards; currently 010–015). Run only after taking a backup:
--   php bin/backup.php
--   C:\xampp\mysql\bin\mysql.exe -u root waimart < database\rollback\rollback_013_to_010.sql
-- Removes the marketplace tables/columns and their reference data, returning the schema to migration 009.
-- Any marketplace data (sellers, seller products, seller orders, ledger, payouts) is DELETED — products owned by
-- third-party sellers are removed with their variants/inventory only if no order references them; otherwise the
-- script stops (see the guard below) and the backup must be used instead.
-- The normal recovery path is restoring the pre-upgrade backup (docs/BACKUP_RESTORE.md); this script exists for
-- when orders taken after the upgrade must be kept.

SET @third_party_products := (SELECT COUNT(*) FROM products WHERE seller_id <> (SELECT id FROM sellers WHERE is_platform = 1));
SET @third_party_order_items := (SELECT COUNT(*) FROM order_items WHERE seller_id <> (SELECT id FROM sellers WHERE is_platform = 1));
-- Guard: refuse (by failing a CHECK) if third-party sellers already have products or orders.
CREATE TEMPORARY TABLE rollback_guard (ok TINYINT, CONSTRAINT ck_rollback_guard CHECK (ok = 1));
INSERT INTO rollback_guard VALUES (IF(@third_party_products = 0 AND @third_party_order_items = 0, 1, 0));
DROP TEMPORARY TABLE rollback_guard;

-- 015 (back-filled seller orders) needs nothing of its own: the seller_orders table and order_items.seller_order_id are
-- dropped with 012 below.

-- 014 (seller stock actor). Seller stock movements exist only for third-party products, which the guard above excludes.
ALTER TABLE stock_movements MODIFY actor_type ENUM('customer','staff','system') NOT NULL;

-- 013
ALTER TABLE order_items DROP FOREIGN KEY fk_oi_commission_rule;
DROP TABLE seller_dispute_messages;
DROP TABLE seller_disputes;
DROP TABLE seller_ledger_entries;
DROP TABLE seller_payout_history;
DROP TABLE seller_payouts;
DROP TABLE commission_rules;

-- 012
ALTER TABLE refunds DROP FOREIGN KEY fk_ref_so, DROP KEY ix_ref_so, DROP COLUMN seller_order_id;
ALTER TABLE return_requests DROP FOREIGN KEY fk_rr_so, DROP KEY ix_rr_so, DROP COLUMN seller_order_id;
ALTER TABLE order_items
    DROP FOREIGN KEY fk_oi_seller, DROP FOREIGN KEY fk_oi_so,
    DROP CONSTRAINT ck_oi_commission, DROP CONSTRAINT ck_oi_commission_amt,
    DROP KEY ix_oi_seller, DROP KEY ix_oi_so,
    DROP COLUMN seller_id, DROP COLUMN seller_order_id, DROP COLUMN commission_rate,
    DROP COLUMN commission_amount, DROP COLUMN seller_net_amount, DROP COLUMN commission_rule_id;
DROP TABLE seller_order_history;
DROP TABLE seller_orders;

-- 011
ALTER TABLE products
    DROP FOREIGN KEY fk_prod_seller, DROP FOREIGN KEY fk_prod_moderator,
    DROP KEY ix_prod_seller, DROP KEY ix_prod_moderation,
    DROP COLUMN seller_id, DROP COLUMN moderation_status, DROP COLUMN moderation_note,
    DROP COLUMN moderated_by, DROP COLUMN moderated_at, DROP COLUMN submitted_at;

-- 010
DROP TABLE seller_payout_methods;
DROP TABLE seller_documents;
DROP TABLE seller_application_history;
DROP TABLE seller_applications;
DROP TABLE seller_users;
DROP TABLE sellers;
DELETE rp FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id
 WHERE p.slug IN ('sellers.view','sellers.manage','products.moderate','commissions.manage','payouts.view','payouts.manage','disputes.manage');
DELETE FROM permissions WHERE slug IN ('sellers.view','sellers.manage','products.moderate','commissions.manage','payouts.view','payouts.manage','disputes.manage');
DELETE FROM settings WHERE setting_key IN ('marketplace_delivery_handler','marketplace_delivery_fee_policy','marketplace_cod_policy',
    'marketplace_commission_provisional','product_moderation','payout_min_amount','payout_hold_days','payout_requires_fulfilment',
    'seller_terms_version','seller_required_documents');
DELETE FROM schema_migrations WHERE filename IN ('015_backfill_seller_orders.sql', '014_seller_stock_actor.sql', '010_marketplace_sellers.sql','011_product_ownership.sql','012_seller_orders.sql','013_marketplace_finance.sql');
