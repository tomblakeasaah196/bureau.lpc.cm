"use strict";
/**
 * Courrier: one video, about ten minutes. docs/TRAINING_VIDEOS.md says how a scene is written and filmed.
 *
 * Decided with the owner (18/11/2026): written example mail on a mail server that exists only on the
 * filming machine (scripts/video/mail-fake.php; the mail is courrier.seed.php), Praxis AI's answers written
 * for the video and served locally (nothing goes to Google), one video, following Daniel Massomba, the
 * accountant, who works invoicing@lpc.cm with Luc Nana; Timothée Massomba, the DG, adds that shared
 * mailbox and gives the access. The video says the messages are examples.
 *
 * Filmed in order on one copy: c02 connects Daniel's mailbox, c07 adds invoicing@lpc.cm, and the
 * chapters after each build on it. `prepare` restores the seeded ground (snapshot courrier-seeded); a
 * retake of a chapter restores the snapshot taken before it (runbook §4.1).
 */
const ground = require("../ground");
const h = require("../helpers");

const MAIL = "/modules/mail/index.php";
const BOXES = "/modules/mail/mailboxes.php";
const PW = { daniel: "Courrier-Daniel-2026", invoicing: "Courrier-Factu-2026" };

/** A conversation in the list, by its subject. */
const thread = (s, subject) => s.page.getByRole("link", { name: new RegExp(subject) }).first();
/** The reader's toolbar button (the reader also has per-message Répondre buttons). */
const tool = (s, name) => s.page.getByRole("toolbar", { name: "Actions sur la conversation" }).getByRole("button", { name, exact: true });
/** A visible field by its label (pages keep hidden copies of their dialogs). */
const field = (s, label) => s.page.getByLabel(label, { exact: true }).filter({ visible: true }).first();
/** The composer window. */
const composer = (s) => s.page.getByRole("dialog").filter({ has: s.page.getByRole("button", { name: "Envoyer", exact: true }) }).last();
/** A mailbox in the mail page's list (Ma boîte, INVOICING-LPC). */
const box = (s, name) => s.page.getByRole("navigation", { name: "Boîtes mail et dossiers" }).getByRole("link", { name: new RegExp(name) }).first();

/** Praxis AI's written answers (owner's choice): each matches the request the app really sends. */
const AI = [
  {
    match: ["Résume une", "Relevé de compte au 15 novembre"],
    delay: 2.2,
    json: {
      gist: "NOFIA SA attend son relevé de compte au 15 novembre. Nous avions promis de l'envoyer après la clôture de la quinzaine, et le client relance.",
      key_points: [
        "15 novembre : NOFIA SA demande son relevé de compte au 15 novembre, pour son rapprochement.",
        "16 novembre : l'équipe promet l'envoi dès la clôture de la quinzaine.",
        "17 novembre : NOFIA SA n'a toujours rien reçu.",
      ],
      asked_of_you: ["Envoyer aujourd'hui le relevé de compte au 15 novembre.", "Joindre le détail des bonbonnes consignées."],
    },
  },
  {
    match: ["Tu écris des e-mails pour Daniel", "bonbonnes consignées"],
    delay: 2.4,
    reply: "Bonjour,\n\nNous vous prions de nous excuser pour ce retard.\n\nVotre relevé de compte au 15 novembre part aujourd'hui, avec le détail des bonbonnes consignées.\n\nNous restons à votre disposition pour toute précision.\n\nCordialement,\nDaniel",
  },
  {
    match: ["Question : Quelles factures fournisseurs attendent un paiement"],
    delay: 2.4,
    reply: "Une facture fournisseur attend un paiement : celle de SOURCE DU PAYS, n° SDP-2611-0412, pour la commande PO-2611-00004, de 18 500 FCFA, payable à 30 jours [2].\nLuc demande aussi de rassembler les factures fournisseurs reçues ce mois pour la clôture de novembre, d'ici vendredi [1].",
  },
];

