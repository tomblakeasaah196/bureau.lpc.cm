-- =============================================================================
-- 074_empties_in_flight.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — empties expected back from open BLs (in-flight consignes).
--
-- WHY
-- ---
-- client_empties_ledger.quantity_owed is the number of empties the ERP is
-- SURE the client owes us, and it only moves at BL signature time (see
-- includes/functions/signature_side_effects.php :: lpc_signature_side_effects
-- _bl). Between BL dispatch and customer signature — hours in the best case,
-- days when a driver's phone is offline, weeks when a paper BL is doing the
-- rounds — the empties are physically with the client but the ledger reports
-- zero. That "in-flight" gap is precisely what let a delivered order like
-- CMD-2608-03837 (1666 units to JBS PRAXIS) look green on Ventes while the
-- Consignes page had no idea they existed.
--
-- The forward fix is a second bucket on the same ledger row:
--
--   total_out         — booked at BL signature (existing, unchanged)
--   total_in          — booked at BL signature or CRE signature (unchanged)
--   quantity_owed     — total_out - total_in, clamped ≥ 0 (unchanged)
--   quantity_in_flight — NEW: empties currently expected from open BLs
--
-- quantity_in_flight is written at BL dispatch (sales_controller.php ::
-- save_order) and decremented at BL signature (moves into total_out) or at
-- BL cancellation. The Consignes UI surfaces it as a distinct amber column
-- next to Solde (Dû), and as its own KPI card, so an ops supervisor sees
-- confirmed dues (red) and unconfirmed ones (amber) side by side without
-- their eyes conflating the two.
--
-- BACKFILL
-- --------
-- Every currently open BL (status dispatched | driver_confirmed) whose line
-- carries a consigne (products.linked_empty_id IS NOT NULL) is retroactively
-- added into quantity_in_flight. Uses INSERT ... ON DUPLICATE KEY UPDATE so
-- rows that already exist (from a prior signature or backfill) get merged
-- correctly; UNIQUE(client_id, site_id, product_id) is the merge key.
--
-- Idempotency: re-running this migration is safe only up to the ADD COLUMN
-- (MySQL will refuse a second ADD). The backfill portion is guarded so a
-- second run does NOT double-count: it subtracts the current
-- quantity_in_flight before adding the freshly computed one, converging on
-- the correct value. Concretely we recompute in a subquery and then
-- overwrite, rather than accumulate.
-- =============================================================================

ALTER TABLE `client_empties_ledger`
    ADD COLUMN `quantity_in_flight` INT NOT NULL DEFAULT 0
        COMMENT 'Empties dispatched on open BLs (dispatched/driver_confirmed) but not yet reconciled by signature. Distinct from quantity_owed which is post-signature.'
        AFTER `quantity_owed`;

-- ---------------------------------------------------------------------------
-- Backfill from open BLs. The pattern is UPDATE-then-INSERT rather than
-- ON DUPLICATE KEY UPDATE for a load-bearing reason:
--
--   client_empties_ledger has UNIQUE(client_id, site_id, product_id), and
--   MySQL InnoDB treats NULL values in UNIQUE indexes as DISTINCT. Existing
--   ledger rows almost all have site_id = NULL (BLs are per-client, not
--   per-site). If we did INSERT ... ON DUPLICATE KEY UPDATE with site_id
--   set to NULL, the unique key would fail to match those existing rows,
--   and we would insert DUPLICATE ledger rows for every open-BL client —
--   exactly the corruption this migration is meant to prevent visibility
--   into.
--
-- Step 1: build the truth table into a temporary derived set.
-- Step 2: UPDATE ledger rows that already exist for (client_id NULL-site,
--         product_id) — the null-safe join uses <=> so NULL site matches
--         NULL site.
-- Step 3: INSERT only the (client, product) combos that had no ledger row
--         at all.
-- ---------------------------------------------------------------------------

-- Snapshot of the truth-per-key. Kept as a temporary table so we can join
-- to it twice (update + insert) without recomputing.
CREATE TEMPORARY TABLE `_074_in_flight_truth` (
    `client_id`  INT NOT NULL,
    `product_id` INT NOT NULL,
    `in_flight`  INT NOT NULL,
    PRIMARY KEY (`client_id`, `product_id`)
) ENGINE=InnoDB;

INSERT INTO `_074_in_flight_truth` (`client_id`, `product_id`, `in_flight`)
SELECT
    d.`client_id`,
    p.`linked_empty_id`,
    SUM(COALESCE(di.`delivered_quantity`, di.`quantity`))
FROM `deliveries` d
JOIN `delivery_items` di ON di.`delivery_id` = d.`id`
JOIN `products` p        ON p.`id` = di.`product_id`
WHERE d.`status` IN ('dispatched', 'driver_confirmed')
  AND p.`linked_empty_id` IS NOT NULL
GROUP BY d.`client_id`, p.`linked_empty_id`;

-- Step 2: overwrite quantity_in_flight on rows that already exist. NULL-
-- safe on site_id via <=>, so an existing (client, NULL, product) row is
-- matched, updated in place, and NOT duplicated.
UPDATE `client_empties_ledger` cel
  JOIN `_074_in_flight_truth` t
    ON t.`client_id`  = cel.`client_id`
   AND t.`product_id` = cel.`product_id`
   AND cel.`site_id` <=> NULL
   SET cel.`quantity_in_flight` = t.`in_flight`;

-- Step 3: insert the (client, product) combos that have no ledger row yet.
INSERT INTO `client_empties_ledger`
    (`client_id`, `site_id`, `product_id`,
     `total_out`, `total_in`, `quantity_owed`, `quantity_in_flight`)
SELECT t.`client_id`, NULL, t.`product_id`, 0, 0, 0, t.`in_flight`
  FROM `_074_in_flight_truth` t
  LEFT JOIN `client_empties_ledger` cel
    ON cel.`client_id`  = t.`client_id`
   AND cel.`product_id` = t.`product_id`
   AND cel.`site_id` <=> NULL
 WHERE cel.`id` IS NULL;

DROP TEMPORARY TABLE `_074_in_flight_truth`;
