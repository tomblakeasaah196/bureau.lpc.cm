"use strict";
/**
 * Introduction, partie 1 : Premiers pas. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * Daniel Massomba, the accountant (Comptable), on his computer: signing in, a forgotten password and
 * the real reset email, the menu, the top bar (search, quick create, notifications, language), his
 * home page Vue Finance and a KPI's detail, his account menu and theme, the help (the page's « Aide »,
 * the Centre d'aide and its training videos), then remembering his computer with a 4-digit code,
 * signing out and coming back with the code. LPC's real people and data, on the filming copy
 * (decided with the owner, 17/11/2026). About seven minutes.
 *
 * The Centre d'aide chapter (c07) is filmed again once this video is in the copy's library, so it can
 * show itself there (runbook §4.3).
 */
const ground = require("../ground");
const h = require("../helpers");

const DANIEL = "daniel.massomba@lpc.cm";
const PIN = "2580";
const HOME = "/modules/dashboard/views/finance_dashboard.php";

/** The sidebar's section headers (<summary>), by name. */
const section = (s, name) => s.page.locator("#lpc-sidebar summary").filter({ hasText: new RegExp(`^\\s*${name}\\s*$`, "i") }).first();
/** The open popover or window that holds `text`. */
const windowWith = (s, text) => s.page.locator(".lpc-modal, [role=dialog], .fixed").filter({ hasText: text }).last();

