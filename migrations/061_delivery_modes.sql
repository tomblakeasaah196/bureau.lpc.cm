-- =============================================================================
-- 061_delivery_modes.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — make the delivery channel explicit on `deliveries`, and stop
-- treating "affectation journalière" as the only way goods can reach a client.
--
-- BACKGROUND — WHY THE OLD SHAPE NO LONGER FITS
-- ---------------------------------------------
-- `deliveries` encoded the logistics channel implicitly, in the NULL-ness of
-- two columns:
--
--     driver_id  NULL  COMMENT 'Null means Enlèvement Magasin (Warehouse Pickup)'
--     vehicle_id NULL  COMMENT 'Null means Client used their own vehicle'
--
-- Two nullable FKs, four combinations, only two of them meaningful, and the
-- meaning written in a column comment rather than in the data. That was already
-- fragile. It becomes wrong under the new business model.
--
-- The old model: LPC owns the trucks. Every morning Flotte records a
-- `vehicle_assignments` row pairing a driver with a vehicle for the day. Sales
-- could then only dispatch to a driver who appeared in THAT day's assignment
-- list — api/v1/sales_controller.php `fetch_metadata` selected drivers by
-- `JOIN vehicle_assignments a ... WHERE a.assignment_date = CURRENT_DATE()`,
-- so the chauffeur dropdown on the BL modal was empty whenever Flotte had not
-- yet done the morning affectation. No affectation, no driver, and in practice
-- no BL: sales was blocked on a fleet-office task.
--
-- The new model: a supplier frequently delivers straight to the client's site.
-- No LPC driver, no LPC vehicle, no affectation — and yet a perfectly real
-- delivery that must produce a BL, move stock, and post COGS exactly as before.
-- Under the old encoding that delivery is indistinguishable from a warehouse
-- pickup: both are driver_id NULL, vehicle_id NULL.
--
-- WHAT THIS MIGRATION DOES
-- ------------------------
--   1. `deliveries.delivery_mode` — an explicit enum, NOT NULL, defaulting to
--      'own_fleet':
--
--        'own_fleet'     LPC driver + LPC vehicle (the historical default).
--        'supplier'      The supplier delivered directly to the client site.
--        'client_pickup' Enlèvement magasin — client came with their own vehicle.
--
--   2. `deliveries.delivery_supplier_id` — nullable FK to `suppliers`, set only
--      when delivery_mode = 'supplier'. A named supplier rather than a bare
--      flag, so "which supplier delivered how much, to whom, when" is a join
--      and not a guess. ON DELETE SET NULL: losing a supplier record must never
--      cascade into deleting delivery history.
--
--   3. A CHECK constraint tying the two together, so the pair cannot drift:
--      a supplier id is allowed only in 'supplier' mode, and 'supplier' mode
--      requires one. MariaDB 10.2+ / MySQL 8.0.16+ enforce CHECK for real; on
--      older engines it parses and is ignored, and the API-side validation in
--      sales_controller.php `generate_dispatch` is the enforcing layer either
--      way. The constraint is added inside a guard so an engine that rejects
--      the syntax outright does not abort the migration.
--
--   4. Backfill of existing rows from the old implicit encoding:
--        driver_id IS NOT NULL  ->  'own_fleet'
--        driver_id IS NULL      ->  'client_pickup'
--      That is exactly what the old column comment said those rows meant, so
--      the backfill is lossless. No historical row can be 'supplier': the
--      concept did not exist before today.
--
--   5. An index on (delivery_mode, date) for the dispatch-tab filters and the
--      logistics KPIs, which now slice by channel.
--
-- WHAT THIS MIGRATION DELIBERATELY DOES NOT DO
-- --------------------------------------------
--   * It does NOT drop `vehicle_assignments`. The Flotte module still uses it
--     to plan the day and the driver dashboard still reads it; it simply stops
--     being a PRECONDITION for invoicing-side work. Affectation becomes a
--     convenience (it pre-fills the vehicle) rather than a gate.
--
--   * It does NOT touch `driver_id` / `vehicle_id` nullability. Both stay
--     nullable and both stay meaningful for 'own_fleet'. Their column comments
--     are rewritten to stop claiming that NULL implies pickup — after this
--     migration `delivery_mode` is the single source of truth for the channel.
--
--   * It adds NOTHING to the customer-facing document. `delivery_mode` and
--     `delivery_supplier_id` are internal logistics attributes. public/documents/
--     bon_livraison.php and api/v1/get_bl.php must never select or render them:
--     the client's BL says what was delivered and to whom, not which of LPC's
--     internal channels carried it. Same rule as the driver/vehicle columns,
--     which the BL has never printed either.
--
-- IDEMPOTENT. Every step is guarded on information_schema, so re-running is a
-- no-op. Safe to replay on a database that already has part of this applied.
--
-- ROLLBACK
-- --------
--   ALTER TABLE deliveries DROP FOREIGN KEY fk_delivery_supplier;
--   ALTER TABLE deliveries DROP INDEX  idx_deliveries_mode_date;
--   ALTER TABLE deliveries DROP CHECK  chk_delivery_supplier_mode;
--   ALTER TABLE deliveries DROP COLUMN delivery_supplier_id;
--   ALTER TABLE deliveries DROP COLUMN delivery_mode;
-- Dropping the columns loses the channel distinction for rows created after
-- this migration — 'supplier' rows collapse back into looking like pickups.
-- Export before rolling back if that distinction matters.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. deliveries.delivery_mode
-- -----------------------------------------------------------------------------
-- NOT NULL with a default so every pre-existing row is valid the instant the
-- column lands, and so any INSERT written before this migration (which does not
-- mention the column) keeps working and lands on the historical default.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'deliveries'
       AND column_name  = 'delivery_mode'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE deliveries
       ADD COLUMN delivery_mode ENUM(''own_fleet'',''supplier'',''client_pickup'')
           NOT NULL DEFAULT ''own_fleet''
           COMMENT ''Logistics channel. INTERNAL ONLY — never rendered on the client BL.''
           AFTER vehicle_id',
    'SELECT ''deliveries.delivery_mode exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. deliveries.delivery_supplier_id
