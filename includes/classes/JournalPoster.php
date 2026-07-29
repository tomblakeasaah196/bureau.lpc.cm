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
            // Preference order, most specific first:
            //   1. what the caller passed (the payment form's account picker)
            //   2. the account the invoice told the client to pay into
            //   3. the "first active account of this type" guess
            // Step 2 is new in Sprint 9. Without it the guess in step 3 was
            // always what ran, and it takes the lowest id — so with an Afriland
            // and a microfinance account both active, every bank payment landed
            // on whichever was created first regardless of where the money
            // actually went, and neither sub-ledger reconciled.
            $treasury_account_id = $treasury_account_id
                ?? self::settlementAccountForInvoice($db, $pay['invoice_id'] ?? null)
                ?? self::resolveTreasuryAccount($db, $pay['payment_method']);
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

        // Sprint 9 · migration 041: split the revenue credit across the accounts
        // the products themselves point at, instead of dumping the whole
        // subtotal on 701. Falls back to a single $revenue_coa line when the
        // mapping isn't available, so behaviour is unchanged pre-migration.
        $revenue_split = self::splitRevenueByProduct($db, (int) $invoice_id, $subtotal, $revenue_coa);

        $ref  = 'INV-' . $inv['reference'];
        $desc = "Émission facture #{$inv['reference']} (client #{$inv['client_id']})";
        $je_id = self::createDraftJe($db, $ref, 'VT', (string)$inv['date'], $desc, $user_id);
        if ($net_payable > 0) self::addLine($db, $je_id, $client_coa,  $net_payable, 0.0);
        if ($air > 0)         self::addLine($db, $je_id, $air_coa,     $air,          0.0);
        foreach ($revenue_split as $coa_id => $amount) {
            if ($amount > 0) self::addLine($db, $je_id, (int) $coa_id, 0.0, $amount);
        }
        if ($tva > 0)         self::addLine($db, $je_id, $tva_coa,     0.0, $tva);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Allocate an invoice subtotal across revenue accounts, one bucket per
     * distinct account the invoice's products resolve to.
     *
     * Resolution order per product (see migration 041):
     *   products.revenue_account_id            — per-SKU override, usually NULL
     *   product_categories.revenue_account_id  — the normal path
     *   $fallback_coa                          — 701, as before
     *
     * ROUNDING. Line shares are computed against the summed line values, then
     * scaled to the invoice subtotal (which can differ — discounts, manual
     * edits). FCFA has no subunit, so each bucket is rounded to a whole franc
     * and the residual is pushed onto the largest bucket. Without that last
     * step a three-category invoice can drift a franc or two and
     * post_journal_entry raises SIGNAL 45000, rolling back the whole invoice.
     *
     * @return array<int,float>  chart_of_accounts.id => credit amount
     */
    private static function splitRevenueByProduct(PDO $db, int $invoice_id, float $subtotal, int $fallback_coa): array
    {
        $subtotal = self::roundFcfa($subtotal);
        if ($subtotal <= 0) return [];
        if (!self::hasProductAccountMapping($db)) return [$fallback_coa => $subtotal];

        try {
            $stmt = $db->prepare("
                SELECT COALESCE(p.revenue_account_id, pc.revenue_account_id) AS coa_id,
                       SUM(ii.quantity * ii.unit_price)                      AS line_value
                  FROM invoice_items ii
                  LEFT JOIN products           p  ON p.id  = ii.product_id
                  LEFT JOIN product_categories pc ON pc.id = p.category_id
                 WHERE ii.invoice_id = ?
                 GROUP BY COALESCE(p.revenue_account_id, pc.revenue_account_id)
            ");
            $stmt->execute([$invoice_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Never let an accounting refinement break invoicing.
            error_log('JournalPoster::splitRevenueByProduct fell back to 701 — ' . $e->getMessage());
            return [$fallback_coa => $subtotal];
        }

        $total_lines = 0.0;
        foreach ($rows as $r) $total_lines += (float) $r['line_value'];
        if (!$rows || $total_lines <= 0) return [$fallback_coa => $subtotal];

        $buckets = [];
        foreach ($rows as $r) {
            $coa = !empty($r['coa_id']) ? (int) $r['coa_id'] : $fallback_coa;
            $buckets[$coa] = ($buckets[$coa] ?? 0.0)
                           + self::roundFcfa($subtotal * ((float) $r['line_value'] / $total_lines));
        }

        // Force the buckets to sum to the subtotal exactly.
        $drift = $subtotal - array_sum($buckets);
        if (abs($drift) >= 0.5) {
            $largest = array_keys($buckets, max($buckets))[0];
            $buckets[$largest] = self::roundFcfa($buckets[$largest] + $drift);
        }

        return $buckets;
    }

    /**
     * Cost of goods sold for a dispatch, valued at CUMP.
     *
     *   Debit  6031 Variations des stocks de marchandises   qty x cump
     *   Credit 31x  Stocks (per product category)           qty x cump
     *
     * WHY HERE: stock physically leaves in sales_controller::generate_dispatch,
     * which writes the inventory_movements 'out_delivery' rows. Until now
     * nothing mirrored that in the ledger, so stock value on the balance sheet
     * only ever grew — receptions debited 601 and updated products.cump, and
     * the outbound side was silent.
     *
     * Called from inside the caller's transaction, like every other method
     * here. A product with cump = 0 (never received through procurement)
     * contributes nothing and is skipped rather than posting a zero line.
     *
     * KNOWN LIMIT: this values delivery_items.quantity (dispatched), not
     * delivered_quantity (accepted after the client's adjustments). That is
     * deliberate — it mirrors inventory_movements, which also books the full
     * dispatched quantity as 'out_delivery' and handles refusals separately
     * via 'in_return_emp' / 'in_adjustment'. Stock and ledger therefore agree.
     * Wire the return path through reverseSource('delivery', $id) if you later
     * want partial refusals to unwind the COGS entry too.
     *
     * @param int   $delivery_id  deliveries.id (already inserted)
     * @param string $date        dispatch date, Y-m-d
     * @param string $reference   the BL reference, for the JE description
     * @return int|null           journal_entries.id, or null when there is
     *                            nothing to value (all CUMP zero, or the
     *                            account mapping isn't installed yet)
     */
    public static function postDeliveryCogs(int $delivery_id, string $date, string $reference): ?int
    {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        if (!self::hasProductAccountMapping($db)) return null;

        $cogs_default = self::coaByOhada($db, '6031');
        if (!$cogs_default) {
            error_log('JournalPoster::postDeliveryCogs — 6031 not mapped; apply migration 041. Skipping COGS.');
            return null;
        }

        // Value at CUMP, grouped by the stock account each product resolves to.
        $stmt = $db->prepare("
            SELECT COALESCE(p.stock_account_id, pc.stock_account_id) AS stock_coa,
                   COALESCE(p.cogs_account_id,  pc.cogs_account_id)  AS cogs_coa,
                   SUM(di.quantity * p.cump)                         AS value_out
              FROM delivery_items di
              JOIN products           p  ON p.id  = di.product_id
              LEFT JOIN product_categories pc ON pc.id = p.category_id
             WHERE di.delivery_id = ?
               AND p.cump > 0
             GROUP BY COALESCE(p.stock_account_id, pc.stock_account_id),
                      COALESCE(p.cogs_account_id,  pc.cogs_account_id)
        ");
        $stmt->execute([$delivery_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lines = [];
        $total = 0.0;
        foreach ($rows as $r) {
            $value = self::roundFcfa((float) $r['value_out']);
            if ($value <= 0) continue;
            if (empty($r['stock_coa'])) {
                // No stock account for this category — refuse to guess. The
                // dispatch still succeeds; the accountant sees the log.
                error_log("JournalPoster::postDeliveryCogs — delivery #{$delivery_id}: "
                        . "no stock account mapped for one category, {$value} FCFA not posted.");
                continue;
            }
            $lines[] = [
                'stock' => (int) $r['stock_coa'],
                'cogs'  => !empty($r['cogs_coa']) ? (int) $r['cogs_coa'] : $cogs_default,
                'value' => $value,
            ];
            $total += $value;
        }
        if ($total <= 0) return null;

        $je_id = self::createDraftJe(
            $db, 'COGS-' . $reference, 'OD', $date,
            "Sortie de stock sur BL {$reference} (valorisée au CUMP)", $user_id,
            'delivery', $delivery_id
        );
        foreach ($lines as $l) {
            self::addLine($db, $je_id, $l['cogs'],  $l['value'], 0.0);
            self::addLine($db, $je_id, $l['stock'], 0.0,         $l['value']);
        }
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * True once migration 041 has been applied — i.e. products carries the
     * account-override columns and product_categories exists.
     *
     * Cached per request. The two methods above call this so that an install
     * running the new code against an un-migrated database keeps posting
     * exactly as it did before rather than fatalling mid-invoice.
     */
    private static function hasProductAccountMapping(PDO $db): bool
    {
        static $has = null;
        if ($has !== null) return $has;

        $n = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'products'
               AND column_name IN ('category_id','revenue_account_id','stock_account_id','cogs_account_id')
        ")->fetchColumn();

        $t = (int) $db->query("
            SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE()
               AND table_name   = 'product_categories'
        ")->fetchColumn();

        return $has = ($n === 4 && $t === 1);
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

    // ========================================================================
    // PURCHASING
    // ------------------------------------------------------------------------
    // Added when the purchase module was migrated off its inline SQL (see
    // migration 038 for the full account of what that inline path got wrong).
    //
    // The three methods below are deliberately separate entries rather than one
    // combined entry per event. A purchase order can be cancelled after it has
    // been received, and cancellation has to unwind the goods, the rebate
    // earned and the rebate spent independently — a single fused entry would
    // force an all-or-nothing reversal.
    //
    // Over the full life of a discounted, fully received order the three
    // together produce:
    //     601  debit   S            (goods, at order prices)
    //     401  credit  S - D        (what the supplier is actually owed)
    //     4098 debit   R - D        (rebate earned but not yet spent)
    //     6019 credit  R            (rebate income, reducing cost of goods)
    // where S is the received subtotal, R = S x 2.47% and D the discount
    // applied. Each line is individually balanced, and 4098 tracks the same
    // number supplier_rebate_ledger shows in the Ristournes SDP panel.
    // ========================================================================

    /**
     * Goods received against a purchase order.
     *
     *   Debit  601  Achats de marchandises      — the value received
     *   Credit 401x the supplier's own account  — what we now owe them
     *
     * $goods_value must be computed from purchase_order_items, never from the
     * request body. The reception endpoint used to take unit prices straight
     * off the client payload, which let a caller book any purchase value it
     * liked and mint rebate to match.
     *
     * @return int journal_entries.id
     */
    public static function postGoodsReceipt(
        int $po_id,
        int $supplier_id,
        float $goods_value,
        string $date,
        string $po_reference
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $goods_value = self::roundFcfa($goods_value);
        if ($goods_value <= 0) {
            throw new RuntimeException("JournalPoster: goods receipt for PO #{$po_id} has non-positive value.");
        }

        $purchases_coa = self::coaByOhada($db, '601');
        if (!$purchases_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 601 (Achats de marchandises) is not mapped in chart_of_accounts. Apply migration 038."
            );
        }
        $supplier_coa = self::coaForSupplier($db, $supplier_id);

        $je_id = self::createDraftJe(
            $db, 'JRN-AC-' . date('ym'), 'AC', $date,
            "Réception achat réf: {$po_reference}", $user_id,
            'purchase_order', $po_id
        );
        self::addLine($db, $je_id, $purchases_coa, $goods_value, 0.0);
        self::addLine($db, $je_id, $supplier_coa,  0.0, $goods_value);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * SDP ristourne earned on reception (2.47% of the value actually received).
     *
     *   Debit  4098 Fournisseurs - RRR à obtenir  — the supplier now owes us this
     *   Credit 6019 RRR obtenus sur achats        — contra-expense, reduces COGS
     *
     * Booked at reception rather than when the rebate is spent, because that is
     * when it is earned: the goods have arrived and the entitlement is
     * unconditional. Recognising it only on use would understate both the
     * balance sheet and the margin for as long as the credit sits unused.
     *
     * @return int journal_entries.id
     */
    public static function postRebateAccrual(
        int $po_id,
        int $supplier_id,
        float $amount,
        string $date,
        string $po_reference
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) {
            throw new RuntimeException("JournalPoster: rebate accrual for PO #{$po_id} is non-positive.");
        }

        $receivable_coa = self::coaByOhada($db, '4098');
        $income_coa     = self::coaByOhada($db, '6019');
        if (!$receivable_coa || !$income_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 4098/6019 not mapped in chart_of_accounts. Apply migration 038."
            );
        }

        $je_id = self::createDraftJe(
            $db, 'JRN-AC-' . date('ym'), 'AC', $date,
            "Ristourne 2,47% acquise — réf: {$po_reference}", $user_id,
            'rebate_accrual', $po_id
        );
        self::addLine($db, $je_id, $receivable_coa, $amount, 0.0);
        self::addLine($db, $je_id, $income_coa,     0.0, $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Ristourne spent as a discount on a new order.
     *
     *   Debit  401x the supplier's account       — we owe them that much less
     *   Credit 4098 Fournisseurs - RRR à obtenir — the credit is consumed
     *
     * Posted at order creation, which is where procurement_controller writes
     * the matching 'deduction' row into supplier_rebate_ledger. Keeping both
     * writes in the same transaction is what stops the Ristournes SDP panel and
     * the general ledger from drifting apart.
     *
     * Until the goods arrive this leaves the supplier account carrying a debit
     * — correct, and it is precisely a credit note held against them. The
     * reception entry then credits the gross value, leaving the net payable.
     *
     * @return int journal_entries.id
     */
    public static function postRebateUsage(
        int $po_id,
        int $supplier_id,
        float $amount,
        string $date,
        string $po_reference
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) {
            throw new RuntimeException("JournalPoster: rebate usage for PO #{$po_id} is non-positive.");
        }

        $receivable_coa = self::coaByOhada($db, '4098');
        if (!$receivable_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 4098 not mapped in chart_of_accounts. Apply migration 038."
            );
        }
        $supplier_coa = self::coaForSupplier($db, $supplier_id);

        $je_id = self::createDraftJe(
            $db, 'JRN-AC-' . date('ym'), 'AC', $date,
            "Utilisation ristourne — réf: {$po_reference}", $user_id,
            'rebate_usage', $po_id
        );
        self::addLine($db, $je_id, $supplier_coa,   $amount, 0.0);
        self::addLine($db, $je_id, $receivable_coa, 0.0, $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Reverse every posted entry produced by a given source document.
     *
     * Migration 004 forbids deleting a posted entry, and rightly so — the
     * correction for a mistake in the books is an opposing entry, not an
     * erasure. This mirrors each line (debits become credits), posts the
     * mirror, then stamps the original 'reversed' and points it at its mirror.
     *
     * The reversal is dated today, not on the original entry's date: back-
     * dating it would drop the correction into a period that may already be
     * closed, and migration 005's triggers would refuse the write anyway.
     *
     * Entries already marked 'reversed' are skipped, so calling this twice on
     * the same document is harmless.
     *
     * @param  string   $source_type e.g. 'purchase_order', 'rebate_accrual'
     * @param  int      $source_id
     * @param  string   $reason      shown in the reversing entry's description
     * @return int[]                 ids of the reversing entries created
     */
    public static function reverseSource(string $source_type, int $source_id, string $reason = ''): array
    {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        if (!self::hasSourceColumns($db)) {
            throw new RuntimeException(
                "JournalPoster: cannot reverse by source — migration 038 has not been applied."
            );
        }

        $stmt = $db->prepare("
            SELECT id, reference, journal_code, description
              FROM journal_entries
             WHERE source_type = ? AND source_id = ? AND status = 'posted'
             ORDER BY id ASC
             FOR UPDATE
        ");
        $stmt->execute([$source_type, $source_id]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $lineStmt = $db->prepare("SELECT account_id, debit, credit FROM journal_lines WHERE journal_entry_id = ?");
        $created  = [];

        foreach ($entries as $entry) {
            $lineStmt->execute([$entry['id']]);
            $lines = $lineStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) continue;

            $desc = 'EXTOURNE — ' . $entry['description'];
            if ($reason !== '') $desc .= " ({$reason})";

            $rev_id = self::createDraftJe(
                $db, 'JRN-EXT-' . date('ym'), $entry['journal_code'], date('Y-m-d'),
                $desc, $user_id, $source_type . '_reversal', $source_id
            );

            // Swap each line. debit XOR credit is enforced by migration 004's
            // bi_jl_xor trigger, so a mirrored line stays valid by construction.
            foreach ($lines as $l) {
                self::addLine($db, $rev_id, (int) $l['account_id'], (float) $l['credit'], (float) $l['debit']);
            }
            self::post($db, $rev_id, $user_id);

            $db->prepare("UPDATE journal_entries SET status = 'reversed', reversed_by_entry_id = ? WHERE id = ?")
               ->execute([$rev_id, $entry['id']]);

            $created[] = $rev_id;
        }

        return $created;
    }

    // ------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------

    /**
     * The supplier's own 401xxx sub-account.
     *
     * mdm_controller creates one per supplier at registration and stores it on
     * suppliers.account_id. Resolving it here — instead of the old
     * `code LIKE '401%' LIMIT 1` — is what makes the accounts-payable
     * sub-ledger mean anything: before, every supplier's debt landed on
     * whichever 401 row sorted first.
     *
     * Throws rather than falling back. A supplier with no account is a data
     * problem to fix in Fournisseurs, and silently booking their purchases
     * onto someone else's account is how the books got into this state.
     */
    private static function coaForSupplier(PDO $db, int $supplier_id): int
    {
        $stmt = $db->prepare("
            SELECT s.account_id, s.name, c.id AS coa_id
              FROM suppliers s
              LEFT JOIN chart_of_accounts c ON c.id = s.account_id
             WHERE s.id = ?
        ");
        $stmt->execute([$supplier_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("JournalPoster: supplier #{$supplier_id} not found.");
        }
        if (empty($row['coa_id'])) {
            throw new RuntimeException(
                "JournalPoster: supplier « {$row['name']} » has no chart-of-accounts entry. "
                . "Open Fournisseurs and re-save the supplier to generate its 401 account."
            );
        }
        return (int) $row['coa_id'];
    }

    private static function createDraftJe(
        PDO $db,
        string $ref,
        string $journal_code,
        string $date,
        string $desc,
        int $user_id,
        ?string $source_type = null,
        ?int $source_id = null
    ): int {
        // Append a random suffix so re-runs in the same second still get a
        // unique reference (matches the existing pattern in inventory_controller).
        $ref .= '-' . strtoupper(bin2hex(random_bytes(2)));

        // source_type / source_id arrived in migration 038 and let an entry be
        // traced back to the document that produced it. Detected rather than
        // assumed: this class is loaded on installs that may not have run 038
        // yet, and an unconditional INSERT naming a missing column would take
        // down every payment and invoice posting, not just purchasing.
        if (self::hasSourceColumns($db)) {
            $db->prepare("
                INSERT INTO journal_entries
                    (reference, journal_code, date, description, status, created_by, source_type, source_id)
                VALUES (?, ?, ?, ?, 'draft', ?, ?, ?)
            ")->execute([$ref, $journal_code, $date, $desc, $user_id, $source_type, $source_id]);
        } else {
            $db->prepare("
                INSERT INTO journal_entries (reference, journal_code, date, description, status, created_by)
                VALUES (?, ?, ?, ?, 'draft', ?)
            ")->execute([$ref, $journal_code, $date, $desc, $user_id]);
        }
        return (int) $db->lastInsertId();
    }

    /** True once migration 038 has added journal_entries.source_type. Cached per request. */
    private static function hasSourceColumns(PDO $db): bool
    {
        static $has = null;
        if ($has !== null) return $has;
        $chk = $db->query("
            SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name   = 'journal_entries'
               AND column_name  = 'source_type'
        ")->fetchColumn();
        return $has = ((int) $chk > 0);
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
     * treasury_accounts.type → the OHADA parent its movements belong under.
     *
     * Sprint 9 — this replaced:
     *
     *     $ohada = ($row['type'] === 'bank') ? '521' : '571';
     *
     * which sent everything that was not a bank to 571 Caisse, so MTN MoMo and
     * Orange Money collections were booked as physical cash. That makes the
     * Trésorerie cash reconciliation unusable — a physical count can never
     * match a balance carrying electronic float — and it is not what SYSCOHADA
     * révisé says: class 55 exists precisely to stop the approximation.
     *
     *   521 Banques
     *   552 Monnaie électronique — téléphone portable   (MoMo / Orange Money)
     *   554 Porte-monnaie électronique
     *   571 Caisse                                      (physical cash only)
     *
     * Operator commissions are NOT netted here. They are entered by hand as a
     * treasury expense against 6317 (Frais sur instruments de monnaie
     * électronique) at payment time, so the collection books gross.
     *
     * 552 and 6317 are seeded by migration 044 — migration 003 only ever
     * created seven parents and neither of these was among them.
     */
    private const TREASURY_OHADA = [
        'bank'          => '521',
        'momo'          => '552',
        'mobile_money'  => '552',
        'wallet'        => '554',
        'caisse'        => '571',
        'cash'          => '571',
    ];

    /**
     * Map a treasury_accounts row to a chart_of_accounts.id.
     *
     * Prefers an explicit coa_account_id — that is how each account gets its
     * own sub-ledger (5211 Afriland, 5212 microfinance, 5521 MTN MoMo), so a
     * journal line can be traced back to one statement. Falls back to the
     * shared parent above when the column is absent or unset.
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

        $type  = (string) $row['type'];
        $ohada = self::TREASURY_OHADA[$type] ?? null;
        if ($ohada === null) {
            // Fail loudly rather than silently dumping an unknown instrument
            // into Caisse, which is exactly how MoMo ended up there.
            throw new RuntimeException(
                "JournalPoster: treasury type '{$type}' has no OHADA mapping. "
                . "Add it to JournalPoster::TREASURY_OHADA."
            );
        }
        $coa = self::coaByOhada($db, $ohada);
        if (!$coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA {$ohada} not mapped (treasury type {$type}). "
                . "Run migration 044 — it seeds 552 and 6317."
            );
        }
        return $coa;
    }

    /**
     * The account the invoice advertised as the one to settle into.
     * Returns null before migration 044, or for a payment with no invoice.
     */
    private static function settlementAccountForInvoice(PDO $db, $invoice_id): ?int
    {
        if (empty($invoice_id)) return null;

        static $has_col = null;
        if ($has_col === null) {
            $has_col = (int) $db->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'invoices'
                   AND column_name = 'settlement_account_id'
            ")->fetchColumn() > 0;
        }
        if (!$has_col) return null;

        $stmt = $db->prepare("SELECT settlement_account_id FROM invoices WHERE id = ?");
        $stmt->execute([(int) $invoice_id]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    /**
     * Last-resort guess: the first active account of the matching type.
     *
     * This is a guess, not a resolution — with two active bank accounts it
     * silently picks one. It is kept only so a payment can still post on a
     * system that has not selected an account anywhere; anything reaching it
     * is worth a log line, because the books it produces may need correcting.
     */
    private static function resolveTreasuryAccount(PDO $db, string $method): ?int
    {
        $type = ($method === 'cash') ? 'caisse' : ($method === 'bank' ? 'bank' : $method);

        // sort_order first (migration 044 seeds it from creation order), so the
        // guess at least matches the account that appears first in the UI.
        $order = self::hasColumn($db, 'treasury_accounts', 'sort_order')
            ? 'sort_order ASC, id ASC'
            : 'id ASC';

        $stmt = $db->prepare("SELECT id FROM treasury_accounts WHERE type = ? AND status = 'active' ORDER BY {$order}");
        $stmt->execute([$type]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$ids) return null;

        if (count($ids) > 1) {
            error_log(
                "JournalPoster: guessing treasury account #{$ids[0]} for method '{$method}' — "
                . count($ids) . " active accounts of this type exist and none was specified. "
                . "Set invoices.settlement_account_id or pass the account explicitly."
            );
        }
        return (int) $ids[0];
    }

    /** Cached information_schema probe, so migration-optional columns are cheap. */
    private static function hasColumn(PDO $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (!array_key_exists($key, $cache)) {
            $stmt = $db->prepare("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
            ");
            $stmt->execute([$table, $column]);
            $cache[$key] = ((int) $stmt->fetchColumn() > 0);
        }
        return $cache[$key];
    }

    private static function roundFcfa(float $amount): float
    {
        return (float) round($amount, 0, PHP_ROUND_HALF_UP);
    }
}
