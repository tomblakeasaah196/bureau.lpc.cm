-- =============================================================================
-- 159 · Help Centre: Tableaux de bord & Rapports Consolidés (VIDEO_QA_FINDINGS
--       §14, 18/11/2026)
-- -----------------------------------------------------------------------------
-- help_article_bodies   Tableau de Bord Exécutif, Vue Finance, Vue Opérations,
--                       Vue Ventes, Rapports Consolidés (fr and en): what each
--                       card counts and where the figure comes from, in the
--                       words of the screen it comes from. They quoted the code
--                       (table and column names, SQL, legacy account codes,
--                       README sections) and described what the code did wrong
--                       as the rule: cash from 52x and 57x only, invoices at
--                       their full amount, empties from drivers' cash returns,
--                       a stock threshold of 100, statuses that do not exist.
-- help_article_chunks   the AI assistant's copy: chunk 0 takes the new body.
--
-- Idempotent: bodies are set whole.
-- =============================================================================

SET NAMES utf8mb4;

START TRANSACTION;

-- Tableau de Bord Exécutif ------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Tableau de Bord Exécutif — Direction Générale",
       b.summary = "La page d'accueil du Directeur Général : le chiffre d'affaires, ce que les clients doivent, la marge, les vides récupérés et l'argent disponible.",
       b.body_md = "Le **Tableau de Bord Exécutif** est la page d'accueil du Directeur Général. Cinq cartes et deux graphiques disent comment se porte LPC sur la période choisie ; chaque chiffre vient de l'écran qui le tient, et chaque carte s'ouvre sur son détail.

## Les cinq cartes

| Carte | Ce qu'elle compte | Elle ouvre |
| --- | --- | --- |
| **Chiffre d'affaires** | Les ventes passées en comptabilité sur la période (eau, vides vendus aux recycleurs), moins les avoirs. Comparé à l'objectif « Chiffre d'affaires » de Budgets & Performance quand il existe. | Les écritures de ventes de la période |
| **Créances clients (AR)** | Ce que les factures ouvertes **doivent encore** : le TTC moins les paiements reçus, l'AIR retenu par le client et les avoirs. Une facture réglée n'y est plus, même si son AIR a été retenu. | Les factures ouvertes avec leur reste dû |
| **Marge nette** | (Ventes − charges) ÷ ventes sur la période. L'écart avec la période précédente se lit en **points**. | Les comptes de produits et de charges de la période |
| **Récupération des vides** | Les bouteilles rendues sur les bouteilles livrées, d'après Gestion des Vides. La pastille compare au **minimum convenu** avec chaque client (80 % pour PROMETAL) ; la ligne du dessous dit combien de vides il reste à récupérer. | Le détail par client : livrées, rendues, minimum, à récupérer |
| **Cash disponible** | Le solde comptable de **chaque compte de trésorerie** (banque, caisse, Mobile Money) à la fin de la période : les mêmes soldes que Trésorerie & Banque. | Le solde de chaque compte |

## La période

En haut : Aujourd'hui, 7 jours, Ce mois, Trimestre, YTD (depuis le 1er janvier, le choix par défaut) ou Personnalisé. Les dates sont celles du Cameroun : « Aujourd'hui » compte les ventes du jour. Chaque fenêtre de détail reprend la période choisie.

Les créances, les vides et le cash sont des **positions** (ce qui est dû, rendu ou disponible à ce jour) : la période ne les change pas, sauf le cash, lu à la fin de la période.

## Les deux graphiques

- **Performance vs Budget** : les ventes de chaque mois, et l'objectif mensuel quand un objectif annuel est saisi.
- **Vieillissement des créances** : le reste dû des factures, selon le retard sur l'échéance (à jour ou jusqu'à 15 jours, 16 à 30 jours, plus de 30 jours).

Une carte qui ne peut pas être calculée affiche « Indisponible » plutôt qu'un chiffre faux ; les autres restent justes.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-executif' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Executive Dashboard — Managing Director",
       b.summary = "The Managing Director's home page: revenue, what clients owe, the margin, empties recovered and the cash available.",
       b.body_md = "The **Executive Dashboard** is the Managing Director's home page. Five cards and two charts show how LPC is doing over the chosen period; each figure comes from the screen that holds it, and each card opens its detail.

