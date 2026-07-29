-- =============================================================================
-- 041_product_master_v2.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 9 · Product master data, v2.
--
-- WHY:
--   Creating a product today is almost entirely manual and silently lossy.
--   Five concrete defects this migration removes the substrate for:
--
--   1. NO ACCOUNTING LINK. `products` has no account columns whatsoever, so
--      every sale credits a blanket 701 (JournalPoster::postInvoiceIssued
--      defaults $revenue_ohada='701'). Water, juice, consigne and equipment
--      all land in one revenue line — margin by category is unobtainable
--      from the ledger. Suppliers already get a 401xxx sub-account minted on
--      creation (mdm_controller.php); products were simply never given the
--      same treatment.
--
--   2. NO COGS / STOCK POSTING. Goods leave the warehouse in
--      sales_controller::generate_dispatch (inventory_movements 'out_delivery')
--      and products.cump is maintained on every reception — but nothing is
--      ever posted to the ledger. Stock value on the balance sheet only ever
--      grows. This migration seeds the 31x / 6031 accounts so dispatch can
--      post Dr 6031 / Cr 31x at CUMP.
--
--   3. CATEGORY IS AN ENUM, HARDCODED IN THREE PLACES. The DB enum, a JS
--      array in admin-master_data.js, and a PHP whitelist in mdm_controller
--      whose failsafe rewrites anything unrecognised to 'Eau'. That failsafe
--      is why product Wat-202-2 "Jus 30L" is currently tagged EAU. Adding a
--      category required a developer; now it is a row.
--
--   4. NO PACK CONCEPT. Drinks arrive in packs of 6 / 12 and there is nowhere
--      to record it. `format` is free text and already distrusted in-code —
--      see the comments in budget_controller.php:412 and
--      sales_dashboard_controller.php:58, both of which fall back to
--      `code LIKE '%-20L-%'` because "products.format is NOT reliable".
--
--   5. EMPTIES METADATA UNCOLLECTED. cre_controller resolves empties by
--      (is_empty, bottle_size, has_cork) — columns added in 013/014 — but the
--      master-data form never captured them, so every Emballage created
--      through the UI since then is invisible to the empties ledger.
--
-- WHAT THIS DOES:
--   1. Creates `product_categories` (name, SKU prefix, revenue/stock/COGS
--      account mapping, behaviour flags) and seeds Eau, Jus, Emballage,
--      Equipement.
--   2. Seeds the OHADA + chart_of_accounts rows those categories point at.
--   3. Converts products.category ENUM -> VARCHAR(50) so categories added
--      from the UI never need another migration.
--   4. Adds to products: category_id, unit_of_measure, units_per_pack,
--      sold_by, revenue_account_id, stock_account_id, cogs_account_id.
--   5. Backfills category_id, pack defaults, and (guarded) reclassifies the
--      one mis-tagged Jus row.
--
-- SAFE TO RE-RUN. Every statement is guarded by an information_schema check
-- or NOT EXISTS. No data is deleted.
--
-- ROLLBACK: see the commented block at the bottom of this file.
--
-- NOTE ON ATOMICITY: MySQL/MariaDB implicitly commits on DDL, so the
-- START TRANSACTION below covers the DML but NOT the ALTERs and CREATEs.
-- That matches migrations 013 and 014. Every statement is individually
-- guarded and re-runnable, so a partial apply is repaired by simply running
-- the file again — but take a dump first, as always.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 0. Guarded DDL helpers (same pattern as migrations 013 / 014).
-- -----------------------------------------------------------------------------
DELIMITER //

DROP PROCEDURE IF EXISTS lpc_add_col_41 //
CREATE PROCEDURE lpc_add_col_41(IN p_table VARCHAR(64), IN p_col VARCHAR(64), IN p_ddl TEXT)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.columns
      WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_col;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN ', p_ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

DROP PROCEDURE IF EXISTS lpc_add_idx_41 //
CREATE PROCEDURE lpc_add_idx_41(IN p_table VARCHAR(64), IN p_idx VARCHAR(64), IN p_ddl TEXT)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.statistics
      WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_idx;
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

-- Foreign keys need their OWN guard, checked against table_constraints rather
-- than statistics. When a suitable index already exists on the column, MySQL
-- reuses it instead of creating one named after the constraint — so an
-- index_name lookup would report "absent" on a re-run and the ALTER would
-- fail with errno 121 (duplicate key name).
DROP PROCEDURE IF EXISTS lpc_add_fk_41 //
CREATE PROCEDURE lpc_add_fk_41(IN p_table VARCHAR(64), IN p_fk VARCHAR(64), IN p_ddl TEXT)
BEGIN
    DECLARE v_exists INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exists FROM information_schema.table_constraints
      WHERE table_schema    = DATABASE()
        AND table_name      = p_table
        AND constraint_name = p_fk
        AND constraint_type = 'FOREIGN KEY';
    IF v_exists = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD ', p_ddl);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END//

