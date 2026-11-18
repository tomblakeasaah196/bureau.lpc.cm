-- =============================================================================
-- 130_help_master_data.sql
-- -----------------------------------------------------------------------------
-- Help Centre: the « Données de Base » articles describe the page as it is.
--
-- WHY
--   The master-data-hub articles were seeded by 071 (and two of them reworded
--   by 106) for an older page: five tabs, open to administrators only, a third
--   « prix de repli » step that no sale ever applied, an employee form that
--   asked for an email, a password and a role, deposits (« consigne ») on
--   empties, supplier codes LPC-SUP-####, a fleet tab with a make and model
--   column. The page now has six tabs (Tarifs Fournisseurs added), opens to
--   the Directeur Général, the Contrôleur Financier, the Comptable and the
--   Responsable des Opérations (only the first two may change anything, the
--   others get « Consulter »), shows two price steps, keeps a price history on
--   each tariff, creates logins only in Paramètres → Utilisateurs, opens a
--   401xxx account for each new supplier, and sells bottles with no deposit
--   (empties go back to recyclers). Found while filming the training videos
--   (docs/TRAINING_VIDEOS.md, VIDEO_QA_FINDINGS §1).
--
-- WHAT
--   help_categories       the category summary names the six tabs.
--   help_articles         mdm-prix-de-repli is unpublished: the step it
--                         explains is no longer on screen.
--   help_article_bodies   the FR title, summary, keywords and body of the
--                         fourteen published articles; the EN ones for the
--                         four articles that have an English body (the other
--                         ten never had one, and the reader falls back to FR).
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body
--                         and the others go, so the assistant stops quoting the
--                         old text at once; every chunk of mdm-prix-de-repli
--                         goes. The body's updated_at moves (ON UPDATE
--                         CURRENT_TIMESTAMP), so scripts/cron/
--                         reindex_help_chunks.php re-chunks and re-embeds these
--                         articles on its next run (it skips unpublished ones).
--
-- The seeds (071, 106) are not edited: they have run in production.
-- Idempotent: every statement sets fixed values.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- -----------------------------------------------------------------------------
-- Category summary
-- -----------------------------------------------------------------------------
UPDATE help_categories
   SET summary_fr = "Les listes que tout l'ERP utilise : produits, tarifs clients, tarifs fournisseurs, employés, fournisseurs et flotte. Qui peut les consulter, qui peut les modifier, et ce que chaque champ change.",
       summary_en = 'The lists the whole ERP relies on: products, client tariffs, supplier tariffs, employees, suppliers and fleet. Who can view them, who can change them, and what each field changes.'
 WHERE slug = 'master-data-hub';

-- -----------------------------------------------------------------------------
-- The fallback price step is gone from the screen
-- -----------------------------------------------------------------------------
UPDATE help_articles SET is_published = 0 WHERE slug = 'mdm-prix-de-repli';

DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug = 'mdm-prix-de-repli';

-- =============================================================================
-- mdm-vue-ensemble
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Découvrir les Données de Base",
       b.summary  = "Les six onglets, qui peut consulter, qui peut modifier.",
       b.keywords = 'donnees de base, onglets, produits, tarifs clients, tarifs fournisseurs, employes, fournisseurs, flotte, consulter, droits',
       b.body_md  = "La page **Données de Base** tient les listes que tout l'ERP utilise : les produits, les prix, les employés, les fournisseurs. Les devis, les commandes, les achats et la paie lisent ces listes. Une correction faite ici se voit donc partout.

## Les six onglets

De gauche à droite :

1. **Produits** : le catalogue. Ce que LPC vend, les emballages vides, le prix de base de chaque produit.
2. **Tarifs Clients** : le prix négocié avec un client pour un produit.
3. **Tarifs Fournisseurs** : ce qu'un fournisseur facture à LPC pour un produit.
4. **Employés & RH** : la fiche de chaque personne que LPC emploie, avec son salaire.
5. **Fournisseurs** : les fournisseurs, comme Source du Pays ou HOREB SARL.
6. **Flotte** : la liste des véhicules, en consultation seulement.

## Qui peut faire quoi

La page s'ouvre au **Directeur Général**, au **Contrôleur Financier**, au **Comptable** et au **Responsable des Opérations**.

Seuls le Directeur Général et le Contrôleur Financier peuvent modifier : créer, corriger, désactiver, saisir un tarif ou le supprimer, changer un prix par défaut.

Le Comptable et le Responsable des Opérations consultent. Ils n'ont pas de bouton pour ajouter. Sur chaque ligne, le bouton **Consulter** (un œil) ouvre la même fiche, avec tous les champs verrouillés et sans **Enregistrer**. Ils peuvent aussi ouvrir l'**Historique des prix** d'un tarif.

