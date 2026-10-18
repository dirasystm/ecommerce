-- Waimart 004: reference data required in every environment.

INSERT INTO counties (code, name) VALUES
(1,'Mombasa'),(2,'Kwale'),(3,'Kilifi'),(4,'Tana River'),(5,'Lamu'),(6,'Taita-Taveta'),(7,'Garissa'),
(8,'Wajir'),(9,'Mandera'),(10,'Marsabit'),(11,'Isiolo'),(12,'Meru'),(13,'Tharaka-Nithi'),(14,'Embu'),
(15,'Kitui'),(16,'Machakos'),(17,'Makueni'),(18,'Nyandarua'),(19,'Nyeri'),(20,'Kirinyaga'),
(21,'Murang''a'),(22,'Kiambu'),(23,'Turkana'),(24,'West Pokot'),(25,'Samburu'),(26,'Trans Nzoia'),
(27,'Uasin Gishu'),(28,'Elgeyo-Marakwet'),(29,'Nandi'),(30,'Baringo'),(31,'Laikipia'),(32,'Nakuru'),
(33,'Narok'),(34,'Kajiado'),(35,'Kericho'),(36,'Bomet'),(37,'Kakamega'),(38,'Vihiga'),(39,'Bungoma'),
(40,'Busia'),(41,'Siaya'),(42,'Kisumu'),(43,'Homa Bay'),(44,'Migori'),(45,'Kisii'),(46,'Nyamira'),
(47,'Nairobi');

INSERT INTO roles (slug, name, description) VALUES
('super_admin',      'Super Administrator', 'Full access including staff accounts and settings'),
('manager',          'Store Manager',       'Runs day-to-day operations'),
('inventory_clerk',  'Inventory Clerk',     'Catalogue, stock receipts/adjustments, return inspection'),
('fulfilment_staff', 'Fulfilment Staff',    'Packing, dispatch, delivery and pickup collection'),
('finance',          'Finance',             'Payment verification, COD reconciliation, refunds'),
('support',          'Customer Support',    'Customer queries, cancellations, returns, reviews');

INSERT INTO permissions (slug, description) VALUES
('dashboard.view',        'View the admin dashboard'),
('catalogue.manage',      'Create and edit categories, brands and products'),
('content.manage',        'Manage banners and flash sales'),
('inventory.view',        'View stock levels and movements'),
('inventory.adjust',      'Receive stock and make stock adjustments'),
('orders.view',           'View orders'),
('orders.manage',         'Move orders through the fulfilment workflow'),
('orders.cancel',         'Cancel or reject orders'),
('orders.collect',        'Confirm pickup collection'),
('orders.deliver',        'Confirm dispatch and delivery'),
('payments.view',         'View payment submissions'),
('payments.verify',       'Verify or reject manual payments'),
('cod.collect',           'Record cash-on-delivery collection'),
('resolutions.manage',    'Resolve orders requiring administrative resolution'),
('returns.manage',        'Process returns and inspections'),
('refunds.manage',        'Record and mark refunds paid'),
('delivery.manage',       'Manage delivery zones and fees'),
('pickup_points.manage',  'Manage pickup points'),
('coupons.manage',        'Manage coupons'),
('customers.view',        'View customer accounts'),
('reviews.moderate',      'Moderate product reviews'),
('reports.view',          'View reports'),
('settings.manage',       'Change store settings'),
('users.manage',          'Manage staff accounts and roles'),
('audit.view',            'View audit logs');

-- super_admin is granted everything in code (Auth::permissionsFor).
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'manager' AND p.slug NOT IN ('users.manage', 'settings.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'inventory_clerk'
   AND p.slug IN ('dashboard.view','catalogue.manage','inventory.view','inventory.adjust','orders.view','returns.manage');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'fulfilment_staff'
   AND p.slug IN ('dashboard.view','orders.view','orders.manage','orders.collect','orders.deliver','cod.collect','inventory.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'finance'
   AND p.slug IN ('dashboard.view','orders.view','payments.view','payments.verify','cod.collect',
                  'refunds.manage','resolutions.manage','reports.view');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
 WHERE r.slug = 'support'
   AND p.slug IN ('dashboard.view','orders.view','orders.cancel','payments.view','customers.view',
                  'returns.manage','reviews.moderate');

INSERT INTO settings (setting_key, setting_value) VALUES
('store_name',                   'Waimart'),
('store_phone',                  ''),
('store_email',                  ''),
('reservation_minutes',          '30'),     -- C4 default reservation window
('cod_auto_confirm',             '0'),      -- COD orders need staff confirmation by default
('guest_email_required',         '0'),      -- D1: email optional for guests
('mpesa_phone_number',           ''),       -- "Send Money" number
('mpesa_phone_name',             ''),
('mpesa_till_number',            ''),
('mpesa_till_name',              ''),
('mpesa_paybill_number',         ''),
('mpesa_paybill_account_hint',   'Use your order number as the account number'),
('max_payment_submissions',      '3'),
('return_window_days',           '7'),
('collection_hold_days',         '7'),
('low_stock_alert_threshold',    '5');
