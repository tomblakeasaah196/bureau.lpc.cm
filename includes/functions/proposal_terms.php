<?php
/**
 * includes/functions/proposal_terms.php
 * -----------------------------------------------------------------------------
 * What a signed proposition commerciale leaves behind (migration 137, the
 * training video QA of 18/11/2026, docs/VIDEO_QA_FINDINGS.md §4.1 and 3.6).
 *
 * Before this, a client's « Bon pour accord » moved the proposal to
 * 'accepted' and nothing else: the client stayed a prospect, the prices it
 * signed never reached Ventes, Net 15 and the 80 % empties minimum were text
 * on a PDF. Now acceptance:
 *
 *   · makes every signed price the client's tariff (client_prices, logged in
 *     client_price_history with source 'proposal' and the devis reference):
 *     Ventes & Commandes then proposes it on every order;
 *   · carries the terms onto the client: payment_terms_days (Facturation
 *     dates the invoice from it), empties_return_min_pct (Gestion des Vides
 *     reads it), terms_proposal_id (the devis the terms come from, which every
 *     later order links to through sales_orders.proposal_id);
 *   · makes a prospect an active client (its 411 account exists since it was
 *     created);
 *   · it does NOT touch the product's « Seuil Min. » (the owner's answer 20 on
 *     #16, 18/11/2026): the buffer a devis promises stays in the devis;
 *     the threshold is set by hand in Données de Base.
 *
 * The parsers are pure functions: scripts/tests/proposal_flow.test.php runs them.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/pricing.php';

/** Weeks in a month, for turning a monthly quantity into weeks of stock. */
const LPC_WEEKS_PER_MONTH = 52 / 12;

/**
 * Days to pay, from the proposal's « Conditions de paiement ».
 * « Net 15 Jours » → 15, « Net 30 Jours » → 30, « Paiement à la Livraison » → 0.
 * Null when the text says nothing a person could count on.
 */
function lpc_proposal_terms_days(?string $terms): ?int
{
    $t = mb_strtolower(trim((string) $terms));
    if ($t === '') return null;
    if (preg_match('/\b(?:net|à|a)\s*(\d{1,3})\s*(?:j|jours|days)?\b/u', $t, $m)) return (int) $m[1];
    if (preg_match('/^(\d{1,3})\s*(?:j|jours|days)\b/u', $t, $m)) return (int) $m[1];
    if (str_contains($t, 'livraison') || str_contains($t, 'delivery') || str_contains($t, 'comptant')) return 0;
    return null;
}

/**
 * The minimum share of delivered bottles the client returns, from the
 * proposal's « Politique des emballages »: « Retour Minimum Exigé (80%) » → 80,
 * « Échange 1-pour-1 (100%) » → 100. Null when no percentage is stated.
 */
function lpc_proposal_empties_pct(?string $policy): ?int
{
    if (preg_match('/(\d{1,3})\s*%/u', (string) $policy, $m)) {
        $pct = (int) $m[1];
        return ($pct >= 0 && $pct <= 100) ? $pct : null;
    }
    return null;
}

/**
 * Article 1 of the proposal, the sentence that depends on the chosen policy.
 * The rest of the article is the template's (Studio Proposition).
 */
function lpc_proposal_empties_clause(?string $policy, string $lang = 'fr'): string
{
    $pct = lpc_proposal_empties_pct($policy);
    if ($lang === 'en') {
        if ($pct === null) return 'Empty bottles are collected by LA PETITE COUR at each delivery.';
        if ($pct >= 100)   return 'The client returns one empty bottle for each full bottle delivered (1 for 1), collected at each delivery.';
        return "The client undertakes to return at least {$pct} % of the bottles delivered, collected by LA PETITE COUR at each delivery.";
    }
    if ($pct === null) return 'Les bonbonnes vides sont reprises par LA PETITE COUR à chaque livraison.';
    if ($pct >= 100)   return 'Le client restitue une bonbonne vide pour chaque bonbonne pleine livrée (1 pour 1), reprise à chaque livraison.';
    return "Le client s'engage à restituer au minimum {$pct} % des bonbonnes livrées, reprises par LA PETITE COUR à chaque livraison.";
}

/**
 * Bottles of stock the signed contracts promise to hold for a product:
 * Σ (monthly quantity × buffer weeks ÷ weeks per month) over each active
 * client's current terms (clients.terms_proposal_id). Rounded up.
 */
