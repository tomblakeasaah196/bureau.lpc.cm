"use strict";
/**
 * Modules, partie 1 : Données de Base. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * The lists the whole ERP reads: products, client and supplier tariffs, employees, suppliers, vehicles.
 * The Contrôleur Financier owns them with the Directeur Général (Luc Nana signs in on screen); the
 * Comptable and the Responsable des Opérations consult them (Daniel Massomba on screen). Narrated by
 * role (runbook §2); the holder is named once, on the bar.
 *
 * LPC's real data on the filming copy (snapshot pre-01-film: the live export of 17/11 and every
 * migration up to 130): OPUR 20L and 10L, their empties, SMART LOGISTICS' tariff, Source du Pays at
 * 1 850 and HOREB SARL at 2 275 for OPUR 20L. Forms are opened and closed without saving, except
 * HOREB SARL saved unchanged (c06) to show its 401 account in the confirmation. The employee shown is
 * JBS Praxis Support's record (no personal data, no salary): a colleague's pay never goes on camera.
 */
const h = require("../helpers");

const PAGE = "/modules/admin/master_data.php";

/** A tab of the page, by its name. */
const tab = (s, name) => s.page.locator("#mdm-tabs button").filter({ hasText: new RegExp(`^\\s*${name}\\s*$`, "i") }).first();
/** A row of the table, by text it holds. */
const row = (s, text) => s.page.locator("#table-body tr").filter({ hasText: text }).first();
/** A row's action button, by its title. */
const act = (s, text, title) => row(s, text).locator(`button[title^="${title}"]`).first();
/** The open record window and its parts. */
const modal = (s) => s.page.locator("#mdmModal");
const modalBody = (s) => s.page.locator("#mdmModal .overflow-y-auto").first();
const cancel = (s) => modal(s).getByRole("button", { name: /annuler/i });
const label = (s, text) => modal(s).locator("label").filter({ hasText: new RegExp(`^\\s*${text}`, "i") }).first();
/** The price history window's « Fermer » (the one with the word, not the cross). */
const closeHistory = (s) => s.page.getByRole("button", { name: "Fermer" }).filter({ hasText: "Fermer" }).last();
/** A step of the price ladder panel. */
const step = (s, title) => s.page.locator("#mdm-panel span").filter({ hasText: new RegExp(`^${title}$`) }).first();

