<?php
/**
 * includes/classes/YearEndClosing.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — SYSCOHADA year-end closing engine.
 *
 * Runs every automatic inventory adjustment for a given fiscal year and drives
 * the state machine defined in migration 088. Consumed by:
 *
 *   • modules/accounting/closing_wizard.php  (UI)
 *   • api/v1/financials_controller.php       (POST actions)
 *
 * State machine (see migration 088):
 *
 *   open ──▶ inventory ──▶ adjustments ──▶ provisional_closed ──▶ locked
 *                                                              ╲     │
 *                                                               ╲    ▼
 *                                                                ▶ final_closed
 *
 *   Reverse transitions:
 *     provisional_closed → adjustments        (admin, before final)
 *     locked             → adjustments        (DG only, with reason logged)
 *     final_closed       → (irreversible; a re-open creates a corrective FY)
 *
 * ATOMICITY
 *   Every phase runs in its own transaction. Each generated JE (depreciation,
 *   provision, accrual, IS provision, profit incorporation) is:
 *     · balanced (debit total == credit total, guarded by migration 004),
 *     · idempotent per (year, kind, target) via the UNIQUE key on
 *       year_end_adjustments,
 *     · reversible from the UI (record.reversal_journal_entry_id).
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../functions/books_period.php';

class YearEndClosing
{
    /** @var PDO */ private $pdo;
    /** @var int */ private $userId;

    public function __construct(PDO $pdo, int $userId)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
    }

    // -------------------------------------------------------------------
    // State transitions
    // -------------------------------------------------------------------

    /** Move a year to a new state, log the transition. Returns the new state. */
    public function transition(int $year, string $to, ?string $note = null): string
    {
        $allowed = [
            'open'               => ['inventory'],
            'inventory'          => ['adjustments','open'],
            'adjustments'        => ['provisional_closed','inventory'],
            'provisional_closed' => ['adjustments','locked','final_closed'],
            'locked'             => ['adjustments','final_closed'],
            'final_closed'       => [], // terminal
        ];
        $own = !$this->pdo->inTransaction();
        if ($own) $this->pdo->beginTransaction();
        try {
            // Ensure the row exists
            $this->pdo->prepare("INSERT IGNORE INTO financial_years (year, status, state) VALUES (?, 'open', 'open')")
                      ->execute([$year]);
            $st = $this->pdo->prepare("SELECT state FROM financial_years WHERE year = ? FOR UPDATE");
            $st->execute([$year]);
            $from = (string)$st->fetchColumn() ?: 'open';

            if (!isset($allowed[$from]) || !in_array($to, $allowed[$from], true)) {
                throw new RuntimeException("Transition refusée: $from → $to");
            }

            // Audit columns
            $cols = ['state = ?', 'notes = COALESCE(NULLIF(TRIM(?), \'\'), notes)'];
            $vals = [$to, $note ?? ''];
            switch ($to) {
                case 'inventory':          $cols[] = 'inventory_started_at    = CURRENT_TIMESTAMP'; break;
                case 'adjustments':        $cols[] = 'adjustments_started_at  = CURRENT_TIMESTAMP'; break;
                case 'provisional_closed':
                    $cols[] = 'provisional_closed_at = CURRENT_TIMESTAMP';
                    $cols[] = 'provisional_closed_by = ?'; $vals[] = $this->userId;
                    break;
                case 'locked':
                    $cols[] = 'locked_at = CURRENT_TIMESTAMP';
                    $cols[] = 'locked_by = ?'; $vals[] = $this->userId;
                    break;
                case 'final_closed':
                    $cols[] = 'final_closed_at = CURRENT_TIMESTAMP';
                    $cols[] = 'final_closed_by = ?'; $vals[] = $this->userId;
                    $cols[] = "status = 'closed'"; // legacy alias
                    break;
            }
            $vals[] = $year;
            $sql = 'UPDATE financial_years SET ' . implode(', ', $cols) . ' WHERE year = ?';
            $this->pdo->prepare($sql)->execute($vals);

            if ($own) $this->pdo->commit();
            return $to;
        } catch (Throwable $e) {
            if ($own && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function getState(int $year): array
    {
        $st = $this->pdo->prepare("SELECT * FROM financial_years WHERE year = ?");
        $st->execute([$year]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ?: ['year' => $year, 'state' => 'open', 'status' => 'open'];
    }

    // -------------------------------------------------------------------
    // Preflight — checks that must pass before provisional_closed
    // -------------------------------------------------------------------
    public function preflight(int $year): array
    {
        $checks = [];

        // 1. Balance sheet ties
        $bal = $this->pdo->prepare(
            "SELECT COALESCE(SUM(debit),0) AS d, COALESCE(SUM(credit),0) AS c
               FROM journal_lines jl
               JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
              WHERE YEAR(je.date) = ?"
        );
        $bal->execute([$year]);
        $r = $bal->fetch(PDO::FETCH_ASSOC) ?: ['d' => 0, 'c' => 0];
        $delta = round((float)$r['d'] - (float)$r['c'], 2);
        $checks[] = [
            'key' => 'balanced_entries',
            'label' => 'Écritures équilibrées (débit = crédit)',
            'ok'    => abs($delta) < 0.01,
            'detail'=> abs($delta) < 0.01 ? 'OK' : "Écart de $delta FCFA",
        ];

        // 2. Drafts still waiting in Écritures → File d'Attente. The status
        //    vocabulary is draft / posted / reversed (migration 039): there
        //    has never been a 'pending', so this check always read 0
        //    (VIDEO_QA_FINDINGS 11.1).
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE YEAR(date) = ? AND status = 'draft'"
        );
        $st->execute([$year]);
        $pending = (int)$st->fetchColumn();
        $checks[] = [
            'key' => 'no_pending_entries',
            'label' => 'Aucun brouillard en attente de validation (Écritures)',
            'ok'    => $pending === 0,
            'detail'=> $pending === 0 ? 'OK' : "$pending brouillard(s) à valider ou supprimer",
        ];

        // 3. Every month of the year depreciated for every asset in service (a monthly
        //    run or the closing's depreciation phase). One month logged used to pass.
        $st = $this->pdo->prepare(
            "SELECT * FROM fixed_assets
              WHERE status = 'active'
                AND COALESCE(service_start_date, acquisition_date) <= CONCAT(?,'-12-31')
                AND (disposal_date IS NULL OR disposal_date > CONCAT(?,'-12-31'))"
        );
        $st->execute([$year, $year]);
        $activeAssets = 0; $missingMonths = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $activeAssets++;
            $missingMonths += count(self::missingDepreciationMonths($this->pdo, $a, $year));
        }
        $checks[] = [
            'key' => 'depreciation_up_to_date',
            'label' => 'Dotations aux amortissements calculées',
            'ok'    => $missingMonths === 0,
            'detail'=> $missingMonths === 0
                ? "$activeAssets immobilisation(s), tous les mois passés"
                : "$missingMonths mois non passés sur $activeAssets immobilisation(s)",
        ];

        // 4. AIR certificates reconciled (attestations available for withheld AIR)
        //    Best-effort — only counts if the table exists.
        try {
            // The payment points at its attestation (payments.withholding_certificate_id);
            // the check looked for a withholding_certificates.payment_id that does
            // not exist, failed, and was silently skipped.
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM payments p
                  WHERE YEAR(p.payment_date) = ?
                    AND COALESCE(p.air_withheld_amount, 0) > 0
                    AND p.status = 'validated'
                    AND p.withholding_certificate_id IS NULL"
            );
            $st->execute([$year]);
            $missing = (int)$st->fetchColumn();
            $checks[] = [
                'key' => 'air_certificates',
                'label' => 'Attestations de retenue à la source (AIR) reçues',
                'ok'    => $missing === 0,
                'detail'=> $missing === 0 ? 'OK' : "$missing paiement(s) sans attestation",
            ];
        } catch (Throwable $e) { /* table absent → skip */ }

        // 5. The year's tax and social declarations, approved and paid. Unpaid
        //    ones are debts the Bilan shows; the check says which.
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM tax_declarations
                  WHERE period_year = ? AND status <> 'paid' AND net_to_pay > 0"
            );
            $st->execute([$year]);
            $open = (int) $st->fetchColumn();
            $checks[] = [
                'key' => 'tax_declarations_paid',
                'label' => 'Déclarations fiscales et sociales de l\'année payées',
                'ok'    => $open === 0,
                'detail'=> $open === 0 ? 'OK' : "$open déclaration(s) approuvée(s) ou calculée(s) non payée(s)",
            ];
        } catch (Throwable $e) { /* table absent → skip */ }

        $ok = true;
        foreach ($checks as $c) if (empty($c['ok'])) { $ok = false; break; }
        return ['ok' => $ok, 'checks' => $checks];
    }

    // -------------------------------------------------------------------
    // Automatic adjustments — each returns count of JEs created
    // -------------------------------------------------------------------

    /**
     * Depreciation at the closing: complete the year, never double it
     * (VIDEO_QA_FINDINGS 12.2). The monthly runs (Immobilisations → Dotations
     * Mensuelles) log each asset's month in depreciation_logs. This phase books
     * only the months of the year no run has logged, each computed as the monthly
     * run would (Depreciation::monthly: first month pro rata, capped at the
     * depreciable base), as one entry per asset, and logs those months so the
     * register and a later run see them. A year whose months were all run books
     * nothing here. It used to book « monthly_dotation × months since acquisition »
     * on top of the monthly runs: the year's depreciation counted twice.
     */
    public function runDepreciation(int $year): int
    {
        require_once __DIR__ . '/Depreciation.php';
        $created = 0;
        $st = $this->pdo->prepare(
            "SELECT fa.*
               FROM fixed_assets fa
              WHERE fa.status = 'active'
                AND COALESCE(fa.service_start_date, fa.acquisition_date) <= CONCAT(?,'-12-31')
                AND (fa.disposal_date IS NULL OR fa.disposal_date > CONCAT(?,'-12-31'))"
        );
        $st->execute([$year, $year]);
        $assets = $st->fetchAll(PDO::FETCH_ASSOC);

        $log = $this->pdo->prepare(
            "INSERT INTO depreciation_logs
                (fixed_asset_id, month, year, amount, proration_days, amount_calculation, status, run_by, journal_entry_id)
             VALUES (?, ?, ?, ?, ?, ?, 'posted', ?, ?)"
        );
        foreach ($assets as $a) {
            $target = 'fixed_asset:' . $a['id'];
            $chk = $this->pdo->prepare(
                "SELECT id FROM year_end_adjustments WHERE fiscal_year = ? AND kind = 'depreciation' AND target_ref = ?"
            );
            $chk->execute([$year, $target]);
            if ($chk->fetchColumn()) continue;

            $missing = self::missingDepreciationMonths($this->pdo, $a, $year);
            $amount  = array_sum(array_column($missing, 'amount'));
            if ($amount <= 0) continue;
            if (empty($a['expense_account_id']) || empty($a['depr_account_id'])) {
                throw new RuntimeException("Immobilisation « {$a['name']} » : comptes 68x / 28x non renseignés.");
            }

            $months = array_column($missing, 'month');
            $desc = "Dotation aux amortissements · {$a['name']} (mois non passés : "
                  . implode(', ', array_map(fn ($m) => sprintf('%02d', $m), $months)) . "/$year)";
            $jeId = $this->postJournal($year, $desc, [
                ['account_id' => (int)$a['expense_account_id'], 'debit' => $amount, 'credit' => 0],
                ['account_id' => (int)$a['depr_account_id'],    'debit' => 0,       'credit' => $amount],
            ]);
            foreach ($missing as $mm) {
                $log->execute([$a['id'], $mm['month'], $year, $mm['amount'], $mm['proration_days'],
                               json_encode($mm['calculation'], JSON_UNESCAPED_UNICODE), $this->userId, $jeId]);
            }
            $this->recordAdjustment($year, 'depreciation', $target, $jeId, $amount, $desc, [
                'months'   => $months,
                'by_month' => array_combine($months, array_column($missing, 'amount')),
            ]);
            $created++;
        }
        return $created;
    }

    /**
     * The months of $year an asset has no depreciation_logs row for, each with
     * what the monthly run would book: [['month','amount','proration_days',
     * 'calculation'], …], in calendar order. Months worth nothing (not yet in
     * service, fully depreciated) are left out.
     */
    public static function missingDepreciationMonths(PDO $pdo, array $asset, int $year): array
    {
        require_once __DIR__ . '/Depreciation.php';
        $logs = $pdo->prepare("SELECT year, month, amount FROM depreciation_logs WHERE fixed_asset_id = ?");
        $logs->execute([(int) $asset['id']]);
        $accumulated = 0.0;   // booked before this year: counts towards the cap
        $thisYear = [];       // month => amount already logged this year
        foreach ($logs->fetchAll(PDO::FETCH_ASSOC) as $l) {
            if ((int) $l['year'] < $year)   $accumulated += (float) $l['amount'];
            if ((int) $l['year'] === $year) $thisYear[(int) $l['month']] = (float) $l['amount'];
        }

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            if (array_key_exists($m, $thisYear)) { $accumulated += $thisYear[$m]; continue; }
            $row = $asset;
            $row['accumulated'] = $accumulated;
            $r = Depreciation::monthly($row, new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m)));
            $amt = (float) $r['amount'];
            if ($amt <= 0) continue;
            $out[] = ['month' => $m, 'amount' => $amt, 'proration_days' => (int) $r['proration_days'], 'calculation' => $r['calculation']];
            $accumulated += $amt;
        }
        return $out;
    }

    /**
     * The year's result: classes 7 and 8 against classes 6 and 8, over the
     * entries the year's balances count (VIDEO_QA_FINDINGS 13.5). Positive is a
     * profit. Class 8 (HAO, the IS provision on 89x) used to be left out.
     */
    public function yearResult(int $year): float
    {
        [$where, $params] = lpc_books_cumulative_where($this->pdo, $year, 'je');
        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
               FROM journal_lines jl
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
               JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
              WHERE SUBSTRING(ca.code, 1, 1) IN ('6','7','8') AND $where"
        );
        $st->execute($params);
        return round((float) $st->fetchColumn(), 2);
    }

    /**
     * « Affectation du résultat », the third phase. It posted Dr 12x / Cr 131 on
     * 31/12, which the Bilan then counted on top of the year's result (and the
     * À-nouveaux a third time). The result now reaches 131 (bénéfice) or 139
     * (perte) once, in the À-nouveaux of 01/01 N+1 that « Clôture définitive »
     * posts; this phase says what it will be.
     */
    public function resultSummary(int $year): array
    {
        $r = $this->yearResult($year);
        $acc = $r >= 0 ? '131' : '139';
        $what = $r >= 0 ? 'bénéfice' : 'perte';
        $msg = sprintf("Résultat %d : %s de %s F. Il passera en %s (%s) dans les À-nouveaux du 01/01/%d, à la clôture définitive. Aucune écriture au 31/12.",
            $year, $what, number_format(abs($r), 0, ',', ' '), $acc, $r >= 0 ? 'Résultat net : bénéfice' : 'Résultat net : perte', $year + 1);
        return ['result' => $r, 'account' => $acc, 'message' => $msg];
    }

    /**
     * « Clôture définitive »: the À-nouveaux of $year + 1 and the state
     * final_closed, in one transaction. The year must be provisionally closed
     * (or locked) first. The À-nouveaux (reference AN-YYYY, 01/01 N+1) carry
     * every class 1 to 5 account's balance at the end of $year and the year's
     * result on 131 / 139; from then on the statements of later years count
     * from it (lpc_books_start_year), never the history again.
     */
    public function closeDefinitively(int $year): array
    {
        if ($year < 2000) throw new RuntimeException('Exercice invalide.');
        $state = (string) ($this->getState($year)['state'] ?? 'open');
        if ($state === 'final_closed') throw new RuntimeException("L'exercice $year est déjà clôturé définitivement.");
        if (!in_array($state, ['provisional_closed', 'locked'], true)) {
            throw new RuntimeException("Passez d'abord l'exercice $year en « Clôture provisoire » (état actuel : $state).");
        }
        $next = $year + 1;
        $ref  = "AN-$next";
        $dup = $this->pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE reference = ?");
        $dup->execute([$ref]);
        if ((int) $dup->fetchColumn() > 0) throw new RuntimeException("Les À-nouveaux $ref existent déjà.");

        [$where, $params] = lpc_books_cumulative_where($this->pdo, $year, 'je');
        $st = $this->pdo->prepare(
            "SELECT ca.id, ca.code, SUM(jl.debit - jl.credit) AS net
               FROM journal_lines jl
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
               JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
              WHERE SUBSTRING(ca.code, 1, 1) IN ('1','2','3','4','5') AND $where
              GROUP BY ca.id, ca.code
             HAVING ABS(net) >= 0.005"
        );
        $st->execute($params);
        $balances = $st->fetchAll(PDO::FETCH_ASSOC);

        $result = $this->yearResult($year);
        $resAcc = lpc_books_account($this->pdo, $result >= 0 ? '131' : '139');
        if (!$resAcc) throw new RuntimeException('Comptes 131 / 139 introuvables : vérifier le plan OHADA.');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, source_type, source_id)
                 VALUES (?, 'OD', ?, ?, 'draft', ?, 'year_opening', ?)"
            )->execute([$ref, "$next-01-01", "À-nouveaux (ouverture de l'exercice $next)", $this->userId, $next]);
            $anId = (int) $this->pdo->lastInsertId();
            $ln = $this->pdo->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)");
            foreach ($balances as $b) {
                $net = round((float) $b['net'], 2);
                $ln->execute([$anId, (int) $b['id'], $net > 0 ? $net : 0, $net < 0 ? -$net : 0]);
            }
            if (abs($result) >= 0.005) {
                $ln->execute([$anId, $resAcc, $result < 0 ? -$result : 0, $result > 0 ? $result : 0]);
            }
            $this->pdo->prepare("CALL post_journal_entry(?, ?)")->execute([$anId, $this->userId]);

            // The À-nouveaux are the NEXT year's opening entry (Bilan
            // d'Ouverture reads them there); the closed year keeps its own.
            $this->pdo->prepare("INSERT INTO financial_years (year, status, state, opening_balance_journal_id) VALUES (?, 'open', 'open', ?)
                                 ON DUPLICATE KEY UPDATE opening_balance_journal_id = VALUES(opening_balance_journal_id)")
                      ->execute([$next, $anId]);
            $this->pdo->prepare("UPDATE financial_years SET net_result_snapshot = ?, locked_by = COALESCE(locked_by, ?),
                                        locked_at = COALESCE(locked_at, CURRENT_TIMESTAMP) WHERE year = ?")
                      ->execute([$result, $this->userId, $year]);
            $this->transition($year, 'final_closed', "À-nouveaux $ref");
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['an_entry_id' => $anId, 'result' => $result, 'result_account' => $result >= 0 ? '131' : '139',
                'accounts' => count($balances)];
    }

    /** Provision IS: charge 891 vs 4472 (impôt à payer) using dynamic rate. */
    public function runIsProvision(int $year): int
    {
        require_once __DIR__ . '/../functions/tax_simulation.php';
        $ts = build_tax_simulation($this->pdo, $year, []);
        $due = round((float)$ts['is_due'], 0);   // FCFA: whole francs (9 809,8 became 9 809,80 in 4472)
        if ($due < 1) return 0;

        $target = 'is:' . $year;
        $chk = $this->pdo->prepare(
            "SELECT id FROM year_end_adjustments WHERE fiscal_year = ? AND kind = 'is_provision' AND target_ref = ?"
        );
        $chk->execute([$year, $target]);
        if ($chk->fetchColumn()) return 0;

        $accCharge = lpc_books_first_account($this->pdo, ['891', '89']);
        $accPay    = lpc_books_account($this->pdo, '4472');
        if (!$accCharge || !$accPay) {
            throw new RuntimeException('Comptes IS introuvables (89 charge, 4472 dette) dans le plan OHADA.');
        }
        $desc = "Provision IS $year — " . $ts['label'];
        $jeId = $this->postJournal($year, $desc, [
            ['account_id' => $accCharge, 'debit' => $due, 'credit' => 0],
            ['account_id' => $accPay,    'debit' => 0,    'credit' => $due],
        ]);
        $this->recordAdjustment($year, 'is_provision', $target, $jeId, $due, $desc, [
            'regime'   => $ts['regime'],
            'cit_rate' => $ts['cit_rate'],
            'air_rate' => $ts['air_rate'],
        ]);
        return 1;
    }

    // -------------------------------------------------------------------
    // Reversal — for each adjustment JE, post a contrepassation on 01/01 N+1
    // -------------------------------------------------------------------
    public function reverseAdjustment(int $adjustmentId): int
    {
        $st = $this->pdo->prepare("SELECT * FROM year_end_adjustments WHERE id = ?");
        $st->execute([$adjustmentId]);
        $adj = $st->fetch(PDO::FETCH_ASSOC);
        if (!$adj) throw new RuntimeException('Adjustment introuvable');
        if ($adj['reversal_journal_entry_id']) throw new RuntimeException('Déjà extourné');
        if (!$adj['journal_entry_id']) throw new RuntimeException('Aucune écriture à extourner');

        // Copy lines with debit ↔ credit flipped, dated N+1 01/01
        $lines = $this->pdo->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = ?");
        $lines->execute([$adj['journal_entry_id']]);
        $flipped = [];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $flipped[] = [
                'account_id' => (int)$l['account_id'],
                'debit'      => (float)$l['credit'],
                'credit'     => (float)$l['debit'],
            ];
        }
        $nextYear = (int)$adj['fiscal_year'] + 1;
        $desc = 'Extourne écriture d\'inventaire #' . $adj['journal_entry_id'];
        $revId = $this->postJournal($nextYear, $desc, $flipped, "$nextYear-01-01");

        $this->pdo->prepare(
            "UPDATE year_end_adjustments SET reversal_journal_entry_id = ?, status = 'reversed' WHERE id = ?"
        )->execute([$revId, $adjustmentId]);
        // A reversed closing depreciation leaves its months to depreciate again
        // (VIDEO_QA_FINDINGS 12.7): they stayed logged, so no run booked them.
        if ($adj['kind'] === 'depreciation') {
            $this->pdo->prepare("DELETE FROM depreciation_logs WHERE journal_entry_id = ?")
                      ->execute([(int) $adj['journal_entry_id']]);
        }
        return $revId;
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------
    private function postJournal(int $year, string $description, array $lines, ?string $date = null): int
    {
        $date = $date ?: "$year-12-31";
        $ref = 'INV-' . $year . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        // Draft, lines, then post_journal_entry (balance check, status 'posted'), as
        // every other posting does. It inserted status 'approved', which migration 039
        // removed from the column: the entries never reached the ledger as posted.
        $st = $this->pdo->prepare(
            "INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, approved_by)
             VALUES (?, 'OD', ?, ?, 'draft', ?, ?)"
        );
        $st->execute([$ref, $date, mb_substr($description, 0, 255), $this->userId, $this->userId]);
        $jeId = (int)$this->pdo->lastInsertId();

        $ln = $this->pdo->prepare(
            "INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)"
        );
        $sumD = 0; $sumC = 0;
        foreach ($lines as $l) {
            $ln->execute([$jeId, $l['account_id'], $l['debit'], $l['credit']]);
            $sumD += (float)$l['debit']; $sumC += (float)$l['credit'];
        }
        if (round($sumD - $sumC, 2) !== 0.00) {
            throw new RuntimeException(sprintf('Écriture non équilibrée: D=%.2f C=%.2f', $sumD, $sumC));
        }
        $this->pdo->prepare("CALL post_journal_entry(?, ?)")->execute([$jeId, $this->userId]);
        return $jeId;
    }

    private function recordAdjustment(int $year, string $kind, string $target, int $jeId,
                                      float $amount, string $desc, array $calc): void
    {
        $st = $this->pdo->prepare(
            "INSERT INTO year_end_adjustments
                (fiscal_year, kind, target_ref, journal_entry_id, amount, description, calculation_json, created_by, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'posted')"
        );
        $st->execute([$year, $kind, $target, $jeId, $amount, $desc, json_encode($calc, JSON_UNESCAPED_UNICODE), $this->userId]);
    }
}
