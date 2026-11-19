"use strict";
/**
 * Video 0, « L'histoire » (Introduction, partie 0): one OPUR 20L bottle through LPC's whole chain for PROMETAL,
 * for every role. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * A journey map (style.journey) carries the bottle station by station; at each one a few seconds of the real
 * screen, signed in as the role that acts, narrated by role (the holder named once on the bar). Every line says
 * what the app does since the module sessions' fixes (runbook §7, the module scenes).
 *
 * Seen by every role: no salary, no payslip line, no personal field. Salaires shows the page before a grid is
 * loaded and the one bank movement that paid the month.
 *
 * Grounds (read only: no glimpse writes), loaded per shot and kept while the next shot uses the same one:
 *   pre-histoire        copy A's after-05-ventes (PROMETAL, DEV-2611-00005 signed, CMD-2611-00001 and
 *                       BL-2611-00001 signed, the truck LT 742 KL) + migrations 144 to 151, on copy B;
 *   pre-histoire-paie   copy C's after-10-salaires (November paid from CCA Bank in one transfer) + migrations;
 *   pre-histoire-vides, pre-histoire-facture, pre-histoire-tableaux: the chain after Gestion des Vides,
 *                       Facturation and the dashboards' sessions (filmed once those snapshots exist).
 */
const h = require("../helpers");
const ground = require("../ground");

const PAGE = {
  mdm: "/modules/admin/master_data.php",
  po: "/modules/inventory/procurement.php",
  stock: "/modules/inventory/stock.php",
  clients: "/modules/crm/clients.php",
  orders: "/modules/sales/orders.php",
  fleet: "/modules/fleet/vehicles.php",
  empties: "/modules/operations/empties_collection.php",
  invoices: "/modules/accounting/invoices.php",
  cash: "/modules/accounting/cashflow.php",
  payroll: "/modules/hr/payroll_finance.php",
  journal: "/modules/accounting/journal_entry.php",
  ledger: "/modules/accounting/ledger.php",
  assets: "/modules/accounting/fixed_assets.php",
  exec: "/modules/dashboard/views/md_dashboard.php",
};

/** Load a ground once; consecutive shots on the same ground keep it (glimpses only read). */
let loaded = null;
const on = (name) => async () => {
  if (loaded === name) return;
  ground.restore(name);
  loaded = name;
};

const row = (s, tbody, text) => s.page.locator(`${tbody} tr`).filter({ hasText: text }).first();
const tab = (s, id) => s.click(s.page.locator(`#tab-${id}`));

/** One glimpse: a few seconds of the real screen. */
const glimpse = (o) => ({ kind: "desktop", ...o });
/** One station's map line. */
const station = (at, say, role, ...more) => ({ kind: "map", role, map: { at: Math.max(at - 1, -1), pos: Math.max(at - 1, 0) },
  lines: [{ say, map: { at, pos: at } }, ...more.map((m) => ({ say: m, map: { at, pos: at } }))] });

