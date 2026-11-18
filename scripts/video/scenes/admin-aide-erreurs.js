"use strict";
/**
 * Administration, partie 3 : Centre d'Aide & Journal d'Erreurs. docs/TRAINING_VIDEOS.md says how a scene is
 * written and filmed.
 *
 * The support technique (JBS Praxis Support on screen) writes a help article and keeps it as a draft,
 * publishes a training video in Centre d'Aide (vidéos) (docs/HELP_VIDEOS.md), and reads the Journal
 * d'Erreurs, marking fixed the one error the copy has: the duplicate payroll run of 18/11/2026, which the
 * Salaires video's fixes (PR #59) turned into a sentence. Only admin pages are on screen: Centre d'Aide
 * (articles) needs help.manage, the Journal d'Erreurs admin.errors.view; the videos page is outside the menu
 * and grants nothing (audience: the admin roles).
 *
 * Ground: snapshot pre-15-film (nine finished videos already in the copy's library; the Salaires video is
 * uploaded on camera from VIDEO_HOME/out/salaires) and the copy's PHP log as saved with it
 * (snapshots/pre-15-film.php-error.log, put back by prepare).
 */
const fs = require("node:fs");
const path = require("node:path");
const h = require("../helpers");

const ARTICLES = "/modules/admin/help_articles.php";
const VIDEOS = "/modules/admin/help_videos.php";
const ERRORS = "/modules/admin/error_monitor.php";
const modal = (s) => s.page.locator("dialog[open]").last();
const form = (s) => s.page.locator("#help-article-form");
const fld = (s, name) => form(s).locator(`[name="${name}"]`);
const group = (s) => s.page.locator("[data-level]").filter({ hasText: "payroll_controller" }).first();

