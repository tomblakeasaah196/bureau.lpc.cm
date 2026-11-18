-- =============================================================================
-- 143_help_ventes.sql
-- -----------------------------------------------------------------------------
-- Ventes & Commandes: the help articles follow what the module now does
-- (migration 142, docs/VIDEO_QA_FINDINGS.md §5, 18/11/2026).
--
--   help_article_bodies   seven articles (FR): « Flotte LPC » is LPC's vehicle
--                         and a driver from the staff (no driver accounts);
--                         the Responsable des Opérations closes deliveries;
--                         one « Clôturer » whatever the channel; only a BL the
--                         client signed is invoiced; an order whose BL is not
--                         signed can be cancelled and its bottles come back;
--                         a price above the tariff is a majoration; no table
--                         names or permission keys on the page.
--   help_article_chunks   the AI assistant's copy: chunk 0 takes the new body,
--                         the others go (reindex_help_chunks.php re-chunks).
--
-- Re-runnable: plain updates keyed by slug.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- demarrer-ventes-et-dispatch ---------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "**Ventes & Commandes** suit la marchandise qui sort : de la commande du client jusqu'à la facture. Le Responsable des Opérations y prend les commandes et organise les livraisons.

## Les deux onglets

- **Commandes Clients** : ce que les clients ont commandé.
- **Dispatch & BL** : ce qui est parti, avec qui, et ce que le client a accepté et signé.

## La barre d'outils

1. **Nouvelle Commande** : le formulaire de saisie.
2. **Facturation** (icône facture) : le module voisin, où l'on facture les BL signés. Le badge orange compte les BL qui attendent leur facture.
3. **Stock & Emballages** (icône entrepôt) : pour vérifier ce que l'on peut livrer.
4. **Période** : la plage de dates que lisent les indicateurs *et* la table.
5. **Aide** : cet article.

## Le trajet d'une commande

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Saisie | Nouvelle Commande | La commande passe **En Attente**, au prix du client. Rien ne bouge en stock. |
| 2. Dispatch | Générer BL | Un **bon de livraison** est créé, les bouteilles sortent du stock, l'écriture de sortie est passée. |
| 3. Clôture | Clôturer | On enregistre ce que le client a **réellement accepté** et les vides rendus. |
| 4. Signature | Signature Client | Le client signe le BL par QR code ou lien WhatsApp. |
| 5. Facturation | module Facturation | Les BL signés deviennent une facture. |

> [!note] Cliquer n'importe quelle ligne ouvre son détail complet : les lignes commandées face à ce qui a été livré, les vides à récupérer, les BL, les factures et les règlements.

> [!tip] Un bouton absent est presque toujours une question de droits, pas une panne : chacun ne voit que ce que son rôle permet (Paramètres, Rôles & Permissions)."
 WHERE a.slug = 'demarrer-ventes-et-dispatch' AND b.lang = 'fr';

-- ventes-comprendre-la-table: the transport column ------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(b.body_md,
       "**Acheminement** indique le canal — Flotte LPC, Fournisseur ou Enlèvement — et qui a transporté.",
       "**Acheminement** indique le canal (Flotte LPC, Fournisseur ou Enlèvement) et qui a transporté : pour la Flotte LPC, le chauffeur et l'immatriculation du véhicule.

**Statut Logistique** : *En Transit* (parti), *Clôturé · à signer* (le bureau a enregistré la livraison), *Signé par le client* (le BL est validé et facturable), *Annulé*."),
       "| **Statut** | En Attente → En Transit → Livré. Annulé apparaît en rouge et la ligne est grisée. |",
       "| **Statut** | En Attente, En Transit, puis Livré quand le client a signé. Annulé apparaît en rouge et la ligne est grisée. |")
 WHERE a.slug = 'ventes-comprendre-la-table' AND b.lang = 'fr';

-- ventes-prix-client-et-remises: no table names; a higher price is a majoration -
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(b.body_md,
       "- **Cochée** → nouveau prix du client. `client_prices` est mis à jour, le changement est tracé dans l'historique du client, et **aucune remise n'est enregistrée**.",
       "- **Cochée** : nouveau prix du client. Son tarif est mis à jour, le changement est tracé dans l'historique de ses prix, et **aucune remise n'est enregistrée**."),
       "- **Décochée** → remise ponctuelle. Le tarif est laissé intact, l'écart est enregistré comme une réduction **déclarée** sur cette ligne, et un motif est exigé.",
       "- **Décochée** : remise ponctuelle (prix sous le tarif) ou majoration ponctuelle (prix au-dessus). Le tarif est laissé intact, l'écart est enregistré comme **déclaré** sur cette ligne, et un motif est exigé."),
       "Une remise dépassant le plafond configuré (15 % par défaut) exige la permission `sales.orders.discount_approve`.",
       "Une remise dépassant le plafond configuré (15 % par défaut) exige le droit d'approuver les remises (la Direction).")
 WHERE a.slug = 'ventes-prix-client-et-remises' AND b.lang = 'fr';

