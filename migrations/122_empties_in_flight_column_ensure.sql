-- =============================================================================
-- 122_empties_in_flight_column_ensure.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — ensure client_empties_ledger.quantity_in_flight exists.
--
-- WHY
-- ---
-- Migration 074_empties_in_flight.sql adds that column with a BARE
-- `ALTER TABLE ... ADD COLUMN`, so it is the one migration in this directory
-- that cannot be re-run: MySQL refuses the second ADD, and the file has no
-- information_schema guard around it. scripts/tests/migration_lint.test.php
-- has been warning about exactly that.
--
-- WHY 074 IS NOT SIMPLY FIXED IN PLACE
-- ------------------------------------
-- Because it has already been applied. scripts/migrate.php records a SHA-256
-- of every migration in schema_migrations and refuses to continue when a
-- recorded file changes on disk:
--
--   REFUSING to skip 074_empties_in_flight: file checksum has changed.
--   Fix the migration (create a new one) or pass --force.
--
-- and exits 1 — which would block the whole deploy, not just that file. That
-- is not a hypothetical: migration 115 drifted this way, was skipped in
-- production, and products.cost_price was never created until
-- 116_product_cost_price_ensure.sql went in to repair it. This file is the
-- same remedy for the same class of problem, and the remedy the runner's own
-- error message prescribes. 074 stays frozen, byte for byte.
--
-- WHAT THIS DOES
-- --------------
-- Guarantees the column exists, using the guarded idiom from 045 / 047 / 055.
-- On every healthy database — where 074 applied normally — this is a no-op.
--
-- SCHEMA ONLY, DELIBERATELY
-- -------------------------
-- 074 also backfills quantity_in_flight from open BLs. That half is NOT
-- repeated here: this repo already owns a safer tool for it,
-- scripts/reconcile_empties_in_flight.php, which recomputes the same truth
-- from deliveries + delivery_items + products.linked_empty_id and is designed
-- to run nightly. If this migration actually had to CREATE the column — i.e.
-- the database missed 074 entirely — the counter starts at 0 for every row
-- and should be seeded with:
--
--   php scripts/reconcile_empties_in_flight.php            # inspect the diff
--   php scripts/reconcile_empties_in_flight.php --apply    # write it
--
-- Rewriting live ledger figures is an operator's decision with a dry-run in
-- front of it, not something a migration should do silently on every host.
--
-- IDEMPOTENT. Additive only. Safe to re-run.
-- =============================================================================

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name   = 'client_empties_ledger'
                AND column_name  = 'quantity_in_flight');

-- Column definition kept byte-identical to 074's, so a database repaired by
-- this file is indistinguishable from one that applied 074 normally.
SET @sql := IF(@has = 0,
   'ALTER TABLE `client_empties_ledger`
        ADD COLUMN `quantity_in_flight` INT NOT NULL DEFAULT 0
            COMMENT ''Empties dispatched on open BLs (dispatched/driver_confirmed) but not yet reconciled by signature. Distinct from quantity_owed which is post-signature.''
            AFTER `quantity_owed`',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW COLUMNS FROM client_empties_ledger LIKE 'quantity_in_flight';
--
--   -- and, if this file had to create it, whether the counter needs seeding:
--   SELECT COUNT(*) AS rows_in_flight FROM client_empties_ledger
--    WHERE quantity_in_flight <> 0;
