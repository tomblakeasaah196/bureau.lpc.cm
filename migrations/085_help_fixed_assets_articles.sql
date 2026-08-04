-- =============================================================================
-- 085_help_fixed_assets_articles.sql
-- -----------------------------------------------------------------------------
-- Bureau LPC ERP — help content for the Immobilisations & Amortissements module.
--
-- WHY
--   The fixed_assets page's toolbar now carries an
--   lpc_help_link('accounting.fixed_assets') button. Until this migration runs,
--   the button renders as the muted "Aide (à venir)" fallback because no
--   article is anchored to the key. This file lays down the article set and
--   wires the anchors so the button starts opening the drawer immediately.
--
-- WHAT THIS CREATES
--   1. help_categories row 'immobilisations' (sort_order 25, just after
--      Facturation/Ventes so it stays close to the finance cluster).
--   2. help_articles for: overview, capitalisation, méthode d'amortissement,
--      dotations mensuelles, cession et rebut, TVA sur cession, référentiel
--      SYSCOHADA, plus a two-entry FAQ.
--   3. help_article_anchors mapping every article's page_path to the
--      'accounting.fixed_assets' anchor key.
--   4. help_article_bodies (FR only; the reader falls back to FR when EN is
--      missing, per lpc_help_article() in includes/functions/help.php).
--
-- Depends on: 032_help_center_schema.sql, 011_fixed_assets_schema.sql,
--             084_syscohada_fixed_assets_accounts.sql.
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE keyed on slug/anchor.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- -----------------------------------------------------------------------------
-- 1. CATEGORY
-- -----------------------------------------------------------------------------
INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'immobilisations', 'accounting', 'accounting.fixed_assets.view',
 'M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75',
 'Immobilisations & Amortissements',
 'Fixed Assets & Depreciation',
 "Capitaliser un actif, poster la dotation mensuelle, céder ou rebuter — le tout en SYSCOHADA révisé.",
 'Capitalise an asset, post monthly depreciation, dispose or scrap — SYSCOHADA revised throughout.',
 25
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'immobilisations' AS cat, 'immo-demarrer'              AS slug, 'accounting.fixed_assets.view'      AS perm, 'article' AS kind, '/modules/accounting/fixed_assets.php' AS page,  10 AS ord UNION ALL
    SELECT 'immobilisations',        'immo-capitaliser-un-actif',           'accounting.fixed_assets.capitalize',       'article', '/modules/accounting/fixed_assets.php',  20 UNION ALL
    SELECT 'immobilisations',        'immo-choisir-la-methode',             'accounting.fixed_assets.view',             'article', '/modules/accounting/fixed_assets.php',  30 UNION ALL
    SELECT 'immobilisations',        'immo-dotations-mensuelles',           'accounting.fixed_assets.depreciate',       'article', '/modules/accounting/fixed_assets.php',  40 UNION ALL
    SELECT 'immobilisations',        'immo-ceder-ou-rebuter',               'accounting.fixed_assets.dispose',          'article', '/modules/accounting/fixed_assets.php',  50 UNION ALL
    SELECT 'immobilisations',        'immo-tva-sur-cession',                'accounting.fixed_assets.dispose',          'article', '/modules/accounting/fixed_assets.php',  60 UNION ALL
    SELECT 'immobilisations',        'immo-plan-comptable-syscohada',       'accounting.fixed_assets.view',             'article', '/modules/accounting/fixed_assets.php',  70 UNION ALL
    SELECT 'immobilisations',        'faq-immo-dropdowns-vides',            'accounting.fixed_assets.capitalize',       'faq',     '',                                       200 UNION ALL
    SELECT 'immobilisations',        'faq-immo-modifier-duree-de-vie',      'accounting.fixed_assets.view',             'faq',     '',                                       210 UNION ALL
    SELECT 'immobilisations',        'faq-immo-erreur-comptes-81-82',       'accounting.fixed_assets.dispose',          'faq',     '',                                       220
) AS x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id = VALUES(category_id),
    required_permission = VALUES(required_permission),
    kind = VALUES(kind), page_path = VALUES(page_path),
    sort_order = VALUES(sort_order), is_published = VALUES(is_published);

