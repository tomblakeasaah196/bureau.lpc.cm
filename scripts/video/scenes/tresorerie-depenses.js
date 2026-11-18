"use strict";
/**
 * Modules, partie 9 : Trésorerie & Banque et Gestion des Dépenses. docs/TRAINING_VIDEOS.md says how a scene
 * is written and filmed.
 *
 * The Comptable (Daniel Massomba on screen) reads the accounts and checks one in the Grand Livre, moves money
 * to the cash box and hands a fuel advance from it. The Responsable des Opérations (Bertin Chadeu) is told the
 * fuel money has no fill-up yet, requests an expense and logs the fill-up in Flotte. The Contrôleur Financier
 * (Luc Nana) validates and pays the requests, compares Finance's fuel money with the fill-ups (the owner's
 * answer 6) and attaches the fill-up to the advance, sets up a recurring expense. The Comptable reconciles MTN
 * with its statement. Then « Emprunts & Financements » (the owner's decision 15, refilmed 18/11/2026): the
 * Contrôleur Financier shows the million that opened MTN, recorded as a loan from Smart Logistics and waiting for
 * the Directeur Général (Timothée Massomba), who approves it; the CF records a repayment, the DG approves it.
 * Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-09b-film (pre-09-film, i.e. after-06-flotte + migrations 134 to 138, then migrations 139
 * to 145). Each account has its own sub-account (5521 MTN MOBILE MONEY, 654 320 F, its opening balance 5521 / 471;
 * 5711 the cash box and 5211 CCA Bank at 0), FIN-2611-00001 (1 000 000 F from Smart Logistics) waits for the DG,
 * the four Flotte requests (fuel paid; repair, visite technique and vidange « à valider »). The scene writes: a 50 000 F transfer MTN → cash box,
 * a 24 840 F fuel advance from the cash box (EX-2611-00005), Bertin's request for a padlock and chain
 * (EX-2611-00006), a 30 L fill-up (EX-2611-00007, attached to the advance), the padlock paid from the cash box,
 * the repair (EX-2611-00002) paid from MTN, a reconciled MTN payment, an example recurring model, the loan
 * approved, a 250 000 F repayment to Smart Logistics from MTN, approved.
 */
const h = require("../helpers");

const PAGE = {
  cash: "/modules/accounting/cashflow.php",
  expenses: "/modules/accounting/expenses.php",
  fleet: "/modules/fleet/vehicles.php",
  ledger: "/modules/accounting/ledger.php",
};
const TRUCK = "LT 742 KL";

const pick = async (s, select, value) => {
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(value);
  await s.wait(0.6);
};
/** The option of a select whose text matches. */
const pickLike = async (s, select, re) => {
  await s.page.waitForFunction((sel) => document.querySelectorAll(`${sel} option`).length > 1, select, { timeout: 15000 });
  const opt = await s.page.locator(`${select} option`).filter({ hasText: re }).first().getAttribute("value");
  await pick(s, select, opt);
};
/** The shared window (lpc-modal.js): its OK / Confirmer. */
const modal = (s) => s.page.locator("dialog[open]").last();
const press = async (s, label) => { await s.click(modal(s).getByRole("button", { name: label })); await s.wait(0.6); };
const toast = (s, text) => s.page.locator("#lpc-toast-root, .lpc-toast, [role=status], [role=alert]").filter({ hasText: text }).last();
const cashTab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const expTab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const expenseRow = (s, ref) => s.page.locator("#list_body tr").filter({ hasText: ref }).first();
const card = (s, name) => s.page.locator("#wallets-grid > div").filter({ hasText: name }).first();
const bell = (s) => s.page.locator("#lpc-notif-btn");

