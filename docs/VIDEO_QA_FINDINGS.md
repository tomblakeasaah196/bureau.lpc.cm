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

1. [V] `api/v1/mdm_controller.php:15` refuses every role but `admin` (401 « Accès Refusé »), under an
   RBAC check that already allows `admin.master_data.view`. CF holds `.view` and `.edit`, Cpt and Ops
   `.view`: for them the page stays on « Chargement des données... » [I]. Only 3 of the write actions
   (`save_default_price`, `save_fallback`, `delete_supplier_price`) check `.edit`; `save`, `save_category`,
   `toggle_status` relied on the admin gate. Fix: drop the gate, require `.edit` on every write.
2. [V] Prix de repli and « Ligne à prix nul » (pricing panel) are stored but nothing in sales reads them
   (`price_fallback_*` only in mdm_controller): either wire them into `includes/functions/pricing.php` or
   stop showing them as policy.
3. [V] « Coût de référence » (`cost_price`) cannot be edited anywhere; at creation it copies `base_price`,
   the selling price (`mdm_controller.php:1210-1213`). Live OPUR 20L has cost 2 275 = its price.
4. [V] Saving a product clears its per-product account overrides: the form never sends
   `revenue_account_id`, `stock_account_id`, `cogs_account_id` (`:1149-1151, 1178`).
5. [V] A supplier with no 401 account: the error says « re-save the supplier », but the update path never
   creates one (`JournalPoster.php:1643`, `mdm_controller.php:1326-1329`).
6. [V] Client tariffs have no delete and no history.

## 2 · Gestion des Achats

1. [V] There is no approval step: PO statuses are pending / partial / received / cancelled. The
   `inventory.procurement.approve` permission gates reception, payment and cancellation instead.
2. [V] **Ops cannot receive goods**: `receive_po` (`inventory_controller.php:402`) checks
   `inventory.procurement.approve` (DG, CF). Ops holds `inventory.stock.receive`, which no API checks,
   and is the one notified to receive (`procurement_controller.php:1213`). « Recevoir » and « Annuler
   Commande » show to everyone and answer 403. Fix: reception accepts `inventory.stock.receive`.
3. [V] CF holds `.approve` but not `.create_po`: the CF can pay and receive but not order. Ops and Cpt
   can order. (Who orders is the owner's call: see « For the owner ».)
4. [V] Every typed « Remise » is booked as spending the supplier's ristourne (Dr 401x / Cr 4098,
   `procurement_controller.php:945, 1163-1188`); HOREB has no ladder, so any remise on HOREB fails
   « Ce fournisseur ne dispose pas d'un compte ristourne ». The KPI hint says otherwise.
5. [V] The ristourne still takes TVA out of the base (TTC / 1.1925) and withholds VAT although purchases
   are booked without TVA (`:1152`, `inventory_controller.php:612`). Accounting rule to confirm.
6. [V] A PO marked « Payé » shows Payé before any money moves; on a partial reception the full amount is
   paid at the first delivery (contradicts `inventory_controller.php:771-773`); a remise taking the
   total to 0 makes `postSupplierPayment` throw and the reception fail.
7. [V] Partial reception, then « Payer Fournisseur », pays the full total: 401 goes debit.
8. [V] « Enregistrer & Valider Stock » does not touch stock (reception does).
9. [V] No supplier invoice step: the PO is the payable. `supplier_invoices` is referenced by TaxEngine
   but the table does not exist on live.
10. [V] Cancelling sends no reason; the list has no reception-status column; `postSupplierPayment`'s
    docblock (`JournalPoster.php:1016-1019`) is stale.

## 3 · Stock & Emballages, Fiche de Stock

1. [I] **Stock accounting mixes two methods.** Reception posts Dr 601 / Cr 401x and never 31x; each BL
   posts Dr 6031 / Cr 31x at CUMP (`JournalPoster.php:515-577`). 31x goes credit and the cost of goods
   is counted twice (601 and 6031). SYSCOHADA perpetual inventory wants, at reception, also
   Dr 31x / Cr 6031 (or reception straight to 31x). Avaries, inventory adjustments and refused bottles
   post nothing. **The biggest accounting finding: fix with the Comptabilité 1 session if not here, and
   check the live books' 31x / 601 / 6031 before and after.**
2. [V] `inventory.stock.damage` and `.audit` are never enforced: anyone with `inventory.stock.view` can
   declare an avarie or seal an inventory.
3. [V] Inventaire shows only the first 25 products (unpaginated read, page 25) and the server trusts the
   browser's theoretical/difference figures (`inventory_controller.php:889`); the Avarie product list
   shows only the current stock page.
4. [V] Fiche de Stock has no opening, running or closing balance (not a kardex), and « Valeur (FCFA) »
   multiplies every past movement by today's CUMP (`inventory-fiche_stock.js:87-92`).
5. [I] Historique labels: recycling sales show as « Expédition Client (BL: …) » with an unrelated BL;
   refused-bottle `in_adjustment` rows can link to an unrelated « Certificat INV-… » (`:328-330`).
6. [V] Nothing links a proposal's « Stock Tampon (Semaines) » to `min_stock_level` (« Seuil Min. »).

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

Grants below are from migrations; live may differ (check `role_permissions` on the copy first).

1. [V] **Fuel and breakdowns can only be entered by the `driver` role**, which LPC does not use:
   `log_fuel` needs `fleet.fuel.log` (driver only since 028), `report_breakdown.php` needs
   `fleet.breakdown.report` (driver only) and its vehicle list loads with `action=read`, which drivers
   lack, so it stays empty (`fleet-report_breakdown.js:14`). Ops and the DG get 403. With driver accounts
   going away, Ops must enter fuel and breakdowns.
2. [V] Ops cannot add or edit a vehicle (`fleet.vehicles.create`: admin only, `001:272`).
3. [V] The « Saisir Carburant » modal never sends the fuel type or the station (`fleet-vehicles.js:516-522`);
   the driver page also requires an assignment for today (`fuel_log.php:76-92`).
4. [V] The assignment's driver list shows only users with role operations or driver (`fleet_controller.php:100`).
5. [V] **Breakdown logic is inverted**: a repair entry sets `repair → active` and never sets `repair`
   (`:478`); help article 071 says the opposite and that maintenance reaches the ledger (it does not).
6. [V] **Fuel, repairs and maintenance post no journal entry and no expense**; `vehicle_expenses` and
   `fleet.vehicles.expense` exist unused. The cost must be typed again in Gestion des Dépenses
   (« Carburant & Transport » 6022, « Assurance véhicule » 6252, « Visite technique » 6225), with no link
   to the vehicle.
7. [V] **Fleet RBAC and CSRF bypass**: a multipart POST arrives with a null action, so the per-action RBAC
   and CSRF checks are skipped (`fleet_controller.php:34-68, 245`): any signed-in user can create or edit
   vehicles. Security: fix first.
8. [V] Capitalising a vehicle (Immobilisations « À Capitaliser (Flotte) ») posts no acquisition entry
   (`fixed_assets_controller.php:327-346`).

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

---

## For the owner (decisions, with the default a session uses meanwhile)

| # | Question | Default used for the videos |
| --- | --- | --- |
| 1 | Who places supplier orders: Ops, the CF, or both? Today Ops and the Comptable can, the CF cannot. | Ops orders and receives; the CF pays. |
| 2 | Should Ops close deliveries (`sales.deliveries.close`)? | Yes: the owner said Ops does the orders; granted by migration, listed in the PR. |
| 3 | Stock accounting: perpetual (31x at reception) or periodic (601 then variation at closing)? | Perpetual, as the BL's 6031/31x entry already assumes. |
