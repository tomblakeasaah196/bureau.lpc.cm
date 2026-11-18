"use strict";
/**
 * Administration, partie 2 : Utilisateurs, Rôles & Permissions. docs/TRAINING_VIDEOS.md says how a scene is
 * written and filmed.
 *
 * The support technique (JBS Praxis Support on screen) shows the accounts, how one is opened, changed and
 * locked, then the roles: what each may do, its home page, and a role made for the next Sales manager
 * (« Responsable Commercial », the owner expects one: runbook §3), granted, given its home page, then deleted
 * so the copy stays as LPC has it. Only admin pages are on screen (audience: the admin roles).
 *
 * Ground: snapshot pre-15-film. Every employee already has a login, so « Provisionner un compte » says so;
 * nothing is locked for real (the lock's window is shown and cancelled).
 */
const h = require("../helpers");

const PAGE = "/modules/settings/index.php";
const modal = (s) => s.page.locator("dialog[open]").last();
const press = async (s, label) => { await s.click(modal(s).getByRole("button", { name: label })); await s.wait(0.6); };
const okIf = async (s, seconds = 3) => {
  const ok = modal(s).getByRole("button", { name: "OK" });
  if (await ok.waitFor({ state: "visible", timeout: seconds * 1000 }).then(() => true, () => false)) { await s.click(ok); await s.wait(0.5); }
};
const tabBtn = (s, key) => s.page.locator(`#tab-${key}`);
const userRow = (s, name) => s.page.locator("#table-body tr").filter({ hasText: name }).first();
const actionModal = (s) => s.page.locator("#actionModal");
const closeAction = (s) => s.click(actionModal(s).getByRole("button", { name: "Annuler" }).last());
const roleChip = (s, label) => s.page.locator("#role-list button").filter({ hasText: label }).first();
const perm = (s, label) => s.page.locator("label.perm-row").filter({ hasText: label }).first();
const landing = (s) => s.page.locator("select[data-autosave]");
const ROLE = "Responsable Commercial";
const GRANTS = ["Voir le tableau de bord Ventes", "Voir la base clients", "Créer un client", "Modifier un client",
  "Voir les propositions/devis", "Créer un devis", "Voir les commandes", "Créer une commande"];

