<?php
/**
 * includes/classes/TaxEngine.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Cameroon tax calculation engine.
 *
 * ONE class, no side effects, deterministic. Given a period and the raw
 * accounting facts already stored (invoices, payments, payroll_runs,
 * withholding_certificates), it returns the exact amounts to declare and
 * the net cash the company owes the DGI / CNPS / commune.
 *
 * Every method returns a plain array with:
 *   - lines[]   : ordered breakdown (line_code, label, amount, is_credit)
 *   - total_due : sum of non-credit lines
 *   - credit    : sum of credit lines (AIR already withheld, TVA credit
 *                 reportable from prior month, etc.)
 *   - net       : max(0, total_due - credit)
 *   - carry     : max(0, credit - total_due) — credit reportable next month
 *
 * The controller (tax_controller.php) persists the returned lines into
 * tax_declarations + tax_declaration_lines. Nothing else touches these
 * numbers, so if the client asks "why did you declare X?" we can point
 * at the exact SQL fingerprint stored per line.
 *
 * All amounts are FCFA (integer half-up). No sub-units.
 *
 * REFERENCES (CM Code Général des Impôts, edition 2026):
 *   Art. 21 bis    — Acompte mensuel AIR (2.2% / 5.5% / autres taux)
 *   Art. 92-93     — TVA collectée / déductible, crédit reportable
 *   Art. 149       — Retenue à la source AIR par entités habilitées
 *   Art. 225       — Régime réel / simplifié / forfait
 *   Art. 233       — TSR (services rendus par non-résidents)
 *   Art. 29/30     — Base imposable IRPP (post-CNPS, -30%, -500k/12)
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class TaxEngine
{
    /**
     * Fetch the company tax settings (régime, rates, CNPS group).
     * Cached per request.
     */
    public static function settings(): array
    {
        static $s = null;
        if ($s !== null) return $s;
        $db = Database::getInstance()->getConnection();
        $s = $db->query("SELECT * FROM company_tax_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$s) {
            // Sensible defaults if not seeded — régime réel, AIR 2.2%, group A.
            $s = [
                'tax_regime' => 'reel',
                'cit_rate'   => 0.33, 'air_rate' => 0.022, 'tva_rate' => 0.1925,
                'tva_liable' => 1, 'cnps_group' => 'A', 'is_withholding_agent' => 0,
                'filing_day' => 15,
            ];
        }
        return $s;
    }

    /**
     * AIR — Acompte d'Impôt sur le Revenu / Minimum de Perception.
     * Monthly instalment based on turnover, minus what clients already
     * withheld at source (Prometal etc.) — the net is what actually
     * goes to the DGI.
     *
     * Formula:
     *   turnover_ht           = SUM(invoices.subtotal issued in period)
     *   air_gross             = turnover_ht * settings.air_rate
     *   air_withheld_at_src   = SUM(payments.air_withheld_amount in period
     *                              WHERE withholding_certificate_id NOT NULL)
     *   net_air_due           = max(0, air_gross - air_withheld_at_src)
     *
     * NB: only CERTIFIED withholdings (attestation uploaded) are credited,
     *     per art. L.94 LPF. Uncertified retenues stay pending until the
     *     attestation is received.
     */
    public static function computeAir(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        $s  = self::settings();
        $rate = (float) $s['air_rate'];

        $q = $db->prepare("
            SELECT COALESCE(SUM(subtotal), 0) AS turnover_ht,
                   COALESCE(SUM(air_amount), 0) AS air_expected
              FROM invoices
             WHERE YEAR(date) = ? AND MONTH(date) = ?
        ");
        $q->execute([$year, $month]);
        $inv = $q->fetch(PDO::FETCH_ASSOC);

        $turnover = self::round((float) $inv['turnover_ht']);
        $air_gross = self::round($turnover * $rate);

        // Certified withholdings received from clients this period.
        $q = $db->prepare("
            SELECT COALESCE(SUM(p.air_withheld_amount), 0) AS air_withheld_certified
              FROM payments p
              JOIN withholding_certificates wc ON p.withholding_certificate_id = wc.id
             WHERE wc.period_year = ? AND wc.period_month = ?
        ");
        $q->execute([$year, $month]);
        $withheld = self::round((float) $q->fetchColumn());

        // Uncertified pending — informational.
        $q = $db->prepare("
            SELECT COALESCE(SUM(air_withheld_amount), 0)
              FROM payments
             WHERE YEAR(payment_date) = ? AND MONTH(payment_date) = ?
               AND air_withheld_amount > 0
               AND withholding_certificate_id IS NULL
        ");
        $q->execute([$year, $month]);
        $pending = self::round((float) $q->fetchColumn());

        $net = max(0, $air_gross - $withheld);
        $carry = max(0, $withheld - $air_gross);

        return [
            'kind'   => 'air',
            'period' => sprintf('%04d-%02d', $year, $month),
            'lines'  => [
                ['line_code' => 'CHIFFRE_AFFAIRES_HT',   'label' => "Chiffre d'affaires HT du mois",
                 'amount' => $turnover, 'is_credit' => 0],
                ['line_code' => 'AIR_TAUX',              'label' => "Taux AIR appliqué ({$s['tax_regime']})",
                 'amount' => (float) ($rate * 100),      'is_credit' => 0, 'informational' => true],
                ['line_code' => 'AIR_BRUT',              'label' => "AIR brut (CA HT × taux)",
                 'amount' => $air_gross, 'is_credit' => 0],
                ['line_code' => 'AIR_RETENU_SOURCE',     'label' => "AIR déjà retenu à la source par les clients (attestations)",
                 'amount' => $withheld,  'is_credit' => 1],
                ['line_code' => 'AIR_ATTESTATIONS_MANQUANTES', 'label' => "AIR retenu sans attestation reçue (crédit différé)",
                 'amount' => $pending,   'is_credit' => 0, 'informational' => true],
            ],
            'total_due' => $air_gross,
            'credit'    => $withheld,
            'net'       => $net,
            'carry'     => $carry,
            'due_date'  => self::deadline($year, $month),
        ];
    }

    /**
     * TVA — monthly VAT declaration.
     *   collectée      = SUM(invoices.tva_amount issued in period)
     *   déductible     = SUM(supplier_invoices.tva_amount posted in period)
     *   crédit report  = prior period carry
     *   net_tva        = max(0, collectée - déductible - crédit report)
     *
     * Falls back to 0 for déductible if the supplier_invoices table isn't
     * present yet — TVA collectée is still declared correctly.
     */
    public static function computeTva(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();

        $q = $db->prepare("SELECT COALESCE(SUM(tva_amount), 0) FROM invoices WHERE YEAR(date)=? AND MONTH(date)=?");
        $q->execute([$year, $month]);
        $collectee = self::round((float) $q->fetchColumn());

        // Déductible — reads from supplier_invoices or overheads with tva column.
        $deductible = 0.0;
        try {
            $q = $db->prepare("SELECT COALESCE(SUM(tva_amount), 0) FROM supplier_invoices WHERE YEAR(date)=? AND MONTH(date)=?");
            $q->execute([$year, $month]);
            $deductible = self::round((float) $q->fetchColumn());
        } catch (Throwable $e) {
            // supplier_invoices not present — degrade gracefully.
        }

        // Prior period credit (crédit de TVA reportable).
        $prev = self::previousPeriod($year, $month);
        $q = $db->prepare("SELECT COALESCE(MAX(carry_forward),0) FROM tax_declarations
                            WHERE kind='tva' AND period_year=? AND period_month=?");
        try {
            $q->execute([$prev[0], $prev[1]]);
        } catch (Throwable $e) {
            // carry_forward column may not exist on legacy schemas — treat as 0.
        }
        $credit_report = self::round((float) ($q->fetchColumn() ?: 0));

        $total_due = $collectee;
        $credit    = $deductible + $credit_report;
        $net       = max(0, $total_due - $credit);
        $carry     = max(0, $credit - $total_due);

        return [
            'kind'   => 'tva',
            'period' => sprintf('%04d-%02d', $year, $month),
            'lines'  => [
                ['line_code' => 'TVA_COLLECTEE',        'label' => 'TVA collectée sur ventes',
                 'amount' => $collectee,     'is_credit' => 0],
                ['line_code' => 'TVA_DEDUCTIBLE',       'label' => 'TVA déductible sur achats',
                 'amount' => $deductible,    'is_credit' => 1],
                ['line_code' => 'TVA_CREDIT_REPORTE',   'label' => 'Crédit de TVA reporté du mois précédent',
                 'amount' => $credit_report, 'is_credit' => 1],
            ],
            'total_due' => $total_due,
            'credit'    => $credit,
            'net'       => $net,
            'carry'     => $carry,
            'due_date'  => self::deadline($year, $month),
        ];
    }

    /**
     * IRPP + CAC + CFC + CRTV + TDL — the DIPE (Déclaration Individuelle
     * des Personnes Employées) sums all payroll_runs of the period.
     * Payroll::compute() has already broken each payslip into IRPP / CAC /
     * CFC / CRTV / TDL / CNPS employee & employer. We aggregate here.
     */
    public static function computeDipe(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();

        $sums = ['irpp' => 0, 'cac' => 0, 'cfc_employee' => 0, 'cfc_employer' => 0,
                 'crtv' => 0, 'tdl' => 0, 'cnps_employee' => 0, 'cnps_employer' => 0,
                 'net_paid' => 0, 'gross_total' => 0];
        try {
            $q = $db->prepare("
                SELECT COALESCE(SUM(irpp),0) irpp, COALESCE(SUM(cac),0) cac,
                       COALESCE(SUM(cfc_employee),0) cfc_employee, COALESCE(SUM(cfc_employer),0) cfc_employer,
                       COALESCE(SUM(crtv),0) crtv, COALESCE(SUM(tdl),0) tdl,
                       COALESCE(SUM(cnps_employee),0) cnps_employee,
                       COALESCE(SUM(cnps_employer),0) cnps_employer,
                       COALESCE(SUM(net_salary),0) net_paid,
                       COALESCE(SUM(gross_salary),0) gross_total
                  FROM payroll_runs
                 WHERE year=? AND month=? AND status IN ('draft','posted')
            ");
            $q->execute([$year, $month]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if ($r) foreach ($r as $k => $v) { $sums[$k] = (float) $v; }
        } catch (Throwable $e) {
            // payroll_runs schema not present — return zero-filled skeleton.
        }

        $dgi_due  = self::round($sums['irpp'] + $sums['cac'] + $sums['cfc_employee'] + $sums['cfc_employer']
                              + $sums['crtv'] + $sums['tdl']);
        $cnps_due = self::round($sums['cnps_employee'] + $sums['cnps_employer']);
        $total_due = $dgi_due + $cnps_due;

        return [
            'kind'   => 'irpp_dipe',
            'period' => sprintf('%04d-%02d', $year, $month),
            'lines'  => [
                ['line_code' => 'MASSE_SALARIALE_BRUTE', 'label' => 'Masse salariale brute',
                 'amount' => self::round($sums['gross_total']), 'is_credit' => 0, 'informational' => true],
                ['line_code' => 'IRPP',            'label' => 'IRPP retenu à la source', 'amount' => self::round($sums['irpp']),            'is_credit' => 0],
                ['line_code' => 'CAC',             'label' => 'CAC (10% de l\'IRPP)',    'amount' => self::round($sums['cac']),             'is_credit' => 0],
                ['line_code' => 'CFC_SALARIE',     'label' => 'CFC part salarié (1%)',  'amount' => self::round($sums['cfc_employee']),    'is_credit' => 0],
                ['line_code' => 'CFC_PATRONAL',    'label' => 'CFC part patronale (1.5%)','amount' => self::round($sums['cfc_employer']),  'is_credit' => 0],
                ['line_code' => 'CRTV',            'label' => 'Redevance audiovisuelle', 'amount' => self::round($sums['crtv']),           'is_credit' => 0],
                ['line_code' => 'TDL',             'label' => 'Taxe de développement local','amount' => self::round($sums['tdl']),         'is_credit' => 0],
                ['line_code' => 'CNPS_SALARIE',    'label' => 'CNPS part salariale',     'amount' => self::round($sums['cnps_employee']),  'is_credit' => 0],
                ['line_code' => 'CNPS_PATRONAL',   'label' => 'CNPS part patronale',     'amount' => self::round($sums['cnps_employer']),  'is_credit' => 0],
            ],
            'total_due'  => $total_due,
            'credit'     => 0,
            'net'        => $total_due,
            'dgi_split'  => $dgi_due,   // pay to DGI
            'cnps_split' => $cnps_due,  // pay to CNPS
            'due_date'   => self::deadline($year, $month),
        ];
    }

    /**
     * TSR — Taxe Spéciale sur le Revenu (services rendus par non-résidents).
     * Applied when we buy services from a foreign supplier: we withhold
     * 15% (general), 10% (short-term PE waived) or 3% (public procurement,
     * digital audiovisual, oil R&D, maritime).
     * Reads from supplier_invoices flagged foreign_service = 1.
     */
    public static function computeTsr(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        $total = 0.0; $lines = [];
        try {
            $q = $db->prepare("
                SELECT COALESCE(SUM(subtotal * tsr_rate),0) AS tsr_amount,
                       COALESCE(SUM(subtotal), 0) AS base
                  FROM supplier_invoices
                 WHERE foreign_service = 1 AND YEAR(date)=? AND MONTH(date)=?
            ");
            $q->execute([$year, $month]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            $total = self::round((float) $r['tsr_amount']);
            $lines[] = ['line_code' => 'TSR_BASE',   'label' => 'Base — services étrangers',
                        'amount' => self::round((float) $r['base']), 'is_credit' => 0, 'informational' => true];
            $lines[] = ['line_code' => 'TSR_MONTANT','label' => 'TSR à reverser', 'amount' => $total, 'is_credit' => 0];
        } catch (Throwable $e) {
            // supplier_invoices without tsr_rate — skip.
            $lines[] = ['line_code' => 'TSR_MONTANT','label' => 'TSR à reverser', 'amount' => 0, 'is_credit' => 0];
        }
        return ['kind' => 'tsr', 'period' => sprintf('%04d-%02d', $year, $month),
                'lines' => $lines, 'total_due' => $total, 'credit' => 0,
                'net' => $total, 'due_date' => self::deadline($year, $month)];
    }

    /**
     * Patente — annual business licence tax.
     *   0.159% for large taxpayers (DGE) — min 5M, max 2.5B
     *   0.283% for medium (CIME/CSPLI)   — min 141,500, max 4.5M
     *   0.494% for small (CDI)           — min 50,000, max 140,000
     * Reads previous FY turnover and clamps into the band.
     */
    public static function computePatente(int $year): array
    {
        $db = Database::getInstance()->getConnection();
        $q = $db->prepare("SELECT COALESCE(SUM(subtotal),0) FROM invoices WHERE YEAR(date)=?");
        $q->execute([$year - 1]);
        $prev_turnover = self::round((float) $q->fetchColumn());

        $s = self::settings();
        // Tier is determined by tax office; use turnover as a fallback proxy.
        if     ($prev_turnover >= 3_000_000_000) { $rate = 0.00159; $min = 5_000_000;   $max = 2_500_000_000; $tier = 'DGE'; }
        elseif ($prev_turnover >= 50_000_000)    { $rate = 0.00283; $min = 141_500;     $max = 4_500_000;     $tier = 'CIME'; }
        else                                     { $rate = 0.00494; $min = 50_000;      $max = 140_000;       $tier = 'CDI';  }

        $raw = self::round($prev_turnover * $rate);
        $due = min($max, max($min, $raw));

        return [
            'kind'   => 'patente',
            'period' => sprintf('%04d', $year),
            'lines'  => [
                ['line_code' => 'CA_N-1',       'label' => "Chiffre d'affaires N-1", 'amount' => $prev_turnover, 'is_credit' => 0, 'informational' => true],
                ['line_code' => 'TAUX',         'label' => "Taux patente ({$tier})", 'amount' => (float) ($rate * 100), 'is_credit' => 0, 'informational' => true],
                ['line_code' => 'PATENTE_BRUT', 'label' => "Patente calculée",       'amount' => $raw, 'is_credit' => 0, 'informational' => true],
                ['line_code' => 'PATENTE_DUE',  'label' => "Patente due (min/max encadrement)", 'amount' => $due, 'is_credit' => 0],
            ],
            'total_due' => $due, 'credit' => 0, 'net' => $due,
            'due_date'  => sprintf('%04d-02-28', $year),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Deadline for monthly filings: the 15th of the following month. */
    private static function deadline(int $year, int $month): string
    {
        $day = (int) (self::settings()['filing_day'] ?? 15);
        $m = $month + 1; $y = $year;
        if ($m > 12) { $m = 1; $y += 1; }
        return sprintf('%04d-%02d-%02d', $y, $m, $day);
    }

    private static function previousPeriod(int $year, int $month): array
    {
        $m = $month - 1; $y = $year;
        if ($m < 1) { $m = 12; $y -= 1; }
        return [$y, $m];
    }

    private static function round(float $x): float
    {
        return (float) round($x, 0, PHP_ROUND_HALF_UP);
    }
}
