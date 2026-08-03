#!/usr/bin/env python3
"""
scripts/tests/syscohada_postings.test.py
------------------------------------------------------------------------------
Bureau LPC ERP — SYSCOHADA posting rules, executable.

WHY THIS EXISTS
    scripts/tests/books_balance.sql answers "do the books balance". It cannot
    answer "do they balance for the RIGHT reason" — an entry that debits 601
    with VAT included balances perfectly and is still wrong. Every defect the
    compliance audit found was of that shape: balanced, posted, and misstated.

    This file encodes the SYSCOHADA rule for each achats/ventes scenario as an
    expected ledger, reproduces what the code now computes, and fails on any
    difference. It runs with no database and no PHP — it is a specification
    that executes, so a future change to the rounding order or the account
    mapping has something to fail against.

    Each scenario is asserted on three axes:
      1. the entry balances (Σ debit == Σ credit)
      2. every line lands on the SYSCOHADA account, not merely a plausible one
      3. the amounts are the whole-franc FCFA figures the code produces,
         including the rounding order

USAGE
    python3 scripts/tests/syscohada_postings.test.py
    Exit 0 = all scenarios hold. Exit 1 = a rule is broken; the offending
    scenario, account and delta are printed.
"""

import sys
from decimal import Decimal, ROUND_HALF_UP

FAILURES = []
CHECKS = 0


# ---------------------------------------------------------------------------
# FCFA arithmetic. The franc has no subunit, so every posted amount is a whole
# number and every rounding is half-up — matching JournalPoster::roundFcfa,
# which is round($x, 0, PHP_ROUND_HALF_UP).
# ---------------------------------------------------------------------------
def fcfa(x) -> Decimal:
    return Decimal(str(x)).quantize(Decimal("1"), rounding=ROUND_HALF_UP)


class Entry:
    """One journal entry: account_number -> (debit, credit)."""

    def __init__(self, name):
        self.name = name
        self.lines = []

    def debit(self, account, amount):
        amount = fcfa(amount)
        if amount > 0:
            self.lines.append((account, amount, Decimal(0)))
        return self

    def credit(self, account, amount):
        amount = fcfa(amount)
        if amount > 0:
            self.lines.append((account, Decimal(0), amount))
        return self

    @property
    def total_debit(self):
        return sum((d for _, d, _ in self.lines), Decimal(0))

    @property
    def total_credit(self):
        return sum((c for _, _, c in self.lines), Decimal(0))

    def net(self, account):
        """Signed movement on an account: debit positive, credit negative."""
        return sum((d - c for a, d, c in self.lines if a == account), Decimal(0))

    def accounts(self):
        return sorted({a for a, _, _ in self.lines})


def check(label, actual, expected):
    global CHECKS
    CHECKS += 1
    if actual != expected:
        FAILURES.append(f"  {label}\n      expected {expected}\n      actual   {actual}")


def check_balanced(entry):
    global CHECKS
    CHECKS += 1
    if entry.total_debit != entry.total_credit:
        FAILURES.append(
            f"  {entry.name}: UNBALANCED — debit {entry.total_debit} != credit {entry.total_credit}"
        )
    # post_journal_entry (migration 065) now also refuses an entry that has
    # fewer than two lines or moves nothing. Assert the same invariants here so
    # a scenario cannot pass this file and be rejected by the database.
    CHECKS += 1
    if len(entry.lines) < 2:
        FAILURES.append(f"  {entry.name}: fewer than two lines — post_journal_entry would refuse it")
    CHECKS += 1
    if entry.total_debit == 0:
        FAILURES.append(f"  {entry.name}: moves nothing — post_journal_entry would refuse it")


