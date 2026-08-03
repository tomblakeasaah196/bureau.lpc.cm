-- =============================================================================
-- 070_sales_line_surcharge.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — the third path a per-line price deviation can take.
--
-- WHY
-- ---
-- Migration 062 built the reconciliation: a line priced OFF the standing tariff
-- forces the operator to declare which of two things it is — the client's new
-- price (client_prices moves, history logged) or a one-off remise (tariff
-- stands, gap stored as sales_order_items.discount_amount). Any other outcome
-- was unrepresentable, so save_order actively refused the third case: a line
-- priced ABOVE tariff without confirmation threw
-- "Prix supérieur au tarif sur une ligne sans confirmation. Confirmez le
--  nouveau prix, ou ramenez la ligne au tarif."
--
-- That refusal was correct given the columns that existed, and wrong given
-- how the operators actually price. A supplier cost pass-through for a single
-- shipment, an urgency premium, a small-quantity uplift — all are legitimate
-- one-off markups that DO NOT belong in the negotiated tariff. Forcing them
-- into client_prices corrupts the tariff for every subsequent order; forcing
-- them into discount_amount as a negative number corrupts every discount total
-- that sums that column (and produces the "surcharge dressed as discount" that
-- 062's header explicitly forbids).
--
-- So the third path is: record it as a POSITIVE surcharge in its own column.
-- Symmetric to discount_amount, non-negative, motivated. Never conflated with
-- discounts on read, never summed together in reports.
--
-- A PRICE CHANGE IS STILL A PRICE CHANGE
-- --------------------------------------
-- Nothing 062 said is walked back. The doctrine survives intact:
--
--   ticked   → new client price. client_prices moves, history logged, line
--              carries neither discount nor surcharge.
--   unticked, typed < tariff → one-off remise. discount_amount holds the gap.
--   unticked, typed > tariff → one-off surcharge. surcharge_amount holds
--              the gap. Same motif field as the remise (they are two forms
--              of the same declaration: "this order deviates, and here is
--              why").
--
-- What is NOT allowed and remains rejected: silence. Every deviation from
-- tariff is one of these three, chosen by a named human, or the order does
-- not save.
--
-- HOW TOTALS COMPOSE AFTER THIS
--   subtotal        = Σ (list_price × quantity)                    -- at tariff
--   line remises    = Σ sales_order_items.discount_amount
--   line surcharges = Σ sales_order_items.surcharge_amount
--   order remise    = the exceptional amount typed in the totals box
--   total_amount    = subtotal
--                     − line remises
--                     − order remise
--                     + line surcharges
--
-- sales_orders.discount_amount continues to mean "total remise on this order"
-- and does NOT net surcharges. The two are separate quantities and are
-- reported separately: kpi_so_discount stays clean, kpi_so_surcharge mirrors
-- it as "Majorations perçues". A blended "net adjustment" figure is the
-- fiction 062 refused, and it is still refused here.
--
-- Depends on: 062_sales_module_parity.sql (list_price, discount_amount).
-- Idempotent: every step is guarded on information_schema. Safe to re-run.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1. sales_order_items.surcharge_amount — the one-off markup, per line
-- -----------------------------------------------------------------------------
-- Non-negative, stored (not derived). The reasoning mirrors 062's rationale
-- for discount_amount storage: a stored figure is what makes the Majorations
-- Perçues KPI a straight SUM, rather than an expression that starts counting
-- the wrong thing the day someone reinterprets unit_price vs list_price.
--
-- On a REPRICED line, surcharge_amount = 0 (list_price = unit_price = new
-- price, there is nothing to surcharge against). On a DECLARED-REMISE line,
-- surcharge_amount = 0 (that side of the story lives in discount_amount).
-- On a DECLARED-SURCHARGE line, list_price = the standing tariff, unit_price
-- = what was charged, surcharge_amount = (unit_price − list_price) × quantity.
--
-- discount_amount and surcharge_amount are MUTUALLY EXCLUSIVE on any given
-- line: a single line cannot both be discounted and surcharged. That is
-- structural (typed is either below tariff or above it, not both), and it is
-- enforced in the API. No DB check constraint here because sales_order_items
-- has no other check constraints today and adding one alone would be
-- inconsistent; the invariant is documented instead.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_order_items'
       AND column_name  = 'surcharge_amount'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sales_order_items
       ADD COLUMN surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
           COMMENT ''Majoration DECLARED on this line by declining to reprice a line typed above tariff. Never inferred, mutually exclusive with discount_amount.''
           AFTER discount_amount',
    'SELECT ''sales_order_items.surcharge_amount exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- No backfill. Pre-070 orders have no declared surcharges — the flow that
-- produces one did not exist, and inferring one from historical price
-- deviations would be the same fiction 062 forbids for discounts. Every
-- existing row correctly reads as surcharge_amount = 0.

-- -----------------------------------------------------------------------------
-- 2. sales_orders.surcharge_amount — the sum, cached on the header
-- -----------------------------------------------------------------------------
-- 062 does not carry a separate header column for line remises: they are
-- summed into sales_orders.discount_amount together with the order-level
-- exceptional remise. That works for discounts because "everything given
-- away on this order" is one number the operator cares about.
--
-- Surcharges are different in intent and audience: they are a supplier cost
-- pass-through, an urgency premium, a small-qty uplift — reported to
-- management as revenue upside, not netted against giveaways. A blended
-- (discounts − surcharges) figure would be the fiction 062 warns about
-- ("would surface as a discount nobody granted", now with the mirror
-- problem). So the header carries surcharge_amount SEPARATELY, and it is
-- always the sum of sales_order_items.surcharge_amount for that order —
-- there is no order-level exceptional surcharge field, because there is no
-- analogue to the exceptional remise: an unattributed markup is not a
-- thing a seller does.
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'sales_orders'
       AND column_name  = 'surcharge_amount'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE sales_orders
       ADD COLUMN surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
           COMMENT ''Sum of sales_order_items.surcharge_amount for this order. Never netted against discount_amount.''
           AFTER discount_note',
    'SELECT ''sales_orders.surcharge_amount exists'' AS info'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 3. Index for the Majorations Perçues KPI
-- -----------------------------------------------------------------------------
-- kpi_so_surcharge is a period-filtered SUM(surcharge_amount) across
-- non-cancelled orders — the exact shape idx_so_status_date already serves
-- (added by 062). Adding a covering index on surcharge_amount alone would
-- not help: the SUM has to read the value regardless. Nothing new needed.

-- =============================================================================
-- VERIFY
-- -----------------------------------------------------------------------------
--   SHOW CREATE TABLE sales_order_items\G
--   -- expect surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
--
--   SHOW CREATE TABLE sales_orders\G
--   -- expect surcharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00
--
--   SELECT COUNT(*) FROM sales_order_items WHERE surcharge_amount <> 0;
--   -- expect 0 immediately after migrating: the flow producing them is new.
--
-- CONSISTENCY CHECK — after the API changes land, this should always return
-- nothing:
--
--   SELECT so.reference,
--          so.surcharge_amount AS header_sum,
--          ROUND(COALESCE(SUM(soi.surcharge_amount), 0), 2) AS lines_sum
--     FROM sales_orders so
--     LEFT JOIN sales_order_items soi ON soi.sales_order_id = so.id
--    GROUP BY so.id
--   HAVING ABS(header_sum - lines_sum) > 0.01;
--
-- Rows here would mean save_order stopped keeping the header in sync with the
-- lines — the same class of bug the 062 subtotal check catches for discounts.
--
-- MUTUAL EXCLUSION — should also always return nothing:
--
--   SELECT id, sales_order_id, product_id, discount_amount, surcharge_amount
--     FROM sales_order_items
--    WHERE discount_amount > 0 AND surcharge_amount > 0;
--
-- MAJORATIONS PERÇUES — the KPI query, for reference:
--
--   SELECT so.reference, so.date, c.name AS client,
--          so.surcharge_amount AS majoration,
--          GROUP_CONCAT(DISTINCT so.discount_note) AS motifs
--     FROM sales_orders so
--     JOIN clients c ON c.id = so.client_id
--    WHERE so.surcharge_amount > 0
--      AND so.status <> 'cancelled'
--      AND so.date BETWEEN ? AND ?
--    GROUP BY so.id
--    ORDER BY so.surcharge_amount DESC;
--
-- ROLLBACK
--   ALTER TABLE sales_orders     DROP COLUMN surcharge_amount;
--   ALTER TABLE sales_order_items DROP COLUMN surcharge_amount;
-- =============================================================================
