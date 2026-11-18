"use strict";
/**
 * Modules, partie 8 : Facturation & AR. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * The Comptable (Daniel Massomba on screen) invoices PROMETAL's signed delivery, grants an avoir for bottles
 * found pierced after delivery, records PROMETAL's transfer net of AIR, and records its withholding
 * attestation. Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-08-film = after-05-ventes + migrations up to 153. PROMETAL (client 4, account 411035,
 * Net 15, AIR 2,2 %) signed BL-2611-00001 (200 OPUR 20L at 2 275 F = 455 000 F), not invoiced. The scene
 * writes: FAC-2611-00001 (18/11/2026, due 03/12), the avoir AV-2611-00001 (4 bottles, 9 100 F HT), PROMETAL's
 * transfer to CCA Bank (436 090 F, AIR 9 810 F), the attestation (an example file the narration says is one).
 */
const fs = require("node:fs");
const path = require("node:path");
const h = require("../helpers");

const PAGE = {
  inv: "/modules/accounting/invoices.php",
  je: "/modules/accounting/journal_entry.php",
  gl: "/modules/accounting/ledger.php",
  tax: "/modules/accounting/tax_declarations.php",
};
const ATTEST = "Attestation-retenue-AIR-PROMETAL-novembre-2026-EXEMPLE.pdf";

const modal = (s) => s.page.locator("dialog[open]").last();
const press = async (s, label) => { await s.click(modal(s).getByRole("button", { name: label })); await s.wait(0.6); };
const invTab = (s, id) => s.click(s.page.locator(`#tab-${id}`));
const invRow = (s) => s.page.locator("#table-body-invoices tr").filter({ hasText: "FAC-2611-00001" }).first();
const token = (ground) => ground.sql("SELECT token FROM invoices WHERE reference = 'FAC-2611-00001'")[0][0];