DELIMITER ;


-- =============================================================================
-- 1. OHADA + chart_of_accounts rows the categories will point at.
-- -----------------------------------------------------------------------------
-- Convention follows migration 038: seed ohada_accounts first, then put a
-- chart_of_accounts row in front of each one. JournalPoster::coaByOhada joins
-- the two, so an ohada_accounts row with no COA row in front of it is unusable.
--
-- Revenue (SYSCOHADA révisé, class 7):
--   7011  Ventes — Eau                     (sub of 701 Ventes)
--   7012  Ventes — Jus & boissons
--   7074  Bonis sur reprises et cessions d'emballages   → consigne income
--   7078  Autres produits accessoires      → equipment / one-off sales
--
-- Stock (class 3, inventaire permanent):
--   311   Stocks — Eau
--   312   Stocks — Jus & boissons
--   313   Stocks — Emballages
--   314   Stocks — Equipement
--
-- Cost of goods sold (class 6):
--   6031  Variations des stocks de marchandises
--
-- 6031 is deliberately shared by all four categories: OHADA expects the
-- variation account to mirror the stock class, and splitting it per category
-- buys nothing that a stock-account breakdown doesn't already give. A category
-- can still override it — the column exists.
-- =============================================================================

INSERT INTO ohada_accounts (account_number, name, type) VALUES
  ('7011', 'Ventes - Eau',                                   'revenue'),
  ('7012', 'Ventes - Jus et boissons',                       'revenue'),
  ('7074', 'Bonis sur reprises et cessions d''emballages',   'revenue'),
  ('7078', 'Autres produits accessoires',                    'revenue'),
  ('311',  'Stocks - Eau',                                   'asset'),
  ('312',  'Stocks - Jus et boissons',                       'asset'),
  ('313',  'Stocks - Emballages',                            'asset'),
  ('314',  'Stocks - Equipement',                            'asset'),
  ('6031', 'Variations des stocks de marchandises',          'expense')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type);

-- One chart_of_accounts row per OHADA row above. NOT EXISTS on the code rather
-- than INSERT IGNORE, because we cannot assume every install carries a UNIQUE
-- index on chart_of_accounts.code (same caveat as migration 038).
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, o.account_number, o.name, o.type, 1
  FROM ohada_accounts o
 WHERE o.account_number IN
       ('7011','7012','7074','7078','311','312','313','314','6031')
   AND NOT EXISTS (
        SELECT 1 FROM chart_of_accounts c WHERE c.code = o.account_number
   );


