-- =============================================================================
-- 136_payroll_undo_fne_and_paid.sql
-- -----------------------------------------------------------------------------
-- Gestion du Personnel (salaires): « Payé » means paid, a month can be undone
-- before it is paid, the FNE is booked, and the Comptable approves advances.
-- Found while preparing the Salaires training video (docs/VIDEO_QA_FINDINGS.md
-- §10, 18/11/2026).
--
-- WHY
--   · « Calculer & Valider Paie » set every payslip to 'paid' the moment its
--     cost was booked, before any money left: the payslip printed « Payé » and
--     « Marquer Payées » looked for 'paid' payslips to pay. From now on a
--     calculated payslip is 'validated' (« À payer ») and only « Marquer
--     Payées » makes it 'paid'. The rows already calculated and never paid out
--     go back to 'validated'.
--   · The payroll entries (PAIE-…) carried no link to their payslip, so nothing
--     could reverse one. New ones are linked (source_type 'payslip'); this
--     links the old ones, so « Annuler la paie » can reverse them too.
--   · The DIPE declared the FNE (1 % of gross, employer) but the payroll never
--     booked it: 432 was short of the DIPE every month. The payroll now books
--     it, with the CFC part patronale, on 6412 « Charges fiscales sur
--     salaires » (credit 432). Each payslip stores both, so the DIPE sums what
--     was booked.
--   · The owner's answer 5 (18/11/2026): the Comptable also approves staff
--     advances.
--
-- WHAT
--   role_permissions      accountant + hr.payroll.approve_advance.
--   payroll_rates         fne_rate 0.01 for every year that has rates.
--   hr_payslips           cfc_employer, fne_employer (NULL on older rows: the
--                         DIPE derives them from the gross as before);
--                         'paid' with no payment entry → 'validated'.
--   journal_entries       the PAIE entries get source_type 'payslip' and the
--                         payslip's id.
--   help_*                the twelve Gestion du Personnel articles describe the
--                         page as it is now; the two that taught SQL and server
--                         logs are unpublished. Chunk 0 takes the new text, the
--                         others go (as in 130).
--
-- The advances' and October/November 2026 data are not otherwise touched.
-- Idempotent: guarded columns, INSERT IGNORE, fixed values.
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- The Comptable approves advances (answer 5)
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
  FROM roles r
  JOIN permissions p ON p.name = 'hr.payroll.approve_advance'
 WHERE r.name = 'accountant';

-- -----------------------------------------------------------------------------
-- FNE rate
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO payroll_rates (year, rate_key, rate_value, notes)
SELECT DISTINCT year, 'fne_rate', 0.010000, "FNE, Fonds National de l'Emploi (part patronale, 1 % du brut)"
  FROM payroll_rates;

