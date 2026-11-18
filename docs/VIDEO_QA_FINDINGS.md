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

Rows 1 to 5 were reproduced and fixed by the « Stock & Emballages » video session (18/11/2026): see
docs/TRAINING_VIDEOS.md §7. Left:

6. [V] Nothing links a proposal's « Stock Tampon (Semaines) » to `min_stock_level` (« Seuil Min. »).
   Not done here: it is a proposal-side setting, for the Proposition commerciale session (§4) to wire or
   to explain. Meanwhile « Seuil Min. » is set per product in Données de Base (50 on every product).

## 4 · Proposition commerciale (Base Clients & Devis, Studio Proposition)

1. [V] **Acceptance creates nothing downstream** (`signature_side_effects.php:471-504`): no client price
   list, no link to orders (`sales_orders.proposal_id` is never written), no empties commitment (the 80 %
   policy is never read outside the proposal files), Net 15 never applied. The story needs at least the
   negotiated prices, the payment terms and the empties target carried to the client on acceptance.
2. [V] The template's Article 1 says « 1 vide pour 1 plein » whatever policy is chosen
   (`proposal_template.php:150`).
3. [V] Quantities are monthly (« Qté (Mensuelle) »): PROMETAL's line is about 1 667 bottles a month.
4. [V] A proposal is written straight as `sent` (`create_proposal.php:68`); declined (« Je souhaite
   discuter », status `rejected`) shows as « Envoyé » in the list; the detail says « Signé » and locks
   after an LPC-only visa while the list says « Envoyé · Visé LPC » (`proposal_detail.php:155-203`).
5. [V] On a network error `submitProposal` opens a dummy link `token=sec_a8f93j2k`; the quote link is
   hardcoded to `https://bureau.lpc.cm` (`crm-clients.js:220-235`).
6. [V] New clients are always saved as « Prospect » (status field hidden, `crm-clients.js:133, 410-423`);
   « Convertir » then creates a **second** 411 account (`convert_client.php:35-56`).
7. [V] Client list columns « Dette Financière » and « Dette Emballages » are hardcoded « 0 FCFA » /
   « 0 Vides » (`crm-clients.js:306,310`); a static demo row « Prometal S.A. 450,000 FCFA / 15 Vides » is
   in the HTML before the list loads (`clients.php:276-303`).
8. [V] An invalid AIR rate is swallowed as « Erreur serveur » (`create_client.php:68, 123-130`); « Devis »
   and « Nouveau Client » show to roles that are then refused.
