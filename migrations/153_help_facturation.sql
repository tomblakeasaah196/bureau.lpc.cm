-- =============================================================================
-- 153 · Help Centre: Facturation & AR (VIDEO_QA_FINDINGS §8, 18/11/2026)
-- -----------------------------------------------------------------------------
-- help_article_bodies   Découvrir Facturation, Générer une facture, Enregistrer
--                       un encaissement, AIR retenu à la source, Lire la Balance
--                       Âgée: each client's own 411, the AIR booked once (at the
--                       invoice), the preview's « Net à virer », Net 15, the
--                       « Factures en retard » list, no drivers' cash, no table
--                       names; « Imputer un avoir » becomes « Utiliser un
--                       trop-perçu » (the tab is « Trop Perçus »).
-- help_articles         new: « Émettre un avoir sur une facture » (8.11).
--                       « Valider la caisse chauffeur » unpublished (LPC has no
--                       drivers' cash rounds).
-- help_article_chunks   the AI assistant's copy: chunk 0 takes the new body.
--
-- Idempotent: bodies are set whole; INSERT IGNORE.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Les quatre onglets et le trajet d'une créance, de la facture au paiement.",
       b.body_md = "**Facturation & AR** suit l'argent que les clients doivent à LPC : de la facture, émise sur les bons de livraison signés, jusqu'au paiement reçu en banque, en caisse ou en Mobile Money.

## Les quatre onglets

- **Balance Âgée** : ce que les clients doivent, par ancienneté, les débiteurs principaux et les **factures en retard** à relancer.
- **BL Non Facturés** : les bons de livraison signés par le client, qui attendent leur facture.
- **Factures & Caisse** : les factures émises, leurs paiements et leurs avoirs.
- **Trop Perçus** : ce qu'un client a payé en trop ou d'avance, à utiliser sur une facture.

## Le trajet d'une créance

| Étape | Où | Ce qui se passe |
|---|---|---|
| 1. Facture | BL Non Facturés | Les BL choisis deviennent une facture. L'écriture passe seule : débit du **compte du client** (411…), crédit des ventes. |
| 2. Suivi | Factures & Caisse, Balance Âgée | La facture est **impayée** jusqu'à son échéance (Net 15 pour PROMETAL), puis **en retard**. |
| 3. Avoir (si besoin) | Factures & Caisse → **Avoir** | Une réduction accordée après la facture : le client doit moins. |
| 4. Paiement | Encaisser | Le compte qui reçoit l'argent est crédité, le compte du client soldé. |
| 5. Attestation | Déclarations Fiscales → Retenues à la source | Le client qui retient l'AIR envoie son attestation. |

Aucune écriture de vente ou d'encaissement ne se saisit à la main : chaque étape passe la sienne."
 WHERE a.slug = 'demarrer-facturation' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Choisir les BL signés, vérifier l'échéance, la TVA et l'AIR, émettre.",
       b.body_md = "Une facture se fait **toujours** depuis des bons de livraison signés par le client, jamais à blanc : on facture ce qui a été livré et accepté.

## Les étapes

1. **BL Non Facturés** : cliquez un ou plusieurs BL du **même client**.
2. **Créer la Facture** ouvre la fenêtre :
   - la **date d'émission** (aujourd'hui) et l'**échéance**, calculée sur les conditions du client (Net 15 pour PROMETAL : 15 jours) ;
   - le **taux de TVA** : 0 % pour l'eau, exonérée ;
   - les **comptes de paiement** imprimés sur la facture ; le premier coché est celui où le paiement est attendu.
3. L'**aperçu** montre le total. Pour un client qui retient l'**AIR** (PROMETAL, 2,2 %), il montre aussi la retenue et le **Net à virer par le client**.
4. **Valider & Générer PDF** émet la facture.

## L'écriture

Elle passe seule, au compte du client :

| Compte | Débit | Crédit |
|---|---|---|
| 411… le compte du client | ce qu'il virera | |
| 4424 AIR retenu (à récupérer) | l'AIR, s'il en retient | |
| 70… Ventes | | le hors taxe |
| 4431 TVA facturée | | la TVA, s'il y en a |