# ===========================================================================
# ACHATS — goods received against a purchase order
# ===========================================================================
def scenario_goods_receipt():
    """
    Supplier quotes TTC, as they always do here. 100 cases at 11 925 FCFA TTC
    with TVA at 19,25 %.

    SYSCOHADA:
        Dr 601  Achats de marchandises       HT
        Dr 4452 TVA récupérable sur achats   the deductible VAT
        Cr 401  Fournisseur                  TTC — what we actually owe

    THE DEFECT THIS PINS DOWN: the whole TTC used to be debited to 601 with no
    4452 line at all. It balanced. It also overstated cost of goods by the VAT
    and left the company unable to evidence a single franc of input credit.
    """
    ttc = Decimal("1192500")          # 100 x 11 925
    vat_rate = Decimal("0.1925")

    # api/v1/inventory_controller.php :: receive_po
    vat = fcfa(ttc - (ttc / (1 + vat_rate)))
    ht = fcfa(ttc - vat)

    e = Entry("Achats · réception marchandises (TTC 1 192 500, TVA 19,25%)")
    e.debit("601", ht).debit("4452", vat).credit("401", ttc)

    check_balanced(e)
    check("goods receipt · 601 carries HT only", e.net("601"), Decimal("1000000"))
    check("goods receipt · 4452 carries the deductible VAT", e.net("4452"), Decimal("192500"))
    check("goods receipt · 401 credited the full TTC", -e.net("401"), ttc)
    check("goods receipt · VAT is not inside 601", e.net("601"), ttc - vat)
    return e


def scenario_goods_receipt_exonerated():
    """
    Water is exonerated (art. 128 CGI). Non-deductible VAT is not a receivable
    against the State — it is part of what the goods cost. The whole amount
    stays in 601 and 4452 is not touched at all.

    Booking a 4452 line here would create an asset the company cannot claim.
    """
    ttc = Decimal("500000")
    vat = Decimal(0)                  # vat_recoverable = 0

    e = Entry("Achats · réception exonérée (eau, art. 128 CGI)")
    e.debit("601", ttc - vat).credit("401", ttc)

    check_balanced(e)
    check("exonerated receipt · whole amount in 601", e.net("601"), ttc)
    check("exonerated receipt · 4452 untouched", "4452" in e.accounts(), False)
    return e


def scenario_rebate_accrual():
    """
    Ristourne earned at reception. Category ladder tier 4 %, TVA 19,25 %,
    précompte 2 % — all three frozen on the order.

    SYSCOHADA:
        Dr 4098 Fournisseurs, RRR à obtenir   net (what the supplier settles)
        Dr 4452 TVA récupérable               VAT withheld from the rebate
        Dr 4473 Précompte subi                précompte withheld
        Cr 6019 RRR obtenus sur achats        GROSS — the full cost reduction

    THE DEFECT: only the net was posted, to both sides. 6019 understated the
    reduction in cost of goods, and the withheld tax — which is imputable
    against the company's own tax — was recorded nowhere at all.

    THE CONSTRAINT THAT MATTERS OPERATIONALLY: 4098 must still carry exactly
    the net, because that is what supplier_rebate_ledger and the Ristournes SDP
    panel track. If this fix moved 4098 the panel and the ledger would diverge
    and every historical balance would need restating.
    """
    ht = Decimal("1000000")
    tier_rate = Decimal("0.04")
    vat_rate = Decimal("0.1925")
    precompte_rate = Decimal("0.02")

    # Rounding ORDER is part of the contract: each component to the franc, then
    # the net as the remainder. Rounding all three independently can leave a
    # 1 FCFA residual, and post_journal_entry rejects the entry — rolling back
    # a goods reception over a rounding artefact.
    gross = fcfa(ht * tier_rate)
    vat = fcfa(gross * vat_rate)
    precompte = fcfa(gross * precompte_rate)
    net = gross - vat - precompte

    e = Entry("Achats · ristourne acquise (palier 4%)")
    e.debit("4098", net).debit("4452", vat).debit("4473", precompte).credit("6019", gross)

    check_balanced(e)
    check("rebate · components sum to gross exactly", net + vat + precompte, gross)
    check("rebate · 6019 credited the gross", -e.net("6019"), gross)
    check("rebate · 4098 still carries the net (panel parity)", e.net("4098"), net)
    check("rebate · net matches the legacy formula to the franc",
          abs(net - fcfa(gross * (1 - (vat_rate + precompte_rate)))) <= 1, True)
    return e


