<?php
/**
 * includes/classes/Payroll.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (parallel) · Cameroon payroll calculation engine.
 *
 * Turns a contract row + monthly inputs into a fully-broken-down payslip:
 *   gross → CNPS employee/employer → IRPP (progressive brackets) →
 *   CFC → CAC → CRTV → TDL → net.
 *
 * Rates and IRPP/CRTV brackets are read from the DB (payroll_rates,
 * payroll_irpp_brackets, payroll_crtv_brackets). If a year isn't seeded the
 * engine falls back to the CM 2026 defaults (baked in here) and logs a
 * warning so accountants notice the config drift.
 *
 * Every FCFA amount is rounded to the nearest integer HALF-UP — FCFA has no
 * subunit and the payroll journal entry must balance without decimal noise.
 *
 * Legal spec references embedded in comments; verify with your accountant
 * before rolling to a new fiscal year.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class Payroll
{
    /**
     * Baked-in CM 2026 defaults. Used only when the DB tables are empty for
     * the requested year. Kept as a single source of truth in one array so
     * the JS/PHP breakdown display can never contradict the compute.
     */
    private const DEFAULT_RATES = [
        'cnps_employee_rate'  => 0.042,
        'cnps_employer_rate'  => 0.162,
        'cnps_ceiling'        => 750000.0,
        'cfc_employee_rate'   => 0.010,
        'cfc_employer_rate'   => 0.015,
        'cac_rate'            => 0.100,
        'frais_pro_rate'      => 0.300,
        'abattement_annuel'   => 500000.0,
        'tdl_threshold'       => 100000.0,
        'tdl_amount'          => 3000.0,
    ];

    private const DEFAULT_IRPP_BRACKETS = [
        // [min, max_or_null, rate]
        [0.0,      166666.0, 0.10],
        [166666.0, 250000.0, 0.15],
        [250000.0, 416666.0, 0.25],
        [416666.0, null,     0.35],
    ];

    private const DEFAULT_CRTV_BRACKETS = [
        // [gross_min, gross_max_or_null, fixed_amount]
        [0.0,       50000.0,       0.0],
        [50000.0,   100000.0,      750.0],
        [100000.0,  200000.0,      1950.0],
        [200000.0,  300000.0,      3250.0],
        [300000.0,  400000.0,      4550.0],
        [400000.0,  500000.0,      5850.0],
        [500000.0,  600000.0,      7150.0],
        [600000.0,  700000.0,      8450.0],
        [700000.0,  800000.0,      9750.0],
        [800000.0,  900000.0,      11050.0],
        [900000.0,  1000000.0,     12350.0],
        [1000000.0, null,          13000.0],
    ];

    /** In-request cache keyed by year, so multiple compute()s hit the DB once. */
    private static array $config_cache = [];

    /**
     * Compute a full monthly payslip.
     *
     * @param array $contract  hr_contracts row (base_salary, housing_allowance,
     *                         transport_allowance, dependents_count, marital_status,
     *                         tax_regime, seniority_years — everything additive is
     *                         optional and defaults to 0 / 'standard').
     * @param array $inputs    Per-run inputs: bonuses, absences_days,
     *                         absences_amount (either), advances_deducted,
     *                         driver_debt_deducted, other_deductions.
     * @param DateTimeImmutable $month  First day of the period the payslip is for.
     * @return array full breakdown — see keys at the end of this method.
     */
    public static function compute(array $contract, array $inputs, DateTimeImmutable $month): array
    {
        $year = (int) $month->format('Y');
        [$rates, $irpp_brackets, $crtv_brackets] = self::loadConfig($year);

        // -- 1. Gross salary --------------------------------------------------
        $base       = (float) ($contract['base_salary']         ?? 0);
        $housing    = (float) ($contract['housing_allowance']   ?? 0);
        $transport  = (float) ($contract['transport_allowance'] ?? 0);
        $bonuses    = (float) ($inputs['bonuses']               ?? 0);

        // Absences may be sent as either a day count or a raw amount.
        $absence_days   = (float) ($inputs['absences_days']   ?? 0);
        $absence_amount = (float) ($inputs['absences_amount'] ?? 0);
        if ($absence_days > 0 && $absence_amount <= 0) {
            // Daily prorate assumes a 30-day fiscal month per CM labor code.
            $absence_amount = ($base / 30.0) * $absence_days;
        }

        $gross = max(0.0, $base + $housing + $transport + $bonuses - $absence_amount);

        // -- 2. CNPS (part salariale + part patronale) ------------------------
        // CNPS base is the LOWER of gross and the monthly ceiling.
        $cnps_base       = min($gross, (float) $rates['cnps_ceiling']);
        $cnps_employee   = self::roundFcfa($cnps_base * (float) $rates['cnps_employee_rate']);
        $cnps_employer   = self::roundFcfa($cnps_base * (float) $rates['cnps_employer_rate']);

        // -- 3. IRPP progressive brackets on the monthly taxable base ---------
        // Taxable base = (gross - CNPS) × (1 - frais_pro_rate) - abattement/12
        // (Cameroon CGI art. 29 & 30. Expatriates and exempt regimes short-circuit.)
        $regime = (string) ($contract['tax_regime'] ?? 'standard');
        $irpp   = 0.0;
        $taxable_base = 0.0;

        if ($regime !== 'exempt') {
            $post_cnps = max(0.0, $gross - $cnps_employee);
            $frais_pro = $post_cnps * (float) $rates['frais_pro_rate'];
            $monthly_abattement = ((float) $rates['abattement_annuel']) / 12.0;
            $taxable_base = max(0.0, $post_cnps - $frais_pro - $monthly_abattement);

            $irpp = self::applyBrackets($taxable_base, $irpp_brackets);
            $irpp = self::roundFcfa($irpp);
        }

        // -- 4. CAC = 10% de l'IRPP (Centimes Additionnels Communaux) ---------
        $cac = self::roundFcfa($irpp * (float) $rates['cac_rate']);

        // -- 5. CFC (Contribution Crédit Foncier) -----------------------------
        // Part salariale: 1% du brut, prélevée sur le net.
        // Part patronale: 1.5% du brut, PAS déduit du net (charge patronale).
        $cfc_employee = self::roundFcfa($gross * (float) $rates['cfc_employee_rate']);
        $cfc_employer = self::roundFcfa($gross * (float) $rates['cfc_employer_rate']);

        // -- 6. CRTV (Redevance Audio-Visuelle) — barème brut -----------------
        $crtv = self::roundFcfa(self::lookupBracket($gross, $crtv_brackets));

        // -- 7. TDL (Taxe de Développement Local) -----------------------------
        $tdl = ($gross > (float) $rates['tdl_threshold'])
             ? self::roundFcfa((float) $rates['tdl_amount'])
             : 0.0;

        // -- 8. Net salary ----------------------------------------------------
        $advances_deducted     = self::roundFcfa((float) ($inputs['advances_deducted']    ?? 0));
        $driver_debt_deducted  = self::roundFcfa((float) ($inputs['driver_debt_deducted'] ?? 0));
        $other_deductions      = self::roundFcfa((float) ($inputs['other_deductions']     ?? 0));

        $employee_deductions = $cnps_employee + $irpp + $cac + $cfc_employee + $crtv + $tdl;
        $net_salary = self::roundFcfa(
            $gross
            - $employee_deductions
            - $advances_deducted
            - $driver_debt_deducted
            - $other_deductions
        );
        if ($net_salary < 0) {
            $net_salary = 0.0; // Never a negative net — accountant reviews.
        }

        // -- 9. Journal-entry line snapshot (used by generate_month) ----------
        // The controller consumes this to post a balanced JE via post_journal_entry.
        // Signs are debit-positive convention.
        $breakdown_lines = [
            ['label' => 'Salaire de base',          'account' => '661', 'debit' => self::roundFcfa($base + $bonuses - $absence_amount), 'credit' => 0.0],
            ['label' => 'Indemnités (logement/transport)', 'account' => '662', 'debit' => self::roundFcfa($housing + $transport), 'credit' => 0.0],
            ['label' => 'Charges patronales CNPS',  'account' => '664', 'debit' => $cnps_employer, 'credit' => 0.0],
            ['label' => 'Charges patronales CFC',   'account' => '664', 'debit' => $cfc_employer, 'credit' => 0.0],

            ['label' => 'CNPS à payer (salarié+patronal)', 'account' => '433', 'debit' => 0.0, 'credit' => $cnps_employee + $cnps_employer],
            ['label' => 'IRPP + CAC + CFC + CRTV + TDL',   'account' => '432', 'debit' => 0.0, 'credit' => $irpp + $cac + $cfc_employee + $crtv + $tdl + $cfc_employer],
            ['label' => 'Avances / dettes déduites',       'account' => '421', 'debit' => 0.0, 'credit' => $advances_deducted + $driver_debt_deducted + $other_deductions],
            ['label' => 'Net à payer',                     'account' => '422', 'debit' => 0.0, 'credit' => $net_salary],
        ];

        return [
            'gross_salary'          => self::roundFcfa($gross),
            'taxable_base'          => self::roundFcfa($taxable_base),
            'cnps_employee'         => $cnps_employee,
            'cnps_employer'         => $cnps_employer,
            'irpp'                  => $irpp,
            'cac'                   => $cac,
            'cfc_employee'          => $cfc_employee,
            'cfc_employer'          => $cfc_employer,
            'crtv'                  => $crtv,
            'tdl'                   => $tdl,
            'advances_deducted'     => $advances_deducted,
            'driver_debt_deducted'  => $driver_debt_deducted,
            'other_deductions'      => $other_deductions,
            'absences_deducted'     => self::roundFcfa($absence_amount),
            'net'                   => $net_salary,
            'breakdown_lines'       => $breakdown_lines,
            'rates_used'            => $rates,
            'year'                  => $year,
            'month'                 => (int) $month->format('n'),
        ];
    }

    /**
     * FCFA has no subunit. Round to nearest integer, half-up.
     * Returns a float so the callers can keep type-coherent addition, but
     * every returned value has no fractional part.
     */
    private static function roundFcfa(float $amount): float
    {
        return (float) round($amount, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Progressive-bracket application for IRPP-style computations.
     * $brackets is an ordered list of [min, max_or_null, rate].
     */
    private static function applyBrackets(float $base, array $brackets): float
    {
        $tax = 0.0;
        foreach ($brackets as [$min, $max, $rate]) {
            if ($base <= $min) {
                break;
            }
            $upper = ($max === null) ? $base : min($base, (float) $max);
            $slice = max(0.0, $upper - (float) $min);
            $tax  += $slice * (float) $rate;
        }
        return $tax;
    }

    /**
     * Flat-lookup brackets (CRTV) — pick the row whose [min, max] contains
     * $gross; return its fixed_amount.
     */
    private static function lookupBracket(float $gross, array $brackets): float
    {
        foreach ($brackets as [$min, $max, $amount]) {
            $in_lower = ($gross > (float) $min) || ($min == 0 && $gross >= 0);
            $in_upper = ($max === null) || ($gross <= (float) $max);
            if ($in_lower && $in_upper) {
                return (float) $amount;
            }
        }
        return 0.0;
    }

    /**
     * Load rates + brackets for a given year. Cached per-request. Falls back
     * to baked-in CM-2026 defaults if the DB rows are missing (logs the
     * fallback so the accountant fixes payroll_rates).
     *
     * @return array{0: array<string,float>, 1: array<int,array{0:float,1:?float,2:float}>, 2: array<int,array{0:float,1:?float,2:float}>}
     */
    private static function loadConfig(int $year): array
    {
        if (isset(self::$config_cache[$year])) {
            return self::$config_cache[$year];
        }

        $rates          = self::DEFAULT_RATES;
        $irpp_brackets  = self::DEFAULT_IRPP_BRACKETS;
        $crtv_brackets  = self::DEFAULT_CRTV_BRACKETS;

        try {
            $db = Database::getInstance()->getConnection();

            $stmt = $db->prepare("SELECT rate_key, rate_value FROM payroll_rates WHERE year = ?");
            $stmt->execute([$year]);
            $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            if (!empty($rows)) {
                foreach ($rows as $k => $v) {
                    $rates[$k] = (float) $v;
                }
            } else {
                error_log("Payroll: no payroll_rates rows for year $year — using CM-2026 defaults.");
            }

            $stmt = $db->prepare(
                "SELECT min_amount, max_amount, rate
                   FROM payroll_irpp_brackets
                  WHERE year = ? ORDER BY tranche_ord ASC"
            );
            $stmt->execute([$year]);
            $db_irpp = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($db_irpp)) {
                $irpp_brackets = array_map(
                    fn($r) => [
                        (float) $r['min_amount'],
                        $r['max_amount'] === null ? null : (float) $r['max_amount'],
                        (float) $r['rate'],
                    ],
                    $db_irpp
                );
            } else {
                error_log("Payroll: no payroll_irpp_brackets rows for year $year — using defaults.");
            }

            $stmt = $db->prepare(
                "SELECT gross_min, gross_max, fixed_amount
                   FROM payroll_crtv_brackets
                  WHERE year = ? ORDER BY tranche_ord ASC"
            );
            $stmt->execute([$year]);
            $db_crtv = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($db_crtv)) {
                $crtv_brackets = array_map(
                    fn($r) => [
                        (float) $r['gross_min'],
                        $r['gross_max'] === null ? null : (float) $r['gross_max'],
                        (float) $r['fixed_amount'],
                    ],
                    $db_crtv
                );
            }
        } catch (Throwable $e) {
            error_log('Payroll::loadConfig fallback to defaults: ' . $e->getMessage());
        }

        self::$config_cache[$year] = [$rates, $irpp_brackets, $crtv_brackets];
        return self::$config_cache[$year];
    }

    /** Clear the in-request rate cache. Called by unit tests. */
    public static function clearCache(): void
    {
        self::$config_cache = [];
    }
}