Une **remise sur la commande** est déduite avant le hors taxe ; la facture la montre sous « Total des lignes »."
 WHERE a.slug = 'facturation-generer-une-facture' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Le client paie : la facture, le montant reçu, le compte qui reçoit, l'AIR retenu.",
       b.body_md = "Sur la facture (Factures & Caisse), le bouton **+** ouvre **Encaisser**, ou **Nouvel Encaissement** en haut de l'onglet.

## Les champs

| Champ | Rôle |
|---|---|
| **Compte client** | Le client qui paie ; ses factures ouvertes se chargent. |
| **Imputation facture** | La facture réglée, avec son reste dû. Vide : l'argent va en trop-perçu. |
| **Montant** | Ce qui est **réellement reçu**. Pré-rempli : le reste dû moins l'AIR que le client retient. |
| **Méthode** | Espèces, virement bancaire ou Mobile Money. |
| **Compte de destination** | La caisse, la banque ou le Mobile Money qui reçoit l'argent. |
| **AIR retenu** | Pour un client qui retient l'AIR : pré-rempli avec l'AIR de la facture. |

## L'écriture

Débit du compte qui reçoit l'argent, crédit du **compte du client**, du montant reçu. L'AIR, lui, a été passé en 4424 dès la facture : il n'est pas compté une seconde fois. Le compte du client finit à zéro quand la facture est réglée.

Un montant au-delà du reste dû va en **Trop Perçus**.

## Ensuite

Le client qui a retenu l'AIR envoie son **attestation** : enregistrez-la dans Déclarations Fiscales → Retenues à la source."
 WHERE a.slug = 'facturation-enregistrer-un-encaissement' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Ce que fait l'ERP quand un client retient l'AIR (PROMETAL, 2,2 %).",
       b.body_md = "L'**AIR** (Acompte sur l'Impôt sur le Revenu) est retenu par certains clients, qui le versent aux impôts pour le compte de LPC (art. 149 CGI). PROMETAL retient 2,2 % du hors taxe.

## La fiche du client

Base Clients & Devis → fiche du client : **agent de retenue** coché et son **taux**.

## À la facture

L'aperçu montre l'AIR et le **Net à virer par le client**. L'écriture le sépare dès l'émission : le compte du client pour ce qu'il virera, **4424 AIR retenu (à récupérer)** pour la retenue.

## Au paiement

Le client vire le net. L'encaissement pré-remplit l'AIR ; il n'est pas passé une seconde fois. Si le client retient moins que prévu, la différence quitte 4424 ; plus que prévu, le supplément y entre.

## L'attestation

Le client remet une **attestation de retenue**. Déclarations Fiscales → **Retenues à la source** : le client, le numéro, la date, le mois, le montant et le fichier. Elle justifie l'AIR que LPC déduira de ses impôts ; « Demander (PDF) » prépare la lettre qui la réclame."
 WHERE a.slug = 'facturation-air-retenu-a-la-source' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.summary = "Les cartes, l'ancienneté, les débiteurs principaux et les factures en retard.",
       b.body_md = "Toutes les cartes comptent l'**AIR retenu** et les **avoirs** : un client ne doit que ce qu'il doit vraiment.

## Les cartes

| Carte | Ce qu'elle compte |
|---|---|
| **Total créances** | Ce que les clients doivent encore, factures non soldées. |
| **Impayés > 30 jours** | La part dont l'échéance est dépassée. |
| **Dette client (avoirs)** | Les trop-perçus en attente d'utilisation (compte 419). |
| **Caisse à valider** | Des encaissements en espèces pas encore comptés. |

## L'ancienneté

En cours (échéance à venir), 1 à 30 jours, 31 à 60 jours, 90 jours et plus. Le graphique lit ces cases.

## Factures en retard

La liste à suivre : chaque facture dont l'échéance est passée, son retard en jours et son reste dû. **Relancer** ouvre la facture, à envoyer au client par Email ou WhatsApp.

