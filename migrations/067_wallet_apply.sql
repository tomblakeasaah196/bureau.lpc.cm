-- ================================================================
-- Migration 067: enable client-wallet application against invoices
--
-- Why:
--   client_wallets.balance only ever went UP. register_payment adds
--   overpayments to it, validate_cash adds unlinked driver cash to
--   it, but nothing consumes it. An operator seeing "PROMETAL S.A —
--   400 000 F" in Trop Perçus (Avoirs) had no way to apply that
--   credit to a new invoice — they would either re-enter a manual
--   payment (double-counting) or leave the invoice open forever.
--
--   This migration widens payments.payment_method so a synthetic
--   'wallet' payment can be booked when a wallet is applied, and
--   adds a small index used by the new apply_client_wallet endpoint.
--
-- Safe to re-run: MODIFY replaces the ENUM definition wholesale.
-- ================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- 1. Widen payments.payment_method to accept 'wallet'.
--    Existing values ('cash','momo','bank') are preserved verbatim.
ALTER TABLE `payments`
    MODIFY COLUMN `payment_method` ENUM('cash','momo','bank','wallet')
    NOT NULL DEFAULT 'cash';

-- 2. Index the wallet lookup path — client_id already has one via the
--    PK, so this is only about the (client_id, balance) filter used
--    on the "avoirs disponibles" list.
--    Idempotent via information_schema probe (MySQL 5.7 has no
--    CREATE INDEX IF NOT EXISTS).
SET @has_idx := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'client_wallets'
       AND index_name = 'idx_client_wallets_positive'
);
SET @sql := IF(@has_idx = 0,
    'CREATE INDEX idx_client_wallets_positive ON client_wallets (client_id, balance)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
