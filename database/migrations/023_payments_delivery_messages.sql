-- Ecommerce 023: payment methods, an encrypted secrets store, delivery settings, order status names and message
-- templates. Additive. Seeds keep today's behaviour exactly: the four payment methods are on, with today's names and
-- checkout descriptions; delivery and pickup are both offered; no store-wide free-delivery threshold; messages and
-- status names use the built-in wording (rows in message_templates / order_status_labels exist only once edited).

-- Who may do what (super admins hold every permission automatically).
INSERT IGNORE INTO permissions (slug, description) VALUES
('payments.configure', 'Manage payment methods, their customer instructions and payment numbers'),
('secrets.manage',     'Set or remove stored credentials (encrypted; never shown again)'),
('messages.manage',    'Edit customer and seller email and SMS templates');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'messages.manage' WHERE r.slug = 'manager';

-- Payment methods offered at checkout. The codes are the methods the order system knows how to handle
-- (orders.payment_method); staff manage how each one is presented and whether it is offered.
CREATE TABLE payment_methods (
    code             VARCHAR(20)  NOT NULL,
    name             VARCHAR(80)  NOT NULL,              -- shown at checkout, on orders and in reports
    short_name       VARCHAR(30)  NOT NULL,              -- footer badge
    description      VARCHAR(255) NOT NULL DEFAULT '',   -- one line under the name at checkout
    instructions     TEXT         NULL,                  -- extra customer instructions (content format), order page and How to pay
    is_enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order       INT          NOT NULL DEFAULT 100,
    min_order_total  DECIMAL(12,2) NULL,
    max_order_total  DECIMAL(12,2) NULL,
    updated_by       BIGINT UNSIGNED NULL,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (code),
    CONSTRAINT fk_pm_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_pm_code CHECK (code IN ('mpesa_till','mpesa_paybill','mpesa_phone','cod')),
    CONSTRAINT ck_pm_limits CHECK ((min_order_total IS NULL OR min_order_total >= 0)
        AND (max_order_total IS NULL OR max_order_total > 0)
        AND (min_order_total IS NULL OR max_order_total IS NULL OR max_order_total >= min_order_total))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO payment_methods (code, name, short_name, description, instructions, is_enabled, sort_order) VALUES
('mpesa_till',    'M-Pesa Buy Goods (Till)',          'Till',             'Pay the exact total by M-Pesa, then enter the transaction code. Your order is confirmed after we verify the payment.', NULL, 1, 10),
('mpesa_paybill', 'M-Pesa Paybill',                   'Paybill',          'Pay the exact total by M-Pesa, then enter the transaction code. Your order is confirmed after we verify the payment.', NULL, 1, 20),
('mpesa_phone',   'M-Pesa Send Money',                'Send Money',       'Pay the exact total by M-Pesa, then enter the transaction code. Your order is confirmed after we verify the payment.', NULL, 1, 30),
('cod',           'Cash on delivery / on collection', 'Cash on Delivery', 'Pay in cash when you receive your order. Our team confirms COD orders before dispatch.', NULL, 1, 40);

-- Credentials (e.g. the SMTP password), encrypted with the app key (AES-256-GCM). Values are write-only in the admin.
CREATE TABLE secrets (
    name        VARCHAR(60)  NOT NULL,
    ciphertext  TEXT         NOT NULL,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (name),
    CONSTRAINT fk_secret_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Edited wording of customer and seller messages. A template without a row uses the built-in wording.
CREATE TABLE message_templates (
    code           VARCHAR(60)  NOT NULL,
    email_subject  VARCHAR(190) NOT NULL DEFAULT '',
    email_body     TEXT         NOT NULL,
    sms_body       VARCHAR(480) NOT NULL DEFAULT '',
    email_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
    sms_enabled    TINYINT(1)   NOT NULL DEFAULT 1,
    updated_by     BIGINT UNSIGNED NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (code),
    CONSTRAINT fk_mt_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Edited names of order statuses as customers see them. A status without a row uses the built-in name.
CREATE TABLE order_status_labels (
    status      VARCHAR(40)  NOT NULL,
    label       VARCHAR(80)  NOT NULL,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (status),
    CONSTRAINT fk_osl_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery settings (Fulfilment → Delivery settings) and email settings (Settings → Email & SMS).
-- Blank email settings fall back to the server configuration (config.local.php), as today.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('delivery_home_enabled',   '1'),
('delivery_pickup_enabled', '1'),
('free_delivery_threshold', ''),
('delivery_checkout_note',  ''),
('pickup_checkout_note',    ''),
('mail_from_name',          ''),
('mail_from_email',         ''),
('mail_reply_to',           ''),
('mail_driver',             ''),
('smtp_host',               ''),
('smtp_port',               '587'),
('smtp_encryption',         'tls'),
('smtp_username',           '');