## Désactiver plutôt que supprimer

Un produit, un employé ou un fournisseur ne se supprime pas. Vous le désactivez avec **Désactiver** : il reste dans l'historique, mais on ne peut plus le choisir. **Réactiver** le remet en service."
 WHERE a.slug = 'mdm-vue-ensemble' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Getting to know Master Data',
       b.summary  = 'The six tabs, who can view, who can change.',
       b.keywords = 'master data, donnees de base, tabs, products, client tariffs, supplier tariffs, employees, suppliers, fleet, view, rights',
       b.body_md  = "The **Données de Base** (Master Data) page holds the lists the whole ERP uses: products, prices, employees, suppliers. Quotes, orders, purchases and payroll read these lists, so a correction made here shows everywhere.

The page itself is in French; buttons are named below as the screen reads them.

## The six tabs

Left to right:

1. **Produits**: the catalogue. What LPC sells, the empties, each product's base price.
2. **Tarifs Clients**: the price agreed with one client for one product.
3. **Tarifs Fournisseurs**: what a supplier charges LPC for one product.
4. **Employés & RH**: the record of each person LPC employs, with their salary.
5. **Fournisseurs**: the suppliers, such as Source du Pays or HOREB SARL.
6. **Flotte**: the list of vehicles, view only.

## Who can do what

The page opens to the **Directeur Général**, the **Contrôleur Financier**, the **Comptable** and the **Responsable des Opérations**.

Only the Directeur Général and the Contrôleur Financier can change anything: create, correct, deactivate, enter or delete a tariff, change a default price.

The Comptable and the Responsable des Opérations view. They have no add button. On each row, the **Consulter** button (an eye) opens the same record with every field locked and no **Enregistrer**. They can also open a tariff's **Historique des prix**.

## Deactivate rather than delete

A product, an employee or a supplier is never deleted. You deactivate it with **Désactiver**: it stays in the history but can no longer be chosen. **Réactiver** brings it back."
 WHERE a.slug = 'mdm-vue-ensemble' AND b.lang = 'en';

-- =============================================================================
-- mdm-produits-vue-ensemble
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "L'onglet Produits",
       b.summary  = "Ce que montre chaque colonne, et comment les emballages vides sont regroupés.",
       b.keywords = 'produits, catalogue, sku, categorie, prix base, emballage lie, compte vente, famille, desactiver',
       b.body_md  = "L'onglet **Produits** est le catalogue. Chaque ligne est un produit que LPC vend ou reprend : l'eau OPUR en 20L et en 10L, les emballages vides, l'équipement.

## Les colonnes

| Colonne | Ce qu'elle montre |
| --- | --- |
| Code SKU | Le code court du produit, par exemple *WAT-20L-001*. |
| Désignation | Le nom qui s'imprime sur les devis et les factures. |
| Catégorie | Eau, Jus, Emballage, Equipement… |
| Emballage Lié | Le vide que le client doit rendre pour ce produit, s'il y en a un. |
| Prix Base | Le prix de vente par défaut, en FCFA. |
| Compte Vente | Le compte que chaque facture du produit crédite. |
| Statut | *Actif* ou *Inactif*. |

## Les emballages vides

Un vide de 20L avec bouchon et le même vide sans bouchon sont deux fiches, car ils n'ont pas le même prix de revente. La liste les montre sur une seule ligne **Famille 20L**, avec une pastille par état. Cliquez sur une pastille pour ouvrir la fiche de cet état.

## Les boutons

- **Nouveau Produit** ouvre une fiche vide. Voir *Créer ou modifier un produit*.
- Le crayon ouvre la fiche d'un produit.
- **Désactiver** retire le produit des listes de saisie. Il reste sur les devis, factures et rapports passés. **Réactiver** le remet en vente.

Le champ **Rechercher...** filtre la liste."
 WHERE a.slug = 'mdm-produits-vue-ensemble' AND b.lang = 'fr';

-- =============================================================================
-- mdm-produit-creer
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Créer ou modifier un produit',
       b.summary  = "La fiche produit, section par section : identification, conditionnement, emballage vide, prix et comptabilité.",
       b.keywords = 'produit, creer, modifier, fiche, sku, format, pack, prix de base, cout de reference, compte de vente',
       b.body_md  = "**Nouveau Produit** ouvre une fiche vide. Le crayon d'une ligne ouvre la même fiche, remplie. Elle a quatre sections.

## Identification

- **Catégorie** : elle décide des champs qui suivent. Le **+** à côté crée une catégorie sans quitter la fiche.
- **Code SKU (proposé, modifiable)** : proposé à partir de la catégorie et du format. Vous pouvez le changer. **⟳** en propose un nouveau.
- **Désignation** : le nom imprimé sur les documents.

## Conditionnement

