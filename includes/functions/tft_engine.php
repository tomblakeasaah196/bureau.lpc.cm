<?php
/**
 * includes/functions/tft_engine.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — SYSCOHADA Tableau de Flux de Trésorerie (TFT) engine.
 *
 * Method: INDIRECT (SYSCOHADA révisé, Article 30). Starts from the net result
 * and unwinds non-cash adjustments to build CAFG, then applies BFR variations
 * for operating flows, then walks class 1/2 movements for investing/financing.
 *
 * Anchors:
 *   CAFG              = Résultat net + Dotations - Reprises - Plus-values + Moins-values
 *   Flux Opérationnel = CAFG - ΔBFR
 *   Flux Investissement = -Δimmo brut (class 2) + cessions
 *   Flux Financement  = Δcapital + Δemprunts - dividendes - remboursements
 *   Variation trésorerie N = Trésorerie N (class 5) - Trésorerie N-1
 *   Contrôle: Flux Ops + Flux Inv + Flux Fin == Var. Trésorerie   (à 1 FCFA près)
 *
 * The categorisation table (migration 089) tags every chart_of_accounts row
 * with an activity so a `class 4` receivable movement (BFR) doesn't accidentally
 * count as an investment. Any accountant override can be done from the settings
 * page (UPDATE chart_of_accounts SET tft_category = ... WHERE code = ...).
 * -----------------------------------------------------------------------------
 */

if (defined('LPC_TFT_LOADED')) { return; }
define('LPC_TFT_LOADED', true);

function build_tft(PDO $pdo, int $year): array
{
    // 1. Load canonical rubric list
    $lines = $pdo->query("SELECT * FROM tft_lines ORDER BY display_order ASC")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];

    // 2. Trésorerie N-1 (opening) and N (closing) — sum of class 5 balances
    $treso_open  = _tft_class_balance($pdo, '5', $year - 1);
    $treso_close = _tft_class_balance($pdo, '5', $year);

    // 3. Net result (class 7 - class 6, period)
    $net = _tft_period_diff($pdo, ['7'], $year) - _tft_period_diff($pdo, ['6'], $year);

    // 4. CAFG = Net + Dotations amortissements/provisions - Reprises - Plus-value cession + Moins-value cession
    //    Dotations: comptes 68 (débit)  ;  Reprises: comptes 78 (crédit)
    //    Plus-values: 77 (résultat sur cession) ; Moins-values: 67 (idem, débit).
    $dotations   = _tft_period_debit($pdo, '68', $year);
    $reprises    = _tft_period_credit($pdo, '78', $year);
    $val_cess    = _tft_period_credit($pdo, '77', $year);   // Produits de cession
    $vnc_cess    = _tft_period_debit($pdo, '67', $year);    // Valeur nette comptable des cessions
    $cafg = $net + $dotations - $reprises - $val_cess + $vnc_cess;

    // 5. BFR variations (year-end N - year-end N-1)
    //    Stocks (class 3), Créances (class 4* débitrices), Dettes (class 4* créditrices)
    $var_stocks   = _tft_bilan_delta($pdo, ['31','32','33','34','35','36','37','38'], $year);
    $var_creances = _tft_bilan_delta($pdo, ['40','41','42','43','44','45','46','47','48'], $year, 'debit');
    $var_dettes   = _tft_bilan_delta($pdo, ['40','41','42','43','44','45','46','47','48'], $year, 'credit');
    $var_hao      = 0.0; // stub — HAO variation reserved for future refinement

    $flux_ops = $cafg - $var_stocks - $var_creances + $var_dettes - $var_hao;

    // 6. Investing (class 2 movements: acquisitions - cessions cash)
    $acq_immo = _tft_period_debit($pdo, '2', $year);
    $ces_immo = _tft_period_credit($pdo, '2', $year);
    $flux_inv = -$acq_immo + $ces_immo;

    // 7. Financing (class 1: capital, emprunts, dividendes)
    $capital_in  = _tft_period_credit($pdo, '10', $year);
    $capital_out = _tft_period_debit($pdo,  '10', $year);
    $dividends   = _tft_period_debit($pdo,  '465', $year); // dividendes à payer débit = versement
    $loans_in    = _tft_period_credit($pdo, '16', $year);
    $loans_out   = _tft_period_debit($pdo,  '16', $year);
    $flux_fin = $capital_in - $capital_out - $dividends + $loans_in - $loans_out;

    $var_treso = $flux_ops + $flux_inv + $flux_fin;
    $treso_check = $treso_close - $treso_open;

    $values = [
        'treso_open'    => $treso_open,
        'cafg'          => $cafg,
        'var_hao'       => $var_hao,
        'var_stocks'    => $var_stocks,
        'var_creances'  => $var_creances,
        'var_dettes'    => $var_dettes,
        'flux_ops'      => $flux_ops,
        'acq_immo'      => $acq_immo,
        'ces_immo'      => $ces_immo,
        'flux_inv'      => $flux_inv,
        'capital_in'    => $capital_in,
        'capital_out'   => $capital_out,
        'dividends'     => $dividends,
        'loans_in'      => $loans_in,
        'loans_out'     => $loans_out,
        'flux_fin'      => $flux_fin,
        'var_treso'     => $var_treso,
        'treso_close'   => $treso_close,
        'treso_check'   => $treso_check,
    ];

    foreach ($lines as &$l) {
        $l['value'] = round((float)($values[$l['formula']] ?? 0), 2);
    }

    return [
        'year'        => $year,
        'lines'       => $lines,
        'net_result'  => round($net, 2),
        'cafg'        => round($cafg, 2),
        // The control diagnostic. |var_treso - treso_check| must be < 1 FCFA.
        'reconciled'  => abs($var_treso - $treso_check) < 1.0,
        'delta'       => round($var_treso - $treso_check, 2),
    ];
}

