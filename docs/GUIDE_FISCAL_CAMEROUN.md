# Guide Fiscal & Comptable — Bureau LPC ERP (Cameroun)

Ce guide décrit le fonctionnement du module fiscal, les rares actions manuelles
qui restent nécessaires, et comment le système gère automatiquement le reste.

Objectif : **plus besoin d'un expert‑comptable externe pour les opérations
mensuelles courantes**. Toutes les écritures naissent des événements de gestion
(facture, paiement, salaire) ; il ne reste que la validation humaine et le
téléversement des attestations reçues.

---

## 1. Ce qui est automatique — vous n'y touchez pas

| Événement | Écriture générée automatiquement |
|---|---|
| Facture émise | Dr 411 Clients (net_payable) · Dr 4424 AIR retenu (si client agent de retenue) · Cr 701 Ventes · Cr 4432 TVA collectée |
| Paiement encaissé | Dr 521/571 (cash reçu) · Dr 4424 (AIR retenu à la source) · Cr 411 Clients (total facturé) |
| Salaire calculé | Dr 661/662/664 · Cr 431/432/433/447/422 (net à payer) |
| Immobilisation amortie | Dr 681 · Cr 28x |
| Année clôturée | Toute écriture datée dans un exercice « closed » est refusée par la base (trigger) |

