"use strict";
/**
 * Modules, partie 3 : Stock & Emballages et Fiche de Stock. docs/TRAINING_VIDEOS.md says how a scene is
 * written and filmed.
 *
 * The Responsable des Opérations runs the stock (Bertin Chadeu on screen): he reads what is on hand,
 * declares three bottles pierced at unloading, counts the warehouse and seals an inventory with two
 * missing, reads the Historique, then the Fiche de Stock in bottles and in francs. The Directeur Général
 * (Timothée Massomba) checks the stock account in the Grand Livre. Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-03-film (after-02-gestion-des-achats + migrations 133 to 135): 260 OPUR 20L at a
 * CUMP of 2 013,46 F, 523 500 F in 311. The scene writes an avarie (3 OPUR 20L, 6 040 F) and an inventory
 * (255 counted, 2 missing, 4 027 F): 255 bottles and 513 433 F are left for Proposition commerciale.
 */
const h = require("../helpers");

const PAGE = {
  stock: "/modules/inventory/stock.php",
  fiche: "/modules/inventory/fiche_stock.php",
  ledger: "/modules/accounting/ledger.php",
};
const OPUR20 = "3";   // products.id of OPUR 20L on the copy

const stockRow = (s, name) => s.page.locator("#tbody-stock tr").filter({ hasText: name }).first();
const kpi = (s, f) => s.page.locator(`.kpi-filter-btn[data-kpi-filter="${f}"]`).first();
const visibleButton = (s, name) => s.page.getByRole("button", { name }).filter({ visible: true }).last();
const pickLike = async (s, select, re) => {
  const opt = await s.page.locator(`${select} option`).filter({ hasText: re }).first().getAttribute("value");
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(opt);
  await s.wait(0.7);
};
const ficheRow = (s, name) => s.page.locator("#matrix-body tr").filter({ hasText: name }).first();