-- =============================================================================
-- 2. product_categories
-- -----------------------------------------------------------------------------
-- `code`        stable machine key, never shown to users, safe for code paths.
-- `name`        what the dropdown shows, and what lands in products.category
--               (kept denormalised so the ~15 existing `p.category = 'Eau'`
--               style queries across the app keep working untouched).
-- `sku_prefix`  drives the auto-generated product code: WAT-20L-004.
-- `*_account_id` resolved chart_of_accounts ids — the app reads these, the
--               ohada columns exist so a fresh install can re-resolve them.
--
-- Behaviour flags, all consumed by the master-data form:
--   is_empty_container  rows in this category ARE returnable empties. Turning
--                       it on reveals bottle_size / has_cork and forces
--                       products.is_empty = 1, which is what cre_controller
--                       and the empties ledger key off.
--   requires_emballage  a sale of this product should normally carry a linked
--                       empty. ADVISORY ONLY — never enforced, because plenty
--                       of products genuinely ship without one. This is the
--                       flag that replaces the stray `required` attribute the
--                       form was putting on the emballage dropdown.
--   allows_packs        show unit_of_measure / units_per_pack for this
--                       category (juice yes, bonbonnes no).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `product_categories` (
  `id`                 int(11)      NOT NULL AUTO_INCREMENT,
  `code`               varchar(32)  NOT NULL COMMENT 'Stable machine key: eau, jus, emballage...',
  `name`               varchar(64)  NOT NULL COMMENT 'Display label; mirrored into products.category',
  `sku_prefix`         varchar(8)   NOT NULL COMMENT 'Drives auto SKU: WAT-20L-004',
  `revenue_ohada`      varchar(10)      DEFAULT NULL,
  `stock_ohada`        varchar(10)      DEFAULT NULL,
  `cogs_ohada`         varchar(10)      DEFAULT NULL,
  `revenue_account_id` int(11)          DEFAULT NULL,
  `stock_account_id`   int(11)          DEFAULT NULL,
  `cogs_account_id`    int(11)          DEFAULT NULL,
  `is_empty_container` tinyint(1)   NOT NULL DEFAULT 0 COMMENT 'Products here are returnable empties',
  `requires_emballage` tinyint(1)   NOT NULL DEFAULT 0 COMMENT 'Advisory hint only, never enforced',
  `allows_packs`       tinyint(1)   NOT NULL DEFAULT 1 COMMENT 'Show pack fields in the form',
  `sort_order`         int(11)      NOT NULL DEFAULT 100,
  `is_active`          tinyint(1)   NOT NULL DEFAULT 1,
  `created_at`         timestamp    NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prodcat_code`   (`code`),
  UNIQUE KEY `uq_prodcat_name`   (`name`),
  UNIQUE KEY `uq_prodcat_prefix` (`sku_prefix`),
  KEY `idx_prodcat_active` (`is_active`, `sort_order`),
  CONSTRAINT `fk_prodcat_revenue` FOREIGN KEY (`revenue_account_id`) REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prodcat_stock`   FOREIGN KEY (`stock_account_id`)   REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prodcat_cogs`    FOREIGN KEY (`cogs_account_id`)    REFERENCES `chart_of_accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the four categories. `name` must match the existing ENUM labels exactly
-- for Eau / Emballage / Equipement, otherwise the backfill in §4 misses rows.
INSERT INTO `product_categories`
    (`code`, `name`, `sku_prefix`, `revenue_ohada`, `stock_ohada`, `cogs_ohada`,
     `is_empty_container`, `requires_emballage`, `allows_packs`, `sort_order`)
SELECT 'eau', 'Eau', 'WAT', '7011', '311', '6031', 0, 1, 1, 10 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `product_categories` WHERE code = 'eau');

INSERT INTO `product_categories`
    (`code`, `name`, `sku_prefix`, `revenue_ohada`, `stock_ohada`, `cogs_ohada`,
     `is_empty_container`, `requires_emballage`, `allows_packs`, `sort_order`)
SELECT 'jus', 'Jus', 'JUS', '7012', '312', '6031', 0, 1, 1, 20 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `product_categories` WHERE code = 'jus');

INSERT INTO `product_categories`
    (`code`, `name`, `sku_prefix`, `revenue_ohada`, `stock_ohada`, `cogs_ohada`,
     `is_empty_container`, `requires_emballage`, `allows_packs`, `sort_order`)
SELECT 'emballage', 'Emballage', 'VIDE', '7074', '313', '6031', 1, 0, 0, 30 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `product_categories` WHERE code = 'emballage');

INSERT INTO `product_categories`
    (`code`, `name`, `sku_prefix`, `revenue_ohada`, `stock_ohada`, `cogs_ohada`,
     `is_empty_container`, `requires_emballage`, `allows_packs`, `sort_order`)
SELECT 'equipement', 'Equipement', 'EQP', '7078', '314', '6031', 0, 0, 1, 40 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `product_categories` WHERE code = 'equipement');

-- Resolve the OHADA strings to chart_of_accounts ids. Re-runnable: it simply
-- re-resolves, which is also how you repair a category after someone renames
-- an account.
UPDATE `product_categories` pc
  JOIN `chart_of_accounts` c ON c.code = pc.revenue_ohada
   SET pc.revenue_account_id = c.id
 WHERE pc.revenue_ohada IS NOT NULL;

UPDATE `product_categories` pc
  JOIN `chart_of_accounts` c ON c.code = pc.stock_ohada
   SET pc.stock_account_id = c.id
 WHERE pc.stock_ohada IS NOT NULL;

UPDATE `product_categories` pc
  JOIN `chart_of_accounts` c ON c.code = pc.cogs_ohada
   SET pc.cogs_account_id = c.id
 WHERE pc.cogs_ohada IS NOT NULL;


-- =============================================================================
-- 3. products — new columns.
-- =============================================================================

