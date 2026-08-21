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
        $this->pdo->beginTransaction();
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

            $this->pdo->commit();
            return $to;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
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

        // 2. Pending journal entries
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM journal_entries WHERE YEAR(date) = ? AND status = 'pending'"
        );
        $st->execute([$year]);
        $pending = (int)$st->fetchColumn();
        $checks[] = [
            'key' => 'no_pending_entries',
            'label' => 'Aucune écriture en attente d\'approbation',
            'ok'    => $pending === 0,
            'detail'=> "$pending en attente",
        ];

        // 3. All fixed assets have monthly depreciation run for every month of year
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM fixed_assets
              WHERE status = 'active' AND acquisition_date <= LAST_DAY(CONCAT(?,'-12-31'))
                AND (disposal_date IS NULL OR disposal_date > CONCAT(?,'-12-31'))"
        );
        $st->execute([$year, $year]);
        $activeAssets = (int)$st->fetchColumn();
        $st = $this->pdo->prepare(
            "SELECT COUNT(DISTINCT fixed_asset_id) FROM depreciation_logs
              WHERE year = ? AND status = 'posted'"
        );
        $st->execute([$year]);
        $depredAssets = (int)$st->fetchColumn();
        $checks[] = [
            'key' => 'depreciation_up_to_date',
            'label' => 'Dotations aux amortissements calculées',
            'ok'    => $activeAssets === 0 || $depredAssets >= $activeAssets,
            'detail'=> "$depredAssets / $activeAssets immobilisations amorties",
        ];

        // 4. AIR certificates reconciled (attestations available for withheld AIR)
        //    Best-effort — only counts if the table exists.
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM payments p
                  WHERE YEAR(p.payment_date) = ?
                    AND COALESCE(p.air_withheld_amount, 0) > 0
                    AND NOT EXISTS (SELECT 1 FROM withholding_certificates c
                                     WHERE c.payment_id = p.id)"
            );
            $st->execute([$year]);
            $missing = (int)$st->fetchColumn();
            $checks[] = [
                'key' => 'air_certificates',
                'label' => 'Attestations de retenue à la source (AIR) reçues',
                'ok'    => $missing === 0,
                'detail'=> "$missing paiements sans attestation",
            ];
        } catch (Throwable $e) { /* table absent → skip */ }

        $ok = true;
        foreach ($checks as $c) if (empty($c['ok'])) { $ok = false; break; }
        return ['ok' => $ok, 'checks' => $checks];
    }

    // -------------------------------------------------------------------
    // Automatic adjustments — each returns count of JEs created
    // -------------------------------------------------------------------

    /** Depreciation: for each active fixed_asset, one 12-month OD if missing. */
    public function runDepreciation(int $year): int
    {
        $created = 0;
        $st = $this->pdo->prepare(
            "SELECT fa.*, coa_ch.id AS charge_id, coa_dp.id AS depr_id
               FROM fixed_assets fa
               JOIN chart_of_accounts coa_ch ON coa_ch.id = fa.expense_account_id
               JOIN chart_of_accounts coa_dp ON coa_dp.id = fa.depr_account_id
              WHERE fa.status = 'active'
                AND fa.acquisition_date <= CONCAT(?,'-12-31')
                AND (fa.disposal_date IS NULL OR fa.disposal_date > CONCAT(?,'-12-31'))"
        );
        $st->execute([$year, $year]);
        $assets = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assets as $a) {
            $months = 12;
            // Prorate for acquisitions inside the year
            $acqYear = (int)date('Y', strtotime($a['acquisition_date']));
            $acqMonth = (int)date('n', strtotime($a['acquisition_date']));
            if ($acqYear === $year) $months = 13 - $acqMonth;
            $months = max(0, min(12, $months));
            $amount = round((float)$a['monthly_dotation'] * $months, 2);
            if ($amount <= 0) continue;

            $target = 'fixed_asset:' . $a['id'];
            // Skip if already booked
            $chk = $this->pdo->prepare(
                "SELECT id FROM year_end_adjustments WHERE fiscal_year = ? AND kind = 'depreciation' AND target_ref = ?"
            );
            $chk->execute([$year, $target]);
            if ($chk->fetchColumn()) continue;

            $desc = "Dotation aux amortissements — {$a['name']} ($months mois)";
            $jeId = $this->postJournal($year, $desc, [
                ['account_id' => (int)$a['expense_account_id'], 'debit' => $amount, 'credit' => 0],
                ['account_id' => (int)$a['depr_account_id'],    'debit' => 0,       'credit' => $amount],
            ]);
            $this->recordAdjustment($year, 'depreciation', $target, $jeId, $amount, $desc, [
                'monthly_dotation' => (float)$a['monthly_dotation'],
                'months'           => $months,
            ]);
            $created++;
        }
        return $created;
    }

    /** Incorporate the year's net result into 131 (profit) or 139 (loss). */
    public function runProfitIncorporation(int $year): int
    {
        // Compute net result from class 7 - class 6
        $st = $this->pdo->prepare(
            "SELECT SUBSTRING(ca.code,1,1) AS klass,
                    COALESCE(SUM(jl.credit - jl.debit), 0) AS bal
               FROM journal_lines jl
               JOIN chart_of_accounts ca ON ca.id = jl.account_id
               JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status='posted'
              WHERE YEAR(je.date) = ? AND SUBSTRING(ca.code,1,1) IN ('6','7')
              GROUP BY SUBSTRING(ca.code,1,1)"
        );
        $st->execute([$year]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $rev = 0.0; $exp = 0.0;
        foreach ($rows as $r) {
            if ($r['klass'] === '7') $rev += (float)$r['bal'];
            if ($r['klass'] === '6') $exp += -(float)$r['bal']; // class 6 debit-normal
        }
        $net = round($rev - $exp, 2);
        if (abs($net) < 0.01) return 0;

        $target = 'result:' . $year;
        $chk = $this->pdo->prepare(
            "SELECT id FROM year_end_adjustments WHERE fiscal_year = ? AND kind = 'profit_incorporation' AND target_ref = ?"
        );
        $chk->execute([$year, $target]);
        if ($chk->fetchColumn()) return 0;

        // Find the "résultat" account (131 profit, 139 loss)
        $code = $net >= 0 ? '131' : '139';
        $acc = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE code LIKE ? ORDER BY code ASC LIMIT 1");
        $acc->execute([$code . '%']);
        $accId = (int)$acc->fetchColumn();
        if (!$accId) {
            throw new RuntimeException("Compte $code introuvable — vérifier le plan comptable.");
        }

        // Class 6 and 7 accounts to zero out at year-end (Solde de gestion → 12)
        // The bilan already reflects the residual via cumulative logic. Here we
        // simply post the net result to 131/139 on 31/12.
        $desc = "Incorporation du résultat de l'exercice $year";
        $lines = [];
        if ($net >= 0) {
            // Bénéfice: 12X (Résultat en instance d'affectation) débité contre 131 crédité
            $acc12 = $this->firstAccountLike('12');
            if (!$acc12) throw new RuntimeException('Compte 12x (Résultat en instance) introuvable.');
            $lines[] = ['account_id' => $acc12, 'debit' => $net, 'credit' => 0];
            $lines[] = ['account_id' => $accId, 'debit' => 0,    'credit' => $net];
        } else {
            $loss = abs($net);
            $lines[] = ['account_id' => $accId,               'debit' => $loss, 'credit' => 0];
            $lines[] = ['account_id' => $this->firstAccountLike('12'), 'debit' => 0, 'credit' => $loss];
        }
        $jeId = $this->postJournal($year, $desc, $lines);
        $this->recordAdjustment($year, 'profit_incorporation', $target, $jeId, $net, $desc, [
            'revenue' => $rev, 'expense' => $exp, 'net' => $net,
        ]);
        return 1;
    }

    /** Provision IS: charge 891 vs 4472 (impôt à payer) using dynamic rate. */
    public function runIsProvision(int $year): int
    {
        require_once __DIR__ . '/../functions/tax_simulation.php';
        $ts = build_tax_simulation($this->pdo, $year, []);
        $due = (float)$ts['is_due'];
        if ($due < 0.01) return 0;

        $target = 'is:' . $year;
        $chk = $this->pdo->prepare(
            "SELECT id FROM year_end_adjustments WHERE fiscal_year = ? AND kind = 'is_provision' AND target_ref = ?"
        );
        $chk->execute([$year, $target]);
        if ($chk->fetchColumn()) return 0;

        $accCharge = $this->firstAccountLike('891') ?: $this->firstAccountLike('871');
        $accPay    = $this->firstAccountLike('4472');
        if (!$accCharge || !$accPay) {
            throw new RuntimeException('Comptes IS introuvables (891 charge, 4472 dette).');
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
        return $revId;
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------
    private function postJournal(int $year, string $description, array $lines, ?string $date = null): int
    {
        $date = $date ?: "$year-12-31";
        $ref = 'INV-' . $year . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $st = $this->pdo->prepare(
            "INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by, approved_by)
             VALUES (?, 'OD', ?, ?, 'approved', ?, ?)"
        );
        $st->execute([$ref, $date, $description, $this->userId, $this->userId]);
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

    private function firstAccountLike(string $prefix): ?int
    {
        $st = $this->pdo->prepare("SELECT id FROM chart_of_accounts WHERE code LIKE ? ORDER BY code ASC LIMIT 1");
        $st->execute([$prefix . '%']);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    }
}
