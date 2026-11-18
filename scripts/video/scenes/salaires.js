"use strict";
/**
 * Modules, partie 10 : Salaires (the page « Gestion du Personnel »). docs/TRAINING_VIDEOS.md says how a scene
 * is written and filmed.
 *
 * The Contrôleur Financier runs the month's payroll and pays it (Luc Nana on screen); the Comptable approves
 * and pays out an advance (Daniel Massomba). Narrated by role (runbook §2).
 *
 * Pay on camera, the owner's decision (18/11/2026): the REAL payroll grid, « so they have an idea of the
 * reality », and the video is seen only by the roles that see payroll. That is why it films ONE page: a
 * video's audience is everyone who can open one of the pages on screen (docs/HELP_VIDEOS.md), so showing
 * Données de Base or the Grand Livre would widen it. No family or personal fields (the payroll no longer
 * shows them). LPC's drivers are real employees not yet in the system: their pay is never invented.
 *
 * Ground: snapshot pre-10-filmed = after-02-gestion-des-achats + migrations 133 to 136, unchanged. As on
 * live, October and November 2026 were calculated on 03/11 and are « À payer »; November used the salaries
 * of that day (the Comptable at 50 000 F, the support account at 50 000 F) and the records have changed since
 * (150 000 F and 0). The scene writes: an example advance of 30 000 F for the Responsable des Opérations, paid
 * out from the Caisse; November undone and calculated again; November paid by bank from CCA Bank.
 */
const h = require("../helpers");

const PAGE = "/modules/hr/payroll_finance.php";
const MONTH = "2026-11";

/** The shared window (lpc-modal.js): its OK / Confirmer, its text field. */
const modal = (s) => s.page.locator("dialog[open]").last();
const press = async (s, label) => { await s.click(modal(s).getByRole("button", { name: label })); await s.wait(0.6); };
const gridRow = (s, name) => s.page.locator("#tbody-payroll tr[data-uid]").filter({ hasText: name }).first();
const advRow = (s, name) => s.page.locator("#tbody-advances tr").filter({ hasText: name }).first();
const detail = (s) => s.page.locator("#modal-payroll-detail");
const scrollDetail = (s, dy) => s.scroll(dy, detail(s).locator(".overflow-auto").first());
/** The month's notice (« calculée, reste à payer », « calculée et payée »), if one comes up. */
const okIf = async (s, seconds = 2.5) => {
  const ok = modal(s).getByRole("button", { name: "OK" });
  if (await ok.waitFor({ state: "visible", timeout: seconds * 1000 }).then(() => true, () => false)) await press(s, "OK");
};
const loadMonth = async (s, dismiss = false) => {
  await s.click(s.page.locator("#tab-payroll"));
  await s.page.locator("#payroll_period").fill(MONTH);
  await s.click(s.page.getByRole("button", { name: "Charger Grille" }));
  await s.wait(2.5);
  if (dismiss) await okIf(s);
};