-- -----------------------------------------------------------------------------
-- 3. ANCHORS — page → article
-- -----------------------------------------------------------------------------
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.fixed_assets', a.id, a.sort_order
  FROM help_articles a
 WHERE a.page_path = '/modules/accounting/fixed_assets.php'
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- -----------------------------------------------------------------------------
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'immo-demarrer' AS slug,
 "Découvrir Immobilisations & Amortissements" AS title,
 "Les trois onglets, l'articulation avec la Flotte, et le cycle de vie d'une immobilisation." AS summary,
 'immobilisation, immo, actif, amortissement, capitalisation, cession, syscohada' AS kw,
 "**Immobilisations & Amortissements** est le module de gestion du cycle de vie d'un actif immobilisé : de son entrée dans les comptes jusqu'à sa sortie, en passant par ses dotations mensuelles.

## Les trois onglets

- **À Capitaliser (Flotte)** — la file d'attente des véhicules créés par la Logistique qui n'ont pas encore d'écriture comptable. Un badge rouge signale les entrées en retard. Un bouton *Ajouter Actif Manuel* permet d'inscrire un actif non-véhicule (ordinateur, machine, mobilier).
- **Registre des Actifs** — la liste complète : valeur brute, amortissements cumulés, VNC (valeur nette comptable), statut. Filtres *Actifs / Amortis / Sortis / Tous* et recherche libre. Export Excel disponible.
- **Dotations Mensuelles** — bouton unique qui génère l'écriture OD (opérations diverses) du mois : Dr 68xx / Cr 28xx.

## Le cycle de vie SYSCOHADA

| Étape | Onglet | Écriture posée |
|---|---|---|
| 1. Acquisition | *À Capitaliser* → *Capitaliser* | Dr **2xx** (coût) / Cr trésorerie ou fournisseur (posté par le module Achats). |
| 2. Dotation mensuelle | *Dotations Mensuelles* → *Exécuter* | Dr **68xx** (dotation) / Cr **28xx** (amortissement). |
| 3. Cession ou rebut | *Registre* → *Céder* | Dr trésorerie + Dr **28xx** + Dr **81xx** / Cr **2xx** + Cr **82xx** + Cr **4431** (si TVA). |

> [!note] Le module est la seule source de vérité. Les écritures d'amortissement ne se saisissent **jamais** à la main dans le Journal — elles se génèrent depuis ici pour rester cohérentes avec le registre." AS body

UNION ALL SELECT 'immo-capitaliser-un-actif',
 "Capitaliser un actif",
 "Les champs obligatoires, la logique de proratisation du premier mois, et la vérification des comptes SYSCOHADA à l'enregistrement." AS summary,
 'capitalisation, capitaliser, coût, valeur résiduelle, durée, mise en service, prorata' AS kw,
 "Capitaliser, c'est inscrire l'actif au bilan et lancer son plan d'amortissement.

## Ouvrir la fenêtre

- Depuis **À Capitaliser (Flotte)** : cliquer *Capitaliser* sur la ligne d'un véhicule. Le nom et le numéro d'immatriculation sont pré-remplis ; les comptes *2451 / 28451 / 6813* (matériel automobile) sont pré-sélectionnés. À vérifier ligne par ligne, à ajuster si l'entreprise utilise un plan personnalisé.
- Depuis **Ajouter Actif Manuel** : formulaire vierge, aucun compte pré-sélectionné.

## Les champs

| Champ | Rôle |
|---|---|
| **Nom** | Libellé affiché partout — informatif, pas comptable. |
| **Valeur d'acquisition** | Coût d'entrée SYSCOHADA : prix d'achat + frais accessoires + TVA non déductible. |
| **Valeur résiduelle** | Valeur estimée en fin de vie utile. Par défaut 0. Ne peut pas être ≥ coût. |
| **Durée de vie utile (mois)** | 60 = 5 ans. Voir *Choisir la méthode et la durée* pour les usages fiscaux. |
| **Date d'acquisition** | Date à laquelle l'entreprise est devenue propriétaire. |
| **Date de mise en service** | Point de départ **réel** de l'amortissement. La proratisation du premier mois se calcule à partir d'ici. Ne peut pas précéder l'acquisition. |
| **Méthode** | *Linéaire (OHADA standard)* par défaut. *Dégressif* disponible pour les actifs éligibles au CGI (voir article dédié). |
| **Compte d'actif (Classe 2)** | Ex. 2451 pour un véhicule, 2445 pour un ordinateur. |
| **Compte d'amortissement (28xx)** | Miroir régulateur du compte 2xx. |
| **Compte de dotation (68xx)** | Charge d'exploitation qui frappera le compte de résultat. |