-- -----------------------------------------------------------------------------
-- The employer's taxes on each payslip
-- -----------------------------------------------------------------------------
SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'hr_payslips' AND column_name = 'cfc_employer');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_payslips ADD COLUMN cfc_employer DECIMAL(15,2) NULL DEFAULT NULL COMMENT ''CFC part patronale (1,5 %) booked by this payslip''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = 'hr_payslips' AND column_name = 'fne_employer');
SET @sql := IF(@has = 0,
   'ALTER TABLE hr_payslips ADD COLUMN fne_employer DECIMAL(15,2) NULL DEFAULT NULL COMMENT ''FNE part patronale (1 %) booked by this payslip''',
   'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- « Payé » means paid
-- -----------------------------------------------------------------------------
UPDATE hr_payslips SET status = 'validated' WHERE status = 'paid' AND payment_je_id IS NULL;

-- -----------------------------------------------------------------------------
-- Link the payroll entries to their payslip
-- -----------------------------------------------------------------------------
UPDATE journal_entries je
  JOIN hr_payslips p ON p.journal_entry_id = je.id
   SET je.source_type = 'payslip', je.source_id = p.id
 WHERE je.source_type IS NULL;

-- =============================================================================
-- Help Centre
-- =============================================================================
START TRANSACTION;

UPDATE help_articles SET is_published = 0
 WHERE slug IN ('paie-configurer-plan-comptable-ohada', 'faq-paie-erreur-serveur-au-valider');

DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('paie-configurer-plan-comptable-ohada', 'faq-paie-erreur-serveur-au-valider');

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Découvrir Gestion du Personnel',
       b.summary  = "Les trois onglets, qui fait quoi, et le trajet d'un salaire jusqu'au paiement.",
       b.keywords = 'paie, salaires, acomptes, bulletin, gestion du personnel, controleur financier, comptable',
       b.body_md  = "**Gestion du Personnel** est la page de la paie : les acomptes, le calcul du mois, les bulletins et le paiement des salaires.

## Les trois onglets

1. **Demandes d'Acomptes** : les avances sur salaire demandées, approuvées, décaissées, puis déduites.
2. **Employés & Contrats** : la liste des employés et de leur salaire, en lecture. Un salaire se modifie dans **Données de Base, Employés & RH** (bouton **Voir la fiche**).
3. **Génération de la Paie** : la grille du mois, le calcul, les bulletins, puis **Marquer Payées**.

## Qui fait quoi

- Le **Contrôleur Financier** calcule la paie du mois et enregistre son paiement.
- Le **Comptable** approuve et décaisse les acomptes, et peut aussi calculer et payer.
- Le **Directeur Général** voit tout et peut tout faire.

## Le trajet d'un salaire

1. Le salaire est tenu dans **Données de Base**.
2. Un acompte éventuel est demandé, approuvé, puis **décaissé**.
3. En fin de mois, **Charger Grille**, puis **Calculer & Valider Paie** : chaque bulletin est calculé, **À payer**, et son écriture de paie est passée.
4. Le jour du virement, **Marquer Payées** : les bulletins deviennent **Payé**, l'argent sort du compte choisi.

Tant qu'un mois n'est pas payé, **Annuler la Paie** permet de le reprendre."
 WHERE a.slug = 'paie-decouvrir-le-module' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Où se modifie un salaire',
       b.summary  = 'Le salaire et les indemnités se tiennent dans Données de Base, Employés & RH.',
       b.keywords = 'salaire, contrat, indemnite, logement, transport, cnps, donnees de base, employes',
       b.body_md  = "L'onglet **Employés & Contrats** montre, pour chaque employé actif, son salaire de base, ses indemnités et son numéro CNPS. Il ne se modifie pas ici.

## Modifier un salaire

Sur la ligne de l'employé, **Voir la fiche** ouvre sa fiche dans **Données de Base, Employés & RH**. Le Directeur Général et le Contrôleur Financier y changent :

- le **salaire de base** ;
- l'**indemnité de logement** et l'**indemnité de transport** ;
- le **numéro CNPS** ;
- le **régime fiscal** (Standard, Expatrié, Exonéré).

Le prochain **Charger Grille** reprend les nouveaux montants.

## Un employé absent de la grille

La grille ne montre que les employés **actifs** qui ont un accès à l'ERP. Un employé sans salaire (par exemple le compte du support informatique) apparaît à 0 et le calcul l'ignore."
 WHERE a.slug = 'paie-enregistrer-un-employe' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Demander un acompte',
       b.summary  = 'La demande, le seuil de 50 % du salaire, et les étapes jusqu’à la déduction.',
       b.keywords = 'acompte, avance sur salaire, demande, 50 %, decaisser, deduire',
       b.body_md  = "Un **acompte** est une avance sur salaire versée en cours de mois. Il est repris sur le bulletin suivant.

## Faire la demande

Onglet **Demandes d'Acomptes**, bouton **Nouvelle Demande**. Choisissez l'employé et le montant (1 000 F au moins), puis **Soumettre Demande**.

Au-delà de **50 % du salaire de base**, un avertissement rouge s'affiche. Ce n'est pas un blocage : c'est à la personne qui approuve de décider.

## Les étapes

| Statut | Ce qu'il veut dire |
|---|---|
| **En attente** | La demande attend une approbation. |
| **Approuvé (À décaisser)** | Accordé, mais l'argent n'est pas encore remis. |
| **Décaissé (À déduire)** | L'argent est remis ; il sera repris sur le prochain bulletin calculé. |
| **Soldé (Déduit)** | Repris sur un bulletin. |
| **Refusé** | Refusé, avec un motif. |

Un acompte approuvé mais pas décaissé n'est **pas** déduit : l'employé ne l'a pas encore reçu."
 WHERE a.slug = 'paie-demander-un-acompte' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Approuver, décaisser ou refuser un acompte',
       b.summary  = "Qui approuve, comment l'argent est remis, et ce que cela passe en comptabilité.",
       b.keywords = 'acompte, approuver, refuser, decaisser, caisse, 421, comptable, controleur financier',
       b.body_md  = "Le **Directeur Général**, le **Contrôleur Financier** et le **Comptable** approuvent les acomptes.

## Approuver ou refuser

Sur une demande **En attente** : **Approuver**, ou **Refuser** avec un motif. L'employé est prévenu. Une demande déjà traitée ne peut plus être approuvée ni refusée.

## Décaisser

Quand l'argent est remis, **Décaisser** sur la ligne approuvée. Choisissez le compte qui paie (la caisse en général), la date, une note si besoin, puis **Confirmer**.

L'ERP enregistre alors la sortie d'argent du compte choisi et l'écriture : débit **421** Personnel, avances, crédit du compte de trésorerie.

## Ensuite

L'acompte est **Décaissé (À déduire)**. Le prochain calcul de paie de l'employé le reprend (crédit 421) et le passe à **Soldé (Déduit)**."
 WHERE a.slug = 'paie-approuver-un-acompte' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Calculer, annuler et payer la paie du mois',
       b.summary  = 'Charger Grille, Calculer & Valider Paie, Annuler la Paie, Marquer Payées.',
       b.keywords = 'paie du mois, charger grille, calculer, valider, annuler la paie, marquer payees, banque, caisse, bulletin',
       b.body_md  = "## 1. Charger la grille

Onglet **Génération de la Paie** : choisissez le mois, puis **Charger Grille**. Une ligne par employé actif : base, indemnités, acomptes décaissés à reprendre, et l'aperçu des retenues et du net.

## 2. Saisir les variables

Pour chaque ligne : **Primes**, **Abs. (J)** (jours d'absence, retirés à raison de base ÷ 30 par jour) et le mode de paiement, **BANQUE** ou **CAISSE**. L'aperçu se recalcule à chaque saisie.

## 3. Calculer & Valider Paie

Le bouton calcule chaque bulletin et passe son écriture de paie. Les bulletins sont **À payer**. Un mois déjà calculé ne peut pas l'être deux fois.

## 4. Annuler la Paie (avant paiement)

Si un salaire était faux, **Annuler la Paie** reprend les bulletins du mois qui ne sont pas encore payés. Un motif est demandé. Chaque écriture de paie est extournée par une écriture inverse, les acomptes repris redeviennent à déduire, et le lien du bulletin déjà envoyé ne l'ouvre plus. Corrigez le salaire dans Données de Base, puis recalculez le mois.

Un bulletin payé ne s'annule pas ici : son argent est sorti.

## 5. Marquer Payées

Le jour du versement, **Marquer Payées** : deux groupes, **Banque/MoMo** et **Caisse**. Pour chaque groupe, cochez les bulletins, choisissez le compte qui paie, puis **Régler le Groupe**. Une seule écriture par groupe (débit 422, crédit du compte), comme le virement unique à la banque. Les bulletins deviennent **Payé**, avec la date."
 WHERE a.slug = 'paie-generer-la-paie-du-mois' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Lire la fiche détaillée d'un employé",
       b.summary  = 'Le calcul ligne par ligne, les charges patronales, l’écriture et les six derniers bulletins.',
       b.keywords = 'fiche detaillee, bulletin, brut, cotisations, charges patronales, fne, ecriture, historique, pdf',
       b.body_md  = "Dans la grille, cliquez sur le **nom** d'un employé : la fiche détaillée du mois s'ouvre.

## Ce qu'elle montre

- **Identité** et **Contrat** : matricule, CNPS, embauche, régime fiscal ; base et indemnités.
- **Composition du Salaire Brut** : base, indemnités, primes, moins les absences.
- **Cotisations & Retenues** : CNPS, IRPP, CAC, CFC, CRTV, TDL, chacune avec sa formule.
- **Avances / Dettes / Absences** : ce qui est repris ce mois.
- **Charges Patronales** : CNPS, CFC et FNE, payées par LPC en plus du brut.
- **Net à payer** et le mode de paiement.
- **Écriture Comptable** : les comptes débités et crédités.
- **Historique** : les six derniers bulletins, **À payer** ou **Payé**, avec leur PDF.

**Télécharger Fiche PDF** ouvre le bulletin quand le mois est calculé."
 WHERE a.slug = 'paie-lire-la-fiche-detaillee' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Le moteur de calcul (Cameroun 2026)',
       b.summary  = 'CNPS, IRPP, CAC, CFC, CRTV, TDL, FNE : les taux et les formules.',
       b.keywords = 'cnps, irpp, cac, cfc, crtv, tdl, fne, bareme, taux, abattement, frais professionnels',
       b.body_md  = "## Le brut

Brut = base + logement + transport + primes − absences (base ÷ 30 par jour d'absence).

## Retenues sur le salaire

| Ligne | Calcul |
|---|---|
| **CNPS** | 4,2 % du brut, plafonné à 750 000 F par mois |
| **IRPP** | barème mensuel sur la base imposable : (brut − CNPS) × 70 % − 41 667 F |
| **CAC** | 10 % de l'IRPP |
| **CFC** | 1 % du brut |
| **CRTV** | montant fixe selon la tranche du brut |
| **TDL** | 3 000 F si le brut dépasse 100 000 F |

Barème IRPP mensuel : 10 % jusqu'à 166 666 F, 15 % jusqu'à 250 000 F, 25 % jusqu'à 416 666 F, 35 % au-delà, chaque taux sur sa seule tranche.

La situation de famille ne change pas l'IRPP au Cameroun : la paie ne l'utilise pas.

## Charges patronales (en plus du brut)

| Ligne | Calcul |
|---|---|
| **CNPS patronale** | 16,2 % du brut plafonné |
| **CFC patronale** | 1,5 % du brut |
| **FNE** | 1 % du brut (Fonds National de l'Emploi) |

## Net à payer

Net = brut − retenues − acomptes − dettes. Jamais négatif.

Les taux de chaque année sont tenus par JBS Praxis Support : demandez-leur toute mise à jour légale."
 WHERE a.slug = 'paie-moteur-de-calcul-cameroun-2026' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Les écritures de la paie',
       b.summary  = 'Le calcul, le paiement, l’acompte et l’annulation : ce que chacun passe en comptabilité.',
       b.keywords = 'ecriture, od, 661, 664, 6412, 432, 433, 421, 422, 521, 571, dipe, cnps, extourne',
       b.body_md  = "## Au calcul (journal OD, une écriture par bulletin)

| Compte | Débit | Crédit |
|---|---|---|
| **661** Rémunérations du personnel | brut | |
| **664** Charges sociales patronales | CNPS patronale | |
| **6412** Charges fiscales sur salaires | CFC patronale + FNE | |
| **433** CNPS | | CNPS salariale + patronale |
| **432** État, impôts sur salaires | | IRPP, CAC, CFC (deux parts), CRTV, TDL, FNE |
| **421** Personnel, avances | | acomptes repris |
| **422** Personnel, rémunérations dues | | net à payer |

Le crédit du mois sur **432** est la DIPE ; celui sur **433**, la déclaration CNPS.

## Au paiement (Marquer Payées, journal BQ)

Débit **422**, crédit du compte de banque ou de caisse choisi : une écriture par groupe.

## À l'acompte (Décaisser)

Débit **421**, crédit du compte qui paie.

## À l'annulation (Annuler la Paie)

Chaque écriture de calcul est extournée par une écriture inverse, datée du jour ; l'originale est marquée extournée. Rien n'est effacé."
 WHERE a.slug = 'paie-ecriture-comptable-od' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Pourquoi le brut ne correspond pas au salaire de base ?',
       b.summary  = 'Le brut ajoute les indemnités de la fiche employé.',
       b.keywords = 'brut, salaire de base, indemnite, logement, transport, colonne indemn',
       b.body_md  = "Brut = base + indemnité de logement + indemnité de transport + primes − absences.

La colonne **Indemn.** de la grille montre logement et transport réunis. La fiche détaillée (clic sur le nom) les sépare dans **Composition du Salaire Brut**.

Une indemnité fausse se corrige dans **Données de Base, Employés & RH**, puis **Charger Grille** à nouveau. Si le mois est déjà calculé et pas payé, **Annuler la Paie** d'abord."
 WHERE a.slug = 'faq-paie-brut-different-du-base' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Comment recalculer l'IRPP à la main pour vérifier ?"
 WHERE a.slug = 'faq-paie-recalculer-irpp-a-la-main' AND b.lang = 'fr';

-- The AI assistant's copy: chunk 0 = title + new body, the rest go.
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('paie-decouvrir-le-module', 'paie-enregistrer-un-employe', 'paie-demander-un-acompte',
                  'paie-approuver-un-acompte', 'paie-generer-la-paie-du-mois', 'paie-lire-la-fiche-detaillee',
                  'paie-moteur-de-calcul-cameroun-2026', 'paie-ecriture-comptable-od',
                  'faq-paie-brut-different-du-base', 'faq-paie-recalculer-irpp-a-la-main')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('paie-decouvrir-le-module', 'paie-enregistrer-un-employe', 'paie-demander-un-acompte',
                  'paie-approuver-un-acompte', 'paie-generer-la-paie-du-mois', 'paie-lire-la-fiche-detaillee',
                  'paie-moteur-de-calcul-cameroun-2026', 'paie-ecriture-comptable-od',
                  'faq-paie-brut-different-du-base', 'faq-paie-recalculer-irpp-a-la-main')
   AND c.chunk_index = 0;

COMMIT;

-- VERIFY (by hand):
--   SELECT status, COUNT(*) FROM hr_payslips GROUP BY status;
--   SELECT id, reference, source_type, source_id FROM journal_entries WHERE reference LIKE 'PAIE-%';
--   SELECT r.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id
--     JOIN permissions p ON p.id = rp.permission_id WHERE p.name = 'hr.payroll.approve_advance';
