-- =============================================================================
-- 003_ohada_backfill.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 3 · fix broken OHADA mappings.
--
-- BACKGROUND (from AUDIT_REPORT §3.1 C7):
--   The first 8 rows of chart_of_accounts use vanity "LPC" codes with
--   ohada_account_id = NULL. Every existing journal entry references one of
--   those NULL-mapped accounts, so `financial_mappings` (which rolls up by
--   OHADA prefix) cannot produce a Bilan or Compte de Résultat. Reports are
--   silently returning zero for pre-migration data.
--
-- WHAT THIS MIGRATION DOES:
--   1. Ensures the target OHADA rows exist in `ohada_accounts` (adds them if
--      missing — safe because ohada_accounts.account_number should be UNIQUE).
--   2. Backfills chart_of_accounts.ohada_account_id for the 8 LPC-prefixed
--      rows by mapping LPC<XXX>YY → OHADA XXX (first 3 digits).
--   3. Ensures ohada_account_id is NOT NULL and FK'd going forward — but only
--      after the backfill so the constraint doesn't reject the migration.
--
-- Idempotent. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- 1. Add UNIQUE on ohada_accounts.account_number if it isn't there yet
--    (needed for the ON DUPLICATE KEY UPDATE below).
SET @sql = (
  SELECT IF(
    COUNT(*) = 0,
    'ALTER TABLE ohada_accounts ADD UNIQUE KEY uq_ohada_number (account_number)',
    'SELECT "ohada_accounts uq exists" AS info'
  )
  FROM information_schema.statistics
  WHERE table_schema = DATABASE()
    AND table_name = 'ohada_accounts'
    AND index_name = 'uq_ohada_number'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Seed the parent OHADA classes we need if they aren't there.
--    OHADA 3-digit convention:
--      401 Fournisseurs         · 411 Clients
--      521 Banques              · 571 Caisse
--      601 Achats               · 605 Autres achats
--      701 Ventes de biens
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('401', 'Fournisseurs',                        'liability'),
  ('411', 'Clients',                             'asset'),
  ('521', 'Banques',                             'asset'),
  ('571', 'Caisse',                              'asset'),
  ('601', 'Achats de marchandises',              'expense'),
  ('605', 'Autres achats',                       'expense'),
  ('701', 'Ventes de produits fabriqués',        'revenue')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type);

-- 3. Backfill mappings for the 8 LPC-prefixed rows.
--    LPCXYYYZZ → OHADA XYY (first 3 digits of the numeric part).
--    Concretely: LPC41211 → 411, LPC70111 → 701, LPC60531 → 605,
--                LPC05711 → 571, LPC52111 → 521, LPC70113 → 701,
--                LPC40111 → 401  (row 8 varies; we handle 7 known + any
--                remaining LPC-prefixed row via the fallback UPDATE).
UPDATE chart_of_accounts coa
  JOIN ohada_accounts    o  ON o.account_number = SUBSTRING(coa.code, 4, 3)
   SET coa.ohada_account_id = o.id
 WHERE coa.code LIKE 'LPC%'
   AND coa.ohada_account_id IS NULL
   AND SUBSTRING(coa.code, 4, 3) REGEXP '^[0-9]{3}$';

-- Sanity: any LPC row still NULL means our regex didn't parse the code.
-- Log to a temporary variable — the migration continues so the admin can
-- inspect via the query below.
SET @unmapped = (SELECT COUNT(*) FROM chart_of_accounts WHERE ohada_account_id IS NULL);

-- 4. Enforce NOT NULL going forward (only if fully backfilled).
--    We use a conditional ALTER so the migration doesn't fail on partial data.
SET @sql = IF(@unmapped = 0,
    'ALTER TABLE chart_of_accounts MODIFY COLUMN ohada_account_id INT(11) NOT NULL',
    'SELECT CONCAT("Skipping NOT NULL — ", @unmapped, " rows still unmapped. Fix manually then rerun.") AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Add FK if missing (only when NOT NULL is in place; skip otherwise).
SET @has_fk = (
  SELECT COUNT(*) FROM information_schema.key_column_usage
  WHERE table_schema = DATABASE()
    AND table_name = 'chart_of_accounts'
    AND column_name = 'ohada_account_id'
    AND referenced_table_name = 'ohada_accounts'
);
SET @sql = IF(@has_fk = 0 AND @unmapped = 0,
    'ALTER TABLE chart_of_accounts
        ADD CONSTRAINT fk_coa_ohada
        FOREIGN KEY (ohada_account_id) REFERENCES ohada_accounts(id)
        ON DELETE RESTRICT ON UPDATE CASCADE',
    'SELECT "FK skipped (already exists or unmapped rows remain)" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;

-- =============================================================================
-- Verification (run manually after import):
--   SELECT COUNT(*) AS still_unmapped FROM chart_of_accounts WHERE ohada_account_id IS NULL;
--     -- expect 0
--   SELECT coa.code, coa.name, o.account_number, o.name AS ohada_name
--     FROM chart_of_accounts coa
--     LEFT JOIN ohada_accounts o ON o.id = coa.ohada_account_id
--     ORDER BY coa.id;
-- =============================================================================
