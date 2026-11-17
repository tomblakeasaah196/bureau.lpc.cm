"use strict";
/**
 * Introduction, partie 2 : Chacun son espace. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * What each of LPC's people finds when they sign in: their own home page and a menu cut to their role.
 * Timothée Massomba, the Directeur Général, on the Tableau de Bord Exécutif and, from his menu, the Vue
 * Ventes a salesperson will land on (LPC has no Sales login yet, 18/11/2026); Luc Nana, the Contrôleur
 * Financier, on the Vue Finance and how his menu differs from Daniel's; Bertin Chadeu, Opérations, on
 * the Vue Opérations and a stock alert; JBS Praxis Support, the IT partner, on Paramètres (the five
 * accounts, the roles and their home pages, the company) and the training videos' own page.
 * LPC's real people and data on the filming copy. About seven minutes.
 *
 * Nothing on screen is changed: every window opened is closed, no setting is saved, no mailbox opened.
 */
const h = require("../helpers");

const PAGE = {
  exec: "/modules/dashboard/views/md_dashboard.php",
  sales: "/modules/dashboard/views/sales_dashboard.php",
  finance: "/modules/dashboard/views/finance_dashboard.php",
  ops: "/modules/dashboard/views/ops_dashboard.php",
  users: "/modules/settings/index.php?tab=users",
  videos: "/modules/admin/help_videos.php",
};

/** A sidebar section header (<summary>), by name. */
const section = (s, name) => s.page.locator("#lpc-sidebar summary").filter({ hasText: new RegExp(`^\\s*${name}\\s*$`, "i") }).first();
/** The open window that holds `text`. */
const windowWith = (s, text) => s.page.locator(".lpc-modal, [role=dialog], .fixed").filter({ hasText: text }).last();
/** A KPI card by the start of its name. */
const card = (s, start) => s.page.getByRole("button", { name: new RegExp(`^${start}`) });

