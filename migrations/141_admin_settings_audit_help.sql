-- =============================================================================
-- 141 · Administration: what the admin screens say and record
-- -----------------------------------------------------------------------------
-- Found while making the admin training videos (docs/TRAINING_VIDEOS.md §7,
-- 18/11/2026):
--
-- 1. Paramètres → Préférences showed settings no screen reads: changing them
--    did nothing (the fallback price the owner had removed, the date and number
--    formats, the purchase-order and credit-note prefixes, alerts, password
--    expiry, 2FA, audit retention…). They are hidden, their values kept, so a
--    later change can wire any of them and show it again. The help lines that
--    named the code's files are in plain words.
-- 2. Two permission labels carried a sprint number and a dash.
-- 3. The users audit trigger logged every UPDATE, even one that changed
--    nothing visible (a sign-in, a status set to what it was): the Journal
--    d'audit filled with « Système · users » rows. It now logs a change of
--    role, access, email or password (never the password itself).
-- 4. Ten help articles described the old screens (Kill Session, « Nouvel
--    Utilisateur », technical role names, a lock that left sessions open) and
--    the Journal d'Erreurs in developer terms. Rewritten in French, with their
--    assistant copy.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 1 · Preferences
-- -----------------------------------------------------------------------------
UPDATE app_preferences SET is_active = 0
 WHERE pref_key IN (
    'price_fallback_amount', 'price_fallback_mode',
    'default_payment_terms_days', 'default_quote_validity_days', 'default_currency',
    'default_late_penalty_rate', 'quote_terms_fr',
    'locale_date_format', 'locale_decimal_sep', 'locale_thousand_sep',
    'doc_prefix_purchase', 'doc_prefix_credit',
    'notify_low_stock_threshold', 'notify_invoice_due_days', 'notify_email_enabled',
    'ops_allow_negative_stock',
    'sec_password_expiry_days', 'sec_require_2fa_admin', 'sec_audit_retention_days'
 );

UPDATE app_preferences SET help_fr = 'Début du numéro des factures (FAC-2611-00001).'                          WHERE pref_key = 'doc_prefix_invoice';
UPDATE app_preferences SET help_fr = 'Début du numéro des devis et propositions commerciales.'                  WHERE pref_key = 'doc_prefix_quote';
UPDATE app_preferences SET help_fr = 'Début du numéro des bons de livraison.'                                   WHERE pref_key = 'doc_prefix_delivery';
UPDATE app_preferences SET help_fr = 'Début du numéro des commandes clients.'                                   WHERE pref_key = 'doc_prefix_order';
UPDATE app_preferences SET help_fr = "Changer ce format en cours d'exercice rompt la suite des numéros que la DGI exige." WHERE pref_key = 'doc_number_format';
UPDATE app_preferences SET help_fr = "Langue de l'application pour une personne qui n'a pas choisi la sienne."  WHERE pref_key = 'locale_default';
UPDATE app_preferences SET help_fr = "Après ce temps sans activité, la session se ferme et il faut se reconnecter." WHERE pref_key = 'sec_session_timeout_min';
UPDATE app_preferences SET help_fr = "Essais par adresse de connexion. Au delà, ce compte seul attend la durée de verrouillage ; les autres continuent." WHERE pref_key = 'sec_max_login_attempts';
UPDATE app_preferences SET help_fr = "Garde-fou par réseau, plus large que la limite par compte : tout un bureau partage la même adresse internet." WHERE pref_key = 'sec_max_login_attempts_ip';
UPDATE app_preferences SET help_fr = "Score minimum pour qu'une écriture en brouillard parte avec « Poster la sélection ». En dessous, elle se valide une par une." WHERE pref_key = 'accounting_confidence_threshold';
UPDATE app_preferences SET help_fr = 'Taux standard au Cameroun : 19,25 % (18 % et 1,25 % de CAC).'              WHERE pref_key = 'tax_tva_rate';
UPDATE app_preferences SET help_fr = 'Groupe A, risque faible : 1,75 % de la masse plafonnée.'  , label_fr = 'CNPS accident du travail, groupe A' WHERE pref_key = 'tax_at_rate_a';
UPDATE app_preferences SET help_fr = 'Groupe B, risque moyen : 2,5 % de la masse plafonnée.'    , label_fr = 'CNPS accident du travail, groupe B' WHERE pref_key = 'tax_at_rate_b';
UPDATE app_preferences SET help_fr = 'Groupe C, risque élevé : 5 % de la masse plafonnée.'      , label_fr = 'CNPS accident du travail, groupe C' WHERE pref_key = 'tax_at_rate_c';
UPDATE app_preferences SET label_fr = 'Taxe communale, forfait mensuel'                                         WHERE pref_key = 'tax_commune_monthly';
UPDATE app_preferences SET label_fr = 'Patente annuelle (montant imposé)', help_fr = "Laisser 0 : la patente est calculée sur le chiffre d'affaires de l'année précédente." WHERE pref_key = 'tax_patente_annual';
UPDATE app_preferences SET label_fr = 'Valeur comptable des cessions (81)', help_fr = "Compte débité de la valeur restante d'une immobilisation cédée." WHERE pref_key = 'accounting_moins_value_account_id';
UPDATE app_preferences SET label_fr = 'Produits des cessions (82)',         help_fr = "Compte crédité du prix de cession d'une immobilisation."         WHERE pref_key = 'accounting_plus_value_account_id';
UPDATE app_preferences SET label_fr = 'TVA facturée sur cessions (443)',    help_fr = "Compte de TVA collectée quand une immobilisation est cédée TTC." WHERE pref_key = 'accounting_vat_output_account_id';

