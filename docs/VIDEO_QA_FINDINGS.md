# Training videos · findings to QA and fix, module by module

The work list the module video sessions start from (docs/TRAINING_VIDEOS.md §1.7: a video session is
QA, then fixes, then the video). On 18/11/2026 four read-only agents read the whole chain the story
video follows (one OPUR 20L bottle from Source du Pays to PROMETAL and into the books) and listed what
looks broken. **Nothing here is confirmed until a session reproduces it on the copy.** A session:

1. reproduces each finding of its module on the copy (crawler, then the real work by hand, §1.7);
2. fixes what is real, in the screen's own code, with a test where the repository has one for that area;
3. moves the row to docs/TRAINING_VIDEOS.md §7 (« Bugs found while filming ») with what was done, and
   deletes it here; a finding that is not real is deleted here with one line in its PR saying why;
4. leaves a finding that needs the owner (who may do what, an accounting rule) under « For the owner »
   below, with the question in plain words, and decides a sensible default so the video can be made.

Tags: **[V]** read in the code, **[I]** inferred, to test. Roles: DG = `admin` / `directeur_general`,
CF = `controleur_financier`, Cpt = `accountant`, Ops = `operations`. Live also has a `sales` role (34
permissions, nobody holds it yet) and a `driver` role (unused: LPC has no drivers).

How LPC trades, as the owner described it (18/11/2026), is in docs/TRAINING_VIDEOS.md §3: the story's
facts and the sessions' yardstick. Who does what today: the DG does leads and proposals, Ops does orders
and deliveries, the Comptable invoices and books, the CF approves and pays and owns the master data.

---

## 1 · Données de Base

All six rows were reproduced and fixed by the « Données de Base » video session (18/11/2026): see
docs/TRAINING_VIDEOS.md §7. Data seen on the copy, for the owner (table below): OPUR 10L's reference cost.
The « Eau » category is set to sell in packs, so the OPUR form shows « Unités par pack » (harmless, 1).

## 2 · Gestion des Achats

