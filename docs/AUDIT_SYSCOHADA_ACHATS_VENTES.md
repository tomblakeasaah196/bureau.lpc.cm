# Audit SYSCOHADA — modules Achats & Ventes

**Périmètre** : `api/v1/procurement_controller.php`, `api/v1/inventory_controller.php`
(réception), `api/v1/sales_controller.php`, `api/v1/invoices_controller.php`,
`includes/classes/JournalPoster.php`, `includes/functions/procurement.php`,
`migrations/003, 004, 005, 020, 038–041, 052, 062, 063`.

**Verdict** : les écritures étaient **équilibrées mais fausses**. Le trigger
`bi_journal_lines_check` et `post_journal_entry` garantissent `Σ débit = Σ crédit`
et le faisaient correctement — ce qu'ils ne peuvent pas garantir, c'est que la
ligne atterrisse sur le bon compte. Six écarts sur sept ci-dessous passaient le
contrôle d'équilibre sans difficulté.

---

## 1. Règles de validation comptable (référentiel)

Ce que le système **doit** produire, scénario par scénario.

### 1.1 Achats

| Événement | Débit | Crédit |
|---|---|---|
| Réception marchandises (prix TTC) | `601` Achats HT · `4452` TVA récupérable sur achats | `401x` Fournisseur TTC |
| Réception exonérée (eau, art. 128 CGI) | `601` TTC intégral | `401x` TTC |
| Ristourne acquise | `4098` RRR à obtenir (net) · `4452` TVA retenue · `4473` Précompte subi | `6019` RRR obtenus (**brut**) |
| Ristourne consommée en remise | `401x` Fournisseur | `4098` RRR à obtenir |
| Escompte de règlement obtenu | `401x` Fournisseur | `773` Escomptes obtenus |
| Annulation de commande | Extourne miroir de chaque écriture postée | — |

**Règles non négociables**

- Le prix saisi est **TTC**. La TVA récupérable n'est jamais un coût : elle ne
  doit figurer ni dans `601`, ni dans le CUMP, ni dans `6031`.
- La TVA **non** récupérable, elle, *est* un coût et reste dans `601`.
- L'escompte de règlement est **financier** (`773`), jamais imputé sur `601` —
  c'est précisément ce que la séparation classe 6 / classe 7 protège.
- Une remise obtenue **sur facture** ne s'enregistre pas : elle est déjà dans le
  net. Seul un avoir **postérieur** touche `6019`.

### 1.2 Ventes

| Événement | Débit | Crédit |
|---|---|---|
| Facture émise | `411` Clients net à payer · `4424` AIR retenu · `4473` Précompte subi · `673` Escompte accordé | `701` Ventes **nettes** · `4461` Droits d'accises · `4431` TVA facturée sur ventes |
| Sortie de stock (BL) | `6031` Variations de stocks (au CUMP **HT**) | `31x` Stocks |
| Encaissement | `521/552/554/571` Trésorerie · `4424` AIR | `411` à hauteur du solde ouvert · `419` pour l'excédent |
| Avoir client (remise hors facture) | `7019` RRR accordés · `4431` TVA reprise | `411` Clients |

**Règles non négociables**

- Base TVA = **HT net de remise + droits d'accises**, dans cet ordre.
- L'AIR et le précompte retenus par le client **soldent** la créance : ils
  n'entament ni le CA ni la TVA, ils déplacent seulement la contrepartie.
- Une remise **sur facture** ne fait l'objet d'aucune ligne — le CA est reconnu
  au net. Une remise **hors facture** passe par `7019`, sinon le chiffre
  d'affaires de la période est sous-évalué, et c'est lui qui sert d'assiette à
  la patente et aux seuils de régime.
- Identité de contrôle : `net_payable + AIR + précompte + escompte = HT + accises + TVA`.

### 1.3 Cycle de vie

`Draft → Validated → Posted → Paid`. Une écriture `posted` est **immuable** :
la correction est une extourne (`JournalPoster::reverseSource`), jamais une
suppression — ce que `bd_journal_entries_no_post_delete` impose déjà au niveau
base.

---

## 2. Audit du code et du schéma

Sept anomalies, classées par impact sur les états financiers.

### P0-1 · Aucune TVA déductible sur les achats

`JournalPoster::postGoodsReceipt` recevait une valeur **TTC** — `save_po` et
`receive_po` le disent tous deux explicitement, et tous deux extraient le HT
avec `$ttc / (1 + vat_rate)` pour la base ristourne — et la passait telle quelle
au débit de `601`, avec le même nombre au crédit de `401`.