module.exports = {
  id: "donnees-de-base",
  file: "LPC-ERP-Donnees-De-Base",
  meta: { title: "LPC ERP · Données de Base (Modules, partie 1)", artist: "JBS Praxis" },
  library: {
    slug: "donnees-de-base",
    title: "Données de Base",
    subtitle: "Produits, tarifs, employés et fournisseurs : les listes que tout l'ERP utilise",
    series: "Modules",
    part: 1,
    parts: 15,
    pages: [PAGE],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 1",
    title: "Données de Base",
    subtitle: "Produits, tarifs, employés et fournisseurs : les listes que tout l'ERP utilise",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 1",
    title: "Merci",
    subtitle: "À suivre : Gestion des Achats",
    left: "bureau.lpc.cm",
  },
  holders: { controleur: "Luc Nana", comptable: "Daniel Massomba" },

  /** The copy as live had it on 17/11, every migration applied, nothing of the hand QA left. */
  async prepare({ log, ground }) {
    ground.restore("pre-01-film");
    log("ground: pre-01-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la première vidéo des modules de LPC ERP : les Données de Base.",
          "Ce sont les listes que tout l'ERP utilise : les produits, les prix, les employés et les fournisseurs.",
          "Les devis, les commandes, les achats et la paie les lisent. Une correction faite ici se voit partout.",
          "Nous suivons le Contrôleur Financier, qui tient ces listes avec le Directeur Général.",
        ],
      }],
    },

    {
      name: "Qui fait quoi",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "Le Contrôleur Financier ouvre Données de Base, dans Administration & RH.",
            do: (s) => h.soft(s, h.shell.menu(s, "Données de Base")) },
          { say: "La page a six onglets : Produits, Tarifs Clients, Tarifs Fournisseurs, Employés & RH, Fournisseurs et Flotte.",
            do: (s) => h.tour(s, ["Produits", "Tarifs Clients", "Tarifs Fournisseurs", "Employés & RH", "Fournisseurs", "Flotte"].map((n) => tab(s, n)), 0.35) },
          "Seuls le Contrôleur Financier et le Directeur Général peuvent modifier ces listes.",
          "Le Comptable et le Responsable des Opérations les consultent : nous verrons leur écran à la fin.",
          { say: "En haut à droite, le bouton d'ajout change avec l'onglet. Ici, « Nouveau Produit ».",
            do: (s) => h.soft(s, s.page.locator("#btn-add")) },
        ],
      }],
    },

    {
      name: "Les produits",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "L'onglet Produits est le catalogue. LPC vend l'eau OPUR en bonbonnes de 20 et de 10 litres, et des distributeurs.",
            speech: "L'onglet Produits est le catalogue. LPC vend l'eau OPUR en bonbonnes de vingt et de dix litres, et des distributeurs.",
            do: (s) => h.tour(s, [row(s, "OPUR 20L"), row(s, "OPUR 10L"), row(s, "Distributeur")], 0.4) },
          { say: "Chaque produit a son code, sa catégorie, son prix de base et son compte de vente.",
            do: (s) => h.soft(s, s.page.locator("#table-head th").filter({ hasText: "Compte Vente" }).first()) },
          { say: "Les emballages vides sont des produits eux aussi. Le vide de 20 litres se range en une famille : avec bouchon, et sans bouchon.",
            speech: "Les emballages vides sont des produits eux aussi. Le vide de vingt litres se range en une famille : avec bouchon, et sans bouchon.",
            do: (s) => h.soft(s, row(s, "Famille 20L")) },
          "Leur prix est celui auquel LPC revend les vides à un recycleur.",
          { say: "Le Contrôleur Financier ouvre la fiche de l'OPUR 20L, avec le crayon.",
            speech: "Le Contrôleur Financier ouvre la fiche de l'OPUR vingt litres, avec le crayon.",
            do: (s) => s.click(act(s, "OPUR 20L", "Modifier")) },
          { say: "La catégorie Eau donne le début du code, et le compte de vente.",
            do: (s) => h.soft(s, label(s, "Catégorie")) },
          { say: "Plus bas, l'emballage vide lié : la bonbonne de 20 litres sans bouchon.",
            speech: "Plus bas, l'emballage vide lié : la bonbonne de vingt litres sans bouchon.",
            do: async (s) => { await s.scroll(380, modalBody(s)); await h.soft(s, s.page.locator("#f_linked_empty_id")); } },
          "À chaque livraison, le client doit autant de vides que de bonbonnes livrées. C'est ce que suit la Gestion des Vides.",
          { say: "Puis les prix. Le prix de base, 2 275 FCFA, vaut pour tout client sans tarif négocié.",
            speech: "Puis les prix. Le prix de base, deux mille deux cent soixante-quinze francs, vaut pour tout client sans tarif négocié.",
            do: async (s) => { await s.scroll(420, modalBody(s)); await h.soft(s, s.page.locator("#f_base_price")); } },
          { say: "Le coût de référence est ce que LPC paie ce produit quand le fournisseur n'a pas de tarif.",
            do: (s) => h.soft(s, s.page.locator("#f_cost_price")) },
          { say: "Enfin, le compte de vente : 7011, Ventes Eau. Chaque facture d'OPUR crédite ce compte.",
            speech: "Enfin, le compte de vente : sept mille onze, Ventes Eau. Chaque facture d'OPUR crédite ce compte.",
            do: (s) => h.soft(s, s.page.locator("#revenue-preview")) },
          { say: "Rien à changer aujourd'hui : il ferme la fiche avec « Annuler ».",
            do: (s) => s.click(cancel(s)) },
        ],
      }],
    },

    {
      name: "Les tarifs clients",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "L'onglet Tarifs Clients garde les prix négociés avec un client.",
            do: (s) => s.click(tab(s, "Tarifs Clients")) },
          { say: "Le panneau du haut dit comment un prix est choisi, dans cet ordre.",
            do: (s) => h.soft(s, s.page.locator("#mdm-panel h3").first()) },
          { say: "D'abord le tarif négocié avec ce client, s'il en a un.",
            do: (s) => h.soft(s, step(s, "Tarif négocié")) },
          { say: "Sinon, le prix par défaut du produit.",
            do: (s) => h.soft(s, step(s, "Prix par défaut")) },
          { say: "SMART LOGISTICS AND SERVICES paie l'OPUR 20L 2 500 FCFA, au lieu de 2 275.",
            speech: "Smart Logistics and Services paie l'OPUR vingt litres deux mille cinq cents francs, au lieu de deux mille deux cent soixante-quinze.",
            do: (s) => h.soft(s, row(s, "SMART")) },
          { say: "Le bouton « Prix par défaut » ouvre la liste des prix de base, à corriger sur place.",
            do: (s) => s.click(s.page.locator("#price-panel-toggle")) },
          { say: "Il la referme, puis prépare un nouveau tarif.",
            do: async (s) => { await s.click(s.page.locator("#price-panel-toggle")); await s.click(s.page.locator("#btn-add")); } },
          { say: "Il choisit le client, NOFIA.",
            do: async (s) => { await s.click(s.page.getByPlaceholder("Rechercher un client…")); await s.page.keyboard.type("NOFIA", { delay: 70 }); await s.wait(0.7); await s.key("Enter"); } },
          { say: "Puis le produit, l'OPUR 20L. Le prix par défaut se met dans le champ.",
            speech: "Puis le produit, l'OPUR vingt litres. Le prix par défaut se met dans le champ.",
            do: async (s) => { await s.click(s.page.getByPlaceholder("Rechercher un produit…")); await s.page.keyboard.type("OPUR 20L", { delay: 70 }); await s.wait(0.9); await s.key("Enter"); } },
          { say: "La ligne du dessous compare le prix saisi au prix par défaut, avec l'écart en francs et en pourcentage.",
            do: async (s) => { await s.type(s.page.locator("#f_custom_price"), "2200", { delay: 140 }); await h.soft(s, s.page.locator("#tarif-hint")); } },
          { say: "Ce tarif n'est pas convenu avec NOFIA : il ferme sans enregistrer.",
            do: (s) => s.click(cancel(s)) },
          { say: "Chaque tarif a son historique des prix : qui l'a changé, quand, et d'où vient le changement.",
            do: (s) => h.soft(s, act(s, "SMART", "Historique")) },
          { say: "Un tarif devenu inutile se supprime avec la corbeille : le client repasse au prix par défaut.",
            do: (s) => h.soft(s, act(s, "SMART", "Supprimer")) },
        ],
      }],
    },

    {
      name: "Les tarifs fournisseurs",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "Tarifs Fournisseurs, c'est l'inverse : ce que chaque fournisseur facture à LPC.",
            do: (s) => s.click(tab(s, "Tarifs Fournisseurs")) },
          { say: "Pour l'OPUR 20L, Source du Pays demande 1 850 FCFA, HOREB SARL 2 275 FCFA.",
            speech: "Pour l'OPUR vingt litres, Source du Pays demande mille huit cent cinquante francs, Horeb SARL deux mille deux cent soixante-quinze.",
            do: (s) => h.tour(s, [row(s, "SOURCE DU PAYS"), row(s, "HOREB")], 0.8) },
          { say: "Sur un bon de commande, le produit prend le tarif du fournisseur choisi, sinon le coût de référence.",
            do: (s) => h.soft(s, step(s, "Coût de référence")) },
          { say: "Le coût moyen du stock sert à valoriser le stock, jamais à fixer le prix d'un achat.",
            do: (s) => h.soft(s, step(s, "Coût moyen \\(CUMP\\)")) },
          { say: "Le Contrôleur Financier ouvre l'historique du tarif de HOREB.", speech: "Le Contrôleur Financier ouvre l'historique du tarif de Horeb.",
            do: (s) => s.click(act(s, "HOREB", "Historique")) },
          "Ce prix a été appris d'un bon de commande, dont la référence est notée. Les achats remplissent ces tarifs tout seuls.",
          { say: "Il ferme l'historique.",
            do: (s) => s.click(closeHistory(s)) },
        ],
      }],
    },

    {
      name: "Les fournisseurs",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE, snapshot: true,
        lines: [
          { say: "L'onglet Fournisseurs liste ceux à qui LPC achète. Le code, FRS-001, est donné par le système.",
            speech: "L'onglet Fournisseurs liste ceux à qui L P C achète. Le code, F R S zéro zéro un, est donné par le système.",
            do: async (s) => { await s.click(tab(s, "Fournisseurs")); await h.soft(s, row(s, "SOURCE DU PAYS")); } },
          { say: "Chaque fournisseur a aussi son propre compte, sous le 401 Fournisseurs. La comptabilité voit ainsi ce que LPC doit à chacun, séparément.",
            speech: "Chaque fournisseur a aussi son propre compte, sous le quatre cent un, Fournisseurs. La comptabilité voit ainsi ce que L P C doit à chacun, séparément." },
          { say: "Pour une nouvelle fiche, « Nouveau Fournisseur » ne demande que la raison sociale. Le téléphone, l'email et l'adresse sont facultatifs.",
            do: (s) => h.soft(s, s.page.locator("#btn-add")) },
          { say: "Ici, il vérifie la fiche de HOREB SARL.", speech: "Ici, il vérifie la fiche de Horeb SARL.",
            do: (s) => s.click(act(s, "HOREB", "Modifier")) },
          { say: "Il l'enregistre telle quelle. La confirmation rappelle son code et son compte fournisseur : 401002.",
            speech: "Il l'enregistre telle quelle. La confirmation rappelle son code et son compte fournisseur : quatre cent un, zéro zéro deux.",
            do: async (s) => { await s.click(s.page.locator("#btn-save")); await h.soft(s, s.page.locator(".lpc-toast, [role=status]").filter({ hasText: "401002" }).first(), 6000); }, hold: 1.5 },
          "Un fournisseur qui n'avait pas encore de compte le reçoit à ce moment. Le renommer renomme aussi son compte.",
          { say: "Le dernier onglet, Flotte, se consulte seulement. Les véhicules se gèrent dans Flotte & Maintenance.",
            do: (s) => s.click(tab(s, "Flotte")) },
        ],
      }],
    },

    {
      name: "Les employés",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "Employés & RH tient la fiche de chaque personne que LPC emploie.",
            do: (s) => s.click(tab(s, "Employés & RH")) },
          { say: "La colonne Rôle Système dit ce que chacun peut faire dans l'ERP. Le poste n'est qu'un libellé.",
            do: (s) => h.soft(s, s.page.locator("#table-head th").filter({ hasText: "Rôle Système" }).first()) },
          { say: "Il ouvre une fiche, celle du support technique, JBS Praxis. Les fiches de chacun restent confidentielles.",
            do: (s) => s.click(act(s, "JBS Praxis", "Modifier")) },
          "Elle tient l'identité, la paie, les documents et l'organisation. Le matricule est donné par le système.",
          "Les salaires sont gardés ici, dans la section Paie : la paie du mois part de ces montants. Une augmentation se saisit donc ici, pas dans Gestion du Personnel.",
          { say: "Le compte de connexion et son rôle se créent dans Paramètres, Utilisateurs, comme le rappelle le bas de la fiche.",
            do: async (s) => { await s.scroll(3000, modalBody(s)); await h.soft(s, modal(s).locator("a").filter({ hasText: "Utilisateurs" }).first()); } },
          { say: "Il ferme la fiche sans rien changer.",
            do: (s) => s.click(cancel(s)) },
          { say: "Le bouton rouge désactive un employé qui quitte LPC. Son compte de connexion est fermé en même temps.",
            do: (s) => h.soft(s, act(s, "Luc", "Désactiver")) },
        ],
      }],
    },

    {
      name: "Ce que voient les autres",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE,
        lines: [
          "Voici maintenant la même page pour le Comptable.",
          { say: "Pas de bouton d'ajout. Sur chaque ligne, un seul bouton : « Consulter ».",
            do: (s) => h.soft(s, act(s, "OPUR 20L", "Consulter")) },
          { say: "Il ouvre la même fiche, avec tous les champs verrouillés et sans « Enregistrer ».",
            do: async (s) => { await s.click(act(s, "OPUR 20L", "Consulter")); await s.scroll(700, modalBody(s)); } },
          { say: "Il la referme.",
            do: (s) => s.click(cancel(s)) },
          { say: "Dans les tarifs, il peut ouvrir l'historique des prix, mais ni les modifier ni les supprimer.",
            do: async (s) => { await s.click(tab(s, "Tarifs Clients")); await h.soft(s, act(s, "SMART", "Historique")); } },
          "Le Responsable des Opérations voit la page de la même façon. Pour une correction, ils s'adressent au Contrôleur Financier.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [
        {
          kind: "desktop", as: "luc", role: "controleur", start: PAGE,
          lines: [
            { say: "Le bouton « Aide », en haut de la page, reprend tout cela par écrit, onglet par onglet.",
              do: (s) => s.click(s.page.getByRole("button", { name: /^Aide/ }).or(s.page.getByRole("link", { name: /^Aide/ })).first()) },
            "En résumé : le Contrôleur Financier et le Directeur Général tiennent les produits, les prix, les fournisseurs et les employés.",
            "Les autres consultent. Et chaque changement de tarif reste dans son historique.",
          ],
        },
        {
          kind: "card", card: "closing",
          lines: [
            "La prochaine vidéo suit ces fournisseurs et ces tarifs dans la Gestion des Achats.",
            "Merci de votre attention.",
          ],
        },
      ],
    },
  ],
};