## La dotation mensuelle estimée

Le champ *Dotation mensuelle estimée* se recalcule à chaque saisie :

- **Linéaire** : `(coût − résiduelle) ÷ durée_en_mois`.
- **Dégressif** : formule dégressive avec coefficient CGI (voir article suivant).

## À la validation

L'ERP vérifie que :

- le coût est > 0 ;
- la valeur résiduelle est ≥ 0 et strictement < coût ;
- la date de mise en service ne précède pas l'acquisition ;
- chaque compte sélectionné appartient à la bonne classe (2xx pour l'actif hors 28x, 28xx pour l'amortissement, 68xx pour la dotation) ;
- le véhicule n'a pas déjà été capitalisé.

Si tout passe, une ligne apparaît dans le *Registre des Actifs*, avec un statut *Actif* et une VNC = coût." AS body

UNION ALL SELECT 'immo-choisir-la-methode',
 "Choisir la méthode d'amortissement",
 "Quand utiliser le linéaire, quand utiliser le dégressif, et comment le CGI Cameroun encadre les coefficients." AS summary,
 'linéaire, dégressif, méthode, durée, CGI, fiscal, coefficient' AS kw,
 "SYSCOHADA révisé retient deux méthodes d'amortissement d'exploitation. Le choix est fait à la capitalisation et **ne peut pas être modifié en cours de vie**.

## Linéaire — la valeur par défaut

Charge constante sur toute la durée utile. `dotation_mensuelle = (coût − résiduelle) ÷ durée_en_mois`.

- Utilisation : bâtiments, aménagements, mobilier, matériel de bureau et **le cas général**.
- Toujours accepté par l'administration fiscale.
- Simple à auditer.

## Dégressif — pour les actifs éligibles

Le CGI Cameroun (art. 7 ter) autorise le dégressif sur les biens **acquis neufs** dont la durée de vie utile ≥ 3 ans, principalement :

- matériel et outillage industriel ;
- matériel informatique ;
- matériel de manutention.

**Ne s'applique pas** aux véhicules de tourisme, aux bâtiments, ni au mobilier.

## Le coefficient dégressif

L'ERP applique automatiquement :

| Durée de vie utile | Coefficient |
|---|---|
| ≤ 2 ans | pas de dégressif (revient au linéaire) |
| 3 à 4 ans | 1,5 |
| 5 à 6 ans | 2,0 |
| 7 ans et plus | 2,5 |

La formule mensuelle utilisée en interne :

```
taux_annuel_dégressif = coefficient ÷ durée_en_années
dotation_mois         = résidu_amortissable × (taux_annuel_dégressif ÷ 12)
```

Lorsque la dotation dégressive devient inférieure à la dotation linéaire résiduelle, l'ERP **bascule automatiquement en linéaire** pour terminer le plan — c'est la règle du switch-back exigée par le CGI.

> [!warning] Un actif à usage mixte fiscal / comptable peut nécessiter un plan différent en comptabilité et en fiscal. L'ERP tient **le plan comptable**. Les écarts fiscaux se traitent hors module via un état de rapprochement." AS body

UNION ALL SELECT 'immo-dotations-mensuelles',
 "Poster les dotations mensuelles",
 "Sélectionner un mois, prévisualiser l'écriture OD, exécuter, et retrouver le brouillard dans Saisie des Journaux." AS summary,
 'dotation, mensuelle, OD, aperçu, brouillard, journal, 6813, 28' AS kw,
 "La dotation mensuelle est le geste comptable qui matérialise la consommation de l'actif. Elle se fait **une fois par mois** pour l'ensemble du registre.

## Le trajet en trois clics