## The five cards

| Card | What it counts | It opens |
| --- | --- | --- |
| **Revenue** | Sales posted to the books over the period (water, empties sold to recyclers), less credit notes. Compared with the « Chiffre d'affaires » target in Budgets & Performance when there is one. | The period's sales entries |
| **Receivables (AR)** | What open invoices **still owe**: the amount incl. tax less payments received, the AIR withheld by the client and credit notes. | Open invoices with what each still owes |
| **Net margin** | (Sales − expenses) ÷ sales over the period; the change is read in **points**. | The period's income and expense accounts |
| **Empties recovered** | Bottles returned over bottles delivered, from Gestion des Vides, against the **minimum agreed** with each client (80 % for PROMETAL); the line below says how many are still to recover. | Each client: delivered, returned, minimum, to recover |
| **Cash available** | The book balance of **every treasury account** (bank, cash box, Mobile Money) at the end of the period: the same balances as Trésorerie & Banque. | Each account's balance |

## The period

Today, 7 days, This month, Quarter, YTD (the default) or Custom, in Cameroon's dates. Each detail window uses the period chosen. Receivables, empties and cash are **positions**: the period does not change them, except cash, read at the period's end.

## The two charts

- **Performance vs Budget**: each month's sales, and the monthly target when a yearly one is set.
- **Receivables ageing**: what invoices still owe, by days past due (up to 15, 16 to 30, over 30).

A card that cannot be computed shows « Indisponible » instead of a wrong figure.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-executif' AND b.lang = 'en';

-- Vue Finance ---------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Vue Finance — Direction Financière",
       b.summary = "L'argent disponible, ce que les clients doivent, ce que LPC doit à ses fournisseurs, ce qui attend Finance, et chaque compte de trésorerie.",
       b.body_md = "La **Vue Finance** est la page d'accueil du Contrôleur Financier et du Comptable ; le Directeur Général l'ouvre aussi. Elle s'ouvre sur **le mois en cours**, le rythme de la clôture.

## Les cinq cartes

| Carte | Ce qu'elle compte | Elle ouvre |
| --- | --- | --- |
| **Trésorerie actuelle** | Le solde comptable de chaque compte de trésorerie (banque, caisse, Mobile Money) à la fin de la période, comparé à la fin de la période précédente. | Les entrées et sorties d'argent de la période, compte par compte |
| **Créances clients (AR)** | Ce que les factures ouvertes doivent encore (TTC moins paiements, AIR retenu et avoirs), et combien de factures sont ouvertes. | Les factures ouvertes et leur reste dû |
| **Dettes fournisseurs (AP)** | Ce que LPC doit à ses fournisseurs : le solde du compte de chaque fournisseur (401) à la fin de la période. | Le solde de chaque fournisseur |
| **Validations en attente** | Ce qui attend Finance : les écritures restées en brouillon, les dépenses à payer, les demandes de correction d'un mouvement de trésorerie. | La liste, par type |
| **Marge nette (période)** | (Ventes − charges) ÷ ventes sur la période choisie. | Les comptes de produits et de charges |

## Sous les cartes

- **Flux de trésorerie** : chaque jour, l'argent entré et sorti des comptes de trésorerie.
- **Âge des créances** : le reste dû par retard sur l'échéance.
- **Comptes de trésorerie** : chaque compte, son sous-compte (5211, 5711, 5521…) et son solde comptable à la fin de la période. Les mêmes chiffres que Trésorerie & Banque.

## La période

Aujourd'hui, 7 jours, Ce mois (par défaut), Trimestre, YTD ou Personnalisé, aux dates du Cameroun. Les fenêtres de détail reprennent la période choisie.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-finance' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Finance View — Finance Department",
       b.summary = "Cash available, what clients owe, what LPC owes its suppliers, what waits on Finance, and each treasury account.",
       b.body_md = "The **Finance View** is the Financial Controller's and the Accountant's home page; the Managing Director opens it too. It opens on **the current month**, the closing rhythm.