function lpc_proposal_committed_buffer(PDO $db, int $productId): int
{
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(pi.quantity * GREATEST(COALESCE(p.buffer_stock_weeks, 0), 0)), 0)
          FROM clients c
          JOIN proposals p       ON p.id = c.terms_proposal_id AND p.status = 'accepted'
          JOIN proposal_items pi ON pi.proposal_id = p.id AND pi.deleted_at IS NULL
         WHERE c.deleted_at IS NULL AND c.status = 'active' AND pi.product_id = ?
    ");
    $stmt->execute([$productId]);
    $qtyWeeks = (float) $stmt->fetchColumn();
    return (int) ceil($qtyWeeks / LPC_WEEKS_PER_MONTH - 1e-9);
}

/**
 * Apply a client's acceptance. Assumes an open transaction on $db (the
 * signature row and everything it causes commit together). Idempotent: a
 * second call for the same proposal changes nothing.
 *
 * @return array{client_id:int|null, prices:int, terms_days:int|null, empties_pct:int|null, activated:bool, thresholds:array<int,int>}
 */
function lpc_proposal_accept(PDO $db, int $proposalId): array
{
    $out = ['client_id' => null, 'prices' => 0, 'terms_days' => null, 'empties_pct' => null, 'activated' => false, 'thresholds' => []];

    $p = $db->prepare("
        SELECT id, reference, client_id, client_name, payment_terms, empties_policy,
               buffer_stock_weeks, created_by
          FROM proposals WHERE id = ?
    ");
    $p->execute([$proposalId]);
    $prop = $p->fetch(PDO::FETCH_ASSOC);
    if (!$prop) return $out;

    // The client: by id (migration 137), else by the name snapshot older
    // proposals carry. One live client only; none means nothing to carry.
    $clientId = (int) ($prop['client_id'] ?? 0);
    if ($clientId <= 0) {
        $c = $db->prepare("SELECT id FROM clients WHERE name = ? AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
        $c->execute([$prop['client_name']]);
        $clientId = (int) ($c->fetchColumn() ?: 0);
        if ($clientId > 0) {
            $db->prepare("UPDATE proposals SET client_id = ? WHERE id = ? AND client_id IS NULL")
               ->execute([$clientId, $proposalId]);
        }
    }
    if ($clientId <= 0) return $out;
    $out['client_id'] = $clientId;

    $c = $db->prepare("SELECT id, status FROM clients WHERE id = ? FOR UPDATE");
    $c->execute([$clientId]);
    $client = $c->fetch(PDO::FETCH_ASSOC);
    if (!$client) return $out;

    // Who the price change is logged against: the commercial who wrote the
    // proposal. The signature itself is the client's, with no LPC session.
    $by = (int) ($prop['created_by'] ?? 0);
    if ($by <= 0) {
        $by = (int) ($db->query("
            SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.name IN ('admin', 'directeur_general') AND u.status = 'active'
             ORDER BY u.id ASC LIMIT 1
        ")->fetchColumn() ?: 0);
    }

    // 1. The signed prices become the client's tariff.
    $items = $db->prepare("
        SELECT product_id, unit_price FROM proposal_items
         WHERE proposal_id = ? AND product_id IS NOT NULL AND deleted_at IS NULL
         ORDER BY id ASC
    ");
    $items->execute([$proposalId]);
    $productIds = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $it) {
        $pid = (int) $it['product_id'];
        $productIds[$pid] = true;
        if ($by > 0 && lpc_apply_client_price_change(
            $db, $clientId, $pid, (float) $it['unit_price'], $by,
            'proposal', $prop['reference'], 'Prix signé au devis ' . $prop['reference']
        )) {
            $out['prices']++;
        }
    }

    // 2. The terms, and the devis they come from.
    $out['terms_days']  = lpc_proposal_terms_days($prop['payment_terms']);
    $out['empties_pct'] = lpc_proposal_empties_pct($prop['empties_policy']);
    $out['activated']   = ($client['status'] === 'prospect');
    $db->prepare("
        UPDATE clients
           SET payment_terms_days     = COALESCE(?, payment_terms_days),
               empties_return_min_pct = COALESCE(?, empties_return_min_pct),
               terms_proposal_id      = ?,
               status                 = IF(status = 'prospect', 'active', status)
         WHERE id = ?
    ")->execute([$out['terms_days'], $out['empties_pct'], $proposalId, $clientId]);

    // « Seuil Min. » is not touched: the owner decided a devis must not raise
    // it (answer 20 on register #16). lpc_proposal_committed_buffer() still
    // says what the signed contracts promise, for whoever wants to read it.

    return $out;
}
