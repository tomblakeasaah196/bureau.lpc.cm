-- =============================================================================
-- migrations/106_help_login_by_email_articles.sql
-- -----------------------------------------------------------------------------
-- Help Centre — Sprint 14: login by email, admin-set passwords, no email reset.
--
-- WHY THIS MIGRATION EXISTS
--   Migration 105 and the Sprint 14 code changed how a person gets into this
--   ERP. Three articles already in the database now describe a system that no
--   longer exists, and one of them gives an instruction that cannot be
--   followed:
--
--     · securite-creer-utilisateur (035) — "Remplissez le code employé —
--       l'identifiant que la personne saisira pour se connecter." The code is
--       no longer typeable and no longer a credential.
--     · mdm-employes-rh (071) — "Un mot de passe temporaire est envoyé par
--       email à la première connexion" and "Il reçoit un email avec un lien de
--       première connexion (valable 24 h)". No email has ever been sent: there
--       is no SMTP, and Mail::send() logs-and-returns-true when MAIL_FROM is
--       empty. This article has been describing a feature that never worked.
--     · faq-mdm-nouvel-employe-connexion (071) — an entire FAQ about that
--       non-existent email, telling people to check their spam folder.
--
--   Help that contradicts the software is worse than no help: it sends people
--   looking for a button that is not there, and it makes them distrust the
--   parts that ARE right. So the bodies are rewritten here, keyed on their
--   existing slugs.
--
-- WHAT THIS SHIPS
--   1. A new category `compte-et-connexion`, required_permission NULL — this
--      is the one subject in the ERP that every single user needs, including
--      a driver who can open nothing else.
--   2. Six articles + four FAQs: signing in, changing your password, what to
--      do when you forget it, what the matricule is now for, onboarding an
--      employee (from either surface), and the session lock.
--   3. Rewritten FR + EN bodies for the three stale articles above.
--   4. Anchors: `auth.login` and `auth.password` for the login and password
--      pages, plus the existing `admin.settings` / `admin.master_data` keys so
--      the "?" button on those pages surfaces the onboarding article.
--
-- WHY THE NEW CATEGORY IS UNGATED
--   README §5.9 rule 1: an article carries an EXISTING module permission, or
--   NULL for "any authenticated user". Never a new help.* permission — an
--   ungranted permission hides its article from everyone with no error, which
--   is the silent failure migration 031 documents at length. Everything in
--   `compte-et-connexion` is about the reader's own account, so NULL is right.
--
-- Text is restricted Markdown, rendered by lpc_help_markdown(), which escapes
-- first and whitelists after. Guillemets « » instead of straight double quotes
-- so the SQL string literals stay unambiguous.
--
-- Depends on: 032_help_center_schema.sql, 035, 071, 105_login_by_email.sql
-- Idempotent: every INSERT is ON DUPLICATE KEY UPDATE, keyed on slug.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- =============================================================================
-- 1. CATEGORY
-- =============================================================================

INSERT INTO help_categories
    (slug, module, required_permission, icon, title_fr, title_en, summary_fr, summary_en, sort_order)
VALUES
(
 'compte-et-connexion', 'auth', NULL,
 'M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z',
 'Mon compte et ma connexion',
 'My account and sign-in',
 "Se connecter avec son adresse email, changer son mot de passe, et que faire en cas d'oubli. Vaut pour tout le monde, quel que soit le rôle.",
 'Signing in with your email address, changing your password, and what to do if you forget it. Applies to everyone, whatever their role.',
 5
)
ON DUPLICATE KEY UPDATE
    module = VALUES(module), required_permission = VALUES(required_permission),
    icon = VALUES(icon), title_fr = VALUES(title_fr), title_en = VALUES(title_en),
    summary_fr = VALUES(summary_fr), summary_en = VALUES(summary_en),
    sort_order = VALUES(sort_order);


-- =============================================================================
-- 2. ARTICLES — metadata
-- -----------------------------------------------------------------------------
-- required_permission NULL throughout: these describe the reader's own account.
-- The two onboarding articles are the exception — they document an admin
-- surface, so they carry that surface's existing permission.
-- =============================================================================

INSERT INTO help_articles
    (category_id, slug, required_permission, kind, page_path, sort_order, is_published)
SELECT c.id, x.slug, x.perm, x.kind, x.page, x.ord, 1
FROM (
    SELECT 'compte-et-connexion' AS cat, 'connexion-se-connecter'        AS slug, NULL AS perm, 'article' AS kind, '/index.php' AS page,  10 AS ord UNION ALL
    SELECT 'compte-et-connexion',         'connexion-changer-mot-de-passe',       NULL,                    'article', '/password_manager.php',  20 UNION ALL
    SELECT 'compte-et-connexion',         'connexion-mot-de-passe-oublie',        NULL,                    'article', '',                       30 UNION ALL
    SELECT 'compte-et-connexion',         'connexion-choisir-mot-de-passe',       NULL,                    'article', '',                       40 UNION ALL
    SELECT 'compte-et-connexion',         'connexion-matricule',                  NULL,                    'article', '',                       50 UNION ALL
    SELECT 'compte-et-connexion',         'connexion-session-verrouillee',        NULL,                    'article', '',                       60 UNION ALL
    -- FAQs (kind = 'faq'; no page_path — rendered as an accordion at the foot)
    SELECT 'compte-et-connexion',         'faq-connexion-oubli-pas-de-lien',      NULL,                    'faq',     '',                      200 UNION ALL
    SELECT 'compte-et-connexion',         'faq-connexion-code-ne-marche-plus',    NULL,                    'faq',     '',                      210 UNION ALL
    SELECT 'compte-et-connexion',         'faq-connexion-compte-desactive',       NULL,                    'faq',     '',                      220 UNION ALL
    SELECT 'compte-et-connexion',         'faq-connexion-trop-de-tentatives',     NULL,                    'faq',     '',                      230
) x
JOIN help_categories c ON c.slug = x.cat
ON DUPLICATE KEY UPDATE
    category_id          = VALUES(category_id),
    required_permission  = VALUES(required_permission),
    kind                 = VALUES(kind),
    page_path            = VALUES(page_path),
    sort_order           = VALUES(sort_order),
    is_published         = VALUES(is_published);


