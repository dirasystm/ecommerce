-- Waimart 011: every product has an owning seller, plus moderation state.
-- Existing products all belong to the Waimart Official Store (seller 1, created in 010); none is left
-- without an owner and none is given to a third-party seller.

ALTER TABLE products
    ADD COLUMN seller_id INT UNSIGNED NULL AFTER id;

-- updated_at = updated_at stops ON UPDATE CURRENT_TIMESTAMP from restamping every product with the migration time.
UPDATE products SET seller_id = 1, updated_at = updated_at WHERE seller_id IS NULL;

ALTER TABLE products
    MODIFY seller_id INT UNSIGNED NOT NULL,
    ADD KEY ix_prod_seller (seller_id, is_active),
    ADD CONSTRAINT fk_prod_seller FOREIGN KEY (seller_id) REFERENCES sellers (id);

-- Moderation. Existing (admin-created) products are already live, so they start as 'published'.
-- The column default stays 'published' until the seller product flow (Phase 4) sets the state explicitly
-- for every insert; admin-created Official Store products keep appearing immediately as they do today.
ALTER TABLE products
    ADD COLUMN moderation_status ENUM('draft','pending','published','rejected','archived') NOT NULL DEFAULT 'published' AFTER is_active,
    ADD COLUMN moderation_note VARCHAR(500) NULL AFTER moderation_status,
    ADD COLUMN moderated_by BIGINT UNSIGNED NULL AFTER moderation_note,
    ADD COLUMN moderated_at DATETIME NULL AFTER moderated_by,
    ADD COLUMN submitted_at DATETIME NULL AFTER moderated_at,
    ADD KEY ix_prod_moderation (moderation_status, submitted_at),
    ADD CONSTRAINT fk_prod_moderator FOREIGN KEY (moderated_by) REFERENCES users (id) ON DELETE SET NULL;