-- -----------------------------------------------------------------------------
-- 2 · Permission labels
-- -----------------------------------------------------------------------------
UPDATE permissions SET description = "Voir le journal d'erreurs"                           WHERE name = 'admin.errors.view';
UPDATE permissions SET description = 'Annuler une commande après son BL (la trace reste)' WHERE name = 'sales.orders.cancel';

-- -----------------------------------------------------------------------------
-- 3 · The users audit trigger: real changes only
-- -----------------------------------------------------------------------------
DELIMITER //
DROP TRIGGER IF EXISTS au_users_audit //
CREATE TRIGGER au_users_audit AFTER UPDATE ON users FOR EACH ROW
BEGIN
    IF NOT (OLD.role_id <=> NEW.role_id) OR NOT (OLD.status <=> NEW.status)
       OR NOT (OLD.email <=> NEW.email) OR NOT (OLD.password_hash <=> NEW.password_hash) THEN
        INSERT INTO audit_logs (user_id, action, table_name, record_id, old_json, new_json, new_value)
        VALUES (@lpc_current_user_id, 'UPDATE', 'users', NEW.id,
                JSON_OBJECT('role_id', OLD.role_id, 'status', OLD.status, 'email', OLD.email),
                JSON_OBJECT('role_id', NEW.role_id, 'status', NEW.status, 'email', NEW.email),
                IF(OLD.password_hash <=> NEW.password_hash,
                   CONCAT('User updated: ', NEW.employee_code),
                   CONCAT('Mot de passe modifié : ', NEW.employee_code)));
    END IF;
END//
DELIMITER ;

-- -----------------------------------------------------------------------------
-- 4 · Help articles (French)
-- -----------------------------------------------------------------------------
START TRANSACTION;

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Découvrir Paramètres Système & Audit',
       b.summary = 'Les six onglets, ce que chacun fait, et qui les voit.',
       b.body_md = "**Paramètres Système & Audit** réunit les réglages et la sécurité de l'application. Le menu y mène par trois entrées : **Identité de l'Entreprise**, **Paramètres Système & Audit** et **Rôles & Permissions**.

## Les six onglets

| Onglet | Ce qu'on y fait |
|---|---|
| Utilisateurs | Ouvrir un compte, changer un rôle ou un mot de passe, verrouiller un accès |
| Rôles | Dire ce que chaque rôle peut faire, et sa page d'accueil |
| Entreprise | L'identité légale imprimée sur les factures, devis et bons de livraison |
| Préférences | Numérotation, taux, langue, politique de sécurité |
| Sessions | Qui est connecté, les connexions refusées, fermer une session |
| Journal d'audit | Qui a changé quoi, quand, et la valeur avant et après |

## Vous ne voyez pas tous les onglets ?

C'est voulu : un onglet que votre rôle ne permet pas d'ouvrir n'est pas affiché.

