"use strict";
/**
 * Administration, partie 1 : Identité & Paramètres. docs/TRAINING_VIDEOS.md says how a scene is written and
 * filmed.
 *
 * The admin series is for the people who run the system: the Directeur Général and the support technique
 * (JBS Praxis Support, the IT partner). Both hold « Administrateur ». Narrated by role (runbook §2).
 *
 * Every page on screen is an admin page (Paramètres needs admin.settings.view or the company/prefs views),
 * so the library shows the video to the admin roles only (docs/HELP_VIDEOS.md: a video's audience is
 * whoever may open one of its pages).
 *
 * Ground: snapshot pre-15-film = after-10-salaires + migrations 137 to 141, and the copy-only preparation
 * (VIDEO_QA_FINDINGS 15.8: the copy's AI keys blanked, the two uploaded logos drawn from the repo's logo).
 * The scene writes one preference and puts it back (Lignes par page 25 → 50 → 25), so the audit trail has
 * a real change to show.
 */
const h = require("../helpers");

const PAGE = "/modules/settings/index.php";
const modal = (s) => s.page.locator("dialog[open]").last();
const okIf = async (s, seconds = 3) => {
  const ok = modal(s).getByRole("button", { name: "OK" });
  if (await ok.waitFor({ state: "visible", timeout: seconds * 1000 }).then(() => true, () => false)) { await s.click(ok); await s.wait(0.5); }
};
const section = (s, title) => s.page.locator("#panel-region").getByText(title, { exact: true }).first();
const toSection = async (s, title) => {
  const loc = section(s, title);
  await loc.evaluate((el) => el.scrollIntoView({ behavior: "smooth", block: "start" })).catch(() => null);
  await s.wait(0.8);
  await h.soft(s, loc);
};
const save = (s) => s.page.getByRole("button", { name: /Enregistrer/ }).filter({ visible: true }).last();
const pageSize = (s) => s.page.locator('[data-field="ui_page_size"]');
const tabBtn = (s, key) => s.page.locator(`#tab-${key}`);

