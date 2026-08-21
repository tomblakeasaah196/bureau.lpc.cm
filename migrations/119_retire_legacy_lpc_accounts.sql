-- 119_retire_legacy_lpc_accounts.sql
--
-- Retires three demo rows from the original seed:
--   LPC60531  "Fuel and Lubricants"      (parent 605)  -- superseded by
--                                                         6022 Combustibles,
--                                                         seeded in migration 117
--   LPC70111  "Sales of 20L Opur"        (parent 701)  -- one SKU, not an account
--   LPC70113  "Sales of 10L Supermont"   (parent 701)  -- one SKU, not an account
--
-- Products carry their own revenue_account_id, so a chart row per SKU was never
-- needed; sales resolve through the 701 parent.
--
-- SCOPE: exactly these three. The structural legacy rows -- LPC40111 (401
-- Fournisseurs), LPC41211 (411 Clients), LPC52111 (521 Banques), LPC05711 (571
-- Caisse) -- are deliberately NOT touched here. They are currently the ONLY
-- chart_of_accounts rows under those OHADA parents, so they are load-bearing:
-- JournalPoster::coaByOhada() and lpc_account_for() resolve 401/411/521/571
-- straight to them. They are hidden from the chart and opening-balance screens
-- at the UI layer instead, and get deleted with the rest of the demo data at
-- wipe time.
--
-- DEACTIVATE, NOT DELETE. chart_of_accounts.id is referenced by journal_lines
-- with no ON DELETE rule, so deleting would orphan any test posting and the
-- grand livre could no longer name the account. is_active = 0 removes the row
-- from every account picker (they all filter is_active = 1) and is reversible
-- from Administration -> Plan Comptable.
--
-- NOTE ON RESOLUTION: coaByOhada() and lpc_account_for() do NOT filter on
-- is_active, so deactivating changes what a human can pick but not what an
-- automatic posting resolves to. That is intentional here -- these three are
-- not the lowest-id row under their parent for any routine that matters, and
-- the aim is to get them out of the operator's way, not to re-route postings
-- days before a full wipe.

START TRANSACTION;

UPDATE chart_of_accounts c
   SET c.is_active = 0
 WHERE c.code IN ('LPC60531', 'LPC70111', 'LPC70113')
   AND c.is_active = 1
   -- Skip anything still wired into configuration: deactivating one of these
   -- would break a posting routine at run time rather than at deploy time.
   -- Whatever is skipped is listed by the verification query below; remap it
   -- in Plan Comptable, then re-run this UPDATE by hand.
   AND NOT EXISTS (SELECT 1 FROM treasury_accounts  t WHERE t.coa_account_id             = c.id)
   AND NOT EXISTS (SELECT 1 FROM expense_categories e WHERE e.coa_account_id             = c.id)
   AND NOT EXISTS (SELECT 1 FROM products           p WHERE p.revenue_account_id         = c.id
                                                        OR p.stock_account_id            = c.id
                                                        OR p.cogs_account_id             = c.id)
   AND NOT EXISTS (SELECT 1 FROM fixed_assets       f WHERE f.expense_account_id          = c.id
                                                        OR f.depr_account_id              = c.id
                                                        OR f.accumulated_depr_account_id  = c.id);

COMMIT;

-- =============================================================================
-- Verification (run by hand after deploy)
--
-- 1. Expect the three rows at is_active = 0:
--   SELECT code, name, is_active FROM chart_of_accounts
--    WHERE code IN ('LPC60531','LPC70111','LPC70113');
--
-- 2. Any of the three still ACTIVE is still referenced by configuration.
--    This names what is holding it open:
--   SELECT c.code, c.name,
--          (SELECT COUNT(*) FROM expense_categories e WHERE e.coa_account_id = c.id) AS categories,
--          (SELECT COUNT(*) FROM products p WHERE p.revenue_account_id = c.id
--                                              OR p.stock_account_id   = c.id
--                                              OR p.cogs_account_id    = c.id)       AS products,
--          (SELECT COUNT(*) FROM journal_lines jl WHERE jl.account_id = c.id)        AS journal_lines
--     FROM chart_of_accounts c
--    WHERE c.code IN ('LPC60531','LPC70111','LPC70113') AND c.is_active = 1;
-- =============================================================================
