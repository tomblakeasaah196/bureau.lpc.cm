-- =============================================================================
-- 146_help_loans_treasury_ledgers.sql
-- -----------------------------------------------------------------------------
-- Help Centre: « Emprunts & Financements » and the treasury accounts' own
-- sub-accounts (the owner's decisions 12 and 15, 18/11/2026; migration 145).
--
-- WHAT
--   help_articles / _bodies / _anchors  new: « Emprunts & Financements »
--                         (accounting.loans.view), shown by the page's Aide.
--   help_article_bodies   « Découvrir Trésorerie & Banque »: four tabs;
--                         « Créer un compte »: each account gets its own
--                         sub-account (5211, 5521, 5711…), and an opening
--                         balance lent by someone becomes a loan.
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (reindex_help_chunks.php re-chunks).
--
-- Idempotent: INSERT IGNORE; each REPLACE finds its text once.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Découvrir Trésorerie & Banque: the fourth tab
-- -----------------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = REPLACE(b.summary, 'Les trois onglets', 'Les quatre onglets'),
       b.body_md = REPLACE(REPLACE(b.body_md, '## Les trois onglets', '## Les quatre onglets'),
                           '- **Rapprochement Bancaire** :',
                           '- **Emprunts & Financements** : ce que LPC doit à ses prêteurs et ce que ses emprunteurs lui doivent. Le Directeur Général approuve chaque financement et chaque remboursement avant qu''il ne soit comptabilisé.\n- **Rapprochement Bancaire** :')
 WHERE a.slug = 'demarrer-tresorerie' AND b.lang = 'fr' AND b.body_md NOT LIKE '%Emprunts & Financements%';

-- -----------------------------------------------------------------------------
-- Créer un compte: its own sub-account
-- -----------------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
           'Il fixe le compte comptable : 521 Banques, 552 Monnaie électronique, 571 Caisse. |',
           'Il fixe où le compte prend place au plan comptable : sous 521 Banques, 552 Monnaie électronique ou 571 Caisse. Le compte reçoit **son propre sous-compte**, à son nom : 5211, 5212… pour les banques, 5521… pour le Mobile Money, 5711… pour les caisses. Son grand livre suit ainsi son relevé. |'),
           '- **Débit** du compte du compte (521, 552 ou 571) ;',
           '- **Débit** du sous-compte du compte (5211, 5521, 5711…) ;')
 WHERE a.slug = 'tresorerie-creer-un-compte' AND b.lang = 'fr' AND b.body_md NOT LIKE '%son propre sous-compte%';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(b.body_md,
           'Le Comptable le régularise ensuite par une écriture OD vers le bon compte (apport en capital, compte courant d''associé, autre banque…).',
           'Le Comptable le régularise ensuite par une écriture OD vers le bon compte (apport en capital, compte courant d''associé, autre banque…). Si l''argent a été **prêté** (par un dirigeant, une société liée, une banque…), enregistrez plutôt un emprunt dans l''onglet *Emprunts & Financements* en choisissant « Est déjà sur le compte » : à l''approbation du DG, il quitte le 471 pour le compte du prêteur.')
 WHERE a.slug = 'tresorerie-creer-un-compte' AND b.lang = 'fr' AND b.body_md NOT LIKE '%Emprunts & Financements%';

-- -----------------------------------------------------------------------------
-- New: Emprunts & Financements
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO help_articles (category_id, slug, required_permission, kind, page_path, sort_order, is_published, created_at, updated_at)
SELECT a.category_id, 'tresorerie-emprunts-et-financements', 'accounting.loans.view', 'article',
       '/modules/accounting/cashflow.php', 45, 1, NOW(), NOW()
  FROM help_articles a WHERE a.slug = 'demarrer-tresorerie';

INSERT IGNORE INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md, updated_at)
SELECT a.id, 'fr', "Emprunts & Financements",
       "Enregistrer un emprunt ou un prêt, ses intérêts et ses remboursements ; le DG approuve tout avant que rien ne soit comptabilisé.",
       'emprunt, pret, financement, interets, remboursement, echeance, dirigeant, associe, smart logistics, banque, tontine, cooperative, 466, 4621, 162, 168',
       "L'onglet **Emprunts & Financements** de Trésorerie & Banque suit l'argent que LPC emprunte (à ses dirigeants, à une société liée comme Smart Logistics, à une banque, à une tontine ou une coopérative) et l'argent qu'elle prête.

## Qui fait quoi

- Le **Contrôleur Financier**, le **Comptable** et le **Directeur Général** enregistrent un financement, un prêteur ou un emprunteur, un remboursement.
- Le **Directeur Général** approuve ou refuse chaque financement et chaque remboursement. **Rien n'est comptabilisé, et aucun argent ne bouge en trésorerie, avant son approbation.** Un refus demande une note ; celui qui a saisi en est prévenu.

## Le compte de chaque prêteur

Chaque prêteur ou emprunteur a son propre sous-compte, créé avec son premier financement :

| Nature | LPC emprunte | LPC prête |
|---|---|---|
| Dirigeant / associé | 4621 Associés, comptes courants | 271 Prêts |
| Société liée | 466 Groupe, comptes courants | 466 (un sous-compte à part) |
| Banque | 162 Emprunts auprès des établissements de crédit | 271 Prêts |
| Tontine / coopérative, autre | 168 Autres emprunts et dettes | 271 Prêts |

Le prêt d'une société qui est aussi cliente reste **à part de son compte client (411)** : on ne compense jamais l'un par l'autre.

## Les conditions

- **Sans intérêt** : le cas le plus courant.
- **Taux fixe mensuel sur le restant dû** : chaque mois, le taux s'applique au capital qui reste dû.
- **Taux mensuel composé** : chaque mois, le taux s'applique au capital et aux intérêts non payés.
- **Frais unique** : retenu sur les fonds versés (LPC emprunte : charge 631 ; LPC prête : produit 771).
- **Échéance** (facultative) : le DG et le Contrôleur Financier sont prévenus une semaine avant, puis si elle passe alors qu'il reste un dû.

Les intérêts d'un financement approuvé se comptabilisent seuls, chaque mois à la date anniversaire, selon les conditions approuvées : ils s'ajoutent à ce qui est dû.

## Les remboursements

**Rembourser**, sur un financement en cours : n'importe quel montant, n'importe quel jour, par le compte de trésorerie choisi. Il paie d'abord les intérêts, puis le capital. Quand tout est remboursé, le financement passe à **Soldé**.

## Des fonds déjà sur un compte

Un solde d'ouverture prêté par quelqu'un (au 471, compte d'attente) s'enregistre comme un emprunt en choisissant **Est déjà sur le compte**. À l'approbation, il quitte le 471 pour le compte du prêteur, sans mouvement d'argent. C'est le cas du million de Smart Logistics qui a ouvert le compte MTN MOBILE MONEY le 03/11/2026.", NOW()
  FROM help_articles a WHERE a.slug = 'tresorerie-emprunts-et-financements';

INSERT IGNORE INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.cashflow', a.id, 45 FROM help_articles a WHERE a.slug = 'tresorerie-emprunts-et-financements';

-- -----------------------------------------------------------------------------
-- The AI assistant's copy
-- -----------------------------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('demarrer-tresorerie', 'tresorerie-creer-un-compte') AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('demarrer-tresorerie', 'tresorerie-creer-un-compte') AND c.chunk_index = 0;

COMMIT;