module.exports = {
  id: "facturation-ar",
  file: "LPC-ERP-Facturation-Et-AR",
  meta: { title: "LPC ERP · Facturation & AR (Modules, partie 8)", artist: "JBS Praxis" },
  library: {
    slug: "facturation-ar",
    title: "Facturation & AR",
    subtitle: "La facture d'un BL signé, l'avoir, le paiement net de l'AIR et l'attestation",
    series: "Modules",
    part: 8,
    parts: 15,
    pages: [PAGE.inv, PAGE.tax, PAGE.je, PAGE.gl],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 8",
    title: "Facturation & AR",
    subtitle: "La facture, l'avoir, le paiement et l'attestation",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 8",
    title: "Merci",
    subtitle: "À suivre : Comptabilité 1",
    left: "bureau.lpc.cm",
  },
  holders: { comptable: "Daniel Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-08-film");
    // The attestation PROMETAL sends: an example file (the narration says so).
    const dir = path.join(ground.HOME, "assets");
    fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(path.join(dir, ATTEST),
      "%PDF-1.4\n% EXEMPLE pour la formation\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    log("ground: pre-08-film restored, example attestation written");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la facturation de LPC ERP : l'argent que les clients doivent à LPC.",
          "Le Comptable facture les livraisons signées, accorde les avoirs, enregistre les paiements et les attestations de retenue.",
          "Nous suivons PROMETAL : sa facture, un avoir, son virement net de l'AIR, et son attestation.",
        ],
      }],
    },

    {
      name: "La page",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.inv,
        lines: [
          { say: "Le Comptable ouvre Facturation & AR, dans Comptabilité & Finance.",
            do: (s) => h.soft(s, h.shell.menu(s, "Facturation & AR")) },
          "La page est celle de la finance : le Comptable y travaille, le Contrôleur Financier et le Directeur Général la suivent.",
          { say: "Quatre onglets : la Balance Âgée, les BL Non Facturés, les Factures & Caisse, et les Trop Perçus.",
            do: (s) => h.tour(s, ["#tab-dashboard", "#tab-to_invoice", "#tab-invoices_payments", "#tab-wallets"].map((id) => s.page.locator(id)), 0.4) },
          { say: "La Balance Âgée est vide : aucune facture n'est encore émise. Un BL attend d'être facturé.",
            do: (s) => h.tour(s, [s.page.locator("#kpi_wallets").locator("xpath=ancestor::div[contains(@class,'rounded-2xl')][1]").first(), s.page.locator("#tab-to_invoice")], 0.5) },
        ],
      }],
    },

    {
      name: "La facture",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.inv + "#to_invoice", snapshot: true,
        lines: [
          { say: "BL Non Facturés : les bons de livraison signés par le client. Voici celui de PROMETAL, 200 bonbonnes OPUR de 20 litres.",
            do: async (s) => { await invTab(s, "to_invoice"); await s.wait(1); await h.soft(s, s.page.locator("tr").filter({ hasText: "BL-2611-00001" }).first()); } },
          { say: "Il le sélectionne, puis clique « Créer la Facture ».",
            do: async (s) => { await s.click(s.page.locator("tr").filter({ hasText: "BL-2611-00001" }).first()); await s.wait(0.8); await s.click(s.page.getByRole("button", { name: /Créer la Facture/ })); await s.wait(1.5); } },
          { say: "L'échéance suit les conditions signées par PROMETAL : Net 15, le 3 décembre.",
            do: (s) => h.soft(s, s.page.locator("#gen_due_hint")) },
          { say: "La date d'émission est celle du jour. Le numéro de la facture se suivra sans trou : FAC, l'année et le mois, puis le rang.",
            do: (s) => h.soft(s, s.page.locator("#gen_date")) },
          { say: "La TVA : 0 %, l'eau est exonérée. Le paiement est attendu sur CCA Bank.",
            do: (s) => h.tour(s, [s.page.locator("#gen_tva"), s.page.locator("#gen_payment_accounts")], 0.6) },
          { say: "L'aperçu : 455 000 FCFA. PROMETAL retient l'AIR, 2,2 % : 10 010 FCFA. Il virera 444 990 FCFA.",
            speech: "L'aperçu : quatre cent cinquante-cinq mille francs. PROMETAL retient l'AIR, deux virgule deux pour cent : dix mille dix francs. Il virera quatre cent quarante-quatre mille neuf cent quatre-vingt-dix francs.",
            do: (s) => h.tour(s, [s.page.locator("#prev_grandtotal"), s.page.locator("#prev_air_amount"), s.page.locator("#prev_net_transfer")], 0.6) },
          { say: "« Valider & Générer PDF ». La facture FAC-2611-00001 est émise.",
            speech: "« Valider et Générer PDF ». La facture F A C deux six onze, zéro zéro zéro zéro un, est émise.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-invoice")); await s.wait(2.5); const ok = modal(s).getByRole("button", { name: "OK" }); if (await ok.isVisible().catch(() => false)) await press(s, "OK"); } },
        ],
      }],
    },

    {
      name: "Ce que reçoit le client",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.inv,
        setup: async (s) => { await s.go("/facture.php?token=" + token(require("../ground"))); },
        lines: [
          { say: "Voici la facture telle que PROMETAL la reçoit : le BL, les 200 bonbonnes à 2 275 FCFA.",
            speech: "Voici la facture telle que PROMETAL la reçoit : le BL, les deux cents bonbonnes à deux mille deux cent soixante-quinze francs.",
            do: (s) => h.soft(s, s.page.getByText("OPUR 20L").first()) },
          { say: "En bas : la retenue AIR, et le net à virer après retenue. Puis le compte CCA Bank où virer.",
            do: async (s) => { await s.scroll(700); await h.tour(s, [s.page.locator("#dyn_air_amount"), s.page.locator("#dyn_net_payable"), s.page.locator("#dyn_bank_block")], 0.6); } },
          { say: "« Email » ou « WhatsApp » l'envoient au client ; « Télécharger PDF » en fait un fichier.",
            do: async (s) => { await s.scroll(-700); await h.tour(s, ["Email", "WhatsApp", "Télécharger PDF"].map((n) => s.page.getByRole("button", { name: new RegExp(n) }).first()), 0.4); } },
          { say: "« Faire accuser réception » demande au client de signer qu'il a bien reçu la facture.",
            do: (s) => h.soft(s, s.page.getByRole("button", { name: /Faire accuser réception/ }).first()) },
          "Le lien de la facture est propre à ce document : PROMETAL l'ouvre sans compte dans l'ERP.",
        ],
      }],
    },

    {
      name: "L'écriture",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.je + "#journal",
        // Opened on the invoice: the whole month also lists payroll entries.
        setup: async (s) => { await s.page.locator("#jr_q").fill("FAC-2611-00001"); await s.settle(1500); },
        lines: [
          { say: "Le Comptable n'a rien saisi : l'écriture est déjà au journal, d'origine Facturation.",
            do: (s) => h.soft(s, s.page.locator("#table-body-journal tr").first().locator("td").nth(3)) },
          { say: "Au débit, le compte de PROMETAL, 411035 : ce qu'il virera. Et 4424 : l'AIR qu'il versera aux impôts pour LPC.",
            speech: "Au débit, le compte de PROMETAL, quatre un un zéro trois cinq : ce qu'il virera. Et quatre quatre deux quatre : l'AIR qu'il versera aux impôts pour L P C.",
            do: (s) => h.soft(s, s.page.locator("#table-body-journal tr").first().locator("td").last()) },
          "Au crédit, les ventes d'eau, 7011, pour 455 000 FCFA.",
        ],
      }],
    },

    {
      name: "Un avoir",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.inv + "#invoices_payments", snapshot: true,
        lines: [
          "Pour l'exemple : PROMETAL signale 4 bonbonnes percées, découvertes après la livraison. LPC accorde un avoir.",
          { say: "Factures & Caisse : sur la facture, le bouton « Avoir ».",
            do: async (s) => { await invTab(s, "invoices_payments"); await s.wait(1); await s.click(invRow(s).getByRole("button", { name: "Avoir" })); await s.wait(0.8); } },
          { say: "Le montant hors taxe : 4 bonbonnes à 2 275, soit 9 100 FCFA. Le motif figure sur l'écriture.",
            speech: "Le montant hors taxe : quatre bonbonnes à deux mille deux cent soixante-quinze, soit neuf mille cent francs. Le motif figure sur l'écriture.",
            do: async (s) => { await s.type(s.page.locator("#cn_amount_ht"), "9100", { delay: 120 }); await s.type(s.page.locator("#cn_reason"), "4 bonbonnes percées constatées après la livraison", { delay: 35 }); } },
          { say: "PROMETAL doit 9 100 FCFA de moins, et ne retiendra pas l'AIR sur cette part : 200 FCFA.",
            speech: "PROMETAL doit neuf mille cent francs de moins, et ne retiendra pas l'AIR sur cette part : deux cents francs.",
            do: (s) => h.tour(s, [s.page.locator("#cn_total"), s.page.locator("#cn_air")], 0.6) },
          { say: "« Émettre l'avoir ». Le reste dû tombe à 445 900 FCFA.",
            speech: "« Émettre l'avoir ». Le reste dû tombe à quatre cent quarante-cinq mille neuf cents francs.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-credit-note")); await s.wait(1.5); await h.soft(s, modal(s)); } },
          { say: "Son écriture passe seule : débit 7019, les réductions accordées ; crédit du compte de PROMETAL.",
            speech: "Son écriture passe seule : débit sept zéro un neuf, les réductions accordées ; crédit du compte de PROMETAL.",
            do: async (s) => { await press(s, "OK"); await s.wait(1); } },
        ],
      }],
    },

    {
      name: "Le paiement",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.inv + "#invoices_payments", snapshot: true,
        lines: [
          "PROMETAL paie par virement, le net, après sa retenue. Le Comptable voit l'argent arriver sur CCA Bank.",
          { say: "Sur la facture, le bouton « + » : encaisser un paiement.",
            do: async (s) => { await invTab(s, "invoices_payments"); await s.wait(1); await s.click(invRow(s).locator("button[title='Encaisser un paiement']")); await s.wait(1.8); } },
          { say: "Tout est pré-rempli : le reste dû moins l'AIR, 436 090 FCFA, et l'AIR retenu, 9 810.",
            speech: "Tout est pré-rempli : le reste dû moins l'AIR, quatre cent trente-six mille quatre-vingt-dix francs, et l'AIR retenu, neuf mille huit cent dix.",
            do: async (s) => { await h.soft(s, s.page.locator("#pay_amount")); await s.wait(0.4); await s.page.locator("#pay_air_withheld").scrollIntoViewIfNeeded(); await h.soft(s, s.page.locator("#pay_air_withheld")); } },
          { say: "La méthode : virement bancaire. Le compte qui reçoit : CCA Bank.",
            do: async (s) => { await s.page.locator("#pay_method").scrollIntoViewIfNeeded(); await s.hover(s.page.locator("#pay_method")); await s.page.locator("#pay_method").selectOption("bank"); await s.wait(0.6); await h.soft(s, s.page.locator("#pay_account_id")); } },
          "Un paiement partiel laisserait la facture « Partiel », avec son reste dû, jusqu'au paiement suivant.",
          { say: "« Enregistrer & Imputer ». La facture est payée.",
            speech: "« Enregistrer et Imputer ». La facture est payée.",
            do: async (s) => { await s.click(s.page.locator("#btn-submit-payment")); await s.wait(2.2); const ok = modal(s).getByRole("button", { name: "OK" }); if (await ok.isVisible().catch(() => false)) await press(s, "OK"); await s.wait(1); await h.soft(s, invRow(s)); } },
          { say: "Le journal de caisse garde chaque mouvement : l'avoir et le virement.",
            do: (s) => h.soft(s, s.page.locator("#table-body-payments tr").first()) },
        ],
      }],
    },

    {
      name: "Le compte du client",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.gl + "?account=411035#grandlivre",
        lines: [
          { say: "Au Grand Livre, le compte de PROMETAL, 411035.",
            speech: "Au Grand Livre, le compte de PROMETAL, quatre un un zéro trois cinq.",
            do: (s) => h.soft(s, s.page.locator("#gl_account_select"), 6000) },
          { say: "La facture au débit ; l'avoir et le virement au crédit. Le solde : zéro, PROMETAL ne doit plus rien.",
            do: async (s) => { await h.tour(s, [s.page.locator("#tbody-gl tr").nth(1), s.page.locator("#tbody-gl tr").nth(2), s.page.locator("#tbody-gl tr").nth(3)], 0.5); await h.soft(s, s.page.locator("#gl_current_balance")); } },
          { say: "La Balance des Tiers le confirme : la ligne de PROMETAL est soldée, rien à recevoir.",
            do: async (s) => { await s.click(s.page.locator("#tab-tiers")); await s.wait(1.5); await h.soft(s, s.page.locator("#tbody-tiers tr").filter({ hasText: "411035" }).first()); } },
          { say: "Et 4424 garde l'AIR que PROMETAL a retenu, une seule fois : 9 810 FCFA, à déduire des impôts de LPC.",
            speech: "Et quatre quatre deux quatre garde l'AIR que PROMETAL a retenu, une seule fois : neuf mille huit cent dix francs, à déduire des impôts de L P C.",
            do: async (s) => { await s.click(s.page.locator("#tab-grandlivre")); await s.wait(1); const opt = await s.page.locator("#gl_account_select option").filter({ hasText: /^\[4424\]/ }).first().getAttribute("value"); await s.hover(s.page.locator("#gl_account_select")); await s.page.locator("#gl_account_select").selectOption(opt); await s.wait(1.8); await h.soft(s, s.page.locator("#gl_current_balance")); } },
        ],
      }],
    },

    {
      name: "L'attestation",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.tax, snapshot: true,
        lines: [
          { say: "Dès le paiement, Déclarations Fiscales signale l'AIR en attente d'attestation : 9 810 FCFA pour PROMETAL, novembre.",
            speech: "Dès le paiement, Déclarations Fiscales signale l'AIR en attente d'attestation : neuf mille huit cent dix francs pour PROMETAL, novembre.",
            do: (s) => h.soft(s, s.page.getByText(/en attente d.attestation/i).first(), 6000) },
          { say: "« Demander (PDF) » prépare la lettre qui la réclame au client.",
            do: (s) => h.soft(s, s.page.getByRole("link", { name: /Demander \(PDF\)/ }).first()) },
          "PROMETAL remet son attestation de retenue. Celle que nous chargeons est un exemple.",
          { say: "Onglet « Retenues à la source ».",
            do: async (s) => { await s.click(s.page.locator("button, a").filter({ hasText: /Retenues à la source/ }).first()); await s.wait(1.5); } },
          { say: "Le client, le numéro de l'attestation, sa date.",
            do: async (s) => {
              const form = s.page.locator("form").filter({ has: s.page.locator("input[type=file]") }).first();
              await s.hover(form.locator("select").first()); await form.locator("select").first().selectOption({ label: "PROMETAL" }); await s.wait(0.4);
              const inputs = form.locator("input:not([type=hidden]):not([type=file])");
              await s.type(inputs.nth(0), "ATT-PROMETAL-2611", { delay: 60 });
              await inputs.nth(1).fill("2026-11-30"); await s.wait(0.4);
            } },
          { say: "Le mois, novembre, le montant, 9 810 FCFA, et le fichier.",
            speech: "Le mois, novembre, le montant, neuf mille huit cent dix francs, et le fichier.",
            do: async (s) => {
              const form = s.page.locator("form").filter({ has: s.page.locator("input[type=file]") }).first();
              const inputs = form.locator("input:not([type=hidden]):not([type=file])");
              await inputs.nth(3).fill(""); await s.type(inputs.nth(3), "11", { delay: 80 });
              await s.type(inputs.nth(4), "9810", { delay: 90 });
              await form.locator("input[type=file]").setInputFiles(path.join(require("../ground").HOME, "assets", ATTEST)); await s.wait(0.6);
            } },
          { say: "« Enregistrer ». L'attestation est rattachée au paiement de PROMETAL ; l'AIR de novembre est justifié.",
            do: async (s) => { const form = s.page.locator("form").filter({ has: s.page.locator("input[type=file]") }).first(); await s.click(form.getByRole("button", { name: /Enregistrer/i })); await s.wait(2); await h.soft(s, s.page.getByText("ATT-PROMETAL-2611").first()); } },
        ],
      }],
    },

    {
      name: "Le suivi",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.inv,
        lines: [
          { say: "Retour à la Balance Âgée. Les cartes : le total des créances, les impayés de plus de 30 jours, les trop-perçus.",
            do: (s) => h.tour(s, [s.page.getByText(/Total créances/i).first(), s.page.getByText(/Impayés > 30/i).first(), s.page.locator("#kpi_wallets")], 0.5) },
          { say: "Le graphique range ce que les clients doivent par ancienneté : en cours, 30, 60, 90 jours et plus.",
            do: (s) => h.soft(s, s.page.locator("#agingChart")) },
          { say: "Dessous, « Factures en retard » : chaque facture dont l'échéance est passée, avec son retard.",
            do: async (s) => { await s.scroll(900); await h.soft(s, s.page.locator("#dash-overdue-body")); } },
          "« Relancer » ouvre la facture, à renvoyer au client par Email ou WhatsApp. Aujourd'hui, rien n'est en retard.",
          { say: "Enfin, « Trop Perçus » garde ce qu'un client a payé en trop, à utiliser sur sa prochaine facture.",
            do: async (s) => { await s.scroll(-900); await h.soft(s, s.page.locator("#tab-wallets")); } },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : une facture se fait depuis un BL signé, au compte du client, avec son AIR.",
          "Un avoir réduit ce qu'il doit ; le paiement net de l'AIR solde son compte ; l'attestation justifie la retenue.",
          "La prochaine vidéo, Comptabilité 1, montre le plan comptable et le Grand Livre où ces écritures arrivent.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
