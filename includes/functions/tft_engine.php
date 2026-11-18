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

/*
 * Comptabilité 3 (VIDEO_QA_FINDINGS 13, copy A, 18/11/2026). The first version
 * computed the result as products PLUS charges (class 7 − (−class 6)), took the
 * interest accounts 67 / 77 for asset disposals (SYSCOHADA puts disposals in
 * HAO: 81 / 82), counted a depreciation's credit on 28x as a disposal, split
 * receivables from debts by two-digit prefix rather than by account, and read
 * the year's opening entry (Bilan d'Ouverture or À-nouveaux) as a flow of the
 * year. Every figure is now a sum of the year's movements (D − C, the opening
 * entry left out) over a set of accounts, and the sets cover every account
 * outside class 5 exactly once, so the control reconciles to the franc:
 *
 *   CAFG        = résultat net + M(68, 69, 85, 78, 79, 86) + M(81, 82)
 *   ΔBFR        = M(class 3), class 4 split by each account's balance
 *   Investissement = −M(class 2) − (the same non-cash add-backs as CAFG)
 *   Financement = −M(class 1)
 *   Variation de trésorerie = Trésorerie fin − Trésorerie début (class 5)
 */
function build_tft(PDO $pdo, int $year): array
{
    require_once __DIR__ . '/books_period.php';
    $lines = $pdo->query("SELECT * FROM tft_lines ORDER BY display_order ASC")->fetchAll(PDO::FETCH_ASSOC);

    // The year's opening entry: its À-nouveaux, or the Bilan d'Ouverture.
    $open = [];
    $q = $pdo->prepare("SELECT id FROM journal_entries WHERE status = 'posted' AND reference = ? AND date = ?");
    $q->execute(["AN-$year", "$year-01-01"]);
    $anId = (int) $q->fetchColumn();
    if ($anId) $open[] = $anId;
    $q = $pdo->prepare("SELECT opening_balance_journal_id FROM financial_years WHERE year = ?");
    $q->execute([$year]);
    $ob = (int) $q->fetchColumn();
    if ($ob && !in_array($ob, $open, true)) $open[] = $ob;
    $notOpen = $open ? ' AND je.id NOT IN (' . implode(',', array_map('intval', $open)) . ')' : '';

    // Per-account movements of the year (opening entry excluded), opening and closing balances.
    [$cumEnd, $pEnd] = lpc_books_cumulative_where($pdo, $year);
    [$cumPrev, $pPrev] = lpc_books_cumulative_where($pdo, $year - 1);
    $openCase = $anId
        ? "je.id = $anId"
        : "(($cumPrev)" . ($ob ? " OR je.id = $ob" : '') . ")";
    $st = $pdo->prepare("
        SELECT ca.code,
               SUM(CASE WHEN YEAR(je.date) = ? $notOpen THEN jl.debit - jl.credit ELSE 0 END) AS mov,
               SUM(CASE WHEN YEAR(je.date) = ? $notOpen THEN jl.debit ELSE 0 END)             AS deb,
               SUM(CASE WHEN $openCase THEN jl.debit - jl.credit ELSE 0 END)                  AS bal_open,
               SUM(CASE WHEN $cumEnd THEN jl.debit - jl.credit ELSE 0 END)                    AS bal_end
          FROM journal_lines jl
          JOIN chart_of_accounts ca ON ca.id = jl.account_id
          JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
         GROUP BY ca.id, ca.code
    ");
    $st->execute(array_merge([$year, $year], $anId ? [] : $pPrev, $pEnd));
    $acc = $st->fetchAll(PDO::FETCH_ASSOC);

    $M = function (array $prefixes) use ($acc): float {
        $sum = 0.0;
        foreach ($acc as $a) {
            foreach ($prefixes as $p) {
                if (strpos((string) $a['code'], (string) $p) === 0) { $sum += (float) $a['mov']; break; }
            }
        }
        return $sum;
    };
    $bal = function (string $col, string $cls, int $sign) use ($acc): float {
        $sum = 0.0;
        foreach ($acc as $a) {
            if (substr((string) $a['code'], 0, 1) !== $cls) continue;
            $v = (float) $a[$col];
            if ($sign > 0) $sum += max(0.0, $v); elseif ($sign < 0) $sum += max(0.0, -$v); else $sum += $v;
        }
        return $sum;
    };

    $treso_open  = $bal('bal_open', '5', 0);
    $treso_close = $bal('bal_end',  '5', 0);

    $net      = -$M(['6', '7', '8']);
    $addbacks = $M(['68', '69', '85', '78', '79', '86']) + $M(['81', '82']);
    $cafg     = $net + $addbacks;

    $var_stocks   = $M(['3']);
    $var_creances = $bal('bal_end', '4',  1) - $bal('bal_open', '4',  1);
    $var_dettes   = $bal('bal_end', '4', -1) - $bal('bal_open', '4', -1);
    $var_hao      = 0.0;
    $flux_ops = $cafg - $var_stocks - $var_creances + $var_dettes - $var_hao;

    $acq_immo = 0.0;
    foreach ($acc as $a) {
        $c = (string) $a['code'];
        if ($c[0] === '2' && strpos($c, '28') !== 0 && strpos($c, '29') !== 0) $acq_immo += (float) $a['deb'];
    }
    $flux_inv = -$M(['2']) - $addbacks;
    $ces_immo = $flux_inv + $acq_immo;   // what came in from disposals, net

    $capital_in  = -min(0.0, $M(['10'])) ;
    $capital_out = max(0.0, $M(['10']));
    $loans_in    = -min(0.0, $M(['16', '17']));
    $loans_out   = max(0.0, $M(['16', '17']));
    $dividends   = 0.0;
    $flux_fin    = -$M(['1']);

    $var_treso   = $flux_ops + $flux_inv + $flux_fin;
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
    unset($l);

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
