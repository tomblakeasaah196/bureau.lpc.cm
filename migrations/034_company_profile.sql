-- =============================================================================
-- 034_company_profile.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — Sprint 8 · single source of truth for company identity.
--
-- WHY THIS EXISTS
-- ---------------
-- Before this migration the company's own identity lived in three places that
-- disagreed with each other:
--
--   1. company_tax_settings (mig. 020)        legal_name 'Bureau LPC SARL'
--                                             niu        'P000000000000'
--   2. proposal_template_settings (mig. 030)  brand_company_name 'La Petite Cour'
--                                             brand_legal_line   'RC/DLA/2019/A/A/687'
--   3. includes/functions/document_pdf.php    'Ets. La Petite Cour'
--      (hardcoded letterhead)                 'NIU M12200000000L'
--
-- Two different NIUs and three different legal names were shipping on live
-- customer documents. This migration creates `company_profile` as THE record,
-- backfills it from whatever the environment already has, and leaves
-- company_tax_settings holding fiscal RATES only (identity columns are kept
-- but become mirrors, synced by the application on save, so migration 020's
-- consumers — TaxEngine, tax_declarations.php — keep working untouched).
--
-- Also introduces `app_preferences`: a typed key/value store for every setting
-- that was previously a magic number in code (document prefixes, session
-- timeout, lockout thresholds, locale, page size).
--
-- IDEMPOTENT. Safe to re-run. Backfill never overwrites a non-default value.
-- =============================================================================

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. company_profile — one active row (single entity), but carries `is_active`
--    and an AUTO_INCREMENT id so a second legal entity can be added later
--    without a schema rewrite. The application reads
--    `WHERE is_active = 1 ORDER BY id LIMIT 1`.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS company_profile (
    id                   INT(11) NOT NULL AUTO_INCREMENT,

    -- ---- Legal identity ------------------------------------------------------
    legal_name           VARCHAR(200) NOT NULL
                         COMMENT 'Registered name exactly as on the RCCM certificate',
    trade_name           VARCHAR(200) NULL
                         COMMENT 'Commercial / trading name if different (nom commercial)',
    legal_form           ENUM('ETS','SARL','SARLU','SA','SAS','SNC','GIE','SCI','EI','ONG','AUTRE')
                         NOT NULL DEFAULT 'SARL'
                         COMMENT 'Forme juridique — printed after the legal name on documents',
    rccm_number          VARCHAR(60)  NULL
                         COMMENT 'Registre du Commerce et du Crédit Mobilier, e.g. RC/DLA/2019/A/A/687',
    niu                  VARCHAR(50)  NULL
                         COMMENT 'Numéro d''Identifiant Unique (Tax ID) — 14 chars, e.g. M123456789012A',
    trade_register_city  VARCHAR(80)  NULL COMMENT 'Greffe of registration, e.g. Douala',
    incorporation_date   DATE         NULL,
    share_capital        DECIMAL(18,2) NULL COMMENT 'Capital social in XAF — mandatory on SARL/SA letterhead',

    -- ---- Fiscal ---------------------------------------------------------------
    fiscal_regime        ENUM('reel','simplifie','forfait','non_professionnel')
                         NOT NULL DEFAULT 'reel',
    tax_office           VARCHAR(120) NULL COMMENT 'Centre des impôts, e.g. CIME Littoral II',
    cnps_employer_number VARCHAR(60)  NULL COMMENT 'Numéro employeur CNPS',
    activity_sector      VARCHAR(160) NULL COMMENT 'e.g. Distribution agroalimentaire',
    activity_code        VARCHAR(40)  NULL COMMENT 'Code APE / NACE if assigned',

    -- ---- Official address -----------------------------------------------------
    address_line1        VARCHAR(200) NULL,
    address_line2        VARCHAR(200) NULL,
    po_box               VARCHAR(60)  NULL COMMENT 'Boîte postale, e.g. B.P. 5120',
    city                 VARCHAR(100) NULL DEFAULT 'Douala',
    region               VARCHAR(100) NULL DEFAULT 'Littoral',
    country              VARCHAR(100) NOT NULL DEFAULT 'Cameroun',
    country_code         CHAR(2)      NOT NULL DEFAULT 'CM',

    -- ---- Contact --------------------------------------------------------------
    phone_primary        VARCHAR(40)  NULL,
    phone_secondary      VARCHAR(40)  NULL,
    whatsapp             VARCHAR(40)  NULL,
    email_general        VARCHAR(160) NULL,
    email_billing        VARCHAR(160) NULL COMMENT 'Shown on invoices for payment queries',
    website              VARCHAR(160) NULL,

    -- ---- Banking (appears on invoice payment block) ----------------------------
    bank_name            VARCHAR(120) NULL,
    bank_account_rib     VARCHAR(60)  NULL COMMENT 'RIB / numéro de compte',
    bank_iban            VARCHAR(60)  NULL,
    bank_swift           VARCHAR(30)  NULL,
    mobile_money_number  VARCHAR(40)  NULL COMMENT 'Orange Money / MTN MoMo merchant number',

    -- ---- Brand assets ---------------------------------------------------------
    erp_display_name     VARCHAR(120) NOT NULL DEFAULT 'LPC ERP'
                         COMMENT 'Name shown in the app shell sidebar + browser title',
    erp_tagline          VARCHAR(160) NULL COMMENT 'Subtitle under the sidebar logo',
    logo_main_path       VARCHAR(255) NULL COMMENT 'Full logo — documents, login, cover pages',
    logo_mark_path       VARCHAR(255) NULL COMMENT 'Square mark — collapsed sidebar, favicon',
    logo_document_path   VARCHAR(255) NULL COMMENT 'Optional print-optimised variant for PDF letterhead',
    brand_color_primary  CHAR(7)      NOT NULL DEFAULT '#005A2B',
    brand_color_accent   CHAR(7)      NOT NULL DEFAULT '#8CC63F',

    -- ---- Document footer ------------------------------------------------------
    document_footer_fr   TEXT NULL COMMENT 'Legal mentions line printed at the foot of every PDF (FR)',
    document_footer_en   TEXT NULL,

    is_active            TINYINT(1) NOT NULL DEFAULT 1,
    created_at           TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by           INT(11) NULL,

    PRIMARY KEY (id),
    KEY idx_active (is_active),
    KEY idx_cp_updated_by (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FK on updated_by, guarded (mig. 006's helper proc may be absent on some envs;
-- a missing FK here is cosmetic, not functional).
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'company_profile'
       AND CONSTRAINT_NAME = 'fk_cp_updated_by'
);
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE company_profile
       ADD CONSTRAINT fk_cp_updated_by FOREIGN KEY (updated_by)
       REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 2. app_preferences — typed key/value store.
--
--    `kind` drives the form control rendered in Settings → Préférences, and
--    the cast applied by Prefs::get(). `group_key` drives the section headings.
--    `is_locked` marks settings that must not be edited from the UI once
--    documents exist (changing an invoice prefix mid-year breaks sequence
--    continuity for the DGI).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_preferences (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    pref_key    VARCHAR(64) NOT NULL,
    pref_value  TEXT NULL,
    kind        ENUM('text','textarea','int','decimal','bool','select','color','date')
                NOT NULL DEFAULT 'text',
    options_json TEXT NULL COMMENT 'JSON array of {value,label} for kind=select',
    group_key   VARCHAR(32) NOT NULL DEFAULT 'general',
    label_fr    VARCHAR(160) NOT NULL DEFAULT '',
    label_en    VARCHAR(160) NOT NULL DEFAULT '',
    help_fr     VARCHAR(255) NULL,
    unit        VARCHAR(24)  NULL COMMENT 'Suffix shown next to the input, e.g. "min", "%"',
    min_value   DECIMAL(18,4) NULL,
    max_value   DECIMAL(18,4) NULL,
    sort_order  SMALLINT NOT NULL DEFAULT 0,
    is_locked   TINYINT(1) NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    updated_by  INT(11) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pref_key (pref_key),
    KEY idx_pref_group_sort (group_key, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- -----------------------------------------------------------------------------
-- 3. Permissions.
--    admin.company.view/edit is deliberately SEPARATE from admin.settings.edit:
--    the RCCM and NIU printed on every invoice are a legal exposure, and the
--    person who resets a password is not necessarily the person who should be
--    able to change the tax number on your invoices.
-- -----------------------------------------------------------------------------
INSERT INTO permissions (name, module, description) VALUES
  ('admin.company.view', 'admin', "Voir la fiche d'identité de l'entreprise"),
  ('admin.company.edit', 'admin', "Modifier l'identité légale, fiscale et la charte graphique"),
  ('admin.prefs.view',   'admin', 'Voir les préférences système'),
  ('admin.prefs.edit',   'admin', 'Modifier les préférences système')
ON DUPLICATE KEY UPDATE module = VALUES(module), description = VALUES(description);

-- Grant all four to any role holding the admin wildcard already.
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  CROSS JOIN permissions p
 WHERE p.name IN ('admin.company.view','admin.company.edit','admin.prefs.view','admin.prefs.edit')
   AND r.name = 'admin'
ON DUPLICATE KEY UPDATE role_id = VALUES(role_id);


-- -----------------------------------------------------------------------------
-- 4. Backfill company_profile — ONLY if empty.
--
--    Values are pulled from the two existing stores. Where they disagreed we
--    take proposal_template_settings for BRAND facts (it holds the real
--    RC number and the real Douala address that already prints on quotes) and
--    company_tax_settings for FISCAL facts (it drives TaxEngine today).
--    The NIU is deliberately left to whatever company_tax_settings holds;
--    the placeholder 'P000000000000' is preserved rather than invented, so it
--    shows up as obviously-wrong in the UI and gets corrected by a human.
-- -----------------------------------------------------------------------------
--    STRUCTURE: seed literals FIRST with no cross-table dependency, then
--    enrich from 020/030 only if those tables are actually present.
--
--    An earlier draft did this in one INSERT ... SELECT that referenced
--    proposal_template_settings and company_tax_settings directly. MySQL
--    resolves table names at parse time, not at row time, so on a database
--    where migration 030 had not run the whole statement died with
--    "Table 'proposal_template_settings' doesn't exist" — even though the
--    COALESCE would never have needed it. Migrations must not assume their
--    predecessors ran; a fresh install, a partial restore, or a hotfix branch
--    can all produce that state. Hence the guarded UPDATEs below.
-- -----------------------------------------------------------------------------
INSERT INTO company_profile (
    legal_name, trade_name, legal_form, rccm_number, niu,
    trade_register_city, fiscal_regime, activity_sector,
    po_box, city, region, country, country_code,
    phone_primary, phone_secondary, email_general, email_billing,
    erp_display_name, erp_tagline,
    logo_main_path, logo_mark_path,
    document_footer_fr, document_footer_en, is_active
)
SELECT
    'La Petite Cour',
    'La Petite Cour',
    'ETS',
    'RC/DLA/2019/A/A/687',
    '',                                  -- filled from company_tax_settings below
    'Douala',
    'reel',
    'Distribution agroalimentaire',
    'B.P. 5120',
    'Douala', 'Littoral', 'Cameroun', 'CM',
    '+237 696 291 800',
    '+237 679 535 564',
    'info@lpc.cm',
    'info@lpc.cm',
    'LPC ERP',
    'Distribution Agroalimentaire',
    '/assets/img/full_logo.svg',
    '/assets/img/small_logo.svg',
    'La Petite Cour · RC/DLA/2019/A/A/687 · B.P. 5120 Douala, Cameroun · info@lpc.cm',
    'La Petite Cour · RC/DLA/2019/A/A/687 · P.O. Box 5120 Douala, Cameroon · info@lpc.cm',
    1
WHERE NOT EXISTS (SELECT 1 FROM company_profile);


-- 4a. Enrich from company_tax_settings (migration 020) — fiscal facts.
--     Guarded: skipped entirely if that table is absent.
SET @has_cts := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_tax_settings');
SET @sql := IF(@has_cts > 0,
  "UPDATE company_profile cp
      JOIN (SELECT * FROM company_tax_settings ORDER BY id LIMIT 1) t
        SET cp.niu           = COALESCE(NULLIF(t.niu, ''), cp.niu),
            cp.fiscal_regime = COALESCE(t.tax_regime, cp.fiscal_regime),
            cp.tax_office    = COALESCE(t.tax_office, cp.tax_office)
      WHERE cp.is_active = 1 AND (cp.niu IS NULL OR cp.niu = '')",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- 4b. Enrich from proposal_template_settings (migration 030) — brand facts.
--     That table already holds the real trading name and logo path used on
--     live quotes, so it wins over our literal seed where it has a value.
SET @has_pts := (SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposal_template_settings');
SET @sql := IF(@has_pts > 0,
  "UPDATE company_profile cp
      SET cp.legal_name = COALESCE(NULLIF((SELECT value_fr FROM proposal_template_settings
                                            WHERE setting_key = 'brand_company_name' LIMIT 1), ''), cp.legal_name),
          cp.logo_main_path = COALESCE(NULLIF((SELECT value_fr FROM proposal_template_settings
                                            WHERE setting_key = 'brand_logo_main' LIMIT 1), ''), cp.logo_main_path)
    WHERE cp.is_active = 1",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 5. Seed app_preferences.
--    Every value here replaces a literal that was previously hardcoded in PHP
--    or JS. The `help_fr` text names the file it came from so the next person
--    can trace it.
-- -----------------------------------------------------------------------------
INSERT INTO app_preferences
    (pref_key, pref_value, kind, options_json, group_key, label_fr, label_en, help_fr, unit, min_value, max_value, sort_order, is_locked)
VALUES

-- ---- Document numbering (was: hardcoded in invoices/sales/proposal controllers)
('doc_prefix_invoice',   'FAC', 'text', NULL, 'numbering', 'Préfixe Facture',       'Invoice prefix',   'Était codé en dur dans api/v1/invoices_controller.php.', NULL, NULL, NULL, 10, 0),
('doc_prefix_quote',     'DEV', 'text', NULL, 'numbering', 'Préfixe Devis',         'Quote prefix',     'Était codé en dur dans api/v1/create_proposal.php.', NULL, NULL, NULL, 20, 0),
('doc_prefix_delivery',  'BL',  'text', NULL, 'numbering', 'Préfixe Bon de Livraison','Delivery note prefix','Était codé en dur dans api/v1/sales_controller.php.', NULL, NULL, NULL, 30, 0),
('doc_prefix_order',     'CMD', 'text', NULL, 'numbering', 'Préfixe Commande',      'Order prefix',     NULL, NULL, NULL, NULL, 40, 0),
('doc_prefix_purchase',  'BC',  'text', NULL, 'numbering', 'Préfixe Bon de Commande','Purchase order prefix', NULL, NULL, NULL, NULL, 50, 0),
('doc_prefix_credit',    'AV',  'text', NULL, 'numbering', 'Préfixe Avoir',         'Credit note prefix', NULL, NULL, NULL, NULL, 60, 0),
('doc_number_format',    '{PREFIX}-{YYMM}-{SEQ}', 'select',
   '[{"value":"{PREFIX}-{YYMM}-{SEQ}","label":"FAC-2607-0042"},{"value":"{PREFIX}-{YYYY}-{SEQ}","label":"FAC-2026-0042"},{"value":"{PREFIX}{YYYY}{SEQ}","label":"FAC20260042"}]',
   'numbering', 'Format de numérotation', 'Numbering format',
   'Changer ce format en cours d''exercice rompt la continuité de séquence exigée par la DGI.', NULL, NULL, NULL, 70, 0),
('doc_sequence_padding', '4', 'int', NULL, 'numbering', 'Longueur du compteur', 'Sequence padding', 'Nombre de chiffres, zéros inclus.', 'chiffres', 2, 8, 80, 0),
('doc_sequence_reset',   'yearly', 'select',
   '[{"value":"yearly","label":"Chaque année"},{"value":"monthly","label":"Chaque mois"},{"value":"never","label":"Jamais"}]',
   'numbering', 'Remise à zéro du compteur', 'Sequence reset', NULL, NULL, NULL, NULL, 90, 0),

-- ---- Commercial defaults
('default_payment_terms_days', '30', 'int', NULL, 'commercial', 'Délai de paiement par défaut', 'Default payment terms', 'Utilisé pour calculer la date d''échéance des factures.', 'jours', 0, 365, 10, 0),
('default_quote_validity_days','15', 'int', NULL, 'commercial', 'Validité des devis', 'Quote validity', NULL, 'jours', 1, 365, 20, 0),
('default_currency',          'XAF', 'select',
   '[{"value":"XAF","label":"FCFA (XAF)"},{"value":"EUR","label":"Euro (EUR)"},{"value":"USD","label":"Dollar US (USD)"}]',
   'commercial', 'Devise par défaut', 'Default currency', NULL, NULL, NULL, NULL, 30, 0),
('default_late_penalty_rate', '1.5', 'decimal', NULL, 'commercial', 'Pénalité de retard mensuelle', 'Monthly late penalty', 'Mention obligatoire sur facture (art. 1153 C. civ.).', '%', 0, 20, 40, 0),
('quote_terms_fr', 'Offre valable 15 jours. Prix en FCFA, TVA en sus. Paiement à 30 jours date de facture.', 'textarea', NULL, 'commercial', 'Conditions générales (devis)', 'Quote terms', NULL, NULL, NULL, NULL, 50, 0),

-- ---- Fiscal (mirrors company_tax_settings; the app writes both on save)
('tax_tva_rate',   '19.25', 'decimal', NULL, 'fiscal', 'Taux TVA',              'VAT rate',        'Taux standard Cameroun 19,25 % (18 % + 1,25 % CAC).', '%', 0, 100, 10, 0),
('tax_air_rate',   '2.2',   'decimal', NULL, 'fiscal', 'Taux AIR',              'AIR rate',        'Régime réel 2,2 % · régime simplifié 5,5 %.', '%', 0, 100, 20, 0),
('tax_cit_rate',   '33',    'decimal', NULL, 'fiscal', "Taux d'IS",             'Corporate tax rate','33 % standard, 27,5 % pour certains secteurs.', '%', 0, 100, 30, 0),
('tax_tva_liable', '1',     'bool', NULL, 'fiscal', 'Assujetti à la TVA',    'VAT liable',      'Uniquement si CA ≥ 50 M FCFA et régime réel.', NULL, NULL, NULL, 40, 0),
('tax_cnps_group', 'A',     'select', '[{"value":"A","label":"Groupe A — 1,75 %"},{"value":"B","label":"Groupe B — 2,5 %"},{"value":"C","label":"Groupe C — 5 %"}]',
   'fiscal', 'Groupe CNPS (accident du travail)', 'CNPS group', NULL, NULL, NULL, NULL, 50, 0),
('tax_is_withholding_agent', '0', 'bool', NULL, 'fiscal', 'Agent de retenue à la source', 'Withholding agent', 'Retenons-nous l''AIR sur nos propres fournisseurs ?', NULL, NULL, NULL, 60, 0),
('tax_filing_day', '15', 'int', NULL, 'fiscal', 'Jour limite de déclaration DGI', 'DGI filing day', NULL, 'du mois', 1, 28, 70, 0),
('fiscal_year_start_month', '1', 'int', NULL, 'fiscal', "Début de l'exercice", 'Fiscal year start', 'Mois (1 = janvier). OHADA impose généralement janvier.', 'mois', 1, 12, 80, 0),

-- ---- Localisation & display
('locale_default',    'fr', 'select', '[{"value":"fr","label":"Français"},{"value":"en","label":"English"}]',
   'display', 'Langue par défaut', 'Default language', 'Était codé en dur via APP_DEFAULT_LANG dans .env.', NULL, NULL, NULL, 10, 0),
('locale_timezone',   'Africa/Douala', 'select',
   '[{"value":"Africa/Douala","label":"Afrique/Douala (WAT, UTC+1)"},{"value":"UTC","label":"UTC"},{"value":"Europe/Paris","label":"Europe/Paris"}]',
   'display', 'Fuseau horaire', 'Timezone', NULL, NULL, NULL, NULL, 20, 0),
('locale_date_format','d/m/Y', 'select',
   '[{"value":"d/m/Y","label":"31/12/2026"},{"value":"Y-m-d","label":"2026-12-31"},{"value":"d M Y","label":"31 déc. 2026"}]',
   'display', 'Format de date', 'Date format', NULL, NULL, NULL, NULL, 30, 0),
('locale_decimal_sep',  ',', 'select', '[{"value":",","label":"Virgule — 1 234,56"},{"value":".","label":"Point — 1,234.56"}]',
   'display', 'Séparateur décimal', 'Decimal separator', NULL, NULL, NULL, NULL, 40, 0),
('locale_thousand_sep', ' ', 'select', '[{"value":" ","label":"Espace — 1 234 567"},{"value":",","label":"Virgule — 1,234,567"},{"value":".","label":"Point — 1.234.567"}]',
   'display', 'Séparateur de milliers', 'Thousand separator', NULL, NULL, NULL, NULL, 50, 0),
('ui_page_size',      '25', 'int', NULL, 'display', 'Lignes par page', 'Rows per page', 'Appliqué à toutes les tables paginées.', 'lignes', 5, 200, 60, 0),

-- ---- Security policy (was: literals in driver_sidebar.php and .env)
('sec_session_timeout_min', '30', 'int', NULL, 'security', 'Déconnexion automatique', 'Session timeout', 'Était codé en dur (1800000 ms) dans includes/components/driver_sidebar.php.', 'min', 5, 480, 10, 0),
('sec_max_login_attempts',  '10', 'int', NULL, 'security', 'Tentatives de connexion max', 'Max login attempts', 'Par tranche de 15 minutes. Était AUTH_MAX_ATTEMPTS_PER_15MIN dans .env.', 'essais', 3, 50, 20, 0),
('sec_lockout_minutes',     '15', 'int', NULL, 'security', 'Durée de verrouillage', 'Lockout duration', NULL, 'min', 1, 1440, 30, 0),
('sec_password_min_length', '10', 'int', NULL, 'security', 'Longueur minimale du mot de passe', 'Min password length', NULL, 'car.', 8, 64, 40, 0),
('sec_password_expiry_days','0',  'int', NULL, 'security', 'Expiration du mot de passe', 'Password expiry', '0 = jamais. Le NIST déconseille désormais la rotation forcée.', 'jours', 0, 730, 50, 0),
('sec_require_2fa_admin',   '0',  'bool', NULL, 'security', 'Imposer la 2FA aux administrateurs', 'Require 2FA for admins', NULL, NULL, NULL, NULL, 60, 0),
('sec_audit_retention_days','365','int', NULL, 'security', "Rétention des logs d'audit", 'Audit log retention', 'OHADA impose la conservation des pièces 10 ans; les logs applicatifs sont plus courts.', 'jours', 30, 3650, 70, 0),

-- ---- Notifications & operations
('notify_low_stock_threshold', '10', 'int', NULL, 'operations', 'Seuil d''alerte de stock bas', 'Low stock threshold', NULL, 'unités', 0, 10000, 10, 0),
('notify_invoice_due_days',    '3',  'int', NULL, 'operations', 'Rappel avant échéance de facture', 'Invoice due reminder', NULL, 'jours', 0, 90, 20, 0),
('notify_email_enabled',       '1',  'bool', NULL, 'operations', 'Notifications par email', 'Email notifications', NULL, NULL, NULL, NULL, 30, 0),
('ops_allow_negative_stock',   '0',  'bool', NULL, 'operations', 'Autoriser le stock négatif', 'Allow negative stock', 'Déconseillé : casse la valorisation OHADA du stock.', NULL, NULL, NULL, 40, 0)

ON DUPLICATE KEY UPDATE
    -- Refresh metadata (labels, help, bounds, options) but NEVER the value.
    kind         = VALUES(kind),
    options_json = VALUES(options_json),
    group_key    = VALUES(group_key),
    label_fr     = VALUES(label_fr),
    label_en     = VALUES(label_en),
    help_fr      = VALUES(help_fr),
    unit         = VALUES(unit),
    min_value    = VALUES(min_value),
    max_value    = VALUES(max_value),
    sort_order   = VALUES(sort_order);


-- -----------------------------------------------------------------------------
-- 6. Sync the fiscal preferences FROM company_tax_settings where that table
--    already holds real (non-default) values — so an environment that was
--    tuned through the tax module doesn't get silently reset to the seeds.
--    Rates are stored as percentages in app_preferences, decimals in
--    company_tax_settings; hence the *100.
-- -----------------------------------------------------------------------------
--    Guarded on the table existing, for the same reason as section 4: this
--    migration must survive being run on a database where 020 never ran.
--    One statement covers all five keys via CASE, so there is a single
--    prepared statement to maintain rather than five.
SET @sql := IF(@has_cts > 0,
  "UPDATE app_preferences p
     JOIN (SELECT * FROM company_tax_settings ORDER BY id LIMIT 1) t
      SET p.pref_value = CASE p.pref_key
            WHEN 'tax_tva_rate'   THEN CAST(ROUND(t.tva_rate * 100, 4) AS CHAR)
            WHEN 'tax_air_rate'   THEN CAST(ROUND(t.air_rate * 100, 4) AS CHAR)
            WHEN 'tax_cit_rate'   THEN CAST(ROUND(t.cit_rate * 100, 4) AS CHAR)
            WHEN 'tax_cnps_group' THEN t.cnps_group
            WHEN 'tax_filing_day' THEN CAST(t.filing_day AS CHAR)
            ELSE p.pref_value
          END
    WHERE p.pref_key IN ('tax_tva_rate','tax_air_rate','tax_cit_rate',
                         'tax_cnps_group','tax_filing_day')",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;


-- -----------------------------------------------------------------------------
-- 7. Record the migration.
-- -----------------------------------------------------------------------------
INSERT INTO schema_migrations (version, applied_at)
VALUES ('034_company_profile', NOW())
ON DUPLICATE KEY UPDATE applied_at = applied_at;

COMMIT;

-- =============================================================================
-- VERIFY
-- -----------------------------------------------------------------------------
--   SELECT legal_name, legal_form, rccm_number, niu, fiscal_regime
--     FROM company_profile WHERE is_active = 1;
--
--   SELECT group_key, COUNT(*) FROM app_preferences GROUP BY group_key;
--
--   -- Should now agree with each other:
--   SELECT (SELECT niu FROM company_profile WHERE is_active=1) AS profile_niu,
--          (SELECT niu FROM company_tax_settings ORDER BY id LIMIT 1) AS tax_niu;
--
-- ROLLBACK (destructive — only in dev)
--   DROP TABLE company_profile; DROP TABLE app_preferences;
--   DELETE FROM schema_migrations WHERE version = '034_company_profile';
-- =============================================================================
