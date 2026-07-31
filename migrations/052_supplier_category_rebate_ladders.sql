-- =============================================================================
-- 052_supplier_category_rebate_ladders.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — replace the per-(supplier, product) flat ristourne rate
-- (migration 051) with a per-(supplier, category) tiered ladder, plus the
-- shared tax rates the ladder math needs.
--
-- WHY THIS SUPERSEDES 051
-- ------------------------
-- Migration 051 modeled ristourne as one flat rate per (supplier, product)
-- pair. Source du Pays's actual arrangement, confirmed directly with the
-- supplier, is per CATEGORY (Eau vs Jus/other), not per product, and for
-- categories other than Eau it is TIERED by purchase volume:
--
--   Eau  : flat 3% of the purchase amount hors taxe (HT). Modeled as a
--          1-tier ladder so both categories go through the same mechanism —
--          if SDP ever tiers Eau too, no schema/code change is needed.
--   Jus  : 0 - 5,000,000 HT  -> 3%
--          5,000,000 - 10,000,000 HT -> 4%
--          10,000,000+ HT -> 5%
--          Confirmed: this is evaluated on the WHOLE purchase order's
--          category subtotal (not per reception), the match is a CLIFF (the
--          entire amount earns the matched tier's single rate, not sliced
--          across brackets), and exactly 5,000,000 belongs to the tier BELOW
--          the boundary (tier 1), not the tier above.
--
-- Both categories are earned net of a 21.25% withholding (19.25% TVA + 2%
-- précompte on the rebate itself), and the purchase price on our purchase
-- orders is confirmed TTC (VAT-inclusive) — so the HT base for the rate has
-- to be backed out of the entered price, not read directly from it.
--
-- All of these rates (TVA, précompte, and every ladder rate/threshold) are
-- meant to be edited from the UI when the government or the supplier changes
-- them — nothing here is meant to be a hardcoded literal in PHP.
--
-- WHAT THIS MIGRATION DOES
--   1. purchase_rebate_tax_settings — one shared row: vat_rate, precompte_rate.
--   2. supplier_category_rebates — one row per (supplier, category) that has
--      a ladder configured at all. General capability: ANY supplier can be
--      configured against ANY category, not just Source du Pays.
--   3. supplier_category_rebate_tiers — the ladder itself: N rows per
--      supplier_category_rebates row, each a (threshold_from, threshold_to,
--      rate) band. threshold_from is EXCLUSIVE (NULL = starts at 0),
--      threshold_to is INCLUSIVE (NULL = open-ended). That pairing is what
--      makes "exactly 5,000,000 is tier 1" unambiguous: tier 1's
--      threshold_to = 5000000 (<=, so 5,000,000 matches); tier 2's
--      threshold_from = 5000000 (>, so 5,000,000 does NOT match tier 2).
--   4. purchase_order_category_rebates — one row per (purchase_order,
--      category) present on that order, written at order creation. This
--      freezes the tier rate AND the tax rates in force at that moment —
--      so a later change to the ladder or the tax settings cannot silently
--      rewrite what an already-placed order is shown to have earned.
--      rebate_amount_earned accumulates as receptions happen (a PO can be
--      delivered across several partial receptions; each adds its share at
--      the frozen rate).
--   5. Seeds Source du Pays (matched by name, best-effort) with the ladder
--      described above, IF that supplier and the eau/jus categories exist.
--
-- 051's supplier_product_rebates / purchase_order_items.rebate_rate /
-- rebate_amount_earned are left in place, unused. Dropping them is a
-- separate, deliberate decision; this migration only stops the application
-- from reading or writing them going forward.
--
-- IDEMPOTENT. Every step is guarded on information_schema or uses
-- INSERT … ON DUPLICATE KEY UPDATE / WHERE NOT EXISTS. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. Shared tax settings for the rebate calculation.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_rebate_tax_settings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    vat_rate        DECIMAL(6,4) NOT NULL DEFAULT 0.1925 COMMENT 'TVA, e.g. 0.1925 = 19.25%. Used both to back HT out of TTC purchase prices and as part of the rebate withholding.',
    precompte_rate  DECIMAL(6,4) NOT NULL DEFAULT 0.0200 COMMENT 'Précompte withheld on the rebate itself, e.g. 0.02 = 2%.',
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO purchase_rebate_tax_settings (vat_rate, precompte_rate)
SELECT 0.1925, 0.0200 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM purchase_rebate_tax_settings);