## The five cards

| Card | What it counts | It opens |
| --- | --- | --- |
| **Cash** | The book balance of each treasury account (bank, cash box, Mobile Money) at the period's end, against the previous period's end. | Money in and out over the period |
| **Receivables (AR)** | What open invoices still owe (amount less payments, AIR withheld and credit notes). | Open invoices and what each still owes |
| **Payables (AP)** | What LPC owes its suppliers: each supplier account (401) at the period's end. | Each supplier's balance |
| **Waiting** | Entries left in draft, expenses to pay, correction requests on a treasury movement. | The list, by type |
| **Net margin (period)** | (Sales − expenses) ÷ sales over the period. | Income and expense accounts |

## Below the cards

Cash flow by day, receivables by days past due, and **Treasury accounts**: each account, its sub-account (5211, 5711, 5521…) and its book balance, as in Trésorerie & Banque. Detail windows use the period chosen.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-finance' AND b.lang = 'en';

-- Vue Opérations --------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Vue Opérations — Direction des Opérations",
       b.summary = "Les BL à livrer, les produits au Seuil Min., les vides à récupérer, le camion et les livraisons du jour.",
       b.body_md = "La **Vue Opérations** est la page d'accueil du Responsable des Opérations ; le Directeur Général l'ouvre aussi. Elle s'ouvre sur **aujourd'hui** : c'est l'écran du dispatch.

## Les cinq cartes

| Carte | Ce qu'elle compte | Elle ouvre |
| --- | --- | --- |
| **Livraisons BL en attente** | Les bons de livraison encore en brouillon ou en transit, quelle que soit leur date. | Chaque BL, son client, son chauffeur et son statut |
| **Alertes stock** | Les produits dont le stock est au **Seuil Min.** (Données de Base) ou en dessous ; la ligne du dessous dit combien sont en rupture. Le stock est compté comme dans Stock & Emballages. | Chaque produit, son stock, son Seuil Min. et son état |
| **Récupération des vides** | Les bouteilles rendues sur les bouteilles livrées, contre le **minimum convenu** avec chaque client, et les vides qui restent à récupérer (Gestion des Vides). | Le détail par client |
| **État de la flotte** | Les véhicules en service, et ceux hors service. | Chaque véhicule, son dernier chauffeur et sa dernière livraison |
| **Livraisons complétées aujourd'hui** | Les BL clôturés par LPC ou signés par le client aujourd'hui, comparés à hier. | Les BL du jour et leur heure |

Le chauffeur d'un BL est l'employé choisi sur le bon (les chauffeurs de LPC n'ont pas de compte).

## Sous les cartes

- **Volume de livraisons** : chaque jour, les BL clôturés et ceux encore à livrer.
- **Stock entrepôt** : l'eau en 20L, l'eau en 10L et les emballages vides.
- **File d'attente dispatch** : les dix derniers BL, avec leurs quantités, leur statut (Brouillon, En transit, Clôturé · à signer, Signé par le client) et leur chauffeur. « Créer un BL » ouvre Ventes.

## La période

Elle change le graphique des livraisons ; les cartes disent l'état présent.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-operations' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Operations View — Operations Department",
       b.summary = "Delivery notes to deliver, products at their minimum stock, empties to recover, the truck and today's deliveries.",
       b.body_md = "The **Operations View** is the Operations Manager's home page; the Managing Director opens it too. It opens on **today**: it is the dispatch screen.

## The five cards

| Card | What it counts | It opens |
| --- | --- | --- |
| **Delivery notes waiting** | Delivery notes still in draft or in transit, whatever their date. | Each note, client, driver and status |
| **Stock alerts** | Products at or under their **Seuil Min.** (Données de Base), and how many are out of stock, counted as in Stock & Emballages. | Each product, stock, minimum and state |
| **Empties recovered** | Bottles returned over bottles delivered, against each client's **agreed minimum**, and what is still to recover. | Each client |
| **Fleet** | Vehicles in service and out of service. | Each vehicle, its last driver and delivery |
| **Deliveries closed today** | Notes closed by LPC or signed by the client today, against yesterday. | Today's notes |

A note's driver is the employee chosen on it (LPC's drivers have no login). Below: deliveries per day, stock by family, and the ten latest notes with their status and driver.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-operations' AND b.lang = 'en';

