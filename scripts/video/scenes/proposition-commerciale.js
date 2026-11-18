"use strict";
/**
 * Modules, partie 4 : Proposition commerciale (Base Clients & Devis, Studio Proposition).
 * docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * The Commercial (today the Directeur Général, Timothée Massomba on screen, until a Sales manager is
 * hired) reads the client base, creates PROMETAL with its real public details (address and phone from
 * public directories; the purchasing address is an example on example.com), writes its proposal
 * (OPUR 20L, 1 667 a month at the default price, Net 15, 80 % minimum return of empties, 2 weeks of
 * buffer), reads it as the client will, and shares the signing link. PROMETAL signs through the public
 * link (signed out; for the training the signature is made on the client's behalf, and the video says
 * so). The Commercial then reads what the signature carried onto the client (VIDEO_QA_FINDINGS 4.1):
 * its tariff, its payment terms, the empties minimum, its status, and the stock alert the promised
 * buffer raised (3.6). Last, the Studio Proposition. Narrated by role (runbook §2).
 *
 * Ground: snapshot pre-04-fixed (after-03-stock + migrations 136 to 138): 255 OPUR 20L, no PROMETAL.
 * The scene writes PROMETAL (client 4), DEV-2611-00005 and its signature: the copy is then snapshot
 * after-04-proposition for Ventes & Commandes.
 */
const h = require("../helpers");
const ground = require("../ground");

const PAGE = {
  clients: "/modules/crm/clients.php",
  studio: "/modules/crm/proposal_studio.php",
  stock: "/modules/inventory/stock.php",
};

const row = (s, name) => s.page.locator("#clients-tbody tr").filter({ hasText: name }).first();
const card = (s, metric) => s.page.locator(`.lpc-kpi-card[data-metric="${metric}"]`).first();
const visibleButton = (s, name) => s.page.getByRole("button", { name }).filter({ visible: true }).last();
const choose = async (s, select, value) => {
  await s.hover(s.page.locator(select));
  await s.page.locator(select).selectOption(value);
  await s.wait(0.6);
};
/** The token of PROMETAL's devis, read from the copy (the shot that signs opens its public link). */
const prometalToken = () => {
  const rows = ground.sql(
    "SELECT p.token FROM proposals p JOIN clients c ON c.id = p.client_id WHERE c.name = 'PROMETAL' ORDER BY p.id DESC LIMIT 1"
  );
  if (!rows.length) throw new Error("PROMETAL has no devis on the copy: film c04s01 first.");
  return rows[0][0];
};