-- =============================================================================
-- 3. ANCHORS
-- -----------------------------------------------------------------------------
-- `auth.login` / `auth.password` are new keys. The login page and the password
-- page are outside the app shell (no toolbar, so no « ? » button), but the
-- anchors are registered anyway: the ⌘K palette and the search index both read
-- them, and a future shell change gets the wiring for free.
-- =============================================================================

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'auth.login', a.id, a.sort_order
  FROM help_articles a
 WHERE a.slug IN ('connexion-se-connecter', 'connexion-mot-de-passe-oublie', 'connexion-matricule')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'auth.password', a.id, a.sort_order
  FROM help_articles a
 WHERE a.slug IN ('connexion-changer-mot-de-passe', 'connexion-choisir-mot-de-passe', 'connexion-mot-de-passe-oublie')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- The admin pages that onboard people should surface the account articles too.
INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'admin.settings', a.id, 300
  FROM help_articles a
 WHERE a.slug IN ('connexion-choisir-mot-de-passe', 'connexion-mot-de-passe-oublie')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

INSERT INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'admin.master_data', a.id, 300
  FROM help_articles a
 WHERE a.slug IN ('connexion-choisir-mot-de-passe', 'connexion-matricule')
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);


-- =============================================================================
-- 4. BODIES — French
-- -----------------------------------------------------------------------------
-- EVERY COLUMN OF THE FIRST SELECT IN A UNION MUST CARRY AN EXPLICIT ALIAS.
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr', x.title, x.summary, x.kw, x.body
FROM (

SELECT 'connexion-se-connecter' AS slug,
 'Se connecter à l''ERP' AS title,
 "Votre adresse email et votre mot de passe. Rien d'autre." AS summary,
 'connexion, login, email, adresse, mot de passe, acces, entrer, se connecter' AS kw,
 "Vous vous connectez à Bureau LPC avec votre **adresse email professionnelle** et votre **mot de passe**.

## Les deux champs

| Champ | Ce qu'on y met |
|---|---|
| Adresse Email | L'adresse que l'administrateur a enregistrée pour vous, par exemple `prenom.nom@lpc.cm`. |
| Mot de Passe | Celui que l'administrateur vous a communiqué, ou celui que vous avez choisi depuis. |

## La casse n'a aucune importance

`Marie.Ngo@…`, `marie.ngo@…` et `MARIE.NGO@…` ouvrent le même compte. Les espaces avant ou après l'adresse sont ignorés eux aussi. Le mot de passe, lui, **respecte** la casse : `Motdepasse1!` et `motdepasse1!` sont deux mots de passe différents.

## Ce qui a changé

Avant, on se connectait avec un **code employé** du type `LPC-001`. Ce n'est plus le cas. Le code existe toujours — il s'appelle maintenant le **matricule** et il sert à vous identifier sur une fiche de paie — mais il n'ouvre plus de session. Voir *Le matricule, à quoi il sert maintenant*.

## Après la connexion

L'ERP vous envoie directement sur le tableau de bord correspondant à votre rôle : Direction, Finance, Opérations ou Chauffeur. Vous ne choisissez pas, c'est votre rôle qui décide.

> [!note] Une seule adresse par personne, et une personne par compte. C'est ce qui permet d'attribuer une écriture, une livraison ou une signature à quelqu'un. Un compte partagé rend le journal d'audit inutilisable." AS body

UNION ALL SELECT 'connexion-changer-mot-de-passe',
 'Changer mon mot de passe',
 "Il faut connaître l'ancien pour en choisir un nouveau.",
 'changer, modifier, mot de passe, ancien, nouveau, securite, remplacer',
 "Vous pouvez changer votre mot de passe quand vous le voulez. Ce n'est pas obligatoire, mais c'est recommandé dès que quelqu'un d'autre a connu le vôtre — y compris l'administrateur qui vous l'a communiqué au départ.

## Où

**Vous devez d'abord être connecté.** Le changement se fait depuis votre propre session : c'est ce qui garantit que personne d'autre ne peut modifier votre mot de passe, même en connaissant l'ancien.

- Une fois connecté : le menu de votre compte, en haut à droite.
- Ou directement l'adresse `/password_manager.php`.

Le lien présent sur l'écran de connexion mène à la même page, mais celle-ci vous demandera de vous connecter avant d'afficher le formulaire.

Si vous avez oublié votre mot de passe, vous ne pouvez pas passer par ici — voir *Mot de passe oublié*.

## Comment

1. Votre **adresse email** — déjà remplie si vous êtes connecté.
2. Votre **mot de passe actuel**. C'est ce qui prouve que c'est bien vous.
3. Le **nouveau mot de passe**, deux fois.
4. **Mettre à jour**.

La liste de critères sous le champ passe au vert au fur et à mesure. Le bouton reste inactif tant que tout n'est pas vert et que les deux saisies ne sont pas identiques.

## Ce qui se passe ensuite

Votre nouveau mot de passe est actif **immédiatement**. Toutes vos **autres** sessions — un autre navigateur, le téléphone, un poste partagé — sont fermées d'office. La session depuis laquelle vous faites le changement reste ouverte.

C'est voulu : si vous changez votre mot de passe parce que vous pensez que quelqu'un le connaissait, laisser ses sessions ouvertes ne servirait à rien.

## Vous ne pouvez changer que le vôtre

Même en connaissant l'ancien mot de passe d'un collègue, vous ne pouvez pas le modifier depuis cet écran. Seul un administrateur peut définir le mot de passe de quelqu'un d'autre, depuis Paramètres → Utilisateurs.

> [!warning] Si vous avez oublié votre mot de passe actuel, cet écran ne peut rien pour vous — il faut l'ancien. Voir *Mot de passe oublié*."

UNION ALL SELECT 'connexion-mot-de-passe-oublie',
 'Mot de passe oublié',
 "Il n'y a pas de lien par email. C'est un administrateur qui vous en redonne un.",
 'oublie, perdu, reinitialiser, reset, bloque, ne peux plus me connecter, administrateur',
 "**Il n'y a pas de récupération automatique par email sur cette installation.** Aucun lien ne vous sera envoyé, quelle que soit la page sur laquelle vous cliquez. Ce n'est pas une panne : l'ERP n'est pas configuré pour envoyer des emails.

## Ce qu'il faut faire

Contactez un administrateur. En trente secondes il vous définit un nouveau mot de passe depuis **Paramètres → Utilisateurs**, et vous le communique.

## Ce qui se passe quand il le fait

- Le nouveau mot de passe fonctionne **tout de suite**.
- Toutes vos sessions ouvertes sont fermées. Si vous étiez connecté quelque part, il faudra vous reconnecter.
- L'opération est inscrite au journal d'audit, au nom de l'administrateur qui l'a faite.

## Ensuite, changez-le

L'administrateur connaît le mot de passe qu'il vient de taper. Dès votre première connexion, remplacez-le par un mot de passe que vous seul connaissez — voir *Changer mon mot de passe*. L'ERP ne vous y oblige pas ; c'est simplement la bonne pratique.

> [!note] Pourquoi pas d'email ? Parce que le serveur n'a pas de service d'envoi configuré. L'ancienne version proposait un bouton « Mot de passe oublié » qui affichait « un lien vous a été envoyé » sans que rien ne parte jamais. Ce bouton a été retiré : une promesse qui n'est pas tenue coûte plus de temps qu'une instruction claire."

UNION ALL SELECT 'connexion-choisir-mot-de-passe',
 'Choisir un mot de passe accepté',
 "Les règles appliquées, et pourquoi la longueur compte plus que les symboles.",
 'regles, criteres, longueur, majuscule, chiffre, special, refuse, trop faible, politique',
 "L'ERP refuse un mot de passe qui ne remplit pas **toutes** les conditions ci-dessous. Les mêmes règles s'appliquent partout : quand vous changez le vôtre, et quand un administrateur en définit un pour quelqu'un.

## Les règles

| Règle | Détail |
|---|---|
| Longueur | Au moins 10 caractères par défaut. Ce nombre est réglable dans Paramètres → Préférences → Sécurité. |
| Majuscule | Au moins une. |
| Minuscule | Au moins une. |
| Chiffre | Au moins un. |
| Caractère spécial | Au moins un : tout ce qui n'est ni une lettre ni un chiffre. Le tiret, l'espace, l'apostrophe et les accents comptent. |

La liste affichée sous le champ est générée à partir de ces mêmes règles : ce que vous voyez à l'écran est exactement ce que le serveur vérifie.

## Ce qui fait un bon mot de passe

La **longueur** protège bien plus que la complexité. `Bouteille-Douala-2026` est nettement plus solide que `Xk9!q` et infiniment plus facile à retenir. Trois ou quatre mots liés par des tirets remplissent toutes les conditions sans effort de mémoire.

## À éviter

- Le nom de la société, votre prénom, votre matricule.
- Un mot de passe déjà utilisé ailleurs, surtout sur une boîte mail.
- Une variation évidente de l'ancien (`Motdepasse1!` → `Motdepasse2!`).

## Vous ne pouvez pas remettre le même

Le nouveau mot de passe doit être différent de l'actuel. Un changement qui ne change rien laisserait l'ancien identifiant valide tout en donnant l'impression, dans le journal, d'une rotation effectuée.

> [!tip] Les accents sont acceptés, et comptent comme caractère spécial. `Café-du-Marché-2026` est un mot de passe valide."

UNION ALL SELECT 'connexion-matricule',
 'Le matricule, à quoi il sert maintenant',
 "Il ne sert plus à se connecter, et personne ne le saisit plus.",
 'matricule, code employe, emp, identifiant, paie, numero',
 "Chaque compte porte un **matricule** de la forme `EMP-014`. Il apparaît sur votre fiche de compte, dans la liste des utilisateurs et sur les états de paie.

## Il ne sert plus à se connecter

C'était l'identifiant de connexion. Ce n'est plus le cas : on se connecte avec son **adresse email**.

## Il est attribué par le système

Personne ne le saisit — ni vous, ni l'administrateur. À la création d'un compte, l'ERP prend le numéro le plus élevé déjà attribué et ajoute 1. Le champ apparaît en gris, non modifiable, sur la fiche d'un utilisateur existant, et n'apparaît pas du tout à la création puisqu'il n'existe pas encore.

## Pourquoi le garder

Parce qu'il est déjà imprimé sur des documents et qu'il identifie une personne dans les états de paie. Le supprimer casserait le lien entre un bulletin papier et la personne qu'il concerne.

## Les matricules qui ne ressemblent à rien

Certains comptes portent un matricule du type `LPC-1754900001`. C'est le résultat d'une anomalie corrigée depuis : le système calculait le bon numéro puis l'écrasait avec un horodatage. Ces matricules sont **conservés tels quels** — ils figurent déjà sur des documents émis, et les renuméroter ferait plus de dégâts que l'inélégance qu'on corrigerait. Les nouveaux comptes reçoivent un `EMP-###` normal.

> [!note] Deux comptes ne peuvent pas partager un matricule : la base l'interdit désormais."

UNION ALL SELECT 'connexion-session-verrouillee',
 'Écran « Session verrouillée »',
 "Ce que l'ERP demande quand vous revenez après une longue pause.",
 'session, verrouillee, expiree, inactivite, deverrouiller, timeout, reprendre',
 "Après une période d'inactivité — 30 minutes par défaut — l'écran se floute et l'ERP demande votre mot de passe pour reprendre.

## Ce qu'on vous demande

Votre **adresse email**, déjà remplie et non modifiable, et votre **mot de passe**. C'est le même mot de passe que pour la connexion normale.

## Pourquoi pas une simple reconnexion

Parce que vous ne perdez pas votre page. Le déverrouillage vous remet exactement là où vous étiez, avec la saisie en cours intacte. Une déconnexion vous ferait tout recommencer.

## Le bouton « Se déconnecter »

Si ce n'est pas vous devant l'écran — un poste partagé, un collègue qui reprend le poste — c'est ce bouton qu'il faut utiliser. Il ferme la session proprement, et le suivant se connecte avec son propre compte.

## Le délai est réglable

Paramètres → Préférences → Sécurité → *Déconnexion automatique*. Entre 5 minutes et 8 heures.

> [!tip] Un délai trop court pousse les gens à contourner la sécurité. Cherchez celui que vos équipes accepteront vraiment."

-- ----------------------------------------------------------------- FAQ (FR)
UNION ALL SELECT 'faq-connexion-oubli-pas-de-lien',
 "Je n'ai pas reçu l'email de réinitialisation",
 "Aucun email n'est envoyé par cette installation. Ce n'est pas un problème de spam.",
 'email, pas recu, spam, lien, reinitialisation, attente',
 "Il n'y a rien à attendre : **l'ERP n'envoie aucun email**. Aucun service d'envoi n'est configuré sur le serveur.

Inutile de vérifier vos spams, de réessayer, ou de patienter. Contactez un administrateur : il définit un nouveau mot de passe pour vous en quelques secondes depuis Paramètres → Utilisateurs.

Voir *Mot de passe oublié*."

UNION ALL SELECT 'faq-connexion-code-ne-marche-plus',
 'Mon code employé ne fonctionne plus',
 "C'est normal : on se connecte désormais avec son adresse email.",
 'code employe, lpc-001, ne marche plus, refuse, identifiant',
 "Saisir `LPC-001` dans le champ de connexion donne « Adresse email ou mot de passe incorrect ». C'est attendu.

Le champ attend maintenant votre **adresse email professionnelle**. Votre mot de passe, lui, n'a pas changé.

Si vous ne savez pas quelle adresse a été enregistrée pour vous, demandez à un administrateur : elle est affichée dans Paramètres → Utilisateurs, colonne *Connexion*."

UNION ALL SELECT 'faq-connexion-compte-desactive',
 '« Ce compte est désactivé »',
 "Votre accès a été suspendu ; l'historique de votre travail est intact.",
 'desactive, verrouille, bloque, suspendu, acces refuse',
 "Un administrateur a verrouillé le compte. C'est volontaire — un départ, une suspension, ou une mesure de sécurité.

Rien n'a été supprimé : vos écritures, vos livraisons et vos signatures restent attribuées à votre nom. Seule la connexion est bloquée.

Un administrateur peut rouvrir l'accès depuis Paramètres → Utilisateurs, en basculant le cadenas de la ligne concernée."

UNION ALL SELECT 'faq-connexion-trop-de-tentatives',
 '« Trop de tentatives »',
 "Une protection contre les essais en série. Elle se lève toute seule.",
 'trop de tentatives, bloque, attendre, limite, brute force, 15 minutes',
 "Après un certain nombre d'échecs depuis la même connexion internet — 10 par tranche de 15 minutes par défaut — l'ERP refuse temporairement toute nouvelle tentative, même avec le bon mot de passe.

Attendez le délai indiqué et réessayez. Le compteur se remet à zéro tout seul ; il n'y a rien à débloquer.

Les deux réglages se trouvent dans Paramètres → Préférences → Sécurité : *Tentatives de connexion max* et *Durée de verrouillage*.

> [!note] La limite s'applique à la **connexion internet**, pas au compte. Sur un bureau partageant une seule ligne, les tentatives de plusieurs personnes s'additionnent."

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 5. BODIES — English
-- =============================================================================

INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en', x.title, x.summary, x.kw, x.body
FROM (

-- Every column of the first SELECT in a UNION must carry an explicit alias,
-- including the last one — `x.body` does not exist without `AS body`.
SELECT 'connexion-se-connecter' AS slug,
 'Signing in' AS title,
 'Your email address and your password. Nothing else.' AS summary,
 'sign in, login, email, address, password, access, enter' AS kw,
 "You sign in to Bureau LPC with your **work email address** and your **password**.

## The two fields

| Field | What goes in it |
|---|---|
| Email Address | The address your administrator registered for you, e.g. `firstname.lastname@lapetitecour.cm`. |
| Password | The one your administrator gave you, or the one you have chosen since. |

## Case does not matter

`Marie.Ngo@…`, `marie.ngo@…` and `MARIE.NGO@…` all open the same account, and spaces around the address are ignored. The **password** is case-sensitive: `Motdepasse1!` and `motdepasse1!` are different passwords.

## What changed

You used to sign in with an **employee code** such as `LPC-001`. That is no longer the case. The code still exists — it is now called the **matricule** and it identifies you on a payslip — but it no longer opens a session. See *What the matricule is for now*.

## After signing in

The ERP takes you straight to the dashboard matching your role: Management, Finance, Operations or Driver. You do not choose; your role decides.

> [!note] One address per person, one person per account. That is what makes it possible to attribute an entry, a delivery or a signature to someone. A shared account makes the audit log useless." AS body

UNION ALL SELECT 'connexion-changer-mot-de-passe',
 'Changing my password',
 'You need the current one to choose a new one.',
 'change, modify, password, current, new, security, replace',
 "You can change your password whenever you like. It is not compulsory, but it is advisable as soon as anyone else has known yours — including the administrator who gave it to you in the first place.

## Where

**You must be signed in first.** The change is made from your own session: that is what guarantees nobody else can change your password, even if they know the old one.

- Once signed in: your account menu, top right.
- Or the address `/password_manager.php` directly.

The link on the sign-in screen leads to the same page, but it will ask you to sign in before showing the form.

If you have forgotten your password you cannot use this route — see *Forgotten password*.

## How

1. Your **email address** — already filled in if you are signed in.
2. Your **current password**. This is what proves it is you.
3. The **new password**, twice.
4. **Update**.

The checklist under the field turns green as you type. The button stays inactive until everything is green and both entries match.

## What happens next

Your new password works **immediately**. All your **other** sessions — another browser, your phone, a shared workstation — are closed. The session you made the change from stays open.

That is deliberate: if you are changing your password because you think someone else knew it, leaving their session open would defeat the purpose.

## You can only change your own

Even knowing a colleague's current password, you cannot change it from this screen. Only an administrator can set someone else's password, from Settings → Users.

> [!warning] If you have forgotten your current password, this screen cannot help — it requires the old one. See *Forgotten password*."

UNION ALL SELECT 'connexion-mot-de-passe-oublie',
 'Forgotten password',
 'There is no email link. An administrator gives you a new one.',
 'forgot, lost, reset, locked out, cannot sign in, administrator',
 "**There is no automatic email recovery on this installation.** No link will be sent to you, whatever page you click on. This is not a fault: the ERP is not configured to send email.

## What to do

Contact an administrator. It takes them thirty seconds to set a new password from **Settings → Users**, which they then give you.

## What happens when they do

- The new password works **straight away**.
- All your open sessions are closed. If you were signed in somewhere, you will need to sign in again.
- The action is written to the audit log, under the name of the administrator who did it.

## Then change it

The administrator knows the password they just typed. On your first sign-in, replace it with one only you know — see *Changing my password*. The ERP does not force you to; it is simply good practice.

> [!note] Why no email? Because the server has no mail service configured. The old version offered a « Forgot password » button that reported « a link has been sent » while nothing was ever sent. That button has been removed: an unkept promise costs more time than a clear instruction."

UNION ALL SELECT 'connexion-choisir-mot-de-passe',
 'Choosing an acceptable password',
 'The rules that are enforced, and why length beats symbols.',
 'rules, criteria, length, uppercase, digit, special, rejected, too weak, policy',
 "The ERP rejects a password that does not meet **every** condition below. The same rules apply everywhere: when you change your own, and when an administrator sets one for somebody else.

## The rules

| Rule | Detail |
|---|---|
| Length | At least 10 characters by default. Adjustable in Settings → Preferences → Security. |
| Uppercase | At least one. |
| Lowercase | At least one. |
| Digit | At least one. |
| Special character | At least one: anything that is neither a letter nor a digit. Hyphens, spaces, apostrophes and accented letters all count. |

The checklist under the field is generated from those same rules: what you see on screen is exactly what the server checks.

## What makes a good password

**Length** protects far more than complexity does. `Bouteille-Douala-2026` is much stronger than `Xk9!q` and infinitely easier to remember. Three or four words joined by hyphens satisfy every condition with no memory effort.

## Avoid

- The company name, your first name, your matricule.
- A password you already use elsewhere, especially on an email account.
- An obvious variation of the old one (`Motdepasse1!` → `Motdepasse2!`).

## You cannot reuse the current one

The new password must differ from the current one. A change that changes nothing would leave the old credential valid while making the audit log look like a rotation had taken place.

> [!tip] Accented characters are accepted and count as special characters. `Café-du-Marché-2026` is a valid password."

UNION ALL SELECT 'connexion-matricule',
 'What the matricule is for now',
 'It no longer signs you in, and nobody types it any more.',
 'matricule, employee code, emp, identifier, payroll, number',
 "Every account carries a **matricule** of the form `EMP-014`. It appears on your account panel, in the user list, and on payroll reports.

## It no longer signs you in

It used to be the sign-in identifier. It is not any more: you sign in with your **email address**.

## The system assigns it

Nobody types it — not you, not the administrator. When an account is created the ERP takes the highest number already issued and adds 1. The field appears greyed out and read-only on an existing user, and does not appear at all when creating one, since it does not exist yet.

## Why keep it

Because it is already printed on documents and identifies a person on payroll reports. Removing it would break the link between a paper payslip and the person it belongs to.

## The matricules that look wrong

Some accounts carry a matricule like `LPC-1754900001`. That is the result of a bug since fixed: the system calculated the correct number and then overwrote it with a timestamp. Those matricules are **kept as they are** — they already appear on issued documents, and renumbering them would do more damage than the untidiness it would fix. New accounts get a normal `EMP-###`.

> [!note] Two accounts can no longer share a matricule: the database now forbids it."

UNION ALL SELECT 'connexion-session-verrouillee',
 'The « Session locked » screen',
 'What the ERP asks for when you come back after a long break.',
 'session, locked, expired, inactivity, unlock, timeout, resume',
 "After a period of inactivity — 30 minutes by default — the screen blurs and the ERP asks for your password before you can carry on.

## What it asks for

Your **email address**, already filled in and not editable, and your **password**. It is the same password as for normal sign-in.

## Why not just sign in again

Because you do not lose your page. Unlocking puts you back exactly where you were, with whatever you were typing intact. Signing out would make you start again.

## The « Sign out » button

If it is not you in front of the screen — a shared workstation, a colleague taking over — use that button. It closes the session cleanly and the next person signs in with their own account.

## The delay is adjustable

Settings → Preferences → Security → *Session timeout*. Between 5 minutes and 8 hours.

> [!tip] Too short a timeout pushes people into working around security. Find the one your teams will actually accept."

-- ----------------------------------------------------------------- FAQ (EN)
UNION ALL SELECT 'faq-connexion-oubli-pas-de-lien',
 'I never got the reset email',
 'This installation sends no email at all. It is not a spam problem.',
 'email, not received, spam, link, reset, waiting',
 "There is nothing to wait for: **the ERP sends no email**. No mail service is configured on the server.

There is no point checking your spam folder, retrying, or waiting. Contact an administrator: they can set a new password for you in seconds from Settings → Users.

See *Forgotten password*."

UNION ALL SELECT 'faq-connexion-code-ne-marche-plus',
 'My employee code no longer works',
 'That is expected: you now sign in with your email address.',
 'employee code, lpc-001, no longer works, rejected, identifier',
 "Typing `LPC-001` into the sign-in field gives « Invalid email address or password ». That is expected.

The field now wants your **work email address**. Your password has not changed.

If you do not know which address is registered for you, ask an administrator: it is shown in Settings → Users, in the *Sign-in* column."

UNION ALL SELECT 'faq-connexion-compte-desactive',
 '« This account is disabled »',
 'Your access has been suspended; your work history is untouched.',
 'disabled, locked, blocked, suspended, access denied',
 "An administrator has locked the account. This is deliberate — a departure, a suspension, or a security measure.

Nothing has been deleted: your entries, deliveries and signatures remain attributed to your name. Only sign-in is blocked.

An administrator can restore access from Settings → Users by toggling the padlock on the relevant row."

UNION ALL SELECT 'faq-connexion-trop-de-tentatives',
 '« Too many attempts »',
 'A protection against repeated guessing. It clears itself.',
 'too many attempts, blocked, wait, limit, brute force, 15 minutes',
 "After a number of failures from the same internet connection — 10 per 15 minutes by default — the ERP temporarily refuses any further attempt, even with the correct password.

Wait for the stated delay and try again. The counter resets on its own; there is nothing to unblock.

Both settings live in Settings → Preferences → Security: *Max login attempts* and *Lockout duration*.

> [!note] The limit applies to the **internet connection**, not the account. In an office sharing one line, several people's attempts add up."

) x
JOIN help_articles a ON a.slug = x.slug
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);


