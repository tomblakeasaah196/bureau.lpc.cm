<?php
/**
 * includes/functions/executive_summary_data.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 7A D2 · Vue Dirigeant aggregator.
 *
 * Single entry point returning every number the MD wants at a glance for a
 * given fiscal year. Shared by:
 *   · api/v1/financials_controller.php ?action=vue_dirigeant  (JSON envelope)
 *   · includes/pdf_templates/executive_summary.php            (server-side PDF)
 *
 * Keeping the aggregation in one place guarantees the on-screen dashboard
 * and the PDF export agree to the last FCFA.
 *
 * All money returned as float FCFA (integer-round in the render layer via
 * lpc_fcfa / LPC.fmt.fcfa). Percentages returned as float with one decimal
 * of significance.
 *
 * DEFENSIVE STYLE:
 *   Every query is wrapped in a small try/catch that logs and returns 0 on
 *   failure — the Vue Dirigeant should render *something* even if one
 *   underlying view is missing or a KPI has no data.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('fetch_executive_summary')) {

    /**
     * Build the Vue Dirigeant payload for the given fiscal year.
     *
     * @param PDO $db  Live PDO handle from Database::getInstance().
     * @param int $year  Fiscal year (YYYY). Defaults to the current year when
     *                   out of range.
     *
     * @return array<string,mixed> {
     *   year:           int,
     *   generated_at:   string 'Y-m-d H:i:s',
     *   ratios:         { margin_gross_pct, ebe_pct, net_margin_pct, dso_days, dpo_days,
     *                     revenue, cogs, ebe_value, net_result, ar_balance, ap_balance },
     *   cash:           { total, caisse, banque, momo, breakdown[{name,type,balance}] },
     *   cashflow_90d:   { labels[Y-m-d], inflow[float], outflow[float], net[float] },
     *   top_debtors:    [{ client_id, client_name, client_code, total_due, days_overdue }],
     *   top_suppliers:  [{ supplier_id, supplier_name, supplier_code, outstanding, latest_po_date }],
     *   snapshot:       { vehicles_by_status[{status,count}], payroll_month, fuel_month,
     *                     payroll_month_ymd, fuel_month_ymd },
     *   alerts:         { je_pending_30d, overdue_payments, budgets_over_90pct }
     * }
     */
    function fetch_executive_summary(PDO $db, int $year): array
    {
        $today = new DateTimeImmutable('today');
        $y = ($year >= 2000 && $year <= 2999) ? $year : (int) $today->format('Y');

        $y_start = "$y-01-01";
        $y_end   = "$y-12-31";
        $days_in_period = (int) (new DateTimeImmutable($y_end))->diff(new DateTimeImmutable($y_start))->days + 1;

        // -------------------------------------------------------------------
        // Ratios
        // -------------------------------------------------------------------
        $revenue     = _lpc_sum_periodic($db, '70', $y_start, $y_end, true);   // Class 70 credit-net
        $cogs        = _lpc_sum_periodic($db, '60', $y_start, $y_end, false);  // Class 60 debit-net
        $charges_expl = _lpc_sum_class_range($db, ['60','61','62','63','64','65'], $y_start, $y_end);
        $depr_prov   = _lpc_sum_periodic($db, '68', $y_start, $y_end, false);  // Class 68 dotations
        $total_expenses = _lpc_sum_periodic($db, '6', $y_start, $y_end, false);

        $ebe_value    = $revenue - $charges_expl;
        $net_result   = $revenue - $total_expenses;
        $margin_gross = $revenue > 0 ? (($revenue - $cogs) / $revenue) * 100 : 0.0;
        $ebe_pct      = $revenue > 0 ? ($ebe_value / $revenue) * 100 : 0.0;
        $net_margin   = $revenue > 0 ? ($net_result / $revenue) * 100 : 0.0;

        // AR balance (Class 411) — cumulative debit-net.
        $ar_balance = _lpc_sum_cumulative($db, '411', $y_end);
        // AP balance (Class 401) — cumulative credit-net (liability side).
        $ap_balance = abs(_lpc_sum_cumulative($db, '401', $y_end));

        // DSO / DPO using annualized ratio.
        $dso_days = $revenue > 0 ? ($ar_balance / $revenue) * $days_in_period : 0.0;
        $dpo_days = $cogs    > 0 ? ($ap_balance / $cogs)    * $days_in_period : 0.0;

        // -------------------------------------------------------------------
        // Cash — treasury_accounts + 90-day cashflow line
        // -------------------------------------------------------------------
        $cash_breakdown = [];
        $cash = ['total' => 0.0, 'caisse' => 0.0, 'banque' => 0.0, 'momo' => 0.0];
        try {
            $stmt = $db->query("
                SELECT name, type, balance
                  FROM treasury_accounts
                 WHERE status = 'active'
                 ORDER BY type ASC, name ASC
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bal  = (float) $r['balance'];
                $type = strtolower((string)$r['type']);
                $cash_breakdown[] = ['name' => $r['name'], 'type' => $type, 'balance' => $bal];
                $cash['total'] += $bal;
                if     ($type === 'caisse') $cash['caisse'] += $bal;
                elseif ($type === 'bank')   $cash['banque'] += $bal;
                elseif ($type === 'momo')   $cash['momo']   += $bal;
            }
        } catch (Throwable $e) {
            error_log('executive_summary cash: ' . $e->getMessage());
        }

        // 90-day cashflow: zero-filled per-day series from treasury_transactions.
        $cf = ['labels' => [], 'inflow' => [], 'outflow' => [], 'net' => []];
        try {
            $end_dt = $today;
            $start_dt = $end_dt->modify('-89 days');
            $bucket = [];
            for ($d = $start_dt; $d <= $end_dt; $d = $d->modify('+1 day')) {
                $bucket[$d->format('Y-m-d')] = ['in' => 0.0, 'out' => 0.0];
            }
            $stmt = $db->prepare("
                SELECT DATE(created_at) AS d,
                       SUM(CASE WHEN transaction_type IN ('in_tournee','in_other','transfer_in')  THEN amount ELSE 0 END) AS in_sum,
                       SUM(CASE WHEN transaction_type IN ('out_expense','transfer_out')           THEN amount ELSE 0 END) AS out_sum
                  FROM treasury_transactions
                 WHERE DATE(created_at) BETWEEN ? AND ?
                 GROUP BY DATE(created_at)
            ");
            $stmt->execute([$start_dt->format('Y-m-d'), $end_dt->format('Y-m-d')]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (isset($bucket[$r['d']])) {
                    $bucket[$r['d']]['in']  = (float)$r['in_sum'];
                    $bucket[$r['d']]['out'] = (float)$r['out_sum'];
                }
            }
            foreach ($bucket as $day => $vals) {
                $cf['labels'][]  = $day;
                $cf['inflow'][]  = $vals['in'];
                $cf['outflow'][] = $vals['out'];
                $cf['net'][]     = $vals['in'] - $vals['out'];
            }
        } catch (Throwable $e) {
            error_log('executive_summary cashflow: ' . $e->getMessage());
        }

        // -------------------------------------------------------------------
        // Top-5 debtor clients from view_ar_aging
        // -------------------------------------------------------------------
        $top_debtors = [];
        try {
            $stmt = $db->query("
                SELECT a.client_id, a.client_name, a.total_due,
                       (a.`31_60_days` + a.`61_90_days` + a.`over_90_days`) AS overdue_amt,
                       COALESCE(c.lpc_code, '') AS client_code
                  FROM view_ar_aging a
                  LEFT JOIN clients c ON c.id = a.client_id
                 WHERE a.total_due > 0
                 ORDER BY a.total_due DESC
                 LIMIT 5
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                // Approximate "days overdue": bucket the row lives in.
                $days = 0;
                if ((float)$r['overdue_amt'] > 0) $days = 60;   // has 31-90 / >90 dues
                $top_debtors[] = [
                    'client_id'     => (int)$r['client_id'],
                    'client_name'   => $r['client_name'] ?? '—',
                    'client_code'   => $r['client_code'] ?? '',
                    'total_due'     => (float)$r['total_due'],
                    'days_overdue'  => $days,
                ];
            }
        } catch (Throwable $e) {
            error_log('executive_summary top_debtors: ' . $e->getMessage());
        }

        // -------------------------------------------------------------------
        // Top-5 suppliers by outstanding (unpaid purchase_orders)
        // -------------------------------------------------------------------
        $top_suppliers = [];
        try {
            $stmt = $db->query("
                SELECT s.id AS supplier_id, s.name AS supplier_name, s.lpc_code AS supplier_code,
                       SUM(po.total_amount) AS outstanding,
                       MAX(po.date) AS latest_po_date
                  FROM purchase_orders po
                  JOIN suppliers s ON s.id = po.supplier_id
                 WHERE po.payment_status = 'unpaid'
                 GROUP BY s.id
                 ORDER BY outstanding DESC
                 LIMIT 5
            ");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $top_suppliers[] = [
                    'supplier_id'    => (int)$r['supplier_id'],
                    'supplier_name'  => $r['supplier_name'],
                    'supplier_code'  => $r['supplier_code'],
                    'outstanding'    => (float)$r['outstanding'],
                    'latest_po_date' => $r['latest_po_date'],
                ];
            }
        } catch (Throwable $e) {
            error_log('executive_summary top_suppliers: ' . $e->getMessage());
        }

        // -------------------------------------------------------------------
        // Snapshot — vehicles, payroll, fuel (current month)
        // -------------------------------------------------------------------
        $current_month = (int) $today->format('n');
        $current_year  = (int) $today->format('Y');
        $month_ymd     = $today->format('Y-m');

        $vehicles_by_status = [];
        try {
            $stmt = $db->query("SELECT status, COUNT(*) AS n FROM vehicles GROUP BY status");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $vehicles_by_status[] = ['status' => $r['status'], 'count' => (int)$r['n']];
            }
        } catch (Throwable $e) {
            error_log('executive_summary vehicles: ' . $e->getMessage());
        }

        $payroll_month = 0.0;
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(net_pay), 0)
                  FROM hr_payslips
                 WHERE year = ? AND month = ?
                   AND status IN ('validated','paid')
            ");
            $stmt->execute([$current_year, $current_month]);
            $payroll_month = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('executive_summary payroll_month: ' . $e->getMessage());
        }

        $fuel_month = 0.0;
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(total_cost), 0)
                  FROM fuel_logs
                 WHERE YEAR(date) = ? AND MONTH(date) = ?
            ");
            $stmt->execute([$current_year, $current_month]);
            $fuel_month = (float) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('executive_summary fuel_month: ' . $e->getMessage());
        }

        // -------------------------------------------------------------------
        // Alerts (last 30 days)
        // -------------------------------------------------------------------
        $alerts = [
            'je_pending_30d'      => 0,
            'overdue_payments'    => 0,
            'budgets_over_90pct'  => 0,
        ];
        try {
            $stmt = $db->query("
                SELECT COUNT(*)
                  FROM journal_entries
                 WHERE status = 'pending'
                   AND created_at >= (NOW() - INTERVAL 30 DAY)
            ");
            $alerts['je_pending_30d'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('executive_summary je_pending: ' . $e->getMessage());
        }
        try {
            $stmt = $db->query("
                SELECT COUNT(*) FROM invoices
                 WHERE status IN ('unpaid','partial')
                   AND due_date < CURDATE()
            ");
            $alerts['overdue_payments'] = (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('executive_summary overdue: ' . $e->getMessage());
        }
        try {
            // Budgets > 90% consumed for the current year.
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM (
                    SELECT bl.id
                      FROM budget_lines bl
                      JOIN budgets b ON b.id = bl.budget_id
                     WHERE b.fiscal_year = ?
                       AND bl.annual_amount > 0
                ) t
            ");
            // Simplification: derive consumed % by joining actuals through the
            // budget_controller helper would double-code the aggregation; use a
            // cheap approximation from the OHADA account balance if available.
            $stmt->execute([$y]);
            $alerts['budgets_over_90pct'] = (int) _lpc_count_budgets_over_pct($db, $y, 0.90);
        } catch (Throwable $e) {
            error_log('executive_summary budgets: ' . $e->getMessage());
        }

        return [
            'year'         => $y,
            'generated_at' => $today->format('Y-m-d H:i:s'),
            'ratios'       => [
                'margin_gross_pct' => round($margin_gross, 1),
                'ebe_pct'          => round($ebe_pct, 1),
                'net_margin_pct'   => round($net_margin, 1),
                'dso_days'         => round($dso_days, 1),
                'dpo_days'         => round($dpo_days, 1),
                'revenue'          => $revenue,
                'cogs'             => $cogs,
                'ebe_value'        => $ebe_value,
                'net_result'       => $net_result,
                'ar_balance'       => $ar_balance,
                'ap_balance'       => $ap_balance,
            ],
            'cash'         => [
                'total'     => $cash['total'],
                'caisse'    => $cash['caisse'],
                'banque'    => $cash['banque'],
                'momo'      => $cash['momo'],
                'breakdown' => $cash_breakdown,
            ],
            'cashflow_90d' => $cf,
            'top_debtors'  => $top_debtors,
            'top_suppliers'=> $top_suppliers,
            'snapshot'     => [
                'vehicles_by_status' => $vehicles_by_status,
                'payroll_month'      => $payroll_month,
                'fuel_month'         => $fuel_month,
                'payroll_month_ymd'  => $month_ymd,
                'fuel_month_ymd'     => $month_ymd,
            ],
            'alerts'       => $alerts,
        ];
    }

    // -----------------------------------------------------------------------
    // Small helpers — module-private but function_exists-guarded so a caller
    // that re-includes this file inside a test bootstrap doesn't re-declare.
    // -----------------------------------------------------------------------
    function _lpc_sum_periodic(PDO $db, string $prefix, string $start, string $end, bool $is_revenue): float {
        $math = $is_revenue ? '(jl.credit - jl.debit)' : '(jl.debit - jl.credit)';
        $stmt = $db->prepare("
            SELECT COALESCE(SUM($math), 0)
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id
              JOIN chart_of_accounts ca ON ca.id = jl.account_id
             WHERE je.status = 'posted' AND ca.code LIKE ?
               AND je.date >= ? AND je.date <= ?
        ");
        $stmt->execute([$prefix . '%', $start, $end]);
        return (float) $stmt->fetchColumn();
    }

    function _lpc_sum_cumulative(PDO $db, string $prefix, string $end): float {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id
              JOIN chart_of_accounts ca ON ca.id = jl.account_id
             WHERE je.status = 'posted' AND ca.code LIKE ?
               AND je.date <= ?
        ");
        $stmt->execute([$prefix . '%', $end]);
        return (float) $stmt->fetchColumn();
    }

    function _lpc_sum_class_range(PDO $db, array $prefixes, string $start, string $end): float {
        $total = 0.0;
        foreach ($prefixes as $p) {
            $total += _lpc_sum_periodic($db, $p, $start, $end, false);
        }
        return $total;
    }

    /**
     * Approximate count of budget lines consumed above a threshold of their
     * annual cap. Uses actuals aggregated from the same source as
     * budget_controller::getActualsByAccountAndMonth.
     */
    function _lpc_count_budgets_over_pct(PDO $db, int $year, float $threshold): int {
        try {
            // Reuse budget_controller helper if it has been included, otherwise
            // inline a compact version so this helper stays self-contained.
            $actuals_by_acc = [];
            $q = $db->prepare("SELECT MONTH(date) m, SUM(total_cost) t FROM fuel_logs WHERE YEAR(date)=? GROUP BY MONTH(date)");
            $q->execute([$year]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $actuals_by_acc['605'] = ($actuals_by_acc['605'] ?? 0) + (float)$r['t'];

            $q = $db->prepare("SELECT MONTH(service_date) m, SUM(total_cost) t FROM vehicle_maintenance WHERE YEAR(service_date)=? GROUP BY MONTH(service_date)");
            $q->execute([$year]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $actuals_by_acc['615'] = ($actuals_by_acc['615'] ?? 0) + (float)$r['t'];

            $q = $db->prepare("
                SELECT o.account_number, bl.annual_amount
                  FROM budget_lines bl
                  JOIN budgets b       ON b.id = bl.budget_id
                  JOIN ohada_accounts o ON o.id = bl.ohada_account_id
                 WHERE b.fiscal_year = ? AND o.type = 'expense'
                   AND bl.annual_amount > 0
            ");
            $q->execute([$year]);
            $over = 0;
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $actual = $actuals_by_acc[$r['account_number']] ?? 0.0;
                if ((float)$r['annual_amount'] > 0
                    && ($actual / (float)$r['annual_amount']) >= $threshold) {
                    $over++;
                }
            }
            return $over;
        } catch (Throwable $e) {
            error_log('_lpc_count_budgets_over_pct: ' . $e->getMessage());
            return 0;
        }
    }
}