-- Vue Ventes -------------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Vue Ventes — Performance commerciale",
       b.summary = "Les ventes du mois : chiffre d'affaires, volumes 20L et 10L, commandes à expédier, factures échues. Toutes les ventes pour la Direction et Finance, les siennes pour un commercial.",
       b.body_md = "La **Vue Ventes** suit les ventes d'un mois : le Directeur Général et le Comptable l'ouvrent, et ce sera la page d'accueil des commerciaux.

## Qui voit quoi

- Le **Directeur Général** et **Finance** voient **toutes les ventes** ; « Mes ventes », dans la liste en haut, réduit aux commandes qu'ils ont saisies.
- Un **commercial** voit les commandes qu'il a saisies et les clients à qui il a vendu.

## Les cinq cartes

| Carte | Ce qu'elle compte | Elle ouvre |
| --- | --- | --- |
| **CA du mois** | Le montant des commandes du mois (hors commandes annulées), et l'objectif quand il est saisi. | Chaque commande |
| **Volume 20L** et **Volume 10L** | Les bouteilles commandées dans le mois, par taille. | Le détail par client |
| **En attente de dispatch** | Les commandes pas encore (ou pas entièrement) expédiées, de n'importe quel mois : la plus ancienne est la plus urgente. | Les commandes à expédier |
| **Encours en retard** | Ce que les factures échues doivent encore. | Les factures échues |

## Les objectifs

Sans objectif pour le mois, une bannière le dit et les cartes montrent le réalisé sans pourcentage : un 0 % ressemblerait à un échec.

## En bas

- **Top 10 clients du mois**, par chiffre d'affaires.
- **Clients dormants** : sans commande depuis 30 jours ou plus ; un clic ouvre la fiche du client.

Le mois et l'année se choisissent en haut.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-ventes' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Sales View — Sales performance",
       b.summary = "The month's sales: revenue, 20L and 10L volumes, orders to dispatch, overdue invoices. Every sale for Management and Finance, their own for a salesperson.",
       b.body_md = "The **Sales View** follows one month's sales. The Managing Director and Finance see **every sale** (« Mes ventes » narrows to the orders they typed); a salesperson sees the orders they entered.

| Card | What it counts |
| --- | --- |
| **Revenue this month** | The month's orders (cancelled ones excluded), against the target when one is set. |
| **20L / 10L volume** | Bottles ordered in the month, by size. |
| **Waiting for dispatch** | Orders not yet (fully) dispatched, from any month. |
| **Overdue** | What overdue invoices still owe. |

Without a target, a banner says so and the cards show what was achieved without a percentage. Below: the month's top 10 clients and the dormant ones (no order for 30 days).",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-ventes' AND b.lang = 'en';

-- Rapports Consolidés --------------------------------------------------------------------
UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Rapports Consolidés — Vue Dirigeant",
       b.summary = "Huit indicateurs pour la Direction et Finance, trois graphiques, les charges du budget et l'export PDF.",
       b.body_md = "**Rapports Consolidés** rassemble huit indicateurs pour le Directeur Général, le Contrôleur Financier et le Comptable, sur l'année en cours (YTD) ou le mois en cours (MTD).

## Les huit cartes