def scenario_purchase_line_remise():
    """
    THE DOUBLE-COUNT. A supplier grants a one-off cut on a line: tariff 1 000,
    paid 900, 100 units. subtotal is summed at LIST, and the gap goes into
    discount_amount.

        subtotal (list)   100 000
        line reduction     10 000
        typed price         900  -> purchase_order_items.unit_price

    Reception books 601/401 at unit_price, which is ALREADY net of that
    reduction. Deducting it a second time through postRebateUsage
    (Dr 401 / Cr 4098) left the supplier under-credited by the reduction, and
    charged a negotiated price cut against a rebate pool granted for volume.

    After the fix only the typed header "Remise (FCFA)" draws on the pool.
    """
    list_total = Decimal("100000")
    line_reduction = Decimal("10000")
    header_remise = Decimal("5000")      # genuine ristourne credit spent

    received_ttc = list_total - line_reduction   # what poi.unit_price sums to

    receipt = Entry("Achats · réception (au prix payé)")
    receipt.debit("601", received_ttc).credit("401", received_ttc)

    usage = Entry("Achats · utilisation ristourne")
    usage.debit("401", header_remise).credit("4098", header_remise)

    check_balanced(receipt)
    check_balanced(usage)

    owed = -(receipt.net("401") + usage.net("401"))
    expected_owed = list_total - line_reduction - header_remise   # = total_amount
    check("purchase remise · 401 equals purchase_orders.total_amount", owed, expected_owed)
    check("purchase remise · pool charged the header remise only",
          -usage.net("4098"), header_remise)

    # The old behaviour, kept as a regression witness.
    old_usage = Entry("(défaut historique) utilisation ristourne = remise + ligne")
    old_usage.debit("401", header_remise + line_reduction)
    old_usage.credit("4098", header_remise + line_reduction)
    old_owed = -(receipt.net("401") + old_usage.net("401"))
    check("purchase remise · old path did understate the payable",
          old_owed == expected_owed - line_reduction, True)

    return receipt


# ===========================================================================
# VENTES — invoice issued, then settled
# ===========================================================================
def scenario_invoice_with_remise():
    """
    Invoice with a commercial reduction carried over from the sales order.

        gross HT      1 000 000
        remise           50 000   (sales_orders.discount_amount, pro-rated)
        net HT          950 000
        TVA 19,25 %     182 875   <- on the NET, never the gross

    SYSCOHADA:
        Dr 411  Clients                    TTC
        Cr 701  Ventes de marchandises     NET HT
        Cr 4431 TVA facturée sur ventes    TVA

    TWO DEFECTS PINNED HERE:
      · the remise never crossed from the order to the invoice, so the client
        was billed the gross and 411/701/TVA were all overstated by it;
      · output VAT was credited to 4432, which in SYSCOHADA révisé is TVA on
        SERVICES. Sales of goods belong in 4431.

    And the rule that is deliberately NOT applied: the remise gets no line of
    its own. A reduction granted ON the invoice is never booked — revenue is
    recognised at the net. Only a post-invoice avoir reaches 7019.
    """
    gross_ht = Decimal("1000000")
    remise = Decimal("50000")
    net_ht = gross_ht - remise
    tva = fcfa(net_ht * Decimal("0.1925"))
    ttc = net_ht + tva

    e = Entry("Ventes · facture avec remise sur facture")
    e.debit("411", ttc).credit("701", net_ht).credit("4431", tva)

    check_balanced(e)
    check("invoice · 701 credited the NET commercial base", -e.net("701"), Decimal("950000"))
    check("invoice · TVA computed on the net", -e.net("4431"), Decimal("182875"))
    check("invoice · output VAT is 4431, not 4432", "4432" in e.accounts(), False)
    check("invoice · no 7019 line for an on-invoice reduction", "7019" in e.accounts(), False)
    check("invoice · 411 equals what the client owes", e.net("411"), ttc)
    return e