-- =============================================================================
-- 6. REWRITE THE ARTICLES THAT NOW DESCRIBE A SYSTEM THAT NO LONGER EXISTS
-- -----------------------------------------------------------------------------
-- These slugs were seeded by 035 and 071. Their bodies are replaced, not their
-- metadata: same category, same permission, same anchors, so nothing that
-- links to them breaks.
-- =============================================================================

-- ---- securite-creer-utilisateur (035) · FR ---------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr',
 'Créer ou modifier un utilisateur',
 "Ouvrir un compte, attribuer un rôle, redonner un mot de passe.",
 'utilisateur, compte, creer, mot de passe, role, employe, acces, email, onboarding',
 "L'onglet **Utilisateurs** liste tous les comptes de l'ERP avec leur matricule, leur adresse de connexion, leur identité, leur rôle et leur état d'accès.

## Créer un compte

1. Cliquez sur **Nouvel Utilisateur**.
2. Saisissez l'**adresse email** — c'est l'identifiant avec lequel la personne se connectera. Elle doit être unique.
3. Renseignez **prénom** et **nom**.
4. Choisissez le **rôle système**.
5. Saisissez un **mot de passe initial**. Il doit respecter les règles de sécurité — voir *Choisir un mot de passe accepté*.
6. **Enregistrer**.

