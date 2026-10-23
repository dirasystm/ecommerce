-- Ecommerce 035: "Keep me signed in". Customers and sellers stay signed in on their device (browser or app) until they
-- sign out. The cookie holds selector:validator; only a SHA-256 of the validator is stored here. Staff accounts are
-- never remembered. Adds one table only.

CREATE TABLE remember_tokens (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    selector       CHAR(24)        NOT NULL,
    validator_hash CHAR(64)        NOT NULL,
    user_agent     VARCHAR(255)    NULL,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at   DATETIME        NULL,
    expires_at     DATETIME        NOT NULL,
    UNIQUE KEY uq_remember_selector (selector),
    KEY ix_remember_user (user_id),
    KEY ix_remember_expires (expires_at),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
