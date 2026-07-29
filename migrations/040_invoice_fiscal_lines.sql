-- =============================================================================
-- 040_invoice_fiscal_lines.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · make an invoice legally complete.
--
-- WHY:
--   invoices carried exactly three fiscal facts: subtotal, tva_rate, tva_amount
--   (migration 020 later added air_rate / air_amount / net_payable). A
--   Cameroonian invoice needs more than that to survive a DGI control:
--
--   1. A 0 % line with no stated reason. Half of LPC's catalogue is water,
--      which is exonerated — but the invoice printed a bare "TVA (0%)  0 FCFA".
--      An auditor reading that cannot tell an exoneration from an omission,
--      and the client cannot justify the charge. Art. 150 CGI requires the
--      basis to be stated. tva_exemption_reason carries it.
--
--   2. Précompte sur achats. Withheld on sales to resellers; the rate depends
--      on the buyer's régime (currently 0,5 % / 5 % / 14 %). It sits between
--      the TTC and what the client actually transfers, so it must be a stored
--      column and not a display-time guess — the rate in force on the day of
--      issue is what the invoice has to keep saying five years later.
--
--   3. Droit d'accises. Applies to part of the beverage catalogue and is
--      computed BEFORE TVA (TVA base = HT + accises), so it cannot be
--      back-derived from the totals already stored.
--
--   Rates are stored per invoice, never read live from a settings table at
--   print time. A reprint of a 2024 invoice must show the 2024 rates.
--
-- IDEMPOTENT:
--   Every ALTER is guarded on information_schema, in the same style as
--   migration 020, so a re-run is a no-op.
--
-- SAFETY:
--   Additive only — new nullable / defaulted columns. No data is rewritten
--   and no existing column changes type. Existing rows land on 0 / NULL,
--   which renders exactly as today.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. invoices — TVA exemption basis
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='tva_exemption_reason');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN tva_exemption_reason VARCHAR(160) NULL COMMENT ''Legal basis printed when tva_rate = 0, e.g. "Exonéré — art. 128 CGI (eau)". NULL when TVA applies.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 2. invoices — précompte sur achats
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='precompte_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN precompte_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT ''Précompte sur achats rate in force at issue date. 0 when not applicable.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='precompte_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN precompte_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''subtotal * precompte_rate''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 3. invoices — droit d'accises (computed before TVA)
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='excise_rate');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN excise_rate DECIMAL(5,4) NOT NULL DEFAULT 0.0000 COMMENT ''Droit d''''accises rate. Applied to subtotal; the result joins the TVA base.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='invoices' AND column_name='excise_amount');
SET @sql := IF(@has=0,
   'ALTER TABLE invoices ADD COLUMN excise_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT ''subtotal * excise_rate''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. clients — RCCM alongside the NIU
-- -----------------------------------------------------------------------------
-- clients.niu and clients.rc already exist in the base schema. `rc` is the
-- older, shorter name; nothing renames it here because create_client.php and
-- update_client.php both write to it. This only widens it, since a full RCCM
-- ("RC/DLA/2019/A/A/687") does not always fit the original VARCHAR(50).
SET @len := (SELECT character_maximum_length FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='rc');
SET @sql := IF(@len IS NOT NULL AND @len < 80,
   'ALTER TABLE clients MODIFY COLUMN rc VARCHAR(80) DEFAULT NULL COMMENT ''RCCM — Registre du Commerce et du Crédit Mobilier''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Index the NIU: the B2B compliance check ("does this client have a NIU?")
-- runs on every invoice generation.
SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema=DATABASE() AND table_name='clients' AND index_name='idx_clients_niu');
SET @sql := IF(@has=0, 'CREATE INDEX idx_clients_niu ON clients (niu)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SELECT column_name, column_type, column_default
--     FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'invoices'
--      AND column_name IN ('tva_exemption_reason','precompte_rate','precompte_amount',
--                          'excise_rate','excise_amount','air_rate','air_amount','net_payable');
--
--   -- every issued invoice should reconcile:
--   SELECT reference, subtotal, excise_amount, tva_amount, total_amount,
--          ROUND(subtotal + excise_amount + tva_amount, 2) AS recomputed
--     FROM invoices
--    WHERE ROUND(subtotal + excise_amount + tva_amount, 2) <> ROUND(total_amount, 2);
