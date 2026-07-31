-- =============================================================================
-- 051_delivery_places_and_product_rebates.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — delivery places on purchase orders + per-supplier/per-product
-- ristourne configuration.
--
-- PART A — DELIVERY PLACES
-- ------------------------
-- Every purchase order can now say where the goods are to be delivered
-- (Zone Industrielle, Bassa, a partner's own warehouse like "Prometal
-- Warehouse", etc). This is purely informational / logistics metadata on the
-- PO — it does NOT create separate warehouses in the stock module. Reception,
-- CUMP and inventory_movements all keep treating stock as one single pool, as
-- they always have. delivery_places is a small managed list (not free text)
-- so the same place is never spelled two different ways on two POs.
--
-- Seeded with the two places already in use: "Zone Industrielle, Bassa" and
-- "Compagnie Ndogbong (Entrepot 1)". More can be added from the UI.
--
-- PART B — PER-SUPPLIER / PER-PRODUCT RISTOURNE RATES
-- ----------------------------------------------------
-- suppliers.rebate_rate (migration 038) is a single blanket rate: pick that
-- supplier on any line, for any product, at any time, and the whole receipt
-- value earns the rebate. That is no longer how ristourne is meant to work —
-- it is negotiated per supplier AND per product. supplier_product_rebates
-- carries one row per (supplier, product) pair with the rate that applies
-- only when that exact combination is ordered. No row = no rebate for that
-- pair, full stop; there is deliberately no supplier-wide fallback.
--
-- suppliers.rebate_rate and the old "Source du Pays / SDP" name-matching
-- fallback in includes/functions/procurement.php are retired by the
-- accompanying code change. The column is left in place (dropping a column
-- that other reports may reference is a separate, deliberate decision) but is
-- no longer read by the rebate calculation.
--
-- IDEMPOTENT. Every step is guarded on information_schema or uses
-- INSERT … ON DUPLICATE KEY UPDATE / INSERT … WHERE NOT EXISTS. Safe to re-run.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. delivery_places
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_places (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_places_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO delivery_places (name) VALUES
    ('Zone Industrielle, Bassa'),
    ('Compagnie Ndogbong (Entrepot 1)')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- 2. purchase_orders.delivery_place_id — nullable so existing/older orders
--    (and any client that hasn't deployed the new form yet) keep working.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'purchase_orders'
       AND column_name  = 'delivery_place_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_orders ADD COLUMN delivery_place_id INT NULL AFTER supplier_id',
    'SELECT ''purchase_orders.delivery_place_id exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- FK, added only once the column exists and only once (name collision guard).
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE()
       AND table_name   = 'purchase_orders'
       AND constraint_name = 'fk_po_delivery_place'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE purchase_orders
       ADD CONSTRAINT fk_po_delivery_place
       FOREIGN KEY (delivery_place_id) REFERENCES delivery_places(id)
       ON DELETE SET NULL',
    'SELECT ''fk_po_delivery_place exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 2. supplier_product_rebates — per (supplier, product) ristourne rate.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS supplier_product_rebates (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id  INT NOT NULL,
    product_id   INT NOT NULL,
    rebate_rate  DECIMAL(6,4) NOT NULL COMMENT 'Fraction, e.g. 0.0247 = 2.47%',
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    notes        VARCHAR(255) NULL,
    created_by   INT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_supplier_product (supplier_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE()
       AND table_name   = 'supplier_product_rebates'
       AND constraint_name = 'fk_spr_supplier'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE supplier_product_rebates
       ADD CONSTRAINT fk_spr_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE',
    'SELECT ''fk_spr_supplier exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
     WHERE table_schema = DATABASE()
       AND table_name   = 'supplier_product_rebates'
       AND constraint_name = 'fk_spr_product'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE supplier_product_rebates
       ADD CONSTRAINT fk_spr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE',
    'SELECT ''fk_spr_product exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. purchase_order_items.rebate_rate / rebate_amount — the rate and amount
--    actually applied to that line at reception time, frozen on the line so
--    a later change to supplier_product_rebates cannot retroactively change
--    what an already-received order is shown to have earned. This is what
--    the PO detail page reads to show "ristourne gagnée sur cette commande".
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'purchase_order_items'
       AND column_name  = 'rebate_rate'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_order_items ADD COLUMN rebate_rate DECIMAL(6,4) NULL',
    'SELECT ''purchase_order_items.rebate_rate exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'purchase_order_items'
       AND column_name  = 'rebate_amount_earned'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_order_items ADD COLUMN rebate_amount_earned DECIMAL(12,2) NOT NULL DEFAULT 0',
    'SELECT ''purchase_order_items.rebate_amount_earned exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

COMMIT;