- **Contenance / Format** : *20L*, *10L*, *1.5L*…
- **Unité de mesure** : bonbonne, bouteille, pack…
- **Unités par pack** et **Le prix de base s'applique à** : seulement pour les catégories vendues en packs, comme le jus. Vous choisissez si le prix vaut pour **L'unité** ou **Le pack complet**.

## Emballage vide

Pour un produit plein, choisissez l'**Emballage vide lié**. Voir *L'emballage vide lié à un produit*. Pour un vide, vous indiquez son format et son bouchon.

## Prix & Comptabilité

- **Prix de base (FCFA)** : obligatoire. Le prix de vente par défaut, pour tout client sans tarif négocié. Pour un vide, c'est le prix auquel LPC le revend à un recycleur.
- **Coût de référence (FCFA)** : ce que LPC paie ce produit quand le fournisseur n'a pas de tarif pour lui. Laissé vide à la création, il prend le prix de base.
- **Compte de vente** : le compte que chaque facture du produit crédite. C'est celui de la catégorie (Eau 7011, Jus 7012, Emballage 7074, Equipement 7078), ou celui du produit s'il a son propre compte. Enregistrer la fiche garde ce compte.

**Enregistrer** ferme la fiche. Le message confirme le code et le compte de vente."
 WHERE a.slug = 'mdm-produit-creer' AND b.lang = 'fr';

-- =============================================================================
-- mdm-produit-categorie-et-sku
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Catégorie et code SKU',
       b.summary  = "Ce que la catégorie décide, et comment le code SKU est proposé.",
       b.keywords = 'categorie, sku, code, prefixe, compte vente, nouvelle categorie, produit',
       b.body_md  = "Deux champs de la fiche produit comptent plus qu'il n'y paraît : la catégorie et le code SKU.

## La catégorie

La catégorie décide de trois choses :

- **Le compte de vente.** Chaque facture d'un produit crédite le compte de sa catégorie : Eau 7011, Jus 7012, Emballage 7074, Equipement 7078. La comptabilité voit ainsi ce que rapporte l'eau, et ce que rapportent les vides revendus.
- **Les champs de la fiche.** Une catégorie vendue en packs affiche les champs de pack. La catégorie Emballage fait de la fiche un emballage vide.
- **Le début du code SKU**, par exemple *WAT* pour l'eau.

Sous le champ, une ligne rappelle le préfixe et le compte, par exemple *Préfixe WAT · ventes au compte 7011*.

Changer la catégorie d'un produit change le compte des factures à venir. Les factures passées ne bougent pas.

## Créer une catégorie

Le **+** à côté de **Catégorie** ouvre une petite fenêtre au-dessus de la fiche. Donnez le **Nom de la catégorie**, et si vous voulez un **Préfixe SKU (facultatif)**. Ses comptes de vente et de stock sont créés tout seuls. La nouvelle catégorie est choisie dans la fiche, qui reste ouverte.

## Le code SKU

Le code a trois parties : la catégorie, le format, un numéro. *WAT-20L-001* est la première eau en 20L.

Il est proposé à partir de la catégorie et du format. Vous pouvez le remplacer, par exemple pour garder un code déjà connu. Après un changement de format, **⟳** en propose un nouveau.

Deux produits ne peuvent pas avoir le même code : l'enregistrement est refusé."
 WHERE a.slug = 'mdm-produit-categorie-et-sku' AND b.lang = 'fr';

-- =============================================================================
-- mdm-produit-consigne-emballage
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "L'emballage vide lié à un produit",
       b.summary  = "Pourquoi une bonbonne pleine est liée à son vide, et comment créer un vide.",
       b.keywords = 'emballage, vide, emballage vide lie, bouchon, format, recycleur, vente recyclage, bonbonne',
       b.body_md  = "LPC vend l'eau OPUR en bonbonnes de 20L et de 10L, sans caution. Le client rend ensuite les bonbonnes vides. LPC les revend à des recycleurs, dans **Vente Recyclage**.

## Lier un produit à son vide

Sur la fiche d'un produit plein, la section **Emballage vide** propose l'**Emballage vide lié**. Ce choix est facultatif : un produit sans vide à reprendre n'en a pas.

Quand un produit a un vide lié, à chaque livraison le client doit autant de vides de cet emballage que de bouteilles livrées. C'est ce qui permet de suivre les vides chez chaque client.

Le vide n'existe pas encore ? Le **+** à côté du champ ouvre **Nouvel emballage vide** :

- **Désignation**, par exemple *20L sans bouchon* ;
- **Format** : 20L, 10L… ;
- **Bouchon** : avec ou sans ;
- **Prix de revente (FCFA)** : le prix auquel LPC revend ce vide à un recycleur.

Le nouveau vide est choisi dans la fiche, qui reste ouverte.