Aucun chemin de code, nulle part dans l'application, ne débitait `445x`.

Conséquences simultanées :

- `601` Achats surévalué de 19,25 % du HT ;
- crédit de TVA déductible **inexistant en comptabilité** → la TVA due calculée
  depuis les comptes est surévaluée du montant déductible **chaque mois** ;
- `products.cump` alimenté en TTC → `6031` (COGS) et `31x` (stocks) surévalués,
  marge brute sous-évaluée sur chaque vente.

Le guide fiscal confirme l'étendue : la déclaration de TVA lit
`invoices.tva_amount − supplier_invoices.tva_amount`, et `supplier_invoices`
n'est alimentée par aucun des deux modules audités.

### P0-2 · Remise commerciale perdue entre la commande et la facture

`sales_orders.discount_amount` existe (migration 062). `generate_dispatch` ne
recopie que `sales_order_items.unit_price` dans `delivery_items`, et
`generate_invoice` reconstruit la facture depuis `delivery_items`. La remise
d'en-tête ne traversait donc jamais.

`invoices` ne possédait d'ailleurs **aucune colonne** pour l'accueillir.

Résultat : le client est facturé plus que ce que dit sa commande, et `411`,
`701` et la base TVA sont tous surévalués du même montant.

### P0-3 · Double décompte de la remise ligne sur les achats

Dans `save_po` :

- `$subtotal` est sommé au **prix tarif** (`list_price`) ;
- `$line_discount_total` est l'écart tarif − prix payé ;
- `$discount_amount = remise saisie + $line_discount_total` ;
- `postRebateUsage($discount_amount)` débite `401` / crédite `4098`.

Or la réception comptabilise `601`/`401` à `purchase_order_items.unit_price`,
**déjà net** de cette réduction ligne. Elle était donc déduite deux fois :

```
401 crédité   = subtotal − remise_ligne
401 débité    = remise_entête + remise_ligne
→ dette nette = subtotal − remise_entête − 2 × remise_ligne
   alors que   total_amount = subtotal − remise_entête − remise_ligne
```

Le compte fournisseur était **sous-crédité de la remise ligne**. Effet de bord
opérationnel : une négociation de prix ponctuelle était imputée au pool de
ristourne accordé pour du volume, et vidait un solde que le contrôle de
suffisance refusait ensuite aux commandes légitimes (« Ristourne insuffisante »).

### P0-4 · TVA collectée sur un compte qui n'existe pas au SYSCOHADA

`postInvoiceIssued` créditait `4432`. Au SYSCOHADA révisé, `4432` est la
**TVA sur prestations de services** ; les ventes de biens relèvent de `4431`.
La migration 020 avait elle-même nommé `4431` « TVA facturée sur ventes
(collectée) » — le code et le schéma se contredisaient.

Plus grave, la famille 44 seedée par 020 est globalement décalée :

| Compte | Migration 020 | SYSCOHADA révisé |
|---|---|---|
| `4432` | TVA collectée à décaisser | TVA facturée sur prestations de services |
| `4433` | TVA à régulariser | TVA facturée sur travaux |
| `4451` | TVA récupérable sur achats de biens | TVA récupérable sur **immobilisations** |
| `4452` | TVA récupérable sur immobilisations | TVA récupérable sur **achats** |
| `4455` | Crédit de TVA reportable | TVA récupérable sur factures non parvenues |
| `4441` | *absent* | État, TVA due |
| `4449` | *absent* | État, crédit de TVA à reporter |

`4451` et `4452` sont **inversés**. Une DSF ou un dépôt e-bilan construit sur ces
numéros est faux en l'état.

### P1-5 · Éléments fiscaux stockés mais jamais comptabilisés

La migration 040 ajoute `excise_rate/amount`, `precompte_rate/amount`,
`tva_exemption_reason` et documente que la base TVA vaut HT + accises.
`generate_invoice` n'alimentait aucune de ces colonnes et `postInvoiceIssued`
n'en lisait aucune. Le droit d'accises était encaissé auprès du client à
l'intérieur du total et n'apparaissait jamais comme dette envers l'État.

Aucun compte d'escompte (`673` / `773`) n'existait — un escompte de règlement
n'avait d'autre issue que d'être noyé dans le CA.
Aucun compte `7019` non plus : un avoir client n'avait nulle part où aller.

### P1-6 · Trop-perçu : `411` en solde débiteur, `419` vide

Les deux chemins de paiement versent l'excédent dans `client_wallets`, qui est
la face opérationnelle de `419`. Le grand livre ne suivait pas :
`postInvoicePayment` créditait la totalité de l'encaissement à `411`.