Le compte est utilisable immédiatement. Il n'y a **aucun email à attendre** : communiquez vous-même l'adresse et le mot de passe à la personne.

## Le matricule n'est pas saisi

Il est attribué par le système (`EMP-014`, `EMP-015`…). Il n'apparaît pas sur le formulaire de création — il n'existe pas encore — et s'affiche en gris, non modifiable, quand vous modifiez un compte existant. Voir *Le matricule, à quoi il sert maintenant*.

## Modifier un compte

L'icône crayon sur la ligne concernée. Le formulaire s'ouvre pré-rempli.

Pour le mot de passe : **laissez le champ vide** pour conserver l'actuel. N'y saisissez quelque chose que si vous voulez réellement le remplacer.

## Redonner un mot de passe à quelqu'un

C'est la procédure officielle quand une personne a oublié le sien — il n'existe pas de récupération par email sur cette installation.

Ouvrez sa fiche, saisissez un nouveau mot de passe, enregistrez. Le nouveau mot de passe est actif tout de suite, et **toutes les sessions ouvertes de cette personne sont fermées**. Communiquez-le-lui, et demandez-lui de le remplacer par un mot de passe qu'elle est seule à connaître.

## Le choix du rôle

La liste des rôles est lue en direct depuis le module RBAC. Elle contient donc tous vos rôles, y compris ceux que vous avez créés.