> [!note] Presque tout ce qui se change ici est écrit dans le journal d'audit. Relisez avant d'enregistrer."
 WHERE a.slug = 'securite-vue-ensemble' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Créer ou modifier un utilisateur',
       b.summary = "Ouvrir un compte à un employé, changer son rôle, lui redonner un mot de passe.",
       b.body_md = "L'onglet **Utilisateurs** liste les comptes : matricule, adresse de connexion, nom, rôle et accès. Une personne a d'abord une fiche dans **Données de Base, Employés & RH** ; le compte lui donne l'accès.

## Deux façons d'ouvrir un compte

- **Provisionner un compte** : pour un employé qui a déjà sa fiche. Choisissez-le, saisissez son adresse de connexion, son rôle et un mot de passe de départ, puis **Enregistrer**. Communiquez le mot de passe de vive voix.
- **Inviter par email** : crée la fiche et le compte, puis envoie à la personne un lien pour choisir elle-même son mot de passe. Le lien vaut 72 heures.

## Modifier un compte

Le crayon sur la ligne. Laissez le mot de passe vide pour le garder. Si vous en saisissez un, il remplace l'ancien tout de suite et **ferme les sessions ouvertes** de la personne.

## Mot de passe oublié

La personne peut demander un lien depuis la page de connexion (« Mot de passe oublié »). Sinon, donnez-lui un nouveau mot de passe ici.

> [!tip] Les trois compteurs du haut : comptes, comptes actifs, comptes verrouillés."
 WHERE a.slug = 'securite-creer-utilisateur' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Verrouiller ou déverrouiller un compte',
       b.summary = "Couper l'accès de quelqu'un tout de suite, sans effacer son historique.",
       b.body_md = "Le cadenas sur la ligne d'un utilisateur verrouille ou rouvre son accès.

## Ce que fait le verrouillage

- La personne ne peut plus se connecter.
- Ses sessions ouvertes se ferment : à son prochain clic, elle revient à la page de connexion.
- Ses appareils mémorisés sont oubliés.

Son historique reste : ses écritures, ses livraisons et ses lignes du journal d'audit gardent son nom.

## Verrouiller plutôt que supprimer

Au départ d'un collaborateur, verrouillez son compte. Supprimer casserait le lien entre les documents et leur auteur.

> [!note] Vous ne pouvez pas verrouiller votre propre compte."
 WHERE a.slug = 'securite-verrouiller-compte' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Comprendre les rôles et les permissions',
       b.summary = "Comment l'application décide qui peut faire quoi.",
       b.body_md = "Les droits ne se donnent pas à une personne mais à un **rôle**, et chaque personne a un rôle.

Personne, puis rôle, puis permissions : le Comptable a le rôle Comptable, le rôle Comptable a la permission « Créer une facture », donc le Comptable crée des factures. Retirez la permission au rôle, et tous ceux qui l'ont la perdent.

## Lire l'onglet Rôles

À gauche, les rôles, avec leur nombre d'utilisateurs et de permissions. Cliquez sur un rôle : ses permissions s'affichent à droite, groupées par domaine (Comptabilité & finance, Stock & achats…), chacune avec son nom en clair et, en petit, son code.

Chaque groupe montre un compteur, par exemple (8/14) : huit permissions accordées sur quatorze.

## La page d'accueil

**Page d'accueil par défaut** choisit le tableau de bord qui s'ouvre après la connexion pour ce rôle. Le rôle doit déjà avoir la permission de ce tableau de bord.

## Un principe

Accordez le minimum, puis élargissez à la demande.

> [!note] Le rôle Administrateur garde toujours les permissions qui permettent d'ouvrir cet écran : on ne peut pas s'en enfermer dehors."
 WHERE a.slug = 'securite-comprendre-rbac' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Modifier les permissions d'un rôle",
       b.summary = 'Cocher, décocher, enregistrer, et quand le changement prend effet.',
       b.body_md = "## La marche à suivre

1. Onglet **Rôles**.
2. Cliquez sur le rôle, à gauche.
3. Cochez ou décochez les permissions.
4. **Enregistrer les permissions**.

**Tout cocher / décocher** ouvre ou ferme un groupe entier d'un geste.

