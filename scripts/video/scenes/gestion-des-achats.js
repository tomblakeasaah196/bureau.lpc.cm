"use strict";
/**
 * Modules, partie 2 : Gestion des Achats. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * LPC buys full OPUR bottles from Source du Pays at its tariff (1 850 F the 20L) and earns a 3 % ristourne.
 * The Responsable des Opérations orders and receives (Bertin Chadeu on screen); the Contrôleur Financier
 * pays (Luc Nana); the Comptable finds the entries (Daniel Massomba). Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-02-film (after-01-donnees-de-base + migration 131 + post_journal_entry). The scene
 * writes: an order of 100 OPUR 20L (PO-2611-00007), its receptions (60, then 40), its payment from MTN
 * MOBILE MONEY. The next video (Stock & Emballages) starts from what this one leaves.
 */
const h = require("../helpers");

const PAGE = {
  proc: "/modules/inventory/procurement.php",
  stock: "/modules/inventory/stock.php",
  ledger: "/modules/accounting/ledger.php",
};
const REF = "PO-2611-00007";

/** A row of a list, by text it holds. */
const row = (s, text) => s.page.locator("#table-body tr").filter({ hasText: text }).first();
const poModal = (s) => s.page.locator("#poModal");
const line = (s, cls) => s.page.locator(`#po-lines-container .${cls}`).first();
const pick = async (s, select, label) => { await s.hover(s.page.locator(select)); await s.page.locator(select).selectOption({ label }); await s.wait(0.6); };
const pickLike = async (s, select, re) => {
  const opt = await s.page.locator(`${select} option`).filter({ hasText: re }).first().getAttribute("value");
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(opt);
  await s.wait(0.7);
};
/** Our order's « Recevoir » on the Réceptions tab (HOREB's order waits there too). */
const recvBtn = (s) => s.page.locator("tr").filter({ hasText: REF }).getByRole("button", { name: "Recevoir" }).first();
const toast = (s, text) => s.page.locator(".lpc-toast, [role=status], [role=alert]").filter({ hasText: text }).first();

