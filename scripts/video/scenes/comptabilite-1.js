"use strict";
/**
 * Modules, partie 11 : Comptabilité 1 (Plan Comptable, Bilan d'Ouverture, Écritures, Grand Livre OHADA).
 * docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * The Comptable works the books day to day (Daniel Massomba on screen); the Contrôleur Financier checks and
 * validates (Luc Nana); the Directeur Général approves a loan (Timothée Massomba). Narrated by role (§2).
 *
 * What it teaches: the modules post their entries by themselves (a purchase, the payroll, a loan once the DG
 * approves it); the Comptable keeps the chart, clears the suspense account 471, types what no module books
 * (bank fees from the statement) as a draft the Contrôleur Financier validates, and reviews the Grand Livre
 * (the trial balance, the tiers, lettrage).
 *
 * Pay: the audience is the finance roles (accounting.* pages), who also see payroll. Still, only totals are
 * shown (the salary settlement, the trial balance): no one's payslip entry is opened on camera.
 *
 * Ground: snapshot pre-11-film = after-15-admin + migrations 142 to 147. Copy C holds the purchase chain
 * (PO-2611-00003, -00004, -00006, -00007), November's payroll paid from CCA Bank, an advance, MTN's opening
 * 1 000 000 F in 471 and Smart Logistics' loan FIN-2611-00001 waiting for the DG. The scene writes: sub-account
 * 631001, the DG's approval of FIN-2611-00001, a bank-fee entry of 2 500 F (draft, then validated), lettrage
 * on 401001.
 */
const h = require("../helpers");

const PAGE = {
  coa: "/modules/admin/chart_of_accounts.php",
  ob: "/modules/accounting/opening_balance.php",
  je: "/modules/accounting/journal_entry.php",
  gl: "/modules/accounting/ledger.php",
  cash: "/modules/accounting/cashflow.php",
  finance: "/modules/dashboard/views/finance_dashboard.php",
};

/** The shared window (lpc-modal.js) and its buttons. */
const modal = (s) => s.page.locator("dialog[open]").last();
const press = async (s, label) => { await s.click(modal(s).getByRole("button", { name: label })); await s.wait(0.6); };
/** A row of the Plan Comptable tree by its OHADA number, and a sub-account row by its code. */
const coaRow = (s, num) => s.page.locator("#coa-tree > div").filter({ has: s.page.locator("span.font-mono", { hasText: new RegExp(`^${num}$`) }) }).first();
const subRow = (s, code) => s.page.locator("#coa-tree > div").filter({ has: s.page.locator("span.font-mono", { hasText: new RegExp(`^${code}$`) }) }).first();
const jrRow = (s, text) => s.page.locator("#table-body-journal tr").filter({ hasText: text }).first();
const glRow = (s, text) => s.page.locator("#tbody-gl tr").filter({ hasText: text }).first();
const card = (s, start) => s.page.getByRole("button", { name: new RegExp(`^${start}`) });
const jeTab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const glTab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const search = async (s, text) => { await s.page.locator("#jr_q").fill(""); await s.type(s.page.locator("#jr_q"), text, { delay: 60 }); await s.wait(1.4); };
/** Pick an account in the assistant's line `i` by its code. */
const pickAccount = async (s, i, code) => {
  const sel = s.page.locator(".wiz-acc-select").nth(i);
  await s.hover(sel);
  const label = (await sel.locator("option").allTextContents()).find((o) => o.startsWith(`${code} -`));
  await sel.selectOption({ label }); await s.wait(0.5);
};