## Quand le changement prend effet

Les permissions d'une personne sont lues à sa connexion. Une personne déjà connectée voit le changement à sa prochaine connexion. Pour un retrait urgent, fermez sa session dans l'onglet **Sessions** : elle devra se reconnecter, avec ses nouveaux droits.

> [!warning] Réfléchissez avant de modifier le rôle avec lequel vous êtes connecté."
 WHERE a.slug = 'securite-modifier-permissions' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Créer ou supprimer un rôle',
       b.summary = "Quand un rôle sur mesure se justifie, et comment l'enlever.",
       b.body_md = "## Créer un rôle

**Nouveau rôle**, sous la liste de gauche. Donnez-lui le nom que les gens liront, par exemple « Responsable Commercial ». L'application en tire elle-même son code.

Un nouveau rôle n'a **aucune permission** : cochez ce qu'il lui faut, puis enregistrez. Choisissez aussi sa page d'accueil.

## Quand créer un rôle

Quand un poste a besoin d'un ensemble de droits qu'aucun rôle n'a déjà. Pas pour une exception sur une seule personne.

## Supprimer un rôle

**Supprimer**, en haut à droite. Un rôle encore donné à quelqu'un ne peut pas être supprimé : donnez d'abord un autre rôle à ces personnes. Les rôles de base (Administrateur, Comptable, Opérations, Commercial, Chauffeur) ne se suppriment pas."
 WHERE a.slug = 'securite-creer-role' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Découvrir l'onglet Préférences",
       b.summary = "Les groupes de réglages et ce qu'ils changent dans l'application.",
       b.body_md = "L'onglet **Préférences** réunit les réglages de l'application.

| Groupe | Exemples |
|---|---|
| Numérotation des documents | Préfixes FAC, DEV, BL, CMD ; format ; longueur et remise à zéro du compteur |
| Fiscalité & taux | TVA, AIR, IS, groupe CNPS, jour de déclaration |
| Localisation & affichage | Langue, fuseau horaire, lignes par page |
| Politique de sécurité | Déconnexion automatique, essais de connexion, longueur du mot de passe |
| Opérations & alertes | Seuil de validation en lot des écritures |
| Charges sociales & taxes | FNE, accident du travail, taxe communale, patente |
| Comptes par défaut | Les comptes des cessions d'immobilisations |

Les nombres ont des limites : une valeur hors limites est refusée.

## Enregistrer

Les champs modifiés se colorent, la barre du bas les compte, rien n'est écrit avant **Enregistrer**. Chaque changement est écrit dans le journal d'audit, avec la valeur avant et après.

> [!tip] Changez peu à la fois. Un préfixe de document changé en cours d'année rompt la suite des numéros."
 WHERE a.slug = 'prefs-vue-ensemble' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Politique de sécurité',
       b.summary = 'Déconnexion automatique, essais de connexion, longueur du mot de passe.',
       b.body_md = "Ce groupe fixe les règles d'accès.

**Déconnexion automatique** (en minutes). Après ce temps sans activité, la session se ferme. Sur un poste partagé, préférez un temps court.

**Tentatives de connexion max (par compte)**. Au delà, ce compte seul attend la **durée de verrouillage** ; les autres continuent.

**Tentatives de connexion max (par IP)**. Un garde-fou plus large pour tout un réseau : un bureau partage la même adresse internet.

**Durée de verrouillage** (en minutes).

**Longueur minimale du mot de passe**.

> [!tip] Un délai trop court pousse à contourner la sécurité. Cherchez le réglage que vos équipes accepteront."
 WHERE a.slug = 'prefs-securite-sessions' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = 'Surveiller les sessions',
       b.summary = 'Qui est connecté, les connexions refusées, et comment fermer une session.',
       b.body_md = "L'onglet **Sessions** est le journal des connexions, réussies ou refusées.

## Les trois compteurs

- **Connectés maintenant** : les personnes dont une session est ouverte et a servi pendant le délai de déconnexion automatique.
- **Mots de passe erronés (24 h)** : bonne adresse, mauvais mot de passe.
- **Connexions refusées (24 h)** : adresse inconnue, ou compte verrouillé.

## Lire le tableau