module.exports = {
  id: "histoire",
  file: "LPC-ERP-L-Histoire",
  meta: { title: "LPC ERP · L'histoire d'une bonbonne (Introduction, partie 0)", artist: "JBS Praxis" },
  library: {
    slug: "histoire",
    title: "L'histoire d'une bonbonne",
    subtitle: "Toute la chaîne de LPC en huit minutes, et la place de chaque rôle",
    series: "Introduction",
    part: null, // the opening video: no part number; the library sorts it first in « Introduction » (it refuses part 0)
    parts: 2,
    audience: null,
    pages: [],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Introduction",
    title: "L'histoire d'une bonbonne",
    subtitle: "Du fournisseur aux tableaux de bord : la chaîne de LPC",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Introduction",
    title: "Merci",
    subtitle: "À suivre : Données de Base",
    left: "bureau.lpc.cm",
  },
  holders: {
    dg: "Timothée Massomba", commercial: "Timothée Massomba", operations: "Bertin Chadeu",
    comptable: "Daniel Massomba", controleur: "Luc Nana",
  },
  journey: {
    eyebrow: "La chaîne de LPC",
    title: "Une bonbonne OPUR 20L, du fournisseur aux tableaux de bord",
    stations: [
      { name: "Données de Base", roles: ["controleur", "dg"] },
      { name: "Achats", roles: ["operations", "controleur"] },
      { name: "Stock", roles: ["operations"] },
      { name: "Proposition", roles: ["commercial"] },
      { name: "Ventes & livraison", roles: ["operations"] },
      { name: "Flotte", roles: ["operations"] },
      { name: "Vides & recyclage", roles: ["operations"] },
      { name: "Facture & encaissement", roles: ["comptable"] },
      { name: "Trésorerie", roles: ["comptable", "controleur", "dg"] },
      { name: "Salaires", roles: ["controleur", "comptable"] },
      { name: "Comptabilité", roles: ["comptable", "controleur"] },
      { name: "Tableaux de bord", roles: ["dg"] },
    ],
  },

  async prepare({ log }) {
    ground.restore("pre-histoire");
    loaded = "pre-histoire";
    log("ground: pre-histoire restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans l'histoire de LPC ERP.",
          "En huit minutes, nous suivons une bonbonne OPUR de vingt litres, du fournisseur jusqu'aux tableaux de bord.",
          "À chaque étape, vous voyez qui agit, et ce que l'application enregistre toute seule.",
          "Repérez votre rôle : c'est là que vous intervenez dans la chaîne.",
        ],
      }],
    },

    {
      name: "La chaîne",
      shots: [{
        kind: "map", map: { at: -1, pos: 0 },
        lines: [
          "Voici la chaîne de LPC, en douze étapes.",
          "Tout commence par les Données de Base : les produits, leurs prix, les fournisseurs.",
          { say: "Puis LPC achète ses bonbonnes pleines, les stocke, signe avec son client, et les livre avec son camion.", map: { at: -1, pos: 4 } },
          { say: "Le client garde la bouteille, sans consigne. LPC récupère ensuite les vides, et les revend aux recycleurs.", map: { at: -1, pos: 6 } },
          { say: "Enfin, la facture est encaissée, les salaires payés, et la comptabilité rassemble tout, jusqu'aux tableaux de bord.", map: { at: -1, pos: 11 } },
          { say: "Chaque couleur est un rôle : la Direction, le Commercial, les Opérations, le Comptable et le Contrôleur Financier.", map: { at: -1, pos: 0 } },
        ],
      }],
    },

    {
      name: "Données de Base",
      shots: [
        station(0, "Étape un : les Données de Base, tenues par le Contrôleur Financier avec le Directeur Général.", "controleur", "Le Comptable et le Responsable des Opérations les consultent ; tout le reste de l'application les lit."),
        glimpse({ as: "luc", role: "controleur", start: PAGE.mdm, ground: on("pre-histoire"), lines: [
          { say: "La bonbonne OPUR 20L y a sa fiche : son prix de base, 2 275 FCFA, et son compte de vente.",
            do: (s) => h.soft(s, row(s, "#table-body", "OPUR 20L")) },
          { say: "Elle est liée à son emballage vide : pour chaque bonbonne livrée, le client doit un vide.",
            do: (s) => h.soft(s, row(s, "#table-body", "20L sans bouchon")) },
          { say: "Les tarifs des fournisseurs et les prix de chaque client sont tenus ici aussi.",
            do: (s) => s.click(s.page.locator("#mdm-tabs button").filter({ hasText: "Tarifs Clients" }).first()) },
          { say: "Une correction faite ici se voit partout : dans les devis, les commandes, les achats et la paie.",
            do: (s) => h.soft(s, s.page.locator("#table-body tr").first()) },
        ] }),
      ],
    },

    {
      name: "Achats",
      shots: [
        station(1, "Étape deux : les achats. Les fournisseurs vendent des bonbonnes pleines, et ne reprennent jamais les vides.", "operations", "Le Responsable des Opérations commande et reçoit ; le Contrôleur Financier paie ce qui est reçu."),
        glimpse({ as: "bertin", role: "operations", start: PAGE.po, ground: on("pre-histoire"), lines: [
          { say: "Le Responsable des Opérations commande à Source du Pays, à son tarif : cent bonbonnes, 185 000 FCFA.",
            do: (s) => h.soft(s, row(s, "#table-body", "PO-2611-00007")) },
          { say: "À la réception, les bonbonnes entrent en stock, la dette envers le fournisseur naît, et la ristourne se gagne.",
            do: (s) => h.soft(s, s.page.locator("#kpi-ribbon")) },
        ] }),
        glimpse({ as: "luc", role: "controleur", start: PAGE.po, ground: on("pre-histoire"), lines: [
          { say: "Le Contrôleur Financier paie ce qui est reçu en totalité, depuis un compte de trésorerie.",
            do: (s) => h.soft(s, s.page.locator("#btn-pay-supplier")) },
        ] }),
      ],
    },

    {
      name: "Stock",
      shots: [
        station(2, "Étape trois : le stock, tenu par le Responsable des Opérations.", "operations", "Chaque bonbonne qui entre ou qui sort laisse une trace, en stock et en comptabilité."),
        glimpse({ as: "bertin", role: "operations", start: PAGE.stock, ground: on("pre-histoire"), lines: [
          { say: "Chaque entrée et chaque sortie bouge le stock, valorisé au coût moyen pondéré.",
            do: (s) => h.soft(s, s.page.locator("#kpi_stock_value")) },
          { say: "Une avarie ou un manquant d'inventaire se déclare avec sa raison, et sort aussi des comptes.",
            do: (s) => h.soft(s, s.page.getByRole("button", { name: /Déclarer Avarie/ })) },
          { say: "L'OPUR 20L est en alerte : son seuil couvre deux semaines de livraisons promises au client.",
            do: (s) => h.soft(s, row(s, "#tbody-stock", "OPUR 20L")) },
          { say: "La Fiche de Stock retrace, produit par produit, chaque mouvement avec son coût, et le compte de stock dit la même chose.",
            do: (s) => h.soft(s, h.shell.menu(s, "Fiche de Stock")) },
        ] }),
      ],
    },

    {
      name: "Proposition commerciale",
      shots: [
        station(3, "Étape quatre : la proposition commerciale. Le Commercial est aujourd'hui le Directeur Général.", "commercial", "Il gagne le client, et le contrat fixe le prix, le délai de paiement et la part des vides à rendre."),
        glimpse({ as: "timothee", role: "commercial", start: PAGE.clients, ground: on("pre-histoire"), lines: [
          { say: "Il crée PROMETAL, qui retient à la source l'acompte d'impôt sur le revenu.",
            do: (s) => h.soft(s, row(s, "#clients-tbody", "PROMETAL")) },
          { say: "Le devis prévoit 1 667 bonbonnes par mois, payables à quinze jours, avec au moins 80 % des vides rendus.",
            do: async (s) => { await s.click(s.page.locator("#tab-btn-devis")); await s.wait(0.8); await h.soft(s, row(s, "#devis-tbody", "PROMETAL")); } },
          { say: "PROMETAL le signe en ligne. La signature reporte tout sur sa fiche : son prix, son délai, son minimum de vides.",
            do: (s) => h.soft(s, row(s, "#devis-tbody", "PROMETAL")) },
        ] }),
      ],
    },

    {
      name: "Commande et livraison",
      shots: [
        station(4, "Étape cinq : la commande et la livraison.", "operations", "Le Responsable des Opérations prend la commande, et organise la livraison avec le camion de LPC."),
        glimpse({ as: "bertin", role: "operations", start: PAGE.orders, ground: on("pre-histoire"), lines: [
          { say: "Le Responsable des Opérations saisit la commande de PROMETAL au prix signé : deux cents bonbonnes, 455 000 FCFA.",
            do: (s) => h.soft(s, row(s, "#table-body", "CMD-2611-00001")) },
          { say: "Le bon de livraison part avec le camion de LPC : les bonbonnes sortent du stock, et leur coût passe en comptabilité.",
            do: async (s) => { await tab(s, "dispatch"); await s.wait(0.8); await h.soft(s, row(s, "#table-body", "BL-2611-00001")); } },
          { say: "PROMETAL signe le bon sur son téléphone. La livraison devient « À facturer », et PROMETAL doit les vides : au moins 160 à rendre.",
            do: (s) => h.soft(s, row(s, "#table-body", "BL-2611-00001")) },
        ] }),
      ],
    },

    {
      name: "Flotte",
      shots: [
        station(5, "Étape six : la flotte.", "operations", "Le Responsable des Opérations y suit le camion ; ses coûts passent par la Finance."),
        glimpse({ as: "bertin", role: "operations", start: PAGE.fleet, ground: on("pre-histoire"), lines: [
          { say: "Le camion LT 742 KL a sa fiche : son chauffeur du jour, ses pleins, son entretien et ses documents.",
            speech: "Le camion L T sept cent quarante-deux K L a sa fiche : son chauffeur du jour, ses pleins, son entretien et ses documents.",
            do: async (s) => { await tab(s, "flotte"); await s.wait(0.8); await h.soft(s, row(s, "#table-body-vehicles", "LT 742 KL")); } },
          { say: "Un plein ou une réparation saisis ici deviennent une demande de dépense, que la Finance valide et paie.",
            do: (s) => h.soft(s, s.page.locator("#tab-carburant")) },
        ] }),
      ],
    },

    {
      name: "Vides et recyclage",
      shots: [
        station(6, "Étape sept : les vides, suivis par le Responsable des Opérations.", "operations", "Le client doit rendre au moins la part de vides que son contrat fixe."),
        glimpse({ as: "bertin", role: "operations", start: PAGE.empties, ground: on("pre-histoire-vides"), lines: [
          { say: "Pour PROMETAL : deux cents bonbonnes livrées, donc au moins 160 vides à récupérer. Après une collecte de 130, il en reste 30.",
            do: (s) => h.soft(s, row(s, "#tbody-owed", "PROMETAL")) },
          { say: "Chaque collecte produit un Certificat de Restitution d'Emballages, le CRE, que LPC puis le client signent.",
            do: (s) => h.soft(s, row(s, "#tbody-owed", "PROMETAL")) },
          { say: "Les vides collectés sont revendus aux recycleurs : cent bonbonnes, 20 000 FCFA en caisse, une seconde source de revenus.",
            do: async (s) => { await tab(s, "revenue"); await s.wait(1); await h.soft(s, s.page.locator("#tbody-revenue tr").first()); } },
        ] }),
      ],
    },

    {
      name: "Facture et encaissement",
      shots: [
        station(7, "Étape huit : la facture et l'encaissement, tenus par le Comptable.", "comptable", "La facture part du bon de livraison signé ; l'argent arrive net de la retenue à la source."),
        glimpse({ as: "daniel", role: "comptable", start: PAGE.invoices, ground: on("pre-histoire-facture"), lines: [
          { say: "Il facture le bon de livraison signé : 455 000 FCFA, à payer quinze jours plus tard, comme le devis le prévoit.",
            do: async (s) => { await tab(s, "invoices_payments"); await s.wait(1); await h.soft(s, row(s, "#table-body-invoices", "FAC-2611-00001")); } },
          { say: "PROMETAL retient 2,2 % d'acompte d'impôt : la facture montre le net à virer, et son attestation justifiera la retenue.",
            do: (s) => h.soft(s, row(s, "#table-body-invoices", "FAC-2611-00001")) },
          { say: "Après un avoir pour quatre bonbonnes percées, le virement arrive net de la retenue : le Comptable l'encaisse, et le compte de PROMETAL est soldé.",
            do: (s) => h.soft(s, s.page.locator("#table-body-payments tr").first()) },
        ] }),
      ],
    },

    {
      name: "Trésorerie",
      shots: [
        station(8, "Étape neuf : la trésorerie, les dépenses et les emprunts.", "comptable", "Le Comptable et le Contrôleur Financier tiennent les comptes ; le Directeur Général approuve les emprunts."),
        glimpse({ as: "daniel", role: "comptable", start: PAGE.cash, ground: on("pre-histoire"), lines: [
          { say: "Chaque compte, banque, Mobile Money ou caisse, a son solde, ses mouvements, et son propre compte au plan comptable.",
            do: (s) => h.tour(s, [s.page.locator("#wallets-grid > div").nth(0), s.page.locator("#wallets-grid > div").nth(2)], 0.5) },
          { say: "Les dépenses se demandent ; la Finance les valide, puis les paie depuis l'un de ces comptes.",
            do: (s) => h.soft(s, s.page.locator("#table-body-transactions tr").first()) },
        ] }),
        glimpse({ as: "timothee", role: "dg", start: PAGE.cash + "#loans", ground: on("pre-histoire"), lines: [
          { say: "Un emprunt, comme celui de Smart Logistics, ne bouge rien sans l'accord du Directeur Général.",
            do: (s) => h.soft(s, s.page.locator("#loans-root section").first()) },
        ] }),
      ],
    },

    {
      name: "Salaires",
      shots: [
        station(9, "Étape dix : les salaires.", "controleur", "Le Contrôleur Financier calcule la paie et la verse ; le Comptable approuve les avances."),
        // No grid is loaded: the columns only, never a person's pay (everyone sees this video).
        glimpse({ as: "luc", role: "controleur", start: PAGE.payroll, ground: on("pre-histoire"), lines: [
          { say: "En fin de mois, le Contrôleur Financier calcule la paie : la CNPS, les impôts et les retenues, bulletin par bulletin.",
            do: async (s) => { await tab(s, "payroll"); await s.wait(0.8); await h.soft(s, s.page.getByRole("button", { name: /Charger Grille/ })); } },
          { say: "Le jour du virement, « Marquer Payées » : un seul mouvement de banque pour tout le mois, et tout passe en comptabilité.",
            do: (s) => h.soft(s, s.page.getByRole("button", { name: /Marquer Payées/ })) },
          "Le détail de chacun reste dans la vidéo des salaires, réservée aux rôles qui voient la paie.",
        ] }),
      ],
    },

    {
      name: "Comptabilité",
      shots: [
        station(10, "Étape onze : la comptabilité.", "comptable", "Le Comptable tient les livres ; le Contrôleur Financier vérifie et valide."),
        glimpse({ as: "daniel", role: "comptable", start: PAGE.journal + "#journal", ground: on("pre-histoire"), lines: [
          { say: "Chaque étape a déjà passé ses écritures, toute seule. Le Comptable les retrouve dans le journal, avec leur origine.",
            do: async (s) => { await s.page.locator("#jr_journal").selectOption("AC").catch(() => null); await s.page.locator("#jr_journal").dispatchEvent("change").catch(() => null); await s.wait(1); await h.soft(s, s.page.locator("#table-body-journal tr").first()); } },
        ] }),
        glimpse({ as: "daniel", role: "comptable", start: PAGE.ledger, ground: on("pre-histoire"), lines: [
          { say: "La balance les rassemble, compte par compte, équilibrée. Il ne saisit à la main que ce qu'aucun module ne connaît.",
            do: async (s) => { await s.wait(1.5); await h.soft(s, s.page.locator("#tfoot-balance")); } },
        ] }),
        glimpse({ as: "luc", role: "controleur", start: PAGE.assets, ground: on("pre-histoire"), lines: [
          { say: "Le Contrôleur Financier capitalise le camion, suit son amortissement, et compare les budgets au Grand Livre.",
            do: (s) => h.soft(s, row(s, "#table-body-queue", "LT 742 KL")) },
          "Les déclarations fiscales, les états financiers et la clôture de l'exercice en sortent : ce sont les vidéos de comptabilité.",
        ] }),
      ],
    },

    {
      name: "Tableaux de bord",
      shots: [
        station(11, "Étape douze : les tableaux de bord.", "dg", "Ils lisent ce que chacun a enregistré : rien n'est ressaisi pour les remplir."),
        glimpse({ as: "timothee", role: "dg", start: PAGE.exec, ground: on("pre-histoire-tableaux"), lines: [
          { say: "Le Directeur Général voit l'entreprise d'un coup d'œil : le chiffre d'affaires, les créances, la marge, les vides et la trésorerie.",
            do: async (s) => { await s.wait(1.5); await h.tour(s, [0, 1, 2, 3, 4].map((i) => s.page.locator("#md-kpi-grid > *").nth(i)), 0.4); } },
          { say: "Chaque indicateur s'ouvre sur son détail : les chiffres viennent du Grand Livre et du terrain.",
            do: (s) => h.soft(s, s.page.locator("#md-kpi-grid > *").first()) },
        ] }),
      ],
    },

    {
      name: "Votre place",
      shots: [
        {
          kind: "map", map: { at: -1, pos: null },
          lines: [
            "Retrouvez maintenant votre place dans la chaîne.",
            { say: "Les Opérations achètent, stockent, livrent, et récupèrent les vides.", map: { lit: ["operations"] } },
            { say: "Le Commercial gagne les clients et leurs contrats.", map: { lit: ["commercial"] } },
            { say: "Le Comptable facture, encaisse, et tient les comptes.", map: { lit: ["comptable"] } },
            { say: "Le Contrôleur Financier tient les données de base, paie, et contrôle.", map: { lit: ["controleur"] } },
            { say: "La Direction approuve et pilote le tout.", map: { lit: ["dg"] } },
          ],
        },
        {
          kind: "card", card: "closing",
          lines: [
            "Une bonbonne, douze étapes, cinq rôles : chacun fait sa part, et l'application relie le tout.",
            "Les vidéos suivantes reprennent chaque étape en détail, en commençant par les Données de Base.",
          ],
        },
      ],
    },
  ],
};
