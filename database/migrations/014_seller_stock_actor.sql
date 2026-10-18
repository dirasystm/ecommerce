-- Waimart 014: stock movements made by sellers are recorded as such in the inventory ledger.
-- Additive: the existing values are kept, so no existing row changes.
ALTER TABLE stock_movements
    MODIFY actor_type ENUM('customer','staff','system','seller') NOT NULL;
