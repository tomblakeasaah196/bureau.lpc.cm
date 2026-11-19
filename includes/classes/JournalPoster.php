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

        // 2. Credit COA — the client's own 411 if there's an invoice (8.1),
        //    419 (client advance) otherwise.
        $credit_coa = $pay['invoice_id']
            ? self::coaForClient($db, (int) $pay['client_id'])
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

        // 3. AIR (VIDEO_QA_FINDINGS 8.2). The invoice already split what the
        //    client withholds: Dr 4424 AIR at issuance, 411 for the net only.
        //    The payment must not book it again (it did: 4424 held the AIR
        //    twice and the client's 411 ended in credit by the AIR). So the
        //    part of `withheld` that the invoice already booked moves nothing;
        //    only an AIR the invoice did not foresee is booked here (Dr 4424 /
        //    Cr 411), and when the payment settles the invoice with less AIR
        //    than booked, the AIR never withheld leaves 4424 (Cr 4424): the
        //    client paid it in cash.
        $air_covered = 0.0;   // withheld, already in 4424 since the invoice
        $air_extra   = 0.0;   // withheld beyond what the invoice booked
        $air_undone  = 0.0;   // booked on the invoice, never withheld
        if ($pay['invoice_id']) {
            $ia = $db->prepare("
                SELECT COALESCE(i.air_amount, 0) AS air,
                       COALESCE((SELECT SUM(COALESCE(p2.air_withheld_amount, 0))
                                   FROM payments p2
                                  WHERE p2.invoice_id = i.id AND p2.status = 'validated' AND p2.id <> ?), 0) AS air_used"
                . (self::hasColumn($db, 'payments', 'credit_air_amount')
                    ? ", COALESCE((SELECT SUM(COALESCE(p3.credit_air_amount, 0))
                                     FROM payments p3
                                    WHERE p3.invoice_id = i.id AND p3.status = 'validated' AND p3.id <> ?), 0) AS air_credited"
                    : ", 0 AS air_credited") . "
                  FROM invoices i WHERE i.id = ?
            ");
            $ia->execute(self::hasColumn($db, 'payments', 'credit_air_amount')
                ? [$payment_id, $payment_id, (int) $pay['invoice_id']]
                : [$payment_id, (int) $pay['invoice_id']]);
            $ar = $ia->fetch(PDO::FETCH_ASSOC) ?: ['air' => 0, 'air_used' => 0, 'air_credited' => 0];
            $air_open    = self::roundFcfa(max(0.0, (float) $ar['air'] - (float) $ar['air_used'] - (float) $ar['air_credited']));
            $air_covered = self::roundFcfa(min($withheld, $air_open, $settled));
            $air_extra   = self::roundFcfa($withheld - $air_covered);
            // Settled in full with less AIR than the invoice booked.
            $closes = isset($open) && abs($settled - $open) < 0.5 && $advance >= 0;
            if ($closes && $air_open > $air_covered) {
                $air_undone = self::roundFcfa(min($air_open - $air_covered, $settled - $air_covered));
            }
        } else {
            $air_extra = $withheld;
        }
        $air_coa = null;
        if ($air_extra > 0 || $air_undone > 0) {
            $air_coa = self::coaByOhada($db, '4424');
            if (!$air_coa) {
                throw new RuntimeException("JournalPoster: OHADA 4424 (AIR retenu à la source) not mapped — run migration 020.");
            }
        }
        $client_credit = self::roundFcfa($settled - $air_covered - $air_undone);
        // An AIR-only receipt the invoice already booked moves nothing at all.
        if ($amount <= 0 && $air_extra <= 0 && $air_undone <= 0 && $advance <= 0 && $client_credit <= 0) {
            return 0;
        }

        // 4. Post the JE draft, add balanced lines, call post_journal_entry.
        $ref  = 'PAY-' . $pay['reference'];
        $invRef = '#' . $pay['invoice_id'];
        if ($pay['invoice_id']) {
            $ir = $db->prepare("SELECT i.reference, c.name FROM invoices i JOIN clients c ON c.id = i.client_id WHERE i.id = ?");
            $ir->execute([(int) $pay['invoice_id']]);
            if ($row = $ir->fetch(PDO::FETCH_ASSOC)) $invRef = $row['reference'] . ' · ' . $row['name'];
        }
        $desc = $pay['invoice_id']
            ? ("Encaissement facture {$invRef}"
                . ($withheld > 0 ? " (dont AIR retenu à la source " . number_format($withheld, 0, ',', ' ') . " FCFA)" : ''))
            : "Avance client (portefeuille)";
        $je_id = self::createDraftJe($db, $ref, self::journalForTreasury($db, $treasury_account_id), (string)$pay['payment_date'], $desc, $user_id,
                                     'invoice_payment', $payment_id);
        if ($amount > 0)    self::addLine($db, $je_id, $treasury_coa, $amount,    0.0);
        if ($air_extra > 0) self::addLine($db, $je_id, $air_coa,      $air_extra, 0.0);
        // Debits: amount + air_extra = (amount + withheld) − air_covered.
        // Credits: client_credit + air_undone + advance = settled − air_covered + advance.
        // settled + advance == amount + withheld by construction: it balances.
        if ($client_credit > 0) self::addLine($db, $je_id, $credit_coa,  0.0, $client_credit);
        if ($air_undone > 0)    self::addLine($db, $je_id, $air_coa,     0.0, $air_undone);
        if ($advance > 0)       self::addLine($db, $je_id, $advance_coa, 0.0, $advance);
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

        $client_coa    = self::coaForClient($db, (int) $inv['client_id']);
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
        $cn = $db->prepare("SELECT name FROM clients WHERE id = ?");
        $cn->execute([(int) $inv['client_id']]);
        $desc = "Facture {$inv['reference']} · " . ((string) ($cn->fetchColumn() ?: "client #{$inv['client_id']}"));
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
        string $reason = '',
        float $air_amount = 0.0,
        ?int $payment_id = null
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount_ht  = self::roundFcfa($amount_ht);
        $vat_amount = self::roundFcfa($vat_amount);
        $air_amount = self::roundFcfa($air_amount);
        if ($amount_ht <= 0) {
            throw new RuntimeException("JournalPoster: credit note for invoice #{$invoice_id} is non-positive.");
        }

        $ci = $db->prepare("SELECT client_id FROM invoices WHERE id = ?");
        $ci->execute([$invoice_id]);
        $client_id = (int) $ci->fetchColumn();
        if (!$client_id) throw new RuntimeException("JournalPoster: invoice #{$invoice_id} not found.");

        $rrr_coa    = self::coaByOhada($db, '7019');
        $client_coa = self::coaForClient($db, $client_id);
        $tva_coa    = ($vat_amount > 0) ? self::coaByOhada($db, '4431') : null;
        // The AIR the invoice booked on the part now credited will never be
        // withheld: it leaves 4424 with the reduction (8.11).
        $air_coa    = ($air_amount > 0) ? self::coaByOhada($db, '4424') : null;
        if ($air_amount > 0 && !$air_coa) throw new RuntimeException("JournalPoster: 4424 (AIR à récupérer) not mapped — run migration 020.");

        if (!$rrr_coa) {
            throw new RuntimeException(
                "JournalPoster: OHADA 7019 (RRR accordés) not mapped in chart_of_accounts. Apply migration 065."
            );
        }
        if (!$client_coa) throw new RuntimeException("JournalPoster: 411 (Clients) not mapped.");
        if ($vat_amount > 0 && !$tva_coa) {
            throw new RuntimeException("JournalPoster: 4431 (TVA facturée sur ventes) not mapped — apply migration 065.");
        }

        $iref = $db->prepare("SELECT reference FROM invoices WHERE id = ?");
        $iref->execute([$invoice_id]);
        $inv_ref = (string) ($iref->fetchColumn() ?: "#{$invoice_id}");
        $desc = "Avoir {$reference} sur facture {$inv_ref}" . ($reason !== '' ? " ({$reason})" : '');
        $je_id = self::createDraftJe($db, $reference, 'VT', $date, $desc, $user_id,
                                     'invoice_credit_note', $invoice_id);
        self::addLine($db, $je_id, $rrr_coa, $amount_ht, 0.0);
        if ($vat_amount > 0) self::addLine($db, $je_id, $tva_coa, $vat_amount, 0.0);
        if ($air_amount > 0) self::addLine($db, $je_id, $air_coa, 0.0, $air_amount);
        self::addLine($db, $je_id, $client_coa, 0.0, $amount_ht + $vat_amount - $air_amount);
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
     * The opening balance of a treasury account created in Trésorerie & Banque
     * (« Solde d'ouverture »): the money was there before the account was in the
     * app, so the ledger receives it too (VIDEO_QA_FINDINGS 9.2). It used to log
     * a treasury movement and post nothing: the screen and the 5x account
     * disagreed by exactly that amount (copy: MTN MOBILE MONEY, 1 000 000 F).
     *
     *   Debit  the account's own 5x (coaFromTreasury)
     *   Credit 471 « Compte d'attente », until the Comptable says where the money
     *          came from (capital, an associate's current account, an earlier
     *          bank...) and moves it there with an OD.
     *
     * The first fiscal year's opening balance is Comptabilité → Bilan
     * d'Ouverture; that screen locks once the year has other entries, which is
     * why a mid-year account opens here. SOURCE LINK: 'treasury_opening',
     * source_id = the treasury_transactions row of the opening movement.
     */
    public static function postTreasuryOpening(int $treasury_account_id, float $amount, int $transaction_id, ?string $date = null): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) throw new RuntimeException("JournalPoster::postTreasuryOpening — non-positive amount.");

        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);
        $suspense_coa = self::coaByOhada($db, '471');
        if (!$suspense_coa) {
            throw new RuntimeException("Le compte d'attente 471 manque au plan comptable (migration 139).");
        }
        $name = $db->prepare("SELECT name FROM treasury_accounts WHERE id = ?");
        $name->execute([$treasury_account_id]);
        $label = (string) ($name->fetchColumn() ?: "#{$treasury_account_id}");

        $day = ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) ? $date : date('Y-m-d');
        $je_id = self::createDraftJe($db, 'OUV-TRS', 'OD', $day,
            "Solde d'ouverture · {$label} (à régulariser depuis le compte d'attente)",
            $user_id, 'treasury_opening', $transaction_id);
        self::addLine($db, $je_id, $treasury_coa, $amount, 0.0);
        self::addLine($db, $je_id, $suspense_coa, 0.0,     $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * One balanced entry for Emprunts & Financements (includes/classes/Loans.php):
     * a loan or a repayment the DG approved, a month's interest on approved
     * terms. $lines: [[coa id, debit, credit], ...]; zero lines are skipped.
     * SOURCE LINK: 'loan' (source_id = loans.id) or 'loan_movement'
     * (loan_movements.id). $user_id: who the entry is for (the approver).
     */
    public static function postLoanEntry(string $ref, string $date, string $desc, array $lines,
                                         string $source_type, int $source_id, int $user_id): int
    {
        $db = Database::getInstance()->getConnection();
        $debit = 0.0; $credit = 0.0;
        foreach ($lines as [$coa, $d, $c]) {
            $debit += self::roundFcfa((float) $d);
            $credit += self::roundFcfa((float) $c);
        }
        if ($debit <= 0 || abs($debit - $credit) > 0.001) {
            throw new RuntimeException("Écriture de financement déséquilibrée ({$debit} / {$credit}).");
        }
        $je_id = self::createDraftJe($db, $ref, 'OD', $date, mb_substr($desc, 0, 255), $user_id, $source_type, $source_id);
        foreach ($lines as [$coa, $d, $c]) {
            self::addLine($db, $je_id, (int) $coa, (float) $d, (float) $c);
        }
        self::post($db, $je_id, $user_id);
        return $je_id;
    }

    /**
     * The acquisition of a fixed asset, posted when it is capitalised in
     * Immobilisations (VIDEO_QA_FINDINGS 12.1; capitalising used to post nothing,
     * so the register held the truck and class 2 of the balance sheet did not).
     *
     *   Debit  the asset's own 2x account                cost
     *   Credit 4812 / 4811 « Fournisseurs d'immobilisations » (bought on credit)
     *       or the treasury account's own 5x               (paid at once; the caller
     *                                                       moves the money)
     *
     * SOURCE LINK: 'fixed_asset', source_id = fixed_assets.id. $credit_coa is a
     * chart_of_accounts id the caller resolved (coaByOhada('4812') or
     * coaFromTreasury). Returns the entry id.
     */
    public static function postAssetAcquisition(int $asset_id, int $asset_coa, int $credit_coa, float $amount, string $date, string $desc): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) throw new RuntimeException("JournalPoster::postAssetAcquisition — non-positive amount.");
        if ($asset_coa <= 0 || $credit_coa <= 0) throw new RuntimeException("Compte d'acquisition manquant.");
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');

        $je_id = self::createDraftJe($db, 'IMM-' . $asset_id, 'OD', $day, mb_substr($desc, 0, 255), $user_id, 'fixed_asset', $asset_id);
        self::addLine($db, $je_id, $asset_coa,  $amount, 0.0);
        self::addLine($db, $je_id, $credit_coa, 0.0,     $amount);
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

        $je_id = self::createDraftJe($db, $ref, self::journalForTreasury($db, $treasury_account_id), $date, $desc, $user_id);
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
     * Goods received enter the stock account: Dr 31x / Cr 6031, at cost.
     *
     * SYSCOHADA perpetual inventory (owner default, VIDEO_QA_FINDINGS 3.1 and
     * « For the owner » #3, 18/11/2026). The purchase itself stays in 601
     * (postGoodsReceipt); this second entry moves the value into stock, and
     * each delivery takes it out again at CUMP (postDeliveryCogs: Dr 6031 /
     * Cr 31x). Over a period 601 plus the 6031 movements is the cost of the
     * goods sold, and 31x holds the stock still on hand. Before this, nothing
     * ever debited 31x: each delivery drove it into credit and counted the
     * cost of the bottles twice (601 at purchase, 6031 at delivery).
     *
     * The account is the one the delivery entry uses for the same product
     * (its own stock / variation account, else its category's), so the two
     * entries always meet in the same accounts.
     *
     * @param array<int,float> $values product_id => value received, at cost
     * @return int|null the entry, or null when nothing could be mapped
     */
    public static function postStockEntry(
        int $po_id,
        array $values,
        string $date,
        string $po_reference
    ): ?int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        if (!self::hasProductAccountMapping($db) || !$values) return null;

        $cogs_default = self::coaByOhada($db, '6031');
        if (!$cogs_default) {
            throw new RuntimeException("JournalPoster: OHADA 6031 is not mapped in chart_of_accounts. Apply migration 041.");
        }

        $ids = implode(',', array_map('intval', array_keys($values)));
        $map = [];
        foreach ($db->query("
            SELECT p.id,
                   COALESCE(p.stock_account_id, pc.stock_account_id) AS stock_coa,
                   COALESCE(p.cogs_account_id,  pc.cogs_account_id)  AS cogs_coa
              FROM products p
              LEFT JOIN product_categories pc ON pc.id = p.category_id
             WHERE p.id IN ({$ids})
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['id']] = $r;
        }

        $lines = [];   // "stock|variation" => value
        foreach ($values as $pid => $value) {
            $m = $map[(int) $pid] ?? null;
            if (!$m || empty($m['stock_coa'])) {
                // Same rule as the delivery: refuse to guess an account. The
                // reception still stands; the accountant sees the log.
                error_log("JournalPoster::postStockEntry — PO #{$po_id}: product #{$pid} has no stock account, "
                        . round((float) $value) . " FCFA not moved into stock.");
                continue;
            }
            $key = (int) $m['stock_coa'] . '|' . (!empty($m['cogs_coa']) ? (int) $m['cogs_coa'] : $cogs_default);
            $lines[$key] = ($lines[$key] ?? 0) + (float) $value;
        }

        $total = 0.0;
        foreach ($lines as $k => $v) { $lines[$k] = self::roundFcfa($v); $total += $lines[$k]; }
        if ($total <= 0) return null;

        $je_id = self::createDraftJe(
            $db, 'STK-' . $po_reference, 'OD', $date,
            "Entrée en stock sur réception {$po_reference} (au coût d'achat)", $user_id,
            'purchase_stock_entry', $po_id
        );
        foreach ($lines as $k => $v) {
            if ($v <= 0) continue;
            [$stock, $variation] = array_map('intval', explode('|', $k));
            self::addLine($db, $je_id, $stock,     $v,  0.0);
            self::addLine($db, $je_id, $variation, 0.0, $v);
        }
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Bottles that enter or leave the stock outside a purchase or a sale: an
     * avarie, an inventory gap, bottles a client refused at delivery.
     *
     *   value < 0 (out: avarie, missing at the count)   Dr 6031 / Cr 31x
     *   value > 0 (in: surplus at the count, refused)   Dr 31x  / Cr 6031
     *
     * Perpetual inventory (the owner's answer 3, VIDEO_QA_FINDINGS 3.1,
     * 18/11/2026): 31x holds the value of the bottles on hand, so a bottle
     * that leaves it any other way than a delivery must leave the account too,
     * or 31x stays above the warehouse. The other side is the product's
     * variation account (6031 by default), the one its deliveries use: a loss
     * or a gap is a cost of the goods, and the entry's text says which.
     *
     * @param array<int,float> $values product_id => signed value (+ in, - out)
     * @param int|null $user_id who the entry is booked by (default: the session)
     * @return int|null the entry, or null when nothing has a value or an account
     */
    public static function postStockAdjustment(
        string $source_type,
        int $source_id,
        array $values,
        string $date,
        string $reference,
        string $description,
        ?int $user_id = null   // a client's signature has no session: the caller names who
    ): ?int {
        $db      = Database::getInstance()->getConnection();
        $user_id = $user_id ?: (int) ($_SESSION['user_id'] ?? 0);

        if (!self::hasProductAccountMapping($db) || !$values) return null;

        $cogs_default = self::coaByOhada($db, '6031');
        if (!$cogs_default) {
            throw new RuntimeException("JournalPoster: OHADA 6031 is not mapped in chart_of_accounts. Apply migration 041.");
        }

        $ids = implode(',', array_map('intval', array_keys($values)));
        $map = [];
        foreach ($db->query("
            SELECT p.id,
                   COALESCE(p.stock_account_id, pc.stock_account_id) AS stock_coa,
                   COALESCE(p.cogs_account_id,  pc.cogs_account_id)  AS cogs_coa
              FROM products p
              LEFT JOIN product_categories pc ON pc.id = p.category_id
             WHERE p.id IN ({$ids})
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[(int) $r['id']] = $r;
        }

        $lines = [];   // "stock|variation" => signed value
        foreach ($values as $pid => $value) {
            $m = $map[(int) $pid] ?? null;
            if (!$m || empty($m['stock_coa'])) {
                error_log("JournalPoster::postStockAdjustment — {$source_type} #{$source_id}: product #{$pid} has no stock account, "
                        . round((float) $value) . " FCFA not posted.");
                continue;
            }
            $key = (int) $m['stock_coa'] . '|' . (!empty($m['cogs_coa']) ? (int) $m['cogs_coa'] : $cogs_default);
            $lines[$key] = ($lines[$key] ?? 0) + (float) $value;
        }
        foreach ($lines as $k => $v) {
            $lines[$k] = self::roundFcfa($v);
            if ($lines[$k] == 0.0) unset($lines[$k]);
        }
        if (!$lines) return null;

        $je_id = self::createDraftJe($db, $reference, 'OD', $date, $description, $user_id, $source_type, $source_id);
        foreach ($lines as $k => $v) {
            [$stock, $variation] = array_map('intval', explode('|', $k));
            if ($v > 0) {
                self::addLine($db, $je_id, $stock,     $v,  0.0);
                self::addLine($db, $je_id, $variation, 0.0, $v);
            } else {
                self::addLine($db, $je_id, $variation, -$v, 0.0);
                self::addLine($db, $je_id, $stock,     0.0, -$v);
            }
        }
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
            ($narration ?? "Ristourne acquise") . " · réf: {$po_reference}", $user_id,
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
            "Utilisation ristourne · réf: {$po_reference}", $user_id,
            'rebate_usage', $po_id
        );
        self::addLine($db, $je_id, $supplier_coa,   $amount, 0.0);
        self::addLine($db, $je_id, $receivable_coa, 0.0, $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Settle a supplier invoice / purchase order from a treasury account.
     *
     *   Debit  401x the supplier's own account   — clears the payable
     *   Credit 5xx  chosen treasury COA          — cash actually leaves
     *
     * Called from two paths (see migration 093 for the full account of the hole
     * this method closes):
     *
     *   1. INLINE, at PO creation, when the buyer ticked "Payé (Cash/Virement)":
     *      procurement_controller::save_po calls postGoodsReceipt AND
     *      postSupplierPayment in the same transaction, so the goods leg and
     *      the payment leg either both post or neither does.
     *
     *   2. STANDALONE, from the Payer Fournisseur screen: the buyer picks one
     *      or more open POs and a treasury account; this method fires once per
     *      PO, updating purchase_orders.payment_status='paid' and stamping the
     *      returned JE id on payment_je_id.
     *
     * BEFORE THIS METHOD EXISTED, every PO marked "paid" only produced the
     * goods-receipt JE (Dr 601 / Dr 4452 / Cr 401). The payment leg was never
     * booked — 401 stayed open on the books forever, and the caisse balance
     * never decremented despite the cash physically leaving the till. The
     * treasury_transactions journal carried no outgoing row for the outflow.
     *
     * PARTIAL PAYMENTS — deliberately not supported yet.
     *   The migration 093 header explains why: most POs are paid in full or on
     *   credit-then-full, and adding a purchase_order_payments child table for
     *   the rare partial case would be dead schema in PR-1. This method throws
     *   if $amount does not exactly clear the PO. A future migration adds the
     *   child table and relaxes this check; the JE shape stays the same.
     *
     * SUPPLIER 401 SUB-ACCOUNT — resolved via coaForSupplier(), which throws
     * if the supplier has no chart-of-accounts entry. Same posture as
     * postGoodsReceipt: silently falling back to a shared 401 is how the AP
     * sub-ledger got meaningless in the first place.
     *
     * SOURCE LINK — source_type='purchase_order_payment' (distinct from
     * postGoodsReceipt's 'purchase_order'). A cancel_po must reverse BOTH
     * source types; a refund/reversal of only the payment (rare) hits only
     * this one. Keeping them separate is what lets that distinction exist.
     *
     * Called inside the caller's transaction. Does NOT open its own.
     *
     * @param int    $po_id                purchase_orders.id being settled
     * @param int    $treasury_account_id  treasury_accounts.id paying from
     * @param float  $amount               FCFA paid; must equal the open balance
     * @param string $payment_date         YYYY-MM-DD; drives the JE date
     * @param string $note                 optional free-text appended to desc
     * @return int                         journal_entries.id of the payment JE
     */
    public static function postSupplierPayment(
        int $po_id,
        int $treasury_account_id,
        float $amount,
        string $payment_date,
        string $note = ''
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) {
            throw new RuntimeException("JournalPoster::postSupplierPayment — non-positive amount for PO #{$po_id}.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            throw new RuntimeException("JournalPoster::postSupplierPayment — payment_date must be YYYY-MM-DD.");
        }

        // 1. Load the PO (locked) and compute the open balance.
        //    Uses FOR UPDATE so a second concurrent Payer Fournisseur call
        //    against the same PO cannot both pass the "clears exactly" check
        //    and double-pay.
        $stmt = $db->prepare("
            SELECT id, reference, supplier_id, total_amount, payment_status, payment_je_id
              FROM purchase_orders
             WHERE id = ?
             FOR UPDATE
        ");
        $stmt->execute([$po_id]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$po) {
            throw new RuntimeException("JournalPoster::postSupplierPayment — PO #{$po_id} not found.");
        }
        // payment_je_id is the ONLY authoritative "settled" signal — it is
        // stamped exclusively at the bottom of this method, atomically with
        // payment_status='paid'. Do not also gate on payment_status: a PO
        // created "Payé (Cash/Virement)" gets payment_status='paid' at
        // creation (procurement_controller::save_po) purely to declare
        // intent, long before any JE exists — that is precisely the state
        // inventory_controller::receive_po calls this method FROM (migration
        // 093). Throwing on payment_status alone made every first-ever
        // payment posting for such a PO fail as "already settled" — see the
        // reception-500 bug this comment replaced.
        if (!empty($po['payment_je_id'])) {
            throw new RuntimeException(
                "JournalPoster::postSupplierPayment — PO #{$po_id} ({$po['reference']}) is already settled."
            );
        }
        $total_owed = self::roundFcfa((float) $po['total_amount']);
        // Both figures are already rounded to whole FCFA, but comparing
        // rounded floats with === is superstition — use a 0.5 F epsilon,
        // which is well under the smallest unit (FCFA has no subunits).
        if (abs($amount - $total_owed) > 0.5) {
            // Partial payments intentionally rejected — see method docblock.
            throw new RuntimeException(sprintf(
                "JournalPoster::postSupplierPayment — PO #%d owes %s FCFA but %s FCFA was tendered. "
                . "Partial payments are not yet supported; settle the full balance or split the PO.",
                $po_id,
                number_format($total_owed, 0, ',', ' '),
                number_format($amount, 0, ',', ' ')
            ));
        }

        // 2. Resolve accounts.
        $supplier_coa = self::coaForSupplier($db, (int) $po['supplier_id']);
        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);

        // 3. Post the JE. Journal CA for a cash box, BQ for a bank or MoMo
        //    account (journalForTreasury, VIDEO_QA_FINDINGS 11.5). The sub-ledger
        //    lives in the treasury COA the coaFromTreasury lookup returned.
        $ref  = 'PAY-FRS-' . $po['reference'];
        $desc = "Règlement fournisseur · {$po['reference']}";
        if ($note !== '') $desc .= " · {$note}";

        $je_id = self::createDraftJe(
            $db, $ref, self::journalForTreasury($db, $treasury_account_id), $payment_date, $desc, $user_id,
            'purchase_order_payment', $po_id
        );
        self::addLine($db, $je_id, $supplier_coa, $amount, 0.0);   // Dr 401
        self::addLine($db, $je_id, $treasury_coa, 0.0,     $amount); // Cr 5xx
        self::post($db, $je_id, $user_id);

        // 4. Treasury movement — outgoing row + balance decrement.
        //    Migration 093 added 'out_supplier_payment' to the enum so this
        //    can be distinguished from ad-hoc treasury expenses in reports.
        $db->prepare("
            INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
            VALUES (?, 'out_supplier_payment', ?, ?, ?, ?)
        ")->execute([$treasury_account_id, $amount, $ref, $desc, $user_id]);

        $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
           ->execute([$amount, $treasury_account_id]);

        // 5. Stamp the PO. payment_je_id is what cancel_po reads to reverse
        //    the payment leg (see procurement_controller::cancel_po after
        //    the PR-1 wiring lands).
        $db->prepare("
            UPDATE purchase_orders
               SET payment_status      = 'paid',
                   treasury_account_id = ?,
                   payment_date        = ?,
                   payment_je_id       = ?
             WHERE id = ?
        ")->execute([$treasury_account_id, $payment_date, $je_id, $po_id]);

        return $je_id;
    }

    /**
     * Disburse an approved HR advance: cash physically handed to the
     * employee against a treasury account.
     *
     *   Debit  421  Personnel — avances et acomptes    amount
     *   Credit 5xx  chosen treasury COA                amount
     *
     * PR-2, migration 096. Closes the third payroll-side hole: approve_
     * advance flipped the status to 'approved' and stopped. Nothing debited
     * 421 to represent money the employee owes, and nothing credited the
     * caisse for money physically handed out. The employee got the cash,
     * the books never learned about it, and the ledger only saw the
     * subsequent Cr 421 line inside the payslip accrual — 421 stayed at
     * zero throughout despite genuine cash out.
     *
     * IDEMPOTENCY — disbursement_je_id set means already disbursed. Throw
     * rather than double-post; the UI must gate the button on that field.
     *
     * SOURCE LINK — source_type='advance_disbursement'. Distinct from
     * payslip source_types so a future "cancel advance" (reversing the
     * disbursement before it's been deducted from a payslip) can address
     * this JE alone.
     *
     * Called inside the caller's transaction.
     *
     * @param int    $advance_id           hr_advances.id
     * @param int    $treasury_account_id  treasury_accounts.id paying out
     * @param string $payment_date         YYYY-MM-DD; drives the JE date
     * @param string $note                 optional free-text appended to desc
     * @return int                         journal_entries.id
     */
    public static function postAdvanceDisbursement(
        int $advance_id,
        int $treasury_account_id,
        string $payment_date,
        string $note = ''
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            throw new RuntimeException("JournalPoster::postAdvanceDisbursement — payment_date must be YYYY-MM-DD.");
        }

        // 1. Load and lock the advance.
        $stmt = $db->prepare("
            SELECT a.id, a.user_id, a.employee_id, a.amount, a.status, a.disbursement_je_id,
                   CONCAT(e.first_name, ' ', e.last_name) AS employee_name
              FROM hr_advances a
              JOIN employees e ON e.id = a.employee_id
             WHERE a.id = ?
             FOR UPDATE
        ");
        $stmt->execute([$advance_id]);
        $adv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$adv) {
            throw new RuntimeException("JournalPoster::postAdvanceDisbursement — advance #{$advance_id} not found.");
        }
        if ($adv['status'] !== 'approved') {
            throw new RuntimeException(
                "JournalPoster::postAdvanceDisbursement — advance #{$advance_id} is not in 'approved' status."
            );
        }
        if (!empty($adv['disbursement_je_id'])) {
            throw new RuntimeException(
                "JournalPoster::postAdvanceDisbursement — advance #{$advance_id} is already disbursed."
            );
        }

        $amount = self::roundFcfa((float) $adv['amount']);
        if ($amount <= 0) {
            throw new RuntimeException("JournalPoster::postAdvanceDisbursement — non-positive amount for advance #{$advance_id}.");
        }

        // 2. Resolve accounts.
        $advance_coa = self::coaByOhada($db, '421');
        if (!$advance_coa) {
            throw new RuntimeException("JournalPoster::postAdvanceDisbursement — OHADA 421 not mapped.");
        }
        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);

        // 3. Post the JE. Journal code 'BQ' — every treasury movement is BQ.
        $ref  = 'PAY-ADV-' . $advance_id;
        $desc = "Décaissement acompte — {$adv['employee_name']} (#{$advance_id})";
        if ($note !== '') $desc .= " · {$note}";

        $je_id = self::createDraftJe(
            $db, $ref, self::journalForTreasury($db, $treasury_account_id), $payment_date, $desc, $user_id,
            'advance_disbursement', $advance_id
        );
        self::addLine($db, $je_id, $advance_coa,  $amount, 0.0);  // Dr 421
        self::addLine($db, $je_id, $treasury_coa, 0.0,    $amount); // Cr 5xx
        self::post($db, $je_id, $user_id);

        // 4. Treasury outflow — 'out_advance' added by migration 096.
        $db->prepare("
            INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
            VALUES (?, 'out_advance', ?, ?, ?, ?)
        ")->execute([$treasury_account_id, $amount, $ref, $desc, $user_id]);
        $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
           ->execute([$amount, $treasury_account_id]);

        // 5. Stamp the advance. status flips to 'disbursed'; the next
        //    payslip's Cr 421 deduction will later flip it to 'deducted'.
        $db->prepare("
            UPDATE hr_advances
               SET status              = 'disbursed',
                   disbursement_je_id  = ?,
                   treasury_account_id = ?,
                   disbursed_at        = NOW()
             WHERE id = ?
        ")->execute([$je_id, $treasury_account_id, $advance_id]);

        return $je_id;
    }

    /**
     * Settle a group of payslips from ONE treasury account, as ONE bulk JE.
     *
     *   Debit  422  Personnel — rémunérations dues     sum(net_pay)
     *   Credit 5xx  chosen treasury COA                sum(net_pay)
     *
     * PR-2, migration 095. Answers the payroll half of the AP-hole audit:
     * generate_month posts the ACCRUAL (Dr 661 / Cr 422) but no settlement
     * ever fires, so 422 balloons on the balance sheet by every month's
     * net payroll despite the money being paid on the 5th.
     *
     * ONE JE PER PAY RUN, PER TREASURY ACCOUNT — deliberate.
     *   The PR-2 questionnaire picked this shape because a single bank
     *   transfer for the month's bank-paid staff reconciles cleanly
     *   against ONE Cr 521 line in the JE. Booking one JE per employee
     *   would produce 40 tiny lines to match against one transfer, which
     *   is how bank reconciliations become impossible.
     *
     *   The corollary: the caller MUST group by treasury account before
     *   calling. Mixing bank-paid and caisse-paid payslips into one call
     *   would fold them into one JE with a single treasury credit, which
     *   would be wrong. payroll_controller::settle_payslips does the
     *   grouping.
     *
     * IDEMPOTENCY — payment_je_id set on any payslip in the list means it
     * was already settled. The method throws rather than double-paying;
     * the caller must filter the list.
     *
     * SOURCE LINK — source_type='payroll_settlement', source_id=first
     * payslip id in the batch (chosen because there's no natural pay-run
     * primary key). reverseSource can still find it via source_type.
     *
     * Called inside the caller's transaction.
     *
     * @param int    $treasury_account_id  treasury_accounts.id paying out
     * @param int[]  $payslip_ids          hr_payslips.id list (single treasury)
     * @param string $payment_date         YYYY-MM-DD; drives the JE date
     * @param string $note                 optional (e.g., "Virement Afriland réf. …")
     * @return int                         journal_entries.id
     */
    public static function postPayrollSettlement(
        int $treasury_account_id,
        array $payslip_ids,
        string $payment_date,
        string $note = ''
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        if (empty($payslip_ids)) {
            throw new RuntimeException("JournalPoster::postPayrollSettlement — empty payslip list.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payment_date)) {
            throw new RuntimeException("JournalPoster::postPayrollSettlement — payment_date must be YYYY-MM-DD.");
        }

        // 1. Load and lock every payslip. FOR UPDATE serialises against a
        //    concurrent "settle again" click — the second one will see the
        //    payment_je_id set and refuse below.
        $ids = array_values(array_unique(array_map('intval', $payslip_ids)));
        $ids = array_filter($ids, fn($id) => $id > 0);
        if (empty($ids)) {
            throw new RuntimeException("JournalPoster::postPayrollSettlement — no valid payslip ids after filtering.");
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("
            SELECT id, user_id, month, year, net_pay, status, payment_je_id
              FROM hr_payslips
             WHERE id IN ({$placeholders})
             FOR UPDATE
        ");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== count($ids)) {
            throw new RuntimeException("JournalPoster::postPayrollSettlement — some payslip ids not found.");
        }

        $total = 0.0;
        foreach ($rows as $r) {
            if ($r['status'] !== 'validated') {
                // Only a 'validated' payslip (accrued by generate_month, not yet
                // paid) can be settled. A 'draft' one (never calculated, or
                // cancelled) has no accrual: settling it would debit a 422 that
                // was never credited. A 'paid' one is paid already.
                // Before 18/11/2026 the accrual itself set 'paid', so a payslip
                // read « Payé » the day it was calculated (VIDEO_QA_FINDINGS 10.1).
                throw new RuntimeException(
                    "JournalPoster::postPayrollSettlement — payslip #{$r['id']} is not validated and unpaid."
                );
            }
            if (!empty($r['payment_je_id'])) {
                throw new RuntimeException(
                    "JournalPoster::postPayrollSettlement — payslip #{$r['id']} is already disbursed."
                );
            }
            $total += (float) $r['net_pay'];
        }
        $total = self::roundFcfa($total);
        if ($total <= 0) {
            throw new RuntimeException("JournalPoster::postPayrollSettlement — total net pay is non-positive.");
        }

        // 2. Resolve accounts.
        $personnel_coa = self::coaByOhada($db, '422');
        if (!$personnel_coa) {
            throw new RuntimeException("JournalPoster::postPayrollSettlement — OHADA 422 not mapped.");
        }
        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);

        // 3. Post the JE.
        $first_id = $rows[0]['id'];
        $ref  = 'PAY-SAL-' . date('ym') . '-' . $first_id;
        $desc = sprintf("Règlement salaires (%d bulletins)", count($rows));
        if ($note !== '') $desc .= " · {$note}";

        $je_id = self::createDraftJe(
            $db, $ref, self::journalForTreasury($db, $treasury_account_id), $payment_date, $desc, $user_id,
            'payroll_settlement', (int) $first_id
        );
        self::addLine($db, $je_id, $personnel_coa, $total, 0.0);  // Dr 422
        self::addLine($db, $je_id, $treasury_coa,  0.0,    $total); // Cr 5xx
        self::post($db, $je_id, $user_id);

        // 4. Treasury outflow — 'out_payroll' added by migration 095.
        $db->prepare("
            INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
            VALUES (?, 'out_payroll', ?, ?, ?, ?)
        ")->execute([$treasury_account_id, $total, $ref, $desc, $user_id]);
        $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
           ->execute([$total, $treasury_account_id]);

        // 5. Stamp every settled payslip with the JE + account + timestamp.
        //    Doing it in ONE UPDATE keeps it atomic even if a caller
        //    passes a very long list.
        $update = $db->prepare("
            UPDATE hr_payslips
               SET status              = 'paid',
                   payment_je_id       = ?,
                   treasury_account_id = ?,
                   paid_at             = ?
             WHERE id IN ({$placeholders})
        ");
        $update->execute(array_merge([$je_id, $treasury_account_id, $payment_date . ' ' . date('H:i:s')], $ids));

        return $je_id;
    }

    /**
     * Book one payslip's accrual (the month's salary cost and what is owed for it).
     *
     *   Debit  661   Rémunérations directes           gross (base + indemnités + primes − absences)
     *   Debit  664   Charges sociales patronales      CNPS part patronale
     *   Debit  6412  Charges fiscales sur salaires    CFC part patronale + FNE (1 %)
     *   Credit 433   CNPS                             CNPS salarié + patronal
     *   Credit 432   État, impôts sur salaires        IRPP, CAC, CFC (both parts), CRTV, TDL, FNE: the DIPE
     *   Credit 421   Personnel, avances               advances and debts deducted
     *   Credit 422   Personnel, rémunérations dues    net to pay
     *
     * Moved here from payroll_controller::generate_month (18/11/2026, VIDEO_QA_FINDINGS
     * 10.1 and 10.4). The entry now carries its source (source_type 'payslip', the
     * payslip id), so « Annuler la paie » reverses exactly it with reverseSource();
     * the FNE, which the DIPE always declared, is now accrued; and the CFC part
     * patronale moves from 664 (social charges) to 6412, the chart's own account for
     * « CFC part patronale, FNE ». 432 therefore holds the whole DIPE of the month.
     * When 6412 is not mapped the two employer taxes fall back on 664, as before.
     *
     * @param int    $payslip_id hr_payslips.id
     * @param array  $r          Payroll::compute() result
     * @param string $date       YYYY-MM-01 of the period
     * @param string $desc       e.g. « Paie 11/2026 · Bertin CHADEU »
     * @return int               journal_entries.id
     */
    public static function postPayslipAccrual(int $payslip_id, array $r, string $date, string $desc): int
    {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $acc = [];
        foreach (['661', '664', '6412', '433', '432', '421', '422'] as $n) $acc[$n] = self::coaByOhada($db, $n);
        foreach (['661', '664', '433', '432', '422'] as $n) {
            if (!$acc[$n]) throw new RuntimeException("Compte OHADA {$n} non mappé : configurez le plan comptable.");
        }
        $taxAcc = $acc['6412'] ?: $acc['664'];

        $gross       = self::roundFcfa((float) $r['gross_salary']);
        $cnps_er     = self::roundFcfa((float) $r['cnps_employer']);
        $taxes_er    = self::roundFcfa((float) ($r['cfc_employer'] ?? 0) + (float) ($r['fne_employer'] ?? 0));
        $cnps_total  = self::roundFcfa((float) $r['cnps_employee'] + $cnps_er);
        $dipe        = self::roundFcfa((float) $r['irpp'] + (float) $r['cac'] + (float) $r['cfc_employee']
                                     + (float) $r['crtv'] + (float) $r['tdl'] + $taxes_er);
        $withheld    = self::roundFcfa((float) $r['advances_deducted'] + (float) $r['driver_debt_deducted']
                                     + (float) ($r['other_deductions'] ?? 0));
        $net         = self::roundFcfa((float) $r['net']);
        if ($withheld > 0 && !$acc['421']) throw new RuntimeException("Compte OHADA 421 non mappé : configurez le plan comptable.");

        [$y, $m] = array_map('intval', explode('-', $date));
        $je_id = self::createDraftJe($db, sprintf('PAIE-%04d-%02d-%d', $y, $m, $payslip_id), 'OD', $date, $desc, $user_id,
                                     'payslip', $payslip_id);
        self::addLine($db, $je_id, $acc['661'], $gross, 0.0);
        self::addLine($db, $je_id, $acc['664'], $cnps_er, 0.0);
        self::addLine($db, $je_id, $taxAcc, $taxes_er, 0.0);
        self::addLine($db, $je_id, $acc['433'], 0.0, $cnps_total);
        self::addLine($db, $je_id, $acc['432'], 0.0, $dipe);
        if ($withheld > 0) self::addLine($db, $je_id, $acc['421'], 0.0, $withheld);
        self::addLine($db, $je_id, $acc['422'], 0.0, $net);
        self::post($db, $je_id, $user_id);
        return $je_id;
    }

    /**
     * Post the sale JE + treasury movement for a recycling sale (empties
     * sold for cash to a recycler).
     *
     *   Debit  5xx  chosen treasury COA        — cash physically arrives
     *   Credit 7074 Bonis sur reprises et      — SYSCOHADA revenue account
     *          cessions d'emballages             for empties reclamations
     *
     * BEFORE THIS METHOD EXISTED, cre_controller::sell_to_recycler inserted
     * rows into recycling_sales and inventory_movements and stopped. No JE
     * was posted, no treasury balance moved. The KPI on empties_collection.
     * php reads recycling_sales.total_amount directly, so the panel and the
     * P&L disagreed by the entire history of recycling.
     *
     * 7074 was seeded by migration 041 precisely for this purpose ("→ consigne
     * income" in the header comment there); it was mapped and never posted
     * to. This method is the missing writer.
     *
     * NO VAT SPLIT — deliberate.
     *   Recycling sales in the field are gross-cash: the driver collects
     *   what the recycler counts out, and total_amount is that gross figure
     *   as the operator entered it. Splitting off output VAT here would
     *   require knowing whether the recycler is TVA-registered (usually not,
     *   in Cameroon) and would change the meaning of the total displayed on
     *   the form — a UX regression. If a future audit requires TVA
     *   collection on empties, add it in a follow-up: the JE shape stays
     *   the same, only the credit splits.
     *
     * SOURCE LINK — source_type='recycling_sale'. Matches the recycling_
     * sales.id foreign key. No cancel path exists today (recycler sales
     * are meant to be final at the gate), but if one is ever built, it can
     * call JournalPoster::reverseSource('recycling_sale', $sale_id).
     *
     * Called inside the caller's transaction. Does NOT open its own.
     *
     * @param int    $sale_id             recycling_sales.id
     * @param int    $treasury_account_id treasury_accounts.id receiving the cash
     * @param float  $amount              FCFA, gross (matches recycling_sales.total_amount)
     * @param string $sale_date           YYYY-MM-DD; drives the JE date
     * @param string $reference           recycling_sales.reference (REC-YYMM-NNN)
     * @return int                        journal_entries.id
     */
    public static function postRecyclingSale(
        int $sale_id,
        int $treasury_account_id,
        float $amount,
        string $sale_date,
        string $reference
    ): int {
        $db      = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $amount = self::roundFcfa($amount);
        if ($amount <= 0) {
            // A zero-value hand-off is legitimate (bad lot given to the
            // recycler for free — see the comment in sell_to_recycler for
            // migration 060) but there is no revenue to book. Refuse to
            // post a zero-line JE; the caller must skip this call.
            throw new RuntimeException("JournalPoster::postRecyclingSale — non-positive amount for sale #{$sale_id}.");
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sale_date)) {
            throw new RuntimeException("JournalPoster::postRecyclingSale — sale_date must be YYYY-MM-DD.");
        }

        $revenue_coa  = self::coaByOhada($db, '7074');
        if (!$revenue_coa) {
            throw new RuntimeException(
                "JournalPoster::postRecyclingSale — OHADA 7074 (Bonis sur reprises et cessions "
                . "d'emballages) is not mapped in chart_of_accounts. Apply migration 041."
            );
        }
        $treasury_coa = self::coaFromTreasury($db, $treasury_account_id);

        $ref  = 'REC-' . $reference;
        $desc = "Vente recyclage — réf {$reference}";

        // Journal: CA for a cash box, BQ for a bank or MoMo account, like every
        // other treasury-side entry (journalForTreasury). The sub-ledger lives in
        // the treasury COA the coaFromTreasury lookup returned.
        $je_id = self::createDraftJe(
            $db, $ref, self::journalForTreasury($db, $treasury_account_id), $sale_date, $desc, $user_id,
            'recycling_sale', $sale_id
        );
        self::addLine($db, $je_id, $treasury_coa, $amount, 0.0);  // Dr 5xx
        self::addLine($db, $je_id, $revenue_coa,  0.0,     $amount); // Cr 7074
        self::post($db, $je_id, $user_id);

        // Treasury inflow — 'in_recycling_sale' added by migration 094 so
        // the operations dashboard can filter recycling revenue apart from
        // ad-hoc 'in_other' cash-in.
        $db->prepare("
            INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
            VALUES (?, 'in_recycling_sale', ?, ?, ?, ?)
        ")->execute([$treasury_account_id, $amount, $ref, $desc, $user_id]);

        $db->prepare("UPDATE treasury_accounts SET balance = balance + ? WHERE id = ?")
           ->execute([$amount, $treasury_account_id]);

        // Stamp the sale so idempotency + audit both work. The column is
        // set here (not by the caller) so any writer using this method
        // gets the linkage automatically.
        $db->prepare("UPDATE recycling_sales SET payment_je_id = ?, treasury_account_id = ? WHERE id = ?")
           ->execute([$je_id, $treasury_account_id, $sale_id]);

        return $je_id;
    }

    /**
     * Reverse every posted entry produced by a given source document.
     *
     * Migration 004 forbids deleting a posted entry, and rightly so — the
     * correction for a mistake in the books is an opposing entry, not an
     * erasure. This mirrors each line (debits become credits), posts the
     * mirror, and points the original at its mirror (reversed_by_entry_id).
     * The original stays 'posted': with its mirror it nets to zero in every
     * report, which read posted entries only (VIDEO_QA_FINDINGS 11.4).
     *
     * The reversal is dated today, not on the original entry's date: back-
     * dating it would drop the correction into a period that may already be
     * closed, and migration 005's triggers would refuse the write anyway.
     *
     * Entries already pointing at a mirror are skipped, so calling this twice
     * on the same document is harmless.
     *
     * @param  string   $source_type e.g. 'purchase_order', 'rebate_accrual'
     * @param  int      $source_id
     * @param  string   $reason      shown in the reversing entry's description
     * @return int[]                 ids of the reversing entries created
     */
    /**
     * Post the settlement of a tax or social declaration (Déclarations Fiscales
     * → « Enregistrer paiement »), VIDEO_QA_FINDINGS 13.1 and 13.2.
     *
     * $lines are the declaration's own accounts, as SYSCOHADA numbers:
     *   [['account' => '432', 'debit' => 57246, 'credit' => 0], ...]
     * — the liabilities the month booked (4431 TVA, 432 DIPE, 433 CNPS…) are
     * debited, the credits it uses (4452 TVA déductible, 4449 crédit reporté,
     * 4424 AIR retenu) are credited. $cash leaves the treasury account: the
     * last credit line, the treasury's own sub-account, journal BQ, and a
     * treasury movement 'out_tax_payment'. A declaration that nets to zero
     * (a TVA credit month) posts its lines in OD and moves no money.
     *
     * Called inside the caller's transaction; throws rather than half-posting
     * (the old path swallowed the error and marked the declaration paid).
     */
    public static function postTaxSettlement(
        int $declaration_id,
        string $label,
        string $date,
        array $lines,
        ?int $treasury_account_id,
        float $cash,
        int $user_id
    ): int {
        $db   = Database::getInstance()->getConnection();
        require_once __DIR__ . '/../functions/books_period.php';
        $cash = self::roundFcfa($cash);
        $day  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
        if ($cash > 0 && !$treasury_account_id) throw new RuntimeException("Choisissez le compte de trésorerie qui paie.");

        $resolved = [];
        foreach ($lines as $l) {
            $num = (string) $l['account'];
            $id  = self::coaByOhada($db, $num) ?: lpc_books_account($db, $num);
            if (!$id) throw new RuntimeException("Compte {$num} absent du plan comptable : créez-le dans Plan Comptable.");
            $resolved[] = [$id, self::roundFcfa((float) ($l['debit'] ?? 0)), self::roundFcfa((float) ($l['credit'] ?? 0))];
        }
        $treasury_coa = $cash > 0 ? self::coaFromTreasury($db, (int) $treasury_account_id) : 0;
        if ($cash > 0) {
            // Never more than the account holds, as Dépenses and Immobilisations refuse.
            $bal = $db->prepare("SELECT name, balance FROM treasury_accounts WHERE id = ? FOR UPDATE");
            $bal->execute([(int) $treasury_account_id]);
            $ta = $bal->fetch(PDO::FETCH_ASSOC);
            if ($ta && (float) $ta['balance'] + 0.5 < $cash) {
                throw new RuntimeException("Solde insuffisant sur « {$ta['name']} » (" . number_format((float) $ta['balance'], 0, ',', ' ')
                    . " FCFA) pour payer " . number_format($cash, 0, ',', ' ') . " FCFA.");
            }
        }

        $sumD = 0.0; $sumC = $cash;
        foreach ($resolved as [, $d, $c]) { $sumD += $d; $sumC += $c; }
        if (abs($sumD - $sumC) > 0.5) {
            throw new RuntimeException(sprintf("Règlement non équilibré (débit %s, crédit %s).",
                number_format($sumD, 0, ',', ' '), number_format($sumC, 0, ',', ' ')));
        }

        $ref = 'TAX-' . $declaration_id;
        $je_id = self::createDraftJe($db, $ref, $cash > 0 ? self::journalForTreasury($db, $treasury_account_id) : 'OD', $day, mb_substr($label, 0, 255), $user_id,
                                     'tax_declaration', $declaration_id);
        foreach ($resolved as [$id, $d, $c]) self::addLine($db, $je_id, $id, $d, $c);
        if ($cash > 0) self::addLine($db, $je_id, $treasury_coa, 0.0, $cash);
        self::post($db, $je_id, $user_id);

        if ($cash > 0) {
            $db->prepare("
                INSERT INTO treasury_transactions (account_id, transaction_type, amount, reference, description, logged_by)
                VALUES (?, 'out_tax_payment', ?, ?, ?, ?)
            ")->execute([(int) $treasury_account_id, $cash, $ref, mb_substr($label, 0, 255), $user_id]);
            $db->prepare("UPDATE treasury_accounts SET balance = balance - ? WHERE id = ?")
               ->execute([$cash, (int) $treasury_account_id]);
        }
        return $je_id;
    }

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
               AND reversed_by_entry_id IS NULL
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

            // The original STAYS posted and points at its mirror: the two
            // cancel out in every balance. Stamping it 'reversed' took it out
            // of the reports (they read posted only) while the mirror stayed,
            // so a cancellation counted twice (VIDEO_QA_FINDINGS 11.4).
            $db->prepare("UPDATE journal_entries SET reversed_by_entry_id = ? WHERE id = ?")
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

    /**
     * Apply a customer's wallet balance (OHADA 419 Clients, avances et acomptes
     * reçus) to an invoice. Called from apply_client_wallet AFTER:
     *   · client_wallets.balance has been decremented
     *   · a payments row with payment_method = 'wallet' has been inserted
     *
     * OHADA lines:
     *   Debit  419  Clients, avances et acomptes reçus   (the wallet drops)
     *   Credit 411  Clients                              (the receivable drops)
     *
     * No treasury_transactions row: no cash actually moves. The wallet is
     * already sitting on the balance sheet as a liability to the client — this
     * entry simply nets it against the receivable.
     *
     * Rounding, source_type/source_id, and error semantics mirror
     * postInvoicePayment. Called inside the caller's transaction.
     *
     * @param int $payment_id  payments.id (payment_method = 'wallet')
     * @return int             journal_entries.id
     */
    public static function postWalletApplication(int $payment_id): int
    {
        $db = Database::getInstance()->getConnection();
        $user_id = (int) ($_SESSION['user_id'] ?? 0);

        $stmt = $db->prepare("
            SELECT p.id, p.reference, p.invoice_id, p.client_id, p.amount, p.payment_date
              FROM payments p
             WHERE p.id = ? AND p.payment_method = 'wallet' FOR UPDATE
        ");
        $stmt->execute([$payment_id]);
        $pay = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pay) {
            throw new RuntimeException("JournalPoster: wallet payment #{$payment_id} not found.");
        }
        $amount = self::roundFcfa((float) $pay['amount']);
        if ($amount <= 0) {
            throw new RuntimeException("JournalPoster: wallet payment #{$payment_id} has non-positive amount.");
        }
        if (empty($pay['invoice_id'])) {
            // Applying a wallet with no invoice would post 419→411 with nothing
            // to clear — that's the operational bug this method exists to
            // prevent. Manual credit-note flows post 411→419 the other way.
            throw new RuntimeException("JournalPoster: wallet payment #{$payment_id} has no invoice to clear.");
        }

        $client_419 = self::coaByOhada($db, '419');
        $client_411 = self::coaForClient($db, (int) $pay['client_id']);   // its own 411 (8.1)
        if (!$client_419) throw new RuntimeException("JournalPoster: 419 (avances clients) not mapped — run migration 020.");
        if (!$client_411) throw new RuntimeException("JournalPoster: 411 (Clients) not mapped.");

        $ref  = 'WLT-' . $pay['reference'];
        $ir = $db->prepare("SELECT reference FROM invoices WHERE id = ?");
        $ir->execute([(int) $pay['invoice_id']]);
        $desc = "Trop-perçu utilisé sur la facture " . ((string) ($ir->fetchColumn() ?: '#' . $pay['invoice_id']));
        $je_id = self::createDraftJe($db, $ref, 'OD', (string) $pay['payment_date'], $desc, $user_id,
                                     'wallet_apply', (int) $payment_id);
        self::addLine($db, $je_id, $client_419, $amount, 0.0);
        self::addLine($db, $je_id, $client_411, 0.0,     $amount);
        self::post($db, $je_id, $user_id);

        return $je_id;
    }

    /**
     * Letter (lettrage) a document once it is settled: the lines its entries put
     * on the client's 411 or the supplier's 401 sub-account, when they cancel to
     * the franc, get the account's next free letter (A, B … ZZZ), as the Grand
     * Livre's lettrage would. For an invoice: its sale, its credit notes and its
     * payments; for a purchase order: its reception and its payment. A « solde
     * d'ouverture » document (is_opening, the owner's answer 26) matches the
     * Bilan d'Ouverture's line on that sub-account instead of a sale or a
     * reception. Lines already lettered are left alone; nothing happens while
     * the lines do not cancel (a partial payment). Returns the letter or null.
     */
    public static function letterDocument(PDO $db, string $kind, int $doc_id): ?string
    {
        if ($kind === 'invoice') {
            $q = $db->prepare("SELECT client_id, date, COALESCE(is_opening, 0) AS is_opening FROM invoices WHERE id = ?");
            $q->execute([$doc_id]);
            $doc = $q->fetch(PDO::FETCH_ASSOC);
            if (!$doc) return null;
            $account = self::coaForClient($db, (int) $doc['client_id']);
            $where = "((je.source_type IN ('invoice', 'invoice_credit_note') AND je.source_id = :id)
                       OR (je.source_type = 'invoice_payment' AND je.source_id IN (SELECT id FROM payments WHERE invoice_id = :id2)))";
        } elseif ($kind === 'purchase_order') {
            $q = $db->prepare("SELECT supplier_id, date, COALESCE(is_opening, 0) AS is_opening FROM purchase_orders WHERE id = ?");
            $q->execute([$doc_id]);
            $doc = $q->fetch(PDO::FETCH_ASSOC);
            if (!$doc) return null;
            $account = self::coaForSupplier($db, (int) $doc['supplier_id']);
            $where = "(je.source_type IN ('purchase_order', 'purchase_order_payment') AND je.source_id = :id)";
        } else {
            return null;
        }
        $params = [':id' => $doc_id, ':acc' => $account];
        if ($kind === 'invoice') $params[':id2'] = $doc_id;
        if ((int) $doc['is_opening'] === 1) {
            $year = (int) substr((string) $doc['date'], 0, 4);
            $ob = $db->prepare("SELECT opening_balance_journal_id FROM financial_years WHERE year = ?");
            $ob->execute([$year]);
            $openId = (int) $ob->fetchColumn();
            if ($openId) { $where = "($where OR je.id = :open)"; $params[':open'] = $openId; }
        }
        $st = $db->prepare("
            SELECT jl.id, jl.debit, jl.credit, jl.lettrage
              FROM journal_lines jl
              JOIN journal_entries je ON je.id = jl.journal_entry_id AND je.status = 'posted'
             WHERE jl.account_id = :acc AND je.reversed_by_entry_id IS NULL AND $where
        ");
        $st->execute($params);
        $lines = $st->fetchAll(PDO::FETCH_ASSOC);
        $open = array_values(array_filter($lines, fn ($l) => $l['lettrage'] === null || $l['lettrage'] === ''));
        if (count($open) < 2) return null;
        $net = 0.0;
        foreach ($open as $l) $net += (float) $l['debit'] - (float) $l['credit'];
        if (abs($net) >= 0.5) return null;

        $used = $db->prepare("SELECT DISTINCT lettrage FROM journal_lines WHERE account_id = ? AND lettrage IS NOT NULL");
        $used->execute([$account]);
        $taken = array_flip($used->fetchAll(PDO::FETCH_COLUMN));
        $letter = null;
        for ($len = 1; $len <= 3 && $letter === null; $len++) {
            for ($n = 0; $n < 26 ** $len; $n++) {
                $s = ''; $x = $n;
                for ($i = 0; $i < $len; $i++) { $s = chr(65 + ($x % 26)) . $s; $x = intdiv($x, 26); }
                if (!isset($taken[$s])) { $letter = $s; break; }
            }
        }
        if ($letter === null) return null;
        $upd = $db->prepare("UPDATE journal_lines SET lettrage = ? WHERE id = ?");
        foreach ($open as $l) $upd->execute([$letter, (int) $l['id']]);
        return $letter;
    }

    /**
     * The journal a treasury movement belongs in (VIDEO_QA_FINDINGS 11.5):
     * « CA » (caisse) for a cash box, « BQ » for a bank or Mobile Money account.
     * Everything that moved money used BQ, so the Caisse journal stayed empty.
     */
    public static function journalForTreasury(PDO $db, ?int $treasury_account_id): string
    {
        if (!$treasury_account_id) return 'BQ';
        static $cache = [];
        if (!isset($cache[$treasury_account_id])) {
            $q = $db->prepare("SELECT type FROM treasury_accounts WHERE id = ?");
            $q->execute([$treasury_account_id]);
            $cache[$treasury_account_id] = (string) $q->fetchColumn();
        }
        return $cache[$treasury_account_id] === 'caisse' ? 'CA' : 'BQ';
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

    /**
     * The chart row an OHADA number posts to. Cached per request.
     *
     * The parent's own row first (code = the OHADA number: « 601 Achats de
     * marchandises »); failing that, the parent's only active sub-account.
     * Never « the oldest row under the parent » (the owner's decision 13,
     * 18/11/2026): that sent client receivables to a deleted test client's
     * 411004 and would have sent CCA Bank's entries to the legacy « LPC52111
     * Afriland First Bank (AFB) ». Null when neither exists: the caller
     * refuses to post rather than guess.
     */
    public static function coaByOhada(PDO $db, string $prefix): ?int
    {
        static $cache = [];
        if (isset($cache[$prefix])) return $cache[$prefix];

        $stmt = $db->prepare("
            SELECT coa.id FROM chart_of_accounts coa
              JOIN ohada_accounts o ON coa.ohada_account_id = o.id
             WHERE o.account_number = ? AND coa.code = o.account_number
             LIMIT 1
        ");
        $stmt->execute([$prefix]);
        $r = $stmt->fetchColumn();
        if (!$r) {
            $stmt = $db->prepare("
                SELECT coa.id FROM chart_of_accounts coa
                  JOIN ohada_accounts o ON coa.ohada_account_id = o.id
                 WHERE o.account_number = ? AND coa.is_active = 1
                 LIMIT 2
            ");
            $stmt->execute([$prefix]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $r = count($ids) === 1 ? $ids[0] : null;
        }
        return $cache[$prefix] = ($r ? (int) $r : null);
    }

    /**
     * The client's own receivable sub-account (clients.account_id, 411030,
     * 411035…), so each client's ledger is its statement (VIDEO_QA_FINDINGS
     * 8.1). Every invoice, payment and credit note used to post to « 411
     * Clients » whatever the client: the Balance des Tiers could not say who
     * owed what. A client whose account is missing or not under 411 is an error
     * the screen explains: saving it in Base Clients creates one.
     */
    public static function coaForClient(PDO $db, int $client_id): int
    {
        $stmt = $db->prepare("
            SELECT c.name, coa.id AS coa_id, coa.is_active, o.account_number
              FROM clients c
              LEFT JOIN chart_of_accounts coa ON coa.id = c.account_id
              LEFT JOIN ohada_accounts o ON o.id = coa.ohada_account_id
             WHERE c.id = ?
        ");
        $stmt->execute([$client_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Client #{$client_id} introuvable.");
        if (empty($row['coa_id']) || strpos((string) $row['account_number'], '411') !== 0) {
            throw new RuntimeException(
                "Le client « {$row['name']} » n'a pas de compte client (411…) au plan comptable. "
                . "Ouvrez sa fiche dans Base Clients & Devis et enregistrez-la : son compte est créé."
            );
        }
        return (int) $row['coa_id'];
    }

    /**
     * Map a treasury_accounts row to its own chart sub-account
     * (treasury_accounts.coa_account_id: 5211 CCA Bank, 5521 MTN Mobile Money,
     * 5711 Caisse Principale - Maison), so each ledger matches its statement.
     *
     * There is no fallback any more (the owner's decision 12, 18/11/2026): an
     * account without its sub-account used to post to the oldest row under its
     * type's parent (« Caisse Principale Siège », the shared 552…), which is
     * how the ledgers stopped matching the accounts. Trésorerie & Banque
     * creates the sub-account with the account (TreasuryAccount::ensureLedger);
     * migration 144 created the missing ones and moved their past lines.
     */
    public static function coaFromTreasury(PDO $db, int $treasury_id): int
    {
        $stmt = $db->prepare("
            SELECT t.name, c.id AS coa_id
              FROM treasury_accounts t
              LEFT JOIN chart_of_accounts c ON c.id = t.coa_account_id
             WHERE t.id = ?
        ");
        $stmt->execute([$treasury_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException("Compte de trésorerie #{$treasury_id} introuvable.");
        if (empty($row['coa_id'])) {
            throw new RuntimeException(
                "Le compte « {$row['name']} » n'a pas de compte au plan comptable. "
                . "Ouvrez-le dans Trésorerie & Banque (Comptes & Caisse) et enregistrez-le : son sous-compte est créé."
            );
        }
        return (int) $row['coa_id'];
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
