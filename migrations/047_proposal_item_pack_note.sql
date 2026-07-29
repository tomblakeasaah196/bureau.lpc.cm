-- =============================================================================
-- 047_proposal_item_pack_note.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — a devis line for a packed product (e.g. "Supermont La
-- Pétillante Verre 33cl", sold as a pack of 12 bottles at 5 500 FCFA) prints
-- product_description + product_format + unit_price and nothing else. A
-- client reading that has no way to tell 5 500 FCFA is the price of the pack,
-- not of one bottle — the same "pack de 12 bouteilles" note Master Data Hub
-- already shows (admin-master_data.js:packBadge()) never reaches the quote.
--
-- WHAT THIS ADDS:
--   proposal_items.pack_note — a plain-text snapshot ("pack de 12
--   bouteilles"), written once at devis creation from the product's
--   units_per_pack / unit_of_measure. Snapshotted rather than joined for the
--   same reason product_description and product_format already are (see the
--   "NO JOINS! Reading pure snapshot data" comments in get_proposal.php): a
--   later edit to a product's pack size in Master Data must not silently
--   change the wording of a devis that already went out.
--
--   NULL for anything sold by the unit (units_per_pack <= 1) — the column is
--   only populated when there is something to disclose.
--
-- IDEMPOTENT. Additive only.
-- =============================================================================

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposal_items' AND column_name='pack_note');
SET @sql := IF(@has=0,
   'ALTER TABLE proposal_items ADD COLUMN pack_note VARCHAR(80) NULL COMMENT ''Snapshot, e.g. "pack de 12 bouteilles". NULL when sold by the unit.'' AFTER product_format',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW COLUMNS FROM proposal_items LIKE 'pack_note';
--
--   -- Existing devis stay NULL (pre-dates this column; the historical PDF
--   -- already went out without the note and re-deriving it now would not
--   -- match what the client actually received). Only new devis populate it.
--   SELECT id, proposal_id, product_description, product_format, pack_note
--     FROM proposal_items ORDER BY id DESC LIMIT 10;