module.exports = {
  id: "admin-identite-parametres",
  file: "LPC-ERP-Admin-1-Identite-Parametres",
  meta: { title: "LPC ERP · Identité & Paramètres (Administration, partie 1)", artist: "JBS Praxis" },
  library: {
    slug: "admin-1-identite-parametres",
    title: "Identité & Paramètres",
    subtitle: "La fiche de l'entreprise, les préférences, le journal d'audit et les sessions",
    series: "Administration",
    part: 1,
    parts: 3,
    pages: [PAGE],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Administration, partie 1",
    title: "Identité & Paramètres",
    subtitle: "La fiche de l'entreprise, les préférences, le journal d'audit et les sessions",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Administration, partie 1",
    title: "Merci",
    subtitle: "À suivre : Utilisateurs, Rôles & Permissions",
    left: "bureau.lpc.cm",
  },
  holders: { dg: "Timothée Massomba", support: "JBS Praxis Support" },

  async prepare({ log, ground }) {
    ground.restore("pre-15-film");
    log("ground: pre-15-film restored");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la série Administration de LPC ERP.",
          "Elle s'adresse à ceux qui font tourner l'application : le Directeur Général et le support technique.",
          "Cette première vidéo présente la fiche de l'entreprise, les préférences du système, et la trace que laisse chaque changement.",
        ],
      }],
    },

    {
      name: "Identité de l'Entreprise",
      shots: [{
        kind: "desktop", as: "timothee", role: "dg", start: `${PAGE}?tab=company`,
        lines: [
          { say: "Le Directeur Général ouvre Identité de l'Entreprise, dans Administration & RH.",
            do: (s) => h.soft(s, h.shell.menu(s, "Identité de l'Entreprise")) },
          { say: "En haut, l'aperçu de l'en-tête : le bloc imprimé en haut de chaque facture, devis et bon de livraison.",
            do: (s) => h.soft(s, section(s, "Aperçu de l'en-tête")) },
          { say: "Puis l'identité légale : la raison sociale, la forme juridique, le RCCM et le NIU.",
            do: (s) => toSection(s, "Identité légale") },
          "Ces informations doivent correspondre exactement au registre du commerce.",
          { say: "Le régime fiscal fixe les taux appliqués : au régime du réel, l'AIR est de 2,2 %.",
            speech: "Le régime fiscal fixe les taux appliqués : au régime du réel, l'AIR est de deux virgule deux pour cent.",
            do: (s) => toSection(s, "Régime fiscal & social") },
          { say: "Viennent l'adresse officielle, les téléphones et les adresses email, dont celle de la facturation.",
            do: (s) => toSection(s, "Coordonnées") },
          { say: "Les coordonnées bancaires s'impriment sur les factures, pour que le client sache où payer.",
            do: (s) => toSection(s, "Coordonnées bancaires") },
          { say: "La marque : le nom affiché de l'application, ses couleurs et ses logos.",
            do: (s) => toSection(s, "Marque & identité de l'application") },
          { say: "Enfin, le pied de page : les mentions légales imprimées en bas de chaque document.",
            do: (s) => toSection(s, "Pied de page des documents") },
          { say: "Rien n'est écrit avant « Enregistrer » : la barre du bas compte les champs modifiés.",
            do: (s) => h.soft(s, save(s)) },
        ],
      }],
    },

    {
      name: "Les préférences",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=users`,
        lines: [
          { say: "Le support technique ouvre Paramètres Système & Audit, puis l'onglet Préférences.",
            do: async (s) => { await h.soft(s, h.shell.menu(s, "Paramètres Système & Audit")); await s.click(tabBtn(s, "preferences")); await s.wait(1.2); } },
          { say: "D'abord la numérotation des documents : le début du numéro des factures, des devis, des bons de livraison et des commandes.",
            do: (s) => toSection(s, "Numérotation des documents") },
          "Changer le format en cours d'année romprait la suite des numéros que la DGI exige.",
          { say: "La fiscalité : la TVA à 19,25 %, l'AIR, l'impôt sur les sociétés, le groupe CNPS.",
            speech: "La fiscalité : la TVA à dix-neuf virgule vingt-cinq pour cent, l'AIR, l'impôt sur les sociétés, le groupe CNPS.",
            do: (s) => toSection(s, "Fiscalité & taux") },
          { say: "L'affichage : la langue, le fuseau horaire de Douala, et le nombre de lignes par page des tableaux.",
            do: (s) => toSection(s, "Localisation & affichage") },
          { say: "La sécurité : la déconnexion automatique après 120 minutes sans activité, et le nombre d'essais de connexion.",
            speech: "La sécurité : la déconnexion automatique après cent vingt minutes sans activité, et le nombre d'essais de connexion.",
            do: (s) => toSection(s, "Politique de sécurité") },
          { say: "Plus bas, les charges patronales de la paie, et les comptes utilisés quand une immobilisation est cédée.",
            do: (s) => toSection(s, "Comptes par défaut") },
        ],
      }],
    },

    {
      name: "Changer un réglage",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=preferences`, snapshot: true,
        lines: [
          { say: "Pour l'exemple, le support technique passe le nombre de lignes par page de 25 à 50.",
            do: async (s) => { await toSection(s, "Localisation & affichage"); await s.type(pageSize(s), "50", { delay: 140 }); } },
          { say: "Le champ modifié se colore, et la barre du bas compte une modification.",
            do: (s) => h.soft(s, s.page.locator("#form-dirty-count")) },
          { say: "« Enregistrer » : l'application confirme le champ mis à jour.",
            do: async (s) => { await s.click(save(s)); await s.wait(1.5); } },
          { say: "Les tableaux de l'application affichent désormais cinquante lignes par page.",
            do: (s) => okIf(s) },
        ],
      }],
    },

    {
      name: "Le journal d'audit",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=audits`, snapshot: true,
        lines: [
          { say: "Chaque changement laisse une trace. L'onglet Journal d'audit dit qui a changé quoi, et quand.",
            do: (s) => h.soft(s, tabBtn(s, "audits")) },
          { say: "En tête, la modification qui vient d'être faite : les préférences, lignes par page, de 25 à 50.",
            do: (s) => h.soft(s, s.page.locator("#table-body tr").first()) },
          { say: "« Détail » montre chaque champ changé, sa valeur avant et après, son auteur et l'heure.",
            do: async (s) => { await s.click(s.page.locator("#table-body tr").first().getByRole("button", { name: "Détail" })); await s.wait(0.8); } },
          { say: "Le journal ne se modifie pas, même par un administrateur : c'est ce qui en fait une preuve.",
            do: async (s) => { await s.wait(1.5); await s.click(modal(s).getByRole("button", { name: "Fermer" })); } },
          { say: "Le support technique remet la valeur d'origine, 25 lignes, et enregistre.",
            do: async (s) => {
              await s.click(tabBtn(s, "preferences")); await s.wait(1.2);
              await toSection(s, "Localisation & affichage");
              await s.type(pageSize(s), "25", { delay: 140 });
              await s.click(save(s)); await s.wait(1.2); await okIf(s);
            } },
          { say: "Ce retour est tracé à son tour, en tête du journal.",
            do: async (s) => { await s.click(tabBtn(s, "audits")); await s.wait(1.2); await h.soft(s, s.page.locator("#table-body tr").first()); } },
        ],
      }],
    },

    {
      name: "Les sessions",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=sessions`,
        lines: [
          { say: "L'onglet Sessions est le journal des connexions, réussies ou refusées.",
            do: (s) => h.soft(s, tabBtn(s, "sessions")) },
          { say: "« Connectés maintenant » compte les personnes dont la session sert encore.",
            do: (s) => h.soft(s, s.page.locator("#val-kpi1")) },
          { say: "Les mots de passe erronés et les connexions refusées des dernières 24 heures signalent une tentative suspecte.",
            speech: "Les mots de passe erronés et les connexions refusées des dernières vingt-quatre heures signalent une tentative suspecte.",
            do: (s) => h.tour(s, [s.page.locator("#val-kpi2"), s.page.locator("#val-kpi3")], 0.6) },
          { say: "« Déconnecter » ferme une session à distance : la personne revient à la page de connexion à son prochain clic.",
            do: (s) => h.soft(s, s.page.locator("#table-body").getByRole("button", { name: /Déconnecter/ }).first()) },
          "C'est le geste à faire pour un poste partagé resté ouvert, ou devant une activité inhabituelle.",
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : la fiche de l'entreprise s'imprime sur chaque document ; les préférences règlent la numérotation, les taux et la sécurité.",
          "Chaque changement est écrit dans le journal d'audit, avec sa valeur avant et après.",
          "La prochaine vidéo, Utilisateurs, Rôles et Permissions, montre qui peut faire quoi, et comment le régler.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
