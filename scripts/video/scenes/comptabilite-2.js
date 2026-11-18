"use strict";
/**
 * Modules, partie 12 : Comptabilité 2, Immobilisations et Budgets & Performance. docs/TRAINING_VIDEOS.md says
 * how a scene is written and filmed.
 *
 * The Contrôleur Financier (Luc Nana on screen) capitalises LPC's delivery truck from the Flotte queue, bought
 * on credit (Dr 2451 / Cr 4812, VIDEO_QA_FINDINGS 12.1), reads the register, runs November's depreciation once
 * (12.2: a second run passes nothing, December is refused before it begins). The Comptable (Daniel Massomba)
 * finds both entries in the Grand Livre. The Contrôleur Financier sets example targets in Budgets & Performance
 * and the Comptable reads them against the ledger (12.3), down to the alert. Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-12-film (after-09b-tresorerie + migrations 147 to 149). The truck LT 742 KL (example plate,
 * owner question 12) waits in « À Capitaliser (Flotte) »; its value, 18 500 000 F, is an example the video says
 * so. The scene writes: the truck capitalised (IMM-1-…), November's depreciation (DOT-2026-11, 308 333 F), the
 * example targets for 2026.
 */
const h = require("../helpers");

const PAGE = {
  assets: "/modules/accounting/fixed_assets.php",
  budgets: "/modules/accounting/budgets.php",
  ledger: "/modules/accounting/ledger.php",
};
const TRUCK = "LT 742 KL";

const modal = (s) => s.page.locator("dialog[open]").last();
const press = async (s, label) => { await s.click(modal(s).getByRole("button", { name: label })); await s.wait(0.6); };
const tab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const pick = async (s, select, value) => {
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(value);
  await s.page.locator(select).dispatchEvent("change");
  await s.wait(0.6);
};
const pickLike = async (s, select, re) => {
  await s.page.waitForFunction((sel) => document.querySelectorAll(`${sel} option`).length > 1, select, { timeout: 15000 });
  const opt = await s.page.locator(`${select} option`).filter({ hasText: re }).first().getAttribute("value");
  await pick(s, select, opt);
};
const setDate = async (s, sel, v) => { await s.hover(s.page.locator(sel)); await s.page.locator(sel).fill(v); await s.page.locator(sel).dispatchEvent("input"); await s.wait(0.4); };
const regRow = (s) => s.page.locator("#table-body-register tr").filter({ hasText: "LT 742 KL" }).first();
const target = (s, key) => s.page.locator(`#targets-rows tr`).filter({ hasText: key }).first();
const typeTarget = async (s, label, value) => {
  const input = target(s, label).locator("input[data-annual-for]");
  await s.type(input, String(value), { delay: 45 });
};