Aucune écriture n'est jamais déséquilibrée : la base refuse au niveau `SIGNAL
45000` toute ligne où débit ≠ crédit.

---

## 2. Régimes fiscaux — AIR 2.2 % vs 5.5 %

Onglet **Paramètres** du module *Déclarations Fiscales* :

| Régime | Chiffre d'affaires annuel | Taux AIR |
|---|---|---|
| Réel | ≥ 50 000 000 FCFA | **2,2 %** du CA HT mensuel |
| Simplifié | 10 000 000 – 49 999 999 FCFA | **5,5 %** du CA HT mensuel |
| Non‑professionnel / forfait | < 10 M | régimes spéciaux |

Le taux appliqué à chacune de vos ventes est celui **de votre entreprise**
(paramètre `company_tax_settings.air_rate`). Le taux `withholding_air_rate` sur
la fiche client est celui que **le client** vous prélève à la source, s'il est
agent de retenue.

---

## 3. Clients qui retiennent à la source (Prometal, SONARA, etc.)

### Configuration client (fiche CRM)
- `is_withholding_agent` = ✅
- `withholding_air_rate` = 0,022 (ou 0,055 selon leur régime)

### Ce que le système fait alors
1. À l'émission de la facture, il calcule automatiquement :
   - `air_amount` = subtotal × 2,2 %
   - `net_payable` = total_amount − air_amount
2. La facture PDF affiche les deux montants distinctement.
3. Au paiement, le client vous verse `net_payable` et garde `air_amount`.

### Ce que VOUS devez faire (mensuellement, 1 seule action manuelle)
Onglet **Retenues à la source** → *Charger une attestation* :
- Sélectionnez le client
- N° d'attestation, date, période (année/mois), montant total retenu
- Joignez le PDF/photo de l'attestation

**Sans cette attestation la retenue n'est PAS créditée sur votre AIR mensuel**
(exigence LPF art. L.94). Le système garde l'écriture au 4424 en attente, et
elle apparaît dans la ligne informative *« AIR retenu sans attestation »*.

Dès l'attestation chargée, toutes les retenues correspondantes deviennent un
crédit qui vient s'imputer sur l'AIR brut du mois → **vous ne payez à la DGI
que le solde**, jamais deux fois.

---

## 4. Déclaration mensuelle — le workflow

Le 1er jour ouvré du mois :

1. Ouvrir *Déclarations Fiscales* → onglet **Échéances**
2. Cliquer sur chacune des lignes affichées (TVA, AIR, DIPE) :
   - Le système recalcule tout depuis les factures / paiements / paies du mois précédent
   - Cliquer **Enregistrer** → la déclaration passe en statut *ready*
3. Se connecter au portail DGI e‑bilan, saisir les montants exacts (visibles à
   l'écran) ; télécharger le récépissé
4. Retour sur ERP → **Marquer déclarée**, coller la référence DGI
5. Après paiement au Trésor → **Marquer payée**

**Deadline** : 15 du mois suivant. La colonne *Échéance* le rappelle.

### Ce qui se déclare quand

| Déclaration | Cadence | Deadline | Comptes source |
|---|---|---|---|
| TVA | mensuelle | 15 | invoices.tva_amount − supplier_invoices.tva_amount − crédit reporté |
| AIR (min. de perception / acompte IS) | mensuelle | 15 | invoices.subtotal × taux − attestations reçues |
| DIPE (IRPP + CAC + CFC + CRTV + TDL + CNPS) | mensuelle | 15 | payroll_runs agrégés |
| TSR | mensuelle | 15 | supplier_invoices étrangères × 3/10/15 % |
| Patente | annuelle | 28 févr. | CA HT de l'année N‑1 × 0,159/0,283/0,494 % clampé |
| DSF (IS annuel) | annuelle | 15 mars | résultat comptable × 33 % (ou 27,5 %) − acomptes AIR versés |

---

## 5. Paie — le seul point qui peut nécessiter une intervention

Le moteur de paie (`includes/classes/Payroll.php`) applique la formule CGI
art. 29‑30 exactement :

```
gross          = base + logement + transport + primes − absences
cnps_salarié   = min(gross, 750 000) × 4,2 %
post_cnps      = gross − cnps_salarié
frais_pro      = post_cnps × 30 %
abattement     = 500 000 / 12
base_IRPP      = post_cnps − frais_pro − abattement
IRPP           = barème progressif 10/15/25/35 %  → arrondi FCFA
CAC            = 10 % × IRPP
CFC salarié    = 1 % × gross
CFC patronal   = 1,5 % × gross
CRTV           = barème fixe sur brut
TDL            = 3 000 si gross > 100 000
```

### Vérifications semestrielles à faire (< 5 min)
- **Groupe CNPS AT** : par défaut Groupe A (1,75 %). Si votre activité est
  classée B (2,5 %) ou C (5 %), corriger `company_tax_settings.cnps_group`.
- **Barèmes** : la table `payroll_irpp_brackets` doit avoir une ligne par
  année ; sinon le code retombe sur les défauts baked‑in et journalise un
  warning. Vérifier chaque 1er janvier.

---

## 6. Ce qui reste manuel (par nature)

1. **Téléversement des attestations de retenue à la source** — pièce papier
   externe, il n'y a pas d'API DGI qui les fournit.
2. **Déclaration sur le portail DGI (e‑bilan)** — pas d'API officielle ;
   copier‑coller depuis l'écran ERP.
3. **Paiement au Trésor** — virement bancaire hors ERP ; puis cliquer
   *Marquer payée*.
4. **Clôture d'exercice** — 1 fois par an, l'admin passe le statut de l'année
   à `closed` (bloque toute post‑écriture antérieure).
5. **Correction d'erreur post‑période** — les écritures postées sont
   immuables ; on passe une **écriture de contre‑passation** puis la bonne
   (jamais de suppression).

---

## 7. En cas de doute — check‑list « les livres sont‑ils justes ? »

- `SELECT SUM(debit)-SUM(credit) FROM journal_lines;` → doit valoir 0
- `SELECT * FROM invoices WHERE net_payable + air_amount <> total_amount;` → doit être vide
- `SELECT id FROM invoices i WHERE NOT EXISTS (SELECT 1 FROM journal_entries WHERE reference = CONCAT('INV-', i.reference));` → doit être vide (toute facture a son écriture)
- `SELECT SUM(air_withheld_amount) FROM payments WHERE withholding_certificate_id IS NULL AND air_withheld_amount > 0;` → si > 0, il vous manque des attestations à téléverser

---

## 8. Références légales

- CGI Cameroun édition 2026 (Loi n°2025/012 du 17 décembre 2025)
- Art. 21 bis — Acompte AIR
- Art. 92‑93 — TVA collectée / déductible, crédit reportable
- Art. 149 — Retenue à la source par entités habilitées
- Art. 225 — Régimes d'imposition
- Art. 29/30 — Base imposable IRPP
- Arrêté DGI n°00001 du 5 janvier 2026 — liste des 838 entités habilitées à la retenue à la source