## La fiche d'un vide

Un vide est un produit de la catégorie Emballage. Sa fiche demande le **Format du contenant** et l'**État du bouchon**. Ces deux champs le distinguent des autres vides : c'est ainsi que le suivi des vides le retrouve chez chaque client.

Un même contenant avec et sans bouchon fait deux fiches, car le prix de revente diffère. La liste les regroupe en une ligne **Famille 20L**.

> [!tip] Le **Prix de base (FCFA)** d'un vide est son prix de revente au recycleur."
 WHERE a.slug = 'mdm-produit-consigne-emballage' AND b.lang = 'fr';

-- =============================================================================
-- mdm-tarifs-clients-vue-ensemble
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "L'onglet Tarifs Clients",
       b.summary  = "Le prix négocié avec un client, son historique, et comment le saisir ou le supprimer.",
       b.keywords = 'tarifs clients, prix negocie, ecart, historique des prix, nouveau tarif, supprimer tarif, prix par defaut',
       b.body_md  = "L'onglet **Tarifs Clients** liste les prix négociés. Un tarif dit : *pour ce client, ce produit se vend à ce prix, et non au prix par défaut*.

## Les colonnes

| Colonne | Ce qu'elle montre |
| --- | --- |
| Client | Le client concerné. |
| Produit | Le produit concerné. |
| Prix Défaut | Le prix de base du produit, pour comparer. |
| Prix Négocié | Le prix accordé à ce client. |
| Écart | La différence en FCFA : ambre en dessous du prix par défaut, vert au-dessus, *Identique* sinon. |

Au-dessus de la liste, le panneau **Comment le prix est choisi** rappelle les deux étapes. Voir *Comment un prix est choisi*.

## Les boutons d'une ligne

- Le crayon corrige le prix négocié.
- **Historique des prix** montre chaque changement : la date, l'ancien prix → le nouveau, la source (**Données de Base**, ou **Commande client** avec sa référence), qui l'a fait, et la note.
- La corbeille supprime le tarif. Ce client repasse alors au prix par défaut.

## Nouveau Tarif

**Nouveau Tarif** ouvre une fiche courte :

- **Client** : cherchez par nom.
- **Produit** : le choisir remplit le prix avec le prix par défaut.
- **Prix Négocié (FCFA)** : le prix accordé.

Une ligne sous le prix montre l'écart avec le prix par défaut.

> [!warning] Un tarif ne suit pas le prix par défaut. Si le prix par défaut monte, le client garde son tarif. Relisez les tarifs à chaque hausse."
 WHERE a.slug = 'mdm-tarifs-clients-vue-ensemble' AND b.lang = 'fr';

-- =============================================================================
-- mdm-echelle-des-prix
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Comment un prix est choisi',
       b.summary  = "Deux étapes pour une vente, dans l'ordre. La première qui donne un prix l'emporte. Et comment un achat est tarifé.",
       b.keywords = 'prix, tarif negocie, prix par defaut, sans prix, bon de commande, tarif fournisseur, cout de reference, cump',
       b.body_md  = "Sur un devis ou une commande, l'ERP cherche le prix de chaque ligne en deux étapes. La **première qui donne un prix l'emporte**.

## 1. Tarif négocié

Si ce client a un tarif pour ce produit, c'est ce prix. Voir *L'onglet Tarifs Clients*.

## 2. Prix par défaut

Sinon, c'est le prix de base de la fiche produit. Il vaut pour tous les autres clients.

Il n'y a pas d'autre étape. Un produit sans prix par défaut, vendu à un client sans tarif, partirait à 0 FCFA.

## Le panneau Comment le prix est choisi

En haut de l'onglet **Tarifs Clients**, deux cartes montrent ces étapes, avec le nombre de tarifs et de produits.

Si des produits n'ont pas de prix par défaut, une ligne ambre le dit : un client sans tarif négocié les recevrait à 0 FCFA.

Pour le Directeur Général et le Contrôleur Financier, le bouton **Prix par défaut** ouvre la liste des produits avec leur prix. Corrigez un prix sur place, puis **OK**. **Sans prix uniquement** ne garde que les produits à corriger.

## Et pour un achat ?

Un bon de commande suit le même principe, dans l'onglet **Tarifs Fournisseurs** :

1. **Tarif fournisseur** : ce que ce fournisseur facture pour ce produit.
2. **Coût de référence** : sur la fiche du produit, quand le fournisseur n'a pas de tarif.
3. **Coût moyen (CUMP)** : il sert à valoriser le stock. Il ne fixe jamais le prix d'un bon de commande.

