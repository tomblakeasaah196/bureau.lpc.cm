-- =============================================================================
-- 063_supplier_pricing_history.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — give suppliers a tariff, and record a change to it as a
-- price change.
--
-- THE SAME RULE, THE OTHER DIRECTION
-- ----------------------------------
-- 062 established it for the sell side: a price change is a price change, never
-- a discount, and the software never infers one from the other. This migration
-- applies it to the buy side.
--
-- It matters just as much here, arguably more. A supplier raising their price
-- is the single most ordinary event in procurement. If the system subtracted
-- the old price from the new and filed the difference under "remise", a price
-- INCREASE would be recorded as a negative discount — a number with no meaning
-- that would then pollute both the Remises Obtenues KPI and, by association,
-- the ristourne account sitting next to it on the same page. Nobody would
-- trust any of the three.
--
-- WHY THIS IS BIGGER THAN THE SELL-SIDE VERSION
-- ---------------------------------------------
-- Clients already had a tariff: `client_prices` has existed since the schema
-- was built, so 062 only had to add history to something that was already
-- there.
--
-- Suppliers have nothing. A purchase-order line price is typed free-hand,
-- validated against nothing, and compared to nothing. The picker offers
-- products.cump (weighted average cost) as a starting figure — which is what we
-- have PAID on average, not what the supplier CHARGES, and the two are only
-- coincidentally equal. There is no answer today to "what does Prometal charge
-- us for a 12-crate?" other than opening the last purchase order and reading it.
--
-- So this migration builds the tariff itself before it can mirror anything:
--
--   1. supplier_prices          — the standing price per (supplier, product).
--   2. supplier_price_history   — append-only log of every change to it.
--   3. purchase_order_items     — list_price + discount_amount, exactly as
--                                 062 added them to sales_order_items.
--
-- SEEDING, AND WHY IT IS NOT AUTOMATIC
-- ------------------------------------
-- The obvious move is to seed supplier_prices from the most recent
-- purchase_order_items row per (supplier, product). This migration does NOT do
-- that, and the reason is the same one that runs through 062: a seeded tariff
-- is an assertion nobody made.
--
-- The last price paid may have been a one-off negotiation, a rush order, or a
-- typo — there is no way to tell from the data, because until now the system
-- never asked. Seeding would turn every one of those into "the supplier's
-- standing price", and the first purchase order raised afterwards would then
-- open the reconciliation modal against a fabricated baseline. Ask a question
-- built on an invented premise and the buyer learns to tick "confirm all"
-- without reading it, which destroys the control.
--
-- Instead the tariff starts EMPTY and fills itself honestly: the first PO for a
-- (supplier, product) pair has no tariff to differ from, so it raises no
-- question and its price is recorded as the tariff. From the second onwards the
-- comparison is against something a human actually put there. The commented
-- seeding query at the foot of this file is provided for the case where someone
-- decides, deliberately and with the caveats understood, to pre-fill it.
--
-- Depends on: 062_sales_module_parity.sql (establishes the rule and the
--             lpc_* pricing helpers this mirrors), 001_rbac_seed.sql.
-- Idempotent: guarded on information_schema / CREATE TABLE IF NOT EXISTS /
-- INSERT IGNORE. Safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 1. supplier_prices — the standing tariff
-- -----------------------------------------------------------------------------
-- Deliberately shaped like client_prices: a composite-key pivot with no
-- surrogate id, one row per (supplier, product). Same shape, same mental model,
-- and the two halves of the pricing code stay symmetrical.
--
-- Both FKs CASCADE. Unlike the history table below, this row carries no audit
-- value on its own — it is the current value, and a current value for a deleted
-- supplier is meaningless. The history is what must survive, and it does.
CREATE TABLE IF NOT EXISTS supplier_prices (
    supplier_id  INT NOT NULL,
    product_id   INT NOT NULL,
    custom_price DECIMAL(10,2) NOT NULL COMMENT 'What this supplier charges for this product.',
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (supplier_id, product_id),
    KEY idx_sp_product (product_id),
    CONSTRAINT fk_sp_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sp_product  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Standing supplier tariff. Mirrors client_prices. See migration 063.';

-- -----------------------------------------------------------------------------
-- 2. supplier_price_history — how it got there
-- -----------------------------------------------------------------------------
-- Mirrors client_price_history from 062 field for field, including what it
-- deliberately omits: there is no delta column and no total_impact column. The
-- new price REPLACES the old one, so the gap between them is the difference
-- between two prices that were each correct on their own date. That is not an
-- impact and must never be summed.
--
-- supplier CASCADEs (matching client_price_history, and required if a supplier
-- is ever purged); product and user RESTRICT, so the log outlives the things it
-- refers to. Neither is hard-deleted anywhere in the codebase today.
CREATE TABLE IF NOT EXISTS supplier_price_history (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id      INT NOT NULL,
    product_id       INT NOT NULL,
    old_price        DECIMAL(10,2) NULL
                     COMMENT 'Previous supplier_prices.custom_price. NULL = no tariff recorded before now.',
    new_price        DECIMAL(10,2) NOT NULL,
    source           ENUM('purchase_order','supplier_file','import') NOT NULL DEFAULT 'purchase_order',
    source_reference VARCHAR(50) NULL
                     COMMENT 'Document that caused it, e.g. BC-2608-1A2B. No FK — the PO may be cancelled; the price change still happened.',
    note             VARCHAR(255) NULL,
    changed_by       INT NOT NULL COMMENT 'users.id — who repriced.',
    changed_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address       VARCHAR(45) NULL,
    KEY idx_sph_supplier_product (supplier_id, product_id, changed_at),
    KEY idx_sph_changed_at (changed_at),
    CONSTRAINT fk_sph_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
    CONSTRAINT fk_sph_product  FOREIGN KEY (product_id)  REFERENCES products(id)  ON DELETE RESTRICT,
    CONSTRAINT fk_sph_user     FOREIGN KEY (changed_by)  REFERENCES users(id)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Append-only log of supplier price changes. A price change is a price change, never a remise or a ristourne.';

-- -----------------------------------------------------------------------------
-- 3. purchase_order_items — the tariff shown, and the remise declared
-- -----------------------------------------------------------------------------
-- Identical treatment to sales_order_items in 062.
--
-- On a REPRICED line (buyer ticked the box) list_price and unit_price are both
-- the new price and discount_amount is 0 — the tariff moved, nothing was
-- discounted. On a DECLARED-REMISE line, list_price is the old tariff,
-- unit_price is what was paid, and discount_amount is the difference × quantity.
--
-- discount_amount is stored, not derived. Deriving it would work only for as
-- long as those two columns keep exactly this meaning, and a stored figure is
-- what keeps Remises Obtenues a straight SUM rather than an expression that
-- quietly starts counting price changes the day someone repurposes a column.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'purchase_order_items'
       AND column_name  = 'list_price'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE purchase_order_items
       ADD COLUMN list_price DECIMAL(10,2) NOT NULL DEFAULT 0.00
           COMMENT ''Supplier tariff shown to the buyer at order time. Snapshot, never re-derived.''
           AFTER unit_price,
       ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
           COMMENT ''Remise DECLARED on this line by declining to reprice. Never inferred.''
           AFTER list_price',
    'SELECT ''purchase_order_items price columns exist'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: every historical line is recorded as bought AT tariff, i.e. with no
-- declared remise. Same reasoning as 062 — the alternative invents remises out
-- of prices that have since moved. Pre-063 lines are neutral in the discount
-- figures rather than wrong in them. Only touches rows on the 0 default.
UPDATE purchase_order_items
   SET list_price = unit_price
 WHERE list_price = 0.00;

-- -----------------------------------------------------------------------------
-- 4. Permission
-- -----------------------------------------------------------------------------
-- Mirrors sales.orders.discount_approve. Gates a declared remise above the
-- configured ceiling; repricing a supplier is never gated by it.
INSERT INTO permissions (name, module, description) VALUES
  ('inventory.procurement.discount_approve', 'inventory',
   'Accorder une remise fournisseur au-delà du plafond')
ON DUPLICATE KEY UPDATE
  module = VALUES(module), description = VALUES(description);

-- Admin only, for the same reason as 062: 001's admin grant is a one-time
-- snapshot, not a wildcard, so every later permission needs an explicit grant —
-- but a blanket re-grant would undo 028_admin_scope_driver_tools.sql.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'inventory.procurement.discount_approve'
 WHERE r.name = 'admin';

-- =============================================================================
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW CREATE TABLE supplier_prices\G
--   SHOW CREATE TABLE supplier_price_history\G
--   SELECT COUNT(*) FROM supplier_prices;          -- expect 0; see the seeding note
--   SELECT COUNT(*) FROM supplier_price_history;   -- expect 0; history starts now
--
--   SELECT COUNT(*) FROM purchase_order_items WHERE list_price = 0.00;
--   -- expect 0 — the backfill covers every existing line
--
-- PRICE EVOLUTION for one supplier/product — what this table is for:
--
--   SELECT h.changed_at, p.name AS produit, h.old_price, h.new_price,
--          h.source_reference, CONCAT(u.first_name,' ',u.last_name) AS par
--     FROM supplier_price_history h
--     JOIN products p ON p.id = h.product_id
--     LEFT JOIN users u ON u.id = h.changed_by
--    WHERE h.supplier_id = ?
--    ORDER BY h.changed_at DESC;
--
-- What it is NOT: a discount report. No view, KPI or export may aggregate
-- (old_price - new_price). Discounts on this side are
-- purchase_orders.discount_amount plus purchase_order_items.discount_amount,
-- and nothing else. The ristourne account is a third, separate thing again.
--
-- OPTIONAL SEEDING — read the header before running this
-- ------------------------------------------------------
-- Deliberately not executed by this migration. It asserts that the last price
-- paid was the supplier's standing price, which the data cannot support. Run it
-- only if you accept that, and prefer a populated baseline to an empty one:
--
--   INSERT IGNORE INTO supplier_prices (supplier_id, product_id, custom_price)
--   SELECT po.supplier_id, poi.product_id, poi.unit_price
--     FROM purchase_order_items poi
--     JOIN purchase_orders po ON po.id = poi.purchase_order_id
--     JOIN (SELECT po2.supplier_id, poi2.product_id, MAX(po2.date) AS d
--             FROM purchase_order_items poi2
--             JOIN purchase_orders po2 ON po2.id = poi2.purchase_order_id
--            WHERE po2.status <> 'cancelled'
--            GROUP BY po2.supplier_id, poi2.product_id) last
--       ON last.supplier_id = po.supplier_id
--      AND last.product_id  = poi.product_id
--      AND last.d           = po.date
--    WHERE po.status <> 'cancelled';
--
-- If you do run it, insert a matching supplier_price_history row with
-- source='import' and a note saying the value was seeded, so the log does not
-- imply a human set each price.
--
-- ROLLBACK
--   DROP TABLE IF EXISTS supplier_price_history;
--   DROP TABLE IF EXISTS supplier_prices;
--   ALTER TABLE purchase_order_items DROP COLUMN list_price,
--                                    DROP COLUMN discount_amount;
--   DELETE FROM permissions WHERE name = 'inventory.procurement.discount_approve';
-- =============================================================================