> [!note] Le total des créances égale le solde des comptes clients (411…) au Grand Livre."
 WHERE a.slug = 'facturation-lire-la-balance-agee' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title = "Utiliser un trop-perçu",
       b.summary = "Ce qu'un client a payé en trop ou d'avance, appliqué à une facture ouverte.",
       b.body_md = "Un **trop-perçu** naît quand un client paie plus que sa facture, ou verse une avance sans facture. Il est dans l'onglet **Trop Perçus** (compte 419, avances reçues).

Il ne se déduit jamais tout seul : le Comptable choisit la facture.

## Utiliser

**Trop Perçus** → ligne du client → **Utiliser** : la facture ouverte, le montant (au plus le trop-perçu et le reste dû), valider. L'écriture passe seule : débit 419, crédit du compte du client. Aucun argent ne bouge.

Ne pas confondre avec un **avoir** : une réduction accordée sur une facture (voir « Émettre un avoir sur une facture »)."
 WHERE a.slug = 'facturation-imputer-un-avoir' AND b.lang = 'fr';

UPDATE help_articles SET is_published = 0 WHERE slug = 'facturation-valider-la-caisse-chauffeur';

INSERT IGNORE INTO help_articles (category_id, slug, required_permission, kind, page_path, sort_order, is_published, created_at, updated_at)
SELECT a.category_id, 'facturation-emettre-un-avoir', 'accounting.invoices.create', 'article',
       '/modules/accounting/invoices.php', 45, 1, NOW(), NOW()
  FROM help_articles a WHERE a.slug = 'demarrer-facturation';

INSERT IGNORE INTO help_article_bodies (article_id, lang, title, summary, keywords, body_md, updated_at)
SELECT a.id, 'fr', "Émettre un avoir sur une facture",
       "Une réduction accordée après la facture : des bouteilles abîmées, une erreur de prix.",
       "avoir, réduction, retour, bouteilles percées, 7019, crédit",
       "Un **avoir** réduit une facture déjà émise : des bonbonnes percées constatées après la livraison, un prix facturé trop haut. La facture ne change pas ; l'avoir dit ce que le client doit en moins.

## Émettre

Factures & Caisse → la facture → **Avoir** :

1. le **montant hors taxe** de la réduction (4 bonbonnes à 2 275 F : 9 100 F) ;
2. le **motif**, qui figure sur l'écriture.

La fenêtre montre la TVA, ce que le client doit en moins, et l'AIR qu'il ne retiendra pas sur cette part. **Émettre l'avoir**.

## Ce qui change

- Le **reste dû** baisse ; la facture et son PDF montrent la ligne **Avoirs**.
- L'encaissement suivant pré-remplit le nouveau net et la nouvelle retenue.
- L'écriture passe seule : débit **7019 RRR accordés** (et 4431 pour la TVA), crédit du **compte du client** et de **4424** pour l'AIR qui ne sera pas retenu.

Un avoir ne dépasse pas ce que la facture doit encore : une facture déjà réglée se rembourse, par un trop-perçu.", NOW()
  FROM help_articles a WHERE a.slug = 'facturation-emettre-un-avoir';

INSERT IGNORE INTO help_article_anchors (anchor_key, article_id, sort_order)
SELECT 'accounting.invoices', a.id, 45 FROM help_articles a WHERE a.slug = 'facturation-emettre-un-avoir';

-- The AI assistant's copy
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('demarrer-facturation', 'facturation-generer-une-facture', 'facturation-enregistrer-un-encaissement',
                  'facturation-air-retenu-a-la-source', 'facturation-lire-la-balance-agee', 'facturation-imputer-un-avoir')
   AND c.lang = 'fr' AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('demarrer-facturation', 'facturation-generer-une-facture', 'facturation-enregistrer-un-encaissement',
                  'facturation-air-retenu-a-la-source', 'facturation-lire-la-balance-agee', 'facturation-imputer-un-avoir')
   AND c.lang = 'fr' AND c.chunk_index = 0;

COMMIT;