module.exports = {
  id: "comptabilite-2",
  file: "LPC-ERP-Comptabilite-2-Immobilisations-Budgets",
  meta: { title: "LPC ERP · Comptabilité 2 : Immobilisations et Budgets (Modules, partie 12)", artist: "JBS Praxis" },
  library: {
    slug: "comptabilite-2",
    title: "Comptabilité 2 : Immobilisations et Budgets",
    subtitle: "Capitaliser le camion, les dotations du mois, les objectifs face au Grand Livre",
    series: "Modules",
    part: 12,
    parts: 15,
    pages: [PAGE.assets, PAGE.budgets],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 12",
    title: "Comptabilité 2",
    subtitle: "Immobilisations, Budgets & Performance",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 12",
    title: "Merci",
    subtitle: "À suivre : Comptabilité 3",
    left: "bureau.lpc.cm",
  },
  holders: { controleur: "Luc Nana", comptable: "Daniel Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-12-film");
    log("ground: pre-12-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Comptabilité 2 : les immobilisations et les budgets.",
          "Une immobilisation est un bien qui sert plusieurs années, comme le camion de livraison.",
          "Le Contrôleur Financier l'inscrit au registre, et son achat entre au Grand Livre.",
          "Chaque mois, l'amortissement répartit son coût sur sa durée de vie.",
          "Puis les budgets : des objectifs annuels, comparés à ce que le Grand Livre a réellement enregistré.",
        ],
      }],
    },

    {
      name: "Les immobilisations",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.assets,
        lines: [
          { say: "Le Contrôleur Financier ouvre Immobilisations, dans Comptabilité & Finance.",
            do: (s) => h.soft(s, h.shell.menu(s, "Immobilisations")) },
          { say: "Trois onglets : les véhicules à capitaliser, le registre des actifs, et les dotations mensuelles.",
            do: (s) => h.tour(s, [s.page.locator("#tab-queue"), s.page.locator("#tab-register"), s.page.locator("#tab-dotations")], 0.4) },
          { say: "Le camion enregistré par les Opérations dans Flotte & Maintenance attend ici : LT 742 KL, un Toyota Dyna.",
            speech: "Le camion enregistré par les Opérations dans Flotte et Maintenance attend ici : L T sept cent quarante-deux K L, un Toyota Dyna.",
            do: (s) => h.soft(s, s.page.locator("#table-body-queue tr").filter({ hasText: TRUCK }).first()) },
          { say: "Pour un bien qui n'est pas un véhicule, un ordinateur ou du mobilier, « Ajouter Actif Manuel ».",
            do: (s) => h.soft(s, s.page.getByRole("button", { name: /Ajouter Actif Manuel/ })) },
        ],
      }],
    },

    {
      name: "Capitaliser le camion",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.assets, snapshot: true,
        lines: [
          { say: "Il clique sur « Capitaliser ». Le nom du camion est déjà rempli.",
            do: async (s) => { await s.click(s.page.locator("#table-body-queue tr").filter({ hasText: TRUCK }).getByRole("button", { name: /Capitaliser/ })); await s.wait(0.8); } },
          { say: "La valeur d'acquisition, ici un exemple : dix-huit millions cinq cent mille francs.",
            do: (s) => s.type(s.page.locator("#cap_cost"), "18500000") },
          { say: "La durée de vie utile, en mois : cinq ans, soixante mois.",
            do: (s) => s.type(s.page.locator("#cap_lifespan"), "60") },
          { say: "La date d'achat et la mise en service : le premier novembre. La méthode reste linéaire.",
            do: async (s) => { await setDate(s, "#cap_date", "2026-11-01"); await setDate(s, "#cap_service_start", "2026-11-01"); await h.soft(s, s.page.locator("#cap_method")); } },
          { say: "La dotation mensuelle s'affiche : trois cent huit mille trois cent trente-trois francs.",
            do: (s) => h.soft(s, s.page.locator("#cap_monthly_display")) },
          { say: "Puis comment le camion a été acquis. Ici, à crédit : il reste à payer au fournisseur.",
            do: async (s) => { await s.scroll(300, s.page.locator("#form-capitalize")); await pick(s, "#cap_funding", "supplier"); } },
          { say: "L'écran dit l'écriture qui sera passée : débit 2451, crédit 4812, Fournisseurs d'immobilisations.",
            speech: "L'écran dit l'écriture qui sera passée : débit deux mille quatre cent cinquante et un, crédit quatre mille huit cent douze, Fournisseurs d'immobilisations.",
            do: (s) => h.soft(s, s.page.locator("#cap_entry_hint")) },
          { say: "Payé comptant, il aurait choisi le compte de trésorerie, et l'argent en serait sorti.",
            do: (s) => h.soft(s, s.page.locator("#cap_funding")) },
          { say: "Les trois comptes sont proposés : matériel automobile, son amortissement, et la dotation.",
            do: async (s) => { await s.scroll(300, s.page.locator("#form-capitalize")); await h.tour(s, [s.page.locator("#cap_acc_asset"), s.page.locator("#cap_acc_depr"), s.page.locator("#cap_acc_exp")], 0.3); } },
          { say: "« Enregistrer l'Actif ». Le message donne la référence de l'écriture d'acquisition.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-cap")); await s.wait(1.2); await h.soft(s, modal(s), 4000); } },
          { say: "Le camion quitte la file : il est au registre.",
            do: async (s) => { await press(s, "OK"); await s.wait(0.8); await h.soft(s, s.page.locator("#table-body-queue")); } },
        ],
      }],
    },

    {
      name: "Le registre",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.assets + "#register",
        lines: [
          { say: "Le registre des actifs montre le camion, son coût et sa valeur nette comptable.",
            do: (s) => h.soft(s, regRow(s)) },
          { say: "Sous son nom : son compte, l'écriture de son acquisition, et le nombre de mois amortis.",
            do: (s) => h.soft(s, regRow(s).locator("td").first()) },
          { say: "Les filtres séparent les actifs en cours, les totalement amortis, et ceux qui sont sortis.",
            do: (s) => h.tour(s, ["active", "depreciated", "disposed"].map((k) => s.page.locator(`[data-filter-btn="${k}"]`)), 0.3) },
          { say: "« Céder » sort un actif vendu ou mis au rebut, avec sa plus ou moins-value.",
            do: (s) => h.soft(s, regRow(s).getByRole("button", { name: /Céder/ })) },
        ],
      }],
    },

    {
      name: "Un bien payé comptant",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.assets, snapshot: true,
        lines: [
          { say: "Le Comptable peut aussi inscrire un bien. Exemple : une imprimante pour la comptabilité, payée par Mobile Money.",
            do: (s) => h.soft(s, s.page.getByRole("button", { name: /Ajouter Actif Manuel/ })) },
          { say: "« Ajouter Actif Manuel ». Il tape le nom de l'imprimante.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Ajouter Actif Manuel/ })); await s.wait(0.8); await s.type(s.page.locator("#cap_name"), "Imprimante multifonction comptabilité", { delay: 40 }); } },
          { say: "Cent quatre-vingt-cinq mille francs, sur trois ans, soit trente-six mois.",
            do: async (s) => { await s.type(s.page.locator("#cap_cost"), "185000"); await s.type(s.page.locator("#cap_lifespan"), "36"); } },
          { say: "Achetée et mise en service le dix novembre : le premier mois comptera vingt et un jours.",
            do: async (s) => { await setDate(s, "#cap_date", "2026-11-10"); await setDate(s, "#cap_service_start", "2026-11-10"); } },
          { say: "Cette fois, « Payé par un compte de trésorerie » : MTN MOBILE MONEY, dont le solde s'affiche.",
            do: async (s) => { await s.scroll(300, s.page.locator("#form-capitalize")); await pick(s, "#cap_funding", "treasury"); await pickLike(s, "#cap_treasury", /MTN/); } },
          { say: "Pour un bien qui n'est pas un véhicule, il choisit lui-même les comptes : matériel de bureau, 2441.",
            speech: "Pour un bien qui n'est pas un véhicule, il choisit lui-même les comptes : matériel de bureau, deux mille quatre cent quarante et un.",
            do: async (s) => { await s.scroll(300, s.page.locator("#form-capitalize")); await pickLike(s, "#cap_acc_asset", /^\[2441\]/); } },
          { say: "Puis son amortissement, 2844, et la dotation, 6813.",
            speech: "Puis son amortissement, deux huit quatre quatre, et la dotation, six huit un trois.",
            do: async (s) => { await pickLike(s, "#cap_acc_depr", /^\[2844\]/); await pickLike(s, "#cap_acc_exp", /^\[6813\]/); } },
          { say: "L'écriture annoncée : débit 2441, crédit le compte de MTN. L'argent sortira du compte.",
            speech: "L'écriture annoncée : débit deux mille quatre cent quarante et un, crédit le compte de M T N. L'argent sortira du compte.",
            do: (s) => h.soft(s, s.page.locator("#cap_entry_hint")) },
          { say: "« Enregistrer l'Actif ». Si le solde ne suffisait pas, rien ne serait enregistré.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-cap")); await s.wait(1.2); await h.soft(s, modal(s), 4000); await s.wait(1.5); await press(s, "OK"); } },
          { say: "Au registre, l'imprimante rejoint le camion, payée par MTN MOBILE MONEY.",
            do: async (s) => { await tab(s, "register"); await s.wait(1); await h.soft(s, s.page.locator("#table-body-register tr").filter({ hasText: "Imprimante" }).first(), 5000); } },
          "Et dans Trésorerie & Banque, le mouvement « Achat d'immobilisation » est sorti du compte MTN.",
        ],
      }],
    },

    {
      name: "Les dotations du mois",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.assets + "#dotations", snapshot: true,
        lines: [
          { say: "Chaque fin de mois, onglet Dotations Mensuelles. Le mois cible est novembre.",
            do: (s) => setDate(s, "#dotation_month", "2026-11") },
          { say: "« Aperçu » : le camion, trente jours, trois cent huit mille trois cent trente-trois francs ; l'imprimante, vingt et un jours.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Aperçu/ })); await s.wait(1); await h.soft(s, s.page.locator("#dotation_preview table"), 5000); } },
          { say: "« Exécuter », puis il confirme. L'écriture part directement au Grand Livre : débit 68, crédit 28.",
            speech: "« Exécuter », puis il confirme. L'écriture part directement au Grand Livre : débit soixante-huit, crédit vingt-huit.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Exécuter/ })); await s.wait(0.6); await press(s, "Confirmer"); await s.wait(1.2); await h.soft(s, modal(s), 4000); } },
          { say: "Un mois ne se passe qu'une fois par actif. L'aperçu le dit maintenant.",
            do: async (s) => { await press(s, "OK"); await s.wait(1); await h.soft(s, s.page.locator("#dotation_preview"), 4000); } },
          { say: "Un actif ajouté plus tard dans le mois recevrait sa propre écriture, sans reprendre les autres.",
            do: (s) => h.soft(s, s.page.locator("#content-dotations p").filter({ hasText: /Exécuter/ }).first()) },
          { say: "Et décembre n'a pas commencé : ses dotations se passeront à partir du premier.",
            do: async (s) => { await setDate(s, "#dotation_month", "2026-12"); await s.click(s.page.getByRole("button", { name: /Aperçu/ })); await s.wait(1); await h.soft(s, s.page.locator("#dotation_preview"), 4000); } },
          { say: "Au registre, chaque bien porte un mois d'amortissement, et sa valeur nette baisse d'autant.",
            do: async (s) => { await tab(s, "register"); await s.wait(1); await h.soft(s, regRow(s), 5000); } },
        ],
      }],
    },

    {
      name: "Au Grand Livre",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.ledger,
        lines: [
          { say: "Le Comptable vérifie au Grand Livre OHADA, compte 2451, matériel automobile.",
            speech: "Le Comptable vérifie au Grand Livre OHADA, compte deux mille quatre cent cinquante et un, matériel automobile.",
            do: async (s) => { await s.click(s.page.locator("#tab-grandlivre")); await pickLike(s, "#gl_account_select", /\[2451\]/); } },
          { say: "Le camion y est, au débit, pour dix-huit millions cinq cent mille francs.",
            do: (s) => h.soft(s, s.page.locator("#tbody-gl tr").filter({ hasText: "IMM-" }).first(), 6000) },
          { say: "En face, le compte 4812 garde la dette envers le fournisseur jusqu'à son paiement.",
            speech: "En face, le compte quatre mille huit cent douze garde la dette envers le fournisseur jusqu'à son paiement.",
            do: async (s) => { await pickLike(s, "#gl_account_select", /\[4812\]/); await h.soft(s, s.page.locator("#tbody-gl tr").first(), 6000); } },
          { say: "Et la dotation de novembre crédite 28451, l'amortissement du matériel automobile.",
            speech: "Et la dotation de novembre crédite deux huit quatre cinq un, l'amortissement du matériel automobile.",
            do: async (s) => { await pickLike(s, "#gl_account_select", /\[28451\]/); await h.soft(s, s.page.locator("#tbody-gl tr").filter({ hasText: "DOT-2026-11" }).first(), 6000); } },
          { say: "Le compte 5521 de MTN porte la sortie de l'imprimante, comme la carte du compte.",
            speech: "Le compte cinquante-cinq vingt et un de M T N porte la sortie de l'imprimante, comme la carte du compte.",
            do: async (s) => { await pickLike(s, "#gl_account_select", /[5521]/); await h.soft(s, s.page.locator("#tbody-gl tr").filter({ hasText: "Imprimante" }).first(), 6000); } },
          "Ces écritures ne se saisissent jamais à la main : elles partent des Immobilisations, et le registre reste juste.",
          "À la clôture de l'exercice, seuls les mois jamais passés sont complétés. L'amortissement n'est jamais compté deux fois.",
        ],
      }],
    },

    {
      name: "Les objectifs",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.budgets, snapshot: true,
        lines: [
          { say: "Place aux budgets. Le Contrôleur Financier ouvre Budgets & Performance.",
            do: (s) => h.soft(s, h.shell.menu(s, "Budgets & Performance")) },
          { say: "Le réalisé, ce sont les écritures passées au Grand Livre cette année. L'écran le dit en toutes lettres.",
            do: (s) => h.soft(s, s.page.locator("#budget-source-note")) },
          { say: "Une demande de dépense pas encore payée n'y est donc pas encore.",
            do: (s) => h.soft(s, s.page.locator("#budget-source-note")) },
          { say: "Chaque ligne du budget attend son objectif : salaires, carburant, maintenance, et les autres.",
            do: (s) => h.soft(s, s.page.locator("#overview-bucket-cards > div").nth(2)) },
          { say: "Onglet Objectifs. Il saisit des objectifs d'exemple pour l'année : un montant annuel par ligne.",
            do: async (s) => { await tab(s, "targets"); await s.wait(1); await h.soft(s, s.page.locator("#targets-rows"), 5000); } },
          { say: "Salaires et charges : seize millions.",
            do: (s) => typeTarget(s, "Salaires", 16000000) },
          { say: "Carburant et transport : neuf cent mille.",
            do: (s) => typeTarget(s, "Carburant", 900000) },
          { say: "Maintenance : cent mille, un objectif serré, pour voir une alerte.",
            do: (s) => typeTarget(s, "Maintenance", 100000) },
          { say: "Autres charges : quatre millions. Le système répartit chaque montant sur douze mois.",
            do: (s) => typeTarget(s, "Autres charges", 4000000) },
          { say: "« Ajuster par mois » permet un mois différent des autres, par exemple un mois de forte saison.",
            do: (s) => h.soft(s, target(s, "Carburant").getByRole("button", { name: /Ajuster par mois/ })) },
          { say: "« Enregistrer les objectifs ».",
            do: async (s) => { await s.click(s.page.locator("#targets-save-btn")); await s.wait(1.5); await h.soft(s, s.page.locator("#targets-feedback"), 4000); } },
        ],
      }],
    },

    {
      name: "Le réalisé face aux objectifs",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.budgets,
        lines: [
          { say: "Le Comptable lit l'Aperçu. Dépenses : le réalisé de l'année face au budget.",
            do: (s) => h.soft(s, s.page.locator("#kpi_exp_actual")) },
          { say: "Chaque ligne affiche son réalisé, son objectif, et un statut au prorata de l'année écoulée.",
            do: (s) => h.tour(s, [s.page.locator("#overview-bucket-cards > div").nth(2), s.page.locator("#overview-bucket-cards > div").nth(3)], 0.5) },
          { say: "Les salaires de novembre y sont, comme les dotations du camion, dans Autres charges.",
            do: (s) => h.soft(s, s.page.locator("#overview-bucket-cards > div").filter({ hasText: "Autres charges" }).first()) },
          { say: "Le graphique compare, mois par mois, le budget et ce qui a été dépensé.",
            do: (s) => h.soft(s, s.page.locator("#executionChart")) },
          { say: "Onglet Alertes : la maintenance a déjà dépassé son rythme. Elle passe en rouge.",
            do: async (s) => { await tab(s, "alerts"); await s.wait(1); await h.soft(s, s.page.locator("#alerts-body > div").first(), 5000); } },
          "L'objectif de chiffre d'affaires sert aussi de cible au Tableau de Bord Exécutif du Directeur Général.",
          "Et chaque franc du réalisé se retrouve au Grand Livre : le budget et la comptabilité disent la même chose.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "Capitaliser un bien passe son acquisition au Grand Livre, et l'inscrit au registre.",
          "Les dotations se passent une fois par mois, et jamais deux fois à la clôture.",
          "Les budgets comparent vos objectifs à ce que le Grand Livre a réellement enregistré.",
          "Prochaine vidéo, Comptabilité 3 : les déclarations fiscales, les états financiers et la clôture de l'exercice.",
        ],
      }],
    },
  ],
};