Chaque bon de commande garde aussi le prix saisi : les tarifs fournisseurs se remplissent au fil des achats. Chaque tarif a son **Historique des prix**, avec la référence du BC. La corbeille supprime un tarif : le bon de commande repasse au coût de référence."
 WHERE a.slug = 'mdm-echelle-des-prix' AND b.lang = 'fr';

-- =============================================================================
-- mdm-employes-rh
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "L'onglet Employés & RH",
       b.summary  = "La fiche de chaque personne que LPC emploie, avec ou sans accès à l'ERP, et son salaire.",
       b.keywords = 'employes, rh, matricule, connexion, role systeme, poste, salaire de base, paie, desactiver',
       b.body_md  = "L'onglet **Employés & RH** tient la fiche de chaque personne que LPC emploie, qu'elle se connecte à l'ERP ou non.

## Les colonnes

| Colonne | Ce qu'elle montre |
| --- | --- |
| Profil | La photo. |
| Matricule | Donné par le système. Il figure sur le bulletin de paie. |
| Connexion | L'adresse avec laquelle la personne se connecte, ou *non provisionnée* si elle n'a pas d'accès. |
| Nom & Prénom | Le nom complet. |
| Rôle Système | Directeur Général, Contrôleur Financier, Comptable, Opérations, Administrateur… Voir *Rôle Système et Poste*. |
| Poste | L'intitulé du métier. |
| Statut | *Actif* ou *Inactif*. |

## Nouvel Employé

La fiche a quatre sections :

1. **Identité & RH** : prénom et nom, date de naissance, genre, numéros de pièce d'identité et de CNPS, situation familiale, enfants à charge, téléphone, adresse, contact d'urgence, date d'embauche, et date de départ si besoin.
2. **Paie & rémunération** : le **Salaire de base (FCFA)**, obligatoire, les indemnités de logement, de transport et autres, le régime fiscal, l'ancienneté, la banque ou le Mobile Money.
3. **Documents** : photo, pièce d'identité, contrat signé.
4. **Organisation & rythme** : poste, département, responsable, jours et heures de travail.

Le matricule n'est pas demandé : le système le donne.

## Le salaire

Les salaires se tiennent ici. La paie, dans **Gestion du Personnel**, les lit. Pour changer un salaire, ouvrez la fiche ici.

## L'accès à l'ERP

La fiche ne demande ni adresse de connexion, ni mot de passe, ni rôle. L'accès se crée dans **Paramètres → Utilisateurs**, pour un employé qui existe déjà ici. Voir *Donner un accès à un nouvel employé*.

## Désactiver

**Désactiver** un employé ferme aussi son accès, s'il en a un. **Réactiver** le rouvre."
 WHERE a.slug = 'mdm-employes-rh' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'The Employees & HR tab',
       b.summary  = 'The record of each person LPC employs, with or without ERP access, and their salary.',
       b.keywords = 'employees, hr, staff number, sign-in, system role, job title, base salary, payroll, deactivate',
       b.body_md  = "The **Employés & RH** tab holds the record of each person LPC employs, whether or not they sign in to the ERP. The page is in French; labels are given as the screen reads them.

## The columns

| Column | What it shows |
| --- | --- |
| Profil | The photo. |
| Matricule | The staff number, given by the system. It is on the payslip. |
| Connexion | The address the person signs in with, or *non provisionnée* if they have no access. |
| Nom & Prénom | The full name. |
| Rôle Système | Directeur Général, Contrôleur Financier, Comptable, Opérations, Administrateur… See *System Role and Job Title*. |
| Poste | The job title. |
| Statut | *Actif* or *Inactif*. |

## Nouvel Employé

The form has four sections:

1. **Identité & RH**: first and last name, birth date, gender, ID and CNPS numbers, marital status, dependent children, phone, address, emergency contact, hire date, and end date when needed.
2. **Paie & rémunération**: the **Salaire de base (FCFA)**, required, the housing, transport and other allowances, the tax regime, seniority, the bank or Mobile Money.
3. **Documents**: photo, ID, signed contract.
4. **Organisation & rythme**: job title, department, manager, working days and hours.

The staff number is not asked for: the system gives it.

## The salary

Salaries are kept here. Payroll, in **Gestion du Personnel**, reads them. To change a salary, open the record here.

## Access to the ERP

The form asks for no sign-in address, no password and no role. Access is created in **Paramètres → Utilisateurs**, for an employee who already exists here. See *Giving a new employee access*.

## Deactivate

**Désactiver** on an employee also closes their access, if they have one. **Réactiver** reopens it."
 WHERE a.slug = 'mdm-employes-rh' AND b.lang = 'en';

-- =============================================================================
-- mdm-employes-role-vs-poste
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Rôle Système et Poste',
       b.summary  = "Le rôle décide de ce que la personne voit et fait dans l'ERP. Le poste est seulement un intitulé.",
       b.keywords = 'role systeme, poste, droits, acces, je ne vois rien, roles et permissions, utilisateurs',
       b.body_md  = "La liste des employés montre deux colonnes qui se ressemblent : **Rôle Système** et **Poste**. Elles n'ont rien à voir.

