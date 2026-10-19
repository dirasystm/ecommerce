-- Basemall 024: roles and admin accounts — editable roles and a permission matrix, per-person extra permissions,
-- optional two-step sign-in (TOTP), forced password change, "sign out everywhere", a sign-in activity log, and an
-- audit log the database refuses to change. Additive: existing roles keep exactly their permissions, no account
-- changes behaviour (two-step sign-in stays optional), and no row is altered.

-- Roles: the six shipped roles are built-in (cannot be deleted; super admin cannot be edited).
ALTER TABLE roles
    ADD COLUMN is_system  TINYINT(1) NOT NULL DEFAULT 0 AFTER description,
    ADD COLUMN created_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_system,
    ADD COLUMN updated_at DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
UPDATE roles SET is_system = 1 WHERE slug IN ('super_admin', 'manager', 'inventory_clerk', 'fulfilment_staff', 'finance', 'support');

INSERT IGNORE INTO permissions (slug, description) VALUES
('roles.manage', 'Create roles and change which permissions each role has');

-- Two new built-in roles from the brief.
INSERT IGNORE INTO roles (slug, name, description, is_system) VALUES
('content_marketing', 'Content & Marketing', 'Pages, menus, homepage, banners, flash sales, coupons and message wording', 1),
('delivery_logistics', 'Delivery & Logistics', 'Dispatch, delivery and pickup handover; pickup station details', 1);
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.slug IN ('dashboard.view', 'content.manage', 'messages.manage', 'coupons.manage')
 WHERE r.slug = 'content_marketing';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
    ON p.slug IN ('dashboard.view', 'orders.view', 'orders.deliver', 'orders.collect', 'pickup_points.manage')
 WHERE r.slug = 'delivery_logistics';

-- Extra permissions for one person, on top of their roles.
CREATE TABLE user_permissions (
    user_id       BIGINT UNSIGNED   NOT NULL,
    permission_id SMALLINT UNSIGNED NOT NULL,
    granted_by    BIGINT UNSIGNED   NULL,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    KEY ix_up_permission (permission_id),
    CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE,
    CONSTRAINT fk_up_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account security.
ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at,
    ADD COLUMN sessions_revoked_at  DATETIME   NULL AFTER must_change_password,   -- sessions older than this are signed out
    ADD COLUMN mfa_secret           TEXT       NULL AFTER sessions_revoked_at,    -- TOTP secret, encrypted with the app key
    ADD COLUMN mfa_enabled_at       DATETIME   NULL AFTER mfa_secret,
    ADD COLUMN mfa_last_step        BIGINT     NULL AFTER mfa_enabled_at;         -- last accepted 30-second step (no code reuse)

CREATE TABLE mfa_recovery_codes (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    BIGINT UNSIGNED NOT NULL,
    code_hash  CHAR(64)        NOT NULL,
    used_at    DATETIME        NULL,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_mrc_user (user_id, used_at),
    CONSTRAINT fk_mrc_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-in activity: every successful and failed sign-in, two-step check and sign-out. Append-only.
CREATE TABLE auth_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     BIGINT UNSIGNED NULL,
    event       VARCHAR(40)     NOT NULL,
    identifier  VARCHAR(80)     NULL,       -- what was typed, masked (never a password)
    is_staff    TINYINT(1)      NOT NULL DEFAULT 0,
    ip_address  VARCHAR(45)     NULL,
    user_agent  VARCHAR(255)    NULL,
    created_at  DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    KEY ix_ae_user (user_id, created_at),
    KEY ix_ae_event (event, created_at),
    KEY ix_ae_staff (is_staff, created_at),
    CONSTRAINT fk_ae_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TRIGGER trg_ae_no_update BEFORE UPDATE ON auth_events FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auth_events is append-only';
CREATE TRIGGER trg_ae_no_delete BEFORE DELETE ON auth_events FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'auth_events is append-only';

-- The audit log is append-only, whatever application or tool issues the change. (Foreign-key actions such as
-- ON DELETE SET NULL on the acting user do not fire triggers, so removing an account still clears attribution.)
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';

-- Settings → Security. Two-step sign-in stays optional (owner decision, 18 October 2026) until this is switched on.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('staff_mfa_required', '0');
