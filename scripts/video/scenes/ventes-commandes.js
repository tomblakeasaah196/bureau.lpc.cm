"use strict";
/**
 * Modules, partie 5 : Ventes & Commandes (Commandes Clients, Dispatch & BL, the order and BL details,
 * the client's signature). docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * The Responsable des Opérations (Bertin Chadeu on screen) takes PROMETAL's order at the price its signed
 * devis set (2 275 F, Net 15, 80 % minimum return of empties), 200 OPUR 20L out of the 255 in stock (the
 * month's 1 667 arrive in several deliveries), dispatches it with LPC's own truck and a driver from the
 * staff (« Flotte LPC », VIDEO_QA_FINDINGS 5.1), closes the delivery (5.2), and has PROMETAL sign the BL
 * through its link (signed out; for the training the signature is made on the client's behalf, and the
 * video says so). Then what the signature leaves: the BL « Signé par le client », the order « Livré »,
 * the empties to recover, the stock. Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-05-film = after-04-proposition + migrations 139 to 143 + the example truck LT 742 KL
 * (Toyota Dyna), Nelson Tanyu (EMP-007, « Chauffeur », name and post only, as on copy B) and today's
 * assignment of Nelson to the truck (prepare dates it today). The scene writes CMD-2611-00001,
 * BL-2611-00001 and its signature: the copy is then snapshot after-05-ventes for Gestion des Vides.
 */
const h = require("../helpers");
const ground = require("../ground");

const PAGE = {
  orders: "/modules/sales/orders.php",
  stock: "/modules/inventory/stock.php",
};

const orderRow = (s) => s.page.locator("#table-body tr").filter({ hasText: "PROMETAL" }).first();
const line = (s, cls) => s.page.locator(`#so-lines-container .${cls}`).first();
const pickLike = async (s, select, re) => {
  const opt = await s.page.locator(`${select} option`).filter({ hasText: re }).first().getAttribute("value");
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(opt);
  await s.wait(0.7);
};
/** PROMETAL's BL on the copy (the shots after the dispatch open it by its link). */
const bl = () => {
  const rows = ground.sql(
    "SELECT d.id, d.token, d.sales_order_id FROM deliveries d JOIN clients c ON c.id = d.client_id WHERE c.name = 'PROMETAL' ORDER BY d.id DESC LIMIT 1"
  );
  if (!rows.length) throw new Error("PROMETAL has no BL on the copy: film c05s01 first.");
  return { id: rows[0][0], token: rows[0][1], order: rows[0][2] };
};