## Rôle Système

Le rôle décide de ce que la personne voit dans le menu, des boutons qu'elle a, et de ce qu'elle peut enregistrer. Les rôles de LPC sont :

- **Directeur Général**
- **Contrôleur Financier**
- **Comptable**
- **Opérations**, pour le Responsable des Opérations
- **Administrateur**

Seule une personne qui se connecte a un rôle. Un employé sans accès affiche *Aucun*.

Le rôle ne se choisit pas dans **Données de Base**. Il se choisit dans **Paramètres → Utilisateurs**, quand l'accès est créé ou modifié. Ce que chaque rôle peut faire se règle dans **Rôles & Permissions**.

## Poste

Le poste est un intitulé libre, comme *Chauffeur* ou *Commercial*. Il figure sur la fiche et dans les rapports RH. Il ne donne **aucun droit**.

Une personne au poste *Comptable* avec le rôle *Opérations* voit ce que voit le Responsable des Opérations, pas la comptabilité.

> [!tip] Un collègue dit « je ne vois rien » ? Regardez son **Rôle Système**, pas son **Poste**. Le rôle se corrige dans **Paramètres → Utilisateurs**."
 WHERE a.slug = 'mdm-employes-role-vs-poste' AND b.lang = 'fr';

-- =============================================================================
-- mdm-fournisseurs
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "L'onglet Fournisseurs",
       b.summary  = "La liste des fournisseurs, le formulaire, et le compte fournisseur ouvert pour chacun.",
       b.keywords = 'fournisseurs, nouveau fournisseur, raison sociale, code, frs, compte fournisseur, 401, doublon',
       b.body_md  = "L'onglet **Fournisseurs** liste ceux à qui LPC achète, comme Source du Pays ou HOREB SARL.

## Les colonnes

| Colonne | Ce qu'elle montre |
| --- | --- |
| Code | *FRS-001*, *FRS-002*… Donné par le système. |
| Fournisseur | La raison sociale. |
| Contact | Le téléphone. |
| Statut | *Actif* ou *Inactif*. |

## Nouveau Fournisseur

La fiche est courte :

- **Raison Sociale** : obligatoire.
- **Téléphone**
- **Email**
- **Adresse Complète**

## Le compte du fournisseur

Enregistrer un nouveau fournisseur lui ouvre aussi son propre compte : 401001, 401002… sous **401 Fournisseurs**. Ainsi la comptabilité montre ce que LPC doit à chaque fournisseur, séparément. Le message de confirmation donne le code et le compte.

Renommer un fournisseur renomme son compte. Un fournisseur enregistré avant d'avoir un compte le reçoit au prochain **Enregistrer**.

## Désactiver

**Désactiver** retire le fournisseur des listes de choix. Ses achats passés restent.

> [!warning] Ne créez jamais deux fois le même fournisseur : ses achats et sa dette seraient coupés en deux. S'il a changé de nom, ouvrez sa fiche avec le crayon et corrigez le nom."
 WHERE a.slug = 'mdm-fournisseurs' AND b.lang = 'fr';

-- =============================================================================
-- mdm-flotte-vs-module-flotte
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "L'onglet Flotte : consultation seulement",
       b.summary  = "Pourquoi les véhicules se lisent ici mais se créent et se modifient dans Flotte & Maintenance.",
       b.keywords = 'flotte, vehicules, consultation seulement, ouvrir, flotte et maintenance, immatriculation',
       b.body_md  = "L'onglet **Flotte** montre la liste des véhicules de LPC. Un bandeau le rappelle : **Consultation seulement**.

## Ce que montre l'onglet

| Colonne | Ce qu'elle montre |
| --- | --- |
| Immatriculation | La plaque. |
| Type | Camion, tricycle, moto. |
| État | *Opérationnel*, ou *En Panne/Retiré*. |

## Où créer et modifier un véhicule

Dans **Flotte & Maintenance**. C'est là que se tiennent le kilométrage, le carburant, l'assurance et la visite technique.

Pour y aller :

- le bouton **Ouvrir** au bout de chaque ligne ;
- le bouton **Ouvrir Flotte & Maintenance** du bandeau.

## Pourquoi

Un véhicule créé sans sa date d'assurance, sa visite technique et son premier kilométrage ressort dans les alertes, et son premier plein est refusé. Créer les véhicules au seul endroit qui demande tout évite ces fiches à moitié remplies.

