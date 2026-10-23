-- Ecommerce 020: one account per phone number, so customers, sellers and staff can sign in with their phone or email.
-- Additive: no data changes. If two accounts already share a phone number, this statement is refused as a whole
-- ("Duplicate entry '2547…' for key 'uq_users_phone'") and nothing changes; find them with
--   SELECT phone, GROUP_CONCAT(email) FROM users WHERE phone IS NOT NULL GROUP BY phone HAVING COUNT(*) > 1;
-- correct those accounts, then run the migration again. Accounts without a phone (NULL) are not affected.
ALTER TABLE users DROP INDEX ix_users_phone, ADD UNIQUE KEY uq_users_phone (phone);