module.exports = {
  id: "ventes-commandes",
  file: "LPC-ERP-Ventes-Et-Commandes",
  meta: { title: "LPC ERP · Ventes & Commandes (Modules, partie 5)", artist: "JBS Praxis" },
  library: {
    slug: "ventes-commandes",
    title: "Ventes & Commandes",
    subtitle: "La commande du client, sa livraison par le camion de LPC, et le BL signé",
    series: "Modules",
    part: 5,
    parts: 15,
    pages: [PAGE.orders, "/modules/sales/order_detail.php", "/modules/sales/bl_detail.php"],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 5",
    title: "Ventes & Commandes",
    subtitle: "La commande du client, sa livraison par le camion de LPC, et le BL signé",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 5",
    title: "Merci",
    subtitle: "À suivre : Gestion des Vides",
    left: "bureau.lpc.cm",
  },
  holders: { operations: "Bertin Chadeu" },

  async prepare({ log, ground: g }) {
    g.restore("pre-05-film");
    // Today's assignment of the truck, whatever day the take is filmed.
    g.sql("UPDATE vehicle_assignments SET assignment_date = CURRENT_DATE WHERE vehicle_id = 1");
    log("ground: pre-05-film restored, assignment dated today");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Ventes & Commandes : comment une commande devient une livraison signée.",
          "Nous suivons le Responsable des Opérations. Il prend les commandes des clients et organise leur livraison.",
          "Le client est PROMETAL, qui vient de signer son devis : son prix, son paiement à quinze jours et son retour des vides sont déjà enregistrés.",
          "Au programme : la commande, le bon de livraison avec le camion de LPC, la clôture, et la signature du client.",
        ],
      }],
    },

    {
      name: "L'écran des ventes",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.orders,
        lines: [
          { say: "Le Responsable des Opérations ouvre Ventes & Commandes, dans Commerce & CRM.",
            do: (s) => h.soft(s, h.shell.menu(s, "Ventes & Commandes")) },
          { say: "Deux onglets : les Commandes Clients, et le Dispatch avec les bons de livraison.",
            do: (s) => h.tour(s, [s.page.locator("#tab-orders"), s.page.locator("#tab-dispatch")], 0.5) },
          { say: "Les cartes résument la période : les ventes, ce qui reste à livrer, ce que les clients doivent, les remises.",
            do: (s) => h.tour(s, ["kpi_so_total", "kpi_so_pending", "kpi_so_debt", "kpi_so_discount"].map((k) => s.page.locator(`#${k}`)), 0.4) },
          "Aucune vente n'est encore enregistrée : ces chiffres se rempliront avec le travail.",
          { say: "La période se choisit ici. Par défaut, l'année en cours.",
            do: (s) => h.soft(s, s.page.locator("#kpi_period_type")) },
        ],
      }],
    },

    {
      name: "La commande de PROMETAL",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.orders, snapshot: true,
        lines: [
          "PROMETAL prend environ mille six cents bonbonnes par mois, livrées en plusieurs fois.",
          "Il y en a 255 en stock : le Responsable des Opérations prend aujourd'hui une commande de deux cents.",
          { say: "« Nouvelle Commande ».",
            do: (s) => s.click(s.page.locator("#btn-primary-action")) },
          { say: "Le client d'abord : PROMETAL. L'écran indique qu'il a un prix négocié.",
            do: async (s) => { await pickLike(s, "#so_client", /PROMETAL/); await h.soft(s, s.page.locator("#so_client_hint")); } },
          { say: "Le produit : l'OPUR 20L. Son prix est celui du devis signé, 2 275 FCFA, marqué « négocié ».",
            speech: "Le produit : l'OPUR vingt litres. Son prix est celui du devis signé, deux mille deux cent soixante-quinze francs, marqué « négocié ».",
            do: async (s) => {
              await s.click(s.page.locator("#so-lines-container").getByPlaceholder(/Choisir un produit/).first());
              await s.page.keyboard.type("OPUR 20L", { delay: 70 }); await s.wait(0.9); await s.key("Enter");
              await s.wait(0.5); await h.soft(s, line(s, "so-tariff"));
            } },
          { say: "Quantité : deux cents. Le total : 455 000 FCFA.",
            speech: "Quantité : deux cents. Le total : quatre cent cinquante-cinq mille francs.",
            do: async (s) => { await s.type(line(s, "so-qty"), "200", { delay: 150 }); await line(s, "so-qty").dispatchEvent("input"); await h.soft(s, s.page.locator("#calc_so_grandtotal")); } },
          "Un prix différent du tarif serait demandé à l'enregistrement : nouveau prix du client, ou remise sur cette commande seulement.",
          { say: "« Enregistrer la commande ». Elle passe « En Attente ». Rien ne sort encore du stock.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-order")); await s.wait(1.5); await h.soft(s, orderRow(s)); } },
        ],
      }],
    },

    {
      name: "Le détail de la commande",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.orders,
        lines: [
          { say: "Il ouvre la commande.",
            do: async (s) => { await s.click(orderRow(s).locator("td").nth(1)); await s.wait(1.5); } },
          { say: "Sous le nom du client : le devis signé, le paiement à quinze jours, le retour des vides au minimum 80 %.",
            speech: "Sous le nom du client : le devis signé, le paiement à quinze jours, le retour des vides au minimum quatre-vingts pour cent.",
            do: (s) => h.soft(s, s.page.locator("#so-proposal")) },
          { say: "Les lignes comparent le commandé, l'expédié et ce que le client acceptera.",
            do: (s) => h.soft(s, s.page.locator("#so-lines-body tr").first()) },
          { say: "Plus bas, les vides à récupérer : au moins 160 bonbonnes sur ces deux cents.",
            speech: "Plus bas, les vides à récupérer : au moins cent soixante bonbonnes sur ces deux cents.",
            do: async (s) => { await s.scroll(600); await h.soft(s, s.page.locator("#so-empties-body tr").first()); } },
          "Les bons de livraison et les factures de la commande s'afficheront ici.",
        ],
      }],
    },

    {
      name: "Le bon de livraison",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.orders, snapshot: true,
        lines: [
          { say: "Pour livrer, « Générer BL » sur la ligne de la commande.",
            do: async (s) => { await s.click(orderRow(s).getByRole("button", { name: /Générer BL/ })); await s.wait(0.8); } },
          { say: "Trois modes : le camion de LPC, une livraison par le fournisseur, ou un enlèvement par le client.",
            do: (s) => h.tour(s, [...["own_fleet", "supplier", "client_pickup"].map((m) => s.page.locator(`.disp-mode-btn[data-mode="${m}"]`))], 0.4) },
          "C'est « Flotte LPC » : le camion de LPC.",
          { say: "Le chauffeur se choisit dans le personnel. LPC n'a pas de comptes chauffeur : Nelson Tanyu, chauffeur.",
            do: async (s) => { await pickLike(s, "#disp_driver", /Nelson/); } },
          { say: "Le camion, LT 742 KL, est déjà rempli : c'est l'affectation du jour, faite dans Flotte & Maintenance.",
            speech: "Le camion, L T sept cent quarante-deux K L, est déjà rempli : c'est l'affectation du jour, faite dans Flotte et Maintenance.",
            do: (s) => h.soft(s, s.page.locator("#disp_vehicle")) },
          { say: "« Valider & Imprimer BL ». Le BL s'imprime pour accompagner le camion.",
            do: async (s) => { await s.click(s.page.locator("#dispatchModal").getByRole("button", { name: /Valider & Imprimer BL/ })); await s.wait(2); } },
          { say: "Les deux cents bonbonnes sortent du stock, à leur coût moyen, et l'écriture comptable est passée.",
            do: (s) => h.soft(s, s.page.locator("#table-body tr").first()) },
          "Le BL est « En Transit ». La commande aussi.",
        ],
      }, {
        kind: "desktop", as: "bertin", role: "operations",
        setup: async (s) => { await s.go(`/bon_livraison.php?token=${bl().token}`); await s.wait(1.5); },
        lines: [
          "Voici le bon de livraison que le chauffeur remet à PROMETAL : le client, les produits et les quantités.",
          { say: "Le chauffeur et le camion n'y figurent pas : c'est une information interne à LPC.",
            do: (s) => h.soft(s, s.page.getByText("OPUR 20L").first()) },
        ],
      }],
    },

    {
      name: "Clôturer la livraison",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: `${PAGE.orders}#dispatch`, snapshot: true,
        lines: [
          "Le camion revient. Le Responsable des Opérations enregistre ce qui s'est passé chez le client.",
          { say: "Dans Dispatch & BL, « Clôturer ».",
            do: async (s) => { await s.click(s.page.locator("#table-body tr").first().getByRole("button", { name: /Clôturer/ })); await s.wait(1); } },
          { say: "PROMETAL a accepté les deux cents bonbonnes : la quantité est déjà remplie.",
            do: (s) => h.soft(s, s.page.locator(".close-qty-input").first()) },
          "S'il avait refusé des bonbonnes, la quantité serait plus basse : les refusées reviendraient en stock à la signature.",
          { say: "Vides rendus : zéro. C'est la première livraison, PROMETAL n'en a pas encore.",
            do: (s) => h.soft(s, s.page.locator(".empty-qty-input").first()) },
          { say: "Montant collecté : zéro. PROMETAL paie par virement, à quinze jours.",
            do: (s) => h.soft(s, s.page.locator("#close_cash")) },
          { say: "« Valider & Clôturer ».",
            do: async (s) => { await s.click(s.page.locator("#closeDeliveryModal").getByRole("button", { name: /Valider & Clôturer/ })); await s.wait(1.5); } },
          { say: "Reste la signature du client : un QR code à faire scanner, ou le lien à envoyer par WhatsApp.",
            do: (s) => h.soft(s, s.page.locator("#qrcode-bl")) },
          { say: "Le BL est « Clôturé, à signer ».",
            speech: "Le BL est « Clôturé, à signer ».",
            do: async (s) => { await s.click(s.page.locator("#modal-share-bl").getByRole("button", { name: "Fermer" })); await s.wait(0.6); await h.soft(s, s.page.locator("#table-body tr").first()); } },
        ],
      }],
    },

    {
      name: "PROMETAL signe le BL",
      shots: [{
        kind: "desktop", snapshot: true,
        setup: async (s) => { await s.go(`/sign_bl.php?token=${bl().token}`); await s.wait(1.2); },
        lines: [
          "Voici ce que PROMETAL ouvre avec le lien, sans compte à créer. Pour la formation, nous signons à sa place.",
          { say: "Il vérifie les quantités reçues. Il peut les corriger s'il manque quelque chose.",
            do: (s) => h.soft(s, s.page.locator(".input-accepted").first()) },
          { say: "Les vides rendus maintenant : zéro. Le montant versé au chauffeur : rien, il paie par virement.",
            do: async (s) => { await h.soft(s, s.page.locator(".input-empties").first()); await h.soft(s, s.page.locator("#input_payment")); } },
          { say: "Le réceptionnaire donne son nom et sa fonction. Le téléphone de PROMETAL est déjà là.",
            do: async (s) => {
              await s.type(s.page.locator("#sig_name"), "Service Achats PROMETAL", { delay: 45 });
              await s.type(s.page.locator("#sig_role"), "Responsable des Achats", { delay: 45 });
            } },
          { say: "Il signe dans le cadre.",
            do: async (s) => {
              const pad = s.page.locator("#signature-pad");
              await pad.scrollIntoViewIfNeeded();
              await s.hover(pad);
              const b = await pad.boundingBox();
              await s.page.mouse.move(b.x + 40, b.y + b.height * 0.6);
              await s.page.mouse.down();
              for (let i = 0; i <= 40; i++) {
                await s.page.mouse.move(b.x + 40 + i * Math.min(9, (b.width - 80) / 40), b.y + b.height * 0.55 - Math.sin(i / 3.2) * b.height * 0.25 + (i % 7) * 2, { steps: 2 });
              }
              await s.page.mouse.up();
            } },
          { say: "« Clôturer & Sécuriser le BL ». La signature est horodatée et scellée.",
            do: async (s) => { await s.click(s.page.locator("#sig_submit_btn")); await s.wait(2.5); } },
        ],
      }],
    },

    {
      name: "Ce que la signature laisse",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: `${PAGE.orders}#dispatch`,
        lines: [
          { say: "Le Responsable des Opérations est prévenu, comme la Facturation : le BL est « Signé par le client ».",
            do: (s) => h.soft(s, s.page.locator("#table-body tr").first()) },
          { say: "Il ouvre le BL.",
            do: async (s) => { await s.click(s.page.locator("#table-body tr").first().locator("td").nth(1)); await s.wait(1.5); } },
          { say: "La chaîne est complète : la livraison de LPC, clôturée, avec Nelson Tanyu et le camion.",
            do: (s) => h.soft(s, s.page.locator("#bl-signatures > div").first()) },
          { say: "Et la signature de PROMETAL, avec le nom et la fonction du signataire, la date et l'heure.",
            do: (s) => h.soft(s, s.page.locator("#bl-signatures > div").nth(1)) },
          { say: "Le BL est maintenant « À facturer » : le Comptable établira la facture, dans Facturation.",
            do: (s) => h.soft(s, s.page.locator("#bl-invoice")) },
          { say: "Le cash collecté reste à zéro : l'argent viendra par virement, et la Facturation le suivra.",
            do: (s) => h.soft(s, s.page.locator("#bl-cash")) },
        ],
      }, {
        kind: "desktop", as: "bertin", role: "operations",
        setup: async (s) => { await s.go(`/modules/sales/order_detail.php?id=${bl().order}`); await s.wait(1.5); },
        lines: [
          { say: "La commande est « Livrée », pour 455 000 FCFA, non payée : l'échéance est à quinze jours.",
            speech: "La commande est « Livrée », pour quatre cent cinquante-cinq mille francs, non payée : l'échéance est à quinze jours.",
            do: (s) => h.soft(s, s.page.locator("#so-total")) },
          "La facture partira de ce montant. PROMETAL la réglera à l'échéance, moins sa retenue à la source.",
          { say: "PROMETAL détient maintenant deux cents bonbonnes vides à rendre. LPC doit en récupérer au moins 160.",
            speech: "PROMETAL détient maintenant deux cents bonbonnes vides à rendre. LPC doit en récupérer au moins cent soixante.",
            do: async (s) => { await s.scroll(600); await h.soft(s, s.page.locator("#so-empties-client-owed")); } },
        ],
      }, {
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.stock,
        lines: [
          { say: "Dans Stock & Emballages, il reste 55 OPUR 20L.",
            speech: "Dans Stock et Emballages, il reste cinquante-cinq OPUR vingt litres.",
            do: (s) => h.soft(s, s.page.locator("#tbody-stock tr").filter({ hasText: "OPUR 20L" }).first()) },
          "Son seuil minimum de 770 la garde en alerte : la prochaine livraison de PROMETAL attendra la réception de la commande fournisseur.",
        ],
      }],
    },

    {
      name: "Annuler ou corriger",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.orders,
        lines: [
          "Une commande encore en attente se modifie ou se supprime depuis sa ligne.",
          { say: "Après le BL, on l'annule avec un motif, tant que le client n'a pas signé : les bonbonnes reviennent alors en stock.",
            do: (s) => h.soft(s, orderRow(s)) },
          "Une commande annulée reste visible, grisée, avec son motif, son auteur et sa date. Elle sort de tous les indicateurs.",
          "Un BL signé ne s'annule plus : la livraison a eu lieu. Une correction passe par un avoir, en Facturation.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : une commande au prix du client, un BL avec le camion de LPC, la clôture, et la signature du client.",
          "La signature compte les bouteilles livrées, les vides dus, et rend le BL facturable.",
          "La prochaine vidéo récupère les vides chez PROMETAL : Gestion des Vides.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
