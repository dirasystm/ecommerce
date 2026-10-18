-- Waimart 001: settings, users, roles & permissions, addresses, security, audit, notifications, counties.

CREATE TABLE settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE counties (
    id   SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code TINYINT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    UNIQUE KEY uq_counties_name (name),
    UNIQUE KEY uq_counties_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(190) NOT NULL,
    phone             VARCHAR(15) NULL,                    -- normalised 2547XXXXXXXX
    password_hash     VARCHAR(255) NOT NULL,
    first_name        VARCHAR(80) NOT NULL,
    last_name         VARCHAR(80) NOT NULL,
    is_staff          TINYINT(1) NOT NULL DEFAULT 0,
    status            ENUM('active','suspended') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    phone_verified_at DATETIME NULL,
    notify_email      TINYINT(1) NOT NULL DEFAULT 1,
    notify_sms        TINYINT(1) NOT NULL DEFAULT 1,
    marketing_opt_in  TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at     DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_phone (phone),
    KEY ix_users_staff (is_staff, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(50) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug        VARCHAR(80) NOT NULL,
    description VARCHAR(255) NOT NULL,
    UNIQUE KEY uq_permissions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id       SMALLINT UNSIGNED NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE password_resets (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pr_token (token_hash),
    KEY ix_pr_user (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_addresses (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id        BIGINT UNSIGNED NOT NULL,
    label          VARCHAR(40) NULL,
    recipient_name VARCHAR(160) NOT NULL,
    phone          VARCHAR(15) NOT NULL,
    county_id      SMALLINT UNSIGNED NOT NULL,
    town           VARCHAR(100) NOT NULL,
    location       VARCHAR(255) NOT NULL,          -- estate, street, building
    instructions   VARCHAR(500) NULL,
    is_default     TINYINT(1) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY ix_addr_user (user_id),
    CONSTRAINT fk_addr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_addr_county FOREIGN KEY (county_id) REFERENCES counties(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
    rl_key       CHAR(64) NOT NULL PRIMARY KEY,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    window_start DATETIME NOT NULL,
    KEY ix_rl_window (window_start)
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action        VARCHAR(80) NOT NULL,
    entity_type   VARCHAR(50) NOT NULL,
    entity_id     VARCHAR(64) NULL,
    before_json   LONGTEXT NULL,
    after_json    LONGTEXT NULL,
    ip_address    VARCHAR(45) NULL,
    user_agent    VARCHAR(255) NULL,
    created_at    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ix_audit_entity (entity_type, entity_id),
    KEY ix_audit_actor (actor_user_id, created_at),
    KEY ix_audit_action (action, created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NULL,
    order_id   BIGINT UNSIGNED NULL,               -- FK added in 003
    channel    ENUM('email','sms') NOT NULL,
    template   VARCHAR(60) NOT NULL,
    recipient  VARCHAR(190) NOT NULL,
    subject    VARCHAR(190) NULL,
    body       TEXT NOT NULL,
    status     ENUM('queued','sent','failed','logged') NOT NULL DEFAULT 'queued',
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    sent_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_notif_status (status, created_at),
    KEY ix_notif_order (order_id),
    KEY ix_notif_user (user_id),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