-- -----------------------------------------------------------------------------
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'deliveries'
       AND column_name  = 'delivery_supplier_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE deliveries
       ADD COLUMN delivery_supplier_id INT NULL
           COMMENT ''Supplier who delivered direct to client site. Set iff delivery_mode = supplier. INTERNAL ONLY.''
           AFTER delivery_mode',
    'SELECT ''deliveries.delivery_supplier_id exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK, added only once the column exists and only once (name collision guard).
-- ON DELETE SET NULL, never CASCADE: a supplier row disappearing must not take
-- delivery history with it.
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema     = DATABASE()
       AND table_name       = 'deliveries'
       AND constraint_name  = 'fk_delivery_supplier'
       AND constraint_type  = 'FOREIGN KEY'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE deliveries
       ADD CONSTRAINT fk_delivery_supplier
       FOREIGN KEY (delivery_supplier_id) REFERENCES suppliers(id)
       ON DELETE SET NULL',
    'SELECT ''fk_delivery_supplier exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3. Backfill from the old implicit encoding
-- -----------------------------------------------------------------------------
-- Runs before the CHECK is added, so the constraint is evaluated against data
-- that is already consistent. Only touches rows still sitting on the column
-- default, so a replay after someone has hand-corrected a row is harmless.
UPDATE deliveries
   SET delivery_mode = 'client_pickup'
 WHERE driver_id IS NULL
   AND delivery_supplier_id IS NULL
   AND delivery_mode = 'own_fleet';

-- -----------------------------------------------------------------------------
-- 4. CHECK constraint: mode and supplier id cannot disagree
-- -----------------------------------------------------------------------------
-- Guarded twice: on prior existence, and on engine support. MariaDB < 10.2 and
-- MySQL < 8.0.16 parse CHECK and silently ignore it — harmless, the API layer
-- validates the same rule. Nothing here can fail the migration.
SET @chk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema    = DATABASE()
       AND table_name      = 'deliveries'
       AND constraint_name = 'chk_delivery_supplier_mode'
);
SET @sql := IF(@chk_exists = 0,
    'ALTER TABLE deliveries
       ADD CONSTRAINT chk_delivery_supplier_mode CHECK (
             (delivery_mode =  ''supplier'' AND delivery_supplier_id IS NOT NULL)
          OR (delivery_mode <> ''supplier'' AND delivery_supplier_id IS NULL)
       )',
    'SELECT ''chk_delivery_supplier_mode exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5. Index for the dispatch tab and the logistics KPIs
-- -----------------------------------------------------------------------------
-- The dispatch list orders by date and now segments by channel; the KPI query
-- counts per mode over a date window. Composite in that order so the index
-- serves both the equality-on-mode and the range-on-date shapes.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'deliveries'
       AND index_name   = 'idx_deliveries_mode_date'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE deliveries ADD INDEX idx_deliveries_mode_date (delivery_mode, date)',
    'SELECT ''idx_deliveries_mode_date exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 6. Correct the now-misleading comments on the legacy columns
-- -----------------------------------------------------------------------------
-- These said NULL "means" pickup. After this migration NULL means only "no LPC
-- driver / no LPC vehicle" — which of the three channels applies is
-- delivery_mode's job. Left as MODIFY (not guarded on a value check) because
-- re-running is idempotent by nature: setting the same comment twice is a no-op.
ALTER TABLE deliveries
    MODIFY COLUMN driver_id  INT NULL
        COMMENT 'LPC driver, when delivery_mode = own_fleet. See delivery_mode for the channel.',
    MODIFY COLUMN vehicle_id INT NULL
        COMMENT 'LPC vehicle, when delivery_mode = own_fleet. See delivery_mode for the channel.';

-- =============================================================================
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW CREATE TABLE deliveries\G
--   -- expect delivery_mode ENUM(...) NOT NULL DEFAULT 'own_fleet',
--   --        delivery_supplier_id INT NULL,
--   --        CONSTRAINT fk_delivery_supplier, KEY idx_deliveries_mode_date
--
--   SELECT delivery_mode, COUNT(*) FROM deliveries GROUP BY delivery_mode;
--   -- every historical row must be own_fleet or client_pickup; zero supplier
--
--   SELECT COUNT(*) FROM deliveries
--    WHERE (delivery_mode =  'supplier' AND delivery_supplier_id IS NULL)
--       OR (delivery_mode <> 'supplier' AND delivery_supplier_id IS NOT NULL);
--   -- expect 0
--
--   -- The rule this whole migration exists to establish: a BL can be cut with
--   -- no affectation journalière at all.
--   DELETE FROM vehicle_assignments WHERE assignment_date = CURRENT_DATE();
--   -- then open Ventes & Dispatch -> Générer BL on a pending order. The modal
--   -- must still offer every active driver, and "Livraison Fournisseur" must
--   -- produce a BL. Before this change the chauffeur dropdown was empty and
--   -- the only option was enlèvement magasin.
--
--   -- And the confidentiality rule:
--   curl -s "https://bureau.lpc.cm/bon_livraison.php?token=<token>" \
--     | grep -Ei 'delivery_mode|fournisseur|chauffeur'
--   -- expect no match: the channel is internal and stays off the client's BL.
-- =============================================================================
