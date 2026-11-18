-- =============================================================================
-- 158_help_comptabilite_3.sql
-- -----------------------------------------------------------------------------
-- Help for Comptabilité 3 (Déclarations Fiscales, États Financiers, Clôture),
-- after the training video's QA (18/11/2026, migration 157):
--
--   declarations-approbation-et-paiement   who approves; the payment's entry on
--       the accounts the month booked; a failure refuses the payment (it used to
--       say the payment is kept without its entry); « Solder (rien à payer) ».
--   assistant-cloture-annuelle             open by permission (it said « admin »);
--       the IS on 89; the result phase posts nothing (it said Dr 12 / Cr 131);
--       the fifth preflight check; the À-nouveaux of the definitive closing.
--   faq-declarations-chiffres-different-paie  the CNPS « Écart » line is gone.
--
-- French bodies only (these articles have no English body). Re-runnable: plain
-- REPLACEs keyed by slug, then the first chunk re-read from the body.
-- =============================================================================

SET NAMES utf8mb4;

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(REPLACE(b.body_md,
       'Cliquez sur **Approuver & verrouiller** dans la section du modal.',
       'Le Contrôleur Financier (ou la Direction) clique sur **Approuver & verrouiller** dans la section du modal. Le Comptable prépare et paie ; il n''approuve pas.'),
       '| **Montant payé** | Pré-rempli au net à payer. Modifiable si paiement partiel. |',
       '| **Montant payé** | Le net à payer de la déclaration verrouillée. Un autre montant (pénalités, paiement partiel) se passe en OD dans Écritures. |'),
       'Débit  447 / 431 / 4432 / 4471 …  (le compte de dette selon la déclaration)',
       'Débit  4431 / 432 / 433 / 4471 …  (les comptes que le mois a crédités)'),
       'Si le mapping OHADA du compte de dette est manquant, l''écriture échoue MAIS le paiement est quand même enregistré (avec la quittance et la référence) : la comptabilité corrige le mapping et repostera manuellement.',
       'Si l''écriture ne peut pas être passée, le paiement est refusé avec la raison : rien n''est marqué payé sans son écriture.\n\nLe détail : la **DIPE** au débit de 432 (ce que les bulletins du mois y ont crédité ; une part qu''ils n''ont pas provisionnée, comme le FNE d''anciens bulletins, en 6412) ; la **CNPS** au débit de 433 ; la **TVA** : 4431 au débit, la TVA déductible 4452 et le crédit reporté 4449 au crédit ; l''**AIR** : 4471 (acompte d''IS) au débit, l''AIR retenu par les clients (4424) au crédit. Le compte de trésorerie est crédité du net et son solde baisse ; l''écriture va au journal CA pour une caisse, BQ pour une banque. Un mois qui ne laisse rien à payer (AIR couverte par l''attestation, crédit de TVA) se solde sans argent : bouton **Solder (rien à payer)**.')
 WHERE a.slug = 'declarations-approbation-et-paiement' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(b.body_md,
       'Réservé au rôle **admin** (permission `accounting.closing.run`).',
       'Ouvert à qui détient la permission `accounting.closing.run` (Comptable, Contrôleur Financier, Direction) ; la clôture définitive demande `accounting.reports.close_year`.'),
       'Compte débité : `891` (charge IS).',
       'Compte débité : `89` (impôts sur le résultat, créé dans le plan s''il manque).'),
       '3. **Affectation du résultat** — incorpore le résultat de l''exercice dans `131` (bénéfice) ou `139` (perte), via `12X` (résultat en instance d''affectation).',
       '3. **Résultat de l''exercice** — calcule le résultat (classes 6, 7 et 8) et dit où il ira : `131` (bénéfice) ou `139` (perte), dans les À-nouveaux de la clôture définitive. Aucune écriture au 31/12.'),
       'Avant de basculer en `provisional_closed`, quatre contrôles automatiques :',
       'Avant de basculer en `provisional_closed`, cinq contrôles automatiques :'),
       '4. Attestations de retenue à la source (AIR) reçues pour tous les paiements concernés.',
       '4. Attestations de retenue à la source (AIR) reçues pour tous les paiements concernés.\n5. Déclarations fiscales et sociales de l''année payées.'),
       'Le bouton **Clôture définitive** enchaîne : preflight → génération des A-Nouveaux (report des soldes de classes 1 à 5 dans une écriture OD datée 01/01 N+1) → verrouillage de l''exercice N → ouverture automatique de N+1. **Irréversible**.',
       'Le bouton **Clôture définitive** (depuis `provisional_closed` ou `locked`) passe les **À-nouveaux** AN-N+1, datés du 01/01 N+1 : les soldes des classes 1 à 5 et le résultat en 131 ou 139. Ils deviennent l''écriture d''ouverture de N+1 (Bilan d''Ouverture) et les états de N+1 partent d''eux, sans recompter l''historique. L''exercice N passe en `final_closed`. **Irréversible**. Le bouton « Assistant de Clôture » de la barre ouvre cet onglet.\n\nUne dotation d''inventaire extournée libère ses mois : un passage mensuel les comptabilise à nouveau.')
 WHERE a.slug = 'assistant-cloture-annuelle' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = CONCAT(SUBSTRING_INDEX(b.body_md, 'Cause 4 — ', 1),
       'Cause 4 — **Le taux AT.** Le bloc CNPS reprend ce que la paie a retenu et comptabilisé en 433 : l''accident du travail y est le reste de la part patronale de la paie. Une ligne signale quand ce taux diffère du groupe CNPS de Paramètres : corrigez le taux de la paie ou le groupe, selon ce que dit la CNPS.')
 WHERE a.slug = 'faq-declarations-chiffres-different-paie' AND b.lang = 'fr'
   AND b.body_md LIKE '%Cause 4 — %';

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('declarations-approbation-et-paiement', 'assistant-cloture-annuelle', 'faq-declarations-chiffres-different-paie')
   AND c.chunk_index = 0;