> [!tip] Un véhicule ancien a des dates vides ? Ouvrez-le dans **Flotte & Maintenance** avec le crayon et complétez l'assurance et la visite technique."
 WHERE a.slug = 'mdm-flotte-vs-module-flotte' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'The Fleet tab: view only',
       b.summary  = 'Why vehicles are listed here but created and changed in Fleet & Maintenance.',
       b.keywords = 'fleet, vehicles, view only, open, fleet and maintenance, plate number',
       b.body_md  = "The **Flotte** tab shows LPC's vehicles. A banner says so: **Consultation seulement** (view only).

## What the tab shows

| Column | What it shows |
| --- | --- |
| Immatriculation | The plate number. |
| Type | Truck, tricycle, motorbike. |
| État | *Opérationnel* (in service), or *En Panne/Retiré* (broken down or retired). |

## Where to create and change a vehicle

In **Flotte & Maintenance** (Fleet & Maintenance). That is where mileage, fuel, insurance and technical inspection are kept.

To get there:

- the **Ouvrir** button at the end of each row;
- the **Ouvrir Flotte & Maintenance** button in the banner.

## Why

A vehicle created without its insurance date, technical inspection and first mileage shows up in the alerts, and its first fuel entry is refused. Creating vehicles only where every field is asked for avoids half-filled records.

> [!tip] An older vehicle has empty dates? Open it in **Flotte & Maintenance** with the pencil and fill in the insurance and technical inspection."
 WHERE a.slug = 'mdm-flotte-vs-module-flotte' AND b.lang = 'en';

-- =============================================================================
-- faq-mdm-produit-invisible-devis
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = "Mon nouveau produit n'apparaît pas sur un devis. Pourquoi ?",
       b.summary  = "Trois causes : le produit est inactif, c'est un emballage vide, ou la page du devis était déjà ouverte.",
       b.keywords = 'produit, invisible, absent, devis, commande, liste des produits, inactif, emballage vide, recharger',
       b.body_md  = "La liste des produits d'un devis ne montre que les produits **actifs**, et pas les emballages vides. Vérifiez dans l'ordre :

1. **Le statut.** Dans **Données de Base → Produits**, la colonne *Statut* doit dire *Actif*. Sinon, cliquez **Réactiver**.
2. **La catégorie.** Un produit de la catégorie Emballage est un vide. Un vide ne se vend pas sur un devis client : il se revend à un recycleur dans **Vente Recyclage**. Si le produit n'est pas un vide, corrigez sa **Catégorie** avec le crayon.
3. **La page du devis.** La liste des produits se charge à l'ouverture de la page. Un produit créé pendant qu'un devis était ouvert n'y paraît qu'après avoir rechargé la page.

Si le produit reste absent après ces trois points, signalez-le à un administrateur."
 WHERE a.slug = 'faq-mdm-produit-invisible-devis' AND b.lang = 'fr';

-- =============================================================================
-- faq-mdm-tarif-au-dessus-defaut
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Mon tarif est plus cher que le prix par défaut. Est-ce grave ?',
       b.summary  = "Souvent une faute de frappe, parfois un choix voulu. L'ERP vous prévient sans bloquer.",
       b.keywords = 'tarif, ecart, plus cher, au-dessus, prix par defaut, faute de frappe, verifier',
       b.body_md  = "Dans **Nouveau Tarif**, une ligne sous **Prix Négocié (FCFA)** compare votre prix au prix par défaut :

- en gris, le prix par défaut seul ;
- en ambre, votre prix est plus bas, avec l'écart en FCFA et en % ;
- en vert, votre prix est plus haut : *au-dessus de la référence : vérifiez* ;
- en rouge, votre prix est plus de 50 % sous le prix par défaut.

Dans la liste, la colonne **Écart** suit les mêmes couleurs.

## Quand c'est voulu

- Un client qui demande un service en plus, compris dans son prix.
- Un accord signé avant une baisse du prix par défaut, que vous gardez exprès.

## Quand c'est une erreur

- **Une faute de frappe** : 22 750 au lieu de 2 275. Comptez les zéros.
- **Le mauvais produit** : le 20L choisi à la place du 10L.
- **Un vieux tarif** : le prix par défaut a baissé, le tarif est resté. Corrigez-le avec le crayon, ou supprimez-le : le client repasse au prix par défaut.

La ligne ne bloque pas l'enregistrement. Elle vous prévient. Un tarif au-dessus du prix par défaut est permis quand c'est un choix."
 WHERE a.slug = 'faq-mdm-tarif-au-dessus-defaut' AND b.lang = 'fr';

-- =============================================================================
-- faq-mdm-nouvel-employe-connexion
-- =============================================================================
UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Donner un accès à un nouvel employé',
       b.summary  = "La fiche se crée dans Données de Base, l'accès dans Paramètres → Utilisateurs.",
       b.keywords = 'nouvel employe, acces, connexion, compte, mot de passe initial, role systeme, utilisateurs, provisionner, ne peut pas se connecter',
       b.body_md  = "Donner un accès se fait en deux temps, sur deux pages.

