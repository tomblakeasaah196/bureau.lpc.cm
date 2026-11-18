<?php
/**
 * includes/classes/Loans.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP · « Emprunts & Financements », a tab of Trésorerie & Banque
 * (the owner's decision 15, 18/11/2026).
 *
 * LPC borrows from its directors, a related company (Smart Logistics, owned by
 * LPC's MD), banks, tontines and cooperatives, and lends too. Lean on purpose:
 * three tables (loan_counterparties, loans, loan_movements, migration 144).
 *
 * THE RULES
 *   · The CF, the Comptable and the DG record (accounting.loans.record); the DG
 *     approves (accounting.loans.approve). NOTHING POSTS before the DG approves
 *     a loan or a repayment: no journal line, no treasury movement.
 *   · Each counterparty has its own sub-account, per direction:
 *       LPC borrows: director/associate 4621, related company 466, bank 162,
 *                    tontine/cooperative 168, other 168;
 *       LPC lends:   related company 466 (a group current account in debit),
 *                    anyone else 271 « Prêts et créances non commerciales ».
 *     A loan never touches a client's 411 or a supplier's 401: Smart Logistics'
 *     loans stay apart from what it owes as a client, never offset.
 *   · Terms: no interest (most), a fixed monthly rate on the principal still
 *     owed ('simple'), or a compound monthly rate on principal + unpaid
 *     interest ('compound'); an optional one-off fee, kept out of the money
 *     paid over (LPC borrows: 631 Frais bancaires; LPC lends: 771).
 *   · Interest: one movement a month, on the loan date's day (the 1st month
 *     after the loan date, the 2nd…), posted on the approved terms without a
 *     new approval: LPC borrows Dr 671 / Cr the lender's account; LPC lends
 *     Dr the borrower's account / Cr 771. It is added to what is owed.
 *   · Repayments: any amount, any day, interest first then principal; the DG
 *     approves each. LPC repays: Dr the lender's account / Cr the treasury
 *     account; LPC is repaid: Dr the treasury account / Cr the borrower's.
 *   · Funds already on an account as its opening balance (in 471 since
 *     migration 139): approving the loan moves them from 471 to the lender's
 *     account (Dr 471 / Cr 466…) and moves no money (the Smart Logistics loan).
 *   · Reminders to the DG and the CF: seven days before a due date, and once
 *     it has passed, while something is still owed.
 * runDue() does the months and the reminders; the tab runs it when it loads,
 * and scripts/cron/loans_due.php can run it nightly.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

require_once __DIR__ . '/JournalPoster.php';
require_once __DIR__ . '/ChartOfAccounts.php';
require_once __DIR__ . '/DocCounter.php';
require_once __DIR__ . '/../functions/notify.php';

final class Loans
{
    public const KINDS = [
        'director' => 'Dirigeant / associé',
        'related'  => 'Société liée',
        'bank'     => 'Banque',
        'tontine'  => 'Tontine / coopérative',
        'other'    => 'Autre',
    ];
    /** Where what LPC owes each kind of lender sits. */
    public const BORROW_PARENT = ['director' => '4621', 'related' => '466', 'bank' => '162', 'tontine' => '168', 'other' => '168'];
    /** Where what each kind of borrower owes LPC sits. */
    public const LEND_PARENT   = ['director' => '271', 'related' => '466', 'bank' => '271', 'tontine' => '271', 'other' => '271'];
    public const INTEREST = ['none' => 'Sans intérêt', 'simple' => 'Taux fixe mensuel sur le restant dû', 'compound' => 'Taux mensuel composé'];

    private const URL = '/modules/accounting/cashflow.php#loans';

    // -------------------------------------------------------------------------
    // Arithmetic (pure)
    // -------------------------------------------------------------------------

    /** One month's interest: rate % of the principal still owed, plus the unpaid interest when compound. */
    public static function monthInterest(string $kind, float $ratePct, float $principal, float $unpaidInterest): float
    {
        if ($kind === 'none' || $ratePct <= 0) return 0.0;
        $base = max(0.0, $principal) + ($kind === 'compound' ? max(0.0, $unpaidInterest) : 0.0);
        return (float) round($base * $ratePct / 100, 0, PHP_ROUND_HALF_UP);
    }

    /** A repayment's split: interest first, then principal. @return array{0: float, 1: float} [interest, principal] */
    public static function split(float $amount, float $interestOwed): array
    {
        $interest = min($amount, max(0.0, $interestOwed));
        return [$interest, $amount - $interest];
    }

    /** The day of month $k after $start (31/01 + 1 month = 28/02, as banks count). */
    public static function monthAfter(string $start, int $k): string
    {
        $d = new DateTimeImmutable($start);
        $target = $d->modify('first day of this month')->modify("+{$k} month");
        $day = min((int) $d->format('j'), (int) $target->format('t'));
        return $target->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /**
     * What is owed on a loan from its approved movements up to $asOf (all when null):
     * principal, interest, total, and the repayments still waiting for the DG.
     */
    public static function balances(PDO $db, int $loanId, ?string $asOf = null): array
    {
        $loan = $db->prepare("SELECT amount, status FROM loans WHERE id = ?");
        $loan->execute([$loanId]);
        $l = $loan->fetch(PDO::FETCH_ASSOC);
        if (!$l) throw new RuntimeException("Financement #{$loanId} introuvable.");
        $principal = in_array($l['status'], ['approved', 'closed'], true) ? (float) $l['amount'] : 0.0;

        $sql = "SELECT
                    COALESCE(SUM(CASE WHEN kind = 'interest'  AND status = 'approved' THEN amount END), 0)         AS interest,
                    COALESCE(SUM(CASE WHEN kind = 'repayment' AND status = 'approved' THEN interest_part END), 0)  AS paid_interest,
                    COALESCE(SUM(CASE WHEN kind = 'repayment' AND status = 'approved' THEN principal_part END), 0) AS paid_principal,
                    COALESCE(SUM(CASE WHEN kind = 'repayment' AND status = 'pending'  THEN amount END), 0)         AS pending
                  FROM loan_movements WHERE loan_id = ?" . ($asOf ? " AND movement_date <= ?" : '');
        $st = $db->prepare($sql);
        $st->execute($asOf ? [$loanId, $asOf] : [$loanId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        $p = max(0.0, $principal - (float) $m['paid_principal']);
        $i = max(0.0, (float) $m['interest'] - (float) $m['paid_interest']);
        return ['principal' => $p, 'interest' => $i, 'total' => $p + $i, 'pending' => (float) $m['pending'],
                'interest_charged' => (float) $m['interest'], 'repaid' => (float) $m['paid_interest'] + (float) $m['paid_principal']];
    }

    /** Everything the tab shows. */
    public static function overview(PDO $db): array
    {
        $loans = $db->query("
            SELECT l.*, DATE_FORMAT(l.loan_date, '%d/%m/%Y') AS date_fr, DATE_FORMAT(l.due_date, '%d/%m/%Y') AS due_fr,
                   cp.name AS counterparty, cp.kind AS counterparty_kind,
                   ca.code AS account_code, ca.name AS account_name,
                   ta.name AS treasury_name,
                   CONCAT_WS(' ', ub.first_name, ub.last_name) AS created_by_name,
                   CONCAT_WS(' ', ua.first_name, ua.last_name) AS approved_by_name
              FROM loans l
              JOIN loan_counterparties cp ON cp.id = l.counterparty_id
              LEFT JOIN chart_of_accounts ca ON ca.id = IF(l.direction = 'borrow', cp.borrow_account_id, cp.lend_account_id)
              LEFT JOIN treasury_accounts ta ON ta.id = l.treasury_account_id
              LEFT JOIN users ub ON ub.id = l.created_by
              LEFT JOIN users ua ON ua.id = l.approved_by
             ORDER BY (l.status = 'pending') DESC, l.loan_date DESC, l.id DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $mv = $db->query("
            SELECT m.*, DATE_FORMAT(m.movement_date, '%d/%m/%Y') AS date_fr, ta.name AS treasury_name,
                   CONCAT_WS(' ', ub.first_name, ub.last_name) AS created_by_name
              FROM loan_movements m
              LEFT JOIN treasury_accounts ta ON ta.id = m.treasury_account_id
              LEFT JOIN users ub ON ub.id = m.created_by
             WHERE NOT (m.kind = 'interest' AND m.amount = 0)
             ORDER BY m.movement_date, m.id
        ")->fetchAll(PDO::FETCH_ASSOC);
        $byLoan = [];
        foreach ($mv as $m) $byLoan[(int) $m['loan_id']][] = $m;

        $sum = ['borrowed' => 0.0, 'lent' => 0.0, 'pending' => 0, 'next_due' => null];
        foreach ($loans as &$l) {
            $l['balances']  = self::balances($db, (int) $l['id']);
            $l['movements'] = $byLoan[(int) $l['id']] ?? [];
            if ($l['status'] === 'approved') {
                $sum[$l['direction'] === 'borrow' ? 'borrowed' : 'lent'] += $l['balances']['total'];
                if ($l['due_date'] && $l['balances']['total'] > 0 && ($sum['next_due'] === null || $l['due_date'] < $sum['next_due']['date'])) {
                    $sum['next_due'] = ['date' => $l['due_date'], 'date_fr' => $l['due_fr'], 'reference' => $l['reference']];
                }
            }
            if ($l['status'] === 'pending') $sum['pending']++;
            foreach ($l['movements'] as $m) if ($m['status'] === 'pending') $sum['pending']++;
        }
        unset($l);

        $counterparties = $db->query("
            SELECT cp.id, cp.name, cp.kind, cp.notes, b.code AS borrow_code, le.code AS lend_code
              FROM loan_counterparties cp
              LEFT JOIN chart_of_accounts b  ON b.id = cp.borrow_account_id
              LEFT JOIN chart_of_accounts le ON le.id = cp.lend_account_id
             ORDER BY cp.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $treasury = $db->query("
            SELECT a.id, a.name, a.type, a.balance, c.code AS coa_code
              FROM treasury_accounts a LEFT JOIN chart_of_accounts c ON c.id = a.coa_account_id
             WHERE a.status = 'active' ORDER BY a.type, a.name
        ")->fetchAll(PDO::FETCH_ASSOC);

        return ['loans' => $loans, 'summary' => $sum, 'counterparties' => $counterparties,
                'treasury' => $treasury, 'openings' => self::openings($db)];
    }

    /** Opening balances still in 471, that a loan may explain (« les fonds sont déjà sur le compte »). */
    public static function openings(PDO $db): array
    {
        return $db->query("
            SELECT je.id, je.reference, je.date, DATE_FORMAT(je.date, '%d/%m/%Y') AS date_fr,
                   a.id AS treasury_id, a.name AS treasury_name, ROUND(l.credit) AS amount
              FROM journal_entries je
              JOIN journal_lines l       ON l.journal_entry_id = je.id AND l.credit > 0
              JOIN chart_of_accounts c   ON c.id = l.account_id AND c.code = '471'
              JOIN treasury_transactions t ON t.id = je.source_id
              JOIN treasury_accounts a   ON a.id = t.account_id
             WHERE je.source_type = 'treasury_opening' AND je.status = 'posted'
               AND NOT EXISTS (SELECT 1 FROM loans lo WHERE lo.opening_entry_id = je.id AND lo.status <> 'rejected')
             ORDER BY je.date
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // Recording (CF, Comptable, DG): nothing posts
    // -------------------------------------------------------------------------

    /** A counterparty, new: name and kind. Its sub-accounts come with its first loan. */
    public static function addCounterparty(PDO $db, string $name, string $kind, string $notes, int $userId): int
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if (mb_strlen($name) < 2) throw new RuntimeException('Indiquez le nom du prêteur ou de l\'emprunteur.');
        if (!isset(self::KINDS[$kind])) throw new RuntimeException('Choisissez sa nature (dirigeant, société liée, banque, tontine…).');
        $dup = $db->prepare("SELECT id FROM loan_counterparties WHERE name = ?");
        $dup->execute([$name]);
        if ($dup->fetchColumn()) throw new RuntimeException("« {$name} » existe déjà : choisissez-le dans la liste.");
        $db->prepare("INSERT INTO loan_counterparties (name, kind, notes, created_by) VALUES (?, ?, ?, ?)")
           ->execute([mb_substr($name, 0, 150), $kind, trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null, $userId]);
        return (int) $db->lastInsertId();
    }

    /** The counterparty's own sub-account for this direction, created when missing. */
    public static function counterpartyAccount(PDO $db, int $cpId, string $direction): int
    {
        $st = $db->prepare("SELECT name, kind, borrow_account_id, lend_account_id FROM loan_counterparties WHERE id = ? FOR UPDATE");
        $st->execute([$cpId]);
        $cp = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cp) throw new RuntimeException('Contrepartie introuvable.');
        $col = $direction === 'borrow' ? 'borrow_account_id' : 'lend_account_id';
        if (!empty($cp[$col])) return (int) $cp[$col];

        $parent = ($direction === 'borrow' ? self::BORROW_PARENT : self::LEND_PARENT)[$cp['kind']] ?? '168';
        // Under 466 both directions share the parent: the label says which one it is.
        $label = $parent === '466' && $direction === 'lend' ? $cp['name'] . ' (prêt de LPC)' : $cp['name'];
        $id = ChartOfAccounts::createSub($db, $parent, $label, 3);
        if ($direction === 'lend') $db->prepare("UPDATE chart_of_accounts SET type = 'asset' WHERE id = ?")->execute([$id]);
        $db->prepare("UPDATE loan_counterparties SET {$col} = ? WHERE id = ?")->execute([$id, $cpId]);
        return $id;
    }

    /**
     * Record a loan (borrowed or lent), pending the DG's approval.
     * $in: direction, counterparty_id, loan_date, amount, funds_source ('treasury'|'opening'),
     *      treasury_account_id | opening_entry_id, interest_kind, monthly_rate, fee_amount, due_date, notes.
     */
    public static function record(PDO $db, array $in, int $userId): array
    {
        $direction = (string) ($in['direction'] ?? '');
        if (!in_array($direction, ['borrow', 'lend'], true)) throw new RuntimeException('Indiquez si LPC emprunte ou prête.');
        $cpId = (int) ($in['counterparty_id'] ?? 0);
        $cp = $db->prepare("SELECT id, name FROM loan_counterparties WHERE id = ?");
        $cp->execute([$cpId]);
        $cpRow = $cp->fetch(PDO::FETCH_ASSOC);
        if (!$cpRow) throw new RuntimeException('Choisissez le prêteur ou l\'emprunteur.');

        $date = self::date($in['loan_date'] ?? '', 'la date du financement');
        if ($date > date('Y-m-d')) throw new RuntimeException('La date du financement ne peut pas être dans le futur.');
        $amount = round((float) ($in['amount'] ?? 0));
        if ($amount <= 0) throw new RuntimeException('Le montant doit être supérieur à 0.');
        $fee = round((float) ($in['fee_amount'] ?? 0));
        if ($fee < 0 || $fee >= $amount) throw new RuntimeException('Les frais doivent être positifs et inférieurs au montant.');

        $kind = (string) ($in['interest_kind'] ?? 'none');
        if (!isset(self::INTEREST[$kind])) throw new RuntimeException('Type d\'intérêt inconnu.');
        $rate = $kind === 'none' ? 0.0 : round((float) ($in['monthly_rate'] ?? 0), 4);
        if ($kind !== 'none' && ($rate <= 0 || $rate > 100)) throw new RuntimeException('Indiquez le taux mensuel (en %, entre 0 et 100).');

        $due = trim((string) ($in['due_date'] ?? ''));
        $due = $due === '' ? null : self::date($due, 'l\'échéance');
        if ($due !== null && $due < $date) throw new RuntimeException('L\'échéance ne peut pas précéder la date du financement.');

        $source = (string) ($in['funds_source'] ?? 'treasury');
        $treasuryId = null; $openingId = null;
        if ($source === 'opening') {
            if ($direction !== 'borrow') throw new RuntimeException('Seul un emprunt peut expliquer un solde d\'ouverture.');
            if ($fee > 0) throw new RuntimeException('Des fonds déjà reçus ne portent pas de frais retenus.');
            $openingId = (int) ($in['opening_entry_id'] ?? 0);
            $match = array_values(array_filter(self::openings($db), fn ($o) => (int) $o['id'] === $openingId));
            if (!$match) throw new RuntimeException('Ce solde d\'ouverture n\'est plus en compte d\'attente 471.');
            if ((float) $match[0]['amount'] !== $amount) {
                throw new RuntimeException('Le montant doit être celui du solde d\'ouverture : ' . self::f((float) $match[0]['amount']) . ' FCFA.');
            }
            $treasuryId = (int) $match[0]['treasury_id'];
        } elseif ($source === 'treasury') {
            $treasuryId = (int) ($in['treasury_account_id'] ?? 0);
            $t = $db->prepare("SELECT id FROM treasury_accounts WHERE id = ? AND status = 'active'");
            $t->execute([$treasuryId]);
            if (!$t->fetchColumn()) throw new RuntimeException('Choisissez le compte de trésorerie par lequel l\'argent passe.');
        } else {
            throw new RuntimeException('Origine des fonds inconnue.');
        }

        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $reference = DocCounter::next($db, 'loan', $date);
            self::counterpartyAccount($db, $cpId, $direction);
            $db->prepare("
                INSERT INTO loans (reference, counterparty_id, direction, loan_date, amount, treasury_account_id, funds_source,
                                   opening_entry_id, interest_kind, monthly_rate, fee_amount, due_date, notes, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?)
            ")->execute([$reference, $cpId, $direction, $date, $amount, $treasuryId, $source, $openingId, $kind, $rate, $fee,
                         $due, self::note($in['notes'] ?? ''), $userId]);
            $id = (int) $db->lastInsertId();
            if ($own) $db->commit();
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }

        $what = $direction === 'borrow' ? 'emprunt ' . self::de($cpRow['name']) : "prêt à {$cpRow['name']}";
        lpc_notify_permission($db, 'accounting.loans.approve', $direction === 'borrow' ? 'Emprunt à approuver' : 'Prêt à approuver',
            "{$reference} : {$what}, " . self::f($amount) . ' FCFA le ' . date('d/m/Y', strtotime($date)) . ', ' . self::terms($kind, $rate, $fee)
            . '. Rien n\'est comptabilisé avant votre approbation.', self::URL, 'warning', [$userId]);
        return ['id' => $id, 'reference' => $reference];
    }

    /** Record a repayment of any amount, pending the DG's approval. */
    public static function recordRepayment(PDO $db, int $loanId, array $in, int $userId): int
    {
        $l = self::loan($db, $loanId);
        if ($l['status'] !== 'approved') throw new RuntimeException('Seul un financement approuvé et non soldé se rembourse.');
        $amount = round((float) ($in['amount'] ?? 0));
        if ($amount <= 0) throw new RuntimeException('Le montant doit être supérieur à 0.');
        $date = self::date($in['date'] ?? '', 'la date du remboursement');
        if ($date > date('Y-m-d')) throw new RuntimeException('La date ne peut pas être dans le futur.');
        if ($date < $l['loan_date']) throw new RuntimeException('Le remboursement ne peut pas précéder le financement.');
        $treasuryId = (int) ($in['treasury_account_id'] ?? 0);
        $t = $db->prepare("SELECT id FROM treasury_accounts WHERE id = ? AND status = 'active'");
        $t->execute([$treasuryId]);
        if (!$t->fetchColumn()) throw new RuntimeException('Choisissez le compte de trésorerie.');

        $b = self::balances($db, $loanId);
        $left = $b['total'] - $b['pending'];
        if ($amount > $left) {
            throw new RuntimeException('C\'est plus que ce qui reste dû : ' . self::f($left) . ' FCFA'
                . ($b['pending'] > 0 ? ' (après les ' . self::f($b['pending']) . ' FCFA qui attendent l\'approbation)' : '') . '.');
        }
        $db->prepare("
            INSERT INTO loan_movements (loan_id, kind, movement_date, amount, treasury_account_id, status, notes, created_by)
            VALUES (?, 'repayment', ?, ?, ?, 'pending', ?, ?)
        ")->execute([$loanId, $date, $amount, $treasuryId, self::note($in['notes'] ?? ''), $userId]);
        $id = (int) $db->lastInsertId();

        lpc_notify_permission($db, 'accounting.loans.approve', 'Remboursement à approuver',
            "{$l['reference']} ({$l['counterparty']}) : " . ($l['direction'] === 'borrow' ? 'LPC rembourse ' : 'LPC reçoit ')
            . self::f($amount) . ' FCFA le ' . date('d/m/Y', strtotime($date)) . '. Rien n\'est comptabilisé avant votre approbation.',
            self::URL, 'warning', [$userId]);
        return $id;
    }

    // -------------------------------------------------------------------------
    // The DG's decision: the only way anything posts
    // -------------------------------------------------------------------------

    public static function decideLoan(PDO $db, int $loanId, bool $approve, string $note, int $userId): string
    {
        $l = self::loan($db, $loanId, true);
        if ($l['status'] !== 'pending') throw new RuntimeException('Ce financement a déjà reçu une décision.');
        $note = trim($note);
        if (!$approve) {
            if (mb_strlen($note) < 3) throw new RuntimeException('Indiquez la raison du refus.');
            $db->prepare("UPDATE loans SET status = 'rejected', decision_note = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
               ->execute([mb_substr($note, 0, 500), $userId, $loanId]);
            return "{$l['reference']} refusé.";
        }

        $amount = (float) $l['amount'];
        $fee = (float) $l['fee_amount'];
        $cpAcc = self::counterpartyAccount($db, (int) $l['counterparty_id'], $l['direction']);
        $desc = ($l['direction'] === 'borrow' ? "Emprunt {$l['reference']} reçu de " : "Prêt {$l['reference']} accordé à ") . $l['counterparty'];
        $txId = null;

        if ($l['funds_source'] === 'opening') {
            // The money is already on the account (its opening balance, in 471): it leaves 471 for the lender.
            $suspense = JournalPoster::coaByOhada($db, '471');
            $still = $db->prepare("SELECT COUNT(*) FROM journal_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
                                    WHERE e.id = ? AND e.status = 'posted' AND l.account_id = ? AND ROUND(l.credit) = ?");
            $still->execute([(int) $l['opening_entry_id'], $suspense, round($amount)]);
            if (!$suspense || !(int) $still->fetchColumn()) throw new RuntimeException('Le solde d\'ouverture n\'est plus en compte d\'attente 471.');
            $lines = [[$suspense, $amount, 0], [$cpAcc, 0, $amount]];
            $desc .= ' (solde d\'ouverture de ' . $l['treasury_name'] . ', régularisé depuis le compte d\'attente 471)';
        } else {
            $trs = JournalPoster::coaFromTreasury($db, (int) $l['treasury_account_id']);
            $net = $amount - $fee;
            if ($l['direction'] === 'borrow') {
                $feeAcc = $fee > 0 ? self::own($db, '631') : null;
                $lines = [[$trs, $net, 0], [$cpAcc, 0, $amount]];
                if ($fee > 0) $lines[] = [$feeAcc, $fee, 0];
                $txId = self::treasuryMove($db, (int) $l['treasury_account_id'], 'in_loan', $net, $l['reference'], $desc, $userId);
            } else {
                $feeAcc = $fee > 0 ? self::own($db, '771') : null;
                $lines = [[$cpAcc, $amount, 0], [$trs, 0, $net]];
                if ($fee > 0) $lines[] = [$feeAcc, 0, $fee];
                $txId = self::treasuryMove($db, (int) $l['treasury_account_id'], 'out_loan', $net, $l['reference'], $desc, $userId);
            }
            if ($fee > 0) $desc .= ', frais de ' . self::f($fee) . ' FCFA retenus';
        }
        $je = JournalPoster::postLoanEntry($l['reference'], $l['loan_date'], $desc, $lines, 'loan', $loanId, $userId);
        $db->prepare("UPDATE loans SET status = 'approved', decision_note = ?, approved_by = ?, approved_at = NOW(),
                             journal_entry_id = ?, treasury_transaction_id = ? WHERE id = ?")
           ->execute([$note !== '' ? mb_substr($note, 0, 500) : null, $userId, $je, $txId, $loanId]);
        return "{$l['reference']} approuvé et comptabilisé.";
    }

    public static function decideRepayment(PDO $db, int $movementId, bool $approve, string $note, int $userId): string
    {
        $st = $db->prepare("SELECT * FROM loan_movements WHERE id = ? AND kind = 'repayment' FOR UPDATE");
        $st->execute([$movementId]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new RuntimeException('Remboursement introuvable.');
        if ($m['status'] !== 'pending') throw new RuntimeException('Ce remboursement a déjà reçu une décision.');
        $l = self::loan($db, (int) $m['loan_id'], true);
        $note = trim($note);
        if (!$approve) {
            if (mb_strlen($note) < 3) throw new RuntimeException('Indiquez la raison du refus.');
            $db->prepare("UPDATE loan_movements SET status = 'rejected', decision_note = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
               ->execute([mb_substr($note, 0, 500), $userId, $movementId]);
            return 'Remboursement refusé.';
        }
        if ($l['status'] !== 'approved') throw new RuntimeException('Le financement n\'est plus ouvert.');

        $amount = (float) $m['amount'];
        $b = self::balances($db, (int) $l['id']);
        if ($amount > $b['total'] + 0.001) throw new RuntimeException('C\'est plus que ce qui reste dû : ' . self::f($b['total']) . ' FCFA.');
        [$interest, $principal] = self::split($amount, $b['interest']);

        $cpAcc = self::counterpartyAccount($db, (int) $l['counterparty_id'], $l['direction']);
        $trs = JournalPoster::coaFromTreasury($db, (int) $m['treasury_account_id']);
        $ref = $l['reference'] . '-R' . $movementId;
        if ($l['direction'] === 'borrow') {
            $desc = "Remboursement de l'emprunt {$l['reference']} à {$l['counterparty']}";
            $lines = [[$cpAcc, $amount, 0], [$trs, 0, $amount]];
            $tx = self::treasuryMove($db, (int) $m['treasury_account_id'], 'out_loan_repayment', $amount, $ref, $desc, $userId);
        } else {
            $desc = "Remboursement du prêt {$l['reference']} par {$l['counterparty']}";
            $lines = [[$trs, $amount, 0], [$cpAcc, 0, $amount]];
            $tx = self::treasuryMove($db, (int) $m['treasury_account_id'], 'in_loan_repayment', $amount, $ref, $desc, $userId);
        }
        $desc .= ' (intérêts ' . self::f($interest) . ', capital ' . self::f($principal) . ')';
        $je = JournalPoster::postLoanEntry($ref, $m['movement_date'], $desc, $lines, 'loan_movement', $movementId, $userId);
        $db->prepare("UPDATE loan_movements SET status = 'approved', interest_part = ?, principal_part = ?, decision_note = ?,
                             approved_by = ?, approved_at = NOW(), journal_entry_id = ?, treasury_transaction_id = ? WHERE id = ?")
           ->execute([$interest, $principal, $note !== '' ? mb_substr($note, 0, 500) : null, $userId, $je, $tx, $movementId]);

        $after = self::balances($db, (int) $l['id']);
        if ($after['total'] <= 0.001) {
            $db->prepare("UPDATE loans SET status = 'closed' WHERE id = ?")->execute([(int) $l['id']]);
            return "Remboursement approuvé : {$l['reference']} est soldé.";
        }
        return 'Remboursement approuvé et comptabilisé. Reste dû : ' . self::f($after['total']) . ' FCFA.';
    }

    /** Tell whoever recorded it what the DG decided. */
    public static function tellRecorder(PDO $db, int $userId, string $title, string $message): void
    {
        lpc_notify_user($db, $userId, $title, $message, self::URL, 'info');
    }

    // -------------------------------------------------------------------------
    // The months and the reminders
    // -------------------------------------------------------------------------

    /** Interest months due up to $today, then due-date reminders. Idempotent. @return string[] what it did */
    public static function runDue(PDO $db, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $done = [];

        $ids = $db->query("SELECT id FROM loans WHERE status = 'approved' AND interest_kind <> 'none' AND monthly_rate > 0")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $db->beginTransaction();
            try {
                $l = self::loan($db, (int) $id, true);
                $last = $db->prepare("SELECT COALESCE(MAX(period_no), 0) FROM loan_movements WHERE loan_id = ? AND kind = 'interest'");
                $last->execute([(int) $id]);
                for ($k = (int) $last->fetchColumn() + 1; $k <= 600; $k++) {
                    $on = self::monthAfter($l['loan_date'], $k);
                    if ($on > $today) break;
                    $b = self::balances($db, (int) $id, $on);
                    if ($b['total'] <= 0.001) break;
                    $amount = self::monthInterest($l['interest_kind'], (float) $l['monthly_rate'], $b['principal'], $b['interest']);
                    $db->prepare("INSERT INTO loan_movements (loan_id, kind, movement_date, amount, period_no, status, notes, created_by, approved_by, approved_at)
                                  VALUES (?, 'interest', ?, ?, ?, 'approved', ?, ?, ?, NOW())")
                       ->execute([(int) $id, $on, $amount, $k, "Intérêts du mois {$k}, selon les conditions approuvées",
                                  (int) $l['approved_by'], (int) $l['approved_by']]);
                    $mid = (int) $db->lastInsertId();
                    if ($amount > 0) {
                        $cpAcc = self::counterpartyAccount($db, (int) $l['counterparty_id'], $l['direction']);
                        $lines = $l['direction'] === 'borrow'
                            ? [[self::own($db, '671'), $amount, 0], [$cpAcc, 0, $amount]]
                            : [[$cpAcc, $amount, 0], [self::own($db, '771'), 0, $amount]];
                        $je = JournalPoster::postLoanEntry($l['reference'] . '-I' . $k, $on,
                            "Intérêts {$l['reference']} ({$l['counterparty']}), mois {$k} : " . rtrim(rtrim(number_format((float) $l['monthly_rate'], 4, ',', ''), '0'), ',') . ' %',
                            $lines, 'loan_movement', $mid, (int) $l['approved_by']);
                        $db->prepare("UPDATE loan_movements SET journal_entry_id = ? WHERE id = ?")->execute([$je, $mid]);
                        $done[] = "{$l['reference']} intérêts mois {$k} : " . self::f($amount);
                    }
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("Loans::runDue loan #{$id}: " . $e->getMessage());
            }
        }

        // Reminders to the DG and the CF, once before and once after the due date.
        $soon = (new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        $due = $db->prepare("SELECT l.id, l.reference, l.direction, l.due_date, l.reminder_stage, cp.name
                               FROM loans l JOIN loan_counterparties cp ON cp.id = l.counterparty_id
                              WHERE l.status = 'approved' AND l.due_date IS NOT NULL AND l.due_date <= ? AND l.reminder_stage < 2");
        $due->execute([$soon]);
        foreach ($due->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stage = $r['due_date'] < $today ? 2 : 1;
            if ($stage <= (int) $r['reminder_stage']) continue;
            $left = self::balances($db, (int) $r['id'])['total'];
            if ($left <= 0) continue;
            $when = date('d/m/Y', strtotime($r['due_date']));
            $title = $stage === 2 ? 'Échéance dépassée' : 'Échéance proche';
            $msg = "{$r['reference']} (" . ($r['direction'] === 'borrow' ? 'emprunt ' . self::de($r['name']) : "prêt à {$r['name']}") . ') : échéance le '
                 . $when . ', reste dû ' . self::f($left) . ' FCFA.';
            self::remind($db, $title, $msg);
            $db->prepare("UPDATE loans SET reminder_stage = ? WHERE id = ?")->execute([$stage, (int) $r['id']]);
            $done[] = "{$r['reference']} : {$title}";
        }
        return $done;
    }

    /** The DG (who approves) and the CF. */
    private static function remind(PDO $db, string $title, string $msg): void
    {
        lpc_notify_permission($db, 'accounting.loans.approve', $title, $msg, self::URL, 'warning');
        $cf = $db->query("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
                           WHERE r.name = 'controleur_financier' AND u.status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cf as $uid) lpc_notify_user($db, (int) $uid, $title, $msg, self::URL, 'warning');
    }

    // -------------------------------------------------------------------------
    // internals
    // -------------------------------------------------------------------------

    private static function loan(PDO $db, int $id, bool $lock = false): array
    {
        $st = $db->prepare("SELECT l.*, cp.name AS counterparty, ta.name AS treasury_name
                              FROM loans l JOIN loan_counterparties cp ON cp.id = l.counterparty_id
                              LEFT JOIN treasury_accounts ta ON ta.id = l.treasury_account_id
                             WHERE l.id = ?" . ($lock ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l) throw new RuntimeException('Financement introuvable.');
        return $l;
    }

    /** The parent's own chart row (631, 671, 771: migration 144). */
    private static function own(PDO $db, string $number): int
    {
        $id = JournalPoster::coaByOhada($db, $number);
        if (!$id) throw new RuntimeException("Le compte {$number} manque au plan comptable (migration 144).");
        return $id;
    }

    /** Money in or out of a treasury account, its balance moved (refused below zero). */
    private static function treasuryMove(PDO $db, int $accountId, string $type, float $amount, string $ref, string $desc, int $userId): int
    {
        $st = $db->prepare("SELECT name, balance FROM treasury_accounts WHERE id = ? AND status = 'active' FOR UPDATE");
        $st->execute([$accountId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) throw new RuntimeException('Compte de trésorerie introuvable ou archivé.');
        $out = str_starts_with($type, 'out_');
        if ($out && (float) $a['balance'] < $amount) {
            throw new RuntimeException("Solde insuffisant sur « {$a['name']} » (" . self::f((float) $a['balance']) . ' FCFA).');
        }
        $db->prepare("UPDATE treasury_accounts SET balance = balance " . ($out ? '-' : '+') . " ? WHERE id = ?")->execute([$amount, $accountId]);
        $db->prepare("INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by) VALUES (?, ?, ?, ?, ?, ?)")
           ->execute([$accountId, $type, $amount, $ref, mb_substr($desc, 0, 500), $userId]);
        return (int) $db->lastInsertId();
    }

    private static function date($v, string $what): string
    {
        $v = trim((string) $v);
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) throw new RuntimeException("Indiquez {$what}.");
        return $v;
    }

    private static function note($v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, 500);
    }

    public static function terms(string $kind, float $rate, float $fee): string
    {
        $r = rtrim(rtrim(number_format($rate, 4, ',', ''), '0'), ',');
        $t = match ($kind) {
            'simple'   => "{$r} % par mois sur le restant dû",
            'compound' => "{$r} % par mois, composé",
            default    => 'sans intérêt',
        };
        return $fee > 0 ? $t . ', frais ' . self::f($fee) . ' FCFA' : $t;
    }

    /** « de Smart Logistics », « d'Afriland First Bank ». */
    private static function de(string $name): string
    {
        return (preg_match('/^[aeiouyhàâéèêîôûAEIOUYHÀÂÉÈÊÎÔÛ]/u', $name) ? "d'" : 'de ') . $name;
    }

    private static function f(float $n): string
    {
        return number_format($n, 0, ',', ' ');
    }
}