module.exports = {
  id: "comptabilite-1",
  file: "LPC-ERP-Comptabilite-1-Plan-Ecritures-Grand-Livre",
  meta: { title: "LPC ERP · Comptabilité 1 (Modules, partie 11)", artist: "JBS Praxis" },
  library: {
    slug: "comptabilite-1",
    title: "Comptabilité 1 : plan comptable, écritures et Grand Livre",
    subtitle: "Ce qui arrive seul au Grand Livre, ce que le Comptable saisit, la balance et le lettrage",
    series: "Modules",
    part: 11,
    parts: 15,
    pages: [PAGE.coa, PAGE.ob, PAGE.je, PAGE.gl],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 11",
    title: "Comptabilité 1",
    subtitle: "Plan comptable, écritures et Grand Livre",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 11",
    title: "Merci",
    subtitle: "À suivre : Comptabilité 2",
    left: "bureau.lpc.cm",
  },
  holders: { comptable: "Daniel Massomba", controleur: "Luc Nana", dg: "Timothée Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-11-film");
    log("ground: pre-11-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la comptabilité de LPC ERP : le plan comptable, les écritures et le Grand Livre.",
          "Le Comptable tient les livres au quotidien. Le Contrôleur Financier vérifie et valide.",
          "Vous verrez ce qui arrive seul au Grand Livre, depuis les achats, la paie et les emprunts.",
          "Puis ce que le Comptable fait encore à la main : une écriture, sa validation, la balance et le lettrage.",
        ],
      }],
    },

    {
      name: "Le plan comptable",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.coa, snapshot: true,
        lines: [
          { say: "Le Comptable ouvre le Plan Comptable, dans le menu Comptabilité & Finance.",
            do: (s) => h.soft(s, h.shell.menu(s, "Plan Comptable")) },
          { say: "Un onglet par classe du plan SYSCOHADA, de 1 à 9, avec le nombre de comptes de chacune.",
            do: (s) => h.tour(s, ["1", "4", "5", "6"].map((k) => s.page.locator(`#coa-tabs [data-cls="${k}"]`)), 0.35) },
          { say: "La classe 4, les tiers. Sous 401 Fournisseurs, chaque fournisseur a son sous-compte.",
            do: async (s) => { await s.click(s.page.locator('#coa-tabs [data-cls="4"]')); await s.click(coaRow(s, "401").locator("[data-toggle]")); await s.wait(0.6); await h.soft(s, subRow(s, "401002")); } },
          "Les clients, les fournisseurs, les comptes de trésorerie et les prêteurs reçoivent le leur tout seuls, à leur création.",
          { say: "La recherche couvre tout le plan. Par exemple, Smart Logistics, la société qui a prêté à LPC.",
            speech: "La recherche couvre tout le plan. Par exemple, Smart Logistics, la société qui a prêté à L P C.",
            do: async (s) => { await s.type(s.page.locator("#coa-search"), "Smart", { delay: 90 }); await s.wait(1); await h.soft(s, subRow(s, "466001")); } },
          { say: "Le Comptable veut suivre à part les frais de tenue de compte de la banque. Sous 631 Frais bancaires, « + Sous-compte ».",
            do: async (s) => {
              await s.page.locator("#coa-search").fill(""); await s.wait(0.4);
              await s.click(s.page.locator('#coa-tabs [data-cls="6"]'));
              await s.click(coaRow(s, "631").locator("[data-add-sub]"));
            } },
          { say: "Le code proposé est le suivant libre : 631001. Il le nomme.",
            speech: "Le code proposé est le suivant libre : six, trois, un, zéro, zéro, un. Il le nomme.",
            do: (s) => s.type(s.page.locator("#f-name"), "Frais de tenue de compte", { delay: 55 }) },
          { say: "« Créer le sous-compte ». Il apparaît sous 631, inutilisé pour l'instant.",
            do: async (s) => { await s.click(s.page.locator("#coa-modal-save")); await s.wait(1.2); await s.scroll(360); await h.soft(s, subRow(s, "631001")); } },
          { say: "« Renommer » change le nom, jamais le code. Il précise : frais de tenue de compte CCA Bank.",
            do: async (s) => {
              await s.click(subRow(s, "631001").locator("[data-rename-coa]"));
              await s.page.locator("#f-name").fill(""); await s.type(s.page.locator("#f-name"), "Frais de tenue de compte CCA Bank", { delay: 45 });
              await s.click(s.page.locator("#coa-modal-save")); await s.wait(1.5);
            } },
          { say: "Un compte qui n'a jamais servi peut être supprimé ; sinon on le désactive, et ses écritures restent.",
            do: (s) => h.soft(s, subRow(s, "631001").locator("[data-delete-coa]")), hold: 0.4 },
        ],
      }],
    },

    {
      name: "Ce qui arrive seul",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.je + "#journal",
        // The month's journal unfiltered lists each person's payroll entries: the take opens on the purchases'
        // journal instead, and later searches name one entry at a time.
        setup: async (s) => { await s.page.locator("#jr_journal").selectOption("AC"); await s.settle(1200); },
        lines: [
          { say: "Dans Écritures, l'onglet « Journal des Écritures » montre tout ce qui a été posté dans le mois.",
            do: (s) => h.soft(s, s.page.locator("#tab-journal")) },
          { say: "Ici, le journal des achats de novembre. La colonne Origine dit d'où vient chaque écriture : toutes viennent des modules.",
            do: (s) => h.tour(s, [s.page.locator("#jr_journal"), s.page.locator("#table-body-journal tr td:nth-child(4)").first(), s.page.locator("#jr_counts")], 0.6) },
          { say: "Prenons un achat : la commande PO-2611-00007, cent bonbonnes chez Source du Pays, dans tous les journaux.",
            speech: "Prenons un achat : la commande P O deux six onze, zéro zéro zéro zéro sept, cent bonbonnes chez Source du Pays, dans tous les journaux.",
            do: async (s) => { await search(s, "PO-2611-00007"); await s.hover(s.page.locator("#jr_journal")); await s.page.locator("#jr_journal").selectOption(""); await s.wait(1.4); } },
          { say: "La réception a passé seule l'achat en 601 et la dette au fournisseur en 401.",
            speech: "La réception a passé seule l'achat en six cent un, et la dette au fournisseur en quatre cent un.",
            do: (s) => h.soft(s, jrRow(s, "Réception achat")) },
          { say: "La même réception a fait entrer les bonbonnes en stock, compte 311, et noté la ristourne gagnée.",
            speech: "La même réception a fait entrer les bonbonnes en stock, compte trois cent onze, et noté la ristourne gagnée.",
            do: (s) => h.tour(s, [jrRow(s, "Entrée en stock"), jrRow(s, "Ristourne")], 0.5) },
          { say: "Le paiement au fournisseur a soldé le 401 par le compte qui a payé.",
            speech: "Le paiement au fournisseur a soldé le quatre cent un par le compte qui a payé.",
            do: (s) => h.soft(s, jrRow(s, "Règlement fournisseur")) },
          { say: "La paie aussi : le virement des salaires de novembre, 561 713 FCFA depuis CCA Bank.",
            speech: "La paie aussi : le virement des salaires de novembre, cinq cent soixante et un mille sept cent treize francs, depuis CCA Bank.",
            do: async (s) => { await search(s, "Règlement salaires"); await h.soft(s, jrRow(s, "Règlement salaires")); } },
          "Le Comptable ne ressaisit rien de tout cela. Il vérifie que chaque écriture est juste.",
        ],
      }],
    },

    {
      name: "Le compte d'attente",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.ob,
        lines: [
          { say: "Le Bilan d'Ouverture pose les soldes de départ d'un exercice neuf.",
            do: (s) => h.soft(s, h.shell.menu(s, "Bilan d'Ouverture")) },
          { say: "Pour 2026, il est figé : l'exercice a déjà des écritures. Un solde oublié passerait par une écriture d'opérations diverses.",
            do: (s) => h.soft(s, s.page.getByText("Bilan d’ouverture figé.").first()) },
          { say: "Dessous, le compte d'attente 471 : 1 000 000 FCFA à régulariser.",
            speech: "Dessous, le compte d'attente quatre cent soixante et onze : un million de francs à régulariser.",
            do: (s) => h.soft(s, s.page.getByText(/Compte d’attente 471/).first()) },
          "C'est le solde d'ouverture de MTN MOBILE MONEY, saisi dans Trésorerie : son origine attend d'être passée en comptabilité.",
          { say: "Cet argent a été prêté par Smart Logistics. L'emprunt est enregistré, et attend l'approbation du Directeur Général.",
            do: (s) => h.soft(s, s.page.getByText(/Emprunt FIN-2611-00001/).first()) },
          "Si l'argent venait d'ailleurs, le Comptable passerait l'écriture lui-même : débit 471, crédit le bon compte.",
        ],
      }],
    },

    {
      name: "Un emprunt approuvé",
      shots: [
        {
          kind: "desktop", as: "timothee", role: "dg", start: PAGE.cash + "#loans", snapshot: true,
          lines: [
            { say: "Le Directeur Général ouvre Emprunts & Financements, dans Trésorerie & Banque, et examine l'emprunt.",
              do: async (s) => { await s.click(s.page.locator("#tab-loans")); await s.click(s.page.locator("[data-act=decide]").first()); await h.soft(s, s.page.locator("#loan-modal-body p").filter({ hasText: "Si vous approuvez" }), 5000); } },
            { say: "S'il approuve, le million quitte le compte d'attente pour le compte de Smart Logistics. Aucun argent ne bouge.",
              speech: "S'il approuve, le million quitte le compte d'attente pour le compte de Smart Logistics. Aucun argent ne bouge.",
              do: (s) => h.soft(s, s.page.locator("#loan-modal-body p").filter({ hasText: "Si vous approuvez" })) },
            { say: "« Approuver et comptabiliser ».",
              do: async (s) => { await s.click(s.page.locator("#loan-modal-actions button").filter({ hasText: "Approuver" })); await s.wait(1.5); await press(s, "OK"); } },
          ],
        },
        {
          kind: "desktop", as: "daniel", role: "comptable", start: PAGE.je + "#journal",
          // Opened on the loan's reference: the unfiltered month lists each person's payroll entries.
          setup: async (s) => { await s.page.locator("#jr_q").fill("FIN-2611-00001"); await s.settle(1500); },
          lines: [
            { say: "Côté Comptable, l'écriture est déjà dans le journal, d'origine « Emprunts & Financements ».",
              do: (s) => h.soft(s, s.page.locator("#table-body-journal tr").first().locator("td").nth(3)) },
            { say: "Débit 471, crédit 466001, Smart Logistics : sa dette d'emprunt, gardée à part de son compte client.",
              speech: "Débit quatre cent soixante et onze, crédit quatre six six zéro zéro un, Smart Logistics : sa dette d'emprunt, gardée à part de son compte client.",
              do: (s) => h.soft(s, s.page.locator("#table-body-journal tr").first().locator("td").last()) },
            { say: "Au Grand Livre, le 471 revient à zéro.",
              speech: "Au Grand Livre, le quatre cent soixante et onze revient à zéro.",
              do: async (s) => { await s.go(PAGE.gl + "?account=471#grandlivre"); await s.wait(2); await h.soft(s, s.page.locator("#gl_current_balance")); } },
          ],
        },
      ],
    },

    {
      name: "Une écriture à la main",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.je + "#wizard", snapshot: true,
        lines: [
          "Certaines opérations, aucun module ne les connaît : ici, les frais de tenue de compte que CCA Bank a prélevés en novembre.",
          { say: "Dans « Saisie Manuelle », le Comptable choisit le journal de banque. La date du jour est déjà posée.",
            do: async (s) => { await s.hover(s.page.locator("#wiz_journal")); await s.page.locator("#wiz_journal").selectOption("BQ"); await s.wait(0.5); await h.soft(s, s.page.locator("#wiz_date")); } },
          { say: "La pièce : le relevé de novembre. Le libellé : frais de tenue de compte.",
            do: async (s) => { await s.type(s.page.locator("#wiz_ref"), "RELEVE-CCA-2611", { delay: 50 }); await s.type(s.page.locator("#wiz_desc"), "Frais de tenue de compte CCA Bank, novembre", { delay: 35 }); } },
          { say: "Au débit, le nouveau sous-compte 631001 : 2 500 FCFA.",
            speech: "Au débit, le nouveau sous-compte six, trois, un, zéro, zéro, un : deux mille cinq cents francs.",
            do: async (s) => { await pickAccount(s, 0, "631001"); await s.type(s.page.locator(".wiz-debit").nth(0), "2500", { delay: 120 }); } },
          { say: "Au crédit, la banque, 5211 CCA Bank. L'écart tombe à zéro.",
            speech: "Au crédit, la banque, cinq deux un un, CCA Bank. L'écart tombe à zéro.",
            do: async (s) => { await pickAccount(s, 1, "5211"); await s.type(s.page.locator(".wiz-credit").nth(1), "2500", { delay: 120 }); await h.soft(s, s.page.locator("#wiz_balance_indicator")); } },
          { say: "« Poster au Grand Livre » est possible. Mais LPC fait relire : le Comptable sauvegarde un brouillon.",
            do: async (s) => { await h.soft(s, s.page.locator("#btn_post_gl")); await s.click(s.page.getByRole("button", { name: /Sauvegarder Brouillon/ })); await s.wait(1); } },
          { say: "Le brouillon attend dans la file d'attente. Il n'est pas encore au Grand Livre.",
            do: async (s) => { await press(s, "OK"); await s.wait(1.2); await h.soft(s, s.page.locator("#table-body-queue tr").filter({ hasText: "RELEVE-CCA-2611" })); } },
          { say: "Le score de confiance compare ce brouillon aux écritures habituelles. Un brouillon saisi par erreur se supprime.",
            do: (s) => h.tour(s, [s.page.locator("#table-body-queue tr").filter({ hasText: "RELEVE-CCA-2611" }).locator("td").nth(5), s.page.locator("#table-body-queue tr").filter({ hasText: "RELEVE-CCA-2611" }).getByRole("button", { name: "Supprimer" })], 0.6) },
        ],
      }],
    },

    {
      name: "La validation",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.finance, snapshot: true,
        lines: [
          { say: "Le Contrôleur Financier le voit sur sa Vue Finance : « Validations en attente », 1.",
            do: (s) => h.soft(s, card(s, "Validations en attente"), 8000) },
          { say: "Le détail nomme le brouillard. « Ouvrir la file d'attente ».",
            do: async (s) => { await s.click(card(s, "Validations en attente")); await s.wait(1.8); await h.soft(s, s.page.getByText("RELEVE-CCA-2611").first(), 5000); } },
          { say: "Il relit les lignes : 631001 au débit, CCA Bank au crédit, le relevé en pièce.",
            speech: "Il relit les lignes : six, trois, un, zéro, zéro, un au débit, CCA Bank au crédit, le relevé en pièce.",
            do: async (s) => { await s.click(s.page.getByRole("link", { name: /Ouvrir la file d'attente/ })); await s.wait(2); await h.soft(s, s.page.locator("#table-body-queue tr").first().locator("td").nth(4)); } },
          { say: "« Valider ». Une écriture postée ne se modifie plus : une erreur se corrigerait par une écriture inverse.",
            do: async (s) => { await s.click(s.page.locator("#table-body-queue tr").first().getByRole("button", { name: "Valider" })); await s.wait(0.8); } },
          { say: "Il confirme. La file est vide, et le Comptable est prévenu.",
            do: async (s) => { await press(s, "Poster au Grand Livre"); await s.wait(1.2); await press(s, "OK"); await h.soft(s, s.page.getByText("Aucun brouillard en attente.").first()); } },
        ],
      }],
    },

    {
      name: "La balance",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.gl,
        lines: [
          { say: "Grand Livre OHADA, premier onglet : la balance à six colonnes, par classe et par compte.",
            do: (s) => h.soft(s, s.page.locator("#tab-balance")) },
          { say: "Ouverture, mouvements de la période, et solde de clôture, en débit et en crédit.",
            do: (s) => h.tour(s, [0, 1, 2].map((i) => s.page.locator("#table-balance thead tr").first().locator("th").nth(i + 1)), 0.4) },
          { say: "En bas, le total général : débits et crédits égaux. La balance est équilibrée.",
            do: async (s) => { await s.scroll(6000, s.page.locator("#content-balance .overflow-auto").first()); await s.wait(0.8); await h.soft(s, s.page.locator("#tfoot-balance")); } },
          { say: "Les comptes sans mouvement sont masqués. « Afficher comptes à zéro » les montre.",
            do: async (s) => { await s.scroll(-6000, s.page.locator("#content-balance .overflow-auto").first()); await h.soft(s, s.page.locator("#toggle_bg")); } },
          { say: "Deuxième onglet, la Balance des Tiers : ce que chacun doit, ou ce que LPC lui doit.",
            speech: "Deuxième onglet, la Balance des Tiers : ce que chacun doit, ou ce que L P C lui doit.",
            do: async (s) => { await glTab(s, "tiers"); await s.wait(1.5); } },
          { say: "HOREB SARL attend 227 500 FCFA. Smart Logistics, 1 000 000 au titre de son prêt.",
            speech: "HOREB SARL attend deux cent vingt-sept mille cinq cents francs. Smart Logistics, un million, au titre de son prêt.",
            do: (s) => h.tour(s, [s.page.locator("#tbody-tiers tr").filter({ hasText: "HOREB" }).first(), s.page.locator("#tbody-tiers tr").filter({ hasText: "466001" }).first()], 0.6) },
        ],
      }],
    },

    {
      name: "Le lettrage",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.gl + "?account=401001#grandlivre", snapshot: true,
        lines: [
          { say: "Troisième onglet, le Grand Livre d'un compte. Voici celui de Source du Pays, 401001.",
            speech: "Troisième onglet, le Grand Livre d'un compte. Voici celui de Source du Pays, quatre zéro un zéro zéro un.",
            do: (s) => h.soft(s, s.page.locator("#gl_account_select"), 6000) },
          { say: "Chaque ligne, avec le solde cumulé. Chaque réception au crédit, chaque paiement au débit.",
            do: (s) => h.tour(s, [glRow(s, "PO-2611-00003"), glRow(s, "Règlement fournisseur")], 0.5) },
          "Lettrer, c'est marquer d'une même lettre une facture et son paiement, pour voir ce qui reste ouvert.",
          { say: "« Lettrer auto » associe chaque débit à un crédit du même montant.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Lettrer auto/i })); await s.wait(0.8); } },
          { say: "Il confirme : deux paires, A et B.",
            do: async (s) => { await press(s, "Lettrer"); await s.wait(1.5); await press(s, "OK"); await s.wait(1.2); } },
          { say: "Le bandeau confirme que chaque lettre est équilibrée. Le reste se lettre à la main, une lettre par ligne.",
            do: (s) => h.tour(s, [s.page.locator("#gl_letters_chips span").first(), s.page.locator(".lettrage-input").first()], 0.6) },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : les achats, la paie, la trésorerie et les emprunts postent leurs écritures seuls.",
          "Le Comptable tient le plan comptable, régularise le compte d'attente, et saisit ce qu'aucun module ne connaît.",
          "Le Contrôleur Financier valide les brouillons ; la balance, les tiers et le lettrage permettent de tout vérifier.",
          "La prochaine vidéo, Comptabilité 2, présente les immobilisations et les budgets.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
