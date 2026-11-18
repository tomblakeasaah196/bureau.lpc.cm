"use strict";
/**
 * Modules, partie 7 : Gestion des Vides (Dus, Collecter, the CRE and its signatures, Vente Recyclage,
 * Revenus Recyclage). docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * LPC sells the bottle; PROMETAL's signed devis sets an 80 % minimum return of the bottles delivered. The
 * Responsable des Opérations (Bertin Chadeu on screen) reads what PROMETAL must still hand back (200
 * delivered: 160 to recover, 40 it may keep), collects 130 empties (120 without cork, 10 with), signs the
 * CRE for LPC, and PROMETAL acknowledges it through its link (signed out; for the training the signature
 * is made on the client's behalf, and the video says so). The debt falls to 30. He then sells 100 empties
 * to a recycler for cash into the house cash box (Dr 5711 / Cr 7074), and reads Revenus Recyclage.
 * Narrated by role (runbook §2). VIDEO_QA_FINDINGS §7: the minimum and « À récupérer » (7.1), one debt per
 * bottle size (7.4), the stock check on the sale (7.5), permissions (7.6), the history (7.7).
 *
 * Ground: snapshot pre-07-film = after-05-ventes + migrations 144 to 151 (PROMETAL holds 200 empties, the
 * 10L empties split by cork). The scene writes CRE-2026…-001 with both signatures and REC-2026…-001: the
 * copy is then snapshot after-07-vides for Facturation & AR.
 */
const h = require("../helpers");
const ground = require("../ground");

const PAGE = { vides: "/modules/operations/empties_collection.php" };
const P20_NOCORK = 2, P20_CORK = 1;

const modal = (s) => s.page.locator("dialog[open]").last();
const okIf = async (s, seconds = 4) => {
  const ok = modal(s).getByRole("button", { name: "OK" });
  if (await ok.waitFor({ state: "visible", timeout: seconds * 1000 }).then(() => true, () => false)) {
    await s.click(ok); await s.wait(0.6);
  }
};
const row = (s) => s.page.locator("#tbody-owed tr").filter({ hasText: "PROMETAL" }).first();
const cell = (s, n) => row(s).locator("td").nth(n);
const kpi = (s, type) => s.page.locator(`#kpi-ribbon [data-kpi-type="${type}"]`);
const tab = async (s, name) => { await s.click(s.page.locator(`#tab-${name}`)); await s.wait(1); };
/** PROMETAL's latest CRE token (the client's shot opens its link). */
const creToken = () => {
  const rows = ground.sql("SELECT d.token FROM cre_documents d JOIN clients c ON c.id = d.client_id WHERE c.name = 'PROMETAL' ORDER BY d.id DESC LIMIT 1");
  if (!rows.length) throw new Error("PROMETAL has no CRE on the copy: film c03s01 first.");
  return rows[0][0];
};