Vous ne pouvez pas modifier **votre propre** rôle depuis cet écran : retirer par mégarde sa propre permission d'administration laisserait potentiellement personne capable de la rendre. Demandez à un autre administrateur.

## Les trois indicateurs

En haut : le nombre total de comptes, les comptes actifs, et les comptes verrouillés.

> [!warning] Communiquez le mot de passe initial par un canal séparé de l'adresse email — un message vocal, ou de vive voix. Et rappelez à la personne qu'elle peut le changer elle-même : vous le connaissez, elle devrait être la seule à le connaître."
FROM help_articles a WHERE a.slug = 'securite-creer-utilisateur'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- securite-creer-utilisateur (035) · EN ---------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en',
 'Creating or editing a user',
 'Opening an account, assigning a role, issuing a new password.',
 'user, account, create, password, role, employee, access, email, onboarding',
 "The **Users** tab lists every account in the ERP with its matricule, sign-in address, identity, role and access state.

## Creating an account

1. Click **New User**.
2. Enter the **email address** — this is the identifier the person signs in with. It must be unique.
3. Fill in **first name** and **last name**.
4. Choose the **system role**.
5. Enter an **initial password**. It must satisfy the security rules — see *Choosing an acceptable password*.
6. **Save**.

The account works immediately. There is **no email to wait for**: give the address and password to the person yourself.

