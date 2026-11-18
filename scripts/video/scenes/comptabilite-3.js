"use strict";
/**
 * Modules, partie 13 : Comptabilité 3, Déclarations Fiscales, États Financiers et Clôture.
 * docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * November 2026 on the copy: PROMETAL's invoice (455 000 F, its 9 100 F avoir), its payment net of the AIR
 * and its attestation (9 810 F); the payroll of October and November. The Comptable (Daniel Massomba on
 * screen) reads the échéances and the month; the Contrôleur Financier (Luc Nana) approves and locks the
 * DIPE, the CNPS and the AIR; the Comptable pays the DIPE and the CNPS from CCA Bank and settles the AIR,
 * which PROMETAL's attestation covers. The Contrôleur Financier then reads the Bilan, the Compte de
 * Résultat, the TFT and the closing assistant (the year stays open: nothing is closed on camera).
 * LPC is not liable to TVA (« LPC assujetti à la TVA » off, the owner's decision 19).
 * Narrated by role (runbook §2). VIDEO_QA_FINDINGS §13.
 *
 * Ground: snapshot pre-13-film = after-08-facturation + migrations 154 to 158. The scene writes three
 * locked declarations and their payments: the copy is then snapshot after-13-comptabilite-3.
 */
const h = require("../helpers");

const PAGE = {
  tax: "/modules/accounting/tax_declarations.php",
  reports: "/modules/accounting/reports.php",
  gl: "/modules/accounting/ledger.php",
};

const dlg = (s) => s.page.locator("dialog[open]").last();
const tab = async (s, name) => { await s.click(s.page.locator(`#tab-${name}`)); await s.wait(1.2); };
const section = (s, label) => s.page.locator("#tmm-body section.kind-section").filter({ has: s.page.locator(".kind-chip", { hasText: new RegExp(`^${label}$`) }) }).first();
const line = (s, label, code) => section(s, label).locator("tr").filter({ hasText: code }).first();
const november = async (s) => {
  await tab(s, "monthly");
  await s.click(s.page.locator("#month-grid-body tr.month-cell").filter({ hasText: "Novembre" }).first());
  await s.wait(2.5);
};
const approve = async (s, kind) => {
  await s.click(s.page.locator(`#tmm-body button[onclick*="approveDeclaration('${kind}'"]`));
  await s.wait(0.9);
  await s.click(dlg(s).getByRole("button", { name: "Approuver & verrouiller" }));
  await s.wait(3.5);
};
const pay = async (s, label, ref) => {
  await s.click(section(s, label).getByRole("button", { name: /Enregistrer paiement|Solder/ }));
  await s.wait(1.4);
  if (ref) await s.type(s.page.locator("#_pay_ref"), ref, { delay: 45 });
};
const okIf = async (s, seconds = 5) => {
  const ok = dlg(s).getByRole("button", { name: "OK" });
  if (await ok.waitFor({ state: "visible", timeout: seconds * 1000 }).then(() => true, () => false)) {
    await s.click(ok); await s.wait(0.6);
  }
};
const passif = (s, text) => s.page.locator("#tbody-passif tr").filter({ hasText: text }).first();
const actif = (s, text) => s.page.locator("#tbody-actif tr").filter({ hasText: text }).first();