-- 3a. category ENUM -> VARCHAR(50).
--     This is the change that makes "add a category inline" possible at all.
--     Every existing query against this column (`p.category = 'Emballage'`,
--     `category = 'Eau'`, GROUP BY category, the Paginator search list) behaves
--     identically against a VARCHAR. Guarded on DATA_TYPE so a re-run is a
--     no-op.
SET @is_enum = (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'products'
       AND column_name  = 'category'
       AND DATA_TYPE    = 'enum'
);
SET @sql = IF(@is_enum > 0,
    "ALTER TABLE `products` MODIFY COLUMN `category` VARCHAR(50) NOT NULL DEFAULT 'Eau' COMMENT 'Denormalised from product_categories.name'",
    "SELECT 'products.category already VARCHAR' AS info"
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3b. Relational category.
CALL lpc_add_col_41('products', 'category_id',
    "category_id INT(11) NULL COMMENT 'FK product_categories.id' AFTER category");

-- 3c. Packaging / unit of measure.
--     units_per_pack = 1 means "sold as a single unit" — the default, and true
--     for every bonbonne. sold_by tells the sales UI which number the price on
--     this row refers to.
CALL lpc_add_col_41('products', 'unit_of_measure',
    "unit_of_measure VARCHAR(24) NOT NULL DEFAULT 'unite' COMMENT 'unite|bouteille|bonbonne|carton|pack|sachet' AFTER format");
CALL lpc_add_col_41('products', 'units_per_pack',
    "units_per_pack INT(11) NOT NULL DEFAULT 1 COMMENT 'Bottles per pack: 6, 12, 24. 1 = sold loose' AFTER unit_of_measure");
CALL lpc_add_col_41('products', 'sold_by',
    "sold_by ENUM('unit','pack') NOT NULL DEFAULT 'unit' COMMENT 'What base_price refers to' AFTER units_per_pack");

-- 3d. Per-product account overrides. NULL = inherit from the category, which
--     is the case we expect for ~every product. The column exists for the
--     exception (a promotional SKU booked to a different revenue line).
CALL lpc_add_col_41('products', 'revenue_account_id',
    "revenue_account_id INT(11) NULL COMMENT 'Override; NULL inherits product_categories.revenue_account_id' AFTER base_price");
CALL lpc_add_col_41('products', 'stock_account_id',
    "stock_account_id INT(11) NULL COMMENT 'Override; NULL inherits category' AFTER revenue_account_id");
CALL lpc_add_col_41('products', 'cogs_account_id',
    "cogs_account_id INT(11) NULL COMMENT 'Override; NULL inherits category' AFTER stock_account_id");

-- 3e. Indexes + FKs.
CALL lpc_add_idx_41('products', 'idx_products_category_id',
    'KEY idx_products_category_id (category_id)');
CALL lpc_add_fk_41('products', 'fk_products_category',
    'CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES product_categories (id) ON DELETE SET NULL');
CALL lpc_add_fk_41('products', 'fk_products_revenue_acc',
    'CONSTRAINT fk_products_revenue_acc FOREIGN KEY (revenue_account_id) REFERENCES chart_of_accounts (id) ON DELETE SET NULL');
CALL lpc_add_fk_41('products', 'fk_products_stock_acc',
    'CONSTRAINT fk_products_stock_acc FOREIGN KEY (stock_account_id) REFERENCES chart_of_accounts (id) ON DELETE SET NULL');
CALL lpc_add_fk_41('products', 'fk_products_cogs_acc',
    'CONSTRAINT fk_products_cogs_acc FOREIGN KEY (cogs_account_id) REFERENCES chart_of_accounts (id) ON DELETE SET NULL');

-- 3f. Empties are grouped in the UI by bottle_size, so index it.
CALL lpc_add_idx_41('products', 'idx_products_empty_size',
    'KEY idx_products_empty_size (is_empty, bottle_size, has_cork)');


-- =============================================================================
-- 4. Backfills.
-- =============================================================================

-- 4a. category_id from the existing text label.
UPDATE `products` p
  JOIN `product_categories` pc ON pc.name = p.category
   SET p.category_id = pc.id
 WHERE p.category_id IS NULL;

-- 4b. Sanity net: anything still unmatched (a stray label, a NULL) goes to Eau
--     so the FK is never left dangling and the UI never shows a blank chip.
UPDATE `products` p
   SET p.category_id = (SELECT id FROM `product_categories` WHERE code = 'eau')
 WHERE p.category_id IS NULL;

-- 4c. Re-sync the text label from the relation, so the two can never disagree
--     after this point. The application writes both on every save.
UPDATE `products` p
  JOIN `product_categories` pc ON pc.id = p.category_id
   SET p.category = pc.name
 WHERE p.category <> pc.name;

-- 4d. Emballage rows are returnable empties by definition — enforce it once so
--     the CRE lookups (is_empty = 1 AND bottle_size = ? AND has_cork = ?)
--     see every container. Migration 014 did this for the rows that existed
--     then; anything created through the UI since has is_empty = 0.
UPDATE `products` p
  JOIN `product_categories` pc ON pc.id = p.category_id
   SET p.is_empty = 1
 WHERE pc.is_empty_container = 1
   AND p.is_empty = 0;

-- 4e. Pack defaults. Existing rows are all sold loose — a bonbonne is one
--     bonbonne. New juice SKUs will carry their real pack size.
UPDATE `products`
   SET units_per_pack = 1
 WHERE units_per_pack IS NULL OR units_per_pack < 1;

UPDATE `products` p
  JOIN `product_categories` pc ON pc.id = p.category_id
   SET p.unit_of_measure = CASE
        WHEN pc.code = 'emballage'  THEN 'bonbonne'
        WHEN pc.code = 'equipement' THEN 'unite'
        WHEN p.bottle_size IN ('20L','10L')  THEN 'bonbonne'
        WHEN p.bottle_size IS NOT NULL       THEN 'bouteille'
        ELSE 'unite'
   END
 WHERE p.unit_of_measure = 'unite';

-- 4f. The one product the ENUM failsafe mis-filed. mdm_controller's
--     `if (!in_array($safe_category, $allowed)) $safe_category = 'Eau';`
--     silently rewrote "Jus" to "Eau" on save, with no error shown to the
--     user. Guarded to that single row by code AND name so it cannot touch
--     anything else; drop this block if you would rather reclassify by hand.
UPDATE `products` p
   SET p.category_id = (SELECT id FROM `product_categories` WHERE code = 'jus'),
       p.category    = 'Jus'
 WHERE UPPER(p.code) = 'WAT-202-2'
   AND LOWER(p.name) LIKE '%jus%'
   AND p.category = 'Eau';


-- -----------------------------------------------------------------------------
-- 5. Cleanup.
-- -----------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS lpc_add_col_41;
DROP PROCEDURE IF EXISTS lpc_add_idx_41;
DROP PROCEDURE IF EXISTS lpc_add_fk_41;

-- schema_migrations is written by the runner (it owns `version`, runtime_ms
-- and checksum), not by the migration — same as 038 / 039 / 040.

COMMIT;


-- =============================================================================
-- VERIFICATION
-- -----------------------------------------------------------------------------
--   -- Categories resolve to real accounts (no NULLs in the last 3 columns):
--   SELECT pc.name, pc.sku_prefix,
--          r.code AS revenue, s.code AS stock, g.code AS cogs
--     FROM product_categories pc
--     LEFT JOIN chart_of_accounts r ON r.id = pc.revenue_account_id
--     LEFT JOIN chart_of_accounts s ON s.id = pc.stock_account_id
--     LEFT JOIN chart_of_accounts g ON g.id = pc.cogs_account_id
--    ORDER BY pc.sort_order;
--
--   -- Every product has a category and the label agrees with the relation:
--   SELECT COUNT(*) AS orphans FROM products p
--     LEFT JOIN product_categories pc ON pc.id = p.category_id
--    WHERE pc.id IS NULL OR pc.name <> p.category;      -- expect 0
--
--   -- Every empty is visible to the CRE lookup:
--   SELECT id, code, name, is_empty, bottle_size, has_cork, base_price
--     FROM products WHERE category = 'Emballage' ORDER BY bottle_size, has_cork;
--   -- expect is_empty = 1 everywhere and no NULL bottle_size
--
--   -- The Jus row landed:
--   SELECT id, code, name, category FROM products WHERE code = 'Wat-202-2';
--
-- ROLLBACK (destructive — take a dump first)
-- -----------------------------------------------------------------------------
--   ALTER TABLE products
--     DROP FOREIGN KEY fk_products_category,
--     DROP FOREIGN KEY fk_products_revenue_acc,
--     DROP FOREIGN KEY fk_products_stock_acc,
--     DROP FOREIGN KEY fk_products_cogs_acc;
--   ALTER TABLE products
--     DROP COLUMN category_id, DROP COLUMN unit_of_measure,
--     DROP COLUMN units_per_pack, DROP COLUMN sold_by,
--     DROP COLUMN revenue_account_id, DROP COLUMN stock_account_id,
--     DROP COLUMN cogs_account_id;
--   ALTER TABLE products MODIFY COLUMN category
--     ENUM('Eau','Emballage','Equipement') NOT NULL DEFAULT 'Eau';
--   DROP TABLE product_categories;
--   -- The chart_of_accounts / ohada_accounts rows are left in place on
--   -- purpose: journal lines may already reference them.
--   DELETE FROM schema_migrations WHERE version LIKE '041%';
-- =============================================================================