9. [V] The shared product picker (quotes, orders, Données de Base tariffs) shows the linked empty's price as
   « + consigne 200 FCFA » (`lpc-product-picker.js`): LPC sells the bottle with no deposit, and that price is
   what a recycler pays for the empty (seen filming Données de Base, 18/11/2026).

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
9. For the Trésorerie & Dépenses session (owner #6, second half): Finance's fuel money against Operations' fuel
   logs, per period, and the alert when they differ. The link it builds on: `expenses.source_type`
   ('fleet_fuel' | 'fleet_maintenance'), `source_id`, `vehicle_id`; `fuel_logs.expense_id`,
   `vehicle_maintenance.expense_id` (migration 133).

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

1. [V] Treasury actions (transfers, expenses, reconciliation, opening balances) check only
   `accounting.cashflow.view`; the treasury, tax, closing, journal, budget and opening-balance APIs have
   no CSRF check.
2. [V] An account's opening balance (« Solde Initial Ouverture ») posts no journal entry
   (`treasury_controller.php:157-160`): with MTN MOBILE MONEY's missing COA link (§7 of the runbook), the
   ledger cash and the treasury screen disagree.
3. [V] Recycling cash shows as money going out: `in_recycling_sale` is missing from the « in » types
   (`treasury_controller.php:88`).
4. [V] Expenses have no approval: a « Payé » save posts the entry with only `expenses.create`, so Ops can
   book paid expenses without `mark_paid`.
5. [V] Rapprochement: « Demande de correction » → « Soumettre à l'Admin » creates a request no endpoint
   ever approves (`:411-416`).
6. [V] « Retour de Tournée »: wrong join for the expected cash, fake 350 000 F « simulation » data on
   error, validating does not mark invoices paid (`:48-101, 289`). A driver screen: likely goes with the
   driver removal.
7. [V] Any payment may take a treasury account below zero: on the copy, paying an advance from « Caisse
   Principale » (0 F) left it at −30 000, and « Marquer Payées » for a month from « CCA Bank » (0 F) at
   −460 944. No screen warns. Partly the missing opening balances (9.2); decide whether a payment beyond
   the balance is refused or only warned (found by the Salaires session, 18/11/2026).

## 10 · Gestion du Personnel (salaires)

All five rows done by the Salaires session (18/11/2026, PR of `claude/video-10-salaires`): see
docs/TRAINING_VIDEOS.md §7.

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
4. [V] « Alertes stock » flags any product under 100 units, not its « Seuil Min. ».
5. [V] Vue Ventes reads orders created by the signed-in user: with Ops entering orders and no Sales login,
   the DG sees 0 (the sales role will see only their own).

---

## For the owner (decisions, with the default a session uses meanwhile)

| # | Question | Default used for the videos |
| --- | --- | --- |
| 1 | Who places supplier orders: Ops, the CF, or both? Today Ops and the Comptable can, the CF cannot. | Decided 18/11: Operations orders and receives; the Contrôleur Financier pays (unchanged). |
| 2 | Should Ops close deliveries (`sales.deliveries.close`)? | Yes: the owner said Ops does the orders; granted by migration, listed in the PR. |
| 3 | Stock accounting: perpetual (31x at reception) or periodic (601 then variation at closing)? | Decided 18/11: perpetual, as built in migration 131 (Gestion des Achats). |
| 4 | Which taxes does PROMETAL withhold: AIR only, or TVA too? The app only handles AIR. | AIR only, at the rate set on the client. |
| 5 | Should the Comptable approve advances and pay suppliers (`approve_advance`, `procurement.approve`), and keep `closing.run` / `reopen`? | Decided 18/11: the Comptable also pays suppliers (`inventory.procurement.approve`, migration 132, done) and approves advances (`hr.payroll.approve_advance`, for the Salaires session); closing rights unchanged. |
| 6 | Fleet costs (fuel, repairs): post them to the ledger from Flotte, or keep typing them in Dépenses? | Decided 18/11: a fuel, repair or servicing cost entered in Flotte creates a linked expense request in Gestion des Dépenses, which Finance validates and pays (done, migration 133, Flotte session). The per-period comparison and its alert: Trésorerie & Dépenses session. |
| 7 | OPUR 10L's « Coût de référence » reads 100 F while it sells at 1 000 F and no supplier has a 10L tariff: what does LPC pay for a 10L? (Données de Base → Produits, now editable by the DG and the CF.) | Decided 18/11: bought at 850 F, sold by default at 1 100 F (some clients 1 200 F, as client tariffs); migration 134 sets both. Still to verify: its empties at 100 F without cork, 200 F with cork (today one « Emballage 10L » at 100 F). |
| 8 | The never-applied « prix de repli » (a floor for products with no default price) was removed from the screen rather than wired into sales. Want it wired? | Decided 18/11: keep it removed. |
| 9 | The ristourne: on what base, and what is withheld? | Decided 18/11 (the DG's rule of 31/07): « 3 % du montant d'achat hors taxe »; order amounts are TTC, so HT = amount ÷ 1,1925; 21,25 % withheld (19,25 % TVA to 4452, 2 % précompte to 4473), net to 4098, gross to 6019; Eau 3 % flat, other products 3/4/5 % by each order's own total. The code does this (a change of 18/11 that dropped it was reverted the same day); past accruals are correct, nothing to post. Open for the accounting sessions: should reception split the recoverable TVA (Dr 601 HT + Dr 4452 / Cr 401 TTC)? |
| 10 | Should a purchase order be approved (DG or CF) before it goes to the supplier? | Decided 18/11: no approval before an order goes to the supplier. |
| 11 | A supplier delivers only part of an order and will not deliver the rest: how should the remainder be closed? | Decided 18/11: the supplier brings the rest later; the order stays open until fully received. |
| 12 | LPC's vehicles and drivers: the delivery truck's plate, make, insurance and roadworthiness dates; the drivers (e.g. Nelson Tanyu) as employees in Gestion du Personnel (no login needed). Live has no vehicle and no driver yet. | The Flotte video uses an example truck on the copy (LT 742 KL, Toyota Dyna) and Nelson Tanyu, added on copy B with his name and post (Chauffeur) only, as the owner allowed (18/11). **The owner adds the drivers and the real truck on live.** |
| 13 | **Live payroll of November 2026** was calculated on 03/11 with Daniel Massomba at 50 000 F and JBS Praxis Support (the IT partner's account) at 50 000 F; their records now say 150 000 F and 0. Should November be undone and recalculated on live (« Annuler la Paie », then « Calculer & Valider Paie »)? And were October's and November's salaries already paid outside the app (none is marked paid)? | The video undoes and recalculates November on the copy and pays it by bank. On live nothing is changed: the owner or the CF decides. If October was paid, « Marquer Payées » for October records it (bank, at the real date). |
| 14 | October and November 2026 were booked before the FNE (1 %) was: the DIPE declares 5 300 F (Oct.) and 6 300 F (Nov.) that 432 never received. | Recalculating November books it. For October, the Comptable passes one OD (Dr 6412 / Cr 432, 5 300 F) if October is kept as is. |