Reproduced on the copy and fixed by the « Gestion des Achats » video session (18/11/2026): 2.2, 2.4,
2.6, 2.7, 2.8, 2.10 and 3.1 (docs/TRAINING_VIDEOS.md §7); 2.5 was not a bug (owner #9). Left:

1. Decided 18/11 (owner #10): no approval step before an order goes out. Nothing to change.
3. Decided 18/11 (owner #1): Ops orders, the CF pays; the Comptable now pays too (owner #5, migration 132).
9. [V] No supplier invoice step: the order is the payable. `supplier_invoices` is referenced by TaxEngine
   but no migration creates it (see 13.3).
11. Decided 18/11 (owner #11): the supplier brings the rest later; a partial order stays open until fully
   received and is paid then.

## 3 · Stock & Emballages, Fiche de Stock

Rows 1 to 5 were reproduced and fixed by the « Stock & Emballages » video session (18/11/2026), and row 6
(« Stock Tampon » feeding « Seuil Min. ») by the « Proposition commerciale » session: see
docs/TRAINING_VIDEOS.md §7.

## 4 · Proposition commerciale (Base Clients & Devis, Studio Proposition)

All nine rows were reproduced on the copy and fixed by the « Proposition commerciale » video session
(18/11/2026): see docs/TRAINING_VIDEOS.md §7. Row 3 (quantities are monthly) was a fact, not a bug: the
signing page now says « Qté / mois ». Decisions taken with defaults are in the owner table (15 to 18).
Seen and left:

10. [V] Renaming a client (« Modifier ») does not rename its 411 account (the Données de Base session did
    it for suppliers).
11. [V] Live keeps active 411 accounts of clients deleted long ago (Societe Generale Bonapriso, DDLP SA,
    XYZ Ltd twice, JBS Praxis ×3, HOREB SARL, SCDP, CIMENCAM): no client points at them. For the
    Comptabilité 1 session to review with the Plan Comptable.
12. [V] The Studio's « Stock Tampon » paragraph on page 2 promises « Capacité de stockage dédiée »: it is
    now backed by the threshold rise, but nothing reserves stock for a client (by design: a devis
    reserves nothing).

## 5 · Ventes & Commandes

1. [V] **« Flotte LPC » BL needs a user with role `driver`** (`sales_controller.php:853-863`): LPC has
   none, so its own trucks cannot deliver. Fix: Flotte LPC takes a vehicle and a driver chosen among the
   employees (no login), as the owner removes driver accounts.
2. [V] **Only the DG can « Clôturer » a delivery** (`sales.deliveries.close`: admin, directeur_general,
   sales, driver). The owner: Ops does orders and deliveries.
3. [V] The order « Supprimer » posts FormData; the controller reads the action only from the URL or a
   JSON body (`sales_controller.php:23-30`): always « Action non spécifiée ».
4. [V] « Annuler » after a BL can never succeed (no way to cancel a BL; guard at `:1584`).
5. [V] **Line remises are subtracted twice**: `sales_orders.discount_amount` already includes them, yet
   it is subtracted again at BL signature (`signature_side_effects.php:427`) and on the invoice
   (`invoices_controller.php:766-803`).
6. [I] Empties owed may be doubled at BL signature: the ledger update has no site filter and can hit both
   the no-site row and « Siège Principal » (`signature_side_effects.php:371-397`).
7. [V] BL, CRE and quote-signature notifications go to roles `admin` and `finance`; `finance` does not
   exist, CF and Cpt are never told.
8. [V] Only the customer's signature (`sign_bl.php`, QR or WhatsApp) completes a BL and books refused
   fulls, empties returned, empties owed and the order's delivered status; only completed BLs can be
   invoiced. That is by design: the story shows PROMETAL signing.

## 6 · Flotte (Flotte & Maintenance, Relevé Carburant, Déclarer une Panne)

Reproduced on the copy and fixed by the « Flotte » video session (18/11/2026): 6.1 to 6.7 (docs/TRAINING_VIDEOS.md
§7; 6.6 per the owner's answer 6: fleet costs become expense requests in Gestion des Dépenses). Left:

8. [V] Capitalising a vehicle (Immobilisations « À Capitaliser (Flotte) ») posts no acquisition entry: the same as
   12.1, for the Comptabilité 2 session. Seen on the copy: the truck LT 742 KL waits in the queue.
10. [V] A visite technique has no type of its own: « Régler » on its alert files it as « Vidange / Révision » (the
   only type that renews the date), so its expense reads « Vidange / Révision LT 742 KL · Centre de visite
   technique ». Small: a « Visite technique » type renewing `technical_visit_expiry`, on the « Visite technique »
   category (6225).

## 7 · Gestion des Vides (CRE = Certificat de Restitution d'Emballages)

1. [V] **The 80 % rule is free text** in `proposals.empties_policy`; no empties code reads it. The ledger
   expects 100 % back: no return-rate figure against the client's agreed minimum, no write-off of the
   20 % LPC may keep short. The owner's business: LPC must recover at least the agreed share.
2. [V] Debt only grows when the full product has `linked_empty_id` (OPUR 20L has it on live).
3. [V] **The internal signature of a CRE applies everything** (status signed, total_in, owed, stock
   `in_return_emp`; `signatures_controller.php:282-311`); after it the client can no longer refuse
   (`reject_cre` requires `en_transit`, `cre_controller.php:289`). Help 069:144, 229-235 are out of date.
4. [V] A CRE with a site selected never reduces the debt: BL ledger rows have `site_id` NULL and the CRE
   update matches on site; later BL signings then update several rows (see 5.6).
5. [V] Vente Recyclage: `operations.recycling.sell` and `.override_price` are admin only by migration
   (live: admin, DG, Ops, sales hold `.sell`); no stock check, so empties stock can go negative; the
   movement reuses `out_delivery` (hence the wrong « Expédition Client » label, 3.5). Entry: Dr 571/552/521
   / Cr 7074, journal BQ, ref REC-… (`JournalPoster.php:1470-1530`).
6. [V] « Revenus Recyclage » checks hardcoded role names admin / finance / accountant
   (`cre_controller.php:546, 644`); the help says the Comptable sees it but the Comptable cannot open the
   page (`operations.empties.view`). Help 069:287 (« Valider la caisse chauffeur ») is out of date.
7. [V] « Historique » shows only the current user's CREs (`:453`); « Rapport PDF » and « Exporter » have no
   handler [I].

## 8 · Facturation & AR

1. [V] **Invoices post to the first 411 account in the chart, not the client's own** (`JournalPoster.php:294`,
   `:1787-1801`).
2. [V] **AIR booked twice**: the invoice posts Dr 411 net payable + Dr 4424 AIR / Cr 701x + Cr 4431; the
   payment posts Dr 521 + Dr 4424 AIR again / Cr 411 the full TTC (`invoices_controller.php:61-194`).
   For every PROMETAL invoice 4424 holds twice the AIR and 411 ends in credit by the AIR. Fix one side
   (the invoice already records the withholding).
3. [V] The PDF and the public invoice compute « Déjà réglé » from SUM(amount) without the AIR, so a fully
   settled invoice shows the AIR as still owed (`document_pdf.php:203`, `get_invoice.php:109`).
4. [V] Net 15 is never applied: the due date defaults to +30 days (`accounting-invoices.js:41-42`).
5. [V] Only AIR withholding exists: accises, précompte, the exemption reason and TVA withholding
   (`withholding_vat_agent`) are never filled. The owner says PROMETAL « withholds taxes »: confirm which.
6. [V] No reminder feature (only the bell « N facture(s) en retard »); aging buckets current / 0-30 /
   30-60 / 60+ and « Débiteurs critiques ».
7. [V] No CSRF check in `invoices_controller.php` (and `tax_controller.php`).
8. [V] The attestation is PROMETAL's: LPC records it in Déclarations Fiscales → « Retenues à la source »
   → « Charger une attestation de retenue à la source ». The request letter (« Demander (PDF) »,
   `_tax_attestation_letter.php:56`) selects `users.full_name/name/username`, none of which exist [V]:
   likely an error.

## 9 · Trésorerie & Banque, Gestion des Dépenses

9.1 to 9.6 and the owner's answer 6 (the fuel comparison, register 6.9) were reproduced on copy B and fixed by the
« Trésorerie & Dépenses » video session (18/11/2026): docs/TRAINING_VIDEOS.md §7. Left:

7. [V] Payroll and advances may take a treasury account below zero: on the copy, paying an advance from « Caisse
   Principale » (0 F) left it at −30 000, and « Marquer Payées » for a month from « CCA Bank » (0 F) at −460 944
   (found by the Salaires session). Transfers, expenses and supplier payments already refuse « Fonds
   insuffisants ». **Default decided:** refuse the same way in Gestion du Personnel, but only once CCA Bank and
   Caisse Principale carry their real balances on live (owner #20): until then refusing would block the real
   November payroll. For the Comptabilité 2 or the admin series session; nothing changed yet.
8. [V] No screen records money coming back into an account except a transfer: change returned from a fuel advance,
   a refund from a supplier, a bank interest. A fuel gap left by an advance larger than its fill-ups can only be
   cleared by an OD. Proposal: an « Entrée diverse » card on Opérations & Transferts (Finance, with its account and
   category, posted Dr 5x / Cr the chosen account), counted by the Carburant tab when its category is Carburant.
   Found by the Trésorerie session, 18/11/2026; not built.

## 10 · Gestion du Personnel (salaires)

Rows 1 to 5 done by the Salaires session (18/11/2026, PR #59): see docs/TRAINING_VIDEOS.md §7.

6. [V] The payroll lists only employees who have a login: the grid joins `users` and `hr_payslips` keys on
   `users.id` (`payroll_controller.php` get_payroll_grid, generate_month). The drivers the Flotte session plans
   as employees without a login (owner #12) would never appear in « Génération de la Paie ». Before LPC adds
   them, key payroll and advances on `employees.id` (a migration of `hr_payslips`/`hr_advances`), or give each
   driver a login (the owner does not want driver accounts).

## 11 · Comptabilité 1 (Plan Comptable, Bilan d'Ouverture, Écritures, Grand Livre)

1. [V] Every automatic entry posts at once (no draft): the « File d'Attente (Brouillards) » holds manual
   drafts only. « Validations en attente » and the closing check « Aucune écriture en attente
   d'approbation » look for status `pending`, which does not exist (draft, posted, reversed):
   always 0 (`YearEndClosing.php:139`).
2. [V] `postSalesCreditNote` has no caller: no credit-note entries.
3. See 3.1 (stock accounting, 601 vs 31x/6031), 8.1 (invoice on the first 411), 8.2 (AIR twice), 4.6
   (second 411 on conversion): the ledger checks belong here if their module sessions did not fix them.

## 12 · Comptabilité 2 (Immobilisations, Budgets & Performance)

1. [V] Capitalising an asset posts no acquisition entry (`fixed_assets_controller.php:254-359`).
2. [V] Running depreciation twice in a month fails (unique reference); the closing « depreciation » phase
   books another 12 months without checking the monthly runs: depreciation counted twice.
3. [V] Budget actuals come from fuel logs, maintenance, invoices and expenses, not from the ledger
   (`budget_controller.php:65-111`); the MD revenue target and « Écart budgétaire global » read
   `budgets` / `budget_lines`, which nothing writes to.

## 13 · Comptabilité 3 (Déclarations Fiscales, États Financiers, Clôture)

1. [V] **Tax payments never reach the ledger**: `tax_controller.php:794, 797, 803` query
   `chart_of_accounts.ohada_prefix`, `.ohada_account_number` and `treasury_accounts.chart_of_account_id`
   (the real column is `coa_account_id`); the error is swallowed (`:227-233`) and the declaration turns
   « paid » with no entry, no treasury movement, no balance change.
2. [V] Even fixed they would hit the wrong accounts: TVA paid from 4432 while sales TVA is in 4431; DIPE
   to 447 and CNPS to 431 while payroll books 432 and 433 (`:210-219` vs `JournalPoster::postPayslipAccrual`).
   Since 18/11/2026 (Salaires) 432's monthly credit equals the DIPE to the franc, FNE included (copy, Nov.
   2026: 76 727 both), and 433's equals the CNPS declaration: paying the DIPE should debit 432 and the CNPS 433.
3. [V] TVA overstated: the deductible part reads `supplier_invoices` (missing on live: always 0); the
   carry-over column is missing; TSR always 0.
4. [V] Déclarations Fiscales is gated only on `accounting.invoices.view` (the `sales` role can approve and
   pay declarations); no CSRF.
5. [V] Closing: « Assistant Clôture » and « Clôturer l'Exercice » show only when the role name is exactly
   `admin`; the old closing likely doubles opening balances [I]; profit incorporation ignores class 8;
   the year picker is hardcoded to 2026 and 2025. The Comptable holds `closing.run` / `reopen` on live,
   which migration 088 gave to admin only.

## 14 · Tableaux de bord & Rapports Consolidés

1. [V] Empties KPIs (MD « Récupération d'emballages », Ops « Retour d'emballages ») read
   `cash_reconciliations`, which nothing inserts into: always 0 %. Should read `client_empties_ledger`
   (and, once 7.1 lands, compare to each client's agreed minimum).
2. [V] Ops delivery KPIs filter delivery status `delivered` / `invoiced`, which do not exist (draft,
   dispatched, driver_confirmed, completed, cancelled): always 0.
3. [V] Cash KPIs leave out Mobile Money (552); AR KPIs count partly paid invoices at their full amount.
   Confirmed by the Trésorerie session (18/11/2026): `finance_dashboard_api.php` (lines 188, 342, 356, 546) and
   `md_dashboard_api.php` (398, 528) read 52x and 57x only. This, not MTN's missing COA link, is why the
   dashboards' cash reads 0 F: MTN posts to 552 by its type, and since migration 139 its 552 balance equals the
   treasury screen (654 320 F on the copy).
4. [V] « Alertes stock » flags any product under 100 units, not its « Seuil Min. ».
5. [V] Vue Ventes reads orders created by the signed-in user: with Ops entering orders and no Sales login,
   the DG sees 0 (the sales role will see only their own).

## 15 · Administration (Paramètres, Utilisateurs, Rôles, Centre d'Aide, Journal d'Erreurs)

The admin series' QA (track C, 18/11/2026: the crawler as JBS Praxis Support and the DG on every admin page,
then by hand: a setting changed and put back, a role created, granted and deleted, an account locked while its
owner was signed in, a session closed, an article draft published and deleted, a video uploaded, an error group
marked fixed). What was fixed is in docs/TRAINING_VIDEOS.md §7. Left:

1. [V] Préférences hid 19 settings no screen reads (migration 137, values kept): payment terms, quote validity,
   currency, late penalty, quote terms, date and number formats, the purchase-order and credit-note prefixes
   (orders are numbered PO-…; there are no credit notes), the three alerts, negative stock, password expiry,
   2FA for admins, audit retention (the purge keeps years, not 365 days). Any the owner wants is wired, then shown
   again (`is_active = 1`). Owner question 21.
2. [V] Data on live, Identité de l'Entreprise: the footer « Mentions légales » reads RCCM « CM-DLA-03-2026-**8**-01777 »
   where the RCCM field reads « …-**B**-01777 »; « Numéro employeur CNPS » and « Code activité (APE) » are empty,
   and « Centre des impôts » too (« CIME Littoral II » is only the field's example). Owner question 22.
3. [V] A role's new permissions reach a person at their next sign-in (permissions are kept with the session).
   The help says so; closing their session (Sessions → Déconnecter) applies it at once.
4. [V] The Journal d'Erreurs labels the app's own error lines « Info » (e.g. a database refusal): only PHP's own
   warnings and fatals get a level. Not changed.
5. [V] A role « Directeur Général » (`directeur_general`, 133 permissions) exists and nobody holds it: the DG and
   JBS Praxis Support are both « Administrateur ». Owner question 23.
6. [V] The other Journal d'Erreurs articles (KPI, santé du logging, niveaux, filtres, groupe, copier, cacher) and
   the English bodies of the rewritten admin articles still speak of files, regex and migrations.
7. [V] Centre d'Aide (vidéos): « Mise à jour 2026-11-18 12:44 » (ISO date) and a command line for the filming PC
   under the upload box. Small; not changed.
8. Not real (copy only): live's AI keys are encrypted with live's key, so the copy logs « Crypto::decrypt » when a
   page reads them, and the copy has no uploaded logos (uploads are not in the export). The filming clears the
   copy's AI keys and draws the logos from `assets/img/full_logo.svg` on the copy.

---

## For the owner (decisions, with the default a session uses meanwhile)

| # | Question | Default used for the videos |
| --- | --- | --- |
| 1 | Who places supplier orders: Ops, the CF, or both? Today Ops and the Comptable can, the CF cannot. | Decided 18/11: Operations orders and receives; the Contrôleur Financier pays (unchanged). |
| 2 | Should Ops close deliveries (`sales.deliveries.close`)? | Yes: the owner said Ops does the orders; granted by migration, listed in the PR. |
| 3 | Stock accounting: perpetual (31x at reception) or periodic (601 then variation at closing)? | Decided 18/11: perpetual, as built in migration 131 (Gestion des Achats). |
| 4 | Which taxes does PROMETAL withhold: AIR only, or TVA too? The app only handles AIR. | AIR only, at the rate set on the client. |
| 5 | Should the Comptable approve advances and pay suppliers (`approve_advance`, `procurement.approve`), and keep `closing.run` / `reopen`? | Decided 18/11: the Comptable also pays suppliers (`inventory.procurement.approve`, migration 132, done) and approves advances (`hr.payroll.approve_advance`, for the Salaires session); closing rights unchanged. |
| 6 | Fleet costs (fuel, repairs): post them to the ledger from Flotte, or keep typing them in Dépenses? | Decided 18/11: a fuel, repair or servicing cost entered in Flotte creates a linked expense request in Gestion des Dépenses, which Finance validates and pays (done, migration 133, Flotte session). The per-period comparison and its alert: done (Gestion des Dépenses → Carburant, migration 139, Trésorerie & Dépenses session). |
| 7 | OPUR 10L's « Coût de référence » reads 100 F while it sells at 1 000 F and no supplier has a 10L tariff: what does LPC pay for a 10L? (Données de Base → Produits, now editable by the DG and the CF.) | Decided 18/11: bought at 850 F, sold by default at 1 100 F (some clients 1 200 F, as client tariffs); migration 134 sets both. Still to verify: its empties at 100 F without cork, 200 F with cork (today one « Emballage 10L » at 100 F). |
| 8 | The never-applied « prix de repli » (a floor for products with no default price) was removed from the screen rather than wired into sales. Want it wired? | Decided 18/11: keep it removed. |
| 9 | The ristourne: on what base, and what is withheld? | Decided 18/11 (the DG's rule of 31/07): « 3 % du montant d'achat hors taxe »; order amounts are TTC, so HT = amount ÷ 1,1925; 21,25 % withheld (19,25 % TVA to 4452, 2 % précompte to 4473), net to 4098, gross to 6019; Eau 3 % flat, other products 3/4/5 % by each order's own total. The code does this (a change of 18/11 that dropped it was reverted the same day); past accruals are correct, nothing to post. Open for the accounting sessions: should reception split the recoverable TVA (Dr 601 HT + Dr 4452 / Cr 401 TTC)? |
| 10 | Should a purchase order be approved (DG or CF) before it goes to the supplier? | Decided 18/11: no approval before an order goes to the supplier. |
| 11 | A supplier delivers only part of an order and will not deliver the rest: how should the remainder be closed? | Decided 18/11: the supplier brings the rest later; the order stays open until fully received. |
| 12 | LPC's vehicles and drivers: the delivery truck's plate, make, insurance and roadworthiness dates; the drivers (e.g. Nelson Tanyu) as employees in Gestion du Personnel (no login needed). Live has no vehicle and no driver yet. | The Flotte video uses an example truck on the copy (LT 742 KL, Toyota Dyna) and Nelson Tanyu, added on copy B with his name and post (Chauffeur) only, as the owner allowed (18/11). **The owner adds the drivers and the real truck on live.** |
| 13 | **Live payroll of November 2026** was calculated on 03/11 when Daniel Massomba's and JBS Praxis Support's records (the IT partner's account) had no salary: each got 50 000 F typed as a prime on a base of 0. Their records now say 150 000 F and 0. Should November be undone and recalculated on live (« Annuler la Paie », then « Calculer & Valider Paie »)? And were October's and November's salaries already paid outside the app (none is marked paid)? | The video undoes and recalculates November on the copy and pays it by bank. On live nothing is changed: the owner or the CF decides. If October was paid, « Marquer Payées » for October records it (bank, at the real date). |
| 14 | October and November 2026 were booked before the FNE (1 %) was: the DIPE declares 5 300 F (Oct.) and 6 300 F (Nov.) that 432 never received. | Recalculating November books it. For October, the Comptable passes one OD (Dr 6412 / Cr 432, 5 300 F) if October is kept as is. |
| 15 | A signed devis's prices become the client's tariff even when they equal the default price. Fine, or only negotiated ones? | Every signed price becomes the tariff: the contract price holds if the default price later changes. A person changes it in Données de Base (Tarifs Clients) or by repricing an order. |
| 16 | The buffer a signed devis promises raises the product's « Seuil Min. » (1 667 a month × 2 weeks = 770 for PROMETAL). It never lowers it. Keep? | Yes: the stock alert fires before LPC runs short of what it promised. Lower it by hand in Données de Base when a contract ends. |
| 17 | Should a new client start as Prospect? | Yes: a client is a prospect until it signs a devis (then Actif by itself) or someone presses « Convertir ». |
| 18 | SMART LOGISTICS and BOULANGERIE SAKER signed devis before this change (Net 30, 1 pour 1). | Migration 137 carries their terms onto them (Net 30, 100 %), not their prices: orders may have moved the prices since. |
| 19 | **Where did MTN MOBILE MONEY's 1 000 000 F come from?** It was entered on 03/11/2026 as the account's opening balance and posted nothing. Migration 139 posts it (Dr 552 / Cr 471 « Compte d'attente », dated 03/11) so the ledger matches the screen; 471 now waits to be cleared to its real origin: share capital (101), an associate's current account (4561, the DG's money?), a loan, another bank. | 1 000 000 F stays in 471 until the owner says; the Comptable then passes one OD (Dr 471 / Cr the right account). The MTN account's COA link was not changed: it posts to 552 by its type already. |
| 20 | **CCA Bank and Caisse Principale - Maison read 0 F** on live although LPC surely has money in them. What were their balances on the day the app started, and on what date? (9.7 waits on this: payments beyond the balance cannot be refused while the balances are not real.) | Nothing entered. Once known, the CF enters each as a correction (or the DG through Bilan d'Ouverture if the year allows), and Gestion du Personnel starts refusing payments beyond the balance like the other modules. |
| 21 | Paramètres → Préférences showed 19 settings that change nothing (finding 15.1). They are hidden, their values kept. Which should work (for example the default payment terms, 2FA for admins, password expiry)? | Hidden until wired; the admin video does not show them. |
| 22 | Identité de l'Entreprise on live: the footer's RCCM ends « 8-01777 » and the field « B-01777 »; CNPS employer number, APE code and tax office are empty. Which RCCM is right, and what are the others? | Nothing changed on live; the video shows the page as it is and does not read those fields out. |
| 23 | The « Directeur Général » role (`directeur_general`) has nobody; the DG signs in as « Administrateur », like JBS Praxis Support. Keep one role for both, or give the DG his own role (and delete the unused one)? | Unchanged; the video says both hold « Administrateur ». |
