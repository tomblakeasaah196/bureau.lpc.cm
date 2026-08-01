-- =============================================================================
-- 056_proposal_edit_support.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Devis répertoire (modules/crm/clients.php, "Devis" tab).
--
-- WHY THIS EXISTS:
--   Until now a devis could only be created, never re-opened. The répertoire
--   adds "edit an unsigned devis", and editing needs one thing the current
--   schema cannot give it: which PRODUCT each line was.
--
--   proposal_items is a deliberate snapshot — product_description,
--   product_format and pack_note are frozen text so a later Master Data edit
--   cannot rewrite a devis that already went out (see 047 and the "NO JOINS!"
--   comments in get_proposal.php). That is correct for RENDERING and is not
--   changed here. But it means reopening a devis in the editor leaves the
--   product picker empty: there is no id to select.
--
-- WHAT THIS ADDS:
--   proposal_items.product_id — nullable FK-less reference used for ONE
--   purpose: rehydrating the picker when a devis is reopened. It is never
--   read to render a devis or a PDF; the snapshot columns remain the only
--   source of truth for what the client was shown. No FK is declared on
--   purpose — a product deleted from the catalogue must not cascade into, or
--   block, a historical devis. A dangling id simply fails to preselect and
--   the line falls back to its snapshot text.
--
--   NULL for every pre-existing devis. update_proposal.php handles that: a
--   line with no product_id is shown read-only in the editor and can be kept
--   as-is or removed, but not re-priced through the picker.
--
--   proposals.updated_at — so the répertoire can show "modifié le" and
--   distinguish a devis edited after sending from one never touched. Only
--   added if 007_audit_columns.sql has not already provided it.
--
-- IDEMPOTENT. Additive only. Safe to re-run.
-- =============================================================================

-- ---- proposal_items.product_id ---------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposal_items'
                AND column_name='product_id');
SET @sql := IF(@has=0,
   'ALTER TABLE proposal_items
      ADD COLUMN product_id INT NULL
      COMMENT ''Editor rehydration only — NOT used to render. NULL on devis created before migration 056.''
      AFTER proposal_id',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- proposals.updated_at ---------------------------------------------------
-- 007_audit_columns.sql already ran CALL lpc_add_audit_cols('proposals'), which
-- may or may not have included updated_at depending on when that install was
-- migrated. Checked rather than assumed.
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema=DATABASE() AND table_name='proposals'
                AND column_name='updated_at');
SET @sql := IF(@has=0,
   'ALTER TABLE proposals
      ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL
      COMMENT ''Set by update_proposal.php when an unsigned devis is edited in place.''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- index for the répertoire ----------------------------------------------
-- The list sorts by date and filters by rep; without this it is a full scan of
-- proposals on every page load once the table passes a few thousand rows.
SET @has := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema=DATABASE() AND table_name='proposals'
                AND index_name='idx_proposals_date');
SET @sql := IF(@has=0,
   'CREATE INDEX idx_proposals_date ON proposals (`date`, id)',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW COLUMNS FROM proposal_items LIKE 'product_id';
--   SHOW COLUMNS FROM proposals LIKE 'updated_at';
--   SHOW INDEX FROM proposals WHERE Key_name = 'idx_proposals_date';
--
--   -- Old lines stay NULL and must remain editable-but-not-repriceable:
--   SELECT COUNT(*) FROM proposal_items WHERE product_id IS NULL;
-- =============================================================================
