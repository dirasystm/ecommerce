-- Ecommerce 037: the demo data still carried the platform's first name ("Waimart") in pickup station names, a demo
-- brand and its products, and on the orders that used them. Replaces it, in text customers see, with the store's
-- current name (Settings -> General -> Store name). Web addresses (slugs), image files, the message log and the audit
-- log are left as they are. Changes existing rows, so it runs from Website updates -> Database updates.

UPDATE pickup_points SET name = REPLACE(name, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name'))
 WHERE name LIKE '%Waimart%' AND (SELECT setting_value FROM settings WHERE setting_key = 'store_name') <> '';

UPDATE brands SET name = REPLACE(name, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name'))
 WHERE name LIKE '%Waimart%' AND (SELECT setting_value FROM settings WHERE setting_key = 'store_name') <> '';

UPDATE products
   SET name = REPLACE(name, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name')),
       short_description = REPLACE(short_description, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name')),
       description = REPLACE(description, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name')),
       specifications = REPLACE(specifications, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name'))
 WHERE (name LIKE '%Waimart%' OR short_description LIKE '%Waimart%' OR description LIKE '%Waimart%' OR specifications LIKE '%Waimart%')
   AND (SELECT setting_value FROM settings WHERE setting_key = 'store_name') <> '';

UPDATE product_images SET alt_text = REPLACE(alt_text, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name'))
 WHERE alt_text LIKE '%Waimart%' AND (SELECT setting_value FROM settings WHERE setting_key = 'store_name') <> '';

UPDATE order_fulfilment SET pickup_name = REPLACE(pickup_name, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name'))
 WHERE pickup_name LIKE '%Waimart%' AND (SELECT setting_value FROM settings WHERE setting_key = 'store_name') <> '';

UPDATE order_items SET product_name = REPLACE(product_name, 'Waimart', (SELECT setting_value FROM settings WHERE setting_key = 'store_name'))
 WHERE product_name LIKE '%Waimart%' AND (SELECT setting_value FROM settings WHERE setting_key = 'store_name') <> '';