module.exports = {
  id: "admin-aide-erreurs",
  file: "LPC-ERP-Admin-3-Aide-Erreurs",
  meta: { title: "LPC ERP · Centre d'Aide & Journal d'Erreurs (Administration, partie 3)", artist: "JBS Praxis" },
  library: {
    slug: "admin-3-aide-erreurs",
    title: "Centre d'Aide & Journal d'Erreurs",
    subtitle: "Écrire un article, publier une vidéo de formation, suivre les erreurs du serveur",
    series: "Administration",
    part: 3,
    parts: 3,
    pages: [ARTICLES, ERRORS],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Administration, partie 3",
    title: "Centre d'Aide & Journal d'Erreurs",
    subtitle: "Écrire un article, publier une vidéo de formation, suivre les erreurs du serveur",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Administration, partie 3",
    title: "Merci",
    subtitle: "Fin de la série Administration",
    left: "bureau.lpc.cm",
  },
  holders: { support: "JBS Praxis Support" },

  async prepare({ log, ground }) {
    ground.restore("pre-15-film");
    const saved = path.join(ground.HOME, "snapshots", "pre-15-film.php-error.log");
    if (fs.existsSync(saved)) fs.copyFileSync(saved, path.join(ground.HOME, "php-error.log"));
    log("ground: pre-15-film restored, with its PHP log");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans la troisième vidéo de la série Administration.",
          "Le support technique y écrit un article d'aide, publie une vidéo de formation, puis suit les erreurs du serveur.",
          "Ces pages sont réservées à l'Administrateur.",
        ],
      }],
    },

    {
      name: "Les articles d'aide",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: ARTICLES,
        lines: [
          { say: "Le support technique ouvre Centre d'Aide, articles, dans Administration & RH.",
            do: (s) => h.soft(s, h.shell.menu(s, "Centre d'Aide (articles)")) },
          { say: "Chaque ligne est un article : son titre, sa catégorie, qui peut le lire, et la page qu'il documente.",
            do: (s) => h.soft(s, s.page.locator("tbody tr").first()) },
          { say: "Le bouton « Aide » de cette page ouvre l'article qui la documente, et la vidéo qui la montre.",
            do: (s) => h.soft(s, s.page.locator(".lpc-help-btn").first()) },
          { say: "La colonne État dit s'il est publié, ou encore en brouillon, visible des seuls rédacteurs.",
            do: (s) => h.soft(s, s.page.locator("[data-help-toggle]").first()) },
        ],
      }],
    },

    {
      name: "Écrire un article",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: ARTICLES, snapshot: true,
        lines: [
          { say: "« Nouvel article ». Un article que tout le monde attend : que faire devant « Erreur serveur ».",
            do: async (s) => { await s.click(s.page.getByRole("link", { name: /Nouvel article/ }).or(s.page.getByRole("button", { name: /Nouvel article/ })).first()); await s.wait(1); } },
          { say: "La catégorie, l'adresse courte de l'article, et qui peut le lire : ici, toute personne connectée.",
            do: async (s) => {
              await s.hover(fld(s, "category_id")); await fld(s, "category_id").selectOption({ label: "Utiliser le centre d'aide" }); await s.wait(0.3);
              await s.type(fld(s, "slug"), "erreur-serveur-que-faire", { delay: 45 });
              await h.soft(s, fld(s, "required_permission"));
            } },
          { say: "Il restera en brouillon le temps d'être relu : « Publié » est décoché.",
            do: async (s) => { await s.click(fld(s, "is_published")); } },
          { say: "Le titre, un résumé d'une ligne, puis le texte, avec ses intertitres et son gras.",
            do: async (s) => {
              await s.type(fld(s, "title_fr"), "Que faire devant « Erreur serveur » ?", { delay: 40 });
              await s.type(fld(s, "summary_fr"), "Noter l'heure et la page, puis prévenir le support technique.", { delay: 30 });
              await s.type(fld(s, "body_fr"), "## Que faire\n\nNotez **l'heure** et **la page**, puis prévenez le support technique.\n\nIl retrouve l'erreur dans le Journal d'Erreurs et la corrige.", { delay: 18 });
            } },
          { say: "« Aperçu FR » montre l'article tel que les lecteurs le verront.",
            do: async (s) => { await s.click(s.page.locator("#help-preview-btn")); await s.wait(1); await s.page.locator("#help-preview").evaluate((el) => el.scrollIntoView({ behavior: "smooth", block: "center" })).catch(() => null); await s.wait(0.6); } },
          { say: "« Enregistrer l'article » : il est enregistré, en brouillon.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Enregistrer l'article/ })); await s.wait(1.2); await h.soft(s, s.page.locator("#help-save-status")); } },
          { say: "Dans la liste, un clic sur « Brouillon » le publiera, le jour où il est relu.",
            do: async (s) => { await s.go(ARTICLES); await s.wait(0.8); const row = s.page.locator("tbody tr").filter({ hasText: "erreur-serveur-que-faire" }).first(); await row.evaluate((el) => el.scrollIntoView({ behavior: "smooth", block: "center" })).catch(() => null); await s.wait(0.6); await h.soft(s, row.locator("[data-help-toggle]")); } },
        ],
      }],
    },

    {
      name: "Publier une vidéo",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: ARTICLES, snapshot: true,
        lines: [
          { say: "L'onglet Vidéos tient la bibliothèque des vidéos de formation, comme celle-ci.",
            do: async (s) => { await s.click(s.page.getByRole("tab", { name: /Vidéos/ }).first()); await s.wait(1.2); } },
          { say: "Chaque tournage produit quatre fichiers : la vidéo, son manifeste, ses sous-titres et son affiche.",
            do: (s) => h.soft(s, s.page.getByText("Déposez les fichiers de la vidéo ici")) },
          { say: "Le support technique dépose les quatre fichiers de la vidéo Salaires.",
            do: async (s) => {
              const dir = path.join(require("../ground").HOME, "out", "salaires");
              await s.page.locator("#vadm-files").setInputFiles(["mp4", "manifest.json", "fr.vtt", "jpg"].map((e) => path.join(dir, `LPC-ERP-Salaires.${e}`)));
              await s.wait(1.2);
            } },
          { say: "L'application vérifie que les fichiers vont ensemble, puis envoie la vidéo par morceaux.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Envoyer la vidéo/ })); await s.wait(2); } },
          { say: "« Publier dès l'envoi » la rend visible tout de suite ; décoché, elle reste un brouillon.",
            do: async (s) => {
              await h.soft(s, s.page.getByText("Publier dès l'envoi"));
              await s.page.waitForFunction(() => /Salaires/.test(document.querySelector("tbody")?.innerText || ""), null, { timeout: 90000 }).catch(() => null);
              await s.wait(0.5);
            } },
          { say: "La voici dans la liste : sa série, sa durée, ses chapitres, et qui la voit.",
            do: async (s) => { const row = s.page.locator("tbody tr").filter({ hasText: "Salaires" }).first(); await row.evaluate((el) => el.scrollIntoView({ behavior: "smooth", block: "center" })).catch(() => null); await s.wait(0.6); await h.soft(s, row); } },
          "Une vidéo est vue par les personnes qui peuvent ouvrir les pages qu'elle montre : celle des salaires, par les rôles de la paie.",
        ],
      }],
    },

    {
      name: "Le Journal d'Erreurs",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: ERRORS, snapshot: true,
        lines: [
          { say: "Quand quelqu'un voit « Erreur serveur », le support technique ouvre le Journal d'Erreurs.",
            do: (s) => h.soft(s, h.shell.menu(s, "Journal d'Erreurs")) },
          { say: "En haut, les erreurs des dernières 24 heures, celles encore ouvertes, et un graphique heure par heure.",
            speech: "En haut, les erreurs des dernières vingt-quatre heures, celles encore ouvertes, et un graphique heure par heure.",
            do: (s) => h.tour(s, [s.page.locator("p").filter({ hasText: /^24h/i }).first(), s.page.locator("p").filter({ hasText: /^Erreurs ouvertes/i }).first(), s.page.getByText(/Erreurs par heure/i).first()], 0.6) },
          { say: "Plus bas, les erreurs regroupées : une même erreur au même endroit n'occupe qu'une ligne, avec son nombre.",
            do: async (s) => { await group(s).evaluate((el) => el.scrollIntoView({ behavior: "smooth", block: "center" })).catch(() => null); await s.wait(0.8); await h.soft(s, group(s)); } },
          "Celle-ci date du tournage de la vidéo Salaires : une paie calculée deux fois. Elle est corrigée depuis : l'application le dit maintenant en clair.",
          { say: "« Copier » met l'erreur complète dans le presse-papiers, prête à transmettre.",
            do: (s) => h.soft(s, group(s).getByRole("button", { name: /Copier/ })) },
          { say: "Une fois corrigée, « Marquer corrigé » la range dans les erreurs corrigées.",
            do: async (s) => { await s.click(group(s).getByRole("button", { name: /Marquer corrigé/ })); await s.wait(1.5); await h.soft(s, s.page.getByText(/marqué\(s\) corrigé\(s\)/).first()); } },
          { say: "Si elle revient un jour, elle repasse d'elle-même dans les erreurs ouvertes.",
            do: async (s) => { const b = s.page.getByRole("link", { name: /Corrigées/ }).or(s.page.getByRole("button", { name: /Corrigées/ })).first(); await b.evaluate((el) => el.scrollIntoView({ block: "center" })).catch(() => null); await s.click(b); await s.wait(1.2); await h.soft(s, group(s)); } },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : un article s'écrit, se prévisualise et reste en brouillon jusqu'à sa relecture.",
          "Une vidéo de formation se publie en déposant ses quatre fichiers ; elle est vue par ceux qui ouvrent ses pages.",
          "Le Journal d'Erreurs montre ce qui a échoué ; une erreur corrigée se marque, et revient si elle se reproduit.",
          "C'est la fin de la série Administration. Merci de votre attention.",
        ],
      }],
    },
  ],
};
