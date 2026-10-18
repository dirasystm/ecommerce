-- Waimart 009: listing performance (Phase 12, measured on 20,000 products / 40,000 variants).

-- An index led by the low-selectivity is_active column made the optimiser scan every active product
-- instead of using ix_prod_cat for category pages. "Newest" sorting only needs created_at itself.
ALTER TABLE products
    DROP INDEX ix_prod_created,
    ADD KEY ix_prod_created (created_at);

-- Covers the "Top deals" ranking (default variants with a discount) without touching the table rows.
ALTER TABLE product_variants
    ADD KEY ix_var_deal (default_product_key, is_active, price, compare_at_price);
