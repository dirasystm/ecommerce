-- Waimart 006: customer account security and cart housekeeping.

-- Sessions that started before the last password change are rejected (logs out other devices).
ALTER TABLE users
    ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash;

-- At most one default address per user.
ALTER TABLE user_addresses
    ADD COLUMN default_user_key BIGINT UNSIGNED AS (IF(is_default = 1, user_id, NULL)) PERSISTENT,
    ADD UNIQUE KEY uq_addr_default (default_user_key);

-- Abandoned guest carts are purged by age.
ALTER TABLE carts
    ADD KEY ix_cart_guest_updated (user_id, updated_at);
