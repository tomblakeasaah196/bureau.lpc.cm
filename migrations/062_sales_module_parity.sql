-- =============================================================================
-- 062_sales_module_parity.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — bring modules/sales/orders.php up to the shape Achats
-- (modules/inventory/procurement.php) already has, and record a price change as
-- a price change.
--
-- WHY
-- ---
-- Ventes & Commandes and Gestion des Achats are the two halves of the same
-- movement — goods in against a supplier document, goods out against a client
-- document — but only the Achats half was ever finished. Achats has a period
-- selector feeding its KPIs, clickable KPIs that open a detail modal, a
-- server-paginated table, a full-page order detail (po_detail.php), a
-- cancellation path that reverses what the order committed, and a discount that
-- is recorded with a motif and totalled into its own KPI. Ventes has none of
-- those. This migration is the schema half of closing that gap; the rest is in
-- api/v1/sales_controller.php, modules/sales/*.php and
-- assets/js/modules/sales-*.js.
--
-- A PRICE IS NOT A DISCOUNT
-- -------------------------
-- Every client has their own price (client_prices.custom_price, falling back to
-- products.base_price). When a seller puts a different number in the price
-- field on an order line, that is — by default — that client's NEW PRICE. It is
-- not a rebate, not a remise, and not a concession.
--
-- Stating this explicitly because the tempting design is the wrong one. Given a
-- tariff and a typed price it is easy to subtract the two, call the difference
-- a "remise implicite" and total it into a discount report. That report would
-- be fiction. Prices move — a client renegotiates, a currency moves, a
-- supplier's cost is passed on — and every one of those movements would surface
-- as a discount nobody ever granted. The number would be wrong, and wrong in a
-- way that reads as fraud detection.
--
-- SO NOTHING IS INFERRED. THE SELLER DECLARES.
-- On submit, sales_controller.php compares each line to the standing tariff and
-- returns any disparities to the browser, which opens a reconciliation modal:
-- one row per changed line, old → new, with a checkbox. What the operator does
-- with each row is the whole mechanism:
--
--   ticked   → this is the client's new price. client_prices is updated,
--              client_price_history records the move, and the line carries
--              NO discount. list_price = unit_price = the new price, because
--              at the moment of sale the tariff IS the new price.
--
--   unticked → this is a one-off reduction. The tariff is left alone and the
--              gap is recorded as a DECLARED remise on that line:
--              list_price = the standing tariff, unit_price = what was
--              charged, discount_amount = the difference × quantity.
--
-- Both outcomes are explicit human statements. Neither is a guess. That is why
-- sales_order_items.list_price exists here after all — not to let a report
-- subtract two numbers behind the operator's back, but to hold the tariff the
-- operator was shown at the moment they declined to change it. Without it a
-- declared line remise cannot be reconstructed at all.
--
-- Same rule on the buy side: a supplier changing their price is a new price,
-- not a ristourne, and purchase_orders.discount_amount keeps meaning only what
-- was explicitly entered as a remise. (Checked 2026-08-02:
-- procurement_controller.php infers nothing of the kind today.) The supplier
-- half of this — supplier_prices, supplier_price_history and the same modal on
-- the PO form — lands in 063; suppliers have no tariff table at all today, so
-- it has to be built before it can be mirrored.
--
-- WHAT THIS MIGRATION DOES
-- ------------------------
--   1. client_price_history           — append-only log of every price change,
--                                       with who / when / from what / against
--                                       which order.
--   2. sales_order_items.list_price   — the tariff the operator was shown.
--      sales_order_items.discount_amount — the remise they declared on that
--                                       line by declining to reprice.
--   3. sales_orders.discount_note     — motif for the order-level exceptional
--                                       remise. Mirrors purchase_orders.
--                                       discount_note, which Achats has had
--                                       since 051.
--   4. sales_orders cancellation trio — cancelled_at / cancelled_by /
--                                       cancellation_reason.
--   5. Two permissions                — sales.orders.cancel and
--                                       sales.orders.discount_approve.
--   6. Indexes for the period-filtered KPI queries.
--
-- HOW THE TOTALS COMPOSE AFTER THIS
--   subtotal        = Σ (list_price × quantity)          -- at tariff
--   line remises    = Σ sales_order_items.discount_amount
--   order remise    = the exceptional amount typed in the totals box
--   discount_amount = line remises + order remise         -- on sales_orders
--   total_amount    = subtotal − discount_amount
--
-- So `sales_orders.discount_amount` keeps its existing meaning ("everything
-- given away on this order") and gains a recoverable breakdown, and the
-- Remises Accordées KPI finally totals something complete. A repriced line
-- contributes nothing to it, correctly — there was no discount, only a price.
--
-- Depends on: 042_pricing_fallback.sql (client_prices), 001_rbac_seed.sql.
-- Idempotent: every step is guarded on information_schema or uses
-- INSERT IGNORE / ON DUPLICATE KEY UPDATE. Safe to re-run.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. client_price_history — how a client's price got to where it is
-- -----------------------------------------------------------------------------
-- Append-only. Never updated, never deleted; a correction is another row.
--
-- old_price is nullable and means "this client had no negotiated price before
-- now" — the first row for a (client, product) pair records the move from the
-- catalogue fallback onto a client-specific price, and there is no earlier
-- custom_price to name. Storing products.base_price there instead would assert
-- the client had been on the catalogue price, which may not be true if the
-- catalogue has since moved.
--
-- source_reference carries the document that caused the change (CMD-2608-XXXX)
-- when it came from an order line, and is NULL when a price was set directly in
-- the client's file (api/v1/mdm_controller.php, which today overwrites
-- client_prices with ON DUPLICATE KEY UPDATE and records nothing). No FK to
-- sales_orders: an order can be deleted before dispatch, and the price change
-- it caused is still real.
--
-- PRECEDENT AND THE DIFFERENCE FROM IT
-- Migration 060 created `recycling_price_overrides` for the same broad problem
-- — someone applied a price other than the catalogue one — and this table
-- follows its conventions: append-only, mandatory attribution, indexed for
-- lookup, FKs chosen so the log outlives the things it refers to.
--
-- It is deliberately NOT the same shape. 060 stores delta_amount, delta_pct and
-- total_impact, because a recycling override is a one-off deviation at the
-- gate: the catalogue price still stands afterwards, so the gap between the two
-- is a real quantity with a real cash effect. A client price change is the
-- opposite — the new number REPLACES the old one and becomes the price. There
-- is no standing price left to deviate from, so a "delta" would be the
-- difference between two prices that were correct on two different dates. That
-- is not an impact and must not be summed. Hence old_price/new_price and no
-- delta column: you can see the move, you cannot total it.
CREATE TABLE IF NOT EXISTS client_price_history (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    client_id       INT NOT NULL,
    product_id      INT NOT NULL,
    old_price       DECIMAL(10,2) NULL
                    COMMENT 'Previous client_prices.custom_price. NULL = client had no specific price before.',
    new_price       DECIMAL(10,2) NOT NULL,
    source          ENUM('order','client_file','import') NOT NULL DEFAULT 'order'
                    COMMENT 'Where the change came from.',
    source_reference VARCHAR(50) NULL
                    COMMENT 'Document reference that caused it, e.g. CMD-2608-1A2B. No FK — the document may later be deleted; the price change still happened.',
    note            VARCHAR(255) NULL,
    changed_by      INT NOT NULL COMMENT 'users.id — who repriced.',
    changed_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      VARCHAR(45) NULL,
    KEY idx_cph_client_product (client_id, product_id, changed_at),
    KEY idx_cph_changed_at (changed_at),
    -- client CASCADEs because api/v1/purge_client.php hard-deletes a client and
    -- relies on FKs to take the dependent rows with it; RESTRICT here would
    -- break purge outright. product and user RESTRICT, as in 060 — you must not
    -- be able to delete a product or a user out from under a price they are on
    -- record as having set. Neither is hard-deleted anywhere in the codebase
    -- today (products deactivate via is_active), so this costs nothing.
    CONSTRAINT fk_cph_client  FOREIGN KEY (client_id)  REFERENCES clients(id)  ON DELETE CASCADE,
    CONSTRAINT fk_cph_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
    CONSTRAINT fk_cph_user    FOREIGN KEY (changed_by) REFERENCES users(id)    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
  COMMENT='Append-only log of client price changes. A price change is a price change, never a discount — see migration header.';

-- No backfill. There is no source to backfill from: client_prices has no
-- history and sales_order_items.unit_price cannot distinguish "this was the
-- price" from "this was a price change" retrospectively. Inventing rows here
-- would put fabricated changes in an audit log. History starts at 062.

-- -----------------------------------------------------------------------------
-- 2. sales_order_items — the tariff shown, and the remise declared against it
-- -----------------------------------------------------------------------------
-- list_price is the standing tariff at the moment the operator was asked. It is
-- a snapshot and not a derivation: client_prices is mutable and keeps no
-- history of its own, so re-deriving "what the tariff was" months later returns
-- the price as of today, not as of the sale. Snapshotting is the point.
--
-- On a REPRICED line (operator ticked the box) list_price and unit_price are
-- both the new price and discount_amount is 0 — there was no discount, the
-- tariff simply moved. On a DECLARED-REMISE line (operator left it unticked)
-- list_price is the old tariff, unit_price is what was charged, and
-- discount_amount is the difference × quantity.
--
-- discount_amount is stored rather than computed on read. It could be derived
-- as (list_price - unit_price) * quantity, but only for as long as those two
-- columns keep exactly this meaning — and a stored figure is what makes the
-- Remises Accordées KPI a straight SUM instead of an expression that quietly
-- starts counting price changes the day someone repurposes a column. Storing
-- the operator's declaration directly is the whole design.
--
-- Both NOT NULL DEFAULT 0; backfilled immediately below so 0 does not survive.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_order_items'
       AND column_name  = 'list_price'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sales_order_items
       ADD COLUMN list_price DECIMAL(10,2) NOT NULL DEFAULT 0.00
           COMMENT ''Standing tariff the operator was shown at sale time. Snapshot, never re-derived.''
           AFTER unit_price,
       ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
           COMMENT ''Remise DECLARED on this line by declining to reprice. Never inferred.''
           AFTER list_price',
    'SELECT ''sales_order_items price columns exist'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: every historical line is recorded as sold AT tariff, i.e. no
-- declared remise. That is the only honest choice available. The alternative —
-- joining to today's client_prices and calling any gap a discount — is exactly
-- the fiction this migration exists to prevent, and it would invent remises out
-- of prices that have since moved. Pre-062 lines are therefore neutral in the
-- discount figures rather than wrong in them, and 062 is the epoch from which
-- Remises Accordées means something. Only touches rows still on the 0 default,
-- so a replay leaves any hand-correction alone.
UPDATE sales_order_items
   SET list_price = unit_price
 WHERE list_price = 0.00;

-- -----------------------------------------------------------------------------
-- 3. sales_orders.discount_note — the motif for an explicit remise
-- -----------------------------------------------------------------------------
-- `discount_amount` has been written by save_order since this module was built
-- and read back by nothing at all — no column, no KPI, no report. There is a
-- 550 FCFA discount on a 1 200 FCFA order in the current data (CMD-2602-F33A)
-- with no reason recorded anywhere. The API now requires a motif whenever the
-- amount is non-zero.
--
-- Nullable despite that rule: every pre-062 order has no note, and a NOT NULL
-- column would fail this migration on exactly those rows. The requirement
-- belongs in sales_controller.php, which can apply it to new writes only.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_orders'
       AND column_name  = 'discount_note'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sales_orders
       ADD COLUMN discount_note VARCHAR(255) NULL
           COMMENT ''Motif de la remise explicite. Required by the API when discount_amount > 0. Nothing to do with pricing.''
           AFTER discount_amount',
    'SELECT ''sales_orders.discount_note exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. Cancellation columns
-- -----------------------------------------------------------------------------
-- `status` has had 'cancelled' in its enum since the table was created, but
-- sales_controller.php has no action that writes it — the only removal path is
-- delete_order, a hard DELETE restricted to orders with no BL. So a dispatched
-- order that falls through cannot be closed out at all, and a deleted one
-- leaves no trace. Achats solved this with cancel_inventory: the row stays,
-- greyed, excluded from the KPIs, with who/when/why recorded.
--
-- cancelled_by has no FK to users, matching the rest of this schema's audit
-- columns (created_by on this same table is a bare int). Deliberate: adding one
-- here alone would be inconsistent, and the missing-FK question is tracked
-- wholesale as H-DB-1 in docs/AUDIT_REPORT.md.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_orders'
       AND column_name  = 'cancelled_at'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sales_orders
       ADD COLUMN cancelled_at        TIMESTAMP NULL DEFAULT NULL
           COMMENT ''Set by sales_controller cancel_order. NULL = not cancelled.'',
       ADD COLUMN cancelled_by        INT NULL DEFAULT NULL
           COMMENT ''users.id of whoever cancelled. No FK — see H-DB-1.'',
       ADD COLUMN cancellation_reason VARCHAR(255) NULL DEFAULT NULL
           COMMENT ''Required by the API when cancelling.''',
    'SELECT ''sales_orders cancellation columns exist'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5. Permissions
-- -----------------------------------------------------------------------------
-- sales.orders.cancel is separate from sales.orders.delete on purpose. Delete
-- applies only before a BL exists and destroys the row; cancel applies after,
-- keeps the row, and reverses what the order committed. Different blast radius,
-- different permission — the same split Achats makes between create_po and
-- approve.
--
-- sales.orders.discount_approve gates an EXPLICIT remise above the configured
-- ceiling. It has nothing to do with pricing: changing a client's price is
-- ordinary commercial work and is not gated here.
INSERT INTO permissions (name, module, description) VALUES
  ('sales.orders.cancel',           'sales', 'Annuler une commande (après BL) — conserve la trace'),
  ('sales.orders.discount_approve', 'sales', 'Accorder une remise exceptionnelle au-delà du plafond')
ON DUPLICATE KEY UPDATE
  module      = VALUES(module),
  description = VALUES(description);

-- Admin. NOT a wildcard re-grant: 001's admin INSERT is a one-time snapshot of
-- the permissions that existed when it ran, and a blanket re-run would undo
-- 028_admin_scope_driver_tools.sql. See the long note in 059.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p
    ON p.name IN ('sales.orders.cancel', 'sales.orders.discount_approve')
 WHERE r.name = 'admin';

-- Operations already holds sales.orders.delete, i.e. authority to remove an
-- order outright. Cancel is strictly narrower than that, so withholding it
-- would be incoherent. discount_approve is deliberately NOT granted — signing
-- off an exceptional remise is a different question from running logistics.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'sales.orders.cancel'
 WHERE r.name = 'operations';

-- -----------------------------------------------------------------------------
-- 6. Indexes for the period-filtered KPIs
-- -----------------------------------------------------------------------------
-- Every KPI on the rebuilt page is now "over a date range, excluding
-- cancelled", and the list is ordered by date DESC. (status, date) serves the
-- KPI shape; (date) alone serves the ORDER BY on the unfiltered list.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_orders'
       AND index_name   = 'idx_so_status_date'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE sales_orders ADD INDEX idx_so_status_date (status, date)',
    'SELECT ''idx_so_status_date exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_orders'
       AND index_name   = 'idx_so_date'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE sales_orders ADD INDEX idx_so_date (date)',
    'SELECT ''idx_so_date exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The dispatch tab's KPIs filter deliveries by date; 061 added
-- (delivery_mode, date), which cannot serve a date-only range scan.
SET @idx_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'deliveries'
       AND index_name   = 'idx_deliveries_date'
);
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE deliveries ADD INDEX idx_deliveries_date (date)',
    'SELECT ''idx_deliveries_date exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =============================================================================
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW CREATE TABLE sales_orders\G
--   -- expect discount_note, cancelled_at, cancelled_by, cancellation_reason,
--   --        KEY idx_so_status_date, KEY idx_so_date
--
--   SHOW CREATE TABLE client_price_history\G
--   SELECT COUNT(*) FROM client_price_history;   -- expect 0; history starts now
--
--   SHOW CREATE TABLE sales_order_items\G
--   -- expect list_price DECIMAL(10,2) NOT NULL, discount_amount DECIMAL(15,2) NOT NULL
--   SELECT COUNT(*) FROM sales_order_items WHERE list_price = 0.00;
--   -- expect 0 — the backfill covers every existing line
--   SELECT COUNT(*) FROM sales_order_items WHERE discount_amount <> 0;
--   -- expect 0 pre-062: no historical line carries a declared remise
--
--   SELECT p.name FROM role_permissions rp
--     JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id
--    WHERE r.name = 'admin' AND p.name IN
--          ('sales.orders.cancel','sales.orders.discount_approve');
--   -- expect 2 rows
--
-- PRICE EVOLUTION for one client/product — what this table is actually for:
--
--   SELECT h.changed_at, p.name AS produit, h.old_price, h.new_price,
--          h.source, h.source_reference,
--          CONCAT(u.first_name,' ',u.last_name) AS par
--     FROM client_price_history h
--     JOIN products p ON p.id = h.product_id
--     LEFT JOIN users u ON u.id = h.changed_by
--    WHERE h.client_id = ?
--    ORDER BY h.changed_at DESC;
--
-- Note what this is NOT: it is not a discount report, and no view, KPI or
-- export may aggregate (old_price - new_price) into one. Those are two
-- different prices on two different dates, and the difference between them is
-- not money given away.
--
-- REMISES ACCORDÉES — the only discount figure there is:
--
--   SELECT so.reference, so.date, c.name AS client,
--          COALESCE(SUM(soi.discount_amount), 0) AS remises_lignes,
--          so.discount_amount                    AS remise_totale,
--          so.discount_note                      AS motif
--     FROM sales_orders so
--     JOIN clients c ON c.id = so.client_id
--     LEFT JOIN sales_order_items soi ON soi.sales_order_id = so.id
--    WHERE so.discount_amount > 0
--      AND so.status <> 'cancelled'
--      AND so.date BETWEEN ? AND ?
--    GROUP BY so.id
--    ORDER BY so.discount_amount DESC;
--
-- Every row in that result is a reduction a named human ticked "no, this is not
-- a new price" against. A repriced line never appears here — it appears in
-- client_price_history instead, which is the correct place for it.
--
-- CONSISTENCY CHECK — should always return nothing:
--
--   SELECT so.reference,
--          ROUND(SUM(soi.list_price * soi.quantity), 2) AS should_be_subtotal,
--          so.subtotal
--     FROM sales_orders so
--     JOIN sales_order_items soi ON soi.sales_order_id = so.id
--    WHERE so.id > 0
--    GROUP BY so.id
--   HAVING ABS(should_be_subtotal - so.subtotal) > 0.01;
--
-- Rows here mean save_order stopped computing the subtotal at tariff, which
-- would silently corrupt the discount split.
--
-- ROLLBACK
--   DROP TABLE IF EXISTS client_price_history;
--   ALTER TABLE sales_order_items DROP COLUMN list_price,
--                                 DROP COLUMN discount_amount;
--   ALTER TABLE sales_orders DROP COLUMN discount_note,
--                            DROP COLUMN cancelled_at,
--                            DROP COLUMN cancelled_by,
--                            DROP COLUMN cancellation_reason,
--                            DROP INDEX idx_so_status_date,
--                            DROP INDEX idx_so_date;
--   ALTER TABLE deliveries DROP INDEX idx_deliveries_date;
--   DELETE FROM permissions WHERE name IN
--     ('sales.orders.cancel','sales.orders.discount_approve');
--   -- role_permissions rows cascade off the permissions delete.
-- =============================================================================