module.exports = {
  id: "gestion-des-achats",
  file: "LPC-ERP-Gestion-Des-Achats",
  meta: { title: "LPC ERP · Gestion des Achats (Modules, partie 2)", artist: "JBS Praxis" },
  library: {
    slug: "gestion-des-achats",
    title: "Gestion des Achats",
    subtitle: "Commander, recevoir, gagner la ristourne et payer le fournisseur",
    series: "Modules",
    part: 2,
    parts: 15,
    pages: [PAGE.proc, PAGE.stock],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 2",
    title: "Gestion des Achats",
    subtitle: "Commander, recevoir, gagner la ristourne et payer le fournisseur",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 2",
    title: "Merci",
    subtitle: "À suivre : Stock & Emballages",
    left: "bureau.lpc.cm",
  },
  holders: { operations: "Bertin Chadeu", controleur: "Luc Nana", comptable: "Daniel Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-02-film");
    log("ground: pre-02-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la Gestion des Achats de LPC ERP.",
          "LPC achète ses bonbonnes OPUR pleines à ses fournisseurs : Source du Pays et HOREB SARL.",
          { say: "Le Responsable des Opérations commande et reçoit la marchandise. Le Contrôleur Financier paie le fournisseur.",
            speech: "Le Responsable des Opérations commande et reçoit la marchandise. Le Contrôleur Financier paie le fournisseur." },
          "Nous suivons une commande de cent bonbonnes de vingt litres chez Source du Pays, jusqu'à son paiement.",
        ],
      }],
    },

    {
      name: "La page des achats",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.proc,
        lines: [
          { say: "Le Responsable des Opérations ouvre Gestion des Achats, dans Logistique & Stock.",
            do: (s) => h.soft(s, h.shell.menu(s, "Gestion des Achats")) },
          { say: "Chaque bon de commande montre son fournisseur, sa réception et son paiement.",
            do: (s) => h.tour(s, ["Fournisseur", "Réception", "Paiement"].map((t) => s.page.locator("#table-head th").filter({ hasText: t }).first()), 0.4) },
          { say: "La commande de HOREB SARL du 11 novembre attend encore sa livraison. Elle n'est donc pas encore une dette.",
            speech: "La commande de Horeb SARL du onze novembre attend encore sa livraison. Elle n'est donc pas encore une dette.",
            do: (s) => h.soft(s, row(s, "PO-2611-00005")) },
          { say: "En haut, le total des achats et les dettes fournisseurs : ce qui a été reçu et pas encore payé.",
            do: (s) => h.soft(s, s.page.locator("#kpi-ribbon").first()) },
          "Le bouton « Payer Fournisseur » n'apparaît pas ici : payer revient au Contrôleur Financier.",
        ],
      }],
    },

    {
      name: "La commande",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.proc, snapshot: true,
        lines: [
          { say: "Il crée un nouveau bon de commande.",
            do: (s) => s.click(s.page.locator("#btn-primary-action")) },
          { say: "Fournisseur : Source du Pays. Sa ristourne disponible s'affiche à droite du total.",
            do: async (s) => { await pickLike(s, "#po_supplier", /SOURCE DU PAYS/i); await h.soft(s, s.page.locator("#po_discount_label")); } },
          { say: "Il choisit le produit, l'OPUR 20L. Le prix se met tout seul : le tarif de Source du Pays, 1 850 FCFA.",
            speech: "Il choisit le produit, l'OPUR vingt litres. Le prix se met tout seul : le tarif de Source du Pays, mille huit cent cinquante francs.",
            do: async (s) => {
              await s.click(s.page.locator("#po-lines-container").getByPlaceholder(/Choisir un produit/).first());
              await s.page.keyboard.type("OPUR 20L", { delay: 70 }); await s.wait(0.9); await s.key("Enter");
              await h.soft(s, line(s, "po-price"));
            } },
          { say: "Quantité : cent bonbonnes, soit 185 000 FCFA.",
            speech: "Quantité : cent bonbonnes, soit cent quatre-vingt-cinq mille francs.",
            do: async (s) => { await s.type(line(s, "po-qty"), "100", { delay: 150 }); await line(s, "po-qty").dispatchEvent("input"); await h.soft(s, s.page.locator("#calc_grandtotal")); } },
          { say: "Si le prix saisi diffère du tarif, l'ERP le remarque. Essayons 1 800.",
            speech: "Si le prix saisi diffère du tarif, l'E R P le remarque. Essayons mille huit cents.",
            do: async (s) => { await s.type(line(s, "po-price"), "1800", { delay: 150 }); await line(s, "po-price").dispatchEvent("input"); await s.click(s.page.locator("#poModal button[onclick^='submitPO']")); } },
          { say: "Il demande : est-ce le nouveau prix du fournisseur, ou une remise sur cette commande seulement ?",
            do: (s) => h.soft(s, s.page.locator("#poPriceConfirmModal h3").first()) },
          { say: "Ce n'est ni l'un ni l'autre : il revient au tarif.",
            do: async (s) => {
              await s.click(s.page.locator("#poPriceConfirmModal button").filter({ hasText: "Retour" }).first());
              await s.type(line(s, "po-price"), "1850", { delay: 150 }); await line(s, "po-price").dispatchEvent("input");
            } },
          { say: "« Enregistrer la commande ». Rien n'entre encore en stock, rien n'est encore dû.",
            do: async (s) => { await s.click(s.page.locator("#poModal button[onclick^='submitPO']")); await s.wait(1.2); } },
          { say: "La commande apparaît : en attente de réception.",
            do: (s) => h.soft(s, row(s, REF), 6000) },
        ],
      }],
    },

    {
      name: "Remise ou ristourne",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.proc,
        lines: [
          "Sous le total, une case réduit le montant de la commande. Son sens dépend du fournisseur.",
          { say: "Chez Source du Pays, elle s'appelle « Ristourne utilisée » : on y dépense la ristourne déjà gagnée, jusqu'au solde affiché.",
            do: async (s) => { await s.click(s.page.locator("#btn-primary-action")); await pickLike(s, "#po_supplier", /SOURCE DU PAYS/i); await h.soft(s, s.page.locator("#po_discount_label")); } },
          "Elle ne baisse pas le prix des bonbonnes : elle règle une partie de ce que LPC doit à Source du Pays.",
          { say: "HOREB SARL n'accorde pas de ristourne. Pour lui, la même case devient « Remise accordée par le fournisseur ».",
            speech: "Horeb SARL n'accorde pas de ristourne. Pour lui, la même case devient « Remise accordée par le fournisseur ».",
            do: async (s) => { await pickLike(s, "#po_supplier", /HOREB/i); await h.soft(s, s.page.locator("#po_discount_label")); } },
          "Cette remise baisse le prix de la commande : les bonbonnes entrent en stock au prix net, à chaque réception.",
          "Une remise doit avoir un motif, et ne peut pas couvrir toute la commande.",
          { say: "Rien à commander aujourd'hui chez HOREB : il ferme sans enregistrer.",
            speech: "Rien à commander aujourd'hui chez Horeb : il ferme sans enregistrer.",
            do: (s) => s.click(poModal(s).getByRole("button", { name: /^Annuler$/ }).first()) },
        ],
      }],
    },

    {
      name: "La réception",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock, snapshot: true,
        lines: [
          { say: "Le camion de Source du Pays arrive. Dans Stock & Emballages, l'onglet Réceptions Fournisseurs liste les commandes à recevoir.",
            do: (s) => s.click(s.page.locator("#tab-receptions")) },
          { say: "Ce premier camion n'apporte que soixante bonbonnes.",
            do: async (s) => {
              await s.click(recvBtn(s));
              await s.type(s.page.locator("#modal-reception input[type=number]").filter({ visible: true }).first(), "60", { delay: 160 });
            } },
          { say: "« Valider l'Entrée » : les soixante bonbonnes entrent en stock, au prix de la commande.",
            do: async (s) => { await s.click(s.page.locator("#modal-reception button[onclick^='submitReception']")); await s.wait(1.2); } },
          { say: "Le récapitulatif montre ce qui manque encore : quarante bonbonnes.",
            do: (s) => h.soft(s, s.page.locator("#tbody-summary").first()) },
          { say: "La commande passe en réception partielle. Elle ne se paie qu'une fois tout reçu.",
            do: (s) => s.click(s.page.locator("#summaryModal button[onclick^='closeModal']").first()) },
          { say: "Le lendemain, les quarante autres arrivent. Il les reçoit de la même façon.",
            do: async (s) => {
              await s.click(s.page.locator("#tab-receptions"));
              await s.click(recvBtn(s));
              await s.type(s.page.locator("#modal-reception input[type=number]").filter({ visible: true }).first(), "40", { delay: 160 });
              await s.click(s.page.locator("#modal-reception button[onclick^='submitReception']"));
              await s.wait(1.2);
            } },
          { say: "La commande est reçue en totalité : cent bonbonnes de plus en stock.",
            do: (s) => s.click(s.page.locator("#summaryModal button[onclick^='closeModal']").first()) },
        ],
      }],
    },

    {
      name: "La ristourne",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.proc,
        lines: [
          { say: "Le Contrôleur Financier ouvre la même page. Lui voit « Payer Fournisseur », mais pas le bouton de commande.",
            do: (s) => h.soft(s, s.page.locator("#btn-pay-supplier")) },
          { say: "Source du Pays accorde à LPC une ristourne de 3 % du montant hors taxe de l'eau achetée. Il l'ouvre dans « Ristournes ».",
            speech: "Source du Pays accorde à L P C une ristourne de trois pour cent du montant hors taxe de l'eau achetée. Il l'ouvre dans « Ristournes ».",
            do: async (s) => { await s.click(s.page.locator("#btn-sdp-ristourne")); await pickLike(s, "#ristourne_supplier_select", /SOURCE DU PAYS/i); } },
          { say: "Les prix de commande sont toutes taxes comprises : le hors taxe est le montant divisé par 1,1925.",
            speech: "Les prix de commande sont toutes taxes comprises : le hors taxe est le montant divisé par un virgule un neuf deux cinq." },
          { say: "Sur la ristourne, 21,25 % sont retenus : 19,25 % de TVA et 2 % de précompte.",
            speech: "Sur la ristourne, vingt et un virgule vingt-cinq pour cent sont retenus : dix-neuf virgule vingt-cinq pour cent de T V A, et deux pour cent de précompte." },
          { say: "Chaque réception l'a gagnée : 2 199 FCFA nets sur les soixante bonbonnes, 1 467 FCFA sur les quarante.",
            speech: "Chaque réception l'a gagnée : deux mille cent quatre-vingt-dix-neuf francs nets sur les soixante bonbonnes, mille quatre cent soixante-sept sur les quarante.",
            do: (s) => h.soft(s, s.page.locator("#ristourne-ledger-body tr").first()) },
          { say: "Le solde s'utilise sur une prochaine commande, comme « Ristourne utilisée » : il réduit ce que LPC doit à Source du Pays.",
            do: (s) => h.soft(s, s.page.locator("#rist_current_balance")) },
          { say: "Il ferme la fenêtre.",
            do: async (s) => { await s.key("Escape"); const x = s.page.locator("#ristourneModal button[onclick^='closeModal']").first(); if (await x.isVisible().catch(() => false)) await s.click(x); } },
        ],
      }],
    },

    {
      name: "Le paiement",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.proc, snapshot: true,
        lines: [
          { say: "Le Contrôleur Financier règle Source du Pays avec « Payer Fournisseur ».",
            do: (s) => s.click(s.page.locator("#btn-pay-supplier")) },
          { say: "Il choisit le fournisseur. Seules les commandes reçues en totalité, et pas encore payées, s'affichent.",
            do: async (s) => { await pickLike(s, "#pay_supplier_id", /SOURCE DU PAYS/i); await h.soft(s, s.page.locator("#pay-pos-body").first()); } },
          { say: "Il paie depuis MTN MOBILE MONEY, et coche la commande : 185 000 FCFA.",
            speech: "Il paie depuis M T N Mobile Money, et coche la commande : cent quatre-vingt-cinq mille francs.",
            do: async (s) => {
              await pickLike(s, "#pay_treasury_account_id", /MTN/i);
              await s.click(s.page.locator("#pay-pos-body input[type=checkbox]").first());
              await h.soft(s, s.page.locator("#pay-summary-total"));
            } },
          { say: "« Confirmer le Règlement ». L'argent sort du compte, et la dette envers Source du Pays est soldée.",
            do: async (s) => { await s.click(s.page.locator("#paySupplierModal button[onclick^='submitSupplierPayment']")); await s.wait(1.5); } },
          { say: "La commande est maintenant payée.",
            do: (s) => h.soft(s, row(s, REF), 6000), hold: 1 },
        ],
      }],
    },

    {
      name: "Le bon de commande",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.proc,
        lines: [
          { say: "Le détail d'une commande raconte toute son histoire. Il l'ouvre avec l'œil.",
            do: (s) => s.click(row(s, REF).locator('button[title="Voir le détail"], a[title="Voir le détail"]').first()) },
          { say: "Réceptionnée, payée, et la ristourne gagnée : 3 666 FCFA nets.",
            speech: "Réceptionnée, payée, et la ristourne gagnée : trois mille six cent soixante-six francs nets.",
            do: (s) => h.tour(s, [s.page.locator("#po-status-badge"), s.page.locator("#po-payment-status"), s.page.locator("#po-rebate-earned")], 0.5) },
          { say: "L'état de réception : cent bonbonnes reçues sur cent, en deux livraisons.",
            do: (s) => s.scroll(260) },
          { say: "« Voir PDF » donne le bon de commande tel que le fournisseur le reçoit.",
            do: async (s) => { await s.scroll(-260); await h.soft(s, s.page.getByRole("link", { name: /Voir PDF/ }).or(s.page.getByRole("button", { name: /Voir PDF/ })).first()); } },
        ],
      }],
    },

    {
      name: "Annuler une commande",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.proc,
        lines: [
          { say: "Une commande qui n'aura pas lieu s'annule ici, avec la croix rouge. C'est au Contrôleur Financier de le faire.",
            do: (s) => h.soft(s, row(s, "PO-2611-00005").locator('button[title="Annuler Commande"]').first()) },
          { say: "L'ERP demande toujours le motif. Une commande déjà reçue est retirée du stock, et ses écritures sont annulées.",
            speech: "L'E R P demande toujours le motif. Une commande déjà reçue est retirée du stock, et ses écritures sont annulées.",
            do: (s) => s.click(row(s, "PO-2611-00005").locator('button[title="Annuler Commande"]').first()) },
          { say: "La commande de HOREB SARL tient toujours : il la garde.",
            speech: "La commande de Horeb SARL tient toujours : il la garde.",
            do: (s) => s.click(s.page.getByRole("button", { name: "Garder la commande" }).filter({ visible: true }).first()) },
        ],
      }],
    },

    {
      name: "Ce que trouve le Comptable",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.ledger,
        lines: [
          { say: "Le Comptable n'a rien à ressaisir : chaque étape a passé ses écritures. Il ouvre le Grand Livre.",
            do: (s) => s.click(s.page.locator("#tab-grandlivre")) },
          { say: "Le compte 311, Stocks Eau : chaque réception y a fait entrer la valeur des bonbonnes.",
            speech: "Le compte trois cent onze, Stocks Eau : chaque réception y a fait entrer la valeur des bonbonnes.",
            do: (s) => pickLike(s, "#gl_account_select", /\[311\]/) },
          { say: "Le compte de Source du Pays, 401001 : la dette née à la réception, puis soldée par le paiement.",
            speech: "Le compte de Source du Pays, quatre cent un zéro zéro un : la dette née à la réception, puis soldée par le paiement.",
            do: (s) => pickLike(s, "#gl_account_select", /\[401001\]/) },
          "Les écritures elles-mêmes, et ce qu'elles veulent dire, sont l'objet des vidéos de comptabilité.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : les Opérations commandent au tarif et reçoivent, le Contrôleur Financier paie ce qui est reçu, la ristourne se gagne à chaque réception.",
          "La prochaine vidéo suit ces bonbonnes dans Stock & Emballages.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