// -------- helpers --------

function _tft_class_balance(PDO $pdo, string $prefix, int $year): float
{
    // Cumulative balance (bilan-style).
    // NOTE: uses ? positional placeholders — with PDO::ATTR_EMULATE_PREPARES=false
    // (production default), MySQL refuses a named placeholder used more than once.
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.debit - jl.credit),0)
           FROM journal_lines jl
           JOIN chart_of_accounts ca ON ca.id = jl.account_id
           JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status='posted'
          WHERE SUBSTRING(ca.code,1,LENGTH(?)) = ?
            AND YEAR(je.date) <= ?"
    );
    $st->execute([$prefix, $prefix, $year]);
    return (float)$st->fetchColumn();
}

function _tft_period_diff(PDO $pdo, array $prefixes, int $year): float
{
    $sum = 0.0;
    foreach ($prefixes as $p) {
        $sum += _tft_period_credit($pdo, $p, $year) - _tft_period_debit($pdo, $p, $year);
    }
    return $sum;
}

function _tft_period_debit(PDO $pdo, string $prefix, int $year): float
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.debit),0)
           FROM journal_lines jl
           JOIN chart_of_accounts ca ON ca.id = jl.account_id
           JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status='posted'
          WHERE ca.code LIKE :pref AND YEAR(je.date) = :yr"
    );
    $st->execute(['pref' => $prefix . '%', 'yr' => $year]);
    return (float)$st->fetchColumn();
}
function _tft_period_credit(PDO $pdo, string $prefix, int $year): float
{
    $st = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.credit),0)
           FROM journal_lines jl
           JOIN chart_of_accounts ca ON ca.id = jl.account_id
           JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status='posted'
          WHERE ca.code LIKE :pref AND YEAR(je.date) = :yr"
    );
    $st->execute(['pref' => $prefix . '%', 'yr' => $year]);
    return (float)$st->fetchColumn();
}

/**
 * Bilan-delta: closing balance year N minus year N-1, filtered by sign.
 *   $side = 'both'   → net balance
 *          'debit'   → only positive (asset-side) balances
 *          'credit'  → only negative (liability-side) balances, returned positive
 */
function _tft_bilan_delta(PDO $pdo, array $prefixes, int $year, string $side = 'both'): float
{
    $delta = 0.0;
    foreach ($prefixes as $p) {
        $bN   = _tft_class_balance($pdo, $p, $year);
        $bN_1 = _tft_class_balance($pdo, $p, $year - 1);
        if ($side === 'debit')  { $bN = max(0, $bN);  $bN_1 = max(0, $bN_1); }
        if ($side === 'credit') { $bN = max(0, -$bN); $bN_1 = max(0, -$bN_1); }
        $delta += ($bN - $bN_1);
    }
    return round($delta, 2);
}
