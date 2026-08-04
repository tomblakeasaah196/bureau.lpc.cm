-- =============================================================================
-- 072_backfill_missing_coa_for_020_tax_accounts.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — put a chart_of_accounts row in front of every 44xx tax
-- account seeded by migration 020.
--
-- WHY
--   Migration 020 seeded a dozen 44xx sub-accounts in `ohada_accounts` but
--   never created the matching rows in `chart_of_accounts`. JournalPoster
--   resolves an OHADA account through `coaByOhada()`, which JOINs the two:
--   an ohada_accounts row with nothing in front of it returns NULL and the
--   poster throws — exactly the lesson migration 038 §1b learned for 601 and
--   migration 065 §2 fixed for 4431/4432/4441/4449/4451/4452/4453/4454/4461/
--   4473/7019/673/773.
--
--   4424 (AIR retenu à la source par les clients) was missed by 065, and it
--   is the one that surfaces first: any invoice to a withholding-agent client
--   (Prometal, PMUC, listed entities per Arrêté DGI n°00001) needs to debit
--   4424 at issue time. Until this migration runs, generate_invoice on such
--   a client dies with:
--
--       JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020.
--
--   Migration 020 has been run — the ohada_accounts row exists. What was
--   missing was the COA row on top of it. That is what this migration adds.
--
--   The other 020-seeded 44xx accounts (4421, 4423, 4425–4428, 4433, 4455,
--   4457, 4471, 4472, 4481, 4485) plus the four non-44 accounts (431, 447,
--   664, 6411, 6412, 419, 421, 422) are backfilled too, so the next code
--   path that resolves one of them stops on green instead of tripping the
--   same fatal.
--
-- WHAT THIS DOES
--   Inserts one `chart_of_accounts` row per OHADA account 020 seeded that
--   still has no COA row in front of it. Code = OHADA account_number, name
--   copied from ohada_accounts, `type` mapped from the OHADA type with the
--   same fallback pattern migration 065 §2 used (`other` -> `asset`).
--
-- IDEMPOTENT. Guarded on NOT EXISTS(code) — a second run inserts nothing.
--
-- SAFETY. Additive only. Nothing is renumbered, no amount changes, no
-- existing row is modified.
-- =============================================================================

INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, o.account_number, o.name,
       CASE o.type WHEN 'other' THEN 'asset' ELSE o.type END, 1
  FROM ohada_accounts o
 WHERE o.account_number IN
       -- 44xx family from migration 020 that migration 065 did not cover
       ('4421','4423','4424','4425','4426','4427','4428',
        '4433','4455','4457',
        '4471','4472','4481','4485',
        -- non-44 accounts from migration 020, same defect
        '431','447','664','6411','6412','419','421','422')
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = o.account_number);


-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--   -- Every account migration 020 seeded now has a COA row in front of it.
--   SELECT o.account_number, o.name, c.id AS coa_id
--     FROM ohada_accounts o
--     LEFT JOIN chart_of_accounts c ON c.ohada_account_id = o.id
--    WHERE o.account_number IN
--          ('4421','4423','4424','4425','4426','4427','4428',
--           '4431','4432','4433','4451','4452','4453','4455','4457',
--           '4471','4472','4481','4485',
--           '431','447','664','6411','6412','419','421','422')
--    ORDER BY o.account_number;
--   -- expect: no NULL coa_id
--
--   -- 4424 specifically — the row that lets AIR post.
--   SELECT o.account_number, o.name, c.id AS coa_id, c.code AS coa_code
--     FROM ohada_accounts o
--     LEFT JOIN chart_of_accounts c ON c.ohada_account_id = o.id
--    WHERE o.account_number = '4424';
--   -- expect: exactly one row, coa_id NOT NULL.
-- =============================================================================
