"use strict";
/**
 * Modules, partie 14 : Tableaux de bord & Rapports Consolidés. docs/TRAINING_VIDEOS.md says how a scene is
 * written and filmed. The last module video: it ends on the story video.
 *
 * Each role's home page, read with the chain the copy holds: the Directeur Général (Timothée Massomba on
 * screen) on the Tableau de Bord Exécutif, Vue Ventes and Rapports Consolidés; the Contrôleur Financier (Luc
 * Nana) on Vue Finance; the Responsable des Opérations (Bertin Chadeu) on Vue Opérations; the Comptable (Daniel
 * Massomba) on his Vue Finance. Every figure is said with where it comes from. Narrated by role (runbook §2).
 * Nothing is written: no colleague's pay is opened (the payroll window of Rapports Consolidés stays shut).
 *
 * Ground: snapshot pre-14-film = after-08-facturation + migrations up to 159 + PROMETAL's collection (130
 * empties: 200 out, 70 with the client, 30 to recover at 80 %) and the sale to a recycler (REC-202611-001,
 * 20 000 F into the house cash box), recorded by rehearsing gestion-des-vides on copy C; OPUR 20L's Seuil Min.
 * back to 50 (a signed devis had raised it to 770: the bug migration 155 fixed). The copy is then snapshot
 * after-14-tableaux-de-bord, where the story video takes its last glimpse.
 *
 * The figures (copy C): revenue 465 900 F (455 000 invoice − 9 100 avoir + 20 000 recycling); receivables
 * 0 F (PROMETAL settled); cash 1 160 090 F (CCA Bank 436 090, Caisse 20 000, MTN 704 000); empties 65 %,
 * 81 % of the minimum, 30 to recover; payables 227 500 F (HOREB SARL); gross margin 13 % (water 9,1 %).
 * Filming may run past midnight: no line says a figure that depends on « today ».
 */
const h = require("../helpers");

const PAGE = {
  md: "/modules/dashboard/views/md_dashboard.php",
  fin: "/modules/dashboard/views/finance_dashboard.php",
  ops: "/modules/dashboard/views/ops_dashboard.php",
  sales: "/modules/dashboard/views/sales_dashboard.php",
  rep: "/modules/analytics/reports.php",
};

const card = (s, grid, id) => s.page.locator(`#${grid} [data-kpi-id="${id}"]`);
const win = (s) => s.page.locator("dialog[open]").last();
/** Open a card's detail window and let it load. */
const open = async (s, grid, id) => { await s.click(card(s, grid, id)); await s.wait(1.6); };
/** Close the detail window with its own button. */
const shut = async (s) => { await s.click(win(s).getByRole("button", { name: "Fermer" }).last()); await s.wait(0.6); };
const period = async (s, pill, preset) => { await s.click(s.page.locator(`#${pill} [data-preset="${preset}"]`)); await s.wait(1.8); };

