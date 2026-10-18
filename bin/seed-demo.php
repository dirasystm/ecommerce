<?php

declare(strict_types=1);

/**
 * Demo catalogue for development: categories, fictional brands, products with variants,
 * opening stock (recorded as stock movements), hero banners and a running flash sale.
 *
 * Usage: php bin/seed-demo.php [--reset]
 *   --reset  removes existing catalogue data first (refused if any orders exist).
 * Refuses to run when app.env = production.
 */

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/database/seeds/demo_svg.php';

use Waimart\Core\App;

if (App::env() === 'production') {
    fwrite(STDERR, "Demo data is not allowed in production.\n");
    exit(1);
}
$opts = getopt('', ['reset']);
$db = App::db();

// ---------------------------------------------------------------- fulfilment (independent, idempotent)
if ((int) $db->value('SELECT COUNT(*) FROM delivery_zones') === 0) {
    $db->transaction(function () use ($db) {
        $county = fn (string $name) => (int) $db->value('SELECT id FROM counties WHERE name = ?', [$name]);
        $zones = [
            // name, fee, free threshold, min, max, cod, [county => [towns] or [] for whole county]
            ['Nairobi', '199', '4999', 1, 2, 1, ['Nairobi' => []]],
            ['Nairobi Metro', '299', '7999', 1, 3, 1, ['Kiambu' => [], 'Kajiado' => ['Kitengela', 'Ongata Rongai', 'Ngong'], 'Machakos' => ['Athi River', 'Syokimau', 'Mlolongo']]],
            ['Major towns', '399', '9999', 2, 4, 1, ['Mombasa' => [], 'Kisumu' => [], 'Nakuru' => [], 'Uasin Gishu' => [], 'Nyeri' => []]],
            ['Upcountry', '549', null, 3, 6, 0, ['Kakamega' => [], 'Kericho' => [], 'Embu' => [], 'Meru' => [], 'Machakos' => []]],
        ];
        foreach ($zones as $i => [$name, $fee, $free, $min, $max, $cod, $locations]) {
            $zid = $db->insert('delivery_zones', [
                'name' => $name, 'fee' => $fee, 'free_delivery_threshold' => $free,
                'min_days' => $min, 'max_days' => $max, 'cod_allowed' => $cod, 'sort_order' => $i,
            ]);
            foreach ($locations as $c => $towns) {
                foreach ($towns ?: [''] as $t) {
                    $db->insert('delivery_zone_locations', ['zone_id' => $zid, 'county_id' => $county($c), 'town' => $t]);
                }
            }
        }
        $points = [
            ['NBO-CBD-01', brand_name() . ' Pickup — Moi Avenue', 'Nairobi', 'Nairobi CBD', 'Moi Avenue, Ground Floor, Shop 12', 'Mon–Sat 8:00–19:00, Sun 10:00–16:00', 'Enter through the main lobby; the counter is on the left.', '0', 1, 1],
            ['NBO-WLD-01', brand_name() . ' Pickup — Westlands', 'Nairobi', 'Westlands', 'Woodvale Grove, 1st Floor', 'Mon–Sat 9:00–18:00', 'Bring your collection code and ID.', '0', 1, 1],
            ['KBU-THK-01', brand_name() . ' Pickup — Thika Town', 'Kiambu', 'Thika', 'Kenyatta Highway, Opp. bus park', 'Mon–Sat 8:30–18:00', null, '50', 1, 1],
            ['MSA-NYL-01', brand_name() . ' Pickup — Nyali', 'Mombasa', 'Nyali', 'Links Road, Shop 4', 'Mon–Sat 9:00–18:00', null, '0', 1, 1],
            ['KSM-CBD-01', brand_name() . ' Pickup — Kisumu CBD', 'Kisumu', 'Kisumu', 'Oginga Odinga Street, Shop 7', 'Mon–Sat 8:00–18:00', null, '0', 0, 1],
            ['NKR-CBD-01', brand_name() . ' Pickup — Nakuru (temporarily closed)', 'Nakuru', 'Nakuru', 'Kenyatta Avenue', 'Closed for renovation', null, '0', 1, 0],
        ];
        foreach ($points as [$code, $name, $c, $town, $addr, $hours, $instr, $fee, $cod, $active]) {
            $db->insert('pickup_points', [
                'code' => $code, 'name' => $name, 'county_id' => $county($c), 'town' => $town, 'address' => $addr,
                'opening_hours' => $hours, 'instructions' => $instr, 'pickup_fee' => $fee, 'cod_allowed' => $cod, 'is_active' => $active,
            ]);
        }
    });
    echo "Demo delivery zones and pickup points created.\n";
}