module.exports = {
  id: "stock-et-emballages",
  file: "LPC-ERP-Stock-Et-Emballages",
  meta: { title: "LPC ERP · Stock & Emballages (Modules, partie 3)", artist: "JBS Praxis" },
  library: {
    slug: "stock-et-emballages",
    title: "Stock & Emballages",
    subtitle: "Le stock au jour le jour, les avaries, l'inventaire et la Fiche de Stock",
    series: "Modules",
    part: 3,
    parts: 15,
    pages: [PAGE.stock, PAGE.fiche],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 3",
    title: "Stock & Emballages",
    subtitle: "Le stock au jour le jour, les avaries, l'inventaire et la Fiche de Stock",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 3",
    title: "Merci",
    subtitle: "À suivre : Proposition commerciale",
    left: "bureau.lpc.cm",
  },
  holders: { operations: "Bertin Chadeu", dg: "Timothée Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-03-film");
    log("ground: pre-03-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Stock & Emballages, la page où LPC suit chaque bonbonne de son entrepôt.",
          "Le Responsable des Opérations tient le stock. Le Directeur Général le suit.",
          "Le Contrôleur Financier et le Comptable consultent ces pages, sans pouvoir déclarer une perte.",
          "Nous partons des cent bonbonnes reçues de Source du Pays dans la vidéo précédente.",
          "Au programme : le stock du jour, une avarie, un inventaire, et la Fiche de Stock.",
        ],
      }],
    },

    {
      name: "Le stock du jour",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock,
        lines: [
          { say: "Le Responsable des Opérations ouvre Stock & Emballages, dans Logistique & Stock.",
            do: (s) => h.soft(s, h.shell.menu(s, "Stock & Emballages")) },
          { say: "La première carte donne la valeur du stock au coût : 523 500 FCFA.",
            speech: "La première carte donne la valeur du stock au coût : cinq cent vingt-trois mille cinq cents francs.",
            do: (s) => h.soft(s, s.page.locator("#kpi_stock_value")) },
          { say: "Six produits au catalogue. Aucun en alerte, mais cinq en rupture : il n'en reste aucun.",
            do: (s) => h.tour(s, [kpi(s, "all"), kpi(s, "alert"), kpi(s, "rupture")], 0.4) },
          { say: "« En Alerte » compterait les produits qui en ont encore, mais pas plus que leur seuil minimum : le moment de commander.",
            do: (s) => h.soft(s, kpi(s, "alert")) },
          { say: "Un clic sur « En Rupture » filtre la table : les emballages vides, l'OPUR 10L et le distributeur.",
            speech: "Un clic sur « En Rupture » filtre la table : les emballages vides, l'OPUR dix litres et le distributeur.",
            do: (s) => s.click(kpi(s, "rupture")) },
          { say: "« Total Références » remet toute la liste.",
            do: (s) => s.click(kpi(s, "all")) },
          { say: "L'OPUR 20L : 260 bonbonnes, au coût moyen de 2 013 FCFA. Le seuil minimum est de cinquante.",
            speech: "L'OPUR vingt litres : deux cent soixante bonbonnes, au coût moyen de deux mille treize francs. Le seuil minimum est de cinquante.",
            do: (s) => h.soft(s, stockRow(s, "OPUR 20L")) },
          "Le seuil minimum de chaque produit se règle sur sa fiche, dans Données de Base.",
          "Ce coût moyen pondéré, le CUMP, change à chaque réception. Une sortie ne le change pas.",
          { say: "Les emballages vides ont aussi leur ligne. Ceux que LPC récupère chez ses clients entrent ici, avant d'être revendus au recycleur.",
            do: (s) => h.soft(s, stockRow(s, "20L sans bouchon")) },
          "Aujourd'hui il n'y en a aucun : la vidéo Gestion des Vides montre comment ils rentrent.",
          { say: "L'onglet Réceptions Fournisseurs garde la commande de HOREB SARL, qui attend sa livraison.",
            speech: "L'onglet Réceptions Fournisseurs garde la commande de Horeb SARL, qui attend sa livraison.",
            do: (s) => s.click(s.page.locator("#tab-receptions")) },
          { say: "Recevoir une commande est montré dans la vidéo Gestion des Achats.",
            do: (s) => s.click(s.page.locator("#tab-stock")) },
        ],
      }],
    },

    {
      name: "Une avarie",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock, snapshot: true,
        lines: [
          "Au déchargement, trois bonbonnes sont percées. Elles ne seront jamais vendues.",
          { say: "Il clique sur « Déclarer Avarie ».",
            do: (s) => s.click(s.page.getByRole("button", { name: /Déclarer Avarie/ }).first()) },
          { say: "La liste montre chaque produit en stock. Il choisit l'OPUR 20L.",
            speech: "La liste montre chaque produit en stock. Il choisit l'OPUR vingt litres.",
            do: async (s) => { await s.wait(0.6); await s.hover(s.page.locator("#dmg_product")); await s.page.locator("#dmg_product").selectOption(OPUR20); await s.wait(0.6); } },
          { say: "Quantité perdue : trois. La raison est obligatoire.",
            do: async (s) => {
              await s.type(s.page.locator("#dmg_qty"), "3", { delay: 160 });
              await s.type(s.page.locator("#dmg_reason"), "Bouteilles percées au déchargement", { delay: 45 });
            } },
          { say: "« Enregistrer Avarie ». Trois bonbonnes sortent du stock, pour 6 040 FCFA au CUMP. Il en reste 257.",
            speech: "« Enregistrer Avarie ». Trois bonbonnes sortent du stock, pour six mille quarante francs au CUMP. Il en reste deux cent cinquante-sept.",
            do: async (s) => { await s.click(s.page.locator("#damageModal button[onclick^='submitDamage']")); await s.wait(1.2); } },
          "La perte sort aussi des comptes : le compte de stock baisse de la même valeur. Personne n'a rien à ressaisir.",
          { say: "La Direction et la Finance sont prévenues.",
            do: (s) => s.click(visibleButton(s, "Fermer")) },
          "Seuls le Responsable des Opérations et la Direction voient ce bouton. Le Contrôleur Financier et le Comptable consultent le stock.",
        ],
      }],
    },

    {
      name: "L'historique",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock,
        lines: [
          { say: "L'onglet Historique garde chaque mouvement, du plus récent au plus ancien.",
            do: (s) => s.click(s.page.locator("#tab-movements")) },
          { say: "En haut, l'avarie, avec sa raison et le nom de qui l'a déclarée.",
            do: (s) => h.soft(s, s.page.locator("#tbody-movements tr").first()) },
          { say: "En dessous, les réceptions, chacune avec son bon de commande.",
            do: (s) => h.soft(s, s.page.locator("#tbody-movements tr").nth(2)) },
          { say: "La dernière colonne donne le stock juste après chaque mouvement.",
            do: (s) => h.soft(s, s.page.locator("#content-movements th").filter({ hasText: /Nouveau solde/i }).first()) },
          "Les livraisons aux clients s'y ajouteront, avec leur bon de livraison.",
          "Si un client refuse des bonbonnes à la livraison, elles reviennent en stock toutes seules quand il signe le bon.",
          "Rien ne s'y efface. Une erreur se corrige par un nouveau mouvement, le plus souvent un inventaire.",
        ],
      }],
    },

    {
      name: "L'inventaire",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock, snapshot: true,
        lines: [
          "En fin de journée, il compte l'entrepôt.",
          { say: "L'onglet Inventaire liste tous les produits, avec ce que le système détient.",
            do: (s) => s.click(s.page.locator("#tab-audit")) },
          { say: "Il ne trouve que 255 bonbonnes d'OPUR 20L, pour 257 attendues.",
            speech: "Il ne trouve que deux cent cinquante-cinq bonbonnes d'OPUR vingt litres, pour deux cent cinquante-sept attendues.",
            do: (s) => s.type(s.page.locator(`#tbody-audit tr[data-pid="${OPUR20}"] .audit-input`), "255", { delay: 170 }) },
          "Les produits qu'il n'a pas comptés restent vides : ils ne bougent pas.",
          { say: "« Valider Inventaire » montre d'abord les écarts : deux bonbonnes manquent.",
            do: (s) => s.click(s.page.getByRole("button", { name: /Valider Inventaire/ }).first()) },
          { say: "Il relit, puis scelle : « Certifier & Sceller ». Un inventaire scellé ne se modifie plus.",
            do: async (s) => { await s.click(s.page.locator("#btn-certify-audit")); await s.wait(1.5); } },
          { say: "Le système a relu son propre stock et calculé l'écart : moins deux, soit 4 027 FCFA au CUMP.",
            speech: "Le système a relu son propre stock et calculé l'écart : moins deux, soit quatre mille vingt-sept francs au CUMP.",
            do: (s) => h.soft(s, s.page.getByRole("dialog").last()) },
          "Comme pour l'avarie, la valeur des bonbonnes manquantes sort du compte de stock.",
          { say: "« Ouvrir le certificat » : le rapport numéroté, scellé, à garder pour un contrôle.",
            do: async (s) => { await s.click(visibleButton(s, "Ouvrir le certificat")); await s.wait(1.5); } },
          { say: "Il porte la date, le responsable, le stock théorique, l'écart et le stock compté.",
            do: (s) => s.scroll(260) },
        ],
      }, {
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock,
        lines: [
          { say: "De retour dans l'onglet Inventaire, « Rapports Passés » garde chaque inventaire scellé.",
            do: async (s) => { await s.click(s.page.locator("#tab-audit")); await s.click(s.page.getByRole("button", { name: /Rapports Passés/ }).first()); } },
          { say: "Un clic déroule ses écarts. Le Contrôleur Financier et le Comptable peuvent les lire aussi.",
            do: async (s) => { await s.wait(0.8); await s.click(s.page.locator("#audit-history-container button").first()); } },
          { say: "Il ferme la fenêtre.",
            do: (s) => s.click(s.page.locator("#historyAuditModal button[onclick^='closeModal']").first()) },
        ],
      }],
    },

    {
      name: "La Fiche de Stock",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.fiche,
        lines: [
          { say: "La Fiche de Stock raconte une période. Par défaut, le mois en cours.",
            do: (s) => h.soft(s, s.page.locator("#start_date")) },
          { say: "Le graphique montre les entrées et les sorties, jour par jour.",
            do: (s) => h.soft(s, s.page.locator("#trendChart")) },
          { say: "Pour l'OPUR 20L : rien au début du mois, 260 entrées, cinq sorties, 255 à la fin.",
            speech: "Pour l'OPUR vingt litres : rien au début du mois, deux cent soixante entrées, cinq sorties, deux cent cinquante-cinq à la fin.",
            do: (s) => s.scroll(320) },
          { say: "Un clic sur le produit ouvre sa fiche, mouvement par mouvement, avec le solde après chacun.",
            do: (s) => s.click(ficheRow(s, "OPUR 20L")) },
          { say: "Chaque mouvement garde son coût : le prix d'achat pour une réception, le CUMP du jour pour une sortie.",
            do: (s) => h.soft(s, s.page.locator("#matrix-body table thead th").filter({ hasText: /Coût unitaire/ }).first()) },
          { say: "L'interrupteur passe en francs.",
            do: async (s) => { await s.scroll(-320); await s.click(s.page.locator("label[for=view-toggle]")); await s.wait(0.8); } },
          { say: "523 500 FCFA sont entrés, 10 067 sont sortis : il reste 513 433 FCFA de stock.",
            speech: "Cinq cent vingt-trois mille cinq cents francs sont entrés, dix mille soixante-sept sont sortis : il reste cinq cent treize mille quatre cent trente-trois francs de stock.",
            do: async (s) => { await s.scroll(320); await h.soft(s, ficheRow(s, "OPUR 20L")); } },
          "Un mois passé garde sa valeur, même quand le coût moyen change ensuite.",
          "Il peut choisir n'importe quelle période : le stock initial est alors celui du premier jour choisi.",
        ],
      }],
    },

    {
      name: "Ce que voit la Direction",
      shots: [{
        kind: "desktop", as: "timothee", role: "dg", start: PAGE.ledger,
        lines: [
          { say: "Le Directeur Général vérifie que le stock et les comptes disent la même chose. Il ouvre le Grand Livre.",
            do: (s) => s.click(s.page.locator("#tab-grandlivre")) },
          { say: "Le compte 311, Stocks Eau : les réceptions en entrée, l'avarie et l'inventaire en sortie.",
            speech: "Le compte trois cent onze, Stocks Eau : les réceptions en entrée, l'avarie et l'inventaire en sortie.",
            do: (s) => pickLike(s, "#gl_account_select", /\[311\]/) },
          { say: "Son solde, 513 433 FCFA, est exactement la valeur du stock de la Fiche.",
            speech: "Son solde, cinq cent treize mille quatre cent trente-trois francs, est exactement la valeur du stock de la Fiche.",
            do: (s) => s.scroll(300) },
          { say: "En face, le compte 6031, Variations des stocks : la perte et le manquant y sont des charges, 10 067 FCFA au débit.",
            speech: "En face, le compte six mille trente et un, Variations des stocks : la perte et le manquant y sont des charges, dix mille soixante-sept francs au débit.",
            do: async (s) => { await s.scroll(-300); await pickLike(s, "#gl_account_select", /\[6031\]/); } },
          { say: "Ce que l'entrepôt perd, le résultat le voit le jour même.",
            do: (s) => s.scroll(300) },
          "Les écritures elles-mêmes sont l'objet des vidéos de comptabilité.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : chaque bonbonne qui entre ou sort laisse une trace, en stock et en comptabilité.",
          "Une perte se déclare avec sa raison. Un écart se corrige par un inventaire scellé.",
          "La prochaine vidéo prépare la vente : la Proposition commerciale.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