module.exports = {
  id: "admin-utilisateurs-roles",
  file: "LPC-ERP-Admin-2-Utilisateurs-Roles",
  meta: { title: "LPC ERP · Utilisateurs, Rôles & Permissions (Administration, partie 2)", artist: "JBS Praxis" },
  library: {
    slug: "admin-2-utilisateurs-roles",
    title: "Utilisateurs, Rôles & Permissions",
    subtitle: "Les comptes, ce que chaque rôle peut faire, et sa page d'accueil",
    series: "Administration",
    part: 2,
    parts: 3,
    pages: [PAGE],
  },
  opening: {
    eyebrow: "Formation LPC ERP · Administration, partie 2",
    title: "Utilisateurs, Rôles & Permissions",
    subtitle: "Les comptes, ce que chaque rôle peut faire, et sa page d'accueil",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Administration, partie 2",
    title: "Merci",
    subtitle: "À suivre : Centre d'Aide & Journal d'Erreurs",
    left: "bureau.lpc.cm",
  },
  holders: { support: "JBS Praxis Support" },

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
          "Bienvenue dans la deuxième vidéo de la série Administration.",
          "Le support technique y montre les comptes de l'application, puis les rôles : ce que chacun peut faire, et sa page d'accueil.",
          "Ces écrans sont réservés à l'Administrateur : le Directeur Général et le support technique.",
        ],
      }],
    },

    {
      name: "Les comptes",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=users`,
        lines: [
          { say: "Le support technique ouvre Paramètres Système & Audit, sur l'onglet Utilisateurs.",
            do: (s) => h.soft(s, tabBtn(s, "users")) },
          { say: "Chaque ligne est un compte : son matricule, son adresse de connexion, la personne, son rôle et son accès.",
            do: (s) => h.soft(s, userRow(s, "Daniel")) },
          { say: "Cinq comptes : le Directeur Général et le support technique, tous deux Administrateur, le Contrôleur Financier, le Comptable et le Responsable des Opérations.",
            do: (s) => h.tour(s, ["Timothée", "JBS Praxis", "Luc", "Daniel", "Bertin"].map((n) => userRow(s, n).locator("td").nth(3)), 0.5) },
          { say: "En haut, trois compteurs : les comptes, les comptes actifs, et les comptes verrouillés.",
            do: (s) => h.tour(s, ["#val-kpi1", "#val-kpi2", "#val-kpi3"].map((id) => s.page.locator(id)), 0.4) },
        ],
      }],
    },

    {
      name: "Ouvrir un compte",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=users`,
        lines: [
          "Une personne a d'abord sa fiche d'employé, dans Données de Base. Le compte lui donne l'accès.",
          { say: "« Provisionner un compte » ouvre l'accès d'un employé qui a déjà sa fiche.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Provisionner un compte/ })); await s.wait(1.2); } },
          { say: "Aujourd'hui, chaque employé a déjà son compte : l'application le dit, et renvoie vers Données de Base.",
            do: (s) => h.soft(s, actionModal(s).getByText("Aucun employé disponible.")) },
          { say: "« Inviter par email » crée la fiche et le compte d'un coup.",
            do: async (s) => { await closeAction(s); await s.wait(0.4); await s.click(s.page.getByRole("button", { name: /Inviter par email/ })); await s.wait(1); } },
          { say: "On saisit le prénom, le nom, l'adresse de connexion, le rôle et le poste.",
            do: (s) => h.tour(s, ["first_name", "last_name", "email", "role_id", "job_title"].map((n) => actionModal(s).locator(`[name="${n}"]`)), 0.4) },
          "La personne reçoit un lien pour choisir elle-même son mot de passe. Le lien vaut 72 heures.",
          { say: "Ici, personne n'est invité : le support technique annule.",
            do: (s) => closeAction(s) },
        ],
      }],
    },

    {
      name: "Modifier ou verrouiller",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=users`,
        lines: [
          { say: "Le crayon ouvre un compte : l'employé lié, l'adresse de connexion, le rôle et le mot de passe.",
            do: async (s) => { await s.click(userRow(s, "Daniel").getByRole("button", { name: "Modifier l'utilisateur" })); await s.wait(1); } },
          { say: "Le mot de passe reste vide pour garder l'actuel. Un nouveau remplace l'ancien tout de suite et ferme les sessions ouvertes de la personne.",
            do: (s) => h.soft(s, actionModal(s).locator('[name="password"]')) },
          { say: "Le cadenas verrouille un compte : la personne ne peut plus se connecter.",
            do: async (s) => { await closeAction(s); await s.wait(0.4); await s.click(userRow(s, "Bertin").getByRole("button", { name: "Verrouiller le compte" })); await s.wait(0.8); } },
          "Ses sessions ouvertes se ferment : à son prochain clic, elle revient à la page de connexion. Son historique reste à son nom.",
          { say: "C'est le geste à faire au départ d'un collaborateur, plutôt que supprimer. Ici, le support technique annule.",
            do: (s) => press(s, "Annuler") },
        ],
      }],
    },

    {
      name: "Les rôles",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=roles`,
        lines: [
          { say: "Les droits ne se donnent pas à une personne mais à un rôle. L'onglet Rôles les règle.",
            do: (s) => h.soft(s, tabBtn(s, "roles")) },
          { say: "À gauche, les rôles, avec leur nombre d'utilisateurs et de permissions.",
            do: (s) => h.soft(s, s.page.locator("#role-list")) },
          { say: "Le rôle Comptable : ses permissions, groupées par domaine, chacune en clair.",
            do: async (s) => { await s.click(roleChip(s, "Comptable")); await s.wait(1.2); await h.soft(s, s.page.locator("label.perm-row").first()); } },
          { say: "Chaque groupe montre combien de permissions sont accordées, et « Tout cocher / décocher » ouvre ou ferme le groupe d'un geste.",
            do: (s) => h.soft(s, s.page.locator("[data-mod-count]").first()) },
          { say: "La page d'accueil par défaut : le tableau de bord qui s'ouvre après la connexion. Pour le Comptable, la Vue Finance.",
            do: (s) => h.soft(s, landing(s)) },
          "Une personne déjà connectée reçoit ses nouveaux droits à sa prochaine connexion.",
        ],
      }],
    },

    {
      name: "Créer un rôle",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=roles`, snapshot: true,
        lines: [
          "LPC attend un Responsable Commercial. Il lui faut un rôle à sa mesure.",
          { say: "« Nouveau rôle », puis le nom tel qu'on le lira : Responsable Commercial.",
            do: async (s) => {
              await s.click(s.page.getByRole("button", { name: /Nouveau rôle/ })); await s.wait(0.6);
              await s.type(modal(s).locator(".lpc-modal-input"), ROLE, { delay: 70 });
            } },
          { say: "Le rôle est créé sans aucune permission : on accorde ce qu'il faut, rien de plus.",
            do: async (s) => { await press(s, "OK"); await s.wait(1.2); await okIf(s, 1.5); await s.click(roleChip(s, ROLE)); await s.wait(1.2); } },
          { say: "Le support technique coche le tableau de bord Ventes, les clients, les devis et les commandes.",
            do: async (s) => {
              for (const g of GRANTS) {
                const box = perm(s, g).locator("input");
                await box.evaluate((el) => el.scrollIntoView({ block: "center" })).catch(() => null);
                await s.click(box); await s.wait(0.15);
              }
            } },
          { say: "« Enregistrer les permissions ».",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: /Enregistrer les permissions/ })); await s.wait(1.2); await okIf(s); } },
          { say: "Puis sa page d'accueil : la Vue Ventes. Le choix s'enregistre aussitôt.",
            do: async (s) => {
              await landing(s).evaluate((el) => el.scrollIntoView({ block: "center" })).catch(() => null);
              await s.hover(landing(s)); await landing(s).selectOption({ label: "Ventes" }); await s.wait(1.2); await okIf(s, 1.5);
            } },
          "Le jour où il arrive, il suffit de lui ouvrir un compte avec ce rôle.",
        ],
      }],
    },

    {
      name: "La trace et la suppression",
      shots: [{
        kind: "desktop", as: "support", role: "support", start: `${PAGE}?tab=audits`, snapshot: true,
        lines: [
          { say: "Le Journal d'audit garde la trace : le rôle créé, puis la liste des permissions ajoutées.",
            do: (s) => h.soft(s, s.page.locator("#table-body tr").filter({ hasText: "Permissions" }).first()) },
          { say: "Pour la démonstration, le rôle d'exemple est maintenant supprimé.",
            do: async (s) => { await s.click(tabBtn(s, "roles")); await s.wait(1.2); await s.click(roleChip(s, ROLE)); await s.wait(1.2); await s.click(s.page.locator("#perm-panel").getByRole("button", { name: /Supprimer/ })); await s.wait(0.6); } },
          { say: "Ses permissions partent avec lui. Un rôle encore donné à quelqu'un ne peut pas être supprimé, ni les rôles de base.",
            do: async (s) => { await press(s, "Confirmer"); await s.wait(1); await okIf(s, 1.5); } },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "En résumé : un compte donne l'accès, son rôle dit ce que la personne peut faire, et le cadenas coupe l'accès sans rien effacer.",
          "Un rôle se crée par son nom, part de zéro permission, et reçoit sa page d'accueil.",
          "La prochaine vidéo présente le Centre d'Aide et le Journal d'Erreurs.",
          "Merci de votre attention.",
        ],
      }],
    },
  ],
};
