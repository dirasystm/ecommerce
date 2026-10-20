-- Basemall 028: password reset by SMS code as well as by email link. Additive.
-- An SMS reset is a password_resets row with a 6-digit code (stored hashed); the correct code swaps in a fresh link
-- token, so both ways end on the same "set a new password" page.

ALTER TABLE password_resets
    ADD COLUMN channel       VARCHAR(10)      NOT NULL DEFAULT 'email' AFTER user_id,
    ADD COLUMN code_hash     CHAR(64)         NULL AFTER token_hash,
    ADD COLUMN code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER code_hash;