if ((int) $db->value('SELECT COUNT(*) FROM products') > 0) {
    if (!isset($opts['reset'])) {
        echo "Catalogue already has products. Use --reset to replace demo data.\n";
        exit(0);
    }
    if ((int) $db->value('SELECT COUNT(*) FROM orders') > 0) {
        fwrite(STDERR, "Refusing to reset: orders reference the catalogue.\n");
        exit(1);
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (['stock_movements', 'flash_sale_items', 'flash_sales', 'banners', 'wishlist_items', 'cart_items', 'inventory',
              'variant_attributes', 'product_variants', 'product_images', 'products', 'brands', 'categories'] as $t) {
        $db->pdo()->exec("DELETE FROM `$t`");
    }
    $db->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo "Existing catalogue removed.\n";
}

function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '', '-'));
    return $s;
}

// ---------------------------------------------------------------- categories
$categories = [
    ['Phones & Tablets', 'phone', ['Smartphones', 'Feature Phones', 'Tablets', 'Phone Accessories']],
    ['Electronics', 'tv', ['Televisions', 'Audio & Speakers', 'Headphones', 'Cameras']],
    ['Computing', 'laptop', ['Laptops', 'Monitors', 'Printers', 'Computer Accessories']],
    ['Appliances', 'plug', ['Refrigerators', 'Cooking Appliances', 'Small Appliances', 'Washing Machines']],
    ['Fashion', 'bag', ["Men's Fashion", "Women's Fashion", 'Shoes', 'Watches']],
    ['Health & Beauty', 'heart-pulse', ['Skin Care', 'Hair Care', 'Fragrances', 'Personal Care']],
    ['Home & Office', 'house', ['Furniture', 'Kitchen & Dining', 'Bedding', 'Lighting']],
    ['Supermarket', 'basket', ['Food Cupboard', 'Beverages', 'Household Cleaning']],
    ['Baby Products', 'balloon-heart', ['Diapering', 'Feeding', 'Baby Toys']],
    ['Sporting Goods', 'bicycle', ['Fitness', 'Outdoor & Cycling', 'Team Sports']],
    ['Gaming', 'controller', ['Consoles', 'Video Games', 'Gaming Accessories']],
];

// Fictional brands (demo only).
$brands = ['Nyota', 'Kilele', 'Zuri', 'Savanna', 'Pwani', 'Simba Home', 'Jua Tech', 'Mto', 'Amani', brand_name() . ' Basics'];