-- -----------------------------------------------------------------------------
-- 2. supplier_category_rebates — which (supplier, category) pairs have a
--    ladder at all. General capability, not SDP-specific.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_category_rebates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT NOT NULL,
    category_id  INT NOT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_category (supplier_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'supplier_category_rebates'
       AND constraint_name = 'fk_scr_supplier'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE supplier_category_rebates ADD CONSTRAINT fk_scr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE',
    'SELECT ''fk_scr_supplier exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'supplier_category_rebates'
       AND constraint_name = 'fk_scr_category'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE supplier_category_rebates ADD CONSTRAINT fk_scr_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE',
    'SELECT ''fk_scr_category exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3. supplier_category_rebate_tiers — the ladder bands.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_category_rebate_tiers (
    id                          INT AUTO_INCREMENT PRIMARY KEY,
    supplier_category_rebate_id INT NOT NULL,
    tier_order                  INT NOT NULL DEFAULT 1,
    threshold_from              DECIMAL(14,2) NULL COMMENT 'Exclusive lower bound. NULL = starts at 0.',
    threshold_to                DECIMAL(14,2) NULL COMMENT 'Inclusive upper bound. NULL = open-ended (this is the top tier).',
    rate                        DECIMAL(6,4) NOT NULL COMMENT 'Fraction, e.g. 0.03 = 3%.',
    created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'supplier_category_rebate_tiers'
       AND constraint_name = 'fk_scrt_parent'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE supplier_category_rebate_tiers
       ADD CONSTRAINT fk_scrt_parent FOREIGN KEY (supplier_category_rebate_id)
       REFERENCES supplier_category_rebates(id) ON DELETE CASCADE',
    'SELECT ''fk_scrt_parent exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. purchase_order_category_rebates — frozen per (PO, category) at order
--    creation; rebate_amount_earned accrues as receptions happen.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS purchase_order_category_rebates (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id      INT NOT NULL,
    category_id            INT NOT NULL,
    category_amount_ttc    DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Whole-PO subtotal for this category, as entered (TTC).',
    category_amount_ht     DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'TTC backed out by vat_rate_applied.',
    tier_rate              DECIMAL(6,4) NOT NULL DEFAULT 0 COMMENT 'Ladder rate matched at order creation, frozen.',
    vat_rate_applied       DECIMAL(6,4) NOT NULL DEFAULT 0,
    precompte_rate_applied DECIMAL(6,4) NOT NULL DEFAULT 0,
    rebate_amount_earned   DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT 'Net rebate actually accrued so far across receptions.',
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_po_category (purchase_order_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'purchase_order_category_rebates'
       AND constraint_name = 'fk_pocr_po'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_category_rebates
       ADD CONSTRAINT fk_pocr_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE',
    'SELECT ''fk_pocr_po exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE() AND table_name = 'purchase_order_category_rebates'
       AND constraint_name = 'fk_pocr_category'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_order_category_rebates
       ADD CONSTRAINT fk_pocr_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE',
    'SELECT ''fk_pocr_category exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5. Seed Source du Pays's ladder, best-effort (name match). If the supplier
--    or the eau/jus categories don't exist on this install, these simply
--    insert nothing — safe no-op, nothing to configure by hand later from
--    the Ristournes config page.
-- -----------------------------------------------------------------------------
INSERT INTO supplier_category_rebates (supplier_id, category_id, is_active)
SELECT s.id, pc.id, 1
  FROM suppliers s
  JOIN product_categories pc ON pc.code = 'eau'
 WHERE (s.name LIKE '%Source du Pays%' OR s.name LIKE '%SDP%')
   AND NOT EXISTS (
        SELECT 1 FROM supplier_category_rebates x
         WHERE x.supplier_id = s.id AND x.category_id = pc.id
   );

INSERT INTO supplier_category_rebate_tiers (supplier_category_rebate_id, tier_order, threshold_from, threshold_to, rate)
SELECT scr.id, 1, NULL, NULL, 0.03
  FROM supplier_category_rebates scr
  JOIN suppliers s ON s.id = scr.supplier_id
  JOIN product_categories pc ON pc.id = scr.category_id AND pc.code = 'eau'
 WHERE (s.name LIKE '%Source du Pays%' OR s.name LIKE '%SDP%')
   AND NOT EXISTS (SELECT 1 FROM supplier_category_rebate_tiers t WHERE t.supplier_category_rebate_id = scr.id);

INSERT INTO supplier_category_rebates (supplier_id, category_id, is_active)
SELECT s.id, pc.id, 1
  FROM suppliers s
  JOIN product_categories pc ON pc.code = 'jus'
 WHERE (s.name LIKE '%Source du Pays%' OR s.name LIKE '%SDP%')
   AND NOT EXISTS (
        SELECT 1 FROM supplier_category_rebates x
         WHERE x.supplier_id = s.id AND x.category_id = pc.id
   );

INSERT INTO supplier_category_rebate_tiers (supplier_category_rebate_id, tier_order, threshold_from, threshold_to, rate)
SELECT scr.id, 1, NULL, 5000000, 0.03
  FROM supplier_category_rebates scr
  JOIN suppliers s ON s.id = scr.supplier_id
  JOIN product_categories pc ON pc.id = scr.category_id AND pc.code = 'jus'
 WHERE (s.name LIKE '%Source du Pays%' OR s.name LIKE '%SDP%')
   AND NOT EXISTS (SELECT 1 FROM supplier_category_rebate_tiers t WHERE t.supplier_category_rebate_id = scr.id AND t.tier_order = 1);

INSERT INTO supplier_category_rebate_tiers (supplier_category_rebate_id, tier_order, threshold_from, threshold_to, rate)
SELECT scr.id, 2, 5000000, 10000000, 0.04
  FROM supplier_category_rebates scr
  JOIN suppliers s ON s.id = scr.supplier_id
  JOIN product_categories pc ON pc.id = scr.category_id AND pc.code = 'jus'
 WHERE (s.name LIKE '%Source du Pays%' OR s.name LIKE '%SDP%')
   AND NOT EXISTS (SELECT 1 FROM supplier_category_rebate_tiers t WHERE t.supplier_category_rebate_id = scr.id AND t.tier_order = 2);

INSERT INTO supplier_category_rebate_tiers (supplier_category_rebate_id, tier_order, threshold_from, threshold_to, rate)
SELECT scr.id, 3, 10000000, NULL, 0.05
  FROM supplier_category_rebates scr
  JOIN suppliers s ON s.id = scr.supplier_id
  JOIN product_categories pc ON pc.id = scr.category_id AND pc.code = 'jus'
 WHERE (s.name LIKE '%Source du Pays%' OR s.name LIKE '%SDP%')
   AND NOT EXISTS (SELECT 1 FROM supplier_category_rebate_tiers t WHERE t.supplier_category_rebate_id = scr.id AND t.tier_order = 3);

COMMIT;