Un client en surpaiement portait donc un solde **débiteur** sur son compte de
créance — un compte d'actif en négatif — pendant que `419` restait vide. Le
passif du bilan était sous-évalué de la somme de tous les portefeuilles clients.

### P1-7 · Intégrité, atomicité, arrondis

| Point | Constat |
|---|---|
| **Atomicité** | Correcte. `beginTransaction` par action, `rollBack` sur `UserFacingException` **et** `Throwable`, `FOR UPDATE` sur PO / BL / facture avant lecture-écriture. `lpc_rebate_balance($for_update = true)` verrouille bien le pool avant dépense. |
| **RBAC** | `generate_invoice`, `register_payment` et `validate_cash` n'étaient gardés que par `accounting.invoices.view`. Les permissions dédiées existaient et étaient déjà attribuées — personne ne les appelait. Un rôle en lecture seule pouvait émettre des factures et enregistrer des paiements. |
| **Arrondis** | `net = round(brut × (1 − tva − précompte))` jetait les deux composantes retenues. En les arrondissant séparément, le triplet peut dériver d'1 FCFA et `post_journal_entry` rejette l'écriture — annulant toute la réception pour un artefact d'arrondi. |
| **Écriture vide** | `post_journal_entry` compare `Σdébit` à `Σcrédit` : sans aucune ligne, `0 = 0` passe et l'écriture est estampillée `posted`. Atteignable dès que tous les montants calculés s'arrondissent à zéro (`addLine` ignore silencieusement une ligne nulle). |
| **Collision de référence** | `journal_entries.reference` est UNIQUE et les achats partagent le radical `JRN-AC-<ym>` sur tout un mois, avec un suffixe de 2 octets. Problème d'anniversaire : ~50 % de risque de collision dès ~300 écritures dans le mois. Le symptôme est une réception qui échoue sur clé dupliquée, illisible pour l'opérateur. |
| **Comparaison lâche** | `array_keys($buckets, max($buckets))[0]` compare en `==` : le résidu d'arrondi pouvait atterrir sur un compte de produit voisin qui compare seulement égal. |
| **Cohérence AR** | `validate_cash` sommait `amount` seul quand `register_payment` sommait `amount + air_withheld_amount`. Une facture réglée en partie par un client retenteur puis en espèces ne pouvait jamais passer `paid`, alors que `411` était bien soldé au grand livre. |

**Non traité, à arbitrer** — le COGS est valorisé sur `delivery_items.quantity`
(expédié) alors que la facture porte sur `delivered_quantity` (accepté). C'est
délibéré et documenté dans `postDeliveryCogs`, mais un refus partiel laisse
`6031` surévalué tant qu'aucune écriture ne suit le mouvement `in_return_emp`.

---

## 3. Correctifs appliqués

### `migrations/065_syscohada_reductions_and_vat.sql` *(nouveau)*

- Corrige la famille 44 aux libellés SYSCOHADA révisé ; seede `4441`, `4449`,
  `4454`, `4461`, `4473`, `7019`, `673`, `773` ; renomme `701` en « Ventes de
  marchandises ».
- **Assertion de pré-vol** : refuse de relibeller `4451/4452/4455` si une
  `journal_line` y pointe déjà — un relibellé n'est sûr que tant que le compte
  est vide. Sur cette base il l'est, aucun chemin de code ne les visait.
- Crée la ligne `chart_of_accounts` devant chaque compte (leçon de 038 §1b :
  `coaByOhada` joint les deux, un `ohada_accounts` seul est inutilisable).
- `purchase_orders` : `vat_rate`, `subtotal_ht`, `vat_amount`, `vat_recoverable`.
- `invoices` : `gross_subtotal`, `discount_amount`, `discount_note`,
  `escompte_rate`, `escompte_amount` ; `invoice_items` : `list_price`,
  `discount_amount`.
- `post_journal_entry` refuse désormais une écriture de moins de deux lignes ou
  de montant nul.

### `includes/classes/JournalPoster.php`

- `postGoodsReceipt` : paramètre `$vat_amount` (défaut 0) → `Dr 601 HT · Dr 4452 TVA · Cr 401 TTC`.
  Le crédit fournisseur ne bouge pas : aucun solde de dette n'est retraité.
- `postInvoiceIssued` : `4432 → 4431`, comptabilise accises (`4461`), précompte
  subi (`4473`) et escompte accordé (`673`) ; identité de réconciliation
  généralisée et message d'erreur explicite ; `source_type = 'invoice'` pour
  rendre la vente extournable.
