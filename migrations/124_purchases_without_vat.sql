-- =============================================================================
-- 124_purchases_without_vat.sql
-- -----------------------------------------------------------------------------
-- Purchases carry no TVA (owner decision, 3 Nov 2026, client QA: « There is no
-- business with TVA from Source du Pays. Leave it at the exact amount always and
-- for all suppliers »).
--
-- save_po used to freeze every order as « 19.25 % TVA included, deductible »
-- (vat_recoverable = 1). Reception then stocked the goods at price / 1.1925
-- (a 1 850 F bottle at 1 551.36 F of CUMP) and booked the difference to 4452
-- TVA récupérable. save_po now freezes vat_rate 0 / vat_recoverable 0 on every
-- new order (api/v1/procurement_controller.php), so the order price is the
-- cost, in stock and in 601.
--
-- This file brings the orders that were placed but NOT YET RECEIVED into line:
-- nothing has been stocked or posted for them, so changing what they will book
-- is safe. Orders already received (partial / received / cancelled) are left
-- exactly as they are: their stock movements, CUMP and journal entries were
-- written with the old split, and a reception cancellation must unwind exactly
-- what was booked (lpc_po_unit_cost reads the order's frozen values for both).
--
-- Idempotent: a plain UPDATE guarded on the old values; a second run matches
-- nothing. Requires migration 065 (the vat_* columns), applied long before.
-- =============================================================================

UPDATE purchase_orders
   SET vat_rate        = 0,
       subtotal_ht     = subtotal,
       vat_amount      = 0,
       vat_recoverable = 0
 WHERE status = 'pending'
   AND (vat_recoverable = 1 OR vat_rate > 0);
