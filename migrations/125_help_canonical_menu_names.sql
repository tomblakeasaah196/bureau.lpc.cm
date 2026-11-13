-- =============================================================================
-- 125_help_canonical_menu_names.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — make the Help Centre speak the ONE menu every role now sees.
--
-- WHY
-- ---
-- Client decision (Nov 2026): every role sees the same menu labels — the
-- admin's — and each page title equals its menu label. The per-role
-- vocabularies (accountant « Saisie des Journaux », sales « Tableau de Bord
-- Ventes », …) and several old page titles (« Révision Comptable », « Master
-- Data Hub », « Facturation & Créances », …) are retired. The help articles
-- were seeded with those old names (033 … 106), so they now send readers to
-- menu entries that no longer exist.
--
-- The seed files are NOT edited: they have already run in production and
-- scripts/migrate.php refuses to continue when a recorded file's checksum
-- changes. Articles can also be edited live from /modules/admin/help_articles.php,
-- so this file never assumes the seed text — it only rewrites the specific
-- phrases below wherever they still occur.
--
-- WHAT IS REWRITTEN
-- -----------------
--   help_article_bodies   title, summary, body_md        (FR rows / EN rows)
--   help_article_chunks   chunk_text                     (the AI assistant's
--                         copy of the same text — see "CHUNKS" below)
--   help_categories       title_fr/summary_fr, title_en/summary_en
--
--   keywords is deliberately NOT rewritten: it keeps the old names so a user
--   who still types « révision comptable » or « master data » finds the page.
--
-- ONLY MENU / PAGE NAMES CHANGE
-- -----------------------------
-- A phrase is rewritten only where it names a menu entry or a page. Left
-- alone on purpose:
--   · in-page TABS: « Commandes Clients », « Dispatch & BL » (tabs of the
--     orders page), « Grand Livre & Lettrage », « Stock Actuel », etc.;
--   · documents and concepts: bon de commande, le grand livre d'un compte,
--     la trésorerie, « Direction Générale » (the executive dashboard's
--     subtitle — it stays), « Ma Performance » (sales dashboard subtitle);
--   · informal shorthands that are not retired labels (« module Stock »,
--     « module Facturation », « Admin → Journal d'Erreurs », …);
--   · menu paths that point at no current page (« Paramètres → Trésorerie »,
--     « Comptabilité → Valorisation », …) — no correct target to map to.
--
-- IDEMPOTENCE
-- -----------
-- Every pair below satisfies: the OLD string never occurs inside its NEW
-- string, nor inside any later pair's output. A second run therefore finds
-- nothing to replace. That is why some pairs carry surrounding context
-- (« **Base Clients** » rather than « Base Clients », which would turn
-- « Base Clients & Devis » into « Base Clients & Devis & Devis »), and why
-- the specific pairs run BEFORE the generic ones (« Le **Tableau de Bord
-- Finance** » must become « Le tableau de bord **Vue Finance** » before a
-- generic pass could leave « Le **Vue Finance** »).
-- REPLACE() is case-sensitive, so lowercase concept uses (« la révision
-- comptable », « contrôle de gestion ») are never touched.
--
-- CHUNKS
-- ------
-- help_article_chunks.chunk_text is the body, split into chunks, that the AI
-- assistant quotes to the model (the title is read live from the bodies table). It gets the same rewrite, so the
-- assistant stops quoting old names immediately. Its EMBEDDINGS are not
-- recomputed here (that needs the provider API), but they need no special
-- handling: help_article_bodies.updated_at is ON UPDATE CURRENT_TIMESTAMP, so
-- every body changed here becomes newer than its chunks' source_updated_at,
-- and scripts/cron/reindex_help_chunks.php re-embeds exactly those bodies on
-- its next run. Unchanged bodies keep their timestamp (MySQL does not bump it
-- when REPLACE() leaves the value identical), so nothing else is re-embedded.
--
-- FULLTEXT (ft_help_body) is maintained by InnoDB on UPDATE — nothing to do.
--
-- Idempotent: safe to re-run.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- 0. Helper: one (lang, old, new) rewrite across every searchable column.
--    Params carry an explicit collation so they never clash with a database
--    whose default collation differs from the tables' utf8mb4_unicode_ci.
-- -----------------------------------------------------------------------------
DELIMITER //
DROP PROCEDURE IF EXISTS lpc_help_rename_125 //
CREATE PROCEDURE lpc_help_rename_125(
    IN p_lang CHAR(2)      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_old  VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
    IN p_new  VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)
BEGIN
    UPDATE help_article_bodies
       SET title   = REPLACE(title,   p_old, p_new),
           summary = REPLACE(summary, p_old, p_new),
           body_md = REPLACE(body_md, p_old, p_new)
     WHERE lang = p_lang
       AND (LOCATE(p_old, title) > 0 OR LOCATE(p_old, summary) > 0
            OR LOCATE(p_old, body_md) > 0);

    UPDATE help_article_chunks
       SET chunk_text = REPLACE(chunk_text, p_old, p_new)
     WHERE lang = p_lang
       AND LOCATE(p_old, chunk_text) > 0;

    IF p_lang = 'fr' THEN
        UPDATE help_categories
           SET title_fr   = REPLACE(title_fr,   p_old, p_new),
               summary_fr = REPLACE(summary_fr, p_old, p_new)
         WHERE LOCATE(p_old, title_fr) > 0 OR LOCATE(p_old, summary_fr) > 0;
    ELSE
        UPDATE help_categories
           SET title_en   = REPLACE(title_en,   p_old, p_new),
               summary_en = REPLACE(summary_en, p_old, p_new)
         WHERE LOCATE(p_old, title_en) > 0 OR LOCATE(p_old, summary_en) > 0;
    END IF;
END//
DELIMITER ;

START TRANSACTION;

-- =============================================================================
-- 1. FRENCH — specific phrases first (context-bearing), generic names after.
-- =============================================================================

-- ---- Stratégie & BI · dashboards -------------------------------------------
CALL lpc_help_rename_125('fr', 'Tableau de Bord Finance — Contrôle financier',       'Vue Finance — Direction Financière');
CALL lpc_help_rename_125('fr', 'Tableau de Bord Opérations — Centre d''opérations',  'Vue Opérations — Direction des Opérations');
CALL lpc_help_rename_125('fr', 'Le **Tableau de Bord Finance**',     'Le tableau de bord **Vue Finance**');
CALL lpc_help_rename_125('fr', 'Le **Tableau de Bord Opérations**',  'Le tableau de bord **Vue Opérations**');
CALL lpc_help_rename_125('fr', 'Le **Tableau de Bord Ventes**',      'Le tableau de bord **Vue Ventes**');
CALL lpc_help_rename_125('fr', 'Tableau de Bord Finance',            'Vue Finance');
CALL lpc_help_rename_125('fr', 'Tableau de Bord Opérations',         'Vue Opérations');
CALL lpc_help_rename_125('fr', 'Tableau de Bord Ventes',             'Vue Ventes');
CALL lpc_help_rename_125('fr', 'Tableau de Bord Ops',                'Vue Opérations');
CALL lpc_help_rename_125('fr', 'Contrôle Financier',                 'Vue Finance');
CALL lpc_help_rename_125('fr', 'Centre d''Opérations',               'Vue Opérations');

-- ---- Commerce & CRM ----------------------------------------------------------
CALL lpc_help_rename_125('fr', 'Découvrir la Base Clients',          'Découvrir Base Clients & Devis');
CALL lpc_help_rename_125('fr', '**Base Clients**',                   '**Base Clients & Devis**');
CALL lpc_help_rename_125('fr', 'La Base Clients s''arrête',          'La page Base Clients & Devis s''arrête');
CALL lpc_help_rename_125('fr', 'La Base Clients **affiche**',        'La page Base Clients & Devis **affiche**');
CALL lpc_help_rename_125('fr', 'sur la Base Clients.',               'sur la page Base Clients & Devis.');
CALL lpc_help_rename_125('fr', 'Base Clients → fiche du client',     'Base Clients & Devis → fiche du client');
CALL lpc_help_rename_125('fr', 'vivent dans **Ventes** et **Comptabilité**.', 'vivent dans **Ventes & Commandes** et **Facturation & AR**.');
CALL lpc_help_rename_125('fr', 'Clients & Prospects',                'Base Clients & Devis');
CALL lpc_help_rename_125('fr', 'Ventes & Dispatch',                  'Ventes & Commandes');
CALL lpc_help_rename_125('fr', 'Commandes & Dispatch',               'Ventes & Commandes');
CALL lpc_help_rename_125('fr', 'Ventes et BL',                       'Ventes & Commandes');

-- ---- Logistique & Stock ------------------------------------------------------
CALL lpc_help_rename_125('fr', '**Achats & Approvisionnement**',     '**Gestion des Achats**');
CALL lpc_help_rename_125('fr', 'Achats & Approvisionnement',         'Gestion des Achats');
CALL lpc_help_rename_125('fr', '**Achats → Dettes fournisseurs**',   '**Gestion des Achats → Dettes fournisseurs**');
CALL lpc_help_rename_125('fr', 'module Achats)',                     'module Gestion des Achats)');
CALL lpc_help_rename_125('fr', 'passe par Achats)',                  'passe par Gestion des Achats)');
CALL lpc_help_rename_125('fr', 'Bons de Commande (SDP)',             'Gestion des Achats');
CALL lpc_help_rename_125('fr', 'ouvre l''**État du Stock** avec',    'ouvre **Stock & Emballages** avec');
CALL lpc_help_rename_125('fr', '| État du Stock, filtré',            '| Stock & Emballages, filtré');
CALL lpc_help_rename_125('fr', 'Consignes & Vides',                  'Gestion des Vides');
CALL lpc_help_rename_125('fr', 'Emballages & Recyclage',             'Gestion des Vides');
CALL lpc_help_rename_125('fr', 'Véhicules & Maintenance',            'Flotte & Maintenance');
CALL lpc_help_rename_125('fr', 'le module Flotte permet',            'le module Flotte & Maintenance permet');

-- ---- Comptabilité & Finance --------------------------------------------------
-- Facturation & AR
CALL lpc_help_rename_125('fr', 'ouvre la **Facturation** avec',      'ouvre **Facturation & AR** avec');
CALL lpc_help_rename_125('fr', '| Facturation, filtrée sur',         '| Facturation & AR, filtrée sur');
CALL lpc_help_rename_125('fr', 'Facturation & Créances',             'Facturation & AR');
CALL lpc_help_rename_125('fr', 'Factures & Paiements',               'Facturation & AR');
CALL lpc_help_rename_125('fr', 'Factures Clients (AR)',              'Facturation & AR');
-- Gestion des Dépenses
CALL lpc_help_rename_125('fr', 'Comptabilité → Gestion des Dépenses', 'Comptabilité & Finance → Gestion des Dépenses');
CALL lpc_help_rename_125('fr', '(module Dépenses)',                  '(module Gestion des Dépenses)');
CALL lpc_help_rename_125('fr', 'Dépenses & Charges',                 'Gestion des Dépenses');
-- Écritures
CALL lpc_help_rename_125('fr', 'Écritures & Journaux',               'Écritures');
CALL lpc_help_rename_125('fr', 'Saisie & Journaux',                  'Écritures');
CALL lpc_help_rename_125('fr', 'Saisie des Journaux',                'Écritures');
-- Grand Livre OHADA
CALL lpc_help_rename_125('fr', 'Révision Comptable',                 'Grand Livre OHADA');
CALL lpc_help_rename_125('fr', 'Comptabilité & Finance → Grand Livre**', 'Comptabilité & Finance → Grand Livre OHADA**');
CALL lpc_help_rename_125('fr', 'via **Grand Livre** sur',            'via **Grand Livre OHADA** sur');
-- Trésorerie & Banque
CALL lpc_help_rename_125('fr', '**Trésorerie → Opérations**',        '**Trésorerie & Banque → Opérations & Transferts**');
CALL lpc_help_rename_125('fr', 'Ouvrir Trésorerie → le compte',      'Ouvrir Trésorerie & Banque → le compte');
CALL lpc_help_rename_125('fr', 'passe par Trésorerie.',              'passe par Trésorerie & Banque.');
CALL lpc_help_rename_125('fr', '» sur Trésorerie écrit',             '» sur Trésorerie & Banque écrit');
CALL lpc_help_rename_125('fr', 'Validations Caisse (EOD)',           'Trésorerie & Banque');
-- Immobilisations
CALL lpc_help_rename_125('fr', 'Immobilisations & Amortissements',   'Immobilisations');
-- Déclarations Fiscales
CALL lpc_help_rename_125('fr', 'Déclarations Fiscales & Sociales',   'Déclarations Fiscales');
CALL lpc_help_rename_125('fr', '**Fiscalité → Déclarations DIPE / TVA**', '**Déclarations Fiscales → DIPE / TVA**');
-- États Financiers
CALL lpc_help_rename_125('fr', 'États Financiers (Bilan)',           'États Financiers');
-- Budgets & Performance
CALL lpc_help_rename_125('fr', '**Contrôle de Gestion → Budgets & Performance**', '**Budgets & Performance**');
CALL lpc_help_rename_125('fr', 'Contrôle de Gestion → Lignes Budgétaires', 'Budgets & Performance → Lignes Budgétaires');
CALL lpc_help_rename_125('fr', 'Budgets & Cibles',                   'Budgets & Performance');
-- Plan Comptable (its own menu entry — no longer under « Paramètres »)
CALL lpc_help_rename_125('fr', 'Paramètres → Plan Comptable',        'Plan Comptable');
CALL lpc_help_rename_125('fr', 'Paramètres → Plan comptable',        'Plan Comptable');

-- ---- Administration & RH -----------------------------------------------------
-- Gestion du Personnel
CALL lpc_help_rename_125('fr', '**RH → Paie & Salaires**',           '**Gestion du Personnel**');
CALL lpc_help_rename_125('fr', 'dans RH → Paie que',                 'dans Gestion du Personnel que');
CALL lpc_help_rename_125('fr', '**Paye & RH**',                      '**Gestion du Personnel**');
CALL lpc_help_rename_125('fr', '(voir *Paie*)',                      '(voir *Gestion du Personnel*)');
CALL lpc_help_rename_125('fr', 'module *Paie*,',                     'module *Gestion du Personnel*,');
CALL lpc_help_rename_125('fr', 'Ressources Humaines & Paie',         'Gestion du Personnel');
CALL lpc_help_rename_125('fr', 'Paie & Acomptes',                    'Gestion du Personnel');
CALL lpc_help_rename_125('fr', 'Paie & Contrats',                    'Gestion du Personnel');
-- Données de Base
CALL lpc_help_rename_125('fr', 'Découvrir le Master Data Hub',       'Découvrir les Données de Base');
CALL lpc_help_rename_125('fr', 'Le **Master Data Hub** est',         'La page **Données de Base** est');
CALL lpc_help_rename_125('fr', 'Le Master Data Hub contient',        'La page Données de Base contient');
CALL lpc_help_rename_125('fr', 'dans le Master Data Hub',            'dans les Données de Base');
CALL lpc_help_rename_125('fr', 'Master Data Hub',                    'Données de Base');
CALL lpc_help_rename_125('fr', '**Admin → Master Data → ',           '**Administration & RH → Données de Base → ');
CALL lpc_help_rename_125('fr', '**Master Data → Produits**',         '**Données de Base → Produits**');
CALL lpc_help_rename_125('fr', 'ajouté en Master Data apparaît',     'ajouté dans Données de Base apparaît');
CALL lpc_help_rename_125('fr', 'Données de Base (GDB)',              'Données de Base');
-- Identité de l'Entreprise / Paramètres Système & Audit
CALL lpc_help_rename_125('fr', 'Administration → **Paramètres** → onglet **Entreprise**.', 'Administration & RH → **Identité de l''Entreprise**.');
CALL lpc_help_rename_125('fr', 'Administration → **Paramètres**.',   'Administration & RH → **Paramètres Système & Audit**.');
CALL lpc_help_rename_125('fr', '**Administration → Entreprise → Régime fiscal & social**', '**Identité de l''Entreprise → Régime fiscal & social**');
CALL lpc_help_rename_125('fr', '**Administration → Préférences → section Fiscal & social**', '**Paramètres Système & Audit → Préférences → section Fiscal & social**');
CALL lpc_help_rename_125('fr', 'Paramètres → Identité de l''Entreprise → Fiscalité', 'Identité de l''Entreprise → Fiscalité');
CALL lpc_help_rename_125('fr', 'Paramètres → Utilisateurs',          'Paramètres Système & Audit → Utilisateurs');
CALL lpc_help_rename_125('fr', 'Paramètres → Préférences',           'Paramètres Système & Audit → Préférences');
CALL lpc_help_rename_125('fr', 'Découvrir le module Administration', 'Découvrir Paramètres Système & Audit');
CALL lpc_help_rename_125('fr', 'Le module **Paramètres** regroupe',  'Le module **Paramètres Système & Audit** regroupe');
CALL lpc_help_rename_125('fr', 'module Paramètres sont filtrés',     'module Paramètres Système & Audit sont filtrés');
CALL lpc_help_rename_125('fr', 'Administration & Configuration',     'Paramètres Système & Audit');
-- Rôles & Permissions lives under the « Administration & RH » section
CALL lpc_help_rename_125('fr', 'Administration → Rôles & Permissions', 'Administration & RH → Rôles & Permissions');

-- =============================================================================
-- 2. ENGLISH — same rules.
-- =============================================================================

-- ---- Strategy & BI · dashboards ----------------------------------------------
CALL lpc_help_rename_125('en', 'Ops Dashboard — Operations Centre',  'Operations View — Operations Department');
CALL lpc_help_rename_125('en', 'The **Finance Dashboard** is',       'The **Finance View** dashboard is');
CALL lpc_help_rename_125('en', 'The **Ops Dashboard** is',           'The **Operations View** dashboard is');
CALL lpc_help_rename_125('en', 'The **Sales Dashboard** answers',    'The **Sales View** dashboard answers');
CALL lpc_help_rename_125('en', 'Finance Dashboard',                  'Finance View');
CALL lpc_help_rename_125('en', 'Ops Dashboard',                      'Operations View');
CALL lpc_help_rename_125('en', 'Sales Dashboard',                    'Sales View');

-- ---- Commerce & CRM ----------------------------------------------------------
-- (not « Getting started with Clients & Quotes »: that contains the old title
--  and a re-run would append « & Quotes » again)
CALL lpc_help_rename_125('en', 'Getting started with Clients',       'Getting started: Clients & Quotes');
CALL lpc_help_rename_125('en', 'The **Clients** page',               'The **Clients & Quotes** page');
CALL lpc_help_rename_125('en', 'Clients page',                       'Clients & Quotes page');
CALL lpc_help_rename_125('en', 'live in **Sales** and **Accounting**.', 'live in **Sales & Orders** and **Invoicing & AR**.');
CALL lpc_help_rename_125('en', 'Sales & Dispatch',                   'Sales & Orders');
CALL lpc_help_rename_125('en', 'Sales & Logistics',                  'Sales & Orders');
CALL lpc_help_rename_125('en', 'Orders & Dispatch',                  'Sales & Orders');
CALL lpc_help_rename_125('en', 'Sales & Delivery Notes',             'Sales & Orders');

-- ---- Logistics & Stock -------------------------------------------------------
CALL lpc_help_rename_125('en', '**Achats & Approvisionnement**',     '**Procurement**');
CALL lpc_help_rename_125('en', 'Achats & Approvisionnement',         'Procurement');
CALL lpc_help_rename_125('en', 'Stock & Empties',                    'Stock & Packaging');
CALL lpc_help_rename_125('en', 'opens **Stock** with',               'opens **Stock & Packaging** with');
CALL lpc_help_rename_125('en', '| Stock, filtered to',               '| Stock & Packaging, filtered to');
CALL lpc_help_rename_125('en', 'Fiche de Stock',                     'Kardex');
CALL lpc_help_rename_125('en', 'Empties & Recycling',                'Empties Management');
CALL lpc_help_rename_125('en', 'Deposits & Empties',                 'Empties Management');
CALL lpc_help_rename_125('en', 'Vehicles & Maintenance',             'Fleet & Maintenance');

-- ---- Accounting & Finance ----------------------------------------------------
CALL lpc_help_rename_125('en', 'opens **Invoicing** with',           'opens **Invoicing & AR** with');
CALL lpc_help_rename_125('en', '| Invoicing, filtered to',           '| Invoicing & AR, filtered to');
CALL lpc_help_rename_125('en', 'Invoicing & Receivables',            'Invoicing & AR');
CALL lpc_help_rename_125('en', 'Billing & Receivables',              'Invoicing & AR');
CALL lpc_help_rename_125('en', 'Invoices & Payments',                'Invoicing & AR');
CALL lpc_help_rename_125('en', 'Customer Invoices (AR)',             'Invoicing & AR');
CALL lpc_help_rename_125('en', '*Accounting → Expenses*',            '*Accounting & Finance → Expenses Management*');
CALL lpc_help_rename_125('en', '**Accounting → Expenses**',          '**Accounting & Finance → Expenses Management**');
CALL lpc_help_rename_125('en', 'Expenses & Charges',                 'Expenses Management');
CALL lpc_help_rename_125('en', 'Entry & Journals',                   'Journal Entries');
CALL lpc_help_rename_125('en', 'Accounting Review',                  'OHADA Ledger');
CALL lpc_help_rename_125('en', 'Cash Validation (EOD)',              'Treasury & Bank');
CALL lpc_help_rename_125('en', 'Fixed Assets & Depreciation',        'Fixed Assets');
CALL lpc_help_rename_125('en', 'Assets & Depreciation',              'Fixed Assets');
CALL lpc_help_rename_125('en', 'Tax & Social Declarations',          'Tax Declarations');
CALL lpc_help_rename_125('en', 'Tax & Social Security Returns',      'Tax Declarations');
CALL lpc_help_rename_125('en', 'Management Control',                 'Budgets & Performance');
CALL lpc_help_rename_125('en', 'Budgets & Targets',                  'Budgets & Performance');

-- ---- Administration & HR -----------------------------------------------------
CALL lpc_help_rename_125('en', 'Human Resources & Payroll',          'Staff Management');
CALL lpc_help_rename_125('en', 'Payroll & Advances',                 'Staff Management');
CALL lpc_help_rename_125('en', 'Payroll & Contracts',                'Staff Management');
CALL lpc_help_rename_125('en', 'Getting to know the Master Data Hub', 'Getting to know Master Data');
CALL lpc_help_rename_125('en', 'The **Master Data Hub** is',         'The **Master Data** page is');
CALL lpc_help_rename_125('en', 'The Master Data Hub carries',        'The Master Data page carries');
CALL lpc_help_rename_125('en', 'the Master Data Hub Fleet tab',      'the Master Data Fleet tab');
CALL lpc_help_rename_125('en', 'the Master Data Hub tab',            'the Master Data tab');
CALL lpc_help_rename_125('en', 'in the Master Data Hub,',            'in Master Data,');
CALL lpc_help_rename_125('en', 'from the Master Data Hub',           'from Master Data');
CALL lpc_help_rename_125('en', 'Master Data Hub',                    'Master Data');
CALL lpc_help_rename_125('en', 'Administration → **Settings** → **Company** tab.', 'Administration & HR → **Company Identity**.');
CALL lpc_help_rename_125('en', 'Administration → **Settings**.',     'Administration & HR → **System Settings & Audit**.');
CALL lpc_help_rename_125('en', 'Getting to know the Administration module', 'Getting to know System Settings & Audit');
CALL lpc_help_rename_125('en', 'The **Settings** module gathers',    'The **System Settings & Audit** module gathers');
CALL lpc_help_rename_125('en', 'Settings → Users',                   'System Settings & Audit → Users');
CALL lpc_help_rename_125('en', 'Settings → Preferences',             'System Settings & Audit → Preferences');
CALL lpc_help_rename_125('en', 'Administration & Configuration',     'System Settings & Audit');
CALL lpc_help_rename_125('en', 'Administration → Roles & Permissions', 'Administration & HR → Roles & Permissions');
CALL lpc_help_rename_125('en', 'Getting started with the Error Log', 'Getting started with the Error Monitor');
CALL lpc_help_rename_125('en', 'The **Error Log** page',             'The **Error Monitor** page');
CALL lpc_help_rename_125('en', 'the Error Log screen',               'the Error Monitor screen');

-- =============================================================================
-- 3. CATEGORY TITLES that name a page but match no generic pair above.
--    Exact-match guards: a title an admin has since renamed by hand is left
--    exactly as they wrote it.
-- =============================================================================
UPDATE help_categories SET title_en = 'Kardex'                      WHERE title_en = 'Stock Card';
UPDATE help_categories SET title_fr = 'Facturation & AR'            WHERE title_fr = 'Facturation & Caisse';
UPDATE help_categories SET title_en = 'Invoicing & AR'              WHERE title_en = 'Invoicing & Cash';
UPDATE help_categories SET title_fr = 'Gestion des Vides'           WHERE title_fr = 'Consignes & Recyclage';
UPDATE help_categories SET title_en = 'Error Monitor & Diagnostics' WHERE title_en = 'Error Log & Diagnostics';
UPDATE help_categories SET title_en = 'Journal Entries'             WHERE title_en = 'Journal Entries & Ledgers';
UPDATE help_categories SET title_en = 'OHADA Ledger'                WHERE title_en = 'Trial Balance & Ledger Review';
UPDATE help_categories SET title_en = 'Treasury & Bank'             WHERE title_en = 'Treasury & Banking';
UPDATE help_categories SET title_en = 'Expenses Management'         WHERE title_en = 'Expense Management';
UPDATE help_categories SET title_fr = 'Budgets & Performance'       WHERE title_fr = 'Budget & Performance';
UPDATE help_categories SET title_en = 'Budgets & Performance'       WHERE title_en = 'Budget & Performance';
UPDATE help_categories SET title_fr = 'Paramètres Système & Audit'  WHERE title_fr = 'Administration, Sécurité & Préférences';
UPDATE help_categories SET title_en = 'System Settings & Audit'     WHERE title_en = 'Administration, Security & Preferences';

COMMIT;

DROP PROCEDURE IF EXISTS lpc_help_rename_125;

-- =============================================================================
-- Verification (run manually after this file):
--
--   SELECT article_id, lang, title FROM help_article_bodies
--    WHERE CONCAT(title, body_md) COLLATE utf8mb4_bin LIKE '%Révision Comptable%'
--       OR CONCAT(title, body_md) COLLATE utf8mb4_bin LIKE '%Master Data Hub%'
--       OR CONCAT(title, body_md) COLLATE utf8mb4_bin LIKE '%Facturation & Créances%'
--       OR CONCAT(title, body_md) COLLATE utf8mb4_bin LIKE '%Ventes & Dispatch%';
--   -- expect 0 rows
--
--   SELECT COUNT(*) FROM help_article_chunks
--    WHERE chunk_text COLLATE utf8mb4_bin LIKE '%Master Data Hub%';
--   -- expect 0
--
--   SELECT slug, title_fr, title_en FROM help_categories ORDER BY sort_order;
--   -- e.g. revision-comptable → Grand Livre OHADA / OHADA Ledger,
--   --      master-data-hub    → Données de Base / Master Data
--
-- The AI assistant's embeddings for the rewritten articles are refreshed by
--   php scripts/cron/reindex_help_chunks.php
-- (nightly cron, or by hand now). Its chunk_text is already corrected above.
-- =============================================================================
