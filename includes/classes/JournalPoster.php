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

        // 2b. OVERPAYMENT SPLIT — SYSCOHADA audit fix.
        //
        // Both callers push anything paid above the invoice total into
        // client_wallets, which is the operational face of 419 (Clients,
        // avances et acomptes reçus). The ledger did not follow: the whole
        // receipt was credited to 411, so an overpaid client ended up carrying
        // a DEBIT balance on their receivable account — an asset account
        // reading negative — while 419 stayed empty and the wallet table showed
        // a credit that appeared nowhere in the books. The balance sheet
        // understated liabilities by every wallet balance in the system.
        //
        // The receipt is now split at the invoice's open balance: what clears
        // the debt goes to 411, the excess goes to 419 where the wallet says it
        // is. Payments already validated are excluded by id so a re-run values
        // the same open balance, and the entry balances either way.
        $settled = $amount + $withheld;
        $advance = 0.0;
        $advance_coa = null;

        if ($pay['invoice_id']) {
            $bal = $db->prepare("
                SELECT i.total_amount,
                       COALESCE((SELECT SUM(p2.amount + COALESCE(p2.air_withheld_amount, 0))
                                   FROM payments p2
                                  WHERE p2.invoice_id = i.id
                                    AND p2.status = 'validated'
                                    AND p2.id <> ?), 0) AS already_paid
                  FROM invoices i WHERE i.id = ?
            ");
            $bal->execute([$payment_id, (int) $pay['invoice_id']]);
            if ($row = $bal->fetch(PDO::FETCH_ASSOC)) {
                $open = self::roundFcfa(max(0.0, (float) $row['total_amount'] - (float) $row['already_paid']));
                if ($settled > $open) {
                    $advance = self::roundFcfa($settled - $open);
                    $settled = $open;
                    $advance_coa = self::coaByOhada($db, '419');
                    if (!$advance_coa) {
                        throw new RuntimeException(
                            "JournalPoster: OHADA 419 (avances clients) not mapped — cannot book the "
                            . "overpayment on payment #{$payment_id}. Run migration 020."
                        );
                    }
                }
            }
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
        // $settled + $advance == $amount + $withheld by construction, so the
        // entry balances whether or not the payment overshot the invoice.
        if ($settled > 0) self::addLine($db, $je_id, $credit_coa,  0.0, $settled);
        if ($advance > 0) self::addLine($db, $je_id, $advance_coa, 0.0, $advance);
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
     * Lines (SYSCOHADA révisé):
     *   Debit  411  Clients                    net_payable   (to be received in cash)
     *   Debit  4424 AIR retenu (à récup.)      air_amount    (client withholds)
     *   Debit  4473 Précompte subi             precompte_amount
     *   Debit  673  Escomptes accordés         escompte_amount
     *   Credit 701  Ventes (or 706 Services)   subtotal      (NET of any remise)
     *   Credit 4461 Droits d'accises           excise_amount
     *   Credit 4431 TVA facturée sur ventes    tva_amount
     *
     * WHAT CHANGED AND WHY (SYSCOHADA compliance audit):
     *
     *   · OUTPUT VAT MOVED 4432 → 4431. SYSCOHADA révisé numbers 443 as
     *     4431 TVA facturée sur ventes / 4432 TVA facturée sur prestations de
     *     services. Everything LPC invoices is a sale of goods, so 4431 is the
     *     account; 4432 as migration 020 labelled it ("TVA collectée à
     *     décaisser") is not a SYSCOHADA account at all. TVA due is
     *     (4431+4432) − (4451+4452+4453+4454); collecting sales VAT in 4432
     *     did not break that arithmetic, but it does break the DSF and any
     *     e-bilan filing built off the account numbers, and it silently
     *     merged goods and services revenue into one VAT line.
     *
     *   · DROITS D'ACCISES ARE POSTED. Migration 040 added excise_rate /
     *     excise_amount and stated the TVA base is HT + accises, but nothing
     *     ever booked the accise. It was collected from the client inside
     *     total_amount and never appeared as a liability to the State.
     *
     *   · ESCOMPTE IS FINANCIAL, NOT COMMERCIAL. A settlement discount goes to
     *     673, never netted into 701 — that is the whole point of separating
     *     the trading margin from the financial result. A commercial reduction
     *     granted ON the invoice is the opposite case: SYSCOHADA nets it into
     *     the 701 credit and it never appears as its own line, which is why
     *     invoices.discount_amount is subtracted into `subtotal` upstream and
     *     is not booked here. Only a reduction granted AFTER the invoice, by
     *     credit note, hits 7019.
     *
     *   · PRÉCOMPTE SUFFERED IS AN ASSET. Same treatment as the AIR: the
     *     client keeps it and remits it for us, so it clears 411 and sits in
     *     4473 until it is imputed against the income tax.
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

        // Migration-optional columns are probed rather than assumed: this class
        // is loaded on installs that may not have run 040 / 065 yet, and naming
        // a missing column here would take down every invoice, not just the
        // ones carrying an accise.
        $sel = ['id', 'reference', 'client_id', 'date', 'subtotal', 'tva_amount',
                'total_amount',
                'COALESCE(air_amount, 0)  AS air_amount',
                'COALESCE(net_payable, total_amount) AS net_payable'];
        foreach (['excise_amount', 'precompte_amount', 'escompte_amount'] as $opt) {
            $sel[] = self::hasColumn($db, 'invoices', $opt)
                ? "COALESCE({$opt}, 0) AS {$opt}"
                : "0 AS {$opt}";
        }
        $stmt = $db->prepare('SELECT ' . implode(', ', $sel) . ' FROM invoices WHERE id = ? FOR UPDATE');
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) throw new RuntimeException("JournalPoster: invoice #{$invoice_id} not found.");

        $subtotal    = self::roundFcfa((float) $inv['subtotal']);
        $tva         = self::roundFcfa((float) $inv['tva_amount']);
        $air         = self::roundFcfa((float) $inv['air_amount']);
        $excise      = self::roundFcfa((float) $inv['excise_amount']);
        $precompte   = self::roundFcfa((float) $inv['precompte_amount']);
        $escompte    = self::roundFcfa((float) $inv['escompte_amount']);
        $net_payable = self::roundFcfa((float) $inv['net_payable']);

        // Sanity: everything the client owes us, however it is settled, must
        // equal everything we recognised. Debits = net cash + what third
        // parties keep on our behalf + what we gave away for early payment;
        // credits = revenue + the taxes we collected for the State.
        $debits  = self::roundFcfa($net_payable + $air + $precompte + $escompte);
        $credits = self::roundFcfa($subtotal + $excise + $tva);
        if ($debits !== $credits) {
            throw new RuntimeException(
                "JournalPoster: invoice #{$invoice_id} does not reconcile — "
                . "net_payable({$net_payable}) + AIR({$air}) + précompte({$precompte}) + escompte({$escompte}) "
                . "= {$debits}, but HT({$subtotal}) + accises({$excise}) + TVA({$tva}) = {$credits}."
            );
        }

        $client_coa    = self::coaByOhada($db, '411');
        $revenue_coa   = self::coaByOhada($db, $revenue_ohada) ?: self::coaByOhada($db, '701');
        $tva_coa       = ($tva > 0)       ? self::coaByOhada($db, '4431') : null;
        $air_coa       = ($air > 0)       ? self::coaByOhada($db, '4424') : null;
        $excise_coa    = ($excise > 0)    ? self::coaByOhada($db, '4461') : null;
        $precompte_coa = ($precompte > 0) ? self::coaByOhada($db, '4473') : null;
        $escompte_coa  = ($escompte > 0)  ? self::coaByOhada($db, '673')  : null;

        if (!$client_coa)  throw new RuntimeException("JournalPoster: 411 (Clients) not mapped.");
        if (!$revenue_coa) throw new RuntimeException("JournalPoster: {$revenue_ohada} (Ventes) not mapped.");
        if ($tva > 0 && !$tva_coa) throw new RuntimeException("JournalPoster: 4431 (TVA facturée sur ventes) not mapped — apply migration 065.");
        if ($air > 0 && !$air_coa) throw new RuntimeException("JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020.");
        if ($excise > 0 && !$excise_coa) throw new RuntimeException("JournalPoster: 4461 (droits d'accises) not mapped — apply migration 065.");
        if ($precompte > 0 && !$precompte_coa) throw new RuntimeException("JournalPoster: 4473 (précompte subi) not mapped — apply migration 065.");
        if ($escompte > 0 && !$escompte_coa) throw new RuntimeException("JournalPoster: 673 (escomptes accordés) not mapped — apply migration 065.");

        // Sprint 9 · migration 041: split the revenue credit across the accounts
        // the products themselves point at, instead of dumping the whole
        // subtotal on 701. Falls back to a single $revenue_coa line when the
        // mapping isn't available, so behaviour is unchanged pre-migration.
        $revenue_split = self::splitRevenueByProduct($db, (int) $invoice_id, $subtotal, $revenue_coa);

        $ref  = 'INV-' . $inv['reference'];
        $desc = "Émission facture #{$inv['reference']} (client #{$inv['client_id']})";
        // source_type/source_id so the entry is traceable back to the invoice
        // and can be extourned with reverseSource('invoice', $id) — the sale
        // was the only document type still posting untraceably.
        $je_id = self::createDraftJe($db, $ref, 'VT', (string)$inv['date'], $desc, $user_id,
                                     'invoice', (int) $invoice_id);
        if ($net_payable > 0) self::addLine($db, $je_id, $client_coa,    $net_payable, 0.0);
        if ($air > 0)         self::addLine($db, $je_id, $air_coa,       $air,         0.0);
        if ($precompte > 0)   self::addLine($db, $je_id, $precompte_coa, $precompte,   0.0);
        if ($escompte > 0)    self::addLine($db, $je_id, $escompte_coa,  $escompte,    0.0);
        foreach ($revenue_split as $coa_id => $amount) {
            if ($amount > 0) self::addLine($db, $je_id, (int) $coa_id, 0.0, $amount);
        }
        if ($excise > 0)      self::addLine($db, $je_id, $excise_coa,  0.0, $excise);
        if ($tva > 0)         self::addLine($db, $je_id, $tva_coa,     0.0, $tva);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Reduction granted to a client AFTER the invoice — an avoir / credit note.
     *
     *   Debit  7019 RRR accordés par l'entreprise   the reduction, HT
     *   Debit  4431 TVA facturée sur ventes         the VAT given back
     *   Credit 411  Clients                         what the client no longer owes
     *
     * WHY THIS IS SEPARATE FROM invoices.discount_amount. SYSCOHADA draws a
     * hard line between the two, and it is not a stylistic one:
     *
     *   · A reduction granted ON the invoice never appears in the books. The
     *     invoice is issued for the net, 701 is credited with the net, and the
     *     VAT base is the net. There is nothing to book because nothing was
     *     ever recognised at the gross.
     *   · A reduction granted AFTER the invoice reverses revenue that HAS been
     *     recognised, so it must be visible as such — 7019 is a contra-revenue
     *     account precisely so the reduction shows against turnover instead of
     *     quietly shrinking it.
     *
     * Netting a post-invoice avoir into 701 would understate turnover for the
     * period, which is the figure the patente and the CA thresholds are
     * assessed on.
     *
     * @param int    $invoice_id  the invoice being credited
     * @param float  $amount_ht   reduction excluding tax
     * @param float  $vat_amount  VAT reversed with it (0 on an exonerated sale)
     * @param string $date        Y-m-d
     * @param string $reference   the credit note's own reference
     * @param string $reason      shown in the entry description
     * @return int                journal_entries.id
     */
    public static function postSalesCreditNote(
        int $invoice_id,
        float $amount_ht,
        float $vat_amount,
        string $date,
        string $reference,
        string $reason = ''
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount_ht  = self::roundFcfa($amount_ht);
        $vat_amount = self::roundFcfa($vat_amount);
        if ($amount_ht <= 0) {
            throw new RuntimeException("JournalPoster: credit note for invoice #{$invoice_id} is non-positive.");
        }

        $rrr_coa    = self::coaByOhada($db, '7019');
        $client_coa = self::coaByOhada($db, '411');
        $tva_coa    = ($vat_amount > 0) ? self::coaByOhada($db, '4431') : null;

        if (!$rrr_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 7019 (RRR accordés) not mapped in chart_of_accounts. Apply migration 065."
            );
        }
        if (!$client_coa) throw new RuntimeException("JournalPoster: 411 (Clients) not mapped.");
        if ($vat_amount > 0 && !$tva_coa) {
            throw new RuntimeException("JournalPoster: 4431 (TVA facturée sur ventes) not mapped — apply migration 065.");
        }

        $desc = "Avoir sur facture #{$invoice_id}" . ($reason !== '' ? " ({$reason})" : '');
        $je_id = self::createDraftJe($db, 'AV-' . $reference, 'VT', $date, $desc, $user_id,
                                     'invoice_credit_note', $invoice_id);
        self::addLine($db, $je_id, $rrr_coa, $amount_ht, 0.0);
        if ($vat_amount > 0) self::addLine($db, $je_id, $tva_coa, $vat_amount, 0.0);
        self::addLine($db, $je_id, $client_coa, 0.0, $amount_ht + $vat_amount);
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
        //
        // array_search with $strict = true, not array_keys($buckets, max(...)).
        // The loose form compares with ==, and PHP's == on a float and a
        // numeric string — which is what the bucket keys and any string-ish
        // value coming back through PDO can be — is not the comparison anyone
        // intends here. max() returns an element of the array, so a strict
        // search always finds it; the loose one could match a different bucket
        // that merely compares equal and push the residual onto the wrong
        // revenue account. Small money, wrong account, silently.
        $drift = $subtotal - array_sum($buckets);
        if (abs($drift) >= 0.5) {
            $largest = array_search(max($buckets), $buckets, true);
            if ($largest !== false) {
                $buckets[$largest] = self::roundFcfa($buckets[$largest] + $drift);
            }
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
     *
     * $entry_date — the date the charge actually settled. Optional and defaulting
     * to today so the existing treasury_controller call site is unaffected, but
     * Gestion des Dépenses passes the real payment date. Without it, marking a
     * July invoice paid in August booked the entry in August: the expense showed
     * in the July budget (which reads expenses.expense_date) while the ledger and
     * every OHADA report showed it in August. The two never reconciled, and the
     * discrepancy is invisible until someone closes the month.
     */
    public static function postExpense(int $expense_coa_id, int $treasury_account_id, float $amount, string $description, string $category = '', ?string $entry_date = null): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) throw new RuntimeException("JournalPoster::postExpense — non-positive amount.");

        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);
        $ref  = 'EXP-' . strtoupper(bin2hex(random_bytes(3)));
        $desc = $description !== '' ? $description : 'Dépense trésorerie';
        if ($category !== '') $desc .= " [{$category}]";

        $date = ($entry_date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry_date))
            ? $entry_date
            : date('Y-m-d');

        $je_id = self::createDraftJe($db, $ref, 'OD', $date, $desc, $user_id);
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
     *   Debit  601  Achats de marchandises        — the value received, HT
     *   Debit  4452 TVA récupérable sur achats    — the deductible input VAT
     *   Credit 401x the supplier's own account    — what we now owe them, TTC
     *
     * THE VAT SPLIT IS THE FIX. $goods_value is TTC — purchase order prices are
     * entered TTC and always have been, which save_po and receive_po both state
     * outright and both rely on when they back the ristourne base out with
     * `$ttc / (1 + vat_rate)`. This method used to debit that TTC figure to 601
     * and credit the same number to 401. Three things followed, all of them
     * live in the books today:
     *
     *   1. 601 Achats carried 19,25 % of VAT that is not a cost at all.
     *   2. 445x TVA récupérable was never debited by anything, anywhere. The
     *      input credit simply did not exist in the ledger, so TVA due
     *      computed from the accounts overstated the liability by the entire
     *      deductible amount, every single month.
     *   3. products.cump was fed the same TTC unit price, so 6031 (COGS) and
     *      31x (stock) inherited the VAT — inventory overstated on the balance
     *      sheet, gross margin understated on the résultat.
     *
     * $vat_amount defaults to 0, which reproduces the old single-line
     * behaviour exactly. A caller that has not been updated, or a purchase
     * with no recoverable VAT (exonerated goods — half the catalogue is water,
     * art. 128 CGI — or a supplier outside the régime réel), posts the whole
     * TTC to 601 and that is the correct treatment: non-deductible VAT IS part
     * of the cost of the goods.
     *
     * $goods_value must be computed from purchase_order_items, never from the
     * request body. The reception endpoint used to take unit prices straight
     * off the client payload, which let a caller book any purchase value it
     * liked and mint rebate to match.
     *
     * @param float $goods_value  TTC value received (HT + recoverable VAT)
     * @param float $vat_amount   the recoverable portion; 0 when not deductible
     * @return int journal_entries.id
     */
    public static function postGoodsReceipt(
        int $po_id,
        int $supplier_id,
        float $goods_value,
        string $date,
        string $po_reference,
        float $vat_amount = 0.0
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $goods_value = self::roundFcfa($goods_value);
        $vat_amount  = self::roundFcfa($vat_amount);
        if ($goods_value <= 0) {
            throw new RuntimeException("JournalPoster: goods receipt for PO #{$po_id} has non-positive value.");
        }
        if ($vat_amount < 0 || $vat_amount >= $goods_value) {
            // VAT at or above the gross means the caller passed HT as TTC, or
            // an absurd rate. Refuse rather than post a zero/negative 601 line.
            throw new RuntimeException(
                "JournalPoster: PO #{$po_id} — recoverable VAT ({$vat_amount}) is not a valid "
                . "fraction of the TTC value ({$goods_value})."
            );
        }
        $goods_ht = self::roundFcfa($goods_value - $vat_amount);

        $purchases_coa = self::coaByOhada($db, '601');
        if (!$purchases_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 601 (Achats de marchandises) is not mapped in chart_of_accounts. Apply migration 038."
            );
        }
        $vat_coa = null;
        if ($vat_amount > 0) {
            $vat_coa = self::coaByOhada($db, '4452');
            if (!$vat_coa) {
                throw new RuntimeException(
                    "JournalPoster: OHADA 4452 (TVA récupérable sur achats) is not mapped in "
                    . "chart_of_accounts. Apply migration 065 — note that migration 020 seeded "
                    . "4451/4452 inverted against SYSCOHADA révisé and 065 corrects them."
                );
            }
        }
        $supplier_coa = self::coaForSupplier($db, $supplier_id);

        $je_id = self::createDraftJe(
            $db, 'JRN-AC-' . date('ym'), 'AC', $date,
            "Réception achat réf: {$po_reference}", $user_id,
            'purchase_order', $po_id
        );
        self::addLine($db, $je_id, $purchases_coa, $goods_ht, 0.0);
        if ($vat_amount > 0) self::addLine($db, $je_id, $vat_coa, $vat_amount, 0.0);
        self::addLine($db, $je_id, $supplier_coa,  0.0, $goods_value);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Ristourne earned on reception — Debit 4098 / Credit 6019.
     *
     *   Debit  4098 Fournisseurs - RRR à obtenir  — the supplier now owes us this
     *   Credit 6019 RRR obtenus sur achats        — contra-expense, reduces COGS
     *
     * Booked at reception rather than when the rebate is spent, because that is
     * when it is earned: the goods have arrived and the entitlement is
     * unconditional. Recognising it only on use would understate both the
     * balance sheet and the margin for as long as the credit sits unused.
     *
     * $narration: migration 052 moved ristourne from one blanket per-supplier
     * rate to a tiered ladder per (supplier, category), so there is no longer
     * one true rate to hardcode into the description — the caller (one call
     * per category, in api/v1/inventory_controller.php::receive_po) passes
     * the actual category + rate that fired. The old default below
     * ("Ristourne 2,47% acquise") is kept ONLY as a fallback for a caller that
     * doesn't supply one; it is no longer accurate for anything posted after
     * migration 052 and should not be relied on. Already-posted entries under
     * the old wording are historical fact and are never rewritten.
     *
     * @return int journal_entries.id
     */
    public static function postRebateAccrual(
        int $po_id,
        int $supplier_id,
        float $amount,
        string $date,
        string $po_reference,
        ?string $narration = null,
        float $vat_withheld = 0.0,
        float $precompte_withheld = 0.0
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount             = self::roundFcfa($amount);
        $vat_withheld       = self::roundFcfa($vat_withheld);
        $precompte_withheld = self::roundFcfa($precompte_withheld);
        if ($amount <= 0) {
            throw new RuntimeException("JournalPoster: rebate accrual for PO #{$po_id} is non-positive.");
        }
        if ($vat_withheld < 0 || $precompte_withheld < 0) {
            throw new RuntimeException("JournalPoster: PO #{$po_id} — withheld amounts cannot be negative.");
        }

        $receivable_coa = self::coaByOhada($db, '4098');
        $income_coa     = self::coaByOhada($db, '6019');
        if (!$receivable_coa || !$income_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 4098/6019 not mapped in chart_of_accounts. Apply migration 038."
            );
        }

        // WHAT THE WITHHOLDINGS ARE AND WHY THEY NOW HAVE LINES.
        //
        // receive_po computes the rebate as
        //     gross = HT × tier_rate
        //     net   = gross × (1 − vat_rate − precompte_rate)
        // and used to post only `net`, to both 4098 and 6019. The difference —
        // the VAT and the précompte the supplier keeps back — was booked
        // nowhere at all. Two things were therefore wrong at once:
        //
        //   · 6019 recognised only the net, so the reduction in cost of goods
        //     was understated by the withheld tax, which is a real reduction
        //     of what the purchase cost us.
        //   · The précompte and the VAT withheld are amounts the company can
        //     impute against its own tax. Never recording them means never
        //     claiming them.
        //
        // The gross is now credited to 6019 and the withheld portions are
        // debited to the accounts that carry them, so the entry balances at
        //     net + VAT + précompte = gross
        // and — this is the part that matters operationally — 4098 still
        // carries exactly `net`, unchanged. The Ristournes SDP panel reads the
        // same number it always did, so the panel and the ledger stay in
        // agreement and no historical balance is restated.
        //
        // Both parameters default to 0, which reproduces the old two-line
        // entry byte for byte for any caller not yet passing them.
        $gross = self::roundFcfa($amount + $vat_withheld + $precompte_withheld);

        $vat_coa = null;
        if ($vat_withheld > 0) {
            $vat_coa = self::coaByOhada($db, '4452');
            if (!$vat_coa) {
                throw new RuntimeException(
                    "JournalPoster: OHADA 4452 (TVA récupérable sur achats) not mapped. Apply migration 065."
                );
            }
        }
        $precompte_coa = null;
        if ($precompte_withheld > 0) {
            $precompte_coa = self::coaByOhada($db, '4473');
            if (!$precompte_coa) {
                throw new RuntimeException(
                    "JournalPoster: OHADA 4473 (précompte subi) not mapped. Apply migration 065."
                );
            }
        }

        $je_id = self::createDraftJe(
            $db, 'JRN-AC-' . date('ym'), 'AC', $date,
            ($narration ?? "Ristourne 2,47% acquise") . " — réf: {$po_reference}", $user_id,
            'rebate_accrual', $po_id
        );
        self::addLine($db, $je_id, $receivable_coa, $amount, 0.0);
        if ($vat_withheld > 0)       self::addLine($db, $je_id, $vat_coa,       $vat_withheld,       0.0);
        if ($precompte_withheld > 0) self::addLine($db, $je_id, $precompte_coa, $precompte_withheld, 0.0);
        self::addLine($db, $je_id, $income_coa, 0.0, $gross);
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
        //
        // FOUR BYTES, NOT TWO. journal_entries.reference is UNIQUE, and the
        // purchasing entries share a constant stem for a whole month —
        // 'JRN-AC-2608'. With a 2-byte suffix that is 65 536 slots, and the
        // collision is a birthday problem, not a sequential one: at ~300
        // purchase entries in a month the probability that some pair collides
        // is already about 50 %. The failure mode is a duplicate-key exception
        // that rolls back a goods reception the operator has no way to
        // interpret, and it gets worse as the business grows. 4 bytes takes the
        // same figure to roughly 0,0005 %.
        $ref .= '-' . strtoupper(bin2hex(random_bytes(4)));

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