-- ventes-confirmer-les-prix -----------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(REPLACE(b.body_md,
       "| C'est le nouveau prix du client | C'est une remise ponctuelle |",
       "| C'est le nouveau prix du client | C'est une remise (ou une majoration) ponctuelle |"),
       "| `client_prices` est mis à jour | Le tarif reste inchangé |",
       "| Son tarif est mis à jour | Le tarif reste inchangé |"),
       "Si au moins une ligne reste décochée — donc devient une remise — un **motif** est obligatoire avant validation.",
       "Si au moins une ligne reste décochée (donc devient une remise ou une majoration), un **motif** est obligatoire avant validation."),
       "> [!warning] Un prix **supérieur** au tarif ne peut pas être laissé décoché : une « remise négative » n'a pas de sens et fausserait tous les cumuls. Soit vous confirmez le nouveau prix, soit vous ramenez la ligne au tarif.",
       "> [!note] Un prix **supérieur** au tarif laissé décoché est une **majoration ponctuelle** : elle compte dans *Majorations Perçues*, jamais en remise négative.")
 WHERE a.slug = 'ventes-confirmer-les-prix' AND b.lang = 'fr';

-- ventes-creer-une-commande -------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = REPLACE(REPLACE(REPLACE(REPLACE(b.body_md,
       "**Ajouter Produit** ajoute une ligne. Le sélecteur de produit est cherchable et affiche le SKU, le stock disponible, le prix et la consigne éventuelle.",
       "**Ajouter Produit** ajoute une ligne. Le sélecteur de produit est cherchable et affiche le stock disponible et le prix."),
       "- **Tarif** — le prix actuel du client, en lecture seule. La mention *négocié* ou *catalogue* indique d'où il vient.",
       "- **Tarif** : le prix actuel du client, en lecture seule. *Négocié* : le prix de son contrat (un devis signé le pose); *catalogue* : le prix par défaut."),
       "- **Prix Unitaire** — modifiable. S'il s'écarte du tarif, une confirmation sera demandée à l'enregistrement.",
       "- **Prix Unitaire** : modifiable. S'il s'écarte du tarif, une confirmation sera demandée à l'enregistrement."),
       "**Remise exceptionnelle** s'applique à la commande entière et exige un motif dès qu'elle est non nulle — le champ apparaît automatiquement.",
       "**Remise exceptionnelle** s'applique à la commande entière et exige un motif dès qu'elle est non nulle : le champ apparaît automatiquement. Une remise ligne n'est comptée qu'une fois : le prix vendu la porte déjà, et seule la remise exceptionnelle s'y ajoute à la livraison comme sur la facture.

Une commande prise pour un client qui a signé un devis porte ce devis : le détail affiche « Devis signé », ses conditions de paiement et son retour minimum des vides.")
 WHERE a.slug = 'ventes-creer-une-commande' AND b.lang = 'fr';

-- ventes-generer-un-bl ------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Les trois modes d'acheminement, le camion et le chauffeur de LPC, et ce que le dispatch déclenche.",
       b.body_md = "**Générer BL** transforme une commande en attente en bon de livraison.

## Les trois modes d'acheminement

| Mode | Qui transporte | Ce qui est exigé |
|---|---|---|
| **Flotte LPC** | Un véhicule de LPC et un membre du personnel au volant | Le véhicule (en service) et le chauffeur |
| **Livraison Fournisseur** | Le fournisseur, directement chez le client | Le fournisseur livreur |
| **Enlèvement Magasin** | Le client, avec son véhicule | Rien |

> [!note] LPC n'a pas de comptes chauffeur : le chauffeur se choisit dans le **personnel actif** (Gestion du Personnel), les chauffeurs en tête. Le véhicule se choisit parmi ceux **en service** dans Flotte & Maintenance; l'affectation du jour, si elle existe, le pré-remplit.

> [!warning] Le mode d'acheminement, le chauffeur et le fournisseur livreur sont **internes**. Ils n'apparaissent pas sur le bon de livraison remis au client, qui indique ce qui a été livré et à qui.

## Ce que le dispatch déclenche

En une seule opération :

1. Un BL est créé avec un lien de signature unique.
2. Les bouteilles **sortent du stock**, au coût moyen du jour (CUMP).
3. L'**écriture de sortie** est passée en comptabilité (coût des ventes contre stock).
4. Les vides attendus du client passent **en circulation**.
5. La commande passe **En Transit**.

Si le stock ne suffit pas sur une ligne, l'opération entière est refusée avec le détail du manque : rien n'est écrit à moitié. Une livraison mensuelle plus grande que le stock se fait en plusieurs commandes.

Le BL imprimable s'ouvre dans un nouvel onglet."
 WHERE a.slug = 'ventes-generer-un-bl' AND b.lang = 'fr';

-- ventes-cloturer-une-livraison ---------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Enregistrer ce que le client a accepté et les vides rendus, puis faire signer le BL.",
       b.body_md = "Au retour de la livraison, le Responsable des Opérations appuie sur **Clôturer** sur la ligne du BL, quel que soit le mode d'acheminement : il enregistre ce qui s'est réellement passé chez le client.

## Ce que l'on saisit

| Champ | Ce qu'il enregistre |
|---|---|
| **Qté acceptée** | Ce que le client a réellement pris, jamais plus que l'expédié. Pré-rempli à la quantité expédiée. |
| **Vides rendus** | Les bouteilles vides que le client a remises à la livraison. |
| **Montant collecté** | L'argent encaissé à la livraison. Laissez 0 quand le client paie plus tard, par virement à l'échéance. |

## La signature du client

La clôture ouvre la fenêtre **Faire signer le client** : un QR code à faire scanner, ou le lien à envoyer par WhatsApp. Le client vérifie les quantités sur son téléphone, les corrige au besoin, et signe. Le BL passe **Signé par le client**.

C'est la signature qui compte :

- les bouteilles refusées reviennent en stock, à leur coût de sortie;
- les vides dus par le client sont comptés dans Gestion des Vides;
- la commande passe **Livré**, à son montant accepté;
- le Responsable des Opérations et la Facturation sont prévenus;
- le BL devient facturable dans *Facturation, À facturer*.

## Écarts

Une quantité acceptée inférieure à la quantité expédiée crée un **écart**, visible dans l'indicateur *Rejets / Avaries* et dans le détail du BL."
 WHERE a.slug = 'ventes-cloturer-une-livraison' AND b.lang = 'fr';

-- ventes-annuler-ou-supprimer -----------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.body_md = "Ce sont **deux choses distinctes**, et le choix dépend de l'état de la commande.

| | Supprimer | Annuler |
|---|---|---|
| **Quand** | Avant tout BL | Avant ou après le BL, tant que le client ne l'a pas signé |
| **Effet** | La commande disparaît | La commande reste, grisée |
| **Trace** | Aucune | Qui, quand, pourquoi |
| **Indicateurs** | Rien à exclure | Exclue de tous |

## L'annulation exige un motif

Sans motif, l'opération est refusée. Le motif s'affiche ensuite en bandeau rouge sur le détail de la commande, avec l'auteur et la date.

## Une commande déjà partie

Si le BL n'est pas encore signé par le client, l'annuler le reprend aussi :

- les bouteilles reviennent en stock, au coût auquel elles étaient sorties;
- leur valeur revient au compte de stock (écriture inverse de la sortie);
- les vides en circulation du client sont libérés;
- le BL passe **Annulé**, avec le motif.

> [!warning] Un BL **signé** par le client ne s'annule pas ici : la livraison a eu lieu. Pour la reprendre, la Facturation établit un avoir."
 WHERE a.slug = 'ventes-annuler-ou-supprimer' AND b.lang = 'fr';

-- The AI assistant's copy ---------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('demarrer-ventes-et-dispatch', 'ventes-comprendre-la-table', 'ventes-prix-client-et-remises',
                  'ventes-confirmer-les-prix', 'ventes-creer-une-commande', 'ventes-generer-un-bl',
                  'ventes-cloturer-une-livraison', 'ventes-annuler-ou-supprimer')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('demarrer-ventes-et-dispatch', 'ventes-comprendre-la-table', 'ventes-prix-client-et-remises',
                  'ventes-confirmer-les-prix', 'ventes-creer-une-commande', 'ventes-generer-un-bl',
                  'ventes-cloturer-une-livraison', 'ventes-annuler-ou-supprimer')
   AND c.chunk_index = 0;

COMMIT;