## The matricule is not typed in

The system assigns it (`EMP-014`, `EMP-015`…). It does not appear on the creation form — it does not exist yet — and shows greyed out and read-only when you edit an existing account. See *What the matricule is for now*.

## Editing an account

The pencil icon on the row. The form opens pre-filled.

For the password: **leave the field empty** to keep the current one. Only type something if you really mean to replace it.

## Issuing someone a new password

This is the official procedure when somebody has forgotten theirs — there is no email recovery on this installation.

Open their record, type a new password, save. It takes effect at once, and **all that person's open sessions are closed**. Pass it on to them, and ask them to replace it with one only they know.

## Choosing the role

The role list is read live from the RBAC module, so it includes every role you have created.

You cannot change **your own** role from this screen: accidentally removing your own administration permission could leave nobody able to grant it back. Ask another administrator.

## The three indicators

At the top: total accounts, active accounts, and locked accounts.

> [!warning] Send the initial password through a channel separate from the email address — a voice message, or in person. And remind the person they can change it themselves: you know it, and they should be the only one who does."
FROM help_articles a WHERE a.slug = 'securite-creer-utilisateur'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- mdm-employes-rh (071) · FR --------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr',
 "L'onglet Employés & RH",
 "Créer un employé, comprendre les colonnes, et ce qui se passe au premier accès.",
 'employes, rh, matricule, role, poste, salaire, avatar, email, mot de passe',
 "L'onglet **Employés & RH** est la fiche de chaque collaborateur qui a un compte dans l'ERP. Un employé sans compte (chauffeur payé à la course sans accès système) n'a pas besoin d'être ici — il vit dans la table des tiers de la paie.

