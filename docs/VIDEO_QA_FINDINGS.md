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
11. Done: migration 144 (decision 13) deleted those 411 rows of deleted clients (checked by the Comptabilité 1
    session on copy C: none is left).
12. [V] The Studio's « Stock Tampon » paragraph on page 2 promises « Capacité de stockage dédiée »: it is
    now backed by the threshold rise, but nothing reserves stock for a client (by design: a devis
    reserves nothing).

## 5 · Ventes & Commandes

All eight rows done 18/11/2026 (Ventes & Commandes session, PR « video-05-ventes », migrations 142 and 143):
5.1 to 5.7 fixed and moved to docs/TRAINING_VIDEOS.md §7; 5.8 (only the client's signature completes a BL)
is the design the video shows. 5.6 could not be reproduced on the copy (PROMETAL has no site row) and was
fixed from the code.

## 6 · Flotte (Flotte & Maintenance, Relevé Carburant, Déclarer une Panne)

Reproduced on the copy and fixed by the « Flotte » video session (18/11/2026): 6.1 to 6.7 (docs/TRAINING_VIDEOS.md
§7; 6.6 per the owner's answer 6: fleet costs become expense requests in Gestion des Dépenses). Left:

10. [V] A visite technique has no type of its own: « Régler » on its alert files it as « Vidange / Révision » (the
   only type that renews the date), so its expense reads « Vidange / Révision LT 742 KL · Centre de visite
   technique ». Small: a « Visite technique » type renewing `technical_visit_expiry`, on the « Visite technique »
   category (6225).

## 7 · Gestion des Vides (CRE = Certificat de Restitution d'Emballages)

All seven rows done 18/11/2026 (Gestion des Vides session, migrations 150 and 151), moved to
docs/TRAINING_VIDEOS.md §7. Not real or already gone: 7.2 (debt grows only for a full product with
`linked_empty_id`: OPUR 20L and OPUR 10L both have one; that is the design), the « Rapport PDF » and
« Exporter » buttons of 7.7 (no longer on the page). 7.5's « own movement type » is not added: the sale
keeps `out_delivery` with `reference_type = 'recycling_sale'`, which every stock screen reads since 3.5.

## 8 · Facturation & AR

Reproduced on copy C and fixed by the « Facturation & AR » video session (track C, 18/11/2026): 8.1, 8.2, 8.3, 8.6,
8.7, 8.8, 8.9, 8.10 and 8.11 (the credit note, was 11.2), with what the QA found (docs/TRAINING_VIDEOS.md §7). 8.4
was done by Proposition (the due date follows the client's terms: Net 15 → 03/12/2026 for an invoice of 18/11).
Left:

5. [V] Only AIR withholding is handled: accises, précompte and TVA withholding (`withholding_vat_agent`) are never
   filled. PROMETAL withholds AIR only (owner's answer 4), so nothing is needed for it.
12. [V] A credit note is limited to what the invoice still owes: an invoice already paid is refunded, and no
   screen records a refund (money going back to a client). Default: the avoir is refused with that sentence; a
   refund screen is for later (the same need as 9.8, money coming back).
13. [V] An invoice paid before migration 152 with AIR withheld kept its double AIR (Dr 4424 / Cr 411 a second time).
   On 17/11 live had no payment; migration 152's header gives the query and the OD to pass if one exists.
14. Done (Comptabilité 3): the attestation form has labels and proposes the current month.
16. Done (Comptabilité 3): « AIR retenu à récupérer » reads 4424 (9 810 F on copy A until the AIR declaration uses it).
15. Not real (copy only): the help drawer's video poster answered 404 on copy C (the copy's videos folder does not
   hold the file); the Responsable des Opérations gets 403 on Facturation and Déclarations Fiscales, as intended.

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
9. [D] The owner's decisions 12 to 15 (18/11/2026, after reviewing live Trésorerie & Banque and the Plan Comptable):
   done by the « Trésorerie, plan comptable et emprunts » session (track B, migrations 144 and 145): each treasury
   account its own sub-account and its past lines moved there; 26 unused sub-accounts deleted; `coaByOhada` takes the
   parent's own row; the Plan Comptable rebuilt; « Emprunts & Financements » built; the 1 000 000 F of MTN recorded as
   Smart Logistics' loan, waiting for the DG. See docs/TRAINING_VIDEOS.md §7.

## 10 · Gestion du Personnel (salaires)

Rows 1 to 5 done by the Salaires session (18/11/2026, PR #59), row 6 by the admin series' session (migration 146):
see docs/TRAINING_VIDEOS.md §7. Left: driver debts (`driver_debts.driver_id`) are still kept by login, so an
employee without one has none deducted; the data wipe's own-rows list removes payslips and advances by login
(`DataWipeMap::STAFF_OWN`), not those of an employee who never had one.

## 11 · Comptabilité 1 (Plan Comptable, Bilan d'Ouverture, Écritures, Grand Livre)

Reproduced on copy C and fixed by the « Comptabilité 1 » video session (track C, 18/11/2026): 11.1 (drafts are
what waits), 11.4 (a cancelled entry stays posted beside its mirror; migration 147 puts back the stamped ones), and
what the QA found on the four pages (docs/TRAINING_VIDEOS.md §7). 11.2 belongs to Facturation: it is now 8.11.
Left:

3. Done: 8.1 and 8.2 (Facturation session, migration 152): each client's own 411, the AIR once; the Balance des
   Tiers shows each client. 3.1 (Stock) and 4.6 (Proposition) were done earlier.
5. Done (Comptabilité 3): invoices and credit notes post in VT (Facturation), and every treasury movement in CA
   for a cash box, BQ for a bank or Mobile Money (`JournalPoster::journalForTreasury`).
6. Moved to 13.8 (opening receivables at go-live) and owner table 34.

## 12 · Comptabilité 2 (Immobilisations, Budgets & Performance)

Reproduced on copy B and fixed by the « Comptabilité 2 » video session (18/11/2026): 12.1 to 12.3, and 6.8 with
12.1 (docs/TRAINING_VIDEOS.md §7). Left, found on the way:

4. [V] Gestion des Dépenses' per-category budget (`expenses_controller.php` « dashboard » and the request form's
   « budget du mois ») still reads `budget_lines`, which nothing writes: it shows no budget. A budget line spans
   several categories, so it cannot simply take a line's target: decide whether Dépenses shows the budget line's
   month (« Carburant & transport : 75 000 / 100 000 ») or nothing.
5. [V] The Vue Ventes targets (`sales_dashboard_controller.php`, `performance_targets`) have had no screen to set
   them since migration 091: « Objectif non défini » for ever. For Tableaux de bord (14): a revenue target per
   month could come from the « Chiffre d'affaires » line.
6. [V] Nothing pays a 4812 « Fournisseurs d'immobilisations » balance from the app: an asset bought on credit is
   settled by an OD in Écritures (Dr 4812 / Cr 5x) and the treasury movement by hand. A « Payer » on the register
   row, like « Payer Fournisseur » in Achats, would close the loop.
7. Done (Comptabilité 3): reversing a closing depreciation deletes the months it logged, so a run books them again.

## 13 · Comptabilité 3 (Déclarations Fiscales, États Financiers, Clôture)

Reproduced on copy A and fixed by the « Comptabilité 3 » video session (track A, 18/11/2026): 13.1 to 13.5, 8.14,
8.16, 11.5, 12.7, and what the QA found on the two pages (docs/TRAINING_VIDEOS.md §7; migration 157,
`scripts/tests/tax_closing.test.php`, five new scenarios in `syscohada_postings.test.py`, two checks in
`books_balance.sql`). 13.3's TSR is not real for LPC: it reads foreign services LPC does not buy (always 0, said on
the line). Live: on 17/11 no declaration was marked paid, and live data is test data (owner answer 16). Left:

6. [V] The Vue Dirigeant's ratios (`executive_summary_data.php`) do not follow the Compte de Résultat: copy A,
   November 2026, EBE 34 287 F (7,7 %) while the SIG reads −1 324 733 F (personnel charges left out). For
   Tableaux de bord (14).
7. [V] « LPC assujetti à la TVA »: the declarations read `TaxEngine::settings()['tva_liable']`, i.e. the preference
   `tax_tva_liable` (Paramètres → Fiscal, « Assujetti à la TVA ») mirrored into `company_tax_settings.tva_liable`. It
   still defaults to on (« 1 », migration 034); the TVA switch session (track C, owner answer 19) turns it off by
   default or makes its own switch drive that value. Until then a copy declares the ristourne's 1 433 F on 4452 as
   a TVA credit to carry.
8. [V] Opening receivables at go-live (was 11.6): a client's or supplier's balance keyed in Bilan d'Ouverture reaches
   its 411 / 401 sub-account but no invoice or order exists to collect or pay it. A year closed in the app is fine
   (the À-nouveaux carry each sub-account and old invoices stay open). For the go-live, see owner table 34.

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
6. [V] The Ops dashboard's driver columns (`ops_dashboard_api.php:176, 219, 257, 279, 536`) and the MD's
   (`md_dashboard_api.php:366`) join `deliveries.driver_id` to users. Since migration 142 an LPC delivery names
   its driver in `deliveries.driver_employee_id` (an employee, no login): those columns read empty. Join
   employees as `sales_controller.php` does (`COALESCE(employee, user)`).

## 15 · Administration (Paramètres, Utilisateurs, Rôles, Centre d'Aide, Journal d'Erreurs)

The admin series' QA (track C, 18/11/2026: the crawler as JBS Praxis Support and the DG on every admin page,
then by hand: a setting changed and put back, a role created, granted and deleted, an account locked while its
owner was signed in, a session closed, an article draft published and deleted, a video uploaded, an error group
marked fixed). What was fixed is in docs/TRAINING_VIDEOS.md §7. Left:

1. [V] Préférences hid 19 settings no screen reads (migration 141, values kept): payment terms, quote validity,
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
7. [V] Centre d'Aide (vidéos): a command line for the filming PC under the upload box. Small; not changed (the ISO
   date and the list's width were fixed, runbook §7).
8. Not real (copy only): live's AI keys are encrypted with live's key, so the copy logs « Crypto::decrypt » when a
   page reads them, and the copy has no uploaded logos (uploads are not in the export). The filming clears the
   copy's AI keys and draws the logos from `assets/img/full_logo.svg` on the copy.

---

## For the owner (decisions, with the default a session uses meanwhile)

| # | Question | Default used for the videos |
| --- | --- | --- |
| 1 | Who places supplier orders: Ops, the CF, or both? Today Ops and the Comptable can, the CF cannot. | Decided 18/11: Operations orders and receives; the Contrôleur Financier pays (unchanged). |
| 2 | Should Ops close deliveries (`sales.deliveries.close`)? | Decided 18/11: yes. Granted by migration 142 (Ventes & Commandes, done). |
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
| 16 | The buffer a signed devis promises raises the product's « Seuil Min. » (1 667 a month × 2 weeks = 770 for PROMETAL). It never lowers it. Keep? | Decided 18/11 (answer 20): a devis must NOT touch « Seuil Min. ». Undone (proposal_terms.php, help migration 155, Gestion des Vides session); thresholds already raised on live are test data (answer 16), set by hand in Données de Base. |
| 17 | Should a new client start as Prospect? | Yes: a client is a prospect until it signs a devis (then Actif by itself) or someone presses « Convertir ». |
| 18 | SMART LOGISTICS and BOULANGERIE SAKER signed devis before this change (Net 30, 1 pour 1). | Migration 137 carries their terms onto them (Net 30, 100 %), not their prices: orders may have moved the prices since. |
| 19 | **Where did MTN MOBILE MONEY's 1 000 000 F come from?** Decided 18/11 (#15): a loan from Smart Logistics, without interest, to be repaid. Migration 144 records it (FIN-2611-00001) waiting for the DG's approval; **the DG approves it on live** (Trésorerie & Banque → Emprunts & Financements), which moves it from 471 to Smart Logistics' 466001. Before: It was entered on 03/11/2026 as the account's opening balance and posted nothing. Migration 139 posts it (Dr 552 / Cr 471 « Compte d'attente », dated 03/11) so the ledger matches the screen; 471 now waits to be cleared to its real origin: share capital (101), an associate's current account (4561, the DG's money?), a loan, another bank. | 1 000 000 F stays in 471 until the owner says; the Comptable then passes one OD (Dr 471 / Cr the right account). The MTN account's COA link was not changed: it posts to 552 by its type already. |
| 20 | **CCA Bank and Caisse Principale - Maison read 0 F** on live although LPC surely has money in them. What were their balances on the day the app started, and on what date? (9.7 waits on this: payments beyond the balance cannot be refused while the balances are not real.) | Nothing entered. Once known, the CF enters each as a correction (or the DG through Bilan d'Ouverture if the year allows), and Gestion du Personnel starts refusing payments beyond the balance like the other modules. |
| 21 | Paramètres → Préférences showed 19 settings that change nothing (finding 15.1). They are hidden, their values kept. Which should work (for example the default payment terms, 2FA for admins, password expiry)? | Hidden until wired; the admin video does not show them. |
| 22 | Identité de l'Entreprise on live: the footer's RCCM ends « 8-01777 » and the field « B-01777 »; CNPS employer number, APE code and tax office are empty. Which RCCM is right, and what are the others? | Nothing changed on live; the video shows the page as it is and does not read those fields out. |
| 23 | The « Directeur Général » role (`directeur_general`) has nobody; the DG signs in as « Administrateur », like JBS Praxis Support. Keep one role for both, or give the DG his own role (and delete the unused one)? | Unchanged; the video says both hold « Administrateur ». |
| 24 | When LPC **lends**, where does the borrower's debt sit? The owner set the borrowing side (4621, 466, 162, 168). | Default: a related company 466 (a sub-account apart from its loan to LPC, « … (prêt de LPC) »), anyone else 271 « Prêts et créances non commerciales ». Note: OHADA company law forbids a company to lend to its managers and physical-person associates; the screen allows it and leaves it to the DG's approval. |
| 25 | A loan's one-off fee and its interest: which accounts? | Default: the fee is kept out of the money paid over; LPC borrows: 631 Frais bancaires; LPC lends: 771. Interest LPC pays: 671 « Intérêts des emprunts » (all lenders); interest it earns: 771 « Intérêts de prêts et créances diverses » (771 read « Gains de change », which SYSCOHADA numbers 776: renamed, 776 added). Interest adds to what is owed, on the loan date's day each month. |
| 26 | The parents' own rows « 521 Banques », « 571 Caisse » and « 552 Monnaie électronique » had no entry once each account had its sub-account. | Deleted with the other unused rows (no treasury posting can reach a parent any more); 411 and 701 got their own row instead, being where invoices still post (8.1). |
| 27 | **Purchase TVA** (open point under answer 9). Supplier orders are TTC and reception books the whole TTC to 601 and 31x; the ristourne already sends its 19,25 % TVA to 4452 « TVA récupérable ». Under SYSCOHADA and the CGI, input TVA is deductible only on purchases used for operations **subject to TVA** (taxed at 19,25 %, or at 0 % with the right to deduct, like exports). If LPC's water is **outside or exempt from TVA** (the owner thinks there is no TVA on water), the purchase TVA is a cost: 601 and 31x at TTC are right, and the ristourne's 19,25 % should go to 6019 too, not 4452. If LPC **charges TVA** on its sales (Préférences say « assujetti TVA », 19,25 %), reception should split it: Dr 601 HT + Dr 4452 TVA / Cr 401 TTC, stock 31x at HT, and LPC deducts it each month. Which is LPC's position (ask the tax adviser: exempt, 0 % with deduction, or 19,25 %)? | Nothing changed: reception keeps 601 and 31x at TTC (right if water is not taxed). On the answer, a session changes reception, the ristourne's TVA line and the CUMP together, and decides whether past receptions are restated. |
| 28 | **Budgets: a ninth line, « Coût des marchandises vendues ».** Budget actuals now come from the ledger (12.3). Goods purchased (601), their stock variation (6031) and the rebates obtained (6019) are LPC's largest charge and fitted none of the MD's eight lines: in « Autres charges » they would swamp it. Keep a line of their own? | Default: yes, line « Coût des marchandises vendues » (migration 148): purchases net of stock and rebates, i.e. the cost of what was sold. With perpetual stock it reads near 0 until bottles leave (copy B: -7 446 F, the ristourne). Remove it by deleting the line's row if the owner prefers eight. |
| 29 | **Capitalising: « Déjà au bilan »** posts nothing (for an asset bought before the app, already in the Bilan d'Ouverture or an earlier entry). Misused, an asset would sit in the register without its cost in class 2. | Default: offered, with the sentence « Aucune écriture : l'acquisition est déjà au bilan » before saving; the register row says « déjà au bilan ». The real truck on live: the CF chooses when he capitalises it. |
| 30 | A client contests a CRE after LPC has counted and signed it (7.3). Should LPC's count stand, or the client's word? | Decided 18/11 (answer 30): LPC's count stands until the client signs; until then « Il y a une erreur de quantité » undoes it and the CRE turns « Contesté » (done, migration 150). |
| 31 | Should the Contrôleur Financier see Gestion des Vides and the recycler sales' revenue? On live he could not open the page. | Decided 18/11 (answer 23): the Contrôleur Financier AND the Comptable work in Gestion des Vides, not read only: they collect, sign CREs for LPC and sell to recyclers (migration 154). |
| 32 | 10L empties (answer 7, « to be verified »): live had one « Emballage 10L » at 100 F marked with cork. | Migration 150 makes it « 10L sans bouchon » at 100 F (OPUR 10L's empty) and adds « 10L avec bouchon » at 200 F. Prices stay editable in Données de Base. |
| 33 | **CNPS accident du travail**: the payroll charges 16,2 % employer CNPS (PVID 4,2 + PF 7 + AT 5 %, i.e. risk group C), while Paramètres → Fiscal say group A (AT 1,75 %). Which group is LPC in (ask the CNPS: water distribution with delivery trucks)? | The declaration follows what the payroll booked (433, 128 520 F for November on copy A) and shows « Taux AT de la paie 5,00 % ; Paramètres (groupe A) : 1,75 %. À vérifier avec la CNPS. » On the answer, the payroll rate (`payroll_rates.cnps_employer_rate`) or the group in Paramètres is corrected. |
| 34 | **Opening receivables and payables at go-live** (13.8): after the wipe, how will LPC enter what clients owe and what it owes suppliers on day one? Keyed in Bilan d'Ouverture, they reach each client's 411 sub-account but no invoice exists to record the payment against. | Default: the Comptable keys them in Bilan d'Ouverture (the ledger is right) and, when a client pays an old balance, records it as an OD (Dr bank / Cr the client's 411). A session can add « Encaissement sur solde d'ouverture » if the owner wants it on screen. |