module.exports = {
  id: "gestion-des-vides",
  file: "LPC-ERP-Gestion-Des-Vides",
  meta: { title: "LPC ERP · Gestion des Vides (Modules, partie 7)", artist: "JBS Praxis" },
  library: {
    slug: "gestion-des-vides",
    title: "Gestion des Vides",
    subtitle: "Ce que chaque client doit rendre, la collecte, et la revente aux recycleurs",
    series: "Modules",
    part: 7,
    parts: 15,
    pages: [PAGE.vides],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 7",
    title: "Gestion des Vides",
    subtitle: "Ce que chaque client doit rendre, la collecte, et la revente aux recycleurs",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 7",
    title: "Merci",
    subtitle: "À suivre : Facturation & AR",
    left: "bureau.lpc.cm",
  },
  holders: { operations: "Bertin Chadeu" },

  async prepare({ log, ground: g }) {
    g.restore("pre-07-film");
    log("ground: pre-07-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Gestion des Vides : les bonbonnes qui reviennent des clients.",
          "LPC vend la bonbonne, sans consigne. Le devis signé fixe la part que le client doit rendre : pour PROMETAL, au moins 80 %.",
          "Les vides récupérés sont revendus aux recycleurs : c'est un second revenu.",
          "Nous suivons le Responsable des Opérations : ce que les clients doivent, une collecte chez PROMETAL, et une vente au recycleur.",
        ],
      }],
    },

    {
      name: "Ce que les clients doivent",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.vides,
        lines: [
          { say: "Le Responsable des Opérations ouvre Gestion des Vides, dans Logistique & Stock.",
            do: (s) => h.soft(s, h.shell.menu(s, "Gestion des Vides")) },
          { say: "Quatre onglets : les Dus, la Collecte, la Vente au recycleur et ses Revenus.",
            do: (s) => h.tour(s, ["owed", "new", "recycling", "revenue"].map((t) => s.page.locator(`#tab-${t}`)), 0.35) },
          { say: "La première carte dit ce qu'il reste à récupérer chez les clients : 160 bonbonnes.",
            speech: "La première carte dit ce qu'il reste à récupérer chez les clients : cent soixante bonbonnes.",
            do: (s) => h.soft(s, kpi(s, "owed")) },
          { say: "Les autres cartes : les vides en route sur des BL pas encore signés, les CRE à signer, les retours du mois, et le revenu du recyclage.",
            do: (s) => h.tour(s, ["inflight", "pending", "signed30", "revenue30"].map((t) => kpi(s, t)), 0.35) },
          { say: "Le tableau donne une ligne par client. PROMETAL a reçu 200 bonbonnes de 20 litres, signées sur son BL.",
            speech: "Le tableau donne une ligne par client. PROMETAL a reçu deux cents bonbonnes de vingt litres, signées sur son BL.",
            do: (s) => h.soft(s, cell(s, 2)) },
          { say: "Son taux de retour est à zéro, face à son minimum de 80 %.",
            speech: "Son taux de retour est à zéro, face à son minimum de quatre-vingts pour cent.",
            do: (s) => h.soft(s, cell(s, 4)) },
          { say: "À récupérer : 160. C'est la dette de PROMETAL en vides.",
            speech: "À récupérer : cent soixante. C'est la dette de PROMETAL en vides.",
            do: (s) => h.soft(s, cell(s, 5)) },
          { say: "Toléré : 40. Ce sont les bonbonnes que son contrat lui permet de garder. Elles ne sont pas une dette.",
            speech: "Toléré : quarante. Ce sont les bonbonnes que son contrat lui permet de garder. Elles ne sont pas une dette.",
            do: (s) => h.soft(s, cell(s, 6)) },
          { say: "Sous le nom du client, l'ERP rappelle son minimum, et s'il est atteint.",
            do: (s) => h.soft(s, cell(s, 0)) },
          { say: "Un client sans devis signé doit rendre toutes ses bonbonnes : son minimum est de 100 %.",
            speech: "Un client sans devis signé doit rendre toutes ses bonbonnes : son minimum est de cent pour cent." },
          { say: "À côté des Dus, l'Historique garde chaque collecte.",
            do: (s) => h.soft(s, s.page.locator("#owed-sub-history")) },
        ],
      }],
    },

    {
      name: "La collecte",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.vides, snapshot: true,
        lines: [
          "Le chauffeur est passé chez PROMETAL et a ramené des vides.",
          { say: "« Collecter », sur la ligne de PROMETAL : le formulaire s'ouvre, le client déjà choisi.",
            do: async (s) => { await s.click(row(s).getByRole("button", { name: /Collecter/ })); await s.wait(1); } },
          "Le site est facultatif : un retour réduit toujours ce que doit le client.",
          { say: "Il compte 120 bonbonnes de 20 litres sans bouchon,",
            speech: "Il compte cent vingt bonbonnes de vingt litres sans bouchon,",
            do: (s) => s.type(s.page.locator(`#qty_${P20_NOCORK}`), "120", { delay: 140 }) },
          { say: "et 10 avec leur bouchon. Toutes comptent pour la dette en 20 litres.",
            speech: "et dix avec leur bouchon. Toutes comptent pour la dette en vingt litres.",
            do: (s) => s.type(s.page.locator(`#qty_${P20_CORK}`), "10", { delay: 160 }) },
          { say: "Les bonbonnes de 10 litres ont aussi leurs deux cases, avec et sans bouchon.",
            speech: "Les bonbonnes de dix litres ont aussi leurs deux cases, avec et sans bouchon.",
            do: (s) => h.soft(s, s.page.locator("#qty_7")) },
          { say: "« Générer le CRE », le Certificat de Restitution d'Emballages.",
            do: async (s) => { await s.click(s.page.locator("button[onclick='generateCRE()']").first()); await s.wait(1.5); } },
          { say: "LPC signe d'abord, à son nom : c'est le comptage du Responsable des Opérations.",
            do: (s) => h.soft(s, s.page.locator("#cre_sign_modal_body")) },
          { say: "« Signer maintenant ». Les 130 vides entrent en stock et sont déduits de ce que doit PROMETAL.",
            speech: "« Signer maintenant ». Les cent trente vides entrent en stock et sont déduits de ce que doit PROMETAL.",
            do: async (s) => { await s.click(s.page.locator("#cre_sign_confirm_btn")); await s.wait(2); } },
          { say: "Le client reçoit ensuite le CRE : un QR code à scanner, ou le lien par WhatsApp.",
            do: (s) => h.soft(s, s.page.locator("#qrcode")) },
        ],
      }],
    },

    {
      name: "PROMETAL signe le CRE",
      shots: [{
        kind: "desktop", snapshot: true,
        setup: async (s) => { await s.go(`/sign_cre.php?token=${creToken()}`); await s.wait(1.2); },
        lines: [
          "Voici ce que PROMETAL ouvre, sans compte à créer. Pour la formation, nous signons à sa place.",
          { say: "Il voit les quantités que LPC a comptées : 120 sans bouchon, 10 avec.",
            speech: "Il voit les quantités que LPC a comptées : cent vingt sans bouchon, dix avec.",
            do: (s) => h.soft(s, s.page.getByText(/Total/).first()) },
          { say: "S'il n'est pas d'accord, « Il y a une erreur de quantité » : le comptage de LPC est annulé et le Responsable des Opérations recompte avec lui.",
            do: (s) => h.soft(s, s.page.locator("#show_reject_btn")) },
          { say: "Ici, le compte est juste. Le réceptionnaire donne son nom et sa fonction ; le téléphone de PROMETAL est déjà là.",
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
          { say: "« Confirmer et Signer ». Le CRE porte maintenant les deux signatures.",
            do: async (s) => { await s.click(s.page.locator("#sig_submit_btn")); await s.wait(2.5); } },
        ],
      }],
    },

    {
      name: "Le retour compté",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.vides,
        lines: [
          { say: "Retour aux Dus. PROMETAL a rendu 130 bonbonnes : son taux de retour est de 65 %.",
            speech: "Retour aux Dus. PROMETAL a rendu cent trente bonbonnes : son taux de retour est de soixante-cinq pour cent.",
            do: (s) => h.soft(s, cell(s, 4)) },
          { say: "Il reste 30 bonbonnes à récupérer pour atteindre son minimum. Les 40 tolérées ne bougent pas.",
            speech: "Il reste trente bonbonnes à récupérer pour atteindre son minimum. Les quarante tolérées ne bougent pas.",
            do: (s) => h.soft(s, cell(s, 5)) },
          { say: "La carte « À récupérer » suit : 30.",
            speech: "La carte « À récupérer » suit : trente.",
            do: (s) => h.soft(s, kpi(s, "owed")) },
          { say: "L'Historique liste chaque collecte, avec qui l'a faite. Ce CRE est « Compté ».",
            do: async (s) => { await s.click(s.page.locator("#owed-sub-history")); await s.wait(1.2); await h.soft(s, s.page.locator("#tbody-history tr").first()); } },
          { say: "« Lien client » renvoie le CRE à PROMETAL, s'il a perdu le message.",
            do: (s) => h.soft(s, s.page.locator("#tbody-history tr").first().getByRole("button", { name: /Lien client/ })) },
          { say: "Son PDF porte la signature de LPC et celle du client.",
            do: (s) => h.soft(s, s.page.locator("#tbody-history tr").first().getByRole("link", { name: /PDF/ })) },
          "Un CRE que LPC n'a pas encore signé peut être annulé ici, avec un motif.",
        ],
      }, {
        kind: "desktop", as: "bertin", role: "operations",
        setup: async (s) => { await s.go(`/print_cre.php?token=${creToken()}`); await s.wait(1.5); },
        lines: [
          { say: "Le CRE se télécharge en PDF : le client, l'intervenant de LPC, et les bonbonnes rendues.",
            do: (s) => h.soft(s, s.page.getByText(/TOTAL RESTITU/i).first()) },
          { say: "En bas, les deux signatures : celle de LPC, avec son empreinte, et celle de PROMETAL.",
            do: (s) => s.scroll(500) },
          "PROMETAL en garde une copie : c'est la preuve de ce qu'il a rendu.",
        ],
      }],
    },

    {
      name: "La vente au recycleur",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.vides, snapshot: true,
        lines: [
          { say: "Les vides en stock se revendent. Onglet « Vente Recyclage ».",
            do: (s) => tab(s, "recycling") },
          { say: "Le lieu : le centre de recyclage de Bonabéri.",
            do: (s) => s.type(s.page.locator("#recycler_location"), "Centre de recyclage, Bonabéri", { delay: 50 }) },
          { say: "Le compte qui reçoit l'argent : la caisse principale.",
            do: (s) => h.soft(s, s.page.locator("#rec_treasury_account")) },
          { say: "Le prix de rachat dépend du bouchon : 250 FCFA avec, 200 FCFA sans.",
            speech: "Le prix de rachat dépend du bouchon : deux cent cinquante francs avec, deux cents francs sans.",
            do: (s) => h.soft(s, s.page.locator(`#price_${P20_CORK}`)) },
          { say: "Chaque type de vide montre son prix catalogue et ce qui est en stock : 120 bonbonnes de 20 litres sans bouchon.",
            speech: "Chaque type de vide montre son prix catalogue et ce qui est en stock : cent vingt bonbonnes de vingt litres sans bouchon.",
            do: (s) => h.soft(s, s.page.locator(`#rec_${P20_NOCORK}`)) },
          { say: "Il en vend 100, à 200 FCFA : 20 000 FCFA attendus en caisse.",
            speech: "Il en vend cent, à deux cents francs : vingt mille francs attendus en caisse.",
            do: async (s) => { await s.type(s.page.locator(`#rec_${P20_NOCORK}`), "100", { delay: 150 }); await s.page.locator(`#rec_${P20_NOCORK}`).dispatchEvent("input"); } },
          "On ne vend jamais plus que le stock : l'ERP refuse.",
          "Un prix négocié avec le recycleur se saisit avec le crayon, et un motif.",
          { say: "« Valider la Vente au Recycleur ».",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-recycling")); await s.wait(1.5); } },
          { say: "Les 100 vides sortent du stock. Les 20 000 FCFA entrent dans la caisse, en produit au compte 7074.",
            speech: "Les cent vides sortent du stock. Les vingt mille francs entrent dans la caisse, en produit au compte sept zéro sept quatre.",
            do: (s) => okIf(s) },
        ],
      }],
    },

    {
      name: "Les revenus du recyclage",
      shots: [{
        kind: "desktop", as: "bertin", role: "operations", start: PAGE.vides,
        lines: [
          { say: "L'onglet « Revenus Recyclage » totalise les ventes de la période : 20 000 FCFA cette année.",
            speech: "L'onglet « Revenus Recyclage » totalise les ventes de la période : vingt mille francs cette année.",
            do: async (s) => { await tab(s, "revenue"); await s.wait(1); await h.soft(s, s.page.locator("#kpi_rev_total")); } },
          { say: "À côté, les bonbonnes vendues, par taille et par bouchon.",
            do: (s) => h.soft(s, s.page.locator("#stat_20n")) },
          { say: "Le journal des ventes : la date, qui a vendu, le recycleur, le montant.",
            do: (s) => h.soft(s, s.page.locator("#tbody-revenue tr").first()) },
          { say: "Plus bas, le registre des prix négociés : qui a changé un prix, de combien, et pourquoi.",
            do: (s) => s.scroll(500) },
          "Le Comptable et le Contrôleur Financier travaillent aussi dans Gestion des Vides : ils peuvent collecter et vendre.",
          { say: "La vente passe aussi en Trésorerie : la caisse principale a reçu les 20 000 FCFA.",
            speech: "La vente passe aussi en Trésorerie : la caisse principale a reçu les vingt mille francs." },
          { say: "Et le Grand Livre la porte au compte 7074, en face de la caisse.",
            speech: "Et le Grand Livre la porte au compte sept zéro sept quatre, en face de la caisse." },
        ],
      }, {
        kind: "desktop", as: "bertin", role: "operations", start: "/modules/inventory/stock.php",
        lines: [
          { say: "Dans Stock & Emballages, les vides sont des produits comme les autres : il reste 20 bonbonnes sans bouchon et 10 avec.",
            speech: "Dans Stock et Emballages, les vides sont des produits comme les autres : il reste vingt bonbonnes sans bouchon et dix avec.",
            do: (s) => h.soft(s, s.page.locator("#tbody-stock tr").filter({ hasText: "20L sans bouchon" }).first()) },
          "Leur Historique montre l'entrée par le CRE et la sortie vers le recycleur.",
          "Comme les bonbonnes pleines, les vides se comptent à l'inventaire, et un écart s'y corrige.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : chaque client doit rendre au moins la part que son devis fixe.",
          "Le Responsable des Opérations compte les vides et signe le CRE ; le client le reconnaît ou le conteste.",
          "Les vides en stock se revendent aux recycleurs, et l'argent entre en caisse.",
          "Chaque bonbonne rendue compte deux fois : pour le contrat du client, et pour le revenu du recyclage.",
          "La prochaine vidéo facture PROMETAL et suit son paiement : Facturation & AR.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