## Les colonnes

| Colonne | Contenu |
| --- | --- |
| Profil | La photo. Un placeholder gris si aucune n'a été chargée. |
| Matricule | Identifiant employé, attribué par le système. Sert à la paie, pas à la connexion. |
| Connexion | L'adresse email avec laquelle la personne se connecte. |
| Nom & Prénom | Concaténé pour l'affichage. |
| Rôle Système | La permission racine (Admin, Ops, Sales, Finance, Driver…). Détermine tout ce que la personne peut faire dans l'ERP. |
| Poste | Le libellé métier libre (*Chauffeur senior*, *Commercial B2B*). Aucun effet sur les permissions. |
| Statut | *Actif* ou *Inactif*. Un employé inactif ne peut plus se connecter. |

## Nouvel Employé

Le bouton en haut à droite ouvre le formulaire :

- **Prénom** / **Nom** — obligatoires.
- **Email (identifiant de connexion)** — *c'est avec cette adresse que la personne se connecte*. Unique et valide.
- **Mot de passe** — obligatoire à la création. C'est vous qui le choisissez et qui le communiquez. En modification, laissez le champ vide pour conserver l'actuel.
- **Rôle Système** — obligatoire. Voir *Rôle Système vs Poste* pour l'écart entre les deux.
- **Poste (ex: Chauffeur)** — texte libre.
- **Téléphone** — utilisé par les notifications.
- **Salaire de Base** — en FCFA, alimente la paie mensuelle.
- **Photo de Profil** — image compressée localement avant envoi (voir `lpc-image-compress.js`).

Le **matricule n'est pas demandé** : le système l'attribue (`EMP-014`, `EMP-015`…).

## Enregistrer

Un nouvel employé apparaît immédiatement dans la liste, statut *Actif*, et peut se connecter tout de suite avec l'adresse et le mot de passe que vous avez saisis.

**Aucun email n'est envoyé.** Cette installation n'a pas de service d'envoi configuré ; c'est à vous de transmettre les identifiants.

## Changer le mot de passe d'un employé

Ouvrez sa fiche, saisissez un nouveau mot de passe, enregistrez. Il prend effet immédiatement et les sessions ouvertes de cette personne sont fermées.

> [!warning] Ne partagez pas de comptes. Chaque personne qui touche l'ERP doit avoir sa propre fiche employé, avec sa propre adresse email. C'est la seule façon d'attribuer une saisie, un paiement, une intervention à quelqu'un — la table `audit_log` cherche l'`user_id`, pas la personne physique devant l'écran."
FROM help_articles a WHERE a.slug = 'mdm-employes-rh'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- mdm-employes-rh (071) · EN --------------------------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en',
 'The Employees & HR tab',
 'Creating an employee, understanding the columns, and what happens at first sign-in.',
 'employees, hr, matricule, role, job title, salary, avatar, email, password',
 "The **Employees & HR** tab holds a record for every colleague who has an ERP account. An employee with no account (a driver paid per run, with no system access) does not need to be here — they live in the payroll counterparties table.

## The columns

| Column | Content |
| --- | --- |
| Profile | The photo. A grey placeholder if none was uploaded. |
| Matricule | Employee identifier, assigned by the system. Used for payroll, not for signing in. |
| Sign-in | The email address the person signs in with. |
| Name | Concatenated for display. |
| System Role | The root permission (Admin, Ops, Sales, Finance, Driver…). Determines everything the person can do. |
| Job title | Free-text business label (*Senior driver*, *B2B sales*). No effect on permissions. |
| Status | *Active* or *Inactive*. An inactive employee can no longer sign in. |

## New Employee

The button at the top right opens the form:

- **First name** / **Last name** — required.
- **Email (sign-in identifier)** — *this is the address the person signs in with*. Unique and valid.
- **Password** — required when creating. You choose it and you pass it on. When editing, leave it empty to keep the current one.
- **System Role** — required. See *System Role vs Job Title*.
- **Job title** — free text.
- **Phone** — used by notifications.
- **Base salary** — in FCFA, feeds monthly payroll.
- **Profile photo** — compressed locally before upload (see `lpc-image-compress.js`).

The **matricule is not asked for**: the system assigns it (`EMP-014`, `EMP-015`…).

## Saving

A new employee appears in the list immediately with status *Active*, and can sign in straight away with the address and password you entered.

**No email is sent.** This installation has no mail service configured; passing on the credentials is your job.

## Changing an employee's password

Open their record, type a new password, save. It takes effect immediately and that person's open sessions are closed.