// [name, subcategory, brand, svg kind, colour, price, compare_at|null, stock, featured, variants|null]
// variants: [name, [attr => value], price, compare_at|null, stock]
$products = [
    ['Nyota X12 Smartphone 6.6" Dual SIM', 'Smartphones', 'Nyota', 'phone', '#263238', '15999', '18999', 25, 1, [
        ['64GB / 4GB · Midnight Black', ['Storage' => '64GB', 'Colour' => 'Midnight Black'], '15999', '18999', 25],
        ['128GB / 6GB · Ocean Blue', ['Storage' => '128GB', 'Colour' => 'Ocean Blue'], '18999', '22499', 12],
    ]],
    ['Nyota X12 Pro 5G Smartphone 256GB', 'Smartphones', 'Nyota', 'phone', '#0b5a3a', '32999', '37999', 8, 1, null],
    ['Jua Tech Spark 8C 4G Smartphone 64GB', 'Smartphones', 'Jua Tech', 'phone', '#f7801e', '11499', '12999', 3, 0, null],
    ['Kilele K1 Feature Phone with FM Radio', 'Feature Phones', 'Kilele', 'basicphone', '#37474f', '1599', '1999', 60, 0, null],
    ['Kilele K3 Dual SIM Torch Phone', 'Feature Phones', 'Kilele', 'basicphone', '#c62828', '1899', null, 40, 0, null],
    ['Jua Tech Tab 10" Android Tablet 64GB', 'Tablets', 'Jua Tech', 'tablet', '#455a64', '17999', '21999', 10, 1, null],
    ['Amani Kids Tablet 7" with Protective Case', 'Tablets', 'Amani', 'tablet', '#1e88e5', '8499', '9999', 2, 0, null],
    ['Mto 20,000mAh Fast-Charge Power Bank', 'Phone Accessories', 'Mto', 'speaker', '#212121', '2499', '3299', 80, 0, null],
    ['Mto Wireless Earbuds with Charging Case', 'Headphones', 'Mto', 'earbuds', '#0b5a3a', '2999', '3999', 45, 1, null],
    ['Savanna 43" Full HD Smart TV', 'Televisions', 'Savanna', 'tv', '#1565c0', '28999', '34999', 9, 1, null],
    ['Savanna 55" 4K UHD Smart TV', 'Televisions', 'Savanna', 'tv', '#6a1b9a', '49999', '58999', 4, 1, null],
    ['Pwani Bluetooth Party Speaker 40W', 'Audio & Speakers', 'Pwani', 'speaker', '#f15a22', '5499', '6999', 20, 0, null],
    ['Mto Over-Ear Wireless Headphones', 'Headphones', 'Mto', 'headphones', '#37474f', '3999', '4999', 18, 0, null],
    ['Zuri Compact Digital Camera 20MP', 'Cameras', 'Zuri', 'camera', '#455a64', '14999', null, 0, 0, null],
    ['Jua Tech Book 14" Laptop Core i5 8GB/512GB', 'Laptops', 'Jua Tech', 'laptop', '#546e7a', '54999', '62999', 7, 1, [
        ['8GB RAM / 512GB SSD', ['Memory' => '8GB', 'Storage' => '512GB'], '54999', '62999', 7],
        ['16GB RAM / 1TB SSD', ['Memory' => '16GB', 'Storage' => '1TB'], '68999', '76999', 3],
    ]],
    ['Jua Tech Student 15.6" Laptop Celeron 4GB/128GB', 'Laptops', 'Jua Tech', 'laptop', '#78909c', '26999', '29999', 11, 0, null],
    ['Savanna 24" Full HD IPS Monitor', 'Monitors', 'Savanna', 'monitor', '#263238', '13499', '15999', 14, 0, null],
    ['Kilele Ink Tank All-in-One Printer', 'Printers', 'Kilele', 'printer', '#37474f', '19999', null, 6, 0, null],
    ['Mto Wireless Mouse 2.4GHz', 'Computer Accessories', 'Mto', 'mouse', '#212121', '699', '999', 150, 0, null],
    ['Simba Home 138L Single Door Fridge', 'Refrigerators', 'Simba Home', 'fridge', '#b0bec5', '23999', '27999', 5, 1, null],
    ['Simba Home 260L Double Door Fridge', 'Refrigerators', 'Simba Home', 'fridge', '#90a4ae', '42999', '48999', 3, 0, null],
    ['Simba Home 20L Microwave Oven', 'Cooking Appliances', 'Simba Home', 'microwave', '#eceff1', '8999', '10999', 16, 1, null],
    ['Simba Home 4-Burner Gas Cooker with Oven', 'Cooking Appliances', 'Simba Home', 'cooker', '#cfd8dc', '27999', '31999', 4, 0, null],
    ['Pwani 1.5L Blender with Grinder', 'Small Appliances', 'Pwani', 'blender', '#f7801e', '3299', '4299', 30, 1, null],
    ['Pwani 1.7L Electric Kettle', 'Small Appliances', 'Pwani', 'kettle', '#0b5a3a', '1799', '2299', 55, 0, null],
    ['Pwani Steam Iron 2000W', 'Small Appliances', 'Pwani', 'iron', '#1e88e5', '2199', null, 35, 0, null],
    ['Simba Home 7kg Top Load Washing Machine', 'Washing Machines', 'Simba Home', 'washer', '#eceff1', '32999', '36999', 2, 0, null],
    ['Amani Men\'s Cotton Crew-Neck T-Shirt', "Men's Fashion", 'Amani', 'shirt', '#0b5a3a', '899', '1299', 0, 1, [
        ['Size S', ['Size' => 'S'], '899', '1299', 15],
        ['Size M', ['Size' => 'M'], '899', '1299', 25],
        ['Size L', ['Size' => 'L'], '899', '1299', 20],
        ['Size XL', ['Size' => 'XL'], '899', '1299', 4],
    ]],
    ['Amani Men\'s Slim-Fit Chinos', "Men's Fashion", 'Amani', 'trousers', '#795548', '2199', '2799', 30, 0, null],
    ['Zuri Women\'s Kitenge Print Maxi Dress', "Women's Fashion", 'Zuri', 'dress', '#f15a22', '2899', '3599', 0, 1, [
        ['Size S', ['Size' => 'S'], '2899', '3599', 6],
        ['Size M', ['Size' => 'M'], '2899', '3599', 9],
        ['Size L', ['Size' => 'L'], '2899', '3599', 5],
    ]],
    ['Kilele Men\'s Running Sneakers', 'Shoes', 'Kilele', 'shoe', '#1e88e5', '3499', '4499', 0, 0, [
        ['EU 40', ['Size' => '40'], '3499', '4499', 6],
        ['EU 41', ['Size' => '41'], '3499', '4499', 8],
        ['EU 42', ['Size' => '42'], '3499', '4499', 10],
        ['EU 43', ['Size' => '43'], '3499', '4499', 1],
        ['EU 44', ['Size' => '44'], '3499', '4499', 0],
    ]],
    ['Zuri Women\'s Leather Handbag', "Women's Fashion", 'Zuri', 'bag', '#8d6e63', '3999', '4999', 12, 0, null],
    ['Kilele Classic Analog Wristwatch', 'Watches', 'Kilele', 'watch', '#fdb913', '2499', '3299', 22, 0, null],
    ['Zuri Shea Butter Body Lotion 400ml', 'Skin Care', 'Zuri', 'bottle', '#f7801e', '649', '799', 70, 1, null],
    ['Zuri Aloe Vera Face Cream 100ml', 'Skin Care', 'Zuri', 'jar', '#43a047', '899', null, 40, 0, null],
    ['Zuri Argan Oil Hair Treatment 200ml', 'Hair Care', 'Zuri', 'bottle', '#8d6e63', '1199', '1499', 25, 0, null],
    ['Amani Eau de Parfum for Women 100ml', 'Fragrances', 'Amani', 'perfume', '#ec407a', '3499', '4299', 9, 0, null],
    ['Simba Home 3-Seater Fabric Sofa', 'Furniture', 'Simba Home', 'sofa', '#5d4037', '38999', '44999', 2, 0, null],
    ['Simba Home 5-Piece Non-Stick Cookware Set', 'Kitchen & Dining', 'Simba Home', 'pot', '#c62828', '4999', '6499', 18, 1, null],
    ['Simba Home Cotton Duvet Set 6x6', 'Bedding', 'Simba Home', 'bedding', '#5c6bc0', '3999', '4999', 14, 0, null],
    ['Mto LED Desk Lamp with USB Port', 'Lighting', 'Mto', 'lamp', '#fdb913', '1499', '1899', 33, 0, null],
    [brand_name() . ' Basics Long-Grain Rice 5kg', 'Food Cupboard', brand_name() . ' Basics', 'sack', '#fff8e1', '899', '999', 120, 1, null],
    [brand_name() . ' Basics Maize Flour 2kg', 'Food Cupboard', brand_name() . ' Basics', 'sack', '#ffe082', '199', null, 200, 0, null],
    [brand_name() . ' Basics Cooking Oil 3L', 'Food Cupboard', brand_name() . ' Basics', 'bottle', '#fdb913', '1099', '1249', 90, 0, null],
    [brand_name() . ' Basics Breakfast Cereal 500g', 'Food Cupboard', brand_name() . ' Basics', 'box', '#f15a22', '549', null, 60, 0, null],
    ['Pwani Mango Juice 1L (Pack of 6)', 'Beverages', 'Pwani', 'can', '#ffb300', '1199', '1399', 45, 0, null],
    [brand_name() . ' Basics Laundry Detergent 2kg', 'Household Cleaning', brand_name() . ' Basics', 'detergent', '#1e88e5', '699', '849', 75, 0, null],
    ['Amani Baby Diapers Size 3 (64 pcs)', 'Diapering', 'Amani', 'diaper', '#4fc3f7', '1899', '2199', 50, 1, null],
    ['Amani Anti-Colic Baby Feeding Bottle 250ml', 'Feeding', 'Amani', 'babybottle', '#f06292', '599', null, 40, 0, null],
    ['Amani Soft Plush Teddy Bear', 'Baby Toys', 'Amani', 'toy', '#ffb74d', '999', '1299', 28, 0, null],
    ['Kilele Adjustable Dumbbell Set 20kg', 'Fitness', 'Kilele', 'dumbbell', '#37474f', '5999', '6999', 10, 0, null],
    ['Kilele 26" Mountain Bike 21-Speed', 'Outdoor & Cycling', 'Kilele', 'bike', '#c62828', '21999', '24999', 3, 0, null],
    ['Kilele Size 5 Football', 'Team Sports', 'Kilele', 'ball', '#0b5a3a', '1299', '1599', 48, 0, null],
    ['Jua Tech Play Console 1TB', 'Consoles', 'Jua Tech', 'console', '#212121', '64999', '69999', 3, 1, null],
    ['Jua Tech Wireless Game Controller', 'Gaming Accessories', 'Jua Tech', 'gamepad', '#263238', '4999', '5999', 26, 0, null],
    ['Safari Rally Legends (Console Game)', 'Video Games', 'Jua Tech', 'disc', '#f7801e', '5499', null, 15, 0, null],
];