## 1. La fiche, dans Données de Base

Créez d'abord la personne dans **Données de Base → Employés & RH**, avec **Nouvel Employé**. La fiche ne demande ni adresse de connexion ni mot de passe.

## 2. L'accès, dans Paramètres → Utilisateurs

Un administrateur ouvre **Paramètres → Utilisateurs** et clique **Provisionner un compte**. Il remplit :

- **Employé** : la personne. Seuls les employés actifs sans accès sont proposés.
- **Email (identifiant de connexion)** : l'adresse avec laquelle elle se connectera. Elle doit être unique.
- **Rôle Système** : ce qu'elle pourra voir et faire. Voir *Rôle Système et Poste*.
- **Mot de passe initial**

Communiquez ce mot de passe à la personne vous-même, de vive voix de préférence. Elle le change ensuite depuis **Changer mon mot de passe**.

## La personne n'arrive pas à se connecter

- **L'adresse.** Relisez la colonne **Connexion** de sa fiche. Une lettre de travers suffit.
- **Le statut.** Un employé *Inactif* ne peut pas se connecter. **Réactiver** rouvre son accès.
- **Le mot de passe.** Un administrateur peut en définir un nouveau dans **Paramètres → Utilisateurs**.

## Elle se connecte mais ne voit rien

C'est son rôle, pas son accès. Corrigez le **Rôle Système** dans **Paramètres → Utilisateurs**."
 WHERE a.slug = 'faq-mdm-nouvel-employe-connexion' AND b.lang = 'fr';

UPDATE help_article_bodies b
  JOIN help_articles a ON a.id = b.article_id
   SET b.title    = 'Giving a new employee access',
       b.summary  = 'The record is created in Master Data, the access in Paramètres → Utilisateurs.',
       b.keywords = 'new employee, access, sign in, account, initial password, system role, users, provision, cannot sign in',
       b.body_md  = "Giving someone access takes two steps, on two pages. The screens are in French; labels are given as they read.

## 1. The record, in Master Data

First create the person in **Données de Base → Employés & RH**, with **Nouvel Employé**. The form asks for no sign-in address and no password.

## 2. The access, in Paramètres → Utilisateurs

An administrator opens **Paramètres → Utilisateurs** and clicks **Provisionner un compte**. They fill in:

- **Employé**: the person. Only active employees with no access are offered.
- **Email (identifiant de connexion)**: the address they will sign in with. It must be unique.
- **Rôle Système**: what they will be able to see and do. See *System Role and Job Title*.
- **Mot de passe initial**: the first password.

Give this password to the person yourself, preferably by word of mouth. They then change it from **Changer mon mot de passe**.

## The person cannot sign in

- **The address.** Re-read the **Connexion** column on their record. One wrong letter is enough.
- **The status.** An *Inactif* employee cannot sign in. **Réactiver** reopens their access.
- **The password.** An administrator can set a new one in **Paramètres → Utilisateurs**.

## They sign in but see nothing

That is their role, not their access. Correct the **Rôle Système** in **Paramètres → Utilisateurs**."
 WHERE a.slug = 'faq-mdm-nouvel-employe-connexion' AND b.lang = 'en';

-- -----------------------------------------------------------------------------
-- The AI assistant's copy: chunk 0 = title + new body, the rest go.
-- -----------------------------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('mdm-vue-ensemble', 'mdm-produits-vue-ensemble', 'mdm-produit-creer',
                  'mdm-produit-categorie-et-sku', 'mdm-produit-consigne-emballage',
                  'mdm-tarifs-clients-vue-ensemble', 'mdm-echelle-des-prix',
                  'mdm-employes-rh', 'mdm-employes-role-vs-poste', 'mdm-fournisseurs',
                  'mdm-flotte-vs-module-flotte', 'faq-mdm-produit-invisible-devis',
                  'faq-mdm-tarif-au-dessus-defaut', 'faq-mdm-nouvel-employe-connexion')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('mdm-vue-ensemble', 'mdm-produits-vue-ensemble', 'mdm-produit-creer',
                  'mdm-produit-categorie-et-sku', 'mdm-produit-consigne-emballage',
                  'mdm-tarifs-clients-vue-ensemble', 'mdm-echelle-des-prix',
                  'mdm-employes-rh', 'mdm-employes-role-vs-poste', 'mdm-fournisseurs',
                  'mdm-flotte-vs-module-flotte', 'faq-mdm-produit-invisible-devis',
                  'faq-mdm-tarif-au-dessus-defaut', 'faq-mdm-nouvel-employe-connexion')
   AND c.chunk_index = 0;

COMMIT;