1. **Choisir le mois cible** — un mois précédent est autorisé (rattrapage), tant que l'exercice n'est pas clôturé.
2. **Aperçu** — l'ERP calcule sans rien poster :
   - un tableau ligne par ligne (nom de l'actif, méthode, jours de prorata, montant) ;
   - le total qui sera débité en 68xx et crédité en 28xx ;
   - le nombre d'actifs déjà traités pour ce mois (ignorés — jamais de double dotation).
3. **Exécuter** — l'ERP écrit une écriture OD unique agrégée par (compte de dotation, compte d'amortissement). Le brouillard part au statut *draft* dans **Saisie des Journaux**, où le comptable le vérifie et le passe en *posted*.

## La proratisation du 1er mois

Convention SYSCOHADA du **mois de 30 jours** :

- Mise en service le 1er du mois : 30 jours → dotation pleine.
- Mise en service le 15 : 30 − 15 + 1 = **16 jours**.
- Mise en service le 30 : **1 jour**.
- Mise en service le 31 : **0 jour** → première dotation le mois suivant.

Le calcul exact figure dans le champ `depreciation_logs.amount_calculation` (JSON) : c'est la trace d'audit officielle pour chaque ligne postée.

## Idempotence

L'ERP refuse silencieusement de poster deux fois la même dotation pour un actif donné dans le même (mois, année). Si le brouillard est supprimé du Journal avant validation, la ligne *depreciation_logs* est purgée avec — vous pouvez donc relancer sans crainte.

> [!note] Un actif totalement amorti (VNC = 0) est ignoré. Un actif sorti (statut *disposed*) aussi. Rien à supprimer, rien à cocher." AS body

UNION ALL SELECT 'immo-ceder-ou-rebuter',
 "Céder ou mettre au rebut",
 "L'écriture SYSCOHADA de sortie d'immobilisation, avec plus/moins-value, et la différence entre une vente et un rebut." AS summary,
 'cession, rebut, sortie, plus-value, moins-value, 81, 82, VNC' AS kw,
 "La sortie d'un actif du bilan se fait depuis le *Registre des Actifs* → bouton **Céder** sur la ligne concernée.

## Cession vs Rebut

| Type | Prix de vente | Trésorerie | Cas d'usage |
|---|---|---|---|
| **Cession** | > 0 | Débitée du prix TTC | Vente à un tiers, reprise, échange. |
| **Rebut** | 0 | Aucune | Destruction, mise à la casse, vol non couvert. |

Le formulaire s'ajuste automatiquement : en *Rebut*, les champs prix, trésorerie et TVA disparaissent.

## L'écriture posée (SYSCOHADA net)

Pour une cession à *P* TTC dont *V* de TVA, avec un actif de coût *C*, amortissements cumulés *A*, VNC = *C − A* :

| Ligne | Débit | Crédit |
|---|---|---|
| 5xx Trésorerie | **P** | |
| 28x Amortissements cumulés | **A** | |
| 81x VCE (moins-value) | **max(0, VNC − (P − V))** | |
| 2xx Coût | | **C** |
| 82x Produits de cession (plus-value) | | **max(0, (P − V) − VNC)** |
| 4431 TVA collectée (si TVA) | | **V** |

Le montant HT (P − V) sert de base au calcul de la plus / moins-value — la TVA est due à l'administration, elle n'est jamais un gain de l'entreprise.

## L'aperçu avant validation

Le bandeau blanc au centre de la fenêtre affiche en temps réel :

- **HT** — le prix hors TVA ;
- **VNC** — la valeur nette comptable calculée sur la base des dotations réellement postées ;
- **Plus-value** en vert / **Moins-value** en rose.

## Après validation

- Le statut de l'actif passe à **Sorti** ; il reste visible dans le registre sous le filtre *Sortis* pour la traçabilité.
- Une ligne apparaît dans `fixed_asset_disposals` avec la répartition + l'ID du JE.
- Aucune dotation supplémentaire n'est calculée pour cet actif.

> [!warning] Impossible de céder un actif dont l'exercice est clôturé — le trigger `bi_je_closed_period` rejette l'écriture. Rouvrez l'exercice dans *Rapports Consolidés → Clôture* si l'opération est légitime." AS body