module.exports = {
  id: "tresorerie-depenses",
  file: "LPC-ERP-Tresorerie-Et-Depenses",
  meta: { title: "LPC ERP · Trésorerie & Dépenses (Modules, partie 9)", artist: "JBS Praxis" },
  library: {
    slug: "tresorerie-depenses",
    title: "Trésorerie & Dépenses",
    subtitle: "Les comptes, les virements, les dépenses demandées, validées et payées, le carburant, les emprunts",
    series: "Modules",
    part: 9,
    parts: 15,
    pages: [PAGE.cash, PAGE.expenses],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 9",
    title: "Trésorerie & Dépenses",
    subtitle: "Les comptes, les virements, les dépenses, le carburant, les emprunts",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 9",
    title: "Merci",
    subtitle: "À suivre : Salaires",
    left: "bureau.lpc.cm",
  },
  holders: { comptable: "Daniel Massomba", operations: "Bertin Chadeu", controleur: "Luc Nana", dg: "Timothée Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-09b-film");
    log("ground: pre-09b-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Trésorerie & Banque et Gestion des Dépenses, l'argent de LPC qui bouge et qui sort.",
          "Le Comptable et le Contrôleur Financier tiennent les comptes, font les virements et paient les dépenses.",
          "Les Opérations demandent : la Finance valide et paie.",
          "Et pour le carburant, chacun voit l'argent donné face aux pleins relevés.",
          "Enfin, les emprunts : ce que LPC doit, ce qu'on lui doit, et rien sans l'accord du Directeur Général.",
        ],
      }],
    },

    {
      name: "Les comptes",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.cash,
        lines: [
          { say: "Le Comptable ouvre Trésorerie & Banque, dans Comptabilité & Finance.",
            do: (s) => h.soft(s, h.shell.menu(s, "Trésorerie & Banque")) },
          { say: "Une carte par compte : la caisse, la banque, le Mobile Money, avec leur solde.",
            do: (s) => h.tour(s, [card(s, "Caisse Principale"), card(s, "CCA BANK"), card(s, "MTN MOBILE MONEY")], 0.4) },
          { say: "Chacun a son propre compte au plan comptable : 5711 pour la caisse, 5211 pour la banque, 5521 pour MTN.",
            speech: "Chacun a son propre compte au plan comptable : cinquante-sept onze pour la caisse, cinquante-deux onze pour la banque, cinquante-cinq vingt et un pour M T N.",
            do: (s) => h.soft(s, card(s, "MTN MOBILE MONEY").getByText(/Compte 5521/)) },
          { say: "Dessous, les derniers mouvements de tous les comptes : ce qui entre en vert, ce qui sort en rouge.",
            do: (s) => h.soft(s, s.page.locator("#table-body-transactions tr").first()) },
          { say: "Un règlement de fournisseur, le plein du camion, chacun avec sa référence.",
            do: async (s) => { await s.scroll(320); await h.tour(s, [
              s.page.locator("#table-body-transactions tr").filter({ hasText: "PO-2611-00007" }).first(),
              s.page.locator("#table-body-transactions tr").filter({ hasText: "EX-2611-00001" }).first()], 0.5); } },
          { say: "Le premier mouvement de MTN est son solde d'ouverture : ce qu'il y avait le jour de sa création.",
            do: (s) => h.soft(s, s.page.locator("#table-body-transactions tr").filter({ hasText: "Solde d'ouverture" }).first()) },
          "Chaque mouvement passe aussi son écriture au Grand Livre. Le Comptable le vérifie.",
          { say: "Grand Livre OHADA, onglet Grand Livre, compte 5521 : celui de MTN MOBILE MONEY, et de lui seul.",
            speech: "Grand Livre OHADA, onglet Grand Livre, compte cinquante-cinq vingt et un : celui de M T N Mobile Money, et de lui seul.",
            do: async (s) => { await s.go(PAGE.ledger); await s.click(s.page.locator("#tab-grandlivre")); await pickLike(s, "#gl_account_select", /\[5521\]/); } },
          { say: "Même solde que la carte : 654 320 FCFA.",
            speech: "Même solde que la carte : six cent cinquante-quatre mille trois cent vingt francs.",
            do: (s) => h.soft(s, s.page.locator("#gl_current_balance"), 6000) },
          { say: "Le solde d'ouverture y entre face au compte d'attente 471. Ce million était un prêt : nous le verrons à la fin.",
            speech: "Le solde d'ouverture y entre face au compte d'attente quatre cent soixante et onze. Ce million était un prêt : nous le verrons à la fin.",
            do: (s) => h.soft(s, s.page.locator("#tbody-gl tr").filter({ hasText: "OUV-TRS" }).first(), 6000) },
        ],
      }],
    },

    {
      name: "Un virement interne",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.cash + "#operations", snapshot: true,
        lines: [
          { say: "La caisse est vide. Dans Opérations & Transferts, il l'approvisionne depuis MTN MOBILE MONEY.",
            speech: "La caisse est vide. Dans Opérations et Transferts, il l'approvisionne depuis M T N Mobile Money.",
            do: async (s) => { await cashTab(s, "operations"); await pickLike(s, "#tf_from", /MTN/); } },
          { say: "Vers la caisse principale.",
            do: (s) => pickLike(s, "#tf_to", /Caisse Principale/) },
          { say: "Le montant, 50 000 FCFA, et le motif : il est obligatoire.",
            speech: "Le montant, cinquante mille francs, et le motif : il est obligatoire.",
            do: async (s) => {
              await s.type(s.page.locator("#tf_amount"), "50000", { delay: 120 });
              await s.type(s.page.locator("#tf_ref"), "Approvisionnement caisse", { delay: 60 });
            } },
          { say: "« Valider Virement » : l'argent passe d'un compte à l'autre, sans quitter l'entreprise.",
            do: async (s) => { await s.click(s.page.locator("#form-transfer button")); await s.wait(1.2); await press(s, "OK"); } },
          { say: "Les deux soldes ont bougé, et le virement apparaît deux fois : émis par MTN, reçu par la caisse.",
            do: async (s) => { await cashTab(s, "wallets"); await h.tour(s, [card(s, "Caisse Principale"), card(s, "MTN MOBILE MONEY"),
              s.page.locator("#table-body-transactions tr").first()], 0.5); } },
          "Son écriture est passée en même temps : débit de la caisse, crédit du Mobile Money.",
        ],
      }],
    },

    {
      name: "Une avance pour le carburant",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.cash + "#operations", snapshot: true,
        lines: [
          "Le camion part en tournée. Le chauffeur reçoit de quoi faire trente litres de gasoil.",
          { say: "« Sortie de Caisse » : une dépense payée tout de suite. Elle est réservée à la Finance.",
            do: (s) => h.soft(s, s.page.locator("#form-expense")) },
          { say: "Le compte qui paie, la caisse, et la catégorie Carburant & Transport.",
            do: async (s) => { await pickLike(s, "#ex_account", /Caisse Principale/); await pickLike(s, "#ex_category", /Carburant/); } },
          { say: "24 840 FCFA, et la description.",
            speech: "Vingt-quatre mille huit cent quarante francs, et la description.",
            do: async (s) => {
              await s.type(s.page.locator("#ex_amount"), "24840", { delay: 120 });
              await s.type(s.page.locator("#ex_desc"), `Avance carburant ${TRUCK} · 30 L`, { delay: 50 });
            } },
          { say: "« Valider Dépense » : elle rejoint Gestion des Dépenses, déjà payée et comptabilisée.",
            do: async (s) => { await s.click(s.page.locator("#form-expense button")); await s.wait(1.2); await h.soft(s, modal(s), 3000); await press(s, "OK"); } },
          "C'est de l'argent donné pour le carburant, sans plein relevé dans Flotte pour l'instant.",
          "Les Opérations en sont prévenues aussitôt.",
        ],
      }],
    },

    {
      name: "Les Opérations demandent",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.expenses, snapshot: true,
        lines: [
          { say: "Le Responsable des Opérations a reçu l'avis dans sa cloche.",
            do: async (s) => { await s.click(bell(s)); await h.soft(s, s.page.locator("#lpc-notif-list").getByText(/Carburant/).first(), 5000); } },
          { say: "L'argent du carburant attend son plein dans Flotte. Il le saisira au retour du chauffeur.",
            do: async (s) => { await s.wait(1.5); await s.click(bell(s)); } },
          { say: "Il lui faut aussi un cadenas et une chaîne pour le dépôt. Dans Gestion des Dépenses, « Enregistrer une Dépense ».",
            do: (s) => s.click(s.page.locator("#content-dashboard button").filter({ hasText: "Enregistrer une Dépense" })) },
          { say: "Le titre, la catégorie, et le montant : 7 500 FCFA.",
            speech: "Le titre, la catégorie, et le montant : sept mille cinq cents francs.",
            do: async (s) => {
              await s.type(s.page.locator("#exp_title"), "Cadenas et chaîne pour le dépôt", { delay: 50 });
              await pickLike(s, "#exp_category", /Entretien & Maintenance/);
              await s.type(s.page.locator("#exp_amount"), "7500", { delay: 120 });
            } },
          { say: "Le statut ne propose qu'une chose : une demande à la Finance. Il ne paie pas lui-même.",
            do: (s) => h.soft(s, s.page.locator("#exp_status")) },
          { say: "Le compte qui paiera, c'est la Finance qui le choisira.",
            do: (s) => h.soft(s, s.page.locator("#exp_treasury")) },
          { say: "« Enregistrer » : la demande part, en attente.",
            do: async (s) => { await s.click(s.page.locator("#btn-save-expense")); await h.soft(s, toast(s, "attente"), 5000); } },
          { say: "Le chauffeur rentre : trente litres. Dans Flotte & Maintenance, Suivi Carburant, il saisit le plein.",
            do: async (s) => { await s.go(PAGE.fleet); await s.click(s.page.locator("#tab-carburant")); await s.click(s.page.locator("#content-carburant button").filter({ hasText: "Saisir Carburant" }).first()); } },
          { say: "Le camion, la station, le compteur, puis trente litres à 828 FCFA : 24 840 FCFA.",
            speech: "Le camion, la station, le compteur, puis trente litres à huit cent vingt-huit francs : vingt-quatre mille huit cent quarante francs.",
            do: async (s) => {
              await pickLike(s, "#fuel_vehicle_id", new RegExp(TRUCK));
              await s.page.locator("#fuel_vehicle_id").dispatchEvent("change");
              await s.type(s.page.locator("#fuel_station"), "TOTAL Bonabéri", { delay: 60 });
              await s.type(s.page.locator("#fuel_odometer"), "84395", { delay: 100 });
              await s.type(s.page.locator("#fuel_liters"), "30", { delay: 140 });
              await s.type(s.page.locator("#fuel_price"), "828", { delay: 140 });
              await h.soft(s, s.page.locator("#fuel_total_display"));
            } },
          { say: "« Valider » : le plein est noté, et sa demande part à la Finance, comme toujours.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-fuel")); await h.soft(s, toast(s, "EX-2611"), 6000); } },
        ],
      }],
    },

    {
      name: "La Finance valide et paie",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.expenses, snapshot: true,
        lines: [
          { say: "Le Contrôleur Financier ouvre Gestion des Dépenses : les demandes l'attendent.",
            do: async (s) => { await expTab(s, "list"); await h.soft(s, expenseRow(s, "EX-2611-00006"), 6000); } },
          { say: "La demande du dépôt : en attente, sans compte.",
            do: (s) => h.soft(s, expenseRow(s, "EX-2611-00006")) },
          { say: "Le bouton vert : il la valide et la paie depuis la caisse.",
            do: async (s) => { await s.click(expenseRow(s, "EX-2611-00006").locator("button[title='Marquer payée']")); await pickLike(s, "#pay_treasury", /Caisse Principale/); } },
          { say: "« Valider & comptabiliser » : la caisse est débitée, l'écriture passe.",
            do: async (s) => { await s.click(s.page.locator("#modal-pay button[onclick*=submitPay]")); await s.wait(1.2); await h.soft(s, expenseRow(s, "EX-2611-00006"), 6000); } },
          { say: "Puis la réparation de l'embrayage, demandée par la flotte : 85 000 FCFA, payés par MTN.",
            speech: "Puis la réparation de l'embrayage, demandée par la flotte : quatre-vingt-cinq mille francs, payés par M T N.",
            do: async (s) => {
              await s.scroll(300);
              await s.click(expenseRow(s, "EX-2611-00002").locator("button[title='Valider et payer']"));
              await pickLike(s, "#pay_treasury", /MTN/);
              await s.click(s.page.locator("#modal-pay button[onclick*=submitPay]"));
              await s.wait(1.2);
            } },
          { say: "Le demandeur est prévenu qu'elle est payée.",
            do: (s) => h.soft(s, expenseRow(s, "EX-2611-00002")) },
          { say: "Le bouton rouge refuserait une demande : la flotte garde la trace, sans paiement, et le demandeur le sait.",
            do: (s) => h.soft(s, expenseRow(s, "EX-2611-00003").locator("button[title='Refuser la demande']")) },
          { say: "Reste le plein du jour. Avant de le payer, il regarde l'onglet Carburant.",
            do: (s) => h.soft(s, expenseRow(s, "EX-2611-00007"), 5000) },
        ],
      }],
    },

    {
      name: "Le carburant, Finance et Opérations",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.expenses + "#fuel", snapshot: true,
        lines: [
          { say: "Onglet Carburant : ce que la Finance a donné pour le carburant ce mois-ci, face aux pleins des Opérations.",
            do: async (s) => { await expTab(s, "fuel"); await h.tour(s, [s.page.locator("#fuel_finance"), s.page.locator("#fuel_ops")], 0.5); } },
          { say: "Les deux font 74 520 FCFA, dont un plein encore en attente de paiement.",
            speech: "Les deux font soixante-quatorze mille cinq cent vingt francs, dont un plein encore en attente de paiement.",
            do: (s) => h.soft(s, s.page.locator("#fuel_pending_line")) },
          { say: "Pourtant, un écart : l'avance n'est rattachée à aucun plein.",
            do: (s) => h.soft(s, s.page.locator("#fuel_gap_card")) },
          { say: "Payer le plein, ce serait payer deux fois le même carburant.",
            do: (s) => h.soft(s, s.page.locator("#fuel_lines").getByText(/Avance carburant/).first()) },
          { say: "« Rattacher à une avance » : ce plein a été payé par l'avance de la caisse.",
            do: async (s) => { await s.click(s.page.locator("#fuel_lines button").filter({ hasText: "Rattacher" }).first()); await h.soft(s, s.page.locator("#attach_advance")); } },
          { say: "« Rattacher » : la demande disparaît, et les montants concordent.",
            do: async (s) => { await s.click(s.page.locator("#modal-attach button[onclick*=submitAttach]")); await s.wait(1.5); await h.soft(s, s.page.locator("#fuel_gap_card"), 5000); } },
          "Les deux côtés sont prévenus que l'écart est régularisé.",
          { say: "Par trimestre ou sur l'année, le tableau reprend chaque mois : Finance, Opérations, et l'écart.",
            do: async (s) => { await pick(s, "#fuel_kind", "quarter"); await s.wait(1.2); await s.scroll(420); await h.soft(s, s.page.locator("#fuel_table tr").last()); } },
          "Les Opérations voient le même écran. Quand un écart apparaît, l'autre côté est prévenu, pour régulariser.",
        ],
      }],
    },

    {
      name: "Le rapprochement",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.cash + "#reconciliation", snapshot: true,
        lines: [
          { say: "En fin de mois, le Comptable compare MTN avec son relevé, dans Rapprochement Bancaire.",
            speech: "En fin de mois, le Comptable compare M T N avec son relevé, dans Rapprochement Bancaire.",
            do: async (s) => { await cashTab(s, "reconciliation"); await pickLike(s, "#rec_account_filter", /MTN/); } },
          { say: "Le règlement de Source du Pays figure sur le relevé : il le coche.",
            do: async (s) => {
              const row = s.page.locator("#table-body-recon tr").filter({ hasText: "PO-2611-00007" }).first();
              await s.scroll(260);
              await s.click(row.locator("input[type=checkbox]"));
            } },
          { say: "Il confirme : la ligne est rapprochée et verrouillée.",
            do: async (s) => { await press(s, "Confirmer"); await s.wait(1); await press(s, "OK"); await h.soft(s, s.page.locator("#table-body-recon tr.reconciled-row").first(), 5000); } },
          { say: "Une erreur sur un mouvement ? Le crayon rouge envoie une demande de correction à la Direction.",
            do: (s) => h.soft(s, s.page.locator("#table-body-recon button[title='Demander une correction']").first()) },
          "La Direction corrige là où le mouvement est né, puis répond ici. Le demandeur reçoit la réponse.",
        ],
      }],
    },

    {
      name: "Les dépenses qui reviennent",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.expenses, snapshot: true,
        lines: [
          { say: "Certaines dépenses reviennent chaque mois. Dans Récurrentes, le Contrôleur Financier crée un modèle.",
            do: async (s) => { await expTab(s, "recurring"); await s.click(s.page.locator("#content-recurring button").filter({ hasText: "Nouveau modèle" })); } },
          { say: "Par exemple l'abonnement internet du bureau : la catégorie, le montant, le jour du mois.",
            do: async (s) => {
              await s.type(s.page.locator("#rec_name"), "Abonnement internet du bureau", { delay: 50 });
              await pickLike(s, "#rec_category", /Télécom/);
              await s.type(s.page.locator("#rec_amount"), "30000", { delay: 120 });
              await s.page.locator("#rec_dom").fill("");
              await s.type(s.page.locator("#rec_dom"), "1", { delay: 120 });
            } },
          { say: "Chaque mois, à ce jour-là, la dépense du mois arrive, en attente, et la Finance est prévenue.",
            do: async (s) => { await s.click(s.page.locator("#modal-recurring button[onclick*=submitRecurring]")); await s.wait(1.2); } },
          { say: "Celle de novembre est déjà là, à valider comme les autres.",
            do: async (s) => { await expTab(s, "list"); await h.soft(s, expenseRow(s, "EXR-2611"), 6000); } },
          { say: "Le Tableau de Bord résume le mois : ce qui est dépensé, le cumul de l'année, ce qui attend son paiement.",
            do: async (s) => { await expTab(s, "dashboard"); await h.tour(s, [s.page.locator("#kpi_month"), s.page.locator("#kpi_ytd"), s.page.locator("#kpi_unpaid")], 0.5); } },
          { say: "Et la répartition par catégorie, face au budget.",
            do: async (s) => { await s.scroll(700); await h.soft(s, s.page.locator("#dash_breakdown > div").first()); } },
        ],
      }],
    },

    {
      name: "Emprunts & Financements",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.cash + "#loans",
        lines: [
          { say: "Le million qui a ouvert MTN venait de Smart Logistics, une société liée : LPC doit le lui rendre.",
            speech: "Le million qui a ouvert M T N venait de Smart Logistics, une société liée : L P C doit le lui rendre.",
            do: async (s) => { await cashTab(s, "loans"); await h.soft(s, s.page.locator("#loans-root table tbody tr").first(), 6000); } },
          { say: "Onglet Emprunts & Financements : il est enregistré comme un emprunt, sans intérêt, et attend le Directeur Général.",
            speech: "Onglet Emprunts et Financements : il est enregistré comme un emprunt, sans intérêt, et attend le Directeur Général.",
            do: (s) => h.soft(s, s.page.locator("#loans-root section").filter({ hasText: "À approuver" }).first()) },
          { say: "Le Contrôleur Financier, le Comptable et le DG enregistrent. Seul le DG approuve : avant, rien n'est comptabilisé.",
            do: (s) => h.tour(s, [s.page.locator("#loans-root .grid > div").nth(0), s.page.locator("#loans-root .grid > div").nth(2)], 0.5) },
          { say: "« Nouveau financement » : LPC emprunte ou prête, à un dirigeant, une société liée, une banque, une tontine.",
            do: async (s) => { await s.click(s.page.locator("[data-act=new]")); await h.soft(s, s.page.locator("#ln_cp")); } },
          { say: "Chaque prêteur a son propre compte : 4621 pour un dirigeant, 466 pour une société liée, 162 pour une banque.",
            speech: "Chaque prêteur a son propre compte : quarante-six vingt et un pour un dirigeant, quatre cent soixante-six pour une société liée, cent soixante-deux pour une banque.",
            do: (s) => h.soft(s, s.page.locator("#ln_cp")) },
          { say: "Les intérêts : aucun, un taux fixe chaque mois sur ce qui reste dû, ou un taux composé.",
            do: async (s) => { await pick(s, "#ln_kind", "simple"); await h.soft(s, s.page.locator("#ln_rate")); } },
          { say: "Des frais uniques, retenus sur les fonds, et une échéance : le DG et le Contrôleur Financier sont prévenus avant.",
            do: (s) => h.tour(s, [s.page.locator("#ln_fee"), s.page.locator("#ln_due")], 0.6) },
          { say: "Il n'a rien à saisir aujourd'hui : il ferme.",
            do: (s) => s.click(s.page.locator("#modal-loan [data-loan-close]").last()) },
        ],
      },
      {
        kind: "desktop", as: "timothee", role: "dg", start: PAGE.cash, snapshot: true,
        lines: [
          { say: "Le Directeur Général a reçu l'avis : un emprunt à approuver.",
            do: async (s) => { await s.click(bell(s)); await h.soft(s, s.page.locator("#lpc-notif-list").getByText(/Emprunt à approuver/).first(), 5000); } },
          { say: "Dans Emprunts & Financements, « Examiner ».",
            speech: "Dans Emprunts et Financements, « Examiner ».",
            do: async (s) => { await s.click(bell(s)); await cashTab(s, "loans"); await s.click(s.page.locator("[data-act=decide]").first()); } },
          { say: "La fenêtre dit ce que fera l'approbation : le million quitte le compte d'attente 471 pour le compte de Smart Logistics, 466001. Aucun argent ne bouge.",
            speech: "La fenêtre dit ce que fera l'approbation : le million quitte le compte d'attente quatre cent soixante et onze pour le compte de Smart Logistics. Aucun argent ne bouge.",
            do: (s) => h.soft(s, s.page.locator("#loan-modal-body p").filter({ hasText: "Si vous approuvez" }), 7000) },
          { say: "« Approuver et comptabiliser ».",
            do: async (s) => { await s.click(s.page.locator("#loan-modal-actions button").filter({ hasText: "Approuver" })); await s.wait(1.2); await press(s, "OK"); } },
          { say: "L'emprunt est en cours : LPC doit 1 000 000 FCFA à Smart Logistics.",
            speech: "L'emprunt est en cours : L P C doit un million de francs à Smart Logistics.",
            do: (s) => h.tour(s, [s.page.locator("#loans-root .grid > div").nth(0), s.page.locator("#loans-root table tbody tr").first()], 0.5) },
          "Smart Logistics est aussi client de LPC : son prêt reste à part de son compte client. Jamais l'un ne paie l'autre.",
        ],
      },
      {
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.cash + "#loans", snapshot: true,
        lines: [
          { say: "LPC rembourse une première partie. Le Contrôleur Financier clique « Rembourser ».",
            speech: "L P C rembourse une première partie. Le Contrôleur Financier clique « Rembourser ».",
            do: async (s) => { await cashTab(s, "loans"); await s.click(s.page.locator("[data-act=repay]").first()); } },
          { say: "Ce qui reste dû, puis le montant : 250 000 FCFA, payés par MTN MOBILE MONEY.",
            speech: "Ce qui reste dû, puis le montant : deux cent cinquante mille francs, payés par M T N Mobile Money.",
            do: async (s) => {
              await s.type(s.page.locator("#rp_amount"), "250000", { delay: 120 });
              await pickLike(s, "#rp_treasury", /MTN/);
            } },
          { say: "N'importe quel montant, n'importe quel jour : il paie d'abord les intérêts, puis le capital.",
            do: (s) => h.soft(s, s.page.locator("#loan-modal-body").getByText(/d'abord les intérêts/)) },
          { say: "« Enregistrer pour approbation » : le remboursement attend le DG.",
            do: async (s) => { await s.click(s.page.locator("#loan-modal-actions button").filter({ hasText: "Enregistrer" })); await s.wait(1.2); await press(s, "OK"); await h.soft(s, s.page.locator("#loans-root section").filter({ hasText: "À approuver" }).first(), 5000); } },
        ],
      },
      {
        kind: "desktop", as: "timothee", role: "dg", start: PAGE.cash + "#loans", snapshot: true,
        lines: [
          { say: "Le DG examine le remboursement.",
            do: async (s) => { await cashTab(s, "loans"); await s.click(s.page.locator("[data-act=decide]").first()); await h.soft(s, s.page.locator("#loan-modal-body p").filter({ hasText: "Si vous approuvez" }), 5000); } },
          { say: "Il approuve : MTN paie, et l'écriture passe.",
            speech: "Il approuve : M T N paie, et l'écriture passe.",
            do: async (s) => { await s.click(s.page.locator("#loan-modal-actions button").filter({ hasText: "Approuver" })); await s.wait(1.2); await press(s, "OK"); } },
          { say: "Reste dû : 750 000 FCFA. Le détail garde chaque remboursement, et qui l'a saisi et approuvé.",
            speech: "Reste dû : sept cent cinquante mille francs. Le détail garde chaque remboursement, et qui l'a saisi et approuvé.",
            do: async (s) => { await s.click(s.page.locator("[data-act=detail]").first()); await h.soft(s, s.page.locator("#loans-root table tbody tr").nth(1), 6000); } },
          "Un emprunt avec intérêts les ajoute seul, chaque mois, selon les conditions approuvées.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : chaque mouvement d'argent a son écriture ; les Opérations demandent, la Finance valide et paie.",
          "Et le carburant se compare, mois par mois, entre la Finance et les Opérations.",
          "Chaque emprunt et chaque remboursement attend l'approbation du Directeur Général.",
          "La prochaine vidéo de la série : Salaires.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