| Carte | Ce qu'elle compte |
| --- | --- |
| **Chiffre d'affaires** | Les ventes comptabilisées (comptes de classe 7) sur la période, comparées au mois précédent. |
| **Ratio masse salariale** | Les charges de personnel sur le chiffre d'affaires. L'écart avec le mois précédent se lit en points. |
| **Coût moyen / livraison** | Le carburant et l'entretien des véhicules, divisés par le nombre de livraisons. |
| **Risque emballages non rendus** | Les vides qu'il reste à récupérer sous le **minimum convenu** de chaque client, au prix du vide (Données de Base). Les bouteilles que le client a le droit de garder n'y sont pas. |
| **Marge brute** | Les ventes de marchandises moins ce que ces marchandises ont coûté (achats, variation du stock, remises obtenues) ; les vides vendus aux recycleurs n'ont pas de coût d'achat. La ligne du dessous donne la marge de chaque famille. |
| **ROI flotte** | Le chiffre d'affaires divisé par le coût du transport. Sans coût de transport, la carte le dit. |
| **Balance âgée > 60 j** | La part de ce que les clients doivent encore qui est échue depuis plus de 60 jours. |
| **Écart budgétaire global** | Les charges de l'année contre les objectifs de charges de Budgets & Performance. Sans objectif saisi : « Aucun budget actif ». |

Chaque carte s'ouvre sur son détail, pour la période choisie en haut.

## Les graphiques et le tableau

- **Coût transport vs chiffre d'affaires**, mois par mois.
- **Répartition trésorerie** : caisse, banques et Mobile Money, d'après le solde comptable de chaque compte de trésorerie.
- **Balance âgée** : le reste dû par retard sur l'échéance (0–30, 31–60, 61–90, plus de 90 jours).
- **Exécution budgétaire** : les trois lignes de charges qui ont le plus coûté, contre leur objectif ; « Sans objectif » quand il n'y en a pas.

## Export PDF

« Exporter PDF » enregistre la page en une feuille A4 paysage, marquée confidentielle avec la date.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-rapports-consolides' AND b.lang = 'fr';

UPDATE help_article_bodies b JOIN help_articles a ON a.id = b.article_id
   SET b.title   = "Consolidated Reports — Board View",
       b.summary = "Eight indicators for Management and Finance, three charts, the budget's charges and the PDF export.",
       b.body_md = "**Consolidated Reports** gathers eight indicators for the Managing Director, the Financial Controller and the Accountant, year to date (YTD) or month to date (MTD).

| Card | What it counts |
| --- | --- |
| **Revenue** | Sales posted (class 7), against the previous month. |
| **Payroll ratio** | Staff costs over revenue; the change is in points. |
| **Cost per delivery** | Fuel and vehicle upkeep over the number of deliveries. |
| **Unreturned empties** | Empties still to recover under each client's **agreed minimum**, at the empty's price. |
| **Gross margin** | Goods sold less what they cost (purchases, stock change, rebates obtained); empties sold to recyclers have no purchase cost. |
| **Fleet ROI** | Revenue over transport cost. |
| **Ageing > 60 days** | The share of what clients still owe that is over 60 days past due. |
| **Budget variance** | The year's charges against the charge targets of Budgets & Performance. |

Each card opens its detail for the period chosen. Charts: transport cost vs revenue, treasury split (cash box, banks, Mobile Money, from each treasury account's book balance), receivables by days past due; the table shows the three costliest charge lines against their target. « Exporter PDF » saves the page as one landscape A4 page.",
       b.updated_at = NOW()
 WHERE a.slug = 'dashboard-rapports-consolides' AND b.lang = 'en';

-- The AI assistant's copy ------------------------------------------------------------
DELETE c FROM help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
 WHERE a.slug IN ('dashboard-executif', 'dashboard-finance', 'dashboard-operations', 'dashboard-ventes', 'dashboard-rapports-consolides')
   AND c.chunk_index > 0;

UPDATE help_article_chunks c
  JOIN help_articles a ON a.id = c.article_id
  JOIN help_article_bodies b ON b.article_id = a.id AND b.lang = c.lang
   SET c.chunk_text = CONCAT(b.title, '\n\n', b.body_md)
 WHERE a.slug IN ('dashboard-executif', 'dashboard-finance', 'dashboard-operations', 'dashboard-ventes', 'dashboard-rapports-consolides')
   AND c.chunk_index = 0;

COMMIT;