module.exports = {
  id: "intro-2-chacun-son-espace",
  file: "LPC-ERP-Introduction-2-Chacun-Son-Espace",
  meta: { title: "LPC ERP · Chacun son espace (Introduction, partie 2)", artist: "JBS Praxis" },
  library: {
    slug: "intro-2-chacun-son-espace",
    title: "Chacun son espace",
    subtitle: "Ce que voient la direction, la finance, les opérations et le support",
    series: "Introduction",
    part: 2,
    parts: 2,
    audience: "all",
  },
  opening: {
    eyebrow: "Formation LPC ERP · Introduction, partie 2",
    title: "Chacun son espace",
    subtitle: "Ce que voient la direction, la finance, les opérations et le support",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Introduction, partie 2",
    title: "Merci",
    subtitle: "À suivre : le travail de tous les jours, module par module",
    left: "bureau.lpc.cm",
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la deuxième partie de l'introduction à LPC ERP.",
          "Chaque personne arrive sur sa propre page d'accueil, et voit un menu taillé pour son rôle.",
          "Nous passons voir le directeur général, la finance, les opérations, puis le support technique.",
          "Ce sont les vraies personnes de La Petite Cour, et leurs vrais écrans.",
        ],
      }],
    },

    {
      name: "La direction",
      shots: [{
        kind: "desktop", as: "timothee", start: PAGE.exec,
        lines: [
          { say: "Timothée Massomba, le directeur général, arrive sur le Tableau de Bord Exécutif.",
            do: (s) => h.soft(s, s.page.locator("header h1, [role=banner] h1").first()) },
          { say: "Cinq indicateurs résument l'entreprise : le chiffre d'affaires, les créances clients, la marge nette, la récupération d'emballages et le cash disponible.",
            do: (s) => h.tour(s, ["Chiffre d'affaires", "Créances clients", "Marge nette", "Récupération d'emballages", "Cash disponible"].map((n) => card(s, n)), 0.4) },
          "Aujourd'hui, ils sont à zéro : aucune vente n'est encore enregistrée. Ils se rempliront avec les premières factures.",
          { say: "La récupération d'emballages vise au moins quatre-vingts pour cent : chaque bonbonne livrée doit revenir.",
            do: (s) => h.soft(s, card(s, "Récupération d'emballages")) },
          { say: "Plus bas, la performance face au budget, et l'âge des créances.",
            do: (s) => s.scroll(420) },
          { say: "Son menu est complet : il voit les cinq rubriques et toutes leurs pages, jusqu'aux paramètres.",
            do: async (s) => { await s.scroll(-420); await h.tour(s, ["STRATÉGIE & BI", "COMMERCE & CRM", "LOGISTIQUE & STOCK", "COMPTABILITÉ & FINANCE", "ADMINISTRATION & RH"].map((n) => section(s, n)), 0.3); } },
          { say: "Le chiffre à côté de Courrier compte ses messages non lus. Sa messagerie reste privée : nous ne l'ouvrons pas.",
            do: (s) => h.soft(s, h.shell.menu(s, /^Courrier/)) },
        ],
      }],
    },

    {
      name: "Les ventes",
      shots: [{
        kind: "desktop", as: "timothee", start: PAGE.exec,
        lines: [
          "La Petite Cour n'a pas encore de compte commercial. Quand il sera créé, son titulaire arrivera sur la Vue Ventes.",
          { say: "Timothée peut l'ouvrir depuis son menu, dans Stratégie & BI.",
            do: (s) => s.click(h.shell.menu(s, "Vue Ventes")) },
          { say: "Le commercial y suit son chiffre d'affaires du mois, et ses volumes en bonbonnes de vingt et de dix litres.",
            do: (s) => h.tour(s, ["Mon CA du mois", "Volume 20L", "Volume 10L"].map((n) => card(s, n)), 0.5) },
          { say: "Puis les commandes en attente de livraison, et l'encours de ses clients en retard de paiement.",
            do: (s) => h.tour(s, ["En attente de dispatch", "Encours en retard"].map((n) => card(s, n)), 0.5) },
          { say: "Aucun objectif n'est encore défini pour ce mois. « Définir les objectifs » mène aux budgets, où la direction les fixe.",
            do: (s) => h.soft(s, h.link(s, "Définir les objectifs")) },
          { say: "Plus bas, le top dix des clients du mois, et les clients dormants à relancer.",
            do: (s) => s.scroll(560) },
        ],
      }],
    },

    {
      name: "La finance",
      shots: [{
        kind: "desktop", as: "luc", start: PAGE.finance,
        lines: [
          { say: "Luc Nana, le contrôleur financier, arrive sur la Vue Finance, comme Daniel, le comptable.",
            do: (s) => h.soft(s, h.shell.user(s)) },
          { say: "Ses indicateurs : la trésorerie, les créances, les dettes fournisseurs, les validations en attente et la marge.",
            do: (s) => h.tour(s, ["Trésorerie actuelle", "Créances clients", "Dettes fournisseurs", "Validations en attente", "Marge nette"].map((n) => card(s, n)), 0.35) },
          { say: "Leurs menus diffèrent pourtant. Luc ouvre Comptabilité & Finance : toute la comptabilité, jusqu'au bilan d'ouverture.",
            do: async (s) => { await s.click(section(s, "COMPTABILITÉ & FINANCE")); await h.soft(s, h.shell.menu(s, "Bilan d'Ouverture")); } },
          { say: "Dans Administration & RH, il trouve la gestion du personnel, avec la paie.",
            do: async (s) => { await s.click(section(s, "ADMINISTRATION & RH")); await h.soft(s, h.shell.menu(s, "Gestion du Personnel")); } },
          { say: "Et « Centre d'Aide (articles) » : il peut écrire et corriger les guides de l'aide, pour toute l'équipe.",
            do: (s) => h.soft(s, h.shell.menu(s, "Centre d'Aide (articles)")) },
          "Ce que chacun voit se règle par rôle, dans les paramètres. Nous y venons avec le support.",
        ],
      }],
    },

    {
      name: "Les opérations",
      shots: [{
        kind: "desktop", as: "bertin", start: PAGE.ops,
        lines: [
          { say: "Bertin Chadeu, aux opérations, arrive sur la Vue Opérations.",
            do: (s) => h.soft(s, s.page.locator("header h1, [role=banner] h1").first()) },
          { say: "Ses indicateurs : les bons de livraison à expédier, les alertes de stock, le retour d'emballages, la flotte et les livraisons du jour.",
            do: (s) => h.tour(s, ["Livraisons BL en attente", "Alertes stock", "Retour d'emballages", "État de la flotte", "Livraisons complétées"].map((n) => card(s, n)), 0.35) },
          { say: "Cinq articles sont sous leur seuil. Bertin ouvre l'alerte.",
            do: (s) => s.click(card(s, "Alertes stock")) },
          { say: "La liste dit lesquels : les bonbonnes vides de vingt litres, les emballages et l'OPUR de dix litres, et le distributeur d'eau.",
            do: async (s) => { await s.wait(1); await h.soft(s, windowWith(s, "seuil critique").getByText("OPUR 10L").first()); } },
          { say: "« Ouvrir le stock » mène à la page du stock. Il referme la liste.",
            do: async (s) => { await h.soft(s, windowWith(s, "seuil critique").getByRole("link", { name: "Ouvrir le stock" }).or(windowWith(s, "seuil critique").getByRole("button", { name: "Ouvrir le stock" })).first()); await s.click(windowWith(s, "seuil critique").getByRole("button", { name: "Fermer" })); } },
          { say: "Plus bas, la file d'attente des livraisons, et « Créer un BL » pour en préparer une.",
            do: async (s) => { await s.scroll(520); await h.soft(s, h.link(s, "+ Créer un BL")); } },
          { say: "Son menu suit son métier : les clients et les devis, les ventes, les achats, le stock, les emballages et les dépenses.",
            do: async (s) => { await s.scroll(-520); await s.click(section(s, "LOGISTIQUE & STOCK")); await h.tour(s, ["Gestion des Achats", "Stock & Emballages", "Gestion des Vides"].map((n) => h.shell.menu(s, n)), 0.35); } },
        ],
      }],
    },

    {
      name: "Le support technique",
      shots: [
        {
          kind: "desktop", as: "support", start: PAGE.users,
          lines: [
            "JBS Praxis, le partenaire informatique de La Petite Cour, tient les comptes et les réglages.",
            { say: "Dans Paramètres, Utilisateurs liste les cinq comptes : leur adresse, leur rôle, et s'ils sont actifs.",
              do: (s) => h.soft(s, s.page.locator("main table").first()) },
            { say: "« Provisionner un compte » crée l'accès d'un nouveau collègue, avec un premier mot de passe.",
              do: (s) => h.soft(s, h.button(s, "+ Provisionner un compte")) },
            { say: "C'est aussi d'ici qu'on redonne un mot de passe, ou qu'on verrouille un compte.",
              do: (s) => h.soft(s, s.page.getByRole("button", { name: "Verrouiller le compte" }).first()) },
            { say: "Rôles & Permissions montre chaque rôle, combien de personnes le portent, et ce qu'il permet.",
              do: (s) => s.click(h.shell.menu(s, "Rôles & Permissions")) },
            { say: "Le rôle Comptable, celui de Daniel, a sa page d'accueil, la Vue Finance, et ses permissions, module par module.",
              do: async (s) => { await s.click(s.page.getByRole("button", { name: /^Comptable/ })); await s.wait(0.8); await h.soft(s, s.page.getByRole("combobox", { name: /Page d'accueil/ })); } },
            "Changer une case change ce que voient toutes les personnes du rôle. Ce sont des réglages à faire avec la direction.",
            { say: "Identité de l'Entreprise tient le nom, le logo et les coordonnées de La Petite Cour.",
              do: (s) => s.click(h.shell.menu(s, "Identité de l'Entreprise")) },
          ],
        },
        {
          kind: "desktop", as: "support", start: PAGE.videos,
          lines: [
            "Enfin, c'est le support qui publie les vidéos de formation, comme celle-ci.",
            { say: "Il dépose la vidéo et ses fichiers, et l'envoie. Chacun la voit dans le Centre d'aide, selon les pages de son rôle.",
              do: (s) => h.soft(s, s.page.getByRole("region", { name: "Ajouter ou remplacer une vidéo" })) },
            { say: "La première partie, Premiers pas, y est publiée pour tout le monde.",
              do: (s) => h.soft(s, s.page.locator("main table tbody tr").first()) },
          ],
        },
      ],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "Voilà chacun dans son espace : la direction voit tout, la finance ses comptes, les opérations le terrain, le support les réglages.",
          "Votre page d'accueil et votre menu suivent votre rôle. Si une page vous manque, parlez-en à la direction.",
          "Les prochaines vidéos suivent le travail de tous les jours, module par module.",
          "Toutes les vidéos et tous les guides sont dans le Centre d'aide. Merci de votre attention.",
        ],
      }],
    },
  ],
};