module.exports = {
  id: "salaires",
  file: "LPC-ERP-Salaires",
  meta: { title: "LPC ERP · Salaires (Modules, partie 10)", artist: "JBS Praxis" },
  library: {
    slug: "salaires",
    title: "Salaires",
    subtitle: "Les acomptes, la paie du mois, les bulletins et le paiement des salaires",
    series: "Modules",
    part: 10,
    parts: 15,
    // No "audience": the video is seen by whoever may open Gestion du Personnel (hr.payroll.view:
    // the Directeur Général, the Contrôleur Financier, the Comptable), the owner's choice for real pay.
    pages: [PAGE],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Modules, partie 10",
    title: "Salaires",
    subtitle: "Les acomptes, la paie du mois, les bulletins et le paiement des salaires",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Modules, partie 10",
    title: "Merci",
    subtitle: "À suivre : Comptabilité 1",
    left: "bureau.lpc.cm",
  },
  holders: { controleur: "Luc Nana", comptable: "Daniel Massomba" },

  async prepare({ log, ground }) {
    ground.restore("pre-10-film");
    log("ground: pre-10-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la paie de LPC ERP, sur la page Gestion du Personnel.",
          "Le Contrôleur Financier calcule la paie du mois et la paie. Le Comptable approuve et verse les acomptes.",
          "Les montants que vous allez voir sont les vrais salaires de LPC. Cette vidéo n'est visible que par les rôles qui voient la paie.",
          "Nous suivons un acompte, puis la paie de novembre : la reprendre, la calculer, et payer les salaires.",
        ],
      }],
    },

    {
      name: "La page de la paie",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "Le Contrôleur Financier ouvre Gestion du Personnel.",
            do: (s) => h.soft(s, h.shell.menu(s, "Gestion du Personnel")) },
          { say: "Trois onglets : les acomptes, les employés et leurs contrats, la paie du mois.",
            do: (s) => h.tour(s, ["#tab-advances", "#tab-contracts", "#tab-payroll"].map((id) => s.page.locator(id)), 0.4) },
          { say: "« Employés & Contrats » montre chaque salarié : son salaire de base, ses indemnités, son numéro CNPS.",
            do: (s) => s.click(s.page.locator("#tab-contracts")) },
          { say: "Un salaire ne se change pas ici, mais dans Données de Base, Employés & RH. « Voir la fiche » y mène.",
            do: (s) => h.soft(s, s.page.locator("#tbody-contracts").getByRole("link", { name: /Voir la fiche/ }).first()) },
          "Les chauffeurs de LPC n'y sont pas encore : ils seront ajoutés quand leur fiche sera créée.",
        ],
      }],
    },

    {
      name: "Un acompte",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE, snapshot: true,
        lines: [
          "Un acompte est une avance sur salaire, versée en cours de mois et reprise sur le bulletin.",
          { say: "Pour l'exemple, le Responsable des Opérations demande 30 000 FCFA. Le Contrôleur Financier saisit la demande.",
            speech: "Pour l'exemple, le Responsable des Opérations demande trente mille francs. Le Contrôleur Financier saisit la demande.",
            do: async (s) => {
              await s.click(s.page.getByRole("button", { name: /Nouvelle Demande/ }));
              await s.hover(s.page.locator("#adv_user_id"));
              const v = await s.page.locator("#adv_user_id option").filter({ hasText: /Bertin/ }).first().getAttribute("value");
              await s.page.locator("#adv_user_id").selectOption(v); await s.wait(0.5);
              await s.type(s.page.locator("#adv_amount"), "30000", { delay: 150 });
            } },
          { say: "Au-delà de la moitié du salaire de base, un avertissement s'afficherait. Ici, tout va bien.",
            do: (s) => h.soft(s, s.page.locator("#adv_amount")) },
          { say: "« Soumettre Demande ». La demande attend une approbation.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Soumettre/ })); await s.wait(1); await press(s, "OK"); await h.soft(s, advRow(s, "Bertin")); } },
        ],
      }],
    },

    {
      name: "Approuver et verser",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE, snapshot: true,
        lines: [
          { say: "Le Comptable approuve les acomptes. Il voit la demande en attente.",
            do: (s) => h.soft(s, advRow(s, "Bertin")) },
          { say: "« Approuver ». L'employé est prévenu ; l'acompte reste à décaisser.",
            do: async (s) => { await s.click(advRow(s, "Bertin").getByRole("button", { name: "Approuver" })); await s.wait(0.6); await press(s, "Confirmer"); await s.wait(1); } },
          "Tant que l'argent n'est pas remis, rien n'est retenu sur le salaire.",
          { say: "Il remet l'argent en espèces, puis clique « Décaisser ».",
            do: (s) => s.click(advRow(s, "Bertin").getByRole("button", { name: /Décaisser/ })) },
          { say: "Le compte qui paie est la caisse. Il confirme.",
            do: async (s) => { await h.soft(s, s.page.locator("#disb_treasury_account")); await s.click(s.page.locator("#modal-disburse-advance").getByRole("button", { name: /Confirmer/ })); await s.wait(1.2); await press(s, "OK"); } },
          { say: "L'argent sort de la caisse. L'acompte est décaissé, à déduire du prochain bulletin.",
            do: (s) => h.soft(s, advRow(s, "Bertin")), hold: 0.5 },
        ],
      }],
    },

    {
      name: "Novembre à reprendre",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "Le Contrôleur Financier ouvre la paie, choisit novembre, et clique « Charger Grille ».",
            do: (s) => loadMonth(s) },
          { say: "Novembre est déjà calculé : il reste à payer.",
            do: (s) => okIf(s, 4) },
          { say: "Chaque ligne montre le bulletin : la base, les indemnités, les acomptes, le brut, les retenues et le net.",
            do: (s) => h.tour(s, [1, 2, 6, 7, 13].map((i) => s.page.locator("#content-payroll thead th").nth(i)), 0.3) },
          "Novembre a été calculé le 3 novembre. Ce jour-là, deux fiches n'avaient pas encore de salaire.",
          { say: "Pour le Comptable, 50 000 FCFA ont été saisis en prime, sur une base à zéro. Sa fiche dit aujourd'hui 150 000.",
            speech: "Pour le Comptable, cinquante mille francs ont été saisis en prime, sur une base à zéro. Sa fiche dit aujourd'hui cent cinquante mille.",
            do: (s) => h.tour(s, [gridRow(s, "Daniel").locator("td").nth(1), gridRow(s, "Daniel").locator(".grid-bonus")], 0.5) },
          { say: "Le compte du support informatique, qui n'est pas salarié de LPC, a reçu la même prime. Sa fiche dit zéro.",
            speech: "Le compte du support informatique, qui n'est pas salarié de L P C, a reçu la même prime. Sa fiche dit zéro.",
            do: (s) => h.soft(s, gridRow(s, "JBS").locator(".grid-bonus")) },
          "Les bulletins de novembre ne sont pas encore payés : on peut donc les reprendre.",
        ],
      }],
    },

    {
      name: "Annuler la paie",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE, snapshot: true,
        lines: [
          { say: "Il clique « Annuler la Paie ».",
            do: async (s) => { await loadMonth(s, true); await s.click(s.page.locator("#btn_cancel_payroll")); } },
          { say: "L'ERP demande le motif. Il écrit : salaires corrigés dans Données de Base.",
            do: (s) => s.type(modal(s).locator(".lpc-modal-input"), "Salaires corrigés dans Données de Base", { delay: 45 }) },
          { say: "Les bulletins de novembre sont annulés. Rien n'est effacé : chaque écriture de paie est extournée par une écriture inverse.",
            do: async (s) => { await press(s, "OK"); await s.wait(2.2); } },
          { say: "Le lien d'un bulletin déjà envoyé ne l'ouvre plus. Un mois payé, lui, ne s'annule pas.",
            do: async (s) => { await press(s, "OK"); await s.wait(2); } },
        ],
      }],
    },

    {
      name: "Calculer la paie",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE, snapshot: true,
        lines: [
          { say: "La grille est de nouveau ouverte, avec les salaires d'aujourd'hui.",
            do: async (s) => { await loadMonth(s); await h.soft(s, gridRow(s, "Daniel").locator(".grid-net")); } },
          { say: "Les 30 000 FCFA versés au Responsable des Opérations sont dans la colonne Acomptes. Son net baisse d'autant.",
            speech: "Les trente mille francs versés au Responsable des Opérations sont dans la colonne Acomptes. Son net baisse d'autant.",
            do: (s) => h.tour(s, [gridRow(s, "Bertin").locator(".grid-advances"), gridRow(s, "Bertin").locator(".grid-net")], 0.6) },
          { say: "On saisirait ici une prime ou des jours d'absence ; l'aperçu se recalcule aussitôt. Ce mois-ci, rien à ajouter.",
            do: (s) => h.tour(s, [gridRow(s, "Bertin").locator(".grid-bonus"), gridRow(s, "Bertin").locator(".grid-absence")], 0.5) },
          { say: "Chacun est payé par banque. Les chauffeurs, payés en espèces, prendront « Caisse ».",
            do: (s) => h.soft(s, gridRow(s, "Bertin").locator(".grid-payment")) },
          { say: "« Calculer & Valider Paie ». L'ERP rappelle qu'avant paiement, le mois peut encore être repris.",
            do: async (s) => { await s.click(s.page.locator("#btn_generate_payroll")); await s.wait(0.8); } },
          { say: "Il confirme. Quatre bulletins sont calculés. Le compte du support, sans salaire, est laissé de côté.",
            do: async (s) => { await press(s, "Confirmer"); await s.wait(3); } },
          { say: "Les lignes se verrouillent, marquées « À payer ».",
            do: async (s) => { await press(s, "OK"); await okIf(s, 4); await h.soft(s, gridRow(s, "Bertin").locator("span").filter({ hasText: /À payer/ })); } },
        ],
      }],
    },

    {
      name: "La fiche détaillée",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "Un clic sur un nom ouvre la fiche détaillée du mois. Voici celle du Responsable des Opérations.",
            do: async (s) => { await loadMonth(s, true); await s.click(gridRow(s, "Bertin").getByRole("button")); await s.wait(1.5); } },
          { say: "Le brut : la base de 100 000 FCFA et 10 000 FCFA d'indemnité de transport.",
            speech: "Le brut : la base de cent mille francs, et dix mille francs d'indemnité de transport.",
            do: (s) => h.soft(s, s.page.locator("#pdet_gross_table")) },
          { say: "Puis chaque retenue avec sa formule : CNPS, IRPP, CAC, CFC, la redevance audiovisuelle et la taxe locale.",
            do: async (s) => { await scrollDetail(s, 380); await h.soft(s, s.page.locator("#pdet_deductions_table")); } },
          { say: "L'acompte de 30 000 FCFA est repris ici.",
            speech: "L'acompte de trente mille francs est repris ici.",
            do: async (s) => { await scrollDetail(s, 300); await h.soft(s, s.page.locator("#pdet_other_deductions")); } },
          { say: "Les charges patronales, payées par LPC en plus du brut : la CNPS, le Crédit Foncier, et le Fonds National de l'Emploi, 1 %.",
            speech: "Les charges patronales, payées par L P C en plus du brut : la CNPS, le Crédit Foncier, et le Fonds National de l'Emploi, un pour cent.",
            do: async (s) => { await scrollDetail(s, 260); await h.soft(s, s.page.locator("#pdet_employer_table")); } },
          { say: "Le net à payer : 65 799 FCFA, par virement.",
            speech: "Le net à payer : soixante-cinq mille sept cent quatre-vingt-dix-neuf francs, par virement.",
            do: async (s) => { await scrollDetail(s, 200); await h.soft(s, s.page.locator("#pdet_net")); } },
        ],
      }],
    },

    {
      name: "Le bulletin",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE,
        lines: [
          { say: "En haut de la fiche, « Télécharger Fiche PDF » donne le bulletin de paie.",
            do: async (s) => { await loadMonth(s, true); await s.click(gridRow(s, "Bertin").getByRole("button")); await s.wait(1.5); await h.soft(s, s.page.locator("#pdet_pdf_btn")); } },
          { say: "Le voici : une page, les gains, les retenues, les charges patronales, et le net en lettres.",
            do: async (s) => { const href = await s.page.locator("#pdet_pdf_btn").getAttribute("href"); await s.go(`${href}&html=1`); await s.wait(0.8); } },
          { say: "Son statut : « À payer ». Il ne dira « Payé » qu'une fois le salaire versé.",
            do: (s) => h.soft(s, s.page.getByText("À payer", { exact: true }).first()) },
          { say: "L'acompte de 30 000 FCFA y figure, et le Fonds National de l'Emploi parmi les charges patronales.",
            speech: "L'acompte de trente mille francs y figure, et le Fonds National de l'Emploi parmi les charges patronales.",
            do: (s) => h.tour(s, [s.page.getByText("Acomptes déduits").first(), s.page.getByText(/Fonds National de l'Emploi/).first()], 0.6) },
        ],
      }],
    },

    {
      name: "Payer les salaires",
      shots: [{
        kind: "desktop", as: "luc", role: "controleur", start: PAGE, snapshot: true,
        lines: [
          { say: "Le jour du virement, le Contrôleur Financier revient sur novembre et clique « Marquer Payées ».",
            do: async (s) => { await loadMonth(s, true); await s.click(s.page.locator("#btn_mark_paid")); await s.wait(1.5); } },
          { say: "Deux groupes : ceux payés par banque, et ceux payés en caisse. La caisse est vide ce mois-ci.",
            do: (s) => h.tour(s, [s.page.locator("#mp-group-bank h4"), s.page.locator("#mp-group-caisse h4")], 0.6) },
          { say: "Il coche tout le groupe banque : quatre salaires, et le total du virement.",
            do: async (s) => { await s.click(s.page.locator("#mp_bank_all")); await h.soft(s, s.page.locator("#mp-bank-total")); } },
          { say: "Le compte qui paie : CCA Bank. La date : celle du virement.",
            do: async (s) => { await s.hover(s.page.locator("#mp_bank_treasury")); await s.page.locator("#mp_bank_treasury").selectOption({ label: "CCA Bank" }); await s.wait(0.4); await h.soft(s, s.page.locator("#mp_payment_date")); } },
          { say: "« Régler le Groupe Banque » : une seule écriture pour tout le virement, comme le relevé de la banque.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Régler le Groupe Banque/ })); await s.wait(2); } },
          { say: "Les bulletins sont payés : la grille et chaque bulletin disent maintenant « Payé », avec la date.",
            do: async (s) => {
              await press(s, "OK"); await s.wait(2.5);
              const ok = modal(s).getByRole("button", { name: "OK" });
              if (await ok.isVisible().catch(() => false)) await press(s, "OK");
              await s.click(s.page.locator("#modal-mark-paid").getByRole("button", { name: "Fermer" }).last()); await s.wait(0.8);
              await h.soft(s, gridRow(s, "Bertin").locator("span").filter({ hasText: /^Payé$/ }));
            } },
          "Un mois payé ne peut plus être annulé : son argent est sorti du compte.",
        ],
      }],
    },

    {
      name: "Ce que trouve le Comptable",
      shots: [{
        kind: "desktop", as: "daniel", role: "comptable", start: PAGE,
        lines: [
          { say: "Le Comptable n'a rien à ressaisir. Sur la fiche du mois, il retrouve l'écriture de paie.",
            do: async (s) => { await loadMonth(s, true); await s.click(gridRow(s, "Bertin").getByRole("button")); await s.wait(1.5); await scrollDetail(s, 1400); await h.soft(s, s.page.locator("#pdet_journal_table")); } },
          { say: "Au débit, le salaire brut en 661, la CNPS patronale en 664, et les taxes patronales en 6412.",
            speech: "Au débit, le salaire brut en six cent soixante et un, la CNPS patronale en six cent soixante-quatre, et les taxes patronales en six mille quatre cent douze.",
            do: (s) => h.soft(s, s.page.locator("#pdet_journal_table tbody tr").first()) },
          { say: "Au crédit, la CNPS en 433, les impôts sur salaires en 432, l'acompte repris en 421, et le net dû en 422.",
            speech: "Au crédit, la CNPS en quatre cent trente-trois, les impôts sur salaires en quatre cent trente-deux, l'acompte repris en quatre cent vingt et un, et le net dû en quatre cent vingt-deux.",
            do: (s) => h.soft(s, s.page.locator("#pdet_journal_table tbody tr").last()) },
          "Le total du mois sur le 432 est exactement la DIPE à déclarer ; celui du 433, la déclaration CNPS.",
          { say: "Le paiement a soldé le 422 par la banque. L'historique montre chaque bulletin, payé ou à payer.",
            do: async (s) => { await scrollDetail(s, 600); await h.soft(s, s.page.locator("#pdet_history_table")); } },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : un acompte se demande, s'approuve et se décaisse ; le bulletin le reprend.",
          "La paie se calcule une fois par mois, se reprend tant qu'elle n'est pas payée, et se paie avec « Marquer Payées ».",
          "La prochaine vidéo, Comptabilité 1, ouvre le plan comptable et le Grand Livre où ces écritures arrivent.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
