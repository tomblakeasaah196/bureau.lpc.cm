-- 119_retire_legacy_lpc_accounts.sql
--
-- Retires the LPC-prefixed auxiliary accounts left over from the original
-- seed (LPC40111 "Account payable SOURCE DU PAYS", LPC52111 "Afriland First
-- Bank", LPC60531 "Fuel and Lubricants", LPC70111 / LPC70113 "Sales of ...",
-- LPC05711 caisse, ...). They are demo data: names tied to one supplier and
-- two SKUs, and codes that are not SYSCOHADA account numbers.
--
-- DEACTIVATE, NOT DELETE.
--   · chart_of_accounts.id is referenced by journal_lines with no ON DELETE
--     rule, so a delete would orphan any posting made during testing and the
--     grand livre could no longer name the account.
--   · Deactivating is reversible from Administration -> Plan Comptable
--     ("Afficher les comptes désactivés" -> Réactiver).
--   · is_active = 0 is exactly what the Bilan d'Ouverture screen filters on,
--     so these rows stop appearing there immediately, which is the point.
--
-- WHAT IS DELIBERATELY LEFT ALONE
--   Any LPC row still wired into configuration -- a treasury account, an
--   expense category, a product's revenue/stock/COGS account, or a fixed
--   asset's charge/depreciation account. Deactivating one of those would
--   break a posting routine at run time rather than at deploy time. The
--   verification query at the bottom lists whatever was skipped so it can be
--   remapped by hand first (Plan Comptable -> Remapper), then re-run.
--
--   LPC05711 / LPC52111 / LPC40111 used to be hardcoded into
--   api/v1/finance_dashboard_api.php as the pinned cash, bank and supplier
--   accounts. That pinning was removed in the same change as this migration
--   -- the dashboard now resolves cash/bank/AP through the OHADA parent
--   (52x, 57x, 40x) -- so retiring these rows no longer zeroes the dashboard.

START TRANSACTION;

UPDATE chart_of_accounts c
   SET c.is_active = 0
 WHERE c.code LIKE 'LPC%'
   AND c.is_active = 1
   AND NOT EXISTS (SELECT 1 FROM treasury_accounts  t  WHERE t.coa_account_id            = c.id)
   AND NOT EXISTS (SELECT 1 FROM expense_categories e  WHERE e.coa_account_id            = c.id)
   AND NOT EXISTS (SELECT 1 FROM products           p  WHERE p.revenue_account_id        = c.id
                                                        OR p.stock_account_id           = c.id
                                                        OR p.cogs_account_id            = c.id)
   AND NOT EXISTS (SELECT 1 FROM fixed_assets       f  WHERE f.expense_account_id        = c.id
                                                        OR f.depr_account_id            = c.id
                                                        OR f.accumulated_depr_account_id = c.id);

COMMIT;

-- =============================================================================
-- Verification (run by hand after deploy)
--
-- 1. What is now retired:
--   SELECT code, name, is_active FROM chart_of_accounts
--    WHERE code LIKE 'LPC%' ORDER BY code;
--
-- 2. Anything still ACTIVE is still referenced by configuration. This shows
--    what is holding each one open -- remap those in Plan Comptable, then
--    re-run this UPDATE by hand (the migration itself will not re-run):
--   SELECT c.code, c.name,
--          (SELECT COUNT(*) FROM treasury_accounts  t WHERE t.coa_account_id = c.id) AS treasury,
--          (SELECT COUNT(*) FROM expense_categories e WHERE e.coa_account_id = c.id) AS categories,
--          (SELECT COUNT(*) FROM products p WHERE p.revenue_account_id = c.id
--                                              OR p.stock_account_id   = c.id
--                                              OR p.cogs_account_id    = c.id)       AS products,
--          (SELECT COUNT(*) FROM fixed_assets f WHERE f.expense_account_id = c.id
--                                                  OR f.depr_account_id    = c.id
--                                                  OR f.accumulated_depr_account_id = c.id) AS assets,
--          (SELECT COUNT(*) FROM journal_lines jl WHERE jl.account_id = c.id)        AS journal_lines
--     FROM chart_of_accounts c
--    WHERE c.code LIKE 'LPC%' AND c.is_active = 1
--    ORDER BY c.code;
-- =============================================================================
