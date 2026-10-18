-- Basemall 022: content management — pages, menus and homepage sections in the database (Admin → Content).
-- Additive. Seeds reproduce the current site exactly: the nine information and legal pages (their existing wording, in
-- the content format rendered by ContentRenderer), the header/footer/mobile menus and the homepage layout.
-- Live facts are never copied into text: {store}, {return_days}… and [[blocks]] are filled in at display time.

CREATE TABLE content_pages (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug             VARCHAR(80)  NOT NULL,
    title            VARCHAR(120) NOT NULL,
    meta_description VARCHAR(200) NULL,
    body             MEDIUMTEXT   NOT NULL,
    status           ENUM('draft','published') NOT NULL DEFAULT 'draft',
    publish_at       DATETIME     NULL,               -- published pages appear from this time (NULL = now)
    unpublish_at     DATETIME     NULL,               -- and disappear after this time (NULL = never)
    show_in_nav      TINYINT(1)   NOT NULL DEFAULT 1, -- listed in the Help Center side menu
    sort_order       INT          NOT NULL DEFAULT 100,
    is_system        TINYINT(1)   NOT NULL DEFAULT 0, -- built-in pages: fixed address, cannot be deleted
    updated_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cp_slug (slug),
    KEY ix_cp_live (status, publish_at),
    CONSTRAINT fk_cp_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_cp_slug CHECK (slug REGEXP '^[a-z0-9]+(-[a-z0-9]+)*$'),
    CONSTRAINT ck_cp_window CHECK (unpublish_at IS NULL OR publish_at IS NULL OR unpublish_at > publish_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every save keeps the previous version, so a change can be undone.
CREATE TABLE content_page_revisions (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id          INT UNSIGNED NOT NULL,
    title            VARCHAR(120) NOT NULL,
    meta_description VARCHAR(200) NULL,
    body             MEDIUMTEXT   NOT NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_cpr_page (page_id, id),
    CONSTRAINT fk_cpr_page FOREIGN KEY (page_id) REFERENCES content_pages (id) ON DELETE CASCADE,
    CONSTRAINT fk_cpr_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed places on the site (slug), each with an editable list of links / short items.
CREATE TABLE menus (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(40)  NOT NULL,
    title       VARCHAR(80)  NOT NULL,                -- shown as the heading where the place has one (footer columns)
    description VARCHAR(255) NOT NULL,                -- where it appears (admin help)
    PRIMARY KEY (id),
    UNIQUE KEY uq_menu_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_id     SMALLINT UNSIGNED NOT NULL,
    parent_id   INT UNSIGNED NULL,                     -- one level of drop-down
    label       VARCHAR(80)  NOT NULL,
    link        VARCHAR(255) NULL,                     -- /page on this site, https://…, mailto: or tel: (validated)
    icon        VARCHAR(40)  NULL,                     -- Bootstrap icon name
    description VARCHAR(160) NULL,                     -- short text for tiles
    sort_order  INT          NOT NULL DEFAULT 100,
    is_visible  TINYINT(1)   NOT NULL DEFAULT 1,
    new_tab     TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY ix_mi_menu (menu_id, parent_id, sort_order),
    CONSTRAINT fk_mi_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_mi_parent FOREIGN KEY (parent_id) REFERENCES menu_items (id) ON DELETE CASCADE,
    CONSTRAINT ck_mi_icon CHECK (icon IS NULL OR icon REGEXP '^[a-z0-9-]{1,40}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The homepage, top to bottom.
CREATE TABLE home_sections (
    id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type        ENUM('hero','quick_categories','flash_sale','top_deals','shop_by_category','featured','category_rails','category_row','values','text') NOT NULL,
    title       VARCHAR(120) NOT NULL,
    subtitle    VARCHAR(160) NULL,
    category_id INT UNSIGNED NULL,                    -- for a category row
    body        TEXT         NULL,                    -- for a text block (content format)
    item_limit  SMALLINT UNSIGNED NULL,
    sort_order  INT          NOT NULL DEFAULT 100,
    is_visible  TINYINT(1)   NOT NULL DEFAULT 1,
    starts_at   DATETIME     NULL,
    ends_at     DATETIME     NULL,
    updated_by  BIGINT UNSIGNED NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_hs_order (sort_order),
    CONSTRAINT fk_hs_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE SET NULL,
    CONSTRAINT fk_hs_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT ck_hs_limit CHECK (item_limit IS NULL OR item_limit BETWEEN 1 AND 48),
    CONSTRAINT ck_hs_window CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Footer note (General settings).
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('footer_note', 'Prices in Kenya Shillings (KSh), VAT inclusive where applicable.');

-- Pages (the existing wording).
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('help', 'Help Center', 'Answers to common questions about ordering, paying, delivery, pickup and returns at {store}.', 'Find quick answers below. If you still need help, [contact our customer care team](/contact).

?? Do I need an account to order?
No. You can check out as a guest using your name and phone number. You will get an order number and a private tracking link. Creating an account lets you save addresses, see your order history and write reviews.

?? How do I pay?
Choose M-Pesa (Till, Paybill or Send Money) or cash on delivery at checkout. For M-Pesa, pay the exact order total and then enter your M-Pesa transaction code on your order page. Our team verifies M-Pesa payments manually; your order is confirmed once payment is verified. [See step-by-step instructions](/how-to-pay).

?? How long is my item held for me?
When you place an order, the items are reserved for you for {reservation_minutes} minutes while you complete payment. If payment is not completed and verified in time, the reservation expires and the items are released to other customers. You will be told if this happens.

?? Can I collect my order instead of having it delivered?
Yes. Choose "Pickup station" at checkout. When your order is ready we will notify you and give you a collection code. Bring the code and your ID to the station. [Learn more](/delivery-pickup).

?? How do I track my order?
Use the link in your confirmation, sign in to your account, or go to [Track order](/track-order) and enter your order number and phone number.

?? Can I cancel my order?
You can request cancellation before your order is dispatched or ready for collection. After that, please use the returns process.

?? How do returns work?
You can request a return within {return_days} days of delivery or collection for eligible items. [Read the returns policy](/returns-policy).', 'published', 1, 10, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('how-to-pay', 'How to pay', 'Pay for your {store} order with M-Pesa Till, Paybill, Send Money or cash on delivery.', '{store} accepts **M-Pesa** and **cash on delivery**. We do not accept card payments online.

> **Important:** submitting an M-Pesa code does not complete your payment on its own. Our team checks each payment against M-Pesa records. Your order is confirmed only after the payment is **verified**, and we will notify you. Items are held for {reservation_minutes} minutes after you place your order, so please pay promptly.

[[payment_instructions]]

## Stay safe
- {store} staff will **never** ask for your M-Pesa PIN.
- Only pay to the Till, Paybill or phone number shown on this page or at checkout.
- Payment instructions are never sent from personal numbers.', 'published', 1, 20, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('delivery-pickup', 'Delivery & pickup', 'Home and office delivery or collection from a {store} pickup station.', 'Choose how you receive your order at checkout. Only options available for your order and location are shown.

## Home or office delivery
- Delivery is available to supported locations. The delivery fee and an estimated delivery range for your area are calculated at checkout.
- Some areas qualify for free delivery when your order reaches a minimum value. The threshold, if any, is shown at checkout.
- If your location is not yet supported, checkout will tell you and you can choose a pickup station instead.
- Delivery ranges are estimates, not guaranteed dates. We will keep you updated as your order is dispatched and out for delivery.

## Pickup stations
1. Choose a pickup station at checkout ([see all stations](/pickup-stations)). You will see its address, opening hours and collection instructions.
2. We notify you when your order is **ready for collection** and give you a private collection code. Please wait for this notice before going to the station.
3. Bring your collection code, your order number and a valid ID. Staff will check them before handing over your order.
4. Orders are held for {hold_days} days after they are ready for collection.

> Keep your collection code private. Anyone who has it, together with your order details, may be able to collect your order.', 'published', 1, 30, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('pickup-stations', 'Pickup stations', 'Find a {store} pickup station near you: addresses, opening hours and collection instructions.', 'Choose a pickup station at checkout. We will notify you with a private collection code when your order is **ready for collection** — please wait for that message before visiting.

[[pickup_stations]]', 'published', 0, 40, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('returns-policy', 'Returns & refunds', 'How to request a return and how refunds work at {store}.', 'If something is not right with your order, you can request a return within **{return_days} days** of delivery or collection.

## How to request a return
1. Sign in and open the order, or use your order tracking link.
2. Select **Request a return**, choose the items and quantity, and tell us why.
3. Our team reviews the request and lets you know the next steps.
4. Returned items are inspected when we receive them.

## Eligibility
- Items should be returned in their original condition and packaging, with all accessories.
- For hygiene reasons some items (for example opened personal-care products and undergarments) can only be returned if faulty or incorrect.
- Items damaged through misuse are not eligible.

## Refunds
- Refunds are approved after inspection. We will tell you the outcome.
- Approved refunds for M-Pesa payments are sent to the paying M-Pesa number. Refunds for cash-on-delivery orders are arranged with you directly.
- If an order cannot be fulfilled after you have paid, we will contact you and record a refund for you.', 'published', 1, 50, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('contact', 'Contact us', 'Get in touch with {store} customer care.', 'Our customer care team is happy to help with orders, payments, delivery, pickup and returns.

[[contact_details]]

When contacting us about an order, please have your **order number** ready. For your security, never share your M-Pesa PIN or your pickup collection code with anyone claiming to be from {store}.', 'published', 1, 60, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('about', 'About {store}', '{store} — {tagline}', '{store} — **{tagline}**

{store} is an online store bringing phones, electronics, home appliances, fashion, beauty, groceries and more together in one place, with simple payment by M-Pesa or cash on delivery.

## What we promise
- **Honest stock:** when you place an order, the items are reserved for you so they cannot be sold to someone else while you pay.
- **Clear payments:** we confirm your order only after your payment is verified, and we tell you at every step.
- **Flexible delivery:** delivery to your door or collection from a pickup station.
- **Support when you need it:** easy cancellations before dispatch and a clear returns process.', 'published', 1, 70, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('terms', 'Terms & conditions', 'Terms and conditions of sale on {store}.', 'These terms apply to purchases made on the {store} website. By placing an order you agree to them.

## 1. Orders and stock
An order is an offer to buy. When you place an order, the items are reserved for a limited time (currently {reservation_minutes} minutes) while payment is completed and verified, or until a cash-on-delivery order is confirmed. If the reservation expires, the items may be sold to other customers and your order may be cancelled or require further confirmation.

## 2. Prices
Prices are in Kenya Shillings. The amount payable is the total shown at checkout, calculated by our system at the time of your order, including any delivery or pickup fee and applicable discounts.

## 3. Payment
We accept M-Pesa (Till, Paybill, Send Money) and, where available, cash on delivery. M-Pesa payments are verified manually. Submitting a transaction code does not by itself confirm payment.

## 4. Delivery and collection
Delivery ranges are estimates. Pickup orders must be collected by the customer or an authorised person presenting the collection code, within the holding period communicated to you.

## 5. Cancellations and returns
You may request cancellation before dispatch or before your order is ready for collection. Returns are handled under our [returns policy](/returns-policy).

## 6. Contact
For questions about these terms, please [contact us](/contact).', 'published', 1, 80, 1);
INSERT INTO content_pages (slug, title, meta_description, body, status, show_in_nav, sort_order, is_system) VALUES
('privacy', 'Privacy policy', 'How {store} collects, uses and protects your personal data.', 'We respect your privacy and handle personal data in line with the Kenya Data Protection Act, 2019.

## What we collect
- Contact details you give us: name, phone number and (optionally) email address.
- Delivery details: county, town, location and instructions; or your chosen pickup station.
- Order and payment records, including M-Pesa transaction codes you submit. We never ask for or store your M-Pesa PIN.
- Account details if you register, such as saved addresses, wishlist and reviews.

## How we use it
- To process, deliver and support your orders, and to verify payments.
- To send order and service notifications. Marketing messages are sent only if you opt in.
- To keep the store secure and prevent fraud.

## Your choices and rights
- You can update your profile and notification preferences in your account.
- You may request access to, correction of, or deletion of your personal data, subject to records we must keep by law.

## Security
Passwords are stored using strong one-way hashing. Order-tracking links and collection codes are random and private to you. Staff access to customer data is restricted by role and logged.', 'published', 1, 90, 1);

-- Menus.
INSERT INTO menus (slug, title, description) VALUES ('topbar_messages', 'Top bar messages', 'Short messages at the far left of the green bar above the header (a link is optional).');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Pay with M-Pesa or Cash on Delivery', NULL, 'phone', NULL, 10 FROM menus WHERE slug = 'topbar_messages';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Free pickup at {store} stations', '/pickup-stations', 'shop', NULL, 20 FROM menus WHERE slug = 'topbar_messages';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Easy returns', NULL, 'arrow-repeat', NULL, 30 FROM menus WHERE slug = 'topbar_messages';
INSERT INTO menus (slug, title, description) VALUES ('topbar_links', 'Top bar links', 'Links at the right of the green bar above the header (large screens).');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Sell on {store}', '/sell', 'shop-window', NULL, 10 FROM menus WHERE slug = 'topbar_links';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Track order', '/track-order', 'truck', NULL, 20 FROM menus WHERE slug = 'topbar_links';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Help', '/help', 'question-circle', NULL, 30 FROM menus WHERE slug = 'topbar_links';
INSERT INTO menus (slug, title, description) VALUES ('help_menu', 'Help menu', 'The "Help" drop-down in the header.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Help Center', '/help', 'life-preserver', NULL, 10 FROM menus WHERE slug = 'help_menu';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'How to pay', '/how-to-pay', 'phone', NULL, 20 FROM menus WHERE slug = 'help_menu';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Delivery & pickup', '/delivery-pickup', 'truck', NULL, 30 FROM menus WHERE slug = 'help_menu';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Returns & refunds', '/returns-policy', 'arrow-repeat', NULL, 40 FROM menus WHERE slug = 'help_menu';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Contact us', '/contact', 'chat-dots', NULL, 50 FROM menus WHERE slug = 'help_menu';
INSERT INTO menus (slug, title, description) VALUES ('footer_highlights', 'Footer highlights', 'Short highlights beside the logo at the top of the footer.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Home delivery', NULL, 'truck', NULL, 10 FROM menus WHERE slug = 'footer_highlights';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Pickup stations', NULL, 'shop', NULL, 20 FROM menus WHERE slug = 'footer_highlights';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'M-Pesa & COD', NULL, 'phone', NULL, 30 FROM menus WHERE slug = 'footer_highlights';
INSERT INTO menus (slug, title, description) VALUES ('footer_col_1', 'Need help?', 'First link column in the footer. The menu title is the column heading.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Help Center', '/help', NULL, NULL, 10 FROM menus WHERE slug = 'footer_col_1';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Contact us', '/contact', NULL, NULL, 20 FROM menus WHERE slug = 'footer_col_1';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Track your order', '/track-order', NULL, NULL, 30 FROM menus WHERE slug = 'footer_col_1';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Returns & refunds', '/returns-policy', NULL, NULL, 40 FROM menus WHERE slug = 'footer_col_1';
INSERT INTO menus (slug, title, description) VALUES ('footer_col_2', 'Shopping', 'Second link column in the footer.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'How to pay', '/how-to-pay', NULL, NULL, 10 FROM menus WHERE slug = 'footer_col_2';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Delivery & pickup', '/delivery-pickup', NULL, NULL, 20 FROM menus WHERE slug = 'footer_col_2';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'My account', '/account', NULL, NULL, 30 FROM menus WHERE slug = 'footer_col_2';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Cart', '/cart', NULL, NULL, 40 FROM menus WHERE slug = 'footer_col_2';
INSERT INTO menus (slug, title, description) VALUES ('footer_col_3', 'About {store}', 'Third link column in the footer.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'About us', '/about', NULL, NULL, 10 FROM menus WHERE slug = 'footer_col_3';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Sell on {store}', '/sell', NULL, NULL, 20 FROM menus WHERE slug = 'footer_col_3';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Terms & conditions', '/terms', NULL, NULL, 30 FROM menus WHERE slug = 'footer_col_3';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Privacy policy', '/privacy', NULL, NULL, 40 FROM menus WHERE slug = 'footer_col_3';
INSERT INTO menus (slug, title, description) VALUES ('home_side_tiles', 'Homepage side tiles', 'The three tiles beside the homepage slider (title, short text, link).');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Pay with M-Pesa', '/how-to-pay', 'phone', 'Till, Paybill or Send Money', 10 FROM menus WHERE slug = 'home_side_tiles';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Pickup stations', '/delivery-pickup', 'shop', 'Collect at a time that suits you', 20 FROM menus WHERE slug = 'home_side_tiles';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Easy returns', '/returns-policy', 'arrow-repeat', 'Hassle-free return requests', 30 FROM menus WHERE slug = 'home_side_tiles';
INSERT INTO menus (slug, title, description) VALUES ('home_values', 'Homepage "why shop with us"', 'The strip of reasons near the bottom of the homepage.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Home & office delivery', NULL, 'truck', 'Delivery estimates shown at checkout for your area.', 10 FROM menus WHERE slug = 'home_values';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Pickup stations', NULL, 'shop', 'Collect your order with a secure collection code.', 20 FROM menus WHERE slug = 'home_values';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Safe payments', NULL, 'shield-check', 'M-Pesa Till, Paybill, Send Money or cash on delivery.', 30 FROM menus WHERE slug = 'home_values';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Easy returns', NULL, 'arrow-counterclockwise', 'Request a return from your order page.', 40 FROM menus WHERE slug = 'home_values';
INSERT INTO menus (slug, title, description) VALUES ('mobile_nav', 'Mobile bottom bar', 'The bar at the bottom of the screen on phones (up to 5 items). A /cart link shows the cart count.');
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Home', '/', 'house', NULL, 10 FROM menus WHERE slug = 'mobile_nav';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Categories', '/categories', 'grid', NULL, 20 FROM menus WHERE slug = 'mobile_nav';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Account', '/account', 'person', NULL, 30 FROM menus WHERE slug = 'mobile_nav';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Help', '/help', 'question-circle', NULL, 40 FROM menus WHERE slug = 'mobile_nav';
INSERT INTO menu_items (menu_id, label, link, icon, description, sort_order) SELECT id, 'Cart', '/cart', 'cart3', NULL, 50 FROM menus WHERE slug = 'mobile_nav';

-- Homepage, top to bottom (as it is today).
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('hero', 'Slider and categories', '{store} deals', NULL, 10);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('quick_categories', 'Popular categories', NULL, 6, 20);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('flash_sale', 'Flash sale', NULL, 12, 30);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('top_deals', 'Top Deals', NULL, 12, 40);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('shop_by_category', 'Shop by Category', NULL, 12, 50);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('featured', 'Top Selling Items', NULL, 12, 60);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('category_rails', 'Category rows', NULL, 4, 70);
INSERT INTO home_sections (type, title, subtitle, item_limit, sort_order) VALUES ('values', 'Why shop with us', NULL, NULL, 80);