def scenario_invoice_full_fiscal_stack():
    """
    Everything migration 040 stores, actually posted. Accises, then TVA on
    (HT + accises), a withholding client keeping AIR, and a settlement discount.

        net HT          1 000 000
        accises 25 %      250 000   -> 4461, and joins the TVA base
        TVA 19,25 %       240 625   on 1 250 000
        TTC             1 490 625
        AIR 2,2 % of HT    22 000   -> 4424, client remits for us
        escompte 1 %       10 000   -> 673, financial charge
        net payable     1 458 625

    THE DEFECT: excise_amount was stored and never posted — the accise was
    collected from the client inside the total and never appeared as a debt to
    the State. And there was no escompte account at all, so a settlement
    discount had to be smuggled into revenue, which is exactly what SYSCOHADA
    separates 673 to prevent.
    """
    ht = Decimal("1000000")
    excise = fcfa(ht * Decimal("0.25"))
    tva = fcfa((ht + excise) * Decimal("0.1925"))
    ttc = ht + excise + tva
    air = fcfa(ht * Decimal("0.022"))
    escompte = fcfa(ht * Decimal("0.01"))
    net_payable = ttc - air - escompte

    e = Entry("Ventes · facture accises + AIR + escompte")
    e.debit("411", net_payable).debit("4424", air).debit("673", escompte)
    e.credit("701", ht).credit("4461", excise).credit("4431", tva)

    check_balanced(e)
    check("full stack · accises booked to 4461", -e.net("4461"), Decimal("250000"))
    check("full stack · TVA base includes the accise", -e.net("4431"), Decimal("240625"))
    check("full stack · escompte is financial (673), not netted into 701",
          e.net("673"), Decimal("10000"))
    check("full stack · 701 untouched by the escompte", -e.net("701"), ht)
    check("full stack · AIR clears the receivable via 4424", e.net("4424"), Decimal("22000"))
    check("full stack · reconciliation identity holds",
          net_payable + air + escompte, ht + excise + tva)
    return e


def scenario_payment_with_overpayment():
    """
    Client owes 500 000 and transfers 600 000.

    SYSCOHADA:
        Dr 521  Banque                          600 000
        Cr 411  Clients                         500 000   (the debt, cleared)
        Cr 419  Clients, avances et acomptes     100 000   (the advance)

    THE DEFECT: the whole 600 000 was credited to 411, so the client's
    receivable went 100 000 into DEBIT — an asset account reading negative —
    while the 100 000 sat in client_wallets, which is the operational face of
    419, and 419 itself stayed empty. The balance sheet understated liabilities
    by every wallet balance in the system.
    """
    open_balance = Decimal("500000")
    received = Decimal("600000")
    settled = min(received, open_balance)
    advance = received - settled

    e = Entry("Ventes · encaissement avec trop-perçu")
    e.debit("521", received).credit("411", settled).credit("419", advance)

    check_balanced(e)
    check("overpayment · 411 clears exactly the debt", -e.net("411"), open_balance)
    check("overpayment · excess lands in 419", -e.net("419"), Decimal("100000"))
    check("overpayment · 411 never goes debit", e.net("411") <= 0, True)
    return e


def scenario_credit_note():
    """
    Reduction granted AFTER the invoice — an avoir.

        Dr 7019 RRR accordés     50 000
        Dr 4431 TVA facturée      9 625   (the VAT given back)
        Cr 411  Clients          59 625

    Netting this into 701 instead would understate turnover for the period —
    and turnover is the figure the patente and the CA thresholds are assessed
    on, so it is not a presentational choice.
    """
    amount_ht = Decimal("50000")
    vat = fcfa(amount_ht * Decimal("0.1925"))

    e = Entry("Ventes · avoir (remise hors facture)")
    e.debit("7019", amount_ht).debit("4431", vat).credit("411", amount_ht + vat)

    check_balanced(e)
    check("credit note · reduction visible on 7019", e.net("7019"), amount_ht)
    check("credit note · VAT reversed on 4431", e.net("4431"), vat)
    check("credit note · 701 untouched", "701" in e.accounts(), False)
    return e