- `postRebateAccrual` : `$vat_withheld` / `$precompte_withheld` (défaut 0) →
  `6019` crédité du **brut**, retenues portées en `4452` / `4473`. **`4098`
  conserve exactement le net**, donc le panneau Ristournes et le grand livre
  restent alignés et aucun historique n'est retraité.
- `postSalesCreditNote` *(nouveau)* : avoir client → `Dr 7019 · Dr 4431 · Cr 411`.
- `postInvoicePayment` : ventile l'encaissement au solde ouvert de la facture —
  `411` pour la dette, `419` pour l'excédent.
- Suffixe de référence porté à 4 octets ; `array_search(..., true)` pour le résidu.

### `includes/functions/procurement.php`

- `lpc_po_vat_context()` : taux figé sur la commande, repli sur le paramétrage
  courant pour les commandes antérieures à 065.
- `lpc_po_unit_cost()` : base de valorisation du stock, **partagée** par la
  réception et l'annulation — si un seul côté passait au HT, annuler une
  commande détricoterait une valeur différente de celle comptabilisée et
  laisserait le CUMP faux à jamais.

### `api/v1/inventory_controller.php` — `receive_po`

- CUMP valorisé au coût HT ; `$received_vat` calculé et transmis au poster.
- Ristourne : chaque composante arrondie au franc, **le net pris comme reste**,
  garantissant `net + TVA + précompte = brut` sans résidu.

### `api/v1/procurement_controller.php`

- `$rebate_spent` séparé de `$discount_amount` : seule la remise saisie tire sur
  le pool de ristourne. Les remises ligne restent dans `discount_amount` pour le
  reporting (KPI et document PO inchangés) mais ne touchent plus le grand livre.
- Fige `vat_rate` / `subtotal_ht` / `vat_amount` / `vat_recoverable` à la
  commande ; repli complet si 065 n'est pas encore appliquée.
- Annulation : inversion du CUMP sur la même base HT.

### `api/v1/invoices_controller.php`

- Report de la remise de commande au prorata de la valeur livrée, plafonné par
  commande ; TVA calculée sur la base **nette**.
- Refuse d'émettre plutôt que d'escamoter la remise si 065 n'est pas appliquée.
- `Rbac::requirePermission` sur `generate_invoice`, `register_payment`, `validate_cash`.
- `validate_cash` aligné sur `register_payment` pour le solde AR.

### `scripts/tests/syscohada_postings.test.py` *(nouveau)*

Le contrôle `books_balance.sql` répond « les livres s'équilibrent-ils ». Il ne
peut pas répondre « pour la bonne raison » — chacun des écarts ci-dessus
s'équilibrait parfaitement. Ce fichier encode la règle SYSCOHADA attendue pour
dix scénarios et vérifie l'équilibre, **le compte d'imputation** et le montant au
franc près, sans base de données ni PHP.

```
$ python3 scripts/tests/syscohada_postings.test.py
65 assertions passed across 10 scenarios.
```

---

## 4. Déploiement

1. **Sauvegarder la base** — 065 contient du DDL, non annulable.
2. `php scripts/migrate.php` (ou import de `065`). L'assertion de pré-vol
   s'arrête d'elle-même si `4451/4452/4455` portent déjà des écritures.
3. Vérifier avec le bloc *VERIFICATION* en fin de migration.
4. `python3 scripts/tests/syscohada_postings.test.py`
5. `mysql … < scripts/tests/books_balance.sql` — inchangé, doit rester à zéro.

**À décider avant mise en production** : les achats et ventes déjà comptabilisés
l'ont été TTC sur `601` et sur `4432`. Ces écritures sont des faits et ne se
réécrivent pas. Le rattrapage se fait par écriture de reclassement à la date du
jour — à quantifier sur l'exercice ouvert avec l'expert-comptable avant de
lancer la migration.

---

## Sources

- [Plan comptable SYSCOHADA révisé](https://plan-comptable-ohada.com/nouvelle-norme-2016/plan-comptable-syscohada.html)
- [SYSCOHADA — Plan de comptes (PDF officiel)](https://dgd.gov.gn/wp-content/uploads/2023/10/Ohada_syscohada_plan_comptable.pdf)
- [Compte 60 — Achats (RRR obtenus, 6019)](https://plan-comptable-ohada.com/nouvelle-norme-2016/compte/60.html)
- [Plan comptable SYSCOHADA révisé — recherche interactive](https://compta-genie.com/plan_syscohada_revise.php)
