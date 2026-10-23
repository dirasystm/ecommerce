-- Ecommerce 033: who can use Marketing Intelligence among the built-in roles. Super administrators have every
-- permission already. Lead contact details (marketing.leads) are not given to any role here: grant them per role
-- under Roles & permissions only to staff who need them.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug IN ('marketing.view', 'marketing.manage') WHERE r.slug = 'content_marketing';
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p ON p.slug = 'marketing.view' WHERE r.slug = 'manager';