module.exports = {
  id: "proposition-commerciale",
  file: "LPC-ERP-Proposition-Commerciale",
  meta: { title: "LPC ERP · Proposition commerciale (Modules, partie 4)", artist: "JBS Praxis" },
  library: {
    slug: "proposition-commerciale",
    title: "Proposition commerciale",
    subtitle: "Un nouveau client, son devis, sa signature, et ce qu'elle change",
    series: "Modules",
    part: 4,
    parts: 15,
    pages: [PAGE.clients, PAGE.studio],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 4",
    title: "Proposition commerciale",
    subtitle: "Un nouveau client, son devis, sa signature, et ce qu'elle change",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 4",
    title: "Merci",
    subtitle: "À suivre : Ventes & Commandes",
    left: "bureau.lpc.cm",
  },
  holders: { commercial: "Timothée Massomba, Directeur Général" },

  async prepare({ log, ground: g }) {
    g.restore("pre-04-fixed");
    log("ground: pre-04-fixed restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la Proposition commerciale : comment LPC gagne un nouveau client.",
          "Nous suivons le Commercial. Aujourd'hui, c'est le Directeur Général qui tient ce rôle, en attendant un responsable des ventes.",
          "Le client de l'histoire est PROMETAL, à Douala : environ vingt mille bonbonnes de vingt litres par an.",
          "Au programme : la fiche du client, son devis, sa signature, et ce que la signature change dans l'application.",
        ],
      }],
    },

    {
      name: "La base clients",
      shots: [{
        kind: "desktop", as: "timothee", role: "commercial", start: PAGE.clients,
        lines: [
          { say: "Le Commercial ouvre Base Clients & Devis, dans Commerce & CRM.",
            do: (s) => h.soft(s, h.shell.menu(s, "Base Clients & Devis")) },
          { say: "Trois cartes : les clients actifs, ce qu'ils doivent en argent, et en emballages vides.",
            do: (s) => h.tour(s, [card(s, "clients"), card(s, "ar"), card(s, "empties")], 0.5) },
          "Aucune facture n'est encore émise : les deux dettes sont à zéro. Elles se remplissent avec les ventes.",
          { say: "Dans le répertoire, chaque client montre ses conditions, celles de son devis signé.",
            do: (s) => h.soft(s, row(s, "SMART LOGISTICS")) },
          "Ici : paiement à trente jours, et une bonbonne vide rendue pour chaque pleine.",
          { say: "Les colonnes de droite donnent ce que chaque client doit, en francs et en vides.",
            do: (s) => h.soft(s, s.page.locator("#clients-tbody tr").first().locator("td").nth(2)) },
          "PROMETAL n'est pas encore dans la liste. Le Commercial va la créer.",
        ],
      }],
    },

    {
      name: "Un nouveau client",
      shots: [{
        kind: "desktop", as: "timothee", role: "commercial", start: PAGE.clients, snapshot: true,
        lines: [
          { say: "Il clique sur « Nouveau Client ».",
            do: (s) => s.click(s.page.locator("[data-lpc-new]")) },
          { say: "Le nom de la société : PROMETAL. Un client entreprise.",
            do: (s) => s.type(s.page.locator("#new_client_name"), "PROMETAL", { delay: 90 }) },
          { say: "Le contact : son service des achats, et le standard de la société.",
            do: async (s) => {
              await s.type(s.page.locator("#new_client_contact"), "Service Achats", { delay: 50 });
              await s.type(s.page.locator("#new_client_phone"), "+237 233 37 85 85", { delay: 50 });
            } },
          { say: "Son adresse électronique ici est un exemple, pour la formation.",
            do: (s) => s.type(s.page.locator("#new_client_email"), "achats@prometal.example.com", { delay: 40 }) },
          { say: "L'adresse : la zone industrielle de Bassa, à Douala.",
            do: (s) => s.type(s.page.locator("#new_client_address"), "Zone Industrielle de Bassa, Logbaba, BP 3061 Douala", { delay: 35 }) },
          { say: "PROMETAL retient l'acompte d'impôt sur le revenu sur ses factures, et seulement lui. Il coche la case.",
            do: async (s) => { await s.click(s.page.locator("#new_client_is_wa")); await s.wait(0.5); } },
          { say: "Le taux du régime réel : 2,2 %. La facture le déduira toute seule.",
            speech: "Le taux du régime réel : deux virgule deux pour cent. La facture le déduira toute seule.",
            do: (s) => choose(s, "#new_client_wa_rate", "0.022") },
          "Le numéro contribuable et le registre de commerce s'ajoutent dès qu'on les a : il en faut un pour facturer.",
          { say: "« Enregistrer le Client ». La fiche reçoit son compte client, 411035.",
            speech: "« Enregistrer le Client ». La fiche reçoit son compte client, quatre cent onze zéro trente-cinq.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Enregistrer le client/i }).first()); await s.wait(1.2); } },
          { say: "PROMETAL entre dans la liste comme prospect : elle n'a encore rien signé.",
            do: async (s) => { await s.click(visibleButton(s, "OK")); await s.wait(0.8); await h.soft(s, row(s, "PROMETAL")); } },
        ],
      }],
    },

    {
      name: "Le devis",
      shots: [{
        kind: "desktop", as: "timothee", role: "commercial", start: PAGE.clients, snapshot: true,
        lines: [
          { say: "Sur la ligne de PROMETAL, il clique sur « Devis ».",
            do: (s) => s.click(row(s, "PROMETAL").getByRole("button", { name: "Devis" })) },
          { say: "La première partie fixe les engagements. Le devis est en français, valable trente jours.",
            do: (s) => h.soft(s, s.page.locator("#prop_validity")) },
          { say: "Livraison chaque semaine.",
            do: (s) => choose(s, "#prop_frequency", "Hebdomadaire") },
          { say: "Stock tampon : deux semaines. LPC garde d'avance deux semaines de bonbonnes pour ce client.",
            do: (s) => h.soft(s, s.page.locator("#prop_buffer")) },
          { say: "Le paiement : quinze jours après la facture.",
            do: (s) => choose(s, "#prop_payment", "Net 15 Jours") },
          { say: "Les emballages : la bouteille est vendue, sans consigne. PROMETAL s'engage à rendre au moins 80 % des vides.",
            speech: "Les emballages : la bouteille est vendue, sans consigne. PROMETAL s'engage à rendre au moins quatre-vingts pour cent des vides.",
            do: (s) => choose(s, "#prop_empties", "Retour Minimum Exigé (80%)") },
          "LPC revend ensuite ces vides au recycleur.",
          { say: "La seconde partie, ce sont les produits. Il cherche l'OPUR 20L.",
            speech: "La seconde partie, ce sont les produits. Il cherche l'OPUR vingt litres.",
            do: async (s) => {
              const input = s.page.locator("#proposal-items-tbody .item-row input[type=text], #proposal-items-tbody .item-row input[role=combobox]").first();
              await s.click(input);
              await s.type(input, "OPUR 20", { delay: 110, clear: false });
              await s.wait(0.8);
            } },
          { say: "La liste montre le stock, 255 bonbonnes, et le prix par défaut : 2 275 FCFA.",
            speech: "La liste montre le stock, deux cent cinquante-cinq bonbonnes, et le prix par défaut : deux mille deux cent soixante-quinze francs.",
            do: async (s) => { await s.wait(2.2); await s.key("Enter"); } },
          { say: "La quantité est mensuelle : 1 667 bonbonnes par mois, soit vingt mille par an.",
            speech: "La quantité est mensuelle : mille six cent soixante-sept bonbonnes par mois, soit vingt mille par an.",
            do: (s) => s.type(s.page.locator("#proposal-items-tbody .prod-qty").first(), "1667", { delay: 160 }) },
          "Un prix tapé sous le tarif du catalogue passerait en rouge. Ici, c'est le prix par défaut.",
          { say: "« Créer & Obtenir le Lien ». Le devis reçoit sa référence, DEV-2611-00005, et s'ouvre tel que le client le verra.",
            speech: "« Créer & Obtenir le Lien ». Le devis reçoit sa référence, devis deux mille six cent onze, cinq, et s'ouvre tel que le client le verra.",
            do: async (s) => {
              const ctx = s.page.context();
              const [tab] = await Promise.all([
                ctx.waitForEvent("page", { timeout: 15000 }),
                s.click(s.page.getByRole("button", { name: /Créer & Obtenir le Lien/ }).first()),
              ]);
              const url = new URL(tab.url());
              await tab.close();
              await s.go(url.pathname + url.search);
            } },
        ],
      }],
    },

    {
      name: "La proposition",
      shots: [{
        kind: "desktop", as: "timothee", role: "commercial",
        setup: async (s) => { await s.go(`/quote.php?token=${prometalToken()}`); await s.wait(1.5); },
        lines: [
          { say: "La proposition fait quatre pages, à l'en-tête de La Petite Cour, préparée pour PROMETAL.",
            do: (s) => h.soft(s, s.page.locator("#cov_client_name")) },
          { say: "La troisième page donne l'offre : la quantité par mois, le prix, et le total mensuel estimé.",
            do: (s) => h.soft(s, s.page.locator("#head_ref_3")) },
          { say: "3 792 425 FCFA par mois. L'eau est exonérée de TVA.",
            speech: "Trois millions sept cent quatre-vingt-douze mille quatre cent vingt-cinq francs par mois. L'eau est exonérée de TVA.",
            do: (s) => s.scroll(380) },
          { say: "En dessous, les engagements : livraison, stock tampon, paiement à quinze jours, retour minimum des vides.",
            do: (s) => h.soft(s, s.page.locator("#dyn_empties_policy")) },
          { say: "Dans les conditions générales, l'article un reprend la politique choisie : au minimum 80 % des bonbonnes livrées.",
            speech: "Dans les conditions générales, l'article un reprend la politique choisie : au minimum quatre-vingts pour cent des bonbonnes livrées.",
            do: (s) => h.soft(s, s.page.locator("#dyn_tc_1_policy")) },
          { say: "Pour l'envoyer, « Faire signer le client » donne le lien de signature, à copier, ou à envoyer par WhatsApp ou par e-mail.",
            do: async (s) => { await s.scroll(-4000); await s.click(s.page.locator("#lpc-share-btn")); await s.wait(1.2); } },
          { say: "Le Commercial l'envoie au service des achats de PROMETAL.",
            do: (s) => s.click(s.page.locator("#lpc_share_close")) },
        ],
      }],
    },

    {
      name: "PROMETAL signe",
      shots: [{
        kind: "desktop", snapshot: true,
        setup: async (s) => { await s.go(`/sign/quote/${prometalToken()}`); await s.wait(1.2); },
        lines: [
          "Voici ce que PROMETAL reçoit : une page à signer, sans compte à créer. Pour la formation, nous signons à sa place.",
          { say: "Elle rappelle l'offre : la référence, la ligne, et le total.",
            do: (s) => h.soft(s, s.page.locator("table").first()) },
          { say: "Puis les conditions : le paiement, la livraison, et le retour minimum des vides.",
            do: (s) => h.soft(s, s.page.getByText(/au minimum 80 %/).first()) },
          { say: "Le signataire donne son nom, sa fonction et son téléphone.",
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
              await s.page.mouse.move(b.x + 60, b.y + 120);
              await s.page.mouse.down();
              for (let i = 0; i <= 40; i++) {
                await s.page.mouse.move(b.x + 60 + i * 9, b.y + 110 - Math.sin(i / 3.2) * 45 + (i % 7) * 2, { steps: 2 });
              }
              await s.page.mouse.up();
            } },
          { say: "« Bon pour Accord, Signer ». La signature est horodatée et scellée.",
            do: async (s) => { await s.click(s.page.locator("#sig_submit_btn")); await s.wait(2.5); } },
          "S'il veut d'abord discuter, « Je souhaite discuter de cette offre » prévient le Commercial, et le devis passe « À revoir ».",
        ],
      }],
    },

    {
      name: "Ce que la signature change",
      shots: [{
        kind: "desktop", as: "timothee", role: "commercial", start: PAGE.clients,
        lines: [
          "Le Commercial est prévenu : le devis est signé.",
          { say: "PROMETAL est maintenant un client actif. Sous son nom : paiement à quinze jours, retour des vides au minimum 80 %.",
            speech: "PROMETAL est maintenant un client actif. Sous son nom : paiement à quinze jours, retour des vides au minimum quatre-vingts pour cent.",
            do: (s) => h.soft(s, row(s, "PROMETAL")) },
          { say: "L'onglet Devis garde tous les devis. Celui de PROMETAL est « Signé », avec le nom du signataire.",
            do: async (s) => { await s.click(s.page.locator("#tab-btn-devis")); await s.wait(1); await h.soft(s, s.page.locator("#devis-tbody tr").first()); } },
          { say: "Il l'ouvre.",
            do: async (s) => { await s.click(s.page.locator("#devis-tbody tr").first().locator("button, a").first()); await s.wait(1.2); } },
          { say: "« Appliqué à la fiche client » : la signature a tout reporté, sans rien ressaisir.",
            do: (s) => h.soft(s, s.page.getByText("Appliqué à la fiche client").first()) },
          "Le prix signé devient le tarif de PROMETAL : Ventes & Commandes le proposera sur chaque commande.",
          "Les quinze jours dateront l'échéance de chaque facture. Gestion des Vides suivra les 80 % à récupérer.",
          { say: "Les commandes passées pour PROMETAL s'afficheront ici, rattachées à ce devis.",
            do: (s) => s.scroll(500, s.page.locator("#devis-modal-body")) },
          "Un devis signé ne se modifie plus. Pour changer l'offre, on en crée un nouveau à partir de celui-ci.",
        ],
      }, {
        kind: "desktop", as: "timothee", role: "commercial", start: PAGE.stock,
        lines: [
          { say: "Le stock tampon promis compte aussi. Dans Stock & Emballages, l'OPUR 20L passe en alerte.",
            speech: "Le stock tampon promis compte aussi. Dans Stock & Emballages, l'OPUR vingt litres passe en alerte.",
            do: (s) => h.soft(s, s.page.locator("#tbody-stock tr").filter({ hasText: "OPUR 20L" }).first()) },
          "Son seuil minimum est monté à 770 bonbonnes : deux semaines de livraisons à PROMETAL. Il n'y en a que 255.",
          "Le Responsable des Opérations sait qu'il faut commander chez le fournisseur.",
        ],
      }],
    },

    {
      name: "Le Studio Proposition",
      shots: [{
        kind: "desktop", as: "timothee", role: "commercial", start: PAGE.clients,
        lines: [
          { say: "Le texte de la proposition se règle dans le Studio Proposition, la roue dentée en haut de la page.",
            do: (s) => s.click(s.page.getByRole("link", { name: "Studio Proposition" }).first()) },
          { say: "Chaque onglet est une page du document : la marque, les références, l'offre, les conditions.",
            do: (s) => h.soft(s, s.page.locator(".pt-tab").first()) },
          { say: "Dans les conditions, l'article un garde sa partie générale.",
            do: async (s) => { await s.click(s.page.locator('.pt-tab[data-tab="p4"]')); await s.wait(0.8); await h.soft(s, s.page.locator('.pt-field[data-key="tc_1_body"]')); } },
          "La phrase sur le retour des vides vient de chaque devis : elle suit toujours la politique choisie.",
          "Chaque texte existe en français et en anglais. Une modification vaut pour les devis ouverts ensuite.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : une fiche client, un devis aux bons engagements, et un lien que le client signe.",
          "La signature reporte tout : le tarif, le délai de paiement, le retour des vides, et le stock à prévoir.",
          "La prochaine vidéo livre PROMETAL : Ventes & Commandes.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