module.exports = {
  id: "comptabilite-3",
  file: "LPC-ERP-Comptabilite-3-Fiscal-Etats-Financiers",
  meta: { title: "LPC ERP · Comptabilité 3 : Déclarations Fiscales, États Financiers, Clôture (Modules, partie 13)", artist: "JBS Praxis" },
  library: {
    slug: "comptabilite-3",
    title: "Comptabilité 3",
    subtitle: "Déclarations fiscales, états financiers et clôture de l'exercice",
    series: "Modules",
    part: 13,
    parts: 15,
    pages: [PAGE.tax, PAGE.reports],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 13",
    title: "Comptabilité 3",
    subtitle: "Déclarations fiscales, états financiers et clôture de l'exercice",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 13",
    title: "Merci",
    subtitle: "À suivre : Tableaux de bord & Rapports",
    left: "bureau.lpc.cm",
  },
  holders: { comptable: "Daniel Massomba", controleur: "Luc Nana" },

  async prepare({ log, ground }) {
    ground.restore("pre-13-film");
    log("ground: pre-13-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans Comptabilité 3 : les déclarations fiscales, les états financiers et la clôture de l'exercice.",
          "Chaque mois, LPC déclare et paie ses impôts et ses cotisations sociales : à la DGI, à la CNPS et à la commune.",
          "L'ERP les calcule à partir de ce que les modules ont déjà enregistré : les factures, les attestations, la paie.",
          "Le Comptable prépare. Le Contrôleur Financier approuve. Puis le paiement passe au Grand Livre.",
          "Ensuite, les états financiers : le Bilan, le Compte de Résultat, le Tableau des Flux de Trésorerie, et la clôture.",
        ],
      }],
    },

    {
      name: "Les échéances",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.tax,
        lines: [
          { say: "Le Comptable ouvre Déclarations Fiscales, dans Comptabilité & Finance.",
            do: (s) => h.soft(s, h.shell.menu(s, "Déclarations Fiscales")) },
          { say: "Quatre onglets : les Échéances, les Déclarations mensuelles, les Retenues à la source, et les Paramètres.",
            do: (s) => h.tour(s, ["dashboard", "monthly", "withholding", "settings"].map((t) => s.page.locator(`#tab-${t}`)), 0.35) },
          { say: "Les déclarations du mois se déposent avant le 15 du mois suivant : ici, le 15 décembre.",
            speech: "Les déclarations du mois se déposent avant le quinze du mois suivant : ici, le quinze décembre.",
            do: (s) => h.soft(s, s.page.locator("#kpi_next_due")) },
          { say: "À décaisser dans les trente jours : 192 066 FCFA, pour la DIPE et la CNPS de novembre.",
            speech: "À décaisser dans les trente jours : cent quatre-vingt-douze mille soixante-six francs, pour la DIPE et la CNPS de novembre.",
            do: (s) => h.soft(s, s.page.locator("#kpi_next_amount")) },
          { say: "AIR retenu à récupérer : 9 810 FCFA. C'est ce que PROMETAL a retenu sur sa facture, et versé pour LPC.",
            speech: "AIR retenu à récupérer : neuf mille huit cent dix francs. C'est ce que PROMETAL a retenu sur sa facture, et versé pour L P C.",
            do: (s) => h.soft(s, s.page.locator("#kpi_air_credit")) },
          { say: "Le tableau liste chaque déclaration à venir, avec son montant net.",
            do: (s) => h.soft(s, s.page.locator("#upcoming-body tr").first()) },
        ],
      }],
    },

    {
      name: "L'attestation de PROMETAL",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.tax,
        lines: [
          { say: "Onglet « Retenues à la source ».",
            do: (s) => tab(s, "withholding") },
          "Quand un client retient l'AIR, il remet à LPC une attestation. Sans elle, la retenue ne peut pas être déduite.",
          { say: "L'attestation de PROMETAL pour novembre est déjà là : 9 810 FCFA, avec sa copie.",
            speech: "L'attestation de PROMETAL pour novembre est déjà là : neuf mille huit cent dix francs, avec sa copie.",
            do: (s) => h.soft(s, s.page.locator("#cert-body tr").filter({ hasText: "PROMETAL" }).first()) },
          { say: "Le formulaire en enregistre une nouvelle : le client, le numéro, le mois des paiements retenus, et le montant.",
            do: (s) => h.tour(s, ["#wc_client", "input[name=certificate_ref]", "input[name=period_month]", "input[name=total_amount]"].map((q) => s.page.locator(q)), 0.4) },
        ],
      }],
    },

    {
      name: "Le mois de novembre",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.tax,
        lines: [
          { say: "Onglet « Déclarations mensuelles » : un clic sur novembre ouvre le récapitulatif du mois.",
            do: (s) => november(s) },
          { say: "En haut, ce qui va à la DGI : 63 546 FCFA. À la CNPS : 128 520 FCFA. À la commune : rien.",
            speech: "En haut, ce qui va à la DGI : soixante-trois mille cinq cent quarante-six francs. À la CNPS : cent vingt-huit mille cinq cent vingt francs. À la commune : rien.",
            do: (s) => h.tour(s, ["#tmm-dgi", "#tmm-cnps", "#tmm-commune"].map((q) => s.page.locator(q)), 0.4) },
          { say: "L'AIR d'abord. Le chiffre d'affaires du mois, avoir déduit : 445 900 FCFA.",
            speech: "L'AIR d'abord. Le chiffre d'affaires du mois, avoir déduit : quatre cent quarante-cinq mille neuf cents francs.",
            do: (s) => h.soft(s, line(s, "AIR", "CHIFFRE_AFFAIRES_HT")) },
          { say: "2,2 % font 9 810 FCFA d'AIR. L'attestation de PROMETAL couvre tout : il ne reste rien à payer.",
            speech: "Deux virgule deux pour cent font neuf mille huit cent dix francs d'AIR. L'attestation de PROMETAL couvre tout : il ne reste rien à payer.",
            do: (s) => h.tour(s, [line(s, "AIR", "AIR_BRUT"), line(s, "AIR", "AIR_RETENU_SOURCE"), section(s, "AIR").locator(".totals")], 0.5) },
          { say: "La TVA : LPC n'est pas assujetti, c'est un réglage des Paramètres. Il n'y a rien à déclarer.",
            do: (s) => h.soft(s, section(s, "TVA").locator("tr").first()) },
          "S'il l'était, la TVA collectée sur les ventes et la TVA déductible des achats viendraient du Grand Livre, et un crédit passerait au mois suivant.",
          { say: "La DIPE regroupe ce que la paie retient pour l'État : IRPP, centimes additionnels, CFC, redevance audiovisuelle, taxe de développement local.",
            do: (s) => h.tour(s, ["IRPP", "CAC", "CFC_SALARIE", "CRTV", "TDL"].map((c) => line(s, "DIPE", c)), 0.3) },
          { say: "Avec les charges patronales : la CFC et le FNE. Total : 63 546 FCFA.",
            speech: "Avec les charges patronales : la CFC et le FNE. Total : soixante-trois mille cinq cent quarante-six francs.",
            do: (s) => h.tour(s, [line(s, "DIPE", "CFC_PATRONAL"), line(s, "DIPE", "FNE_PATRONAL"), section(s, "DIPE").locator(".totals")], 0.4) },
          { say: "La CNPS : la pension, les prestations familiales et l'accident du travail. 128 520 FCFA, exactement ce que la paie a retenu.",
            speech: "La CNPS : la pension, les prestations familiales et l'accident du travail. Cent vingt-huit mille cinq cent vingt francs, exactement ce que la paie a retenu.",
            do: (s) => h.soft(s, section(s, "CNPS").locator(".totals")) },
          { say: "Une ligne signale que le taux d'accident du travail de la paie diffère du groupe des Paramètres : à vérifier avec la CNPS.",
            do: (s) => h.soft(s, line(s, "CNPS", "CNPS_AT_A_VERIFIER")) },
          { say: "Le Comptable n'approuve pas : c'est le rôle du Contrôleur Financier, le bandeau le rappelle.",
            do: (s) => h.soft(s, s.page.locator("#tmm-subtitle")) },
        ],
      }],
    },

    {
      name: "Approuver et verrouiller",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.tax, snapshot: true,
        setup: async (s) => { await november(s); },
        lines: [
          "Le Contrôleur Financier ouvre le même mois. Lui voit les boutons d'approbation.",
          { say: "Sur la DIPE, « Approuver & verrouiller ». Il confirme.",
            do: (s) => approve(s, "irpp_dipe") },
          { say: "La déclaration est verrouillée : ses chiffres ne bougeront plus, même si un bulletin changeait après.",
            do: (s) => h.soft(s, section(s, "DIPE").locator("header")) },
          { say: "Un PDF horodaté est généré, avec le nom de celui qui a approuvé : la pièce à déposer à la DGI.",
            do: (s) => h.soft(s, section(s, "DIPE").getByRole("link", { name: /PDF/ })) },
          { say: "Il approuve aussi la CNPS,",
            do: (s) => approve(s, "cnps") },
          { say: "et l'AIR.",
            do: (s) => approve(s, "air") },
          "L'ERP ne dépose rien à la place de LPC : la télédéclaration se fait sur le site de la DGI, PDF en main.",
        ],
      }],
    },

    {
      name: "Payer",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.tax, snapshot: true,
        setup: async (s) => { await november(s); },
        lines: [
          "Le virement est parti. Le Comptable l'enregistre.",
          { say: "Sur la DIPE, « Enregistrer paiement ». Le compte qui paie : CCA Bank, avec son solde.",
            do: (s) => pay(s, "DIPE") },
          { say: "Le montant est celui de la déclaration verrouillée. On ne paie jamais plus que le solde du compte.",
            do: (s) => h.soft(s, s.page.locator("#_pay_amt")) },
          { say: "La référence du virement, la date, et la quittance si on l'a.",
            do: async (s) => { await s.type(s.page.locator("#_pay_ref"), "VIR-DGI-2611", { delay: 55 }); await h.soft(s, s.page.locator("#_pay_file")); } },
          { say: "« Enregistrer ».",
            do: async (s) => { await s.click(dlg(s).getByRole("button", { name: "Enregistrer" })); await s.wait(3.5); } },
          { say: "La DIPE est payée. L'écriture solde le compte 432, où la paie avait inscrit ces retenues.",
            speech: "La DIPE est payée. L'écriture solde le compte quatre cent trente-deux, où la paie avait inscrit ces retenues.",
            do: (s) => h.soft(s, section(s, "DIPE").locator("header")) },
          "Le FNE de ces bulletins anciens n'avait pas été provisionné : il passe en charge le jour du paiement.",
          { say: "La CNPS de la même façon, depuis CCA Bank : elle solde le compte 433.",
            speech: "La CNPS de la même façon, depuis CCA Bank : elle solde le compte quatre cent trente-trois.",
            do: async (s) => { await pay(s, "CNPS", "VIR-CNPS-2611"); await s.click(dlg(s).getByRole("button", { name: "Enregistrer" })); await s.wait(3.5); } },
          { say: "L'AIR ne demande pas d'argent : « Solder ». L'AIR retenue par PROMETAL devient l'acompte d'impôt de LPC.",
            do: async (s) => { await pay(s, "AIR"); await s.click(dlg(s).getByRole("button", { name: "Enregistrer" })); await s.wait(3.5); } },
          { say: "Les trois déclarations sont payées. CCA Bank a baissé de 192 066 FCFA.",
            speech: "Les trois déclarations sont payées. CCA Bank a baissé de cent quatre-vingt-douze mille soixante-six francs.",
            do: (s) => h.soft(s, s.page.locator("#tmm-body .badge-status").first()) },
        ],
      }, {
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE.gl + "?account=432#grandlivre",
        lines: [
          { say: "Au Grand Livre, le compte 432 : la paie de novembre au crédit, le paiement au débit, le même jour que le virement.",
            speech: "Au Grand Livre, le compte quatre cent trente-deux : la paie de novembre au crédit, le paiement au débit, le même jour que le virement.",
            do: (s) => h.soft(s, s.page.locator("#gl_account_select"), 6000) },
          { say: "Il reste 54 746 FCFA : la DIPE d'octobre, qui n'a pas encore été déclarée.",
            speech: "Il reste cinquante-quatre mille sept cent quarante-six francs : la DIPE d'octobre, qui n'a pas encore été déclarée.",
            do: (s) => h.soft(s, s.page.locator("#gl_current_balance")) },
        ],
      }],
    },

    {
      name: "Le Bilan",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.reports,
        lines: [
          { say: "Le Contrôleur Financier ouvre États Financiers. Premier onglet, le Bilan de l'exercice 2026.",
            speech: "Le Contrôleur Financier ouvre États Financiers. Premier onglet, le Bilan de l'exercice deux mille vingt-six.",
            do: (s) => h.soft(s, h.shell.menu(s, "États Financiers")) },
          { say: "À gauche, ce que LPC possède : le stock, les créances, et la trésorerie, 948 024 FCFA.",
            speech: "À gauche, ce que L P C possède : le stock, les créances, et la trésorerie, neuf cent quarante-huit mille vingt-quatre francs.",
            do: (s) => h.tour(s, [actif(s, "Stocks"), actif(s, "Créances"), actif(s, "Trésorerie")], 0.5) },
          { say: "À droite, ce qu'il doit, et le résultat de l'exercice.",
            do: (s) => h.tour(s, [passif(s, "Passif Circulant"), passif(s, "Résultat")], 0.5) },
          { say: "Les deux totaux sont égaux : la coche verte dit que le Bilan est équilibré.",
            do: (s) => h.soft(s, s.page.locator("#tbody-passif .row-total")) },
          { say: "Un clic sur une rubrique montre les comptes qui la composent. Le passif circulant :",
            do: async (s) => { await s.click(passif(s, "Passif Circulant")); await s.wait(2); } },
          { say: "les fournisseurs, les salaires dus, la DIPE et la CNPS d'octobre, et le prêt de Smart Logistics en attente.",
            do: (s) => h.soft(s, s.page.locator("#tbody-drilldown")) },
          { say: "Un compte de tiers se classe selon son solde : l'AIR à récupérer est une créance, pas une dette.",
            do: async (s) => { await s.click(s.page.locator("button[onclick=\"closeModal('modal-drilldown')\"]")); await s.wait(0.8); await h.soft(s, actif(s, "Créances")); } },
        ],
      }],
    },

    {
      name: "Le Compte de Résultat",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.reports + "#resultat",
        lines: [
          { say: "Deuxième onglet, le Compte de Résultat, présenté en soldes intermédiaires de gestion.",
            do: (s) => tab(s, "resultat") },
          { say: "Le chiffre d'affaires, moins le coût des marchandises : la marge brute.",
            do: (s) => h.soft(s, s.page.locator(".row-sig").first()) },
          { say: "Puis la valeur ajoutée, l'excédent brut d'exploitation après les salaires, et le résultat d'exploitation.",
            do: (s) => h.tour(s, [1, 2, 3].map((i) => s.page.locator(".row-sig").nth(i)), 0.5) },
          { say: "Chaque solde part du précédent. En bas, le résultat net de l'exercice.",
            do: (s) => h.soft(s, s.page.locator(".row-sig").last()) },
          "Ce sont des données de test : deux mois de salaires pour une seule facture. Le résultat est donc négatif.",
          { say: "En haut, l'indicateur fiscal : l'impôt minimum de l'année est déjà couvert par l'AIR.",
            speech: "En haut, l'indicateur fiscal : l'impôt minimum de l'année est déjà couvert par l'AIR.",
            do: (s) => h.soft(s, s.page.locator("#tax_status_text")) },
        ],
      }],
    },

    {
      name: "Les flux de trésorerie",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.reports + "#tft",
        lines: [
          { say: "Troisième onglet, le Tableau des Flux de Trésorerie.",
            do: (s) => tab(s, "tft") },
          { say: "Il part du résultat, ajoute ce qui n'a pas coûté d'argent, et suit le stock, les créances et les dettes.",
            do: (s) => h.tour(s, [1, 3, 4, 5].map((i) => s.page.locator("#tbody-tft tr").nth(i)), 0.4) },
          { say: "Il arrive à la trésorerie de fin de période : 948 024 FCFA, ce que les comptes contiennent vraiment.",
            speech: "Il arrive à la trésorerie de fin de période : neuf cent quarante-huit mille vingt-quatre francs, ce que les comptes contiennent vraiment.",
            do: (s) => h.soft(s, s.page.locator("#tft_reconciliation")) },
          "Le contrôle compare les deux au franc près : rapprochement correct.",
        ],
      }],
    },

    {
      name: "La clôture de l'exercice",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE.reports + "#cloture",
        lines: [
          { say: "L'onglet « Assistant Clôture » prépare la fin de l'année. Le bouton rouge de la barre y mène aussi.",
            do: async (s) => { await tab(s, "cloture"); await h.soft(s, s.page.locator("#btn_cloture")); } },
          { say: "D'abord, les contrôles : écritures équilibrées, aucun brouillon, amortissements passés, attestations reçues, déclarations payées.",
            do: (s) => h.tour(s, [0, 1, 2, 3, 4].map((i) => s.page.locator("#cloture_preflight > div").nth(i)), 0.3) },
          "Tout est vert, grâce aux paiements de tout à l'heure.",
          { say: "Ensuite, les phases du 31 décembre : les amortissements manquants, et la provision pour l'impôt sur les sociétés.",
            do: (s) => h.tour(s, ["depreciation", "is_provision"].map((p) => s.page.locator(`button[onclick="runClosingPhase('${p}')"]`)), 0.5) },
          { say: "« Résultat de l'exercice » le calcule, et dit où il ira.",
            do: async (s) => {
              await s.click(s.page.locator(`button[onclick="runClosingPhase('profit_incorporation')"]`)); await s.wait(0.9);
              await s.click(dlg(s).getByRole("button").last()); await s.wait(1.5);
            } },
          { say: "Une perte passe au compte 139 ; un bénéfice, au compte 131. Aucune écriture n'est passée maintenant.",
            speech: "Une perte passe au compte cent trente-neuf ; un bénéfice, au compte cent trente et un. Aucune écriture n'est passée maintenant.",
            do: (s) => okIf(s) },
          { say: "Enfin les étapes : inventaire, ajustements, clôture provisoire, verrouillage, et clôture définitive.",
            do: (s) => s.scroll(500) },
          "La clôture définitive passe les À-nouveaux au premier janvier : les soldes et le résultat ouvrent l'année suivante.",
          { say: "L'exercice 2026 n'est pas fini : rien n'est clôturé aujourd'hui.",
            speech: "L'exercice deux mille vingt-six n'est pas fini : rien n'est clôturé aujourd'hui." },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "Le Comptable prépare les déclarations, le Contrôleur Financier les approuve et les verrouille.",
          "Chaque paiement solde les comptes que les ventes et la paie avaient remplis, et sort l'argent du bon compte.",
          "Le Bilan s'équilibre, le résultat se lit en cascade, et la trésorerie se rapproche au franc près.",
          "Prochaine vidéo : Tableaux de bord & Rapports, la vue du Directeur Général sur toute l'activité.",
        ],
      }],
    },
  ],
};
