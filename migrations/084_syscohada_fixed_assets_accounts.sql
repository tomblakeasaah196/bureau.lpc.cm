-- =============================================================================
-- 084_syscohada_fixed_assets_accounts.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — SYSCOHADA revised (2017) fixed-asset chart of accounts.
--
-- WHY
--   The Immobilisations module modal shipped since Sprint 4 renders three
--   account dropdowns (Actif 2xx, Amortissement 28x, Dotation 68x) and,
--   for the cession modal, one Trésorerie 5xx dropdown. They all render
--   empty because:
--     · migration 003 only seeded the 3-digit tiers (401, 411, 521, 571,
--       601, 605, 701) — none of the Class 2 / 28 / 68 / 81 / 82 accounts
--       required for the fixed-asset lifecycle;
--     · the eight legacy chart_of_accounts rows carry `LPC…`-prefixed
--       codes, so the JS filter `code.startsWith('2')` matches none of
--       them; nothing else in the migration timeline back-fills 2xx.
--   Net effect on prod: the modal cannot be submitted, JournalPoster has
--   no 81/82 mapping for a cession, and every fixed-asset flow errors out.
--
-- WHAT THIS DOES
--   1. Seeds the SYSCOHADA revised Class 2 / 28 / 68 / 81 / 82 accounts
--      used by the fixed-asset lifecycle. Codes are the OHADA
--      3-to-4-digit numbers (241, 244, 245, 2451, 2841, 2844, 2845, 6813,
--      812, 822). Types map to `ohada_accounts.type` per its enum.
--   2. Seeds two Class 5 accounts (521 Banques, 571 Caisse) that the
--      cession modal points at when the sale is a real cash-in.
--      Migration 003 seeded those under the *asset* type; here we only
--      guarantee a `chart_of_accounts` row in front of them so the
--      dropdown resolves.
--   3. Fills two `app_preferences` rows — accounting_moins_value_account_id
--      and accounting_plus_value_account_id — so `Depreciation::disposalGain()`
--      has a company-level default when the per-asset override is null.
--      Their kind is 'int'; the picker in Settings → Comptabilité renders
--      them as free-text integers for now (a proper picker widget can wait
--      for Sprint 9).
--   4. Adds `disposal_vat_amount` to `fixed_asset_disposals` so the cession
--      modal can carry a TVA amount when the asset was subject to VAT and
--      the sale price is TTC — the JE splits into 5xx / 4432 accordingly.
--   5. Adds `plus_value_account_id` and `moins_value_account_id` columns on
--      `fixed_assets` — per-asset overrides for the company defaults above.
--
-- Depends on: 003_ohada_backfill.sql, 011_fixed_assets_schema.sql,
--             034_company_profile.sql.
-- Idempotent: every INSERT uses ON DUPLICATE KEY / NOT EXISTS. Every
--             ALTER is behind an information_schema pre-check.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. OHADA accounts — Class 2, 28, 68, 81, 82.
-- -----------------------------------------------------------------------------
INSERT INTO ohada_accounts (account_number, name, type) VALUES
  -- Immobilisations incorporelles.
  ('211', 'Frais de développement et de prospection',              'asset'),
  ('213', 'Logiciels et sites internet',                           'asset'),
  ('214', 'Brevets, licences, concessions et droits similaires',   'asset'),
  -- Terrains et bâtiments.
  ('221', 'Terrains agricoles et forestiers',                      'asset'),
  ('222', 'Terrains nus',                                          'asset'),
  ('231', 'Bâtiments industriels, administratifs et commerciaux',  'asset'),
  ('232', 'Bâtiments industriels sur sol d''autrui',               'asset'),
  ('233', 'Ouvrages d''infrastructures',                           'asset'),
  ('235', 'Aménagements de bureaux',                               'asset'),
  -- Matériel — le gros des cas d'usage.
  ('241',  'Matériel et outillage industriel et commercial',       'asset'),
  ('244',  'Matériel et mobilier',                                 'asset'),
  ('2441', 'Matériel de bureau',                                   'asset'),
  ('2444', 'Mobilier de bureau',                                   'asset'),
  ('2445', 'Matériel informatique',                                'asset'),
  ('245',  'Matériel de transport',                                'asset'),
  ('2451', 'Matériel automobile',                                  'asset'),
  ('2452', 'Matériel ferroviaire',                                 'asset'),
  ('2454', 'Matériel aérien',                                      'asset'),
  ('2458', 'Autre matériel de transport',                          'asset'),
  -- Amortissements (comptes régulateurs de l'actif).
  ('281',  'Amortissements des immobilisations incorporelles',     'asset'),
  ('2813', 'Amortissements des logiciels et sites internet',       'asset'),
  ('2814', 'Amortissements des brevets, licences',                 'asset'),
  ('283',  'Amortissements des bâtiments et installations',        'asset'),
  ('2831', 'Amortissements des bâtiments industriels et commerciaux', 'asset'),
  ('284',  'Amortissements du matériel',                           'asset'),
  ('2841', 'Amortissements du matériel et outillage',              'asset'),
  ('2844', 'Amortissements du matériel et mobilier',               'asset'),
  ('2845', 'Amortissements du matériel de transport',              'asset'),
  ('28451','Amortissements du matériel automobile',                'asset'),
  -- Dotations aux amortissements (charges d'exploitation).
  ('681',  'Dotations aux amortissements d''exploitation',         'expense'),
  ('6811', 'Dotations aux amortissements des immobilisations incorporelles', 'expense'),
  ('6813', 'Dotations aux amortissements des immobilisations corporelles',   'expense'),
  -- Valeurs comptables et produits de cession.
  ('81',   'Valeurs comptables des cessions d''immobilisations',   'expense'),
  ('812',  'VCE d''immobilisations corporelles',                   'expense'),
  ('811',  'VCE d''immobilisations incorporelles',                 'expense'),
  ('82',   'Produits des cessions d''immobilisations',             'revenue'),
  ('822',  'Produits des cessions d''immobilisations corporelles', 'revenue'),
  ('821',  'Produits des cessions d''immobilisations incorporelles', 'revenue')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  type = VALUES(type);

-- -----------------------------------------------------------------------------
-- 2. chart_of_accounts rows in front of every seed above. JournalPoster and
--    the UI both resolve accounts through chart_of_accounts, so an ohada_accounts
--    row with no COA row is invisible to the app.
--    Guarded with NOT EXISTS(code) — chart_of_accounts.code is UNIQUE per
--    migration 003, but not every install has the index yet.
-- -----------------------------------------------------------------------------
INSERT INTO chart_of_accounts (ohada_account_id, code, name, type, is_active)
SELECT o.id, o.account_number, o.name,
       CASE o.type WHEN 'other' THEN 'asset' ELSE o.type END, 1
  FROM ohada_accounts o
 WHERE o.account_number IN (
        '211','213','214',
        '221','222','231','232','233','235',
        '241','244','2441','2444','2445','245','2451','2452','2454','2458',
        '281','2813','2814','283','2831','284','2841','2844','2845','28451',
        '681','6811','6813',
        '81','812','811','82','822','821',
        -- Trésorerie: guarantee a COA row exists even if 003 didn't add one.
        '521','571'
   )
   AND NOT EXISTS (SELECT 1 FROM chart_of_accounts c WHERE c.code = o.account_number);

-- -----------------------------------------------------------------------------
-- 3. Per-asset override columns on fixed_assets.
--    Depreciation::disposalGain() already reads $asset['plus_value_account_id']
--    and $asset['moins_value_account_id']; migration 011 forgot to add them.
--    Falls back to the company-default preference below when NULL — see the
--    controller.
-- -----------------------------------------------------------------------------
SET @has_plus := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='fixed_assets'
       AND column_name='plus_value_account_id');
SET @sql := IF(@has_plus = 0,
    'ALTER TABLE fixed_assets ADD COLUMN plus_value_account_id INT NULL
        COMMENT ''Override for 82x. NULL = fall back to accounting_plus_value_account_id preference''
        AFTER charge_account_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_moins := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='fixed_assets'
       AND column_name='moins_value_account_id');
SET @sql := IF(@has_moins = 0,
    'ALTER TABLE fixed_assets ADD COLUMN moins_value_account_id INT NULL
        COMMENT ''Override for 81x. NULL = fall back to accounting_moins_value_account_id preference''
        AFTER plus_value_account_id',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 4. TVA column on fixed_asset_disposals.
--    When the ceded asset was VAT-eligible (immobilisation subject to TVA
--    récupérée à l'origine) and the sale is TTC, the JE has to split the
--    encaissement between the cash account and 4431 (TVA facturée).
--    Storing it here keeps the disposal row self-describing.
-- -----------------------------------------------------------------------------
SET @has_vat := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema=DATABASE() AND table_name='fixed_asset_disposals'
       AND column_name='disposal_vat_amount');
SET @sql := IF(@has_vat = 0,
    'ALTER TABLE fixed_asset_disposals ADD COLUMN disposal_vat_amount DECIMAL(15,2) NOT NULL DEFAULT 0
        COMMENT ''TVA collectée sur cession — posted to 4431 when > 0''
        AFTER sale_amount',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- -----------------------------------------------------------------------------
-- 5. app_preferences — company-level defaults for 81x and 82x.
--    Stored as chart_of_accounts.id so the resolver can join straight to
--    the account row without a second lookup.
-- -----------------------------------------------------------------------------
INSERT INTO app_preferences
    (pref_key, pref_value, kind, group_key, label_fr, label_en, help_fr, sort_order, is_locked)
VALUES
    ('accounting_moins_value_account_id',
     CAST((SELECT id FROM chart_of_accounts WHERE code = '812' LIMIT 1) AS CHAR),
     'int', 'accounting',
     'Compte moins-value de cession (81x)',
     'Loss on disposal account (81x)',
     'Compte par défaut débité de la moins-value lors d''une cession d''immobilisation.',
     820, 0),
    ('accounting_plus_value_account_id',
     CAST((SELECT id FROM chart_of_accounts WHERE code = '822' LIMIT 1) AS CHAR),
     'int', 'accounting',
     'Compte plus-value de cession (82x)',
     'Gain on disposal account (82x)',
     'Compte par défaut crédité de la plus-value lors d''une cession d''immobilisation.',
     821, 0),
    ('accounting_vat_output_account_id',
     CAST((SELECT id FROM chart_of_accounts WHERE code = '4431' LIMIT 1) AS CHAR),
     'int', 'accounting',
     'Compte TVA facturée (4431)',
     'Output VAT account (4431)',
     'Compte TVA collectée utilisé lors des cessions d''immobilisations TTC.',
     822, 0)
ON DUPLICATE KEY UPDATE
    label_fr    = VALUES(label_fr),
    label_en    = VALUES(label_en),
    help_fr     = VALUES(help_fr),
    group_key   = VALUES(group_key),
    kind        = VALUES(kind),
    sort_order  = VALUES(sort_order);

COMMIT;

-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--   -- All fixed-asset accounts have a COA row in front of them.
--   SELECT o.account_number, o.name, c.id AS coa_id
--     FROM ohada_accounts o
--     LEFT JOIN chart_of_accounts c ON c.ohada_account_id = o.id
--    WHERE o.account_number REGEXP '^(2[0-9]|28|68|81|82)[0-9]?[0-9]?$'
--    ORDER BY o.account_number;
--   -- expect: no NULL coa_id
--
--   -- The two defaults land on the intended accounts.
--   SELECT p.pref_key, p.pref_value, c.code, c.name
--     FROM app_preferences p
--     LEFT JOIN chart_of_accounts c ON c.id = p.pref_value
--    WHERE p.pref_key IN ('accounting_moins_value_account_id',
--                         'accounting_plus_value_account_id');
--   -- expect: 812 and 822.
-- =============================================================================
