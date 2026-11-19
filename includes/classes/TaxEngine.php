<?php
/**
 * includes/classes/TaxEngine.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Cameroon tax calculation engine.
 *
 * ONE class, no side effects, deterministic. Given a period and the raw
 * accounting facts already stored (invoices, payments, hr_payslips,
 * supplier_invoices, withholding_certificates), it returns the exact amounts
 * to declare and the net cash the company owes DGI / CNPS / commune.
 *
 * Every method returns a plain array with:
 *   - lines[]     : ordered breakdown (line_code, label, amount, is_credit,
 *                   informational, destination)
 *   - total_due   : sum of non-credit, non-informational lines
 *   - credit      : sum of credit lines
 *   - net         : max(0, total_due - credit)
 *   - carry       : max(0, credit - total_due) — reportable next period
 *   - due_date    : YYYY-MM-DD DGI/CNPS filing deadline
 *   - destination : which authority receives it ('dgi'|'cnps'|'commune')
 *
 * The controller (tax_controller.php) persists the returned lines into
 * tax_declarations + tax_declaration_lines. Nothing else touches these
 * numbers, so if the DGI or PwC asks "why did you declare X?" we can point
 * at the exact SQL fingerprint stored per line (see source_ref).
 *
 * All amounts are FCFA (integer half-up). No sub-units.
 *
 * REFERENCES (CM Code Général des Impôts, edition 2026):
 *   Art. 21 bis     — Acompte mensuel AIR (2.2 % / 5.5 %)
 *   Art. 92-93      — TVA collectée / déductible, crédit reportable
 *   Art. 149        — Retenue à la source AIR par entités habilitées
 *   Art. 225        — Régime réel / simplifié / forfait
 *   Art. 233        — TSR (services rendus par non-résidents)
 *   Art. 29 / 30    — Base imposable IRPP (post-CNPS, -30 %, -500 k / 12)
 *   Loi CNPS 1969   — PVID 8,4 % (4,2 sal / 4,2 pat), PF 7 % pat, AT 1,75-5 % pat
 *   Loi FNE 1990    — 1 % patronal sur la masse salariale
 *   Art. L.94 LPF   — Attestation obligatoire pour crédit AIR / TSR
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class TaxEngine
{
    /**
     * The five declaration kinds the monthly modal bundles. Order matters:
     * this is the order they appear in the Master Monthly Modal.
     */
    public const MONTHLY_KINDS = ['tva', 'air', 'irpp_dipe', 'cnps', 'tsr', 'commune'];

    /**
     * Company tax settings — régime, rates, CNPS group.
     * Reads company_tax_settings if the row exists (Sprint 8 sync from
     * Prefs::setMany writes to the same table). Falls back to preferences
     * so a fresh install without the row still boots.
     * Cached per request.
     */
    public static function settings(): array
    {
        static $s = null;
        if ($s !== null) return $s;
        $db = Database::getInstance()->getConnection();
        try {
            $row = $db->query("SELECT * FROM company_tax_settings ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $row = null;
        }
        if (!$row) $row = [];

        // Merge with preference fallbacks so a bare install still has usable
        // rates. Preferences (Prefs::*) write back to company_tax_settings on
        // save via Prefs::syncTaxSettings(), so this branch is only used
        // BEFORE the first save.
        $s = array_merge([
            'tax_regime'             => class_exists('CompanyProfile') ? CompanyProfile::field('fiscal_regime', 'reel') : 'reel',
            'cit_rate'               => class_exists('Prefs') ? Prefs::rate('tax_cit_rate', 33)    : 0.33,
            'air_rate'               => class_exists('Prefs') ? Prefs::rate('tax_air_rate', 2.2)   : 0.022,
            'tva_rate'               => class_exists('Prefs') ? Prefs::rate('tax_tva_rate', 19.25) : 0.1925,
            'tva_liable'             => class_exists('Prefs') ? (Prefs::bool('tax_tva_liable', true) ? 1 : 0) : 1,
            'cnps_group'             => class_exists('Prefs') ? Prefs::str('tax_cnps_group', 'A')  : 'A',
            'is_withholding_agent'   => class_exists('Prefs') ? (Prefs::bool('tax_is_withholding_agent', false) ? 1 : 0) : 0,
            'filing_day'             => class_exists('Prefs') ? Prefs::int('tax_filing_day', 15)   : 15,
            'niu'                    => class_exists('CompanyProfile') ? CompanyProfile::field('niu', '—') : '—',
        ], $row);

        // « LPC assujetti à la TVA » is the preference itself (decision 19,
        // migration 156): the mirrored company_tax_settings.tva_liable kept the
        // old 1 until the next save of Paramètres.
        if (class_exists('Prefs')) $s['tva_liable'] = Prefs::bool('tax_tva_liable', false) ? 1 : 0;

        // Layered preferences that are not (yet) mirrored to company_tax_settings.
        $s['fne_rate']         = class_exists('Prefs') ? Prefs::rate('tax_fne_rate', 1.0)      : 0.01;
        $s['at_rate']          = self::atRateForGroup((string) ($s['cnps_group'] ?? 'A'));
        $s['commune_monthly']  = class_exists('Prefs') ? (float) Prefs::int('tax_commune_monthly', 0) : 0.0;
        $s['patente_override'] = class_exists('Prefs') ? (float) Prefs::int('tax_patente_annual', 0)  : 0.0;

        return $s;
    }

    /**
     * Accident-du-travail rate by CNPS risk group. 2026 statutory schedule:
     *   A (risque faible)  = 1.75 %
     *   B (risque moyen)   = 2.50 %
     *   C (risque élevé)   = 5.00 %
     * Reads the preference so the DGI cannot surprise us with a rate change
     * requiring a code deploy.
     */
    private static function atRateForGroup(string $group): float
    {
        // One source with the payroll (Payroll::cnpsEmployerParts, the group set
        // in Paramètres): the declaration and the payslips cannot disagree.
        require_once __DIR__ . '/Payroll.php';
        return (float) Payroll::cnpsEmployerParts()['at'];
    }

    // -------------------------------------------------------------------------
    // AIR — Acompte d'Impôt sur le Revenu / Minimum de Perception
    // -------------------------------------------------------------------------

    /**
     * Formula:
     *   turnover_ht         = SUM(invoices.subtotal issued in period)
     *   air_gross           = turnover_ht * settings.air_rate
     *   air_withheld_at_src = SUM(payments.air_withheld_amount in period
     *                             WHERE withholding_certificate_id NOT NULL)
     *   net_air_due         = max(0, air_gross - air_withheld_at_src)
     *
     * Only CERTIFIED withholdings (attestation uploaded) are credited,
     * per art. L.94 LPF. Uncertified retenues stay pending until the
     * attestation is received; they surface separately in the pending block.
     *
     * The returned `attestations` array lists every attestation applied on
     * this month, so the modal can render "AIR retenu par: Prometal 245 000,
     * SOSUCAM 180 000…" — this is what an auditor needs to reconcile.
     */
    public static function computeAir(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        $s  = self::settings();
        $rate = (float) $s['air_rate'];

        // Turnover HT from invoices posted in period.
        $q = $db->prepare("
            SELECT COALESCE(SUM(subtotal), 0)  AS turnover_ht,
                   COALESCE(SUM(air_amount),0) AS air_expected,
                   COUNT(*)                    AS invoice_count
              FROM invoices
             WHERE YEAR(date) = ? AND MONTH(date) = ?
        ");
        $q->execute([$year, $month]);
        $inv = $q->fetch(PDO::FETCH_ASSOC) ?: ['turnover_ht' => 0, 'air_expected' => 0, 'invoice_count' => 0];

        // Credit notes (avoirs, migration 152) issued in the month reduce the
        // turnover the AIR is computed on: PROMETAL's 9 100 F avoir took 200 F of
        // AIR back, so the attestation (9 810 F) covers the month exactly.
        $avoirs = 0.0;
        if (self::columnExists($db, 'payments', 'credit_amount_ht')) {
            $q = $db->prepare("SELECT COALESCE(SUM(credit_amount_ht), 0) FROM payments
                                WHERE payment_method = 'avoir' AND status = 'validated'
                                  AND YEAR(payment_date) = ? AND MONTH(payment_date) = ?");
            $q->execute([$year, $month]);
            $avoirs = (float) $q->fetchColumn();
        }
        $inv['turnover_ht'] = max(0.0, (float) $inv['turnover_ht'] - $avoirs);

        $turnover  = self::round((float) $inv['turnover_ht']);
        $air_gross = self::round($turnover * $rate);

        // Certified withholdings received from clients for this period.
        // Grouped so we can list "who withheld what" in the modal.
        $q = $db->prepare("
            SELECT c.id             AS client_id,
                   c.name           AS client_name,
                   wc.id            AS certificate_id,
                   wc.certificate_ref,
                   wc.issue_date,
                   wc.file_path,
                   COALESCE(SUM(p.air_withheld_amount), 0) AS air_withheld
              FROM payments p
              JOIN withholding_certificates wc ON p.withholding_certificate_id = wc.id
              JOIN clients c ON c.id = p.client_id
             WHERE wc.period_year = ? AND wc.period_month = ?
             GROUP BY c.id, c.name, wc.id, wc.certificate_ref, wc.issue_date, wc.file_path
             ORDER BY air_withheld DESC
        ");
        $q->execute([$year, $month]);
        $attestations = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $withheld = 0.0;
        foreach ($attestations as &$a) {
            $a['air_withheld'] = self::round((float) $a['air_withheld']);
            $withheld += (float) $a['air_withheld'];
        }
        unset($a);
        $withheld = self::round($withheld);

        // Uncertified pending — informational, not credited.
        $q = $db->prepare("
            SELECT COALESCE(SUM(p.air_withheld_amount), 0), COUNT(*)
              FROM payments p
             WHERE YEAR(p.payment_date) = ? AND MONTH(p.payment_date) = ?
               AND p.air_withheld_amount > 0
               AND p.withholding_certificate_id IS NULL
        ");
        $q->execute([$year, $month]);
        [$pending_sum, $pending_count] = $q->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $pending = self::round((float) $pending_sum);

        $net   = max(0.0, $air_gross - $withheld);
        $carry = max(0.0, $withheld - $air_gross);

        return [
            'kind'        => 'air',
            'destination' => 'dgi',
            'period'      => sprintf('%04d-%02d', $year, $month),
            'lines'       => [
                ['line_code' => 'CHIFFRE_AFFAIRES_HT',   'label' => "Chiffre d'affaires HT du mois (factures moins avoirs)",
                    'amount' => $turnover, 'is_credit' => 0, 'informational' => 1,
                    'source_ref' => "invoices(YEAR={$year} MONTH={$month}): {$inv['invoice_count']} factures"],
                ['line_code' => 'AIR_TAUX',              'label' => "Taux AIR appliqué (régime {$s['tax_regime']})",
                    'amount' => (float) ($rate * 100), 'is_credit' => 0, 'informational' => 1, 'unit' => '%'],
                ['line_code' => 'AIR_BRUT',              'label' => "AIR brut (CA HT × taux)",
                    'amount' => $air_gross, 'is_credit' => 0],
                ['line_code' => 'AIR_RETENU_SOURCE',     'label' => "AIR retenu à la source par les clients (attestations certifiées)",
                    'amount' => $withheld,  'is_credit' => 1,
                    'source_ref' => count($attestations) . ' attestation(s) reçue(s)'],
                ['line_code' => 'AIR_PENDING',           'label' => "AIR retenu sans attestation (crédit différé)",
                    'amount' => $pending, 'is_credit' => 0, 'informational' => 1,
                    'source_ref' => "{$pending_count} paiement(s) en attente"],
            ],
            'total_due'    => $air_gross,
            'credit'       => $withheld,
            'net'          => $net,
            'carry'        => $carry,
            'due_date'     => self::deadline($year, $month),
            'attestations' => $attestations,
            'pending_count' => (int) $pending_count,
            'pending_amount' => $pending,
        ];
    }

    // -------------------------------------------------------------------------
    // TVA — Taxe sur la Valeur Ajoutée
    // -------------------------------------------------------------------------

    /**
     * TVA, read from the ledger the month booked (VIDEO_QA_FINDINGS 13.3):
     *   collectée      = the month's net credit on 4431 (invoices, less credit notes)
     *   déductible     = the month's net debit on 4452 (receptions that split the
     *                    recoverable TVA, when LPC is liable)
     *   crédit reporté = the previous month's declaration's carry_forward
     *   net à payer    = max(0, collectée − déductible − crédit reporté)
     *   carry          = what is left of the credit, carried to the next month
     * The payment clears exactly these accounts (tax_controller record_payment).
     * Entries posted by a declaration's own payment are left out.
     *
     * It used to read invoices.tva_amount (credit notes ignored) and a
     * `supplier_invoices` table that does not exist, so the deductible part was
     * always 0, and the carry-over column did not exist either.
     *
     * « LPC assujetti à la TVA » (Paramètres; TaxEngine::settings()['tva_liable'])
     * off: there is nothing to declare, the block says so and nets to 0.
     */
    public static function computeTva(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        $liable = !empty(self::settings()['tva_liable']);

        $collectee = $liable ? self::round(-self::ledgerMonthNet($db, '4431', $year, $month)) : 0.0;
        // Only what receptions booked (Dr 601 HT + Dr 4452 / Cr 401, decision 19)
        // and their cancellations: the ristourne's 4452 line is not a purchase.
        $deductible = $liable ? self::round(self::ledgerMonthNet($db, '4452', $year, $month, ['purchase_order', 'purchase_order_reversal'])) : 0.0;
        $collectee  = max(0.0, $collectee);
        $deductible = max(0.0, $deductible);

        $credit_report = 0.0;
        if ($liable && self::columnExists($db, 'tax_declarations', 'carry_forward')) {
            [$py, $pm] = self::previousPeriod($year, $month);
            $q = $db->prepare("SELECT COALESCE(MAX(carry_forward),0) FROM tax_declarations
                                WHERE kind='tva' AND period_year=? AND period_month=?");
            $q->execute([$py, $pm]);
            $credit_report = self::round((float) $q->fetchColumn());
        }

        $total_due = $collectee;
        $credit    = $deductible + $credit_report;
        $net       = max(0.0, $total_due - $credit);
        $carry     = max(0.0, $credit - $total_due);

        $lines = $liable ? [
                ['line_code' => 'TVA_COLLECTEE',      'label' => 'TVA collectée sur ventes (4431)',
                    'amount' => $collectee, 'is_credit' => 0],
                ['line_code' => 'TVA_DEDUCTIBLE',     'label' => 'TVA déductible sur achats (4452)',
                    'amount' => $deductible, 'is_credit' => 1],
                ['line_code' => 'TVA_CREDIT_REPORTE', 'label' => 'Crédit de TVA reporté du mois précédent (4449)',
                    'amount' => $credit_report, 'is_credit' => 1],
                ['line_code' => 'TVA_CREDIT_A_REPORTER', 'label' => 'Crédit de TVA à reporter sur le mois suivant',
                    'amount' => $carry, 'is_credit' => 0, 'informational' => 1],
            ] : [
                ['line_code' => 'TVA_NON_ASSUJETTI',  'label' => "LPC n'est pas assujetti à la TVA (Paramètres) : rien à déclarer",
                    'amount' => 0.0, 'is_credit' => 0, 'informational' => 1],
            ];

        return [
            'kind'        => 'tva',
            'destination' => 'dgi',
            'period'      => sprintf('%04d-%02d', $year, $month),
            'lines'       => $lines,
            'total_due' => $total_due,
            'credit'    => $credit,
            'net'       => $net,
            'carry'     => $carry,
            'liable'    => $liable,
            'due_date'  => self::deadline($year, $month),
        ];
    }

    /**
     * Net movement (debit − credit) on one chart account (its own code and its
     * sub-accounts) over a month, posted entries only, leaving out the entries a
     * declaration's payment posted.
     */
    public static function ledgerMonthNet(PDO $db, string $code, int $year, int $month, array $sources = []): float
    {
        $only = $sources ? ' AND je.source_type IN (' . implode(',', array_fill(0, count($sources), '?')) . ')' : '';
        $q = $db->prepare("
            SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
              JOIN chart_of_accounts ca ON ca.id = jl.account_id
             WHERE ca.code LIKE ? AND YEAR(je.date) = ? AND MONTH(je.date) = ?
               AND COALESCE(je.source_type, '') <> 'tax_declaration' $only
        ");
        $q->execute(array_merge([$code . '%', $year, $month], $sources));
        return (float) $q->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // DIPE — IRPP + CAC + CFC + CRTV + TDL (DGI portion of payroll)
    // -------------------------------------------------------------------------

    /**
     * DIPE (Déclaration Individuelle des Personnes Employées) aggregates all
     * validated / paid payslips of the month. Every retenue that goes to the
     * DGI is here: IRPP, CAC, CFC (employee + employer), CRTV, TDL, RAV, FNE.
     *
     * CNPS is a SEPARATE declaration (different filing, different bank
     * account) — see computeCnps().
     *
     * The employer's CFC (1,5 %) and FNE (1 %) are what each payslip booked
     * (hr_payslips.cfc_employer / fne_employer, migration 136), so the DIPE equals
     * the month's credit on 432 to the franc (VIDEO_QA_FINDINGS 10.4). A payslip
     * calculated before 18/11/2026 stored neither: its share is still derived from
     * its gross × the rate, rounded per payslip as the payroll rounds it. For those
     * months the FNE is declared here but was never accrued by the payroll.
     */
    public static function computeDipe(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();

        $sums = [
            'gross_total'    => 0.0, 'net_paid'    => 0.0,
            'irpp'           => 0.0, 'cac'         => 0.0,
            'cfc_employee'   => 0.0, 'crtv'        => 0.0,
            'tdl'            => 0.0, 'rav'         => 0.0,
            'payslip_count'  => 0,
        ];
        // Rates for employer contributions (CFC-pat 1.5 %, FNE 1 %).
        $cfc_employer_rate = self::payrollRate($year, 'cfc_employer_rate', 0.015);
        $fne_rate          = self::payrollRate($year, 'fne_rate', (float) (self::settings()['fne_rate'] ?? 0.01));

        if (self::tableExists($db, 'hr_payslips')) {
            $q = $db->prepare("
                SELECT COALESCE(SUM(gross_salary),0) gross_total,
                       COALESCE(SUM(net_pay),0)      net_paid,
                       COALESCE(SUM(irpp),0)         irpp,
                       COALESCE(SUM(cac),0)          cac,
                       COALESCE(SUM(cfc),0)          cfc_employee,
                       COALESCE(SUM(crtv),0)         crtv,
                       COALESCE(SUM(tdl),0)          tdl,
                       COALESCE(SUM(rav),0)          rav,
                       COALESCE(SUM(COALESCE(cfc_employer, ROUND(gross_salary * :cfc_rate))),0) cfc_employer,
                       COALESCE(SUM(COALESCE(fne_employer, ROUND(gross_salary * :fne_rate))),0) fne_employer,
                       COUNT(*)                      payslip_count
                  FROM hr_payslips
                 WHERE year=:y AND month=:m AND status IN ('validated','paid')
            ");
            $q->execute([':y' => $year, ':m' => $month, ':cfc_rate' => $cfc_employer_rate, ':fne_rate' => $fne_rate]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row) foreach ($row as $k => $v) { $sums[$k] = (float) $v; }
        }

        $cfc_employer = self::round((float) ($sums['cfc_employer'] ?? 0));
        $fne_employer = self::round((float) ($sums['fne_employer'] ?? 0));

        $irpp = self::round($sums['irpp']);
        $cac  = self::round($sums['cac']);
        $cfc_e = self::round($sums['cfc_employee']);
        $crtv = self::round($sums['crtv']);
        $tdl  = self::round($sums['tdl']);
        $rav  = self::round($sums['rav']);

        $total = $irpp + $cac + $cfc_e + $cfc_employer + $crtv + $tdl + $rav + $fne_employer;

        return [
            'kind'        => 'irpp_dipe',
            'destination' => 'dgi',
            'period'      => sprintf('%04d-%02d', $year, $month),
            'lines'       => [
                ['line_code' => 'MASSE_SALARIALE',   'label' => 'Masse salariale brute',
                    'amount' => self::round($sums['gross_total']), 'is_credit' => 0, 'informational' => 1,
                    'source_ref' => "hr_payslips (year={$year} month={$month}): " . (int)$sums['payslip_count'] . ' bulletin(s)'],
                ['line_code' => 'IRPP',              'label' => 'IRPP retenu à la source',                    'amount' => $irpp,          'is_credit' => 0],
                ['line_code' => 'CAC',               'label' => "CAC (10 % de l'IRPP)",                       'amount' => $cac,           'is_credit' => 0],
                ['line_code' => 'CFC_SALARIE',       'label' => 'CFC part salariale (1 %)',                   'amount' => $cfc_e,         'is_credit' => 0],
                ['line_code' => 'CFC_PATRONAL',      'label' => 'CFC part patronale (1,5 %)',                 'amount' => $cfc_employer,  'is_credit' => 0,
                    'source_ref' => 'bulletins (masse_salariale × ' . ($cfc_employer_rate * 100) . '%)'],
                ['line_code' => 'CRTV',              'label' => 'Redevance audiovisuelle (barème)',           'amount' => $crtv,          'is_credit' => 0],
                ['line_code' => 'RAV',               'label' => 'Redevance audio-visuelle (autre)',           'amount' => $rav,           'is_credit' => 0],
                ['line_code' => 'TDL',               'label' => 'Taxe de Développement Local',                'amount' => $tdl,           'is_credit' => 0],
                ['line_code' => 'FNE_PATRONAL',      'label' => "FNE — Fonds National de l'Emploi (1 %)",     'amount' => $fne_employer,  'is_credit' => 0,
                    'source_ref' => 'bulletins (masse_salariale × ' . ($fne_rate * 100) . '%)'],
            ],
            'total_due' => $total,
            'credit'    => 0.0,
            'net'       => $total,
            'due_date'  => self::deadline($year, $month),
            'employee_count' => (int) $sums['payslip_count'],
        ];
    }

    // -------------------------------------------------------------------------
    // CNPS — Cotisations sociales (part salariale + part patronale)
    // -------------------------------------------------------------------------

    /**
     * CNPS declaration = PVID (pension) + PF (prestations familiales) +
     * AT (accident du travail). Filed with CNPS, not DGI. The base is the
     * gross salary CAPPED at the CNPS ceiling (currently 750 000 FCFA).
     *
     *   PVID = 8.4 %  (4.2 salarié / 4.2 patronal) — value: cnps_employee/employer
     *                  in Payroll::compute() aggregates PVID + PF + AT_share.
     *                  We split back here for the CNPS filing form.
     *   PF   = 7 %    (patronal only)
     *   AT   = 1.75 / 2.5 / 5 % according to sector group (patronal only)
     *
     * Because Payroll::compute stores the AGGREGATED employer share, we
     * decompose here using the CNPS ceiling × current rates for the audit
     * trail. The total number matches what payroll already withheld.
     */
    public static function computeCnps(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        $settings = self::settings();

        $sums = ['cnps_employee' => 0.0, 'cnps_employer' => 0.0, 'gross_total' => 0.0, 'plafonnee' => 0.0, 'payslip_count' => 0];
        if (self::tableExists($db, 'hr_payslips')) {
            $q = $db->prepare("
                SELECT COALESCE(SUM(cnps_employee),0) cnps_employee,
                       COALESCE(SUM(cnps_employer),0) cnps_employer,
                       COALESCE(SUM(gross_salary),0)  gross_total,
                       COALESCE(SUM(LEAST(gross_salary, :ceiling)), 0) AS plafonnee,
                       COUNT(*)                       payslip_count
                  FROM hr_payslips
                 WHERE year=:y AND month=:m AND status IN ('validated','paid')
            ");
            $ceiling = self::payrollRate($year, 'cnps_ceiling', 750000.0);
            $q->execute([':y' => $year, ':m' => $month, ':ceiling' => $ceiling]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row) foreach ($row as $k => $v) { $sums[$k] = (float) $v; }
        }

        // Decomposition of the employer share (audit only — total matches
        // what Payroll::compute already withheld).
        $pvid_emp_rate = self::payrollRate($year, 'cnps_employee_rate', 0.042);
        $pf_rate       = 0.07;            // Prestations Familiales, patronal
        $at_rate       = (float) $settings['at_rate'];

        // The lines add up to what the payslips booked on 433 (the declaration
        // pays it, Dr 433). The employee part is the payslips' own; the employer
        // part is PVID 4,2 % and PF 7 % of the capped base, and the accident du
        // travail is the rest of what the payroll charged (Comptabilité 3: the
        // payroll's 16,2 % implies AT 5 %, group C, while Paramètres say group A;
        // a « Écart » line used to show the 20 475 F difference unexplained).
        $pvid_salarie   = self::round((float) $sums['cnps_employee']);
        $pvid_patronal  = self::round((float) $sums['plafonnee'] * $pvid_emp_rate);   // 4.2 % pat
        $pf_patronal    = self::round((float) $sums['plafonnee'] * $pf_rate);
        $at_patronal    = self::round((float) $sums['cnps_employer'] - $pvid_patronal - $pf_patronal);
        $at_implied     = (float) $sums['plafonnee'] > 0 ? $at_patronal / (float) $sums['plafonnee'] : $at_rate;

        $stored_total = self::round((float) $sums['cnps_employee'] + (float) $sums['cnps_employer']);
        $total = $stored_total;   // what the payroll withheld and booked on 433

        $pct = fn (float $r) => number_format($r * 100, 2, ',', ' ');
        $lines = [
                ['line_code' => 'MASSE_SALARIALE',       'label' => 'Masse salariale brute',
                    'amount' => self::round((float) $sums['gross_total']), 'is_credit' => 0, 'informational' => 1,
                    'source_ref' => "hr_payslips: " . (int)$sums['payslip_count'] . ' bulletin(s)'],
                ['line_code' => 'MASSE_PLAFONNEE',       'label' => 'Masse plafonnée CNPS (750 000 / mois × effectif)',
                    'amount' => self::round((float) $sums['plafonnee']), 'is_credit' => 0, 'informational' => 1],
                ['line_code' => 'CNPS_PVID_SAL',         'label' => 'PVID part salariale (' . $pct($pvid_emp_rate) . ' %)',
                    'amount' => $pvid_salarie, 'is_credit' => 0],
                ['line_code' => 'CNPS_PVID_PAT',         'label' => 'PVID part patronale (' . $pct($pvid_emp_rate) . ' %)',
                    'amount' => $pvid_patronal, 'is_credit' => 0],
                ['line_code' => 'CNPS_PF',               'label' => 'Prestations Familiales patronales (7 %)',
                    'amount' => $pf_patronal, 'is_credit' => 0],
                ['line_code' => 'CNPS_AT',               'label' => 'Accident du Travail patronal (' . $pct($at_implied) . ' %, taux de la paie)',
                    'amount' => $at_patronal, 'is_credit' => 0],
        ];
        if ((float) $sums['plafonnee'] > 0 && abs($at_implied - $at_rate) > 0.0005) {
            $lines[] = ['line_code' => 'CNPS_AT_A_VERIFIER', 'label' => 'Taux AT de la paie ' . $pct($at_implied) . ' % ; Paramètres (groupe '
                    . $settings['cnps_group'] . ') : ' . $pct($at_rate) . ' %. À vérifier avec la CNPS.',
                'amount' => 0.0, 'is_credit' => 0, 'informational' => 1];
        }

        return [
            'kind'        => 'cnps',
            'destination' => 'cnps',
            'period'      => sprintf('%04d-%02d', $year, $month),
            'lines'       => $lines,
            'total_due' => $total,
            'credit'    => 0.0,
            'net'       => $total,
            'due_date'  => self::deadline($year, $month),
            'employee_count' => (int) $sums['payslip_count'],
        ];
    }

    // -------------------------------------------------------------------------
    // TSR — Taxe Spéciale sur le Revenu (services étrangers)
    // -------------------------------------------------------------------------

    public static function computeTsr(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        $total = 0.0; $base = 0.0; $count = 0;
        if (self::tableExists($db, 'supplier_invoices') && self::columnExists($db, 'supplier_invoices', 'foreign_service')) {
            $q = $db->prepare("
                SELECT COALESCE(SUM(subtotal * tsr_rate),0) AS tsr_amount,
                       COALESCE(SUM(subtotal), 0)          AS base,
                       COUNT(*)                            AS n
                  FROM supplier_invoices
                 WHERE foreign_service = 1 AND YEAR(date)=? AND MONTH(date)=?
            ");
            $q->execute([$year, $month]);
            $r = $q->fetch(PDO::FETCH_ASSOC) ?: ['tsr_amount' => 0, 'base' => 0, 'n' => 0];
            $total = self::round((float) $r['tsr_amount']);
            $base  = self::round((float) $r['base']);
            $count = (int) $r['n'];
        }
        return [
            'kind'        => 'tsr',
            'destination' => 'dgi',
            'period'      => sprintf('%04d-%02d', $year, $month),
            'lines'       => [
                ['line_code' => 'TSR_BASE',    'label' => 'Base — services étrangers',
                    'amount' => $base,  'is_credit' => 0, 'informational' => 1,
                    'source_ref' => "supplier_invoices(foreign_service=1): {$count} facture(s)"],
                ['line_code' => 'TSR_MONTANT', 'label' => 'TSR à reverser',
                    'amount' => $total, 'is_credit' => 0],
            ],
            'total_due' => $total, 'credit' => 0.0, 'net' => $total,
            'due_date'  => self::deadline($year, $month),
        ];
    }

    // -------------------------------------------------------------------------
    // COMMUNE — Taxe communale forfaitaire (mensuelle) + prorata patente
    // -------------------------------------------------------------------------

    public static function computeCommune(int $year, int $month): array
    {
        $s = self::settings();
        $monthly = self::round((float) $s['commune_monthly']);

        // 1/12ᵉ de la patente annuelle si un montant est configuré. Sinon on
        // n'ajoute rien ici — la patente réelle passe par computePatente
        // (annuelle, exigible en février).
        $patente_override = (float) $s['patente_override'];
        $patente_mensuel  = $patente_override > 0 ? self::round($patente_override / 12) : 0.0;

        $total = $monthly + $patente_mensuel;

        return [
            'kind'        => 'commune',
            'destination' => 'commune',
            'period'      => sprintf('%04d-%02d', $year, $month),
            'lines'       => [
                ['line_code' => 'COMMUNE_MONTHLY', 'label' => 'Taxe communale forfaitaire mensuelle',
                    'amount' => $monthly, 'is_credit' => 0,
                    'source_ref' => 'Prefs::tax_commune_monthly'],
                ['line_code' => 'PATENTE_MENSUEL', 'label' => 'Provision patente (1/12 du montant annuel configuré)',
                    'amount' => $patente_mensuel, 'is_credit' => 0,
                    'source_ref' => 'Prefs::tax_patente_annual / 12'],
            ],
            'total_due' => $total, 'credit' => 0.0, 'net' => $total,
            'due_date'  => self::deadline($year, $month),
        ];
    }

    // -------------------------------------------------------------------------
    // PATENTE — Annuelle (exigible février)
    // -------------------------------------------------------------------------

    public static function computePatente(int $year): array
    {
        $db = Database::getInstance()->getConnection();
        $q = $db->prepare("SELECT COALESCE(SUM(subtotal),0) FROM invoices WHERE YEAR(date)=?");
        $q->execute([$year - 1]);
        $prev_turnover = self::round((float) $q->fetchColumn());

        if     ($prev_turnover >= 3_000_000_000) { $rate = 0.00159; $min = 5_000_000;   $max = 2_500_000_000; $tier = 'DGE'; }
        elseif ($prev_turnover >= 50_000_000)    { $rate = 0.00283; $min = 141_500;     $max = 4_500_000;     $tier = 'CIME'; }
        else                                     { $rate = 0.00494; $min = 50_000;      $max = 140_000;       $tier = 'CDI';  }

        $raw = self::round($prev_turnover * $rate);
        $due = min($max, max($min, $raw));

        // Manual override — the DGI's actual notice may differ.
        $override = (float) (self::settings()['patente_override'] ?? 0);
        if ($override > 0) $due = self::round($override);

        return [
            'kind'        => 'patente',
            'destination' => 'commune',
            'period'      => sprintf('%04d', $year),
            'lines'       => [
                ['line_code' => 'CA_N-1',       'label' => "Chiffre d'affaires N-1", 'amount' => $prev_turnover, 'is_credit' => 0, 'informational' => 1],
                ['line_code' => 'TAUX',         'label' => "Taux patente ({$tier})", 'amount' => (float) ($rate * 100), 'is_credit' => 0, 'informational' => 1, 'unit' => '%'],
                ['line_code' => 'PATENTE_BRUT', 'label' => "Patente calculée",       'amount' => $raw, 'is_credit' => 0, 'informational' => 1],
                ['line_code' => 'PATENTE_DUE',  'label' => "Patente due (min/max encadrement" . ($override > 0 ? ' + override manuel' : '') . ")",
                    'amount' => $due, 'is_credit' => 0],
            ],
            'total_due' => $due, 'credit' => 0.0, 'net' => $due,
            'due_date'  => sprintf('%04d-02-28', $year),
        ];
    }

    // -------------------------------------------------------------------------
    // MONTHLY SUMMARY — bundles TVA + AIR + DIPE + CNPS + TSR + Commune
    // -------------------------------------------------------------------------

    /**
     * The Master Monthly Modal's data source. Runs every kind, tallies the
     * amounts per destination (DGI / CNPS / Commune), and returns the whole
     * package in one call. Individual failures are captured per kind so a
     * missing supplier_invoices table cannot break the AIR + DIPE view.
     */
    public static function computeMonthlySummary(int $year, int $month): array
    {
        $blocks = [];
        $totals = ['dgi' => 0.0, 'cnps' => 0.0, 'commune' => 0.0, 'grand' => 0.0];
        $errors = [];

        foreach (self::MONTHLY_KINDS as $kind) {
            try {
                $out = match ($kind) {
                    'tva'       => self::computeTva($year, $month),
                    'air'       => self::computeAir($year, $month),
                    'irpp_dipe' => self::computeDipe($year, $month),
                    'cnps'      => self::computeCnps($year, $month),
                    'tsr'       => self::computeTsr($year, $month),
                    'commune'   => self::computeCommune($year, $month),
                };
                $blocks[$kind] = $out;
                $dest = $out['destination'] ?? 'dgi';
                $totals[$dest] = ($totals[$dest] ?? 0.0) + (float) $out['net'];
                $totals['grand'] += (float) $out['net'];
            } catch (Throwable $e) {
                $errors[$kind] = $e->getMessage();
                error_log("TaxEngine::computeMonthlySummary({$year},{$month},{$kind}): " . $e->getMessage());
            }
        }

        // Round destination totals.
        foreach ($totals as $k => $v) $totals[$k] = self::round((float) $v);

        // Load any locked snapshots persisted for this month, so the modal
        // can render "Approuvée par Timothée le 15/08/2026 · PDF" per kind.
        $locks = self::loadLocksForPeriod($year, $month);

        return [
            'year'      => $year,
            'month'     => $month,
            'period'    => sprintf('%04d-%02d', $year, $month),
            'due_date'  => self::deadline($year, $month),
            'blocks'    => $blocks,
            'totals'    => $totals,
            'locks'     => $locks,
            'errors'    => $errors,
        ];
    }

    /**
     * Return persisted tax_declarations for the given period, keyed by kind.
     * Used by the monthly modal to render lock status + PDF link + payment
     * status per declaration.
     */
    public static function loadLocksForPeriod(int $year, int $month): array
    {
        $db = Database::getInstance()->getConnection();
        try {
            $q = $db->prepare("
                SELECT id, kind, status, computed_at, filed_at, paid_at, locked_at, locked_by,
                       total_due, total_credit, net_to_pay, snapshot_pdf_path, approval_hash,
                       reference, quittance_path, paid_amount, paid_reference,
                       payment_je_id, payable_je_id, treasury_account_id
                  FROM tax_declarations
                 WHERE period_year = ? AND period_month = ?
            ");
            $q->execute([$year, $month]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $rows = [];
        }
        $by = [];
        foreach ($rows as $r) $by[$r['kind']] = $r;
        return $by;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Deadline for monthly filings: the 15th of the following month (statutory). */
    public static function deadline(int $year, int $month): string
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

    /** FCFA rounding — no subunit, half-up. Kept public for the controller. */
    public static function round(float $x): float
    {
        return (float) round($x, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Pull a rate from payroll_rates for the given year, falling back to a
     * hard-coded default if the row is missing (bootstrap safety).
     * Cached per request.
     */
    private static function payrollRate(int $year, string $key, float $fallback): float
    {
        static $cache = [];
        $ck = "{$year}::{$key}";
        if (isset($cache[$ck])) return $cache[$ck];
        $db = Database::getInstance()->getConnection();
        try {
            $q = $db->prepare("SELECT rate_value FROM payroll_rates WHERE year=? AND rate_key=?");
            $q->execute([$year, $key]);
            $v = $q->fetchColumn();
            return $cache[$ck] = ($v !== false ? (float) $v : $fallback);
        } catch (Throwable $e) {
            return $cache[$ck] = $fallback;
        }
    }

    /** Existence checks — cached per request. Prevents SQL errors on partially-migrated DBs. */
    private static function tableExists(PDO $db, string $table): bool
    {
        static $cache = [];
        if (isset($cache[$table])) return $cache[$table];
        try {
            $q = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
            $q->execute([$table]);
            return $cache[$table] = ((int) $q->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$table] = false;
        }
    }
    private static function columnExists(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $k = "{$table}.{$column}";
        if (isset($cache[$k])) return $cache[$k];
        try {
            $q = $db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $q->execute([$table, $column]);
            return $cache[$k] = ((int) $q->fetchColumn() > 0);
        } catch (Throwable $e) {
            return $cache[$k] = false;
        }
    }
}