$bgs = ['#f4f8f6', '#fff6ee', '#f6f7fb', '#fffbea', '#f8f4f9'];
$mediaDir = App::root() . '/public/uploads/products/demo';
if (!is_dir($mediaDir)) {
    mkdir($mediaDir, 0775, true);
}

$db->transaction(function () use ($db, $categories, $brands, $products, $bgs, $mediaDir) {
    $subIds = [];
    foreach ($categories as $i => [$name, $icon, $children]) {
        $parentId = $db->insert('categories', ['name' => $name, 'slug' => slugify($name), 'icon' => $icon, 'sort_order' => $i]);
        foreach ($children as $j => $child) {
            $subIds[$child] = $db->insert('categories', [
                'parent_id' => $parentId, 'name' => $child, 'slug' => slugify($child), 'icon' => $icon, 'sort_order' => $j,
            ]);
        }
    }
    $brandIds = [];
    foreach ($brands as $b) {
        $brandIds[$b] = $db->insert('brands', ['name' => $b, 'slug' => slugify($b)]);
    }

    $variantBySlug = [];
    foreach ($products as $n => [$name, $sub, $brand, $kind, $colour, $price, $compare, $stock, $featured, $variants]) {
        $slug = slugify($name);
        $productId = $db->insert('products', [
            'seller_id' => Waimart\Services\Sellers::platformId($db),
            'category_id' => $subIds[$sub],
            'brand_id' => $brandIds[$brand],
            'name' => $name,
            'slug' => $slug,
            'short_description' => "$name from $brand.",
            'description' => "$name — quality you can trust from $brand.\nDemo product description; replace with real details.",
            'specifications' => json_encode(['Brand' => $brand, 'Category' => $sub, 'Warranty' => '12 months'], JSON_UNESCAPED_UNICODE),
            'is_featured' => $featured,
            'max_per_order' => 5,
        ]);

        $file = "$slug.svg";
        file_put_contents("$mediaDir/$file", demo_product_svg($kind, $colour, $bgs[$n % count($bgs)]));
        $db->insert('product_images', ['product_id' => $productId, 'path' => "uploads/products/demo/$file", 'alt_text' => $name]);

        $variants ??= [['Default', [], $price, $compare, $stock]];
        foreach ($variants as $k => [$vName, $attrs, $vPrice, $vCompare, $vStock]) {
            $variantId = $db->insert('product_variants', [
                'product_id' => $productId,
                'sku' => strtoupper(substr(preg_replace('/[^A-Z]/i', '', $brand), 0, 3)) . '-' . str_pad((string) ($n + 1), 3, '0', STR_PAD_LEFT) . '-' . ($k + 1),
                'name' => $vName,
                'price' => $vPrice,
                'compare_at_price' => $vCompare,
                'is_default' => $k === 0 ? 1 : 0,
                'sort_order' => $k,
            ]);
            foreach ($attrs as $an => $av) {
                $db->insert('variant_attributes', ['variant_id' => $variantId, 'attr_name' => $an, 'attr_value' => $av]);
            }
            $db->insert('inventory', ['variant_id' => $variantId, 'on_hand' => $vStock, 'reserved' => 0]);
            if ($vStock > 0) {
                // Opening stock is recorded in the ledger like any receipt (A6).
                $db->insert('stock_movements', [
                    'variant_id' => $variantId, 'movement_type' => 'receipt', 'on_hand_delta' => $vStock,
                    'reserved_delta' => 0, 'on_hand_after' => $vStock, 'reserved_after' => 0,
                    'actor_type' => 'system', 'note' => 'Demo seed opening stock',
                ]);
            }
            if ($k === 0) {
                $variantBySlug[$slug] = ['id' => $variantId, 'price' => $vPrice];
            }
        }
    }

    // Hero banners
    $db->insert('banners', ['title' => 'Phones & Tablets Week', 'subtitle' => 'Up to 15% off selected smartphones and tablets.', 'link_url' => '/category/phones-tablets', 'sort_order' => 1]);
    $db->insert('banners', ['title' => 'Stock up your kitchen', 'subtitle' => 'Everyday groceries and household essentials, delivered.', 'link_url' => '/category/supermarket', 'sort_order' => 2]);
    $db->insert('banners', ['title' => 'Collect at a pickup station', 'subtitle' => 'Choose pickup at checkout and collect with your private code.', 'link_url' => '/delivery-pickup', 'sort_order' => 3]);

    // Flash sale running for the next 6 hours (relative so the demo always has a live sale).
    $saleId = $db->insert('flash_sales', [
        'title' => 'Flash Sales',
        'starts_at' => date('Y-m-d H:i:s', time() - 3600),
        'ends_at' => date('Y-m-d H:i:s', time() + 6 * 3600),
    ]);
    $flash = [
        'nyota-x12-smartphone-6-6-dual-sim' => '14499', 'mto-wireless-earbuds-with-charging-case' => '2299',
        'savanna-43-full-hd-smart-tv' => '26499', 'pwani-1-5l-blender-with-grinder' => '2799',
        'simba-home-20l-microwave-oven' => '7999', 'zuri-shea-butter-body-lotion-400ml' => '549',
        'jua-tech-spark-8c-4g-smartphone-64gb' => '10499', 'amani-baby-diapers-size-3-64-pcs' => '1649',
    ];
    foreach ($flash as $slug => $salePrice) {
        $db->insert('flash_sale_items', ['flash_sale_id' => $saleId, 'variant_id' => $variantBySlug[$slug]['id'], 'sale_price' => $salePrice]);
    }
});

echo 'Demo catalogue created: ' . $db->value('SELECT COUNT(*) FROM products') . ' products, '
    . $db->value('SELECT COUNT(*) FROM product_variants') . ' variants, '
    . $db->value('SELECT COUNT(*) FROM categories') . " categories.\n";