Chaque ligne donne l'heure, l'adresse saisie, l'adresse internet et le résultat. La dernière colonne dit si la session est **En ligne**, **Inactive** depuis une heure donnée, ou **Fermée**.

## Fermer une session

**Déconnecter** sur une ligne en ligne : la personne revient à la page de connexion à son prochain clic. Utile pour un poste partagé resté ouvert ou une activité suspecte.

> [!note] Des erreurs isolées sont normales. C'est la répétition depuis la même adresse qui doit alerter."
 WHERE a.slug = 'securite-sessions-actives' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Consulter le journal d'audit",
       b.summary = 'Qui a changé quoi, quand, avec la valeur avant et après.',
       b.body_md = "Le **Journal d'audit** garde la trace des changements faits dans l'application.

## Lire une ligne

| Colonne | Contenu |
|---|---|
| Horodatage | Date et heure |
| Utilisateur | Qui a agi (« Système » quand personne n'était connecté) |
| Action | Création, Modification, Suppression |
| Élément | Ce qui a changé : un compte, un rôle, l'identité de l'entreprise, une écriture… |
| Ce qui a changé | Le champ, sa valeur avant et après |

**Détail** ouvre la liste complète des champs changés.

## Ce qui est tracé

Les comptes (rôle, accès, adresse, mot de passe changé, jamais le mot de passe lui-même), les rôles et leurs permissions, la fiche entreprise et les préférences champ par champ, les sessions fermées, les écritures comptables et le plan comptable.

## À quoi ça sert

Comprendre un changement, répondre à un auditeur, reconstituer un incident.

> [!note] Le journal ne se modifie pas, même par un administrateur."
 WHERE a.slug = 'securite-journal-audit' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Découvrir le Journal d'Erreurs",
       b.summary = "À quoi sert cette page, qui l'ouvre, et quand.",
       b.body_md = "Le **Journal d'Erreurs** montre les erreurs que le serveur a rencontrées, regroupées : une même erreur au même endroit n'occupe qu'une ligne, avec le nombre de fois où elle est arrivée.

## Quand l'ouvrir

1. **Quelqu'un voit « Erreur serveur »** : la page montre ce qui s'est vraiment passé, à transmettre au support technique.
2. **Après une mise à jour** : le graphique des 24 dernières heures montre si des erreurs ont augmenté.
3. **Pour la transmettre** : **Copier** met l'erreur complète dans le presse-papiers, prête à coller dans un message.

## Marquer corrigé

Quand le support technique a corrigé une erreur, **Marquer corrigé** la range dans **Corrigées**. Si elle revient après, elle repasse dans **Ouvertes**.

## Qui l'ouvre

Les rôles qui ont la permission « Voir le journal d'erreurs » : l'Administrateur, par défaut.

## Ce qu'elle ne fait pas

Elle ne modifie rien sur le serveur, et n'envoie pas d'alerte : il faut l'ouvrir."
 WHERE a.slug = 'error-monitor-vue-ensemble' AND b.lang = 'fr';

-- The AI assistant's copy: chunk 0 = title + new body, the rest go.
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('securite-vue-ensemble', 'securite-creer-utilisateur', 'securite-verrouiller-compte',
                  'securite-comprendre-rbac', 'securite-modifier-permissions', 'securite-creer-role',
                  'prefs-vue-ensemble', 'prefs-securite-sessions', 'securite-sessions-actives',
                  'securite-journal-audit', 'error-monitor-vue-ensemble')
   AND c.lang = 'fr' AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('securite-vue-ensemble', 'securite-creer-utilisateur', 'securite-verrouiller-compte',
                  'securite-comprendre-rbac', 'securite-modifier-permissions', 'securite-creer-role',
                  'prefs-vue-ensemble', 'prefs-securite-sessions', 'securite-sessions-actives',
                  'securite-journal-audit', 'error-monitor-vue-ensemble')
   AND c.lang = 'fr' AND c.chunk_index = 0;

COMMIT;

-- VERIFY (by hand):
--   SELECT pref_key FROM app_preferences WHERE is_active = 0;
--   SHOW CREATE TRIGGER au_users_audit;
--   SELECT b.title FROM help_article_bodies b JOIN help_articles a ON a.id = b.article_id
--    WHERE a.slug LIKE 'securite-%' AND b.lang = 'fr';
