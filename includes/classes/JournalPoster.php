<?php
/**
 * includes/classes/JournalPoster.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 4 (Batch B) · treasury / journal wiring.
 *
 * WHY:
 *   The audit report §6 opens with "books don't balance" — invoices marked
 *   'paid' with no matching treasury_transactions row and no journal entry.
 *   Every payment reception, every internal transfer, every expense MUST:
 *     1. Insert a row into treasury_transactions.
 *     2. Post a BALANCED journal_entries + journal_lines pair via
 *        `CALL post_journal_entry(entry_id, user_id)` — the stored proc
 *        raises SIGNAL SQLSTATE '45000' on any drift, which trips the
 *        outer transaction and rolls the whole thing back.
 *
 * This class is the ONE place that writes to journal_entries for treasury/AR
 * flows. Controllers call these methods inside an existing transaction; the
 * class never opens its own — atomicity is the caller's responsibility.
 *
 * All FCFA amounts are rounded half-up (integers). Currency subunits do
 * not exist in FCFA.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class JournalPoster
{
    /**
     * Post the journal entry + treasury_transactions row for a single
     * validated client payment (INSERT INTO payments … done by the caller
     * BEFORE calling this).
     *
     * OHADA lines (no withholding):
     *   Debit  cash/bank account (521 or 571) — where the cash actually lands
     *   Credit client account   (411)         — clears the FULL AR balance
     *
     * OHADA lines (client withholds AIR at source — Prometal etc.):
     *   Debit  cash/bank account (521 or 571) — for `payments.amount`
     *   Debit  4424 AIR retenu à la source     — for `payments.air_withheld_amount`
     *   Credit client account   (411)          — for the full invoice net_payable
     *                                            (amount + air_withheld_amount)
     *
     *   → the sum on the credit side equals what the invoice booked as
     *     receivable, so 411 clears exactly and we never double-count
     *     revenue OR taxes.
     *
     * If the payment does not link to an invoice, we still post — the credit
     * goes to 419 (avances / wallet) instead of 411.
     *
     * @param int $payment_id                 payments.id
     * @param int|null $treasury_account_id   treasury_accounts.id where cash lands
     *                                        (null → resolved from payment_method:
     *                                        cash → caisse; bank → banque)
     * @return int                            journal_entries.id
     */
    public static function postInvoicePayment(int $payment_id, ?int $treasury_account_id = null): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT p.id, p.reference, p.invoice_id, p.client_id, p.amount,
                   p.payment_method, p.payment_date,
                   COALESCE(p.air_withheld_amount, 0) AS air_withheld
              FROM payments p WHERE p.id = ? FOR UPDATE
        ");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException("JournalPoster: payment #{$payment_id} not found.");
        }
        $amount   = self::roundFcfa((float) $pay['amount']);          // cash actually received
        $withheld = self::roundFcfa((float) $pay['air_withheld']);    // AIR kept by client
        if ($amount <= 0 && $withheld <= 0) {
            throw new RuntimeException("JournalPoster: payment #{$payment_id} has non-positive amount.");
        }

        // 1. Treasury account (cash or bank) — only if cash was actually received.
        $treasury_coa = null;
        if ($amount > 0) {
            $treasury_account_id = $treasury_account_id ?? self::resolveTreasuryAccount($db, $pay['payment_method']);
            if (!$treasury_account_id) {
                throw new RuntimeException("JournalPoster: no treasury account configured for method {$pay['payment_method']}.");
            }
            $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);
        }

        // 2. Credit COA — 411 if there's an invoice, 419 (client advance) otherwise.
        $credit_coa = $pay['invoice_id']
            ? self::coaByOhada($db, '411')
            : self::coaByOhada($db, '419');
        if (!$credit_coa) {
            throw new RuntimeException("JournalPoster: OHADA credit account (411/419) not mapped.");
        }

        // 3. AIR-withheld debit account (only used if withheld > 0).
        $air_coa = null;
        if ($withheld > 0) {
            $air_coa = self::coaByOhada($db, '4424');
            if (!$air_coa) {
                throw new RuntimeException("JournalPoster: OHADA 4424 (AIR retenu à la source) not mapped — run migration 020.");
            }
        }

        // 4. Post the JE draft, add balanced lines, call post_journal_entry.
        $ref  = 'PAY-' . $pay['reference'];
        $desc = $pay['invoice_id']
            ? ("Encaissement paiement facture #{$pay['invoice_id']}"
                . ($withheld > 0 ? " (dont AIR retenu à la source " . number_format($withheld, 0, ',', ' ') . " FCFA)" : ''))
            : "Avance client (portefeuille)";
        $je_id = self::createDraftJe($db, $ref, 'BQ', (string)$pay['payment_date'], $desc, $user_id);
        if ($amount > 0)   self::addLine($db, $je_id, $treasury_coa, $amount,   0.0);
        if ($withheld > 0) self::addLine($db, $je_id, $air_coa,      $withheld, 0.0);
        self::addLine($db, $je_id, $credit_coa,   0.0,     $amount + $withheld);
        self::post($db, $je_id, $user_id);

        // 5. Treasury transaction — maintain balance + audit trail.
        //    Skip if AIR-only payment (no cash actually moved).
        if ($amount > 0) {
            $db->prepare("
                INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
                VALUES (?, 'in_client_payment', ?, ?, ?, ?)
            ")->execute([$treasury_account_id, $amount, $ref, $desc, $user_id]);
            $db->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?")
               ->execute([$amount, $treasury_account_id]);
        }

        return $je_id;
    }

    /**
     * Post the JE for an invoice at issuance time. Called by
     * invoices_controller::generate_invoice AFTER the invoices row has
     * been inserted so we know the final subtotal / tva_amount /
     * air_amount / net_payable.
     *
     * Lines (SYSCOHADA revised):
     *   Debit  411 Clients                   net_payable  (what will be received in cash)
     *   Debit  4424 AIR retenu (à récup.)    air_amount   (what client will withhold)
     *   Credit 701 Ventes (or 706 Services)  subtotal
     *   Credit 4432 TVA collectée            tva_amount
     *
     * This is the missing half — until this exists the ledger only sees
     * revenue when cash is received, which under-reports the AIR base
     * every month it takes to get paid.
     *
     * @param int $invoice_id            invoices.id (already inserted)
     * @param string $revenue_ohada      OHADA account for the sale (default '701')
     * @return int                       journal_entries.id
     */
    public static function postInvoiceIssued(int $invoice_id, string $revenue_ohada = '701'): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT id, reference, client_id, date, subtotal, tva_amount,
                   COALESCE(air_amount, 0)  AS air_amount,
                   COALESCE(net_payable, total_amount) AS net_payable,
                   total_amount
              FROM invoices WHERE id = ? FOR UPDATE
        ");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new RuntimeException("JournalPoster: invoice #{$invoice_id} not found.");

        $subtotal    = self::roundFcfa((float) $inv['subtotal']);
        $tva         = self::roundFcfa((float) $inv['tva_amount']);
        $air         = self::roundFcfa((float) $inv['air_amount']);
        $net_payable = self::roundFcfa((float) $inv['net_payable']);

        // Sanity: net_payable + air must equal total (subtotal + tva).
        $expected = self::roundFcfa($subtotal + $tva);
        if (self::roundFcfa($net_payable + $air) !== $expected) {
            throw new RuntimeException("JournalPoster: invoice #{$invoice_id} — net_payable+air ({$net_payable}+{$air}) ≠ total ({$expected}).");
        }

        $client_coa  = self::coaByOhada($db, '411');
        $revenue_coa = self::coaByOhada($db, $revenue_ohada) ?: self::coaByOhada($db, '701');
        $tva_coa     = ($tva > 0) ? self::coaByOhada($db, '4432') : null;
        $air_coa     = ($air > 0) ? self::coaByOhada($db, '4424') : null;

        if (!$client_coa)  throw new RuntimeException("JournalPoster: 411 (Clients) not mapped.");
        if (!$revenue_coa) throw new RuntimeException("JournalPoster: {$revenue_ohada} (Ventes) not mapped.");
        if ($tva > 0 && !$tva_coa) throw new RuntimeException("JournalPoster: 4432 (TVA collectée) not mapped — run migration 020.");
        if ($air > 0 && !$air_coa) throw new RuntimeException("JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020.");

        $ref  = 'INV-' . $inv['reference'];
        $desc = "Émission facture #{$inv['reference']} (client #{$inv['client_id']})";
        $je_id = self::createDraftJe($db, $ref, 'VT', (string)$inv['date'], $desc, $user_id);
        if ($net_payable > 0) self::addLine($db, $je_id, $client_coa,  $net_payable, 0.0);
        if ($air > 0)         self::addLine($db, $je_id, $air_coa,     $air,          0.0);
        self::addLine($db, $je_id, $revenue_coa, 0.0, $subtotal);
        if ($tva > 0)         self::addLine($db, $je_id, $tva_coa,     0.0, $tva);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Post the JE + treasury_transactions for an internal transfer between
     * two treasury accounts (already updated in balance by the caller).
     *
     * Debit target COA / credit source COA.
     */
    public static function postInternalTransfer(int $from_treasury_id, int $to_treasury_id, float $amount, string $note = ''): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) throw new RuntimeException("JournalPoster::transfer — non-positive amount.");
        if ($from_treasury_id === $to_treasury_id) throw new RuntimeException("JournalPoster::transfer — same source and destination.");

        $from_coa = self::coaFromTreasury($db, $from_treasury_id);
        $to_coa   = self::coaFromTreasury($db, $to_treasury_id);

        $ref  = 'TRF-' . date('Ymd-His');
        $desc = $note !== '' ? $note : 'Virement interne';
        $je_id = self::createDraftJe($db, $ref, 'OD', date('Y-m-d'), $desc, $user_id);
        self::addLine($db, $je_id, $to_coa,   $amount, 0.0);
        self::addLine($db, $je_id, $from_coa, 0.0,     $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Post the JE for a treasury expense (petite caisse).
     *   Debit expense COA (typically 6xx)
     *   Credit source treasury COA (typically 5xx)
     */
    public static function postExpense(int $expense_coa_id, int $treasury_account_id, float $amount, string $description, string $category = ''): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) throw new RuntimeException("JournalPoster::postExpense — non-positive amount.");

        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);
        $ref  = 'EXP-' . strtoupper(bin2hex(random_bytes(3)));
        $desc = $description !== '' ? $description : 'Dépense trésorerie';
        if ($category !== '') $desc .= " [{$category}]";

        $je_id = self::createDraftJe($db, $ref, 'OD', date('Y-m-d'), $desc, $user_id);
        self::addLine($db, $je_id, $expense_coa_id, $amount, 0.0);
        self::addLine($db, $je_id, $treasury_coa,   0.0,     $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Post the reconciliation JE for a driver tournée return.
     * Called by treasury_controller::process_tournee AFTER the treasury
     * accounts + driver_debts + client_wallets rows have been updated.
     *
     * Lines (simplified, but balanced):
     *   Debit  caisse COA         actual_cash
     *   Debit  driver 421 (debt)  shortfall (if actual < expected)
     *   Credit client 411         expected
     *   Credit client 419 wallet  overage  (if actual > expected)
     */
    public static function postTourneeReconciliation(int $driver_id, float $expected, float $actual, ?int $overage_client_id, int $caisse_treasury_id): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $expected = self::roundFcfa($expected);
        $actual   = self::roundFcfa($actual);
        $variance = $actual - $expected;

        $caisse_coa   = self::coaFromTreasury($db, $caisse_treasury_id);
        $client_coa   = self::coaByOhada($db, '411');
        $driver_coa   = self::coaByOhada($db, '421');    // Personnel — avances/débits
        $wallet_coa   = self::coaByOhada($db, '419');    // Avances clients

        $ref  = "TRN-{$driver_id}-" . date('Ymd');
        $je_id = self::createDraftJe($db, $ref, 'OD', date('Y-m-d'), "Retour de tournée chauffeur #{$driver_id}", $user_id);

        // Debit caisse for what actually came in.
        if ($actual > 0) self::addLine($db, $je_id, $caisse_coa, $actual, 0.0);

        // Credit client for what was owed.
        if ($expected > 0) self::addLine($db, $je_id, $client_coa, 0.0, $expected);

        if ($variance < 0) {
            // Shortfall — driver debt.
            self::addLine($db, $je_id, $driver_coa, abs($variance), 0.0);
        } elseif ($variance > 0) {
            // Overage — into a client wallet (if identified) or misc income.
            self::addLine($db, $je_id, $wallet_coa, 0.0, $variance);
        }

        self::post($db, $je_id, $user_id);
        return $je_id;
    }

    // ------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------

    private static function createDraftJe(PDO $db, string $ref, string $journal_code, string $date, string $desc, int $user_id): int
    {
        // Append a random suffix so re-runs in the same second still get a
        // unique reference (matches the existing pattern in inventory_controller).
        $ref .= '-' . strtoupper(bin2hex(random_bytes(2)));
        $db->prepare("
            INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by)
            VALUES (?, ?, ?, ?, 'draft', ?)
        ")->execute([$ref, $journal_code, $date, $desc, $user_id]);
        return (int) $db->lastInsertId();
    }

    private static function addLine(PDO $db, int $entry_id, int $account_id, float $debit, float $credit): void
    {
        if ($debit == 0.0 && $credit == 0.0) return;
        if (!$account_id) {
            throw new RuntimeException("JournalPoster: missing account_id on a JE line.");
        }
        $db->prepare("INSERT INTO journal_lines (journal_entry_id, account_id, debit, credit) VALUES (?, ?, ?, ?)")
           ->execute([$entry_id, $account_id, self::roundFcfa($debit), self::roundFcfa($credit)]);
    }

    private static function post(PDO $db, int $entry_id, int $user_id): void
    {
        // post_journal_entry stored proc (migration 004) checks balance +
        // stamps status='posted'. Raises SIGNAL SQLSTATE '45000' on drift.
        $db->prepare("CALL post_journal_entry(?, ?)")->execute([$entry_id, $user_id]);
    }

    /** Look up chart_of_accounts.id by OHADA account_number prefix. Cached per request. */
    private static function coaByOhada(PDO $db, string $prefix): ?int
    {
        static $cache = [];
        if (isset($cache[$prefix])) return $cache[$prefix];

        $stmt = $db->prepare("
            SELECT coa.id FROM chart_of_accounts coa
              JOIN ohada_accounts o ON coa.ohada_account_id = o.id
             WHERE o.account_number = ?
             ORDER BY coa.id ASC LIMIT 1
        ");
        $stmt->execute([$prefix]);
        $r = $stmt->fetchColumn();
        return $cache[$prefix] = ($r ? (int)$r : null);
    }

    /**
     * Map a treasury_accounts row to a chart_of_accounts.id.
     * treasury_accounts.type ∈ {'caisse','bank','mobile_money',...} → COA 571 / 521.
     *
     * Prefers an explicit coa_account_id column if it exists (added by
     * migration 015). Otherwise falls back to the OHADA type lookup.
     */
    private static function coaFromTreasury(PDO $db, int $treasury_id): int
    {
        static $has_coa_col = null;
        if ($has_coa_col === null) {
            $chk = $db->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'treasury_accounts'
                   AND column_name = 'coa_account_id'
            ")->fetchColumn();
            $has_coa_col = ((int) $chk > 0);
        }
        $cols = $has_coa_col ? "type, coa_account_id" : "type, NULL AS coa_account_id";
        $stmt = $db->prepare("SELECT $cols FROM treasury_accounts WHERE id = ?");
        $stmt->execute([$treasury_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("JournalPoster: treasury account #{$treasury_id} not found.");

        if (!empty($row['coa_account_id'])) return (int) $row['coa_account_id'];

        $ohada = ($row['type'] === 'bank') ? '521' : '571';
        $coa = self::coaByOhada($db, $ohada);
        if (!$coa) throw new RuntimeException("JournalPoster: OHADA {$ohada} not mapped (treasury type {$row['type']}).");
        return $coa;
    }

    /** Best-effort: pick a treasury_accounts.id by payment method. */
    private static function resolveTreasuryAccount(PDO $db, string $method): ?int
    {
        $type = ($method === 'cash') ? 'caisse' : ($method === 'bank' ? 'bank' : $method);
        $stmt = $db->prepare("SELECT id FROM treasury_accounts WHERE type = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
        $stmt->execute([$type]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    }

    private static function roundFcfa(float $amount): float
    {
        return (float) round($amount, 0, PHP_ROUND_HALF_UP);
    }
}
