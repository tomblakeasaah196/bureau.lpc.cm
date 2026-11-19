-- =============================================================================
-- 160_cnps_group_and_opening_tiers.sql
-- -----------------------------------------------------------------------------
-- The owner's answers 25 and 26 (19/11/2026; register owner table 33 and 34).
--
-- 25. The CNPS accident-risk group is a setting the payroll always reads.
--     LPC is in group A. Payroll::cnpsEmployerParts() builds the employer's
--     rate on every calculation: PVID 4,2 % + prestations familiales 7 % + the
--     accident du travail of the group set in Paramètres (« Groupe CNPS »,
--     tax_cnps_group; the group's rate in tax_at_rate_a/b/c). Group A: 12,95 %.
--       app_preferences       tax_cnps_group = 'A', with a help text that says so
--       company_tax_settings  cnps_group = 'A' (the declarations' mirror)
--       payroll_rates         the stored cnps_employer_rate (16,2 %, group C)
--                             is removed: nothing reads it any more; the PVID
--                             and PF parts get their own rows
--     Payslips already calculated keep the amounts they were calculated with
--     (live data is test data, the owner's answer 16).
--
-- 26. Opening debts per client and supplier (register 11.6): a client's or a
--     supplier's opening balance keyed on its sub-account in Bilan
--     d'Ouverture becomes a « solde d'ouverture » document to collect or pay.
--       invoices.is_opening, purchase_orders.is_opening
--     Help: a new article « Saisir le Bilan d'Ouverture », and the payroll's
--     and the preferences' articles.
--
-- Re-runnable: guarded ADD COLUMN, keyed updates, INSERT IGNORE.
-- =============================================================================

SET NAMES utf8mb4;

-- 25. The CNPS group ------------------------------------------------------------
UPDATE app_preferences
   SET pref_value = 'A',
       help_fr    = "LPC est en groupe A (risque faible). La paie lit ce groupe à chaque calcul : part patronale CNPS = PVID 4,2 % + prestations familiales 7 % + accident du travail du groupe (A 1,75 %, B 2,5 %, C 5 %). Les déclarations CNPS lisent le même groupe."
 WHERE pref_key = 'tax_cnps_group';

UPDATE company_tax_settings SET cnps_group = 'A';

DELETE FROM payroll_rates WHERE rate_key = 'cnps_employer_rate';

INSERT IGNORE INTO payroll_rates (year, rate_key, rate_value)
SELECT y.year, k.rate_key, k.rate_value
  FROM (SELECT DISTINCT year FROM payroll_rates) y
  JOIN (SELECT 'cnps_pvid_employer_rate' AS rate_key, 0.042 AS rate_value UNION ALL
        SELECT 'cnps_pf_rate', 0.070) k;

-- 26. Opening documents -----------------------------------------------------------
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'is_opening');
SET @s := IF(@c = 0,
    "ALTER TABLE invoices ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Solde d''ouverture from Bilan d''Ouverture (no sale behind it)'",
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'purchase_orders' AND column_name = 'is_opening');
SET @s := IF(@c = 0,
    "ALTER TABLE purchase_orders ADD COLUMN is_opening TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Solde d''ouverture from Bilan d''Ouverture (no reception behind it)'",
    'SELECT 1');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Help ------------------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       '| **CNPS patronale** | 16,2 % du brut plafonné |',
       '| **CNPS patronale** | PVID 4,2 % + prestations familiales 7 % + accident du travail du groupe CNPS de Paramètres (groupe A : 1,75 %, soit 12,95 % du brut plafonné) |')
 WHERE a.slug = 'paie-moteur-de-calcul-cameroun-2026' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md, '(CNPS 16,2 % + CFC 1,5 %)', '(CNPS patronale + CFC 1,5 %)')
 WHERE a.slug = 'faq-paie-erreur-serveur-au-valider' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
       '| Groupe CNPS | A | Accident du travail : A 1,75 %, B 2,5 %, C 5 % |',
       '| Groupe CNPS | A | Accident du travail : A 1,75 %, B 2,5 %, C 5 %. La paie le lit à chaque calcul (part patronale = 4,2 % + 7 % + ce taux) |')
 WHERE a.slug = 'prefs-fiscalite-taux' AND b.lang = 'fr';

INSERT IGNORE INTO help_articles (category_id, slug, required_permission, kind, page_path, sort_order, is_published, created_at, updated_at)
SELECT a.category_id, 'bilan-d-ouverture-saisir', 'accounting.chart.view', 'article',
       '/modules/accounting/opening_balance.php', 15, 1, NOW(), NOW()
  FROM help_articles a WHERE a.slug = 'demarrer-ecritures';

INSERT IGNORE INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md, updated_at)
SELECT a.id, 'fr', "Saisir le Bilan d'Ouverture",
       "Les soldes du jour où LPC démarre dans l'ERP : comptes, trésorerie, et ce que doit chaque client et chaque fournisseur.",
       "bilan d'ouverture, soldes, démarrage, 411, 401, solde d'ouverture, lettrage",
       "Le **Bilan d'Ouverture** reprend les soldes du jour où LPC démarre dans l'ERP. Il se saisit une fois, au premier janvier de l'exercice, tant que rien d'autre n'est passé cette année-là.

## Saisir

Chaque compte a sa ligne : un montant au débit ou au crédit. Le total des débits doit égaler celui des crédits.

- La **trésorerie** : chaque banque, caisse et Mobile Money a son sous-compte (5211, 5711, 5521…). Son solde devient celui du compte dans Trésorerie & Banque.
- **Chaque client** a son sous-compte 411 : ce qu'il doit à LPC se saisit au débit, sur sa ligne.
- **Chaque fournisseur** a son sous-compte 401 : ce que LPC lui doit se saisit au crédit, sur sa ligne.

## Ce que deviennent les soldes clients et fournisseurs

À l'enregistrement :

- ce qu'un client doit devient une facture **Solde d'ouverture** (OUV-année-C…) dans Facturation : elle compte dans la Balance Âgée, dans le solde du client, et se paie comme une autre facture, en une ou plusieurs fois ;
- ce que LPC doit à un fournisseur devient une dette **Solde d'ouverture** (OUV-année-F…) dans Gestion des Achats, onglet des dettes : elle se règle par « Payer Fournisseur », en une fois.

Ces documents ne passent pas d'écriture : le Bilan d'Ouverture a déjà mis le solde au Grand Livre. Quand le solde est réglé, la ligne d'ouverture et les paiements sont **lettrés** ensemble sur le sous-compte du tiers. La Balance des Tiers les montre comme toute autre dette.

## Corriger

Tant que l'exercice ne contient que l'ouverture, on peut la modifier et l'enregistrer à nouveau : les documents d'ouverture sont refaits. Ensuite, une correction passe par une OD dans Écritures.

> [!note] Les exercices suivants n'ont pas de Bilan d'Ouverture à saisir : la clôture définitive passe les À-nouveaux, et les factures et commandes encore ouvertes le restent.", NOW()
  FROM help_articles a WHERE a.slug = 'bilan-d-ouverture-saisir';

INSERT IGNORE INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.opening_balance', a.id, 10 FROM help_articles a WHERE a.slug = 'bilan-d-ouverture-saisir';

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('paie-moteur-de-calcul-cameroun-2026', 'faq-paie-erreur-serveur-au-valider', 'prefs-fiscalite-taux')
   AND c.chunk_index = 0;
