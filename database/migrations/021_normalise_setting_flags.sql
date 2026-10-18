-- Basemall 021: on/off settings stored in one format ('1'/'0'), as the settings pages save them.
-- product_moderation was seeded as 'required'/'none' (migration 010); both forms mean the same to the code
-- (SellerProductService::moderationRequired), so this changes no behaviour — it only makes export → import exact.
UPDATE settings SET setting_value = '1' WHERE setting_key = 'product_moderation' AND setting_value = 'required';
UPDATE settings SET setting_value = '0' WHERE setting_key = 'product_moderation' AND setting_value = 'none';
