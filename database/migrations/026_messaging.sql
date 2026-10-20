-- Basemall 026: SMS gateway (Talksasa bulk SMS) and the Email & SMS admin page. Additive.
-- The API token is stored encrypted in `secrets` (sms_api_token), never in settings.

INSERT IGNORE INTO permissions (slug, description) VALUES
('messaging.manage', 'Email & SMS: gateway setup, SMS balance, test messages and the message log');

-- Whoever could already configure email (settings.manage) can manage messaging.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT rp.role_id, p.id FROM role_permissions rp
  JOIN permissions s ON s.id = rp.permission_id AND s.slug = 'settings.manage'
  JOIN permissions p ON p.slug = 'messaging.manage';

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('sms_driver',    ''),
('sms_api_url',   'https://bulksms.talksasa.com/api/v3/'),
('sms_sender_id', '');

-- The gateway's message id and its latest delivery report, for SMS sent through the gateway.
ALTER TABLE notifications
    ADD COLUMN provider_ref    VARCHAR(64) NULL AFTER last_error,
    ADD COLUMN provider_status VARCHAR(40) NULL AFTER provider_ref;

-- Tracking-number lookups (Track order accepts the courier's delivery reference).
CREATE INDEX ix_so_tracking_reference ON seller_orders (tracking_reference);