> [!warning] Do not share accounts. Everyone who touches the ERP needs their own employee record with their own email address. It is the only way to attribute an entry, a payment or a job to someone — the `audit_log` table looks for the `user_id`, not the human in front of the screen."
FROM help_articles a WHERE a.slug = 'mdm-employes-rh'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- faq-mdm-nouvel-employe-connexion (071) · FR ---------------------------
-- The whole premise of this FAQ (an automatic first-sign-in email) never
-- existed. Retitled and rewritten rather than deleted: the slug is anchored
-- from the Master Data page, and someone searching "nouvel employé connexion"
-- should land on the answer that is true.
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'fr',
 'Un nouvel employé ne peut pas se connecter',
 "Aucun email n'est envoyé : vérifiez l'adresse, le mot de passe transmis et le rôle.",
 'nouvel employe, ne peut pas se connecter, email, mot de passe, role, premiere connexion',
 "Un employé que vous venez de créer n'arrive pas à entrer. Dans l'ordre :

## 1. N'attendez aucun email

L'ERP **n'envoie pas d'email**. Il n'y a pas de lien de première connexion, et il n'y en a jamais eu — l'ancienne version de cet article en décrivait un qui n'a jamais fonctionné, faute de service d'envoi sur le serveur.

La personne se connecte avec l'**adresse email** et le **mot de passe** que vous avez saisis dans le formulaire. C'est à vous de les lui communiquer.

## 2. Vérifiez l'adresse exacte

Ouvrez sa fiche dans **Master Data Hub → Employés & RH** et relisez la colonne *Connexion*. Une faute de frappe dans l'adresse est la cause la plus fréquente : la personne saisit ce qu'elle croit être son adresse, l'ERP cherche celle qui est enregistrée, et les deux diffèrent d'un caractère.

La casse et les espaces n'ont aucune importance ; l'orthographe, si.

## 3. Vérifiez le mot de passe

Retapez-en un depuis sa fiche et redonnez-le-lui de vive voix. Un mot de passe transmis par écrit se recopie mal — le `l` et le `1`, le `O` et le `0`.

## 4. Vérifiez le statut

Le compte doit être *Actif*. Un compte *Inactif* refuse la connexion avec le message « Ce compte est désactivé ».

## 5. Le rôle est bon mais la personne ne voit rien

Ce n'est plus un problème de connexion mais de permissions : elle est entrée, son rôle ne lui donne simplement accès à rien. Voir *Rôle Système vs Poste*.

> [!tip] « Trop de tentatives » après plusieurs essais ratés est normal : la limite est par connexion internet et se lève seule au bout du délai configuré."
FROM help_articles a WHERE a.slug = 'faq-mdm-nouvel-employe-connexion'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

-- ---- faq-mdm-nouvel-employe-connexion (071) · EN ---------------------------
INSERT INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md)
SELECT a.id, 'en',
 'A new employee cannot sign in',
 'No email is sent: check the address, the password you passed on, and the role.',
 'new employee, cannot sign in, email, password, role, first sign-in',
 "An employee you have just created cannot get in. In order:

## 1. Do not wait for an email

The ERP **sends no email**. There is no first-sign-in link, and there never was — the previous version of this article described one that never worked, because the server has no mail service.

The person signs in with the **email address** and the **password** you typed into the form. Passing them on is your job.

## 2. Check the exact address

Open their record in **Master Data Hub → Employees & HR** and re-read the *Sign-in* column. A typo in the address is the most common cause: the person types what they believe their address to be, the ERP looks for the one on file, and they differ by one character.

Case and spaces do not matter; spelling does.

## 3. Check the password

Set a new one from their record and give it to them verbally. A written password gets mis-copied — `l` and `1`, `O` and `0`.

## 4. Check the status

The account must be *Active*. An *Inactive* account refuses sign-in with « This account is disabled ».

## 5. The role is right but they see nothing

That is no longer a sign-in problem but a permissions one: they are in, and their role simply grants access to nothing. See *System Role vs Job Title*.

> [!tip] « Too many attempts » after several failed tries is expected: the limit is per internet connection and clears itself after the configured delay."
FROM help_articles a WHERE a.slug = 'faq-mdm-nouvel-employe-connexion'
ON DUPLICATE KEY UPDATE
    title = VALUES(title), summary = VALUES(summary),
    keywords = VALUES(keywords), body_md = VALUES(body_md);

COMMIT;

-- =============================================================================
-- VERIFICATION (run manually after import)
-- -----------------------------------------------------------------------------
--   -- The category exists and is ungated.
--   SELECT slug, module, required_permission, is_published
--     FROM help_categories WHERE slug = 'compte-et-connexion';
--   -- expect: one row, required_permission IS NULL, is_published = 1
--
--   -- 10 rows (6 articles + 4 FAQs).
--   SELECT slug, kind, page_path FROM help_articles
--    WHERE slug LIKE 'connexion-%' OR slug LIKE 'faq-connexion-%'
--    ORDER BY sort_order;
--
--   -- Every one of them is bilingual.
--   SELECT a.slug,
--          MAX(CASE WHEN b.lang='fr' THEN 1 END) AS fr,
--          MAX(CASE WHEN b.lang='en' THEN 1 END) AS en
--     FROM help_articles a
--     LEFT JOIN help_article_bodies b ON b.article_id = a.id
--    WHERE a.slug LIKE 'connexion-%' OR a.slug LIKE 'faq-connexion-%'
--    GROUP BY a.slug;
--   -- expect: fr = 1 AND en = 1 on every row.
--
--   -- THE IMPORTANT ONE: no surviving article still teaches the old flow.
--   SELECT a.slug, b.lang
--     FROM help_article_bodies b JOIN help_articles a ON a.id = b.article_id
--    WHERE b.body_md LIKE '%code employé — l%'
--       OR b.body_md LIKE '%mot de passe temporaire%'
--       OR b.body_md LIKE '%lien de première connexion%'
--       OR b.body_md LIKE '%employee code — the identifier%';
--   -- expect: NO ROWS.
--
--   -- Anchors resolve.
--   SELECT an.anchor_key, COUNT(*) FROM help_article_anchors an
--    WHERE an.anchor_key IN ('auth.login','auth.password')
--    GROUP BY an.anchor_key;
--   -- expect: auth.login = 3, auth.password = 3
-- =============================================================================
