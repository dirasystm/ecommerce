-- Basemall 029: how notifications are sent (Admin → Email & SMS): email, sms or both. Additive.
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('notify_channels', 'both');