UNION ALL SELECT 'immo-tva-sur-cession',
 "TVA sur cession d'immobilisation",
 "Quand la cession est soumise à TVA, comment renseigner le montant, et le cas de la régularisation TVA sur cinq ans." AS summary,
 'tva, cession, 4431, régularisation, immobilisation, ttc' AS kw,
 "La TVA sur cession d'immobilisation est un sujet à part : elle dépend de la nature de l'actif, de son origine, et parfois d'une régularisation.

## Cas 1 — cession TVA « normale »

L'actif a ouvert droit à déduction lors de son acquisition (véhicule utilitaire, matériel, mobilier) et la cession se fait dans les 5 ans. La TVA est **due sur le prix de vente** au taux en vigueur (19,25 %).

- Renseigner **Prix de vente (TTC)** = prix négocié.
- Renseigner **TVA collectée** = HT × 19,25 %, ou lue directement sur la facture émise.
- L'ERP poste automatiquement Cr 4431 pour le montant TVA.

## Cas 2 — cession exonérée

L'actif est cédé **plus de 5 ans après acquisition** (délai de garde), ou n'a jamais ouvert droit à déduction (voiture particulière, dépenses de logement) : pas de TVA à collecter.

- Renseigner **Prix de vente** = prix net.
- Laisser **TVA collectée** à 0.

## Cas 3 — régularisation TVA (mémo)

Si l'actif est cédé **avant 5 ans** et que la TVA d'origine avait été déduite, l'administration peut exiger un reversement de la fraction non consommée (règle des cinquièmes). Ce n'est pas géré par le module : à traiter dans la déclaration TVA du mois de cession.

## Vérification

Le montant TVA apparaît :

- en Cr 4431 dans l'écriture de cession ;
- dans le rapport TVA du mois (module *Déclarations fiscales*) ;
- dans le tableau des cessions (`fixed_asset_disposals.disposal_vat_amount`)." AS body

UNION ALL SELECT 'immo-plan-comptable-syscohada',
 "Le plan comptable SYSCOHADA des immobilisations",
 "Les classes 2, 28, 68, 81 et 82 utilisées par le module, avec le mapping recommandé par famille d'actif." AS summary,
 'syscohada, plan, comptable, classe 2, 28, 68, 81, 82, mapping' AS kw,
 "Le SYSCOHADA révisé (2017) structure les immobilisations en cinq classes que ce module utilise sans exception.

## Classe 2 — Immobilisations (actif du bilan)

| Compte | Usage |
|---|---|
| **211 / 213 / 214** | Frais de développement, logiciels, brevets. |
| **221 / 222** | Terrains. |
| **231 / 232 / 235** | Bâtiments et aménagements. |
| **241** | Matériel et outillage industriel. |
| **2441 / 2444 / 2445** | Matériel et mobilier de bureau, informatique. |
| **2451** | Matériel automobile — le compte des véhicules. |
| **2452 / 2454 / 2458** | Autres matériels de transport. |

## Classe 28 — Amortissements (régulateurs de l'actif)

Miroir de la classe 2. Toujours créditeur.

| Compte | Miroir de |
|---|---|
| 2813 / 2814 | 213 / 214 |
| 2831 | 231 |
| 2841 | 241 |
| 2844 | 244x |
| 28451 | 2451 |

## Classe 68 — Dotations aux amortissements (charges)

| Compte | Usage |
|---|---|
| **6811** | Dotations sur immobilisations incorporelles. |
| **6813** | Dotations sur immobilisations corporelles — **le cas général**. |

## Classes 81 et 82 — Cessions

| Compte | Sens | Rôle |
|---|---|---|
| **812** | Charge | VCE (Valeur Comptable des Cessions) d'immobilisations corporelles = moins-value + coût moins amortissements. Configuré par défaut en préférences. |
| **822** | Produit | Produits de cession d'immobilisations corporelles = plus-value. Configuré par défaut en préférences. |

## Où ces comptes sont configurés

- **Par actif** : dans le formulaire *Capitaliser*, trois champs (Actif, Amortissement, Dotation).
- **Comptes par défaut de la société** : *Paramètres → Comptabilité* — clés `accounting_moins_value_account_id`, `accounting_plus_value_account_id`, `accounting_vat_output_account_id`.
- **Plan comptable** : *Paramètres → Plan comptable* — la migration 084 seed tous les comptes ci-dessus." AS body