module.exports = {
  id: "tableaux-de-bord",
  file: "LPC-ERP-Tableaux-De-Bord",
  meta: { title: "LPC ERP · Tableaux de bord & Rapports Consolidés (Modules, partie 14)", artist: "JBS Praxis" },
  library: {
    slug: "tableaux-de-bord",
    title: "Tableaux de bord & Rapports Consolidés",
    subtitle: "La page d'accueil de chaque rôle, et d'où vient chaque chiffre",
    series: "Modules",
    part: 14,
    parts: 15,
    pages: [PAGE.md, PAGE.fin, PAGE.ops, PAGE.sales, PAGE.rep],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 14",
    title: "Tableaux de bord",
    subtitle: "La page d'accueil de chaque rôle, et d'où vient chaque chiffre",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 14",
    title: "Merci",
    subtitle: "À suivre : L'histoire d'une bonbonne",
    left: "bureau.lpc.cm",
  },
  holders: { dg: "Timothée Massomba", controleur: "Luc Nana", operations: "Bertin Chadeu", comptable: "Daniel Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-14-film");
    log("ground: pre-14-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans les tableaux de bord de LPC ERP : la première page que chacun voit en se connectant.",
          "Le Directeur Général, le Contrôleur Financier, le Responsable des Opérations et le Comptable ont chacun la leur.",
          "Chaque chiffre vient d'un écran que vous connaissez déjà, et chaque carte s'ouvre sur son détail.",
        ],
      }],
    },

    {
      name: "Le Tableau de Bord Exécutif",
      shots: [{
        kind: "desktop", as: "timothee", role: "dg", start: PAGE.md,
        lines: [
          { say: "Le Directeur Général arrive sur le Tableau de Bord Exécutif, dans Stratégie & BI.",
            do: (s) => h.soft(s, h.shell.menu(s, "Tableau de Bord Exécutif")) },
          { say: "En haut, la période : depuis le début de l'année, ici. Cinq cartes disent comment se porte LPC.",
            do: (s) => h.tour(s, [s.page.locator("#md-period-pill [data-preset=\"ytd\"]"), card(s, "md-kpi-grid", "md-revenue")], 0.5) },
          { say: "Le chiffre d'affaires : 465 900 FCFA. La facture de PROMETAL, moins son avoir, plus les vides vendus au recycleur.",
            speech: "Le chiffre d'affaires : quatre cent soixante-cinq mille neuf cents francs. La facture de PROMETAL, moins son avoir, plus les vides vendus au recycleur.",
            do: (s) => h.soft(s, card(s, "md-kpi-grid", "md-revenue")) },
          { say: "Les créances : ce que les clients doivent encore. Zéro : PROMETAL a tout réglé, net de son AIR.",
            do: (s) => h.soft(s, card(s, "md-kpi-grid", "md-ar")) },
          { say: "La marge nette est négative pour l'instant : deux mois de salaires pour une seule vente depuis le démarrage.",
            do: (s) => h.soft(s, card(s, "md-kpi-grid", "md-margin")) },
          { say: "La récupération des vides : 65 % des bouteilles sont revenues. PROMETAL s'est engagé à 80 % : il en reste 30 à récupérer.",
            speech: "La récupération des vides : soixante-cinq pour cent des bouteilles sont revenues. PROMETAL s'est engagé à quatre-vingts pour cent : il en reste trente à récupérer.",
            do: (s) => h.soft(s, card(s, "md-kpi-grid", "md-empties")) },
          { say: "Un clic ouvre le détail, client par client : livrées, rendues, le minimum convenu et ce qui reste dû.",
            do: async (s) => { await open(s, "md-kpi-grid", "md-empties"); await h.soft(s, win(s).getByText("PROMETAL").first()); } },
          { say: "Le cash disponible : 1,2 million de FCFA. La banque, la caisse et le Mobile Money, comme dans Trésorerie & Banque.",
            speech: "Le cash disponible : un virgule deux million de francs. La banque, la caisse et le Mobile Money, comme dans Trésorerie et Banque.",
            do: async (s) => { await shut(s); await open(s, "md-kpi-grid", "md-cash"); await h.soft(s, win(s).getByText("MTN MOBILE MONEY").first()); } },
          { say: "« Aujourd'hui » compte les ventes du jour, à l'heure du Cameroun ; « Personnalisé » choisit deux dates.",
            do: async (s) => { await shut(s); await h.tour(s, [s.page.locator("#md-period-pill [data-preset=\"today\"]"), s.page.locator("#md-period-pill [data-preset=\"custom\"]")], 0.6); } },
          { say: "« Ce mois » change la période ; les fenêtres de détail suivent la période choisie.",
            do: (s) => period(s, "md-period-pill", "month") },
          { say: "Dessous, les ventes de chaque mois face au budget, et l'âge de ce que les clients doivent : rien, ici.",
            do: async (s) => { await s.scroll(500); await h.tour(s, [s.page.locator("#budgetChart"), s.page.locator("#agingChart")], 0.6); await s.scroll(-500); } },
          "Une carte qui ne peut pas être calculée affiche « Indisponible » : jamais un chiffre inventé.",
        ],
      }],
    },

    {
      name: "Vue Ventes",
      shots: [{
        kind: "desktop", as: "timothee", role: "dg", start: PAGE.sales,
        lines: [
          { say: "Vue Ventes suit les ventes du mois. Le Directeur Général y voit toutes les ventes, quel que soit qui les a saisies.",
            do: (s) => h.soft(s, s.page.locator("#sd-scope")) },
          { say: "Novembre : 455 000 FCFA de commandes, 200 bonbonnes de 20 litres.",
            speech: "Novembre : quatre cent cinquante-cinq mille francs de commandes, deux cents bonbonnes de vingt litres.",
            do: (s) => h.tour(s, [card(s, "sd-kpi-grid", "sd-revenue"), card(s, "sd-kpi-grid", "sd-vol-20l")], 0.6) },
          { say: "La carte ouvre chaque commande : celle de PROMETAL, livrée.",
            do: async (s) => { await open(s, "sd-kpi-grid", "sd-revenue"); await h.soft(s, win(s).getByText("CMD-2611-00001").first()); } },
          { say: "À côté : les commandes à expédier et les factures échues. Rien n'attend aujourd'hui.",
            do: async (s) => { await shut(s); await h.tour(s, [card(s, "sd-kpi-grid", "sd-dispatch"), card(s, "sd-kpi-grid", "sd-overdue")], 0.5); } },
          "Aucun objectif n'est encore saisi : la bannière le dit, et les cartes montrent le réalisé sans pourcentage.",
          { say: "En bas, les dix meilleurs clients du mois, et les clients dormants : sans commande depuis trente jours.",
            do: async (s) => { await s.scroll(900); await h.tour(s, [s.page.locator("#sd-top-clients"), s.page.locator("#sd-dormant")], 0.6); await s.scroll(-900); } },
          "Les commerciaux auront cette page pour accueil ; chacun y verra ses propres ventes.",
        ],
      }],
    },

    {
      name: "Rapports Consolidés",
      shots: [{
        kind: "desktop", as: "timothee", role: "dg", start: PAGE.rep,
        lines: [
          { say: "Rapports Consolidés : huit indicateurs pour la Direction et Finance, sur l'année ou sur le mois.",
            do: (s) => h.soft(s, s.page.locator("#timeframe_filter")) },
          { say: "Le chiffre d'affaires et la masse salariale, rapportée au chiffre d'affaires, se comparent au mois précédent.",
            do: (s) => h.tour(s, [card(s, "ar-kpi-grid", "ar-revenue"), card(s, "ar-kpi-grid", "ar-payroll-ratio")], 0.6) },
          { say: "Le coût par livraison et le ROI de la flotte viennent du carburant et de l'entretien saisis dans Flotte.",
            do: (s) => h.tour(s, [card(s, "ar-kpi-grid", "ar-delivery-cost"), card(s, "ar-kpi-grid", "ar-fleet-roi")], 0.6) },
          { say: "La marge brute : 13 %. Sur l'eau, 9,1 % : ce que LPC gagne entre l'achat et la vente.",
            speech: "La marge brute : treize pour cent. Sur l'eau, neuf virgule un pour cent : ce que LPC gagne entre l'achat et la vente.",
            do: (s) => h.soft(s, card(s, "ar-kpi-grid", "ar-gross-margin")) },
          { say: "Le risque emballages : les 30 vides que PROMETAL doit encore, au prix du vide. 6 000 FCFA.",
            speech: "Le risque emballages : les trente vides que PROMETAL doit encore, au prix du vide. Six mille francs.",
            do: async (s) => { await open(s, "ar-kpi-grid", "ar-empties-risk"); await h.soft(s, win(s).getByText("PROMETAL").first()); } },
          { say: "La balance âgée au-delà de 60 jours, et l'écart au budget : aucun objectif de charges n'est encore saisi.",
            do: async (s) => { await shut(s); await h.tour(s, [card(s, "ar-kpi-grid", "ar-ar-over60"), card(s, "ar-kpi-grid", "ar-budget-exec")], 0.6); } },
          { say: "La répartition de la trésorerie : la caisse, la banque et le Mobile Money.",
            do: async (s) => { await s.scroll(450); await h.tour(s, [s.page.locator("#liquidityChart"), s.page.locator("#total_liquidity")], 0.6); } },
          { say: "Et les charges qui pèsent le plus, face à leur objectif quand il existe.",
            do: async (s) => { await s.scroll(450); await h.soft(s, s.page.locator("#tbody-budget")); await s.scroll(-900); } },
          "« Exporter PDF » enregistre cette page en une feuille, pour un conseil ou un banquier.",
        ],
      }],
    },

    {
      name: "Vue Finance",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.fin,
        lines: [
          { say: "Le Contrôleur Financier ouvre Vue Finance. Elle s'ouvre sur le mois en cours, le rythme de la clôture.",
            do: (s) => h.soft(s, s.page.locator("#fin-period-pill [data-preset=\"month\"]")) },
          { say: "La trésorerie : 1 160 090 FCFA en fin de période, comparée à la fin du mois précédent.",
            speech: "La trésorerie : un million cent soixante mille quatre-vingt-dix francs en fin de période, comparée à la fin du mois précédent.",
            do: (s) => h.soft(s, card(s, "fin-kpi-grid", "fin-cash")) },
          { say: "Les dettes fournisseurs : 227 500 FCFA, compte par compte. C'est la commande de HOREB SARL, pas encore payée.",
            speech: "Les dettes fournisseurs : deux cent vingt-sept mille cinq cents francs, compte par compte. C'est la commande de HOREB SARL, pas encore payée.",
            do: async (s) => { await open(s, "fin-kpi-grid", "fin-ap"); await h.soft(s, win(s).getByText("HOREB").first()); } },
          { say: "« Exporter CSV » enregistre la fenêtre pour un tableur ; « Ouvrir les achats » mène à la commande.",
            do: (s) => h.tour(s, [win(s).getByRole("button", { name: /Exporter CSV/i }).first(), win(s).getByText("Ouvrir les achats").first()], 0.6) },
          { say: "Les créances : ce que les factures ouvertes doivent encore. La marge nette suit la période choisie.",
            do: async (s) => { await shut(s); await h.tour(s, [card(s, "fin-kpi-grid", "fin-ar"), card(s, "fin-kpi-grid", "fin-margin")], 0.6); } },
          { say: "Les validations en attente : les écritures en brouillon, les dépenses à payer, les corrections demandées. Rien aujourd'hui.",
            do: (s) => h.soft(s, card(s, "fin-kpi-grid", "fin-pending")) },
          { say: "Le flux de trésorerie montre, jour par jour, l'argent entré et sorti.",
            do: async (s) => { await s.scroll(450); await h.soft(s, s.page.locator("#cashflowChart")); } },
          { say: "Et en bas, chaque compte de trésorerie avec son sous-compte et son solde : les mêmes chiffres que Trésorerie & Banque.",
            do: async (s) => { await s.scroll(500); await h.soft(s, s.page.locator("#treasury-accounts-body")); } },
        ],
      }],
    },

    {
      name: "Vue Opérations",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.ops,
        lines: [
          { say: "Le Responsable des Opérations arrive sur Vue Opérations, l'écran du dispatch.",
            do: (s) => h.soft(s, s.page.locator("#ops-kpi-grid")) },
          { say: "Les BL en attente : les bons en brouillon ou en transit. Aucun pour l'instant.",
            do: (s) => h.soft(s, card(s, "ops-kpi-grid", "ops-pending-bl")) },
          { say: "Les alertes stock : chaque produit à son Seuil Min. ou en dessous, et ceux en rupture.",
            do: async (s) => { await open(s, "ops-kpi-grid", "ops-low-stock"); await h.soft(s, win(s).locator("tbody tr").first()); } },
          "Le 10 litres n'est pas encore en stock ; les vides de 20 litres sont sous leur seuil, en attendant la prochaine collecte.",
          { say: "La récupération des vides : la même que celle du Directeur Général, et l'état de la flotte.",
            do: async (s) => { await shut(s); await h.tour(s, [card(s, "ops-kpi-grid", "ops-empties"), card(s, "ops-kpi-grid", "ops-fleet")], 0.6); } },
          { say: "La flotte s'ouvre sur chaque véhicule, son dernier chauffeur et sa dernière livraison.",
            do: async (s) => { await open(s, "ops-kpi-grid", "ops-fleet"); await h.soft(s, win(s).getByText("LT 742 KL").first()); } },
          { say: "Sur sept jours, le graphique montre les BL clôturés, et le stock se lit par famille.",
            do: async (s) => { await shut(s); await period(s, "ops-period-pill", "7d"); await s.scroll(450); await h.tour(s, [s.page.locator("#deliveriesChart"), s.page.locator("#inventoryChart")], 0.6); } },
          { say: "En bas, la file du dispatch : le BL de PROMETAL, signé par le client, avec son chauffeur.",
            do: async (s) => { await s.scroll(500); await h.soft(s, s.page.locator("#dispatch-table-body tr").first()); } },
          { say: "« Créer un BL » ouvre Ventes, pour préparer la prochaine livraison.",
            do: (s) => h.soft(s, s.page.getByText("Créer un BL").first()) },
        ],
      }],
    },

    {
      name: "Le Comptable",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.fin,
        lines: [
          { say: "Le Comptable arrive lui aussi sur Vue Finance : les mêmes chiffres que le Contrôleur Financier.",
            do: (s) => h.soft(s, s.page.locator("#fin-kpi-grid")) },
          { say: "Pour lui, « Validations en attente » est la liste de ce qu'il doit traiter.",
            do: (s) => h.soft(s, card(s, "fin-kpi-grid", "fin-pending")) },
          { say: "Les créances s'ouvrent sur chaque facture et ce qu'elle doit encore, pour relancer le bon client.",
            do: async (s) => { await open(s, "fin-kpi-grid", "fin-ar"); await s.wait(0.8); await shut(s); } },
          { say: "Les dettes fournisseurs lui disent ce qui reste à payer, fournisseur par fournisseur.",
            do: (s) => h.soft(s, card(s, "fin-kpi-grid", "fin-ap")) },
          { say: "Il ouvre aussi Vue Ventes et Rapports Consolidés, dans Stratégie & BI.",
            do: (s) => h.tour(s, [h.shell.menu(s, "Vue Ventes"), h.shell.menu(s, "Rapports Consolidés")], 0.5) },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : chaque rôle arrive sur sa page, et chaque carte vient d'un écran qu'il connaît.",
          "Le cash vient de la Trésorerie, les créances de la Facturation, les vides de la Gestion des Vides, le stock de son Seuil Min.",
          "C'était la dernière vidéo des modules. La suivante, « L'histoire d'une bonbonne », relie toute la chaîne de LPC en huit minutes.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