module.exports = {
  id: "intro-1-premiers-pas",
  file: "LPC-ERP-Introduction-1-Premiers-Pas",
  meta: { title: "LPC ERP · Premiers pas (Introduction, partie 1)", artist: "JBS Praxis" },
  library: {
    slug: "intro-1-premiers-pas",
    title: "Premiers pas",
    subtitle: "Se connecter et découvrir votre espace de travail",
    series: "Introduction",
    part: 1,
    parts: 2,
    audience: "all",
  },
  opening: {
    eyebrow: "Formation LPC ERP · Introduction, partie 1",
    title: "Premiers pas",
    subtitle: "Se connecter et découvrir votre espace de travail",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Introduction, partie 1",
    title: "Merci",
    subtitle: "À suivre : Chacun son espace",
    left: "bureau.lpc.cm",
  },
  signsIn: ["daniel"],

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue sur LPC ERP, le bureau en ligne de La Petite Cour.",
          "Ventes, stock, emballages, comptabilité et paie : tout le travail du bureau se fait ici, au même endroit.",
          "Cette vidéo vous apprend à vous connecter, puis à vous repérer dans votre espace de travail.",
          "Nous suivons Daniel Massomba, le comptable, sur son ordinateur.",
          "Les écrans, les noms et les chiffres que vous allez voir sont ceux de La Petite Cour.",
        ],
      }],
    },

    {
      name: "Se connecter",
      shots: [{
        kind: "desktop", start: "/", still: true,
        lines: [
          "Votre compte est créé par l'administrateur. Il vous donne votre adresse et un premier mot de passe.",
          { say: "Ouvrez votre navigateur à l'adresse bureau.lpc.cm. La page de connexion s'affiche.",
            do: (s) => h.soft(s, h.heading(s, "LA PETITE COUR SARL")) },
          { say: "En haut à droite, FR et EN choisissent la langue. Nous restons en français.",
            do: (s) => h.soft(s, h.link(s, "FR")) },
          { say: "Dans « Adresse Email », Daniel saisit son adresse professionnelle.",
            do: (s) => s.type("Adresse Email", DANIEL) },
          { say: "Puis son mot de passe, dans « Mot de Passe ». L'œil, à droite, affiche ce qu'il tape.",
            do: async (s) => { await s.type("Mot de Passe", s.password, { delay: 70 }); await h.soft(s, h.button(s, "Afficher le mot de passe")); } },
          { say: "La case « Se souvenir de cet appareil » évite le mot de passe la prochaine fois. Nous la verrons à la fin.",
            do: (s) => h.soft(s, s.page.getByRole("checkbox")) },
          { say: "Il appuie sur « Accéder au Bureau ».",
            do: (s) => s.click("Accéder au Bureau") },
          { say: "Le voici sur sa page d'accueil, la Vue Finance. Chaque rôle arrive sur la sienne.",
            before: (s) => h.shell.user(s).waitFor({ state: "visible", timeout: 30000 }).then(() => s.settle(900)) },
        ],
      }],
    },

    {
      name: "Mot de passe oublié",
      shots: [{
        kind: "desktop", start: "/", still: true,
        // Mailpit is the copy's mailbox: start it empty, so the email on screen is the one this take asks for.
        setup: () => fetch("http://127.0.0.1:8025/api/v1/messages", { method: "DELETE" }).catch(() => null),
        lines: [
          "Un mot de passe oublié ? Pas d'inquiétude.",
          { say: "Sous le formulaire, appuyez sur « Mot de passe ou adresse oubliés ? ».",
            do: (s) => s.click("Mot de passe ou adresse oubliés ?") },
          { say: "Saisissez votre adresse, puis appuyez sur « Recevoir le lien ».",
            do: async (s) => { await s.type("Adresse email", DANIEL); await s.click("Recevoir le lien"); } },
          { say: "La réponse est la même que l'adresse existe ou non. C'est voulu : elle protège les comptes de vos collègues.",
            do: (s) => h.soft(s, h.text(s, /Si un compte actif correspond/, false)) },
          { say: "Adresse oubliée ? Votre matricule, inscrit sur votre bulletin de paie, la retrouve, en partie masquée.",
            do: (s) => h.soft(s, s.page.getByLabel("Matricule")) },
          { say: "Le lien arrive dans votre boîte mail. Il est valable une heure, et ne sert qu'une seule fois.",
            do: async (s) => {
              const mail = await ground.mailTo(DANIEL, { subject: /Réinitialisation/ });
              if (!mail) throw new Error("No reset email in Mailpit: is it running (runbook §4.1)?");
              await s.page.setContent(h.mailPage(mail));
              await s.settle(600);
            } },
          "Il ouvre une page où vous choisissez votre nouveau mot de passe, puis vous vous connectez avec.",
        ],
      }],
    },

    {
      name: "Le menu",
      shots: [{
        kind: "desktop", as: "daniel", start: HOME,
        lines: [
          "Voici l'espace de travail : le menu à gauche, la barre d'outils en haut, et la page au centre.",
          { say: "En haut du menu, « Courrier » ouvre la messagerie de Daniel. Un chiffre y signale les messages non lus.",
            do: (s) => h.soft(s, h.shell.menu(s, /^Courrier/)) },
          { say: "Les modules sont rangés en cinq rubriques, de la stratégie à l'administration.",
            do: (s) => h.tour(s, ["STRATÉGIE & BI", "COMMERCE & CRM", "LOGISTIQUE & STOCK", "COMPTABILITÉ & FINANCE", "ADMINISTRATION & RH"].map((n) => section(s, n)), 0.35) },
          { say: "Un clic ouvre une rubrique. Daniel ouvre Comptabilité & Finance.",
            do: (s) => s.click(section(s, "COMPTABILITÉ & FINANCE")) },
          { say: "Il y trouve la facturation, les dépenses, les écritures, le Grand Livre OHADA et la trésorerie.",
            do: (s) => h.tour(s, ["Facturation & AR", "Gestion des Dépenses", "Écritures", "Grand Livre OHADA", "Trésorerie & Banque"].map((n) => h.shell.menu(s, n)), 0.3) },
          "Chacun ne voit que les pages que son rôle lui ouvre. Le menu de Bertin, aux opérations, n'est pas celui de Daniel.",
          { say: "La flèche au bord du menu le réduit, pour laisser plus de place à la page. Un second clic le rouvre.",
            do: async (s) => {
              const toggle = s.page.getByRole("button", { name: /le menu$/i }).first();
              await s.click(toggle);
              await s.wait(1.2);
              await s.click(toggle);
            } },
        ],
      }],
    },

    {
      name: "La barre du haut",
      shots: [{
        kind: "desktop", as: "daniel", start: HOME,
        lines: [
          { say: "En haut, le titre de la page et son service : Vue Finance, Direction Financière.",
            do: (s) => h.soft(s, s.page.locator("header h1, [role=banner] h1").first()) },
          { say: "La recherche trouve une page, une action ou un article d'aide. Appuyez sur Ctrl et K.",
            do: async (s) => { await h.soft(s, s.page.getByRole("button", { name: /^Rechercher/ })); await s.key("Control+k"); } },
          { say: "Daniel tape « facture ».",
            do: (s) => s.page.keyboard.type("facture", { delay: 110 }) },
          { say: "Les résultats sont groupés : les pages, les actions, puis l'aide. Échap referme la recherche.",
            do: async (s) => { await s.wait(1.6); await s.key("Escape"); } },
          { say: "Le bouton plus, « Création rapide », propose ce que votre rôle peut créer. Pour Daniel : une facture ou une commande fournisseur.",
            do: (s) => s.click("Création rapide") },
          { say: "Une facture part toujours des livraisons. « Nouvelle facture » ouvre donc les BL non facturés.",
            do: (s) => s.click(s.page.getByRole("menuitem", { name: "Nouvelle facture" })) },
          { say: "Aujourd'hui, aucun BL n'attend sa facture. Daniel n'a rien à faire ici.",
            do: (s) => h.soft(s, s.page.getByRole("button", { name: /^BL Non Factur/i })) },
          { say: "La cloche réunit les notifications : un stock bas, un client converti, une commande reçue.",
            do: (s) => s.click(s.page.getByRole("button", { name: /^Notifications/ })) },
          { say: "Chacune mène à la bonne page. « Voir tout » ouvre l'historique complet.",
            do: (s) => h.soft(s, h.link(s, "Voir tout")) },
          { say: "Enfin, FR et EN, à droite, changent la langue de l'application à tout moment.",
            do: async (s) => { await s.key("Escape"); await h.soft(s, s.page.getByRole("group", { name: "Langue" }).first()); } },
        ],
      }],
    },

    {
      name: "La page d'accueil",
      shots: [{
        kind: "desktop", as: "daniel", start: HOME,
        lines: [
          "La Vue Finance est la page d'accueil de Daniel.",
          { say: "Ces boutons choisissent la période : aujourd'hui, sept jours, ce mois, le trimestre, l'année ou des dates choisies.",
            do: (s) => h.tour(s, ["Aujourd'hui", "7 jours", "Ce mois", "Trimestre", "YTD", "Personnalisé"].map((n) => h.button(s, n)), 0.25) },
          { say: "Cinq indicateurs suivent : la trésorerie, les créances clients, les dettes fournisseurs, les validations et la marge.",
            do: (s) => h.tour(s, [/^Trésorerie actuelle/, /^Créances clients/, /^Dettes fournisseurs/, /^Validations en attente/, /^Marge nette/].map((n) => s.page.getByRole("button", { name: n })), 0.3) },
          { say: "Chaque carte s'ouvre sur son détail. Daniel ouvre les dettes fournisseurs.",
            do: (s) => s.click(s.page.getByRole("button", { name: /^Dettes fournisseurs/ })) },
          { say: "Le détail liste les montants dus, avec un export CSV et un lien vers la page d'origine, ici les achats.",
            do: async (s) => { await s.wait(1); await h.soft(s, windowWith(s, "Dettes fournisseurs").getByRole("link", { name: "Ouvrir les achats" }).or(windowWith(s, "Dettes fournisseurs").getByRole("button", { name: "Ouvrir les achats" })).first()); } },
          { say: "Il referme le détail.",
            do: (s) => s.click(windowWith(s, "Dettes fournisseurs").getByRole("button", { name: "Fermer" })) },
          { say: "Plus bas, les graphiques suivent la trésorerie et l'âge des créances. Ils se remplissent au fil des factures et des paiements.",
            do: (s) => s.scroll(420) },
        ],
      }],
    },

    {
      name: "Votre compte et l'aide",
      shots: [{
        kind: "desktop", as: "daniel", start: HOME,
        lines: [
          { say: "En bas du menu, le nom de Daniel ouvre son compte.",
            do: (s) => s.click(h.shell.user(s)) },
          { say: "« Mon profil » montre sa fiche, et « Changer le mot de passe » en choisit un nouveau à tout moment.",
            do: (s) => h.tour(s, [s.page.getByRole("menuitem", { name: "Mon profil" }), s.page.getByRole("menuitem", { name: "Changer le mot de passe" })], 0.5) },
          { say: "Le thème se règle ici aussi. Daniel essaie le thème sombre.",
            do: (s) => s.click(h.shell.userMenu(s).getByRole("button", { name: "Sombre" })) },
          { say: "Tout l'écran passe en sombre, et le choix est gardé. Il revient au thème clair.",
            do: async (s) => {
              await s.wait(1.8);
              if (!(await h.shell.userMenu(s).isVisible())) await s.click(h.shell.user(s));
              await s.click(h.shell.userMenu(s).getByRole("button", { name: "Clair" }));
              await s.key("Escape");
            } },
          { say: "Chaque page a son aide. Le bouton « Aide » ouvre l'article de la page, sans la quitter.",
            do: (s) => s.click(h.button(s, "Aide sur cette page")) },
          { say: "Ici, la Vue Finance expliquée pas à pas.",
            do: async (s) => { await s.wait(1.5); await s.scroll(250, s.page.locator("#lpc-help-drawer")); } },
          { say: "Le point d'interrogation, en haut, ouvre le Centre d'aide.",
            do: async (s) => { await s.key("Escape"); await s.click(h.link(s, "Centre d'aide")); } },
          { say: "Les guides y sont rangés par module, et vous ne voyez que ceux de vos pages.",
            do: (s) => s.scroll(260) },
          { say: "Les vidéos de formation y sont aussi, celle-ci comprise.",
            do: async (s) => { await s.scroll(-260); await h.soft(s, h.text(s, /Vidéos de formation/, false), 3000); } },
          { say: "La recherche trouve même une phrase dite dans une vidéo, et l'ouvre à ce moment précis.",
            do: async (s) => {
              await s.type(s.page.getByRole("searchbox", { name: "Rechercher dans l'aide" }), "mot de passe oublié", { delay: 80 });
              await s.wait(1.5);
              await h.soft(s, s.page.getByRole("link", { name: /Premiers pas/ }).first(), 6000);
            }, hold: 2.5 },
        ],
      }],
    },

    {
      name: "Se souvenir de cet appareil",
      shots: [{
        kind: "desktop", start: "/", still: true,
        lines: [
          "Sur votre propre ordinateur, l'application peut vous reconnaître. Jamais sur un ordinateur partagé.",
          { say: "Daniel saisit son adresse et son mot de passe, puis coche « Se souvenir de cet appareil ».",
            do: async (s) => {
              await s.type("Adresse Email", DANIEL, { delay: 40 });
              await s.type("Mot de Passe", s.password, { delay: 50 });
              await s.click(s.page.getByRole("checkbox"));
            } },
          { say: "Il appuie sur « Accéder au Bureau ».",
            do: (s) => s.click("Accéder au Bureau") },
          { say: "L'application propose de sécuriser cet ordinateur avec un code à quatre chiffres.",
            before: (s) => s.page.waitForURL(/device_setup/, { timeout: 30000 }).then(() => s.settle(700)) },
          { say: "Daniel choisit son code, et le confirme.",
            do: async (s) => { await s.type("Code", PIN, { delay: 220 }); await s.type("Confirmer le code", PIN, { delay: 220 }); } },
          { say: "Il appuie sur « Enregistrer le code ». Après cinq codes erronés, l'appareil est oublié.",
            do: (s) => s.click("Enregistrer le code") },
          { say: "Une clé d'accès, avec l'empreinte ou le visage, est aussi possible.",
            do: (s) => h.soft(s, h.button(s, "Ajouter une clé d'accès")) },
          { say: "« Continuer » ouvre sa page d'accueil.",
            do: (s) => s.click(s.page.getByRole("link", { name: /^Continuer/ })) },
        ],
      }],
    },

    {
      name: "Se déconnecter",
      shots: [{
        kind: "desktop", from: "c08s01", start: HOME,
        lines: [
          "Après deux heures sans activité, l'écran se verrouille : il suffit de se reconnecter.",
          { say: "En fin de journée, Daniel ouvre son compte et appuie sur « Se déconnecter ».",
            do: async (s) => { await s.click(h.shell.user(s)); await s.click(s.page.getByRole("menuitem", { name: "Se déconnecter" })); } },
          { say: "L'application demande confirmation, car les modifications non enregistrées seraient perdues. Il confirme.",
            do: async (s) => { await s.wait(1.2); await s.click(windowWith(s, "Se déconnecter ?").getByRole("button", { name: "Se déconnecter" })); } },
          { say: "L'ordinateur est mémorisé : la page l'accueille par son prénom, « Bon retour, Daniel ».",
            before: (s) => h.text(s, /Bon retour/, false).waitFor({ state: "visible", timeout: 30000 }).then(() => s.settle(600)) },
          { say: "« Utiliser mon mot de passe » reste possible, et « Ce n'est pas moi » fait oublier l'appareil.",
            do: (s) => h.tour(s, [h.button(s, "Utiliser mon mot de passe"), h.button(s, "Ce n'est pas moi")], 0.6) },
          { say: "Daniel tape son code. Au quatrième chiffre, il entre, sans appuyer sur rien.",
            // The page signs in on the fourth digit by itself: « Entrer » is there for a slow keyboard, not needed.
            do: (s) => s.type("Votre code", PIN, { delay: 260 }) },
          { say: "Le voici de retour au bureau, sans mot de passe.",
            before: (s) => h.shell.user(s).waitFor({ state: "visible", timeout: 30000 }).then(() => s.settle(900)) },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "Voilà pour les premiers pas. Vous savez vous connecter, retrouver un mot de passe et mémoriser votre ordinateur.",
          "Vous connaissez le menu, la recherche, la création rapide, les notifications, votre compte et l'aide.",
          "Dans la prochaine vidéo, Chacun son espace : ce que voient la direction, la finance, les opérations et le support.",
          "Toutes les vidéos et tous les guides sont dans le Centre d'aide. Merci de votre attention.",
        ],
      }],
    },
  ],
};