module.exports = {
  id: "courrier",
  file: "LPC-ERP-Courrier",
  meta: { title: "LPC ERP · Courrier", artist: "JBS Praxis" },
  library: {
    slug: "courrier",
    title: "Courrier",
    subtitle: "Votre boîte, les boîtes partagées et Praxis AI",
    series: "Modules",
    part: 1,
    parts: 1,
    audience: "all",
  },
  opening: {
    eyebrow: "Formation LPC ERP · Courrier",
    title: "Courrier",
    subtitle: "Votre boîte, les boîtes partagées et Praxis AI",
    left: "bureau.lpc.cm",
  },
  closing: {
    eyebrow: "Formation LPC ERP · Courrier",
    title: "Merci",
    subtitle: "Tout le courrier de l'équipe, au même endroit",
    left: "bureau.lpc.cm",
  },
  signsIn: ["daniel", "timothee"],

  /** The seeded ground: example mail on the fake server, nothing connected, Praxis AI's answers written. */
  async prepare({ log }) {
    ground.restore("courrier-seeded");
    ground.aiReplies(AI);
    log("ground: courrier-seeded restored, AI answers written");
  },

  chapters: [
    {
      name: "Bienvenue",
      shots: [{
        kind: "card", card: "opening",
        lines: [
          "Bienvenue dans le Courrier de LPC ERP.",
          "Votre boîte mail professionnelle, et les boîtes partagées de l'équipe, se lisent et s'écrivent ici, sans quitter le bureau.",
          "Le courrier reste sur le serveur de La Petite Cour. Le bureau en garde une copie des douze derniers mois, pour aller vite.",
          "Nous suivons Daniel Massomba, le comptable. Timothée Massomba, le directeur général, ouvre la boîte partagée de la facturation.",
          "Les messages que vous allez voir sont des exemples écrits pour cette formation.",
        ],
      }],
    },

    {
      name: "Connecter votre boîte",
      shots: [{
        kind: "desktop", as: "daniel", start: MAIL,
        lines: [
          { say: "Courrier est en haut du menu, pour tout le monde. La première fois, il propose de connecter votre boîte.",
            do: (s) => h.soft(s, h.shell.menu(s, /^Courrier/)) },
          { say: "Daniel appuie sur « Connecter ma boîte mail ».",
            do: (s) => s.click(h.link(s, "Connecter ma boîte mail")) },
          { say: "Son adresse est déjà remplie. Il saisit le mot de passe de sa boîte mail : celui de la messagerie, pas celui du bureau.",
            do: async (s) => { await s.wait(0.8); await s.type(field(s, "Mot de passe de la boîte mail"), PW.daniel, { delay: 70 }); } },
          { say: "Le nom affiché est celui que verront ses destinataires. Il appuie sur « Connecter ».",
            do: async (s) => { await h.soft(s, field(s, "Nom affiché aux destinataires")); await s.click(s.page.getByRole("button", { name: "Connecter", exact: true })); } },
          { say: "Sa boîte est à jour, avec quatre messages non lus. Le mot de passe est chiffré, et n'est plus jamais réaffiché.",
            before: (s) => h.text(s, /non lus/, false).waitFor({ state: "visible", timeout: 30000 }).then(() => s.settle(500)),
            do: (s) => h.soft(s, h.text(s, /non lus/, false)) },
          { say: "Sa boîte personnelle est privée. Même les administrateurs ne la voient pas.",
            do: (s) => h.soft(s, h.text(s, /Vous seul la voyez/, false)) },
        ],
      }],
    },

    {
      name: "Lire",
      shots: [{
        kind: "desktop", as: "daniel", start: MAIL,
        lines: [
          { say: "Voici sa boîte de réception. Les dossiers à gauche, les conversations au milieu, la lecture à droite.",
            do: (s) => h.soft(s, s.page.getByRole("navigation", { name: "Boîtes mail et dossiers" })) },
          { say: "Un point marque un message non lu, un trombone une pièce jointe. « Non lus » ne garde que ceux-là.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: "Non lus", exact: true })); await s.wait(1.2); await s.click(s.page.getByRole("button", { name: "Tous", exact: true })); } },
          { say: "Daniel ouvre le message de Bertin, aux opérations : la commande PO-2611-00004 est arrivée.",
            do: (s) => s.click(thread(s, "Réception de la commande PO-2611-00004")) },
          { say: "Le bon de réception signé est joint. « Ouvrir » l'affiche, « Télécharger » l'enregistre.",
            do: (s) => h.soft(s, s.page.getByRole("link", { name: /^Ouvrir Bon-de-reception/ })) },
          { say: "En haut, les actions : archiver, indésirable, corbeille, déplacer, marquer non lu, ou marquer pour suivi.",
            do: (s) => h.tour(s, ["Archiver", "Placer dans Indésirables", "Placer dans la Corbeille", "Marquer comme non lue", "Marquer pour suivi"].map((n) => tool(s, n)), 0.3) },
          { say: "Il le marque pour suivi : il reviendra dessus avec la facture du fournisseur.",
            do: (s) => s.click(tool(s, "Marquer pour suivi")) },
          { say: "Les images envoyées par un expéditeur extérieur sont retenues, par prudence. Un clic les affiche, une fois ou toujours.",
            do: async (s) => { await s.click(thread(s, "Nos tarifs de décembre")); await s.wait(1); await h.soft(s, s.page.getByRole("button", { name: /images/i }).first()); } },
        ],
      }],
    },

    {
      name: "Chercher",
      shots: [{
        kind: "desktop", as: "daniel", start: MAIL,
        lines: [
          { say: "La recherche lit les expéditeurs, les objets et le texte. Daniel cherche la commande PO-2611-00004.",
            do: (s) => s.type(s.page.getByRole("searchbox").first(), "PO-2611-00004", { delay: 90 }) },
          { say: "Le message de Bertin sort aussitôt.",
            do: async (s) => { await s.key("Enter"); await s.wait(1.2); await h.soft(s, thread(s, "Réception de la commande")); } },
          { say: "Le bureau garde douze mois de courrier. Pour plus ancien, « Charger le courrier plus ancien » le lit sur le serveur.",
            do: async (s) => { await s.page.getByRole("searchbox").first().fill(""); await s.key("Enter"); await s.wait(0.8); await h.soft(s, h.button(s, "Charger le courrier plus ancien")); } },
        ],
      }],
    },

    {
      name: "Répondre et écrire",
      shots: [{
        kind: "desktop", as: "daniel", start: MAIL,
        lines: [
          { say: "Luc, le contrôleur financier, demande les pièces de la clôture de novembre. Daniel lui répond.",
            do: async (s) => { await s.click(thread(s, "Clôture de novembre")); await s.wait(0.8); await s.click(tool(s, "Répondre")); } },
          { say: "Le destinataire et l'objet sont déjà remplis. Daniel écrit sa réponse.",
            do: async (s) => {
              const editor = composer(s).locator("[contenteditable=true]").first();
              await s.click(editor);
              await s.page.keyboard.type("Bonjour Luc,\n\nC'est noté : les relevés, les factures et les bons de réception seront prêts jeudi.\n\nDaniel", { delay: 28 });
            } },
          { say: "Sa signature est ajoutée d'elle-même : la courte sur une réponse, la complète sur un nouveau message.",
            do: (s) => h.soft(s, composer(s).getByText("Comptable").first()) },
          { say: "« Joindre » ajoute un fichier. Il appuie sur « Envoyer ».",
            do: async (s) => { await h.soft(s, composer(s).getByRole("button", { name: "Joindre" })); await s.click(composer(s).getByRole("button", { name: "Envoyer", exact: true })); } },
          { say: "Pendant dix secondes, « Annuler » le rattrape. Passé ce délai, il part.",
            do: async (s) => { await h.soft(s, s.page.getByRole("button", { name: "Annuler" }).last()); await s.wait(10.5); await ground.mailAsync("send"); await ground.mailAsync("sync"); } },
          { say: "Il le retrouve dans « Envoyés ».",
            do: (s) => s.click(s.page.getByRole("navigation", { name: "Boîtes mail et dossiers" }).getByRole("link", { name: /^Envoyés/ })) },
          { say: "Pour Timothée, Daniel prépare sa réponse ce soir, et la programme pour demain matin.",
            do: async (s) => {
              await s.click(s.page.getByRole("navigation", { name: "Boîtes mail et dossiers" }).getByRole("link", { name: /^Boîte de réception/ }));
              await s.click(thread(s, "Point budget lundi"));
              await s.wait(0.6);
              await s.click(tool(s, "Répondre"));
              await s.click(composer(s).locator("[contenteditable=true]").first());
              await s.page.keyboard.type("Bonjour Timothée,\n\nJe serai là lundi à 9 h avec la situation de trésorerie.\n\nDaniel", { delay: 28 });
            } },
          { say: "La flèche à côté d'« Envoyer » ouvre « Programmer l'envoi ». Il choisit demain à huit heures.",
            do: async (s) => { await s.click(composer(s).getByRole("button", { name: "Programmer l’envoi" })); await s.wait(0.6); await s.click(s.page.getByRole("button", { name: "Demain à 08:00" })); } },
        ],
      }],
    },

    {
      name: "Votre signature",
      shots: [{
        kind: "desktop", as: "daniel", start: "/modules/mail/signature.php",
        lines: [
          { say: "Ma signature montre ce que verront ses destinataires, en version complète, courte ou texte seul.",
            do: (s) => h.tour(s, ["Complète", "Courte", "Texte seul"].map((n) => h.button(s, n)), 0.4) },
          { say: "C'est la présentation de La Petite Cour : le logo, le nom, l'adresse et les numéros légaux.",
            do: (s) => s.scroll(260) },
          { say: "Le nom et le poste viennent de sa fiche du personnel. Une erreur se corrige là, et la signature suit.",
            do: (s) => h.soft(s, h.heading(s, "Repris de votre fiche du personnel")) },
          { say: "Lui ne règle que ses numéros : le téléphone de bureau, et son mobile.",
            do: async (s) => { await s.scroll(300); await h.soft(s, h.heading(s, "Mes numéros")); } },
        ],
      }],
    },

    {
      name: "Une boîte partagée",
      shots: [{
        kind: "desktop", as: "timothee", start: BOXES,
        lines: [
          "Les boîtes partagées sont les adresses d'équipe. Timothée, administrateur, ajoute celle de la facturation.",
          { say: "Il appuie sur « Ajouter une boîte partagée », et saisit l'adresse, le nom affiché, puis le mot de passe de la boîte.",
            do: async (s) => {
              await s.click("Ajouter une boîte partagée");
              await s.wait(0.6);
              await s.type(field(s, "Adresse"), "invoicing@lpc.cm", { delay: 45 });
              await s.type(field(s, "Nom affiché aux destinataires"), "INVOICING-LPC", { delay: 45 });
              await s.type(field(s, "Mot de passe de la boîte mail"), PW.invoicing, { delay: 45 });
            } },
          { say: "Il appuie sur « Ajouter la boîte ». Il en devient le gestionnaire.",
            do: (s) => s.click("Ajouter la boîte") },
          { say: "« Gérer les personnes » donne l'accès, à trois niveaux.",
            before: (s) => h.text(s, /INVOICING-LPC/, false).waitFor({ state: "visible", timeout: 30000 }).then(() => s.settle(500)),
            do: (s) => s.click(s.page.getByRole("button", { name: "Gérer les personnes" }).first()) },
          { say: "Lecture voit tout le courrier. Lecture et envoi répond aussi, écrit et classe. Gestionnaire gère aussi les personnes et le mot de passe.",
            do: (s) => h.soft(s, s.page.locator("select:visible").last()) },
          { say: "Daniel reçoit « Lecture et envoi ».",
            do: async (s) => {
              const who = s.page.locator("select:visible").filter({ has: s.page.locator("option", { hasText: "Choisir une personne" }) }).first();
              await s.hover(who);
              await who.selectOption({ label: "Daniel MASSOMBA · Comptable — daniel.massomba@lpc.cm" });
              await s.page.locator("select:visible").last().selectOption({ label: "Lecture et envoi" });
              await s.click("Donner l’accès");
            } },
          { say: "Et Luc, « Gestionnaire », pour la tenir avec lui.",
            do: async (s) => {
              const who = s.page.locator("select:visible").filter({ has: s.page.locator("option", { hasText: "Choisir une personne" }) }).first();
              await who.selectOption({ label: "Luc NANA · Contrôleur Financier — luc.nana@gmail.com" });
              await s.page.locator("select:visible").last().selectOption({ label: "Gestionnaire" });
              await s.click("Donner l’accès");
            } },
          { say: "Chacun la voit maintenant dans son Courrier. Et chaque nouveau message fait sonner leur cloche.",
            do: (s) => s.click(s.page.getByRole("button", { name: "Fermer" }).filter({ visible: true }).last()) },
        ],
      }],
    },

    {
      name: "Travailler à plusieurs",
      shots: [{
        kind: "desktop", as: "daniel", start: MAIL,
        lines: [
          { say: "Dans le Courrier de Daniel, la boîte INVOICING-LPC s'ajoute sous la sienne.",
            do: (s) => s.click(box(s, "INVOICING-LPC")) },
          { say: "Des filtres en plus : « Assignées à moi », et les conversations « Ouvertes » ou « Traitées ».",
            do: (s) => h.tour(s, [h.button(s, "Assignées à moi"), s.page.getByRole("button", { name: /^Ouvertes/ }).first()], 0.5) },
          { say: "NOFIA SA relance pour son relevé de compte. Daniel ouvre la conversation : trois messages.",
            do: (s) => s.click(thread(s, "Relevé de compte au 15 novembre")) },
          { say: "Le bureau montre qui a ouvert chaque message, pour que personne ne réponde deux fois.",
            do: (s) => h.soft(s, h.text(s, /Ouvert par/, false)) },
          { say: "Daniel la prend en charge : « M'assigner ».",
            do: (s) => s.click(h.button(s, "M’assigner")) },
          { say: "Il laisse une note interne. Les clients ne la voient jamais.",
            do: async (s) => {
              await s.click(h.button(s, "Notes"));
              await s.wait(0.6);
              await s.type(s.page.getByPlaceholder(/client rappelé/), "Relevé en préparation, envoi aujourd'hui avec le détail des bonbonnes consignées.", { delay: 30 });
              await s.click(h.button(s, "Ajouter la note"));
            } },
          { say: "« Activité » retrace qui a fait quoi sur la conversation.",
            do: (s) => h.soft(s, h.button(s, "Activité")) },
        ],
      }],
    },

    {
      name: "Praxis AI",
      shots: [{
        kind: "desktop", as: "daniel", start: MAIL,
        setup: async (s) => { await s.click(box(s, "INVOICING-LPC")); await s.click(thread(s, "Relevé de compte au 15 novembre")); await s.wait(0.8); },
        lines: [
          "Praxis AI aide, sur demande seulement. Il n'envoie jamais rien, et ne lit jamais les pièces jointes.",
          { say: "« Résumer » donne l'essentiel de la conversation, et ce que le client attend.",
            do: async (s) => { await s.click(s.page.getByRole("button", { name: "Résumer" }).first()); await h.text(s, /NOFIA SA attend/, false).waitFor({ timeout: 15000 }).catch(() => null); }, hold: 1.5 },
          { say: "Daniel répond. « Écrire avec l'IA » prépare un brouillon : il dit en quelques mots ce que le message doit dire.",
            do: async (s) => {
              await s.click(tool(s, "Répondre"));
              await s.wait(0.8);
              await s.click(composer(s).getByRole("button", { name: "Écrire avec l’IA" }));
              await s.wait(0.6);
              await s.type(s.page.getByLabel("Que doit dire le message ?"), "S'excuser du retard : le relevé part aujourd'hui avec le détail des bonbonnes consignées.", { delay: 22 });
            } },
          { say: "« Écrire le brouillon ».",
            do: async (s) => { await s.click("Écrire le brouillon"); await s.wait(3.5); } },
          { say: "Le brouillon arrive dans le message. Il part au nom de la boîte, INVOICING-LPC, avec la signature de Daniel. Daniel le relit.",
            do: (s) => h.soft(s, composer(s).getByText(/Nous vous prions de nous excuser/).first()), hold: 1 },
          { say: "Il envoie, puis marque la conversation « Traitée ». Si NOFIA SA répond, elle se rouvrira d'elle-même.",
            do: async (s) => {
              await s.click(composer(s).getByRole("button", { name: "Envoyer", exact: true }));
              await s.wait(1.5);
              await s.click(h.button(s, "Marquer traitée"));
            } },
          { say: "Enfin, « Demander à votre courrier » répond à une question, à partir du courrier qu'il peut lire.",
            do: (s) => s.click(h.button(s, "Demander à votre courrier")) },
          { say: "Daniel demande quelles factures fournisseurs attendent un paiement.",
            do: async (s) => { await s.wait(0.6); await s.click(h.button(s, "Quelles factures fournisseurs attendent un paiement ?")); await h.text(s, /SDP-2611-0412/, false).waitFor({ timeout: 15000 }).catch(() => null); } },
          { say: "La réponse cite ses sources : un clic sur un numéro ouvre le message.",
            do: (s) => h.soft(s, s.page.getByRole("link", { name: /^\[?2\]?$|message 2/i }).first()), hold: 1.5 },
        ],
      }],
    },

    {
      name: "Pour conclure",
      shots: [{
        kind: "card", card: "closing",
        lines: [
          "Voilà le Courrier : votre boîte, connectée une fois, puis lue, cherchée et écrite sans quitter le bureau.",
          "Les boîtes partagées se tiennent à plusieurs : on s'assigne, on note, on marque traité.",
          "Et Praxis AI résume, rédige et répond à vos questions, toujours sur demande.",
          "Retrouvez cette vidéo et les guides dans le Centre d'aide. Merci de votre attention.",
        ],
      }],
    },
  ],
};
