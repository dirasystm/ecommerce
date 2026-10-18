-- Waimart 002: catalogue, variants, inventory (on-hand / reserved) and stock movement ledger.

CREATE TABLE categories (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT UNSIGNED NULL,
    name        VARCHAR(120) NOT NULL,
    slug        VARCHAR(140) NOT NULL,
    description TEXT NULL,
    icon        VARCHAR(60) NULL,
    image_path  VARCHAR(255) NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cat_slug (slug),
    KEY ix_cat_parent (parent_id, is_active, sort_order),
    CONSTRAINT fk_cat_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE brands (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(120) NOT NULL,
    slug       VARCHAR(140) NOT NULL,
    logo_path  VARCHAR(255) NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brand_slug (slug),
    UNIQUE KEY uq_brand_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_id       INT UNSIGNED NOT NULL,
    brand_id          INT UNSIGNED NULL,
    name              VARCHAR(200) NOT NULL,
    slug              VARCHAR(220) NOT NULL,
    short_description VARCHAR(500) NULL,
    description       MEDIUMTEXT NULL,
    specifications    MEDIUMTEXT NULL,             -- JSON object of label => value
    is_active         TINYINT(1) NOT NULL DEFAULT 1,
    is_featured       TINYINT(1) NOT NULL DEFAULT 0,
    max_per_order     SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    rating_avg        DECIMAL(3,2) NOT NULL DEFAULT 0.00,
    rating_count      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_prod_slug (slug),
    KEY ix_prod_cat (category_id, is_active),
    KEY ix_prod_brand (brand_id, is_active),
    KEY ix_prod_featured (is_featured, is_active),
    FULLTEXT KEY ft_prod_search (name, short_description),
    CONSTRAINT fk_prod_cat FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
    CONSTRAINT fk_prod_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL,
    CONSTRAINT ck_prod_max CHECK (max_per_order >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id BIGINT UNSIGNED NOT NULL,
    path       VARCHAR(255) NOT NULL,
    alt_text   VARCHAR(200) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    KEY ix_pimg_prod (product_id, sort_order),
    CONSTRAINT fk_pimg_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every sellable unit is a variant. Simple products have exactly one default variant.
CREATE TABLE product_variants (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id       BIGINT UNSIGNED NOT NULL,
    sku              VARCHAR(64) NOT NULL,
    name             VARCHAR(120) NOT NULL DEFAULT 'Default',   -- e.g. "Black / 128GB"
    price            DECIMAL(12,2) NOT NULL,
    compare_at_price DECIMAL(12,2) NULL,                         -- "was" price for discounts
    is_default       TINYINT(1) NOT NULL DEFAULT 0,
    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    sort_order       INT NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_var_sku (sku),
    KEY ix_var_prod (product_id, is_active),
    KEY ix_var_price (price),
    CONSTRAINT fk_var_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT ck_var_price CHECK (price >= 0),
    CONSTRAINT ck_var_compare CHECK (compare_at_price IS NULL OR compare_at_price >= price)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE variant_attributes (
    variant_id BIGINT UNSIGNED NOT NULL,
    attr_name  VARCHAR(60) NOT NULL,     -- e.g. Colour, Size, Storage
    attr_value VARCHAR(100) NOT NULL,
    PRIMARY KEY (variant_id, attr_name),
    KEY ix_va_filter (attr_name, attr_value),
    CONSTRAINT fk_va_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- C1: on-hand vs reserved. available = on_hand - reserved. Invariants enforced by CHECKs.
CREATE TABLE inventory (
    variant_id          BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    on_hand             INT NOT NULL DEFAULT 0,
    reserved            INT NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 5,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_inv_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE RESTRICT,
    CONSTRAINT ck_inv_on_hand CHECK (on_hand >= 0),
    CONSTRAINT ck_inv_reserved CHECK (reserved >= 0),
    CONSTRAINT ck_inv_available CHECK (reserved <= on_hand)
) ENGINE=InnoDB;

CREATE TABLE banners (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(120) NOT NULL,
    subtitle   VARCHAR(200) NULL,
    image_path VARCHAR(255) NULL,
    link_url   VARCHAR(255) NULL,
    position   ENUM('hero','side','strip') NOT NULL DEFAULT 'hero',
    sort_order INT NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    starts_at  DATETIME NULL,
    ends_at    DATETIME NULL,
    KEY ix_banner_pos (position, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE flash_sales (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(120) NOT NULL,
    starts_at  DATETIME NOT NULL,
    ends_at    DATETIME NOT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_fs_window (is_active, starts_at, ends_at),
    CONSTRAINT ck_fs_window CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE flash_sale_items (
    flash_sale_id INT UNSIGNED NOT NULL,
    variant_id    BIGINT UNSIGNED NOT NULL,
    sale_price    DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (flash_sale_id, variant_id),
    KEY ix_fsi_var (variant_id),
    CONSTRAINT fk_fsi_sale FOREIGN KEY (flash_sale_id) REFERENCES flash_sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_fsi_var FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    CONSTRAINT ck_fsi_price CHECK (sale_price >= 0)
) ENGINE=InnoDB;

CREATE TABLE wishlist_items (
    user_id    BIGINT UNSIGNED NOT NULL,
    product_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, product_id),
    CONSTRAINT fk_wish_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_wish_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;
