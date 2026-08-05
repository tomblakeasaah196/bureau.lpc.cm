<?php
/**
 * includes/functions/tax_simulation.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — dynamic tax simulation for Cameroon (revised OHADA).
 *
 * Replaces the hardcoded 30% IS / 5.5% AIR block previously living inline in
 * financials_controller.php. Rates and régime are read from company_tax_settings
 * (migration 020), so the same code answers:
 *
 *   • Régime du Réel   → IS = MAX(profit × cit_rate,  turnover × air_rate)
 *                         (Article 23 CGI: Minimum de Perception = 2,2% du CA)
 *   • Régime Simplifié → IR libératoire = turnover × air_rate  (souvent 5,5%)
 *   • Régime Forfait   → forfait mensuel (échelonné, hors périmètre — retourne 0
 *                         mais expose le label + le CA pour affichage)
 *   • Non-professionnel→ Aucun IS/IR société — retourne 0 avec label explicite
 *
 * The AIR credit (`4471`) and the AIR withheld by clients (`4424`) are both
 * pulled so the frontend can subtract them from what is owed.
 *
 * Contract (returned array — always the same shape):
 *
 *   [
 *     'regime'              => 'reel' | 'simplifie' | 'forfait' | 'non_professionnel',
 *     'label'               => 'Régime Réel — IS 30% / MP 2,2%',
 *     'cit_rate'            => 0.30,
 *     'air_rate'            => 0.022,
 *     'turnover'            => 45000000.00,     // classe 7 (produits d'exploitation)
 *     'profit'              => 8000000.00,      // résultat comptable = -Σ classe 6 + Σ classe 7
 *     'is_on_profit'        => 2400000.00,      // profit * cit_rate (0 si perte)
 *     'is_minimum'          => 990000.00,       // turnover * air_rate
 *     'is_due'              => 2400000.00,      // whichever applies for this régime
 *     'air_paid'            => 800000.00,       // Σ debit-credit sur 4471% (acomptes versés)
 *     'air_withheld'        => 400000.00,       // Σ debit-credit sur 4424% (retenus par clients)
 *     'balance'             => 1200000.00,      // is_due - air_paid - air_withheld  (>0 reliquat, <0 crédit)
 *     'settings_id'         => 1,
 *   ]
 *
 * DEFENSIVE: if the settings row is missing (empty install), returns the
 * default régime réel with system-wide default rates AND label = "Régime par
 * défaut (paramètres non renseignés)" so the accountant sees to configure it.
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_TAX_SIM_LOADED')) { return; }
define('LPC_TAX_SIM_LOADED', true);

/**
 * @param PDO   $pdo
 * @param int   $year         fiscal year to simulate
 * @param array $resultat     rows from getFinancialAggregates()['resultat'] (may be empty)
 * @return array              tax_simulation envelope, see file header
 */
