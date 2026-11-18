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
 * with its statement. Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-09-film (after-06-flotte + migrations 134 to 138). MTN MOBILE MONEY 654 320 F (its
 * opening balance now posted: 552 / 471), CCA Bank and the cash box at 0, the four Flotte requests (fuel paid;
 * repair, visite technique and vidange « à valider »). The scene writes: a 50 000 F transfer MTN → cash box,
 * a 24 840 F fuel advance from the cash box (EX-2611-00005), Bertin's request for a padlock and chain
 * (EX-2611-00006), a 30 L fill-up (EX-2611-00007, attached to the advance), the padlock paid from the cash box,
 * the repair (EX-2611-00002) paid from MTN, a reconciled MTN payment, an example recurring model.
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
    subtitle: "Les comptes, les virements, les dépenses demandées, validées et payées, le carburant",
    series: "Modules",
    part: 9,
    parts: 15,
    pages: [PAGE.cash, PAGE.expenses],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 9",
    title: "Trésorerie & Dépenses",
    subtitle: "Les comptes, les virements, les dépenses demandées, validées et payées, le carburant",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 9",
    title: "Merci",
    subtitle: "À suivre : Salaires",
    left: "bureau.lpc.cm",
  },
  holders: { comptable: "Daniel Massomba", operations: "Bertin Chadeu", controleur: "Luc Nana" },

  async prepare({ log, ground }) {
    ground.restore("pre-09-film");
    log("ground: pre-09-film restored");
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
          { say: "Dessous, les derniers mouvements de tous les comptes : ce qui entre en vert, ce qui sort en rouge.",
            do: (s) => h.soft(s, s.page.locator("#table-body-transactions tr").first()) },
          { say: "Un règlement de fournisseur, le plein du camion, chacun avec sa référence.",
            do: async (s) => { await s.scroll(320); await h.tour(s, [
              s.page.locator("#table-body-transactions tr").filter({ hasText: "PO-2611-00007" }).first(),
              s.page.locator("#table-body-transactions tr").filter({ hasText: "EX-2611-00001" }).first()], 0.5); } },
          { say: "Le premier mouvement de MTN est son solde d'ouverture : ce qu'il y avait le jour de sa création.",
            do: (s) => h.soft(s, s.page.locator("#table-body-transactions tr").filter({ hasText: "Solde d'ouverture" }).first()) },
          "Chaque mouvement passe aussi son écriture au Grand Livre. Le Comptable le vérifie.",
          { say: "Grand Livre OHADA, onglet Grand Livre, compte 552, la monnaie électronique.",
            speech: "Grand Livre OHADA, onglet Grand Livre, compte cinq cent cinquante-deux, la monnaie électronique.",
            do: async (s) => { await s.go(PAGE.ledger); await s.click(s.page.locator("#tab-grandlivre")); await pickLike(s, "#gl_account_select", /\[552\]/); } },
          { say: "Même solde que la carte : 654 320 FCFA.",
            speech: "Même solde que la carte : six cent cinquante-quatre mille trois cent vingt francs.",
            do: (s) => h.soft(s, s.page.locator("#gl_current_balance"), 6000) },
          { say: "Le solde d'ouverture y entre face au compte d'attente 471 : le Comptable le portera au bon compte une fois son origine connue.",
            speech: "Le solde d'ouverture y entre face au compte d'attente quatre cent soixante et onze : le Comptable le portera au bon compte une fois son origine connue.",
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
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : chaque mouvement d'argent a son écriture ; les Opérations demandent, la Finance valide et paie.",
          "Et le carburant se compare, mois par mois, entre la Finance et les Opérations.",
          "La prochaine vidéo de la série : Salaires.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
