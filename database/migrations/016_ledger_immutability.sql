-- Waimart 016: the seller money ledger and payout history are append-only, enforced by the database.
-- Corrections are new rows (see SellerLedgerService). Any UPDATE or DELETE of an existing row is refused,
-- whatever application or tool issues it. Additive: no table or row changes.
-- (Foreign-key actions such as ON DELETE SET NULL on the acting user do not fire triggers, so removing a user
-- account still clears its attribution.)

CREATE TRIGGER trg_sle_no_update BEFORE UPDATE ON seller_ledger_entries FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seller_ledger_entries is append-only: post a correcting entry instead';

CREATE TRIGGER trg_sle_no_delete BEFORE DELETE ON seller_ledger_entries FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seller_ledger_entries is append-only: post a correcting entry instead';

CREATE TRIGGER trg_sph_no_update BEFORE UPDATE ON seller_payout_history FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seller_payout_history is append-only';

CREATE TRIGGER trg_sph_no_delete BEFORE DELETE ON seller_payout_history FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'seller_payout_history is append-only';