function build_tax_simulation(PDO $pdo, int $year, array $resultat = []): array
{
    // 1. Load settings (or defaults).
    $settings = null;
    try {
        $r = $pdo->query("SELECT * FROM company_tax_settings ORDER BY id ASC LIMIT 1")
                 ->fetch(PDO::FETCH_ASSOC);
        if ($r) $settings = $r;
    } catch (Throwable $e) {
        // Table may not exist on very early installs — fall through to defaults.
    }
    $defaults_note = null;
    if (!$settings) {
        $settings = [
            'id'         => null,
            'tax_regime' => 'reel',
            'cit_rate'   => 0.3300,
            'air_rate'   => 0.0220,
        ];
        $defaults_note = 'Régime par défaut (paramètres non renseignés)';
    }

    $regime   = (string)($settings['tax_regime'] ?? 'reel');
    $cit_rate = (float)($settings['cit_rate']   ?? 0.33);
    $air_rate = (float)($settings['air_rate']   ?? 0.022);

    // 2. Compute turnover (produits d'exploitation = classes 70 à 78)
    //    and profit (=  Σ produits  -  Σ charges).
    //    We compute directly from the aggregated `resultat` buckets when they
    //    carry a class hint; otherwise we re-query the ledger by class.
    $turnover = 0.0; $profit = 0.0;
    try {
        $stmt = $pdo->prepare(
            "SELECT SUBSTRING(ca.code, 1, 1) AS klass,
                    SUM(jl.debit)  AS deb,
                    SUM(jl.credit) AS cre
               FROM journal_lines jl
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
               JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'approved'
              WHERE YEAR(je.date) = :yr
                AND SUBSTRING(ca.code, 1, 1) IN ('6','7')
              GROUP BY SUBSTRING(ca.code, 1, 1)"
        );
        $stmt->execute(['yr' => $year]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $rev = 0.0; $exp = 0.0;
        foreach ($rows as $r) {
            $bal = (float)$r['cre'] - (float)$r['deb'];   // class 7 credit-normal
            if ($r['klass'] === '7') { $rev += $bal;         $turnover += $bal; }
            if ($r['klass'] === '6') { $exp += -$bal; }      // class 6 debit-normal → flip
        }
        $profit = $rev - $exp;
    } catch (Throwable $e) {
        // Leave zeros if the query fails — caller can still render.
    }

    // 3. Compute IS
    $is_on_profit = max(0.0, $profit * $cit_rate);
    $is_minimum   = max(0.0, $turnover * $air_rate);
    $is_due = 0.0;
    $label  = '';
    switch ($regime) {
        case 'reel':
            // Article 23 CGI: minimum de perception = 2,2% du chiffre d'affaires
            // hors taxes. On retient le plus grand des deux.
            $is_due = max($is_on_profit, $is_minimum);
            $label  = sprintf('Régime Réel — IS %s%% / MP %s%%',
                        _fmt_rate($cit_rate * 100), _fmt_rate($air_rate * 100));
            break;
        case 'simplifie':
            // Impôt libératoire assis sur le CA. Pas de MP additionnel.
            $is_due = $is_minimum;
            $label  = sprintf('Régime Simplifié — IR libératoire %s%% du CA',
                        _fmt_rate($air_rate * 100));
            break;
        case 'forfait':
            $is_due = 0.0;
            $label  = 'Régime du Forfait — hors périmètre du simulateur';
            break;
        case 'non_professionnel':
            $is_due = 0.0;
            $label  = 'Non-professionnel — pas d\'IS société';
            break;
        default:
            $is_due = $is_on_profit;
            $label  = 'Régime inconnu — calcul IS profit';
    }
    if ($defaults_note) $label = $defaults_note . ' · ' . $label;

    // 4. AIR paid (compte 4471) & AIR withheld by clients (compte 4424)
    $air_paid     = _sum_debit_credit($pdo, '4471', $year);
    $air_withheld = _sum_debit_credit($pdo, '4424', $year);

    $balance = $is_due - $air_paid - $air_withheld;

    return [
        'regime'        => $regime,
        'label'         => $label,
        'cit_rate'      => $cit_rate,
        'air_rate'      => $air_rate,
        'turnover'      => round($turnover, 2),
        'profit'        => round($profit, 2),
        'is_on_profit'  => round($is_on_profit, 2),
        'is_minimum'    => round($is_minimum, 2),
        'is_due'        => round($is_due, 2),
        'air_paid'      => round($air_paid, 2),
        'air_withheld'  => round($air_withheld, 2),
        'balance'       => round($balance, 2),
        'settings_id'   => $settings['id'] ?? null,
    ];
}

function _sum_debit_credit(PDO $pdo, string $prefix, int $year): float
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
               FROM journal_lines jl
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
               JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'approved'
              WHERE ca.code LIKE :pref AND YEAR(je.date) = :yr"
        );
        $stmt->execute(['pref' => $prefix . '%', 'yr' => $year]);
        return (float)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0.0;
    }
}

function _fmt_rate(float $pct): string
{
    // 30 → "30", 2.2 → "2,2", 5.5 → "5,5"
    $s = rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',');
    return $s === '' ? '0' : $s;
}
