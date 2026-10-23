-- Ecommerce 032: a "Recommended for you" homepage section (Admin → Content → Homepage), below the flash sale.
-- The section type list gains 'recommended' (existing values unchanged), then the section is added once.
ALTER TABLE home_sections MODIFY type ENUM('hero','quick_categories','flash_sale','top_deals','shop_by_category','featured',
    'category_rails','category_row','values','text','recommended') NOT NULL;

INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order, is_visible)
SELECT 'recommended', 'Recommended for you', NULL, 12, 35, 1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM home_sections WHERE type = 'recommended');