def scenario_cogs_on_dispatch():
    """
    Stock leaves on a delivery note, valued at CUMP.

        Dr 6031 Variations des stocks   qty x cump
        Cr 31x  Stocks                  qty x cump

    The audit fix here is upstream: CUMP is now built from the HT cost, not the
    TTC price. Recoverable VAT is a receivable against the State, never a cost,
    and carrying it inflated stock on the balance sheet and COGS on every sale.
    """
    qty = Decimal("100")
    cump_ht = Decimal("10000")          # was 11 925 with VAT baked in
    value = fcfa(qty * cump_ht)

    e = Entry("Ventes · sortie de stock au CUMP")
    e.debit("6031", value).credit("311", value)

    check_balanced(e)
    check("COGS · valued at HT cost", e.net("6031"), Decimal("1000000"))
    check("COGS · VAT excluded from stock value",
          e.net("6031") < fcfa(qty * Decimal("11925")), True)
    return e


# ===========================================================================
# CROSS-CUTTING: a full purchase-to-sale cycle must leave a coherent ledger
# ===========================================================================
def scenario_full_cycle():
    """
    Buy 1 192 500 TTC, sell the same goods for 1 490 625 TTC, and check the
    two VAT accounts against what the DGI declaration should say.

        TVA due = (4431 + 4432) - (4451 + 4452 + 4453 + 4454)

    Before the fix 4452 was zero because nothing ever debited it, so TVA due
    read as the whole output VAT — the company would have remitted 192 500 FCFA
    per cycle that it did not owe.
    """
    purchase_ttc = Decimal("1192500")
    input_vat = fcfa(purchase_ttc - purchase_ttc / Decimal("1.1925"))

    sale_ht = Decimal("1250000")
    output_vat = fcfa(sale_ht * Decimal("0.1925"))

    tva_due = output_vat - input_vat
    check("cycle · input VAT recognised", input_vat, Decimal("192500"))
    check("cycle · TVA due is output minus input", tva_due, Decimal("48125"))

    # What the books said before the fix.
    tva_due_before = output_vat - Decimal(0)
    check("cycle · old path overstated TVA due by the input credit",
          tva_due_before - tva_due, input_vat)
    return None


# ===========================================================================
def main():
    scenarios = [
        scenario_goods_receipt,
        scenario_goods_receipt_exonerated,
        scenario_rebate_accrual,
        scenario_purchase_line_remise,
        scenario_invoice_with_remise,
        scenario_invoice_full_fiscal_stack,
        scenario_payment_with_overpayment,
        scenario_credit_note,
        scenario_cogs_on_dispatch,
        scenario_full_cycle,
    ]

    print("SYSCOHADA posting rules — achats / ventes")
    print("=" * 72)
    for fn in scenarios:
        before = len(FAILURES)
        entry = fn()
        status = "FAIL" if len(FAILURES) > before else "ok"
        label = entry.name if entry is not None else fn.__name__.replace("scenario_", "")
        print(f"  [{status:4}] {label}")
        if entry is not None:
            for acct, d, c in entry.lines:
                side = f"D {d:>12,}" if d else f"C {c:>12,}"
                print(f"           {acct:<6} {side}".replace(",", " "))

    print("=" * 72)
    if FAILURES:
        print(f"{len(FAILURES)} of {CHECKS} assertions FAILED:\n")
        print("\n".join(FAILURES))
        return 1

    print(f"{CHECKS} assertions passed across {len(scenarios)} scenarios.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
