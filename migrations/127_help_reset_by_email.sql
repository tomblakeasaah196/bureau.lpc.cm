-- =============================================================================
-- 127_help_reset_by_email.sql
-- -----------------------------------------------------------------------------
-- Help Centre — « Mot de passe oublié » describes the reset link that exists.
--
-- WHY
--   Article connexion-mot-de-passe-oublie (106) was written when the server
--   could not send mail: « Il n'y a pas de lien par email. C'est un
--   administrateur qui vous en redonne un. » Reset by email was built since
--   (includes/functions/account_recovery.php, lpc_reset_by_email_enabled()),
--   and bureau.lpc.cm sends it: its « Mot de passe oublié » page offers
--   « Recevoir le lien », a link valid one hour and usable once, and finds a
--   forgotten address from the matricule. The article now told people the
--   opposite of what the page beside it does (found while filming the training
--   videos, docs/TRAINING_VIDEOS.md §7).
--
-- WHAT
--   help_article_bodies   the FR and EN summary, keywords and body.
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body
--                         and the others go, so the assistant stops quoting the
--                         old text at once. The body's updated_at moves (ON
--                         UPDATE CURRENT_TIMESTAMP), so
--                         scripts/cron/reindex_help_chunks.php re-chunks and
--                         re-embeds this article on its next run.
--
-- The seed (106) is not edited: it has run in production. Idempotent.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.summary  = "Un lien par email, valable une heure, pour choisir un nouveau mot de passe.",
       b.keywords = 'oublie, perdu, reinitialiser, reset, lien, email, recevoir le lien, bloque, ne peux plus me connecter, matricule, adresse oubliee, administrateur',
       b.body_md  = "Sur la page de connexion, appuyez sur **Mot de passe ou adresse oubliés ?**.

## Recevoir un lien

1. Saisissez votre **Adresse email** professionnelle.
2. Appuyez sur **Recevoir le lien**.
3. Ouvrez l'email *Réinitialisation de votre mot de passe* et cliquez sur le lien. Il est valable **une heure** et ne sert **qu'une seule fois**.
4. Choisissez votre nouveau mot de passe, puis connectez-vous avec.

La page répond toujours la même chose, que l'adresse existe ou non. C'est voulu : cela protège les comptes de vos collègues. L'email indique aussi l'adresse d'où la demande est partie ; si vous n'avez rien demandé, ignorez-le, votre mot de passe reste inchangé.

## Adresse oubliée ?

Sous **Adresse de connexion oubliée ?**, indiquez votre **Matricule** (il figure sur votre bulletin de paie) et appuyez sur **Retrouver** : l'ERP affiche votre adresse, en partie masquée.

## Rien n'arrive ?

Regardez dans vos courriers indésirables. Si l'email n'arrive toujours pas, un administrateur peut vous définir un nouveau mot de passe depuis **Paramètres → Utilisateurs**. Remplacez-le ensuite par un mot de passe que vous seul connaissez : voir *Changer mon mot de passe*.

> [!note] Sur votre propre ordinateur, cochez **Se souvenir de cet appareil** à la connexion : un code à 4 chiffres suffit ensuite, et le mot de passe sert moins souvent."
 WHERE a.slug = 'connexion-mot-de-passe-oublie' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.summary  = "An email link, valid for one hour, to choose a new password.",
       b.keywords = 'forgot, forgotten, lost, reset, link, email, get the link, locked out, cannot sign in, staff number, forgot address, administrator',
       b.body_md  = "On the sign-in page, press **Forgot your password or address?**.

## Get a link

1. Type your work **Email address**.
2. Press **Get the link** (« Recevoir le lien »).
3. Open the email *Reset your password* and click the link. It is valid for **one hour** and works **only once**.
4. Choose your new password, then sign in with it.

The page always gives the same answer, whether or not the address exists. That is deliberate: it protects your colleagues' accounts. The email also shows where the request came from; if you did not ask for it, ignore it and your password stays as it is.

## Forgot your address?

Under **Forgot your sign-in address?**, type your **Staff number** (it is on your payslip) and press **Find**: the ERP shows your address, partly masked.

## Nothing arrives?

Look in your spam folder. If the email still does not arrive, an administrator can set a new password for you from **Settings → Users**. Then replace it with a password only you know: see *Change my password*.

> [!note] On your own computer, tick **Remember this device** when you sign in: a 4-digit code is then enough, and you need the password less often."
 WHERE a.slug = 'connexion-mot-de-passe-oublie' AND b.lang = 'en';

DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug = 'connexion-mot-de-passe-oublie' AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug = 'connexion-mot-de-passe-oublie' AND c.chunk_index = 0;

COMMIT;