-- =============================================================================
-- FAQ
-- =============================================================================

UNION ALL SELECT 'faq-immo-dropdowns-vides',
 "Pourquoi les listes déroulantes de comptes sont-elles vides ?",
 "Diagnostic rapide et migration corrective." AS summary,
 'faq, dropdown, vide, compte, migration 084' AS kw,
 "**Symptôme :** dans la fenêtre *Capitaliser un Actif*, les listes *Compte d'Actif (Classe 2)*, *Compte d'Amortissement (28x)* et *Compte de Dotation (68x)* n'affichent aucune option.

**Cause :** les comptes SYSCOHADA de classe 2, 28, 68 (et 81, 82) ne sont pas présents dans le plan comptable. Les huit lignes historiques du plan LPC utilisent des codes vanity préfixés `LPC…` que le filtre du formulaire ne peut pas rapprocher.

**Correction :** appliquer la migration `084_syscohada_fixed_assets_accounts.sql`. Elle :

- crée les comptes OHADA manquants (2xx / 28x / 68x / 81x / 82x) ;
- pose une ligne `chart_of_accounts` en face de chacun ;
- alimente les préférences par défaut (81x / 82x / 4431).

Après application, rafraîchir la page — les listes se remplissent." AS body

UNION ALL SELECT 'faq-immo-modifier-duree-de-vie',
 "Peut-on modifier la durée de vie d'un actif après capitalisation ?",
 "La règle SYSCOHADA et la façon dont l'ERP la traite." AS summary,
 'faq, durée, vie utile, modification, syscohada' AS kw,
 "**Réponse courte :** non, la durée de vie utile ne se modifie pas à la légère.

SYSCOHADA autorise une **révision du plan d'amortissement** uniquement lorsque des faits nouveaux modifient l'utilisation prévue de l'actif — pas pour ajuster une charge d'exploitation.

Si la révision est justifiée, le processus recommandé :

1. Solder l'actif (rebut sans plus-value) à sa VNC courante.
2. Le re-capitaliser avec la nouvelle durée de vie utile.
3. Documenter la révision dans les notes annexes des états financiers.

Une fonction de révision directe pourra être ajoutée plus tard ; en attendant, la traçabilité passe par la comptabilité." AS body

UNION ALL SELECT 'faq-immo-erreur-comptes-81-82',
 "« Compte OHADA 81/82 manquant » à la cession — que faire ?",
 "Où configurer les comptes de plus/moins-value au niveau société." AS summary,
 'faq, erreur, 81, 82, cession, moins-value, plus-value' AS kw,
 "**Symptôme :** au clic sur *Valider la Sortie*, l'ERP renvoie *« Compte OHADA 81/82 manquant — configurez les comptes de plus/moins-value. »*

**Cause :** ni l'actif cédé, ni les préférences société ne pointent sur un compte de classe 81 (moins-value) ou 82 (plus-value).

**Correction :**

1. Ouvrir *Paramètres → Comptabilité*.
2. Renseigner :
   - **Compte moins-value de cession (81x)** — recommandé : `812`.
   - **Compte plus-value de cession (82x)** — recommandé : `822`.
3. Sauvegarder et relancer la cession.

La migration 084 pré-alimente ces deux préférences si les comptes 812 / 822 existent. Si l'erreur persiste après application, c'est que ces comptes n'ont pas été créés — vérifier dans le *Plan comptable* ou re-jouer la migration." AS body

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title    = VALUES(title),
    summary  = VALUES(summary),
    keywords = VALUES(keywords),
    body_md  = VALUES(body_md);

COMMIT;

-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--   -- The anchor now resolves to 7 articles + 3 FAQs.
--   SELECT COUNT(*) FROM help_article_anchors WHERE anchor_key = 'accounting.fixed_assets';
--   -- expect: 10
--
--   -- The primary drawer article opens on the overview.
--   SELECT a.slug, b.title
--     FROM help_article_anchors an
--     JOIN help_articles a ON a.id = an.article_id
--     JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = 'fr'
--    WHERE an.anchor_key = 'accounting.fixed_assets'
--    ORDER BY an.sort_order LIMIT 1;
--   -- expect: slug 'immo-demarrer'
-- =============================================================================
