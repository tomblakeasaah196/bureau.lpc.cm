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


def scenario_stock_entry_on_reception():
    """
    Perpetual inventory (VIDEO_QA_FINDINGS 3.1, owner default #3). Reception of
    50 x OPUR 20L at 1 850 posts the purchase AND moves it into stock:

        Dr 601  Achats de marchandises            92 500
        Cr 401  Fournisseur                       92 500
        Dr 311  Stocks Eau                        92 500
        Cr 6031 Variations des stocks             92 500

    THE DEFECT: only the first entry existed. Nothing ever debited 31x, so each
    delivery's Dr 6031 / Cr 31x drove the stock account into credit and the
    cost of the bottles was counted twice (601 at purchase, 6031 at delivery).
    """
    value = fcfa(Decimal("50") * Decimal("1850"))
    e = Entry("Achats · réception et entrée en stock (50 OPUR 20L)")
    e.debit("601", value).credit("401", value).debit("311", value).credit("6031", value)

    check_balanced(e)
    check("stock entry · 31x debited at cost", e.net("311"), Decimal("92500"))
    check("stock entry · 6031 credited the same", -e.net("6031"), Decimal("92500"))
    return e


def scenario_perpetual_cycle():
    """
    Buy 100 bottles at 1 850, deliver 60 at CUMP 1 850. Perpetual inventory
    must leave 31x = the 40 bottles still on hand, and 601 + 6031 = the cost of
    the 60 bottles sold, once and only once.
    """
    bought = fcfa(Decimal("100") * Decimal("1850"))
    sold = fcfa(Decimal("60") * Decimal("1850"))

    e = Entry("Cycle · achat 100, livraison 60")
    e.debit("601", bought).credit("401", bought)          # reception, purchase
    e.debit("311", bought).credit("6031", bought)         # reception, into stock
    e.debit("6031", sold).credit("311", sold)             # delivery, at CUMP

    check_balanced(e)
    check("cycle · 31x holds the 40 bottles left", e.net("311"), fcfa(Decimal("40") * Decimal("1850")))
    check("cycle · 601 + 6031 is the cost of the 60 sold", e.net("601") + e.net("6031"), sold)
    # What the books said before the fix: 601 bought + 6031 sold, 31x in credit.
    check("cycle · old path counted the sold bottles twice",
          (bought + sold) - (e.net("601") + e.net("6031")), bought)
    return e


def scenario_stock_adjustments():
    """
    Perpetual stock outside a purchase or a sale (VIDEO_QA_FINDINGS 3.1, the
    copy on 18/11/2026): 260 OPUR 20L at CUMP 2 013,46 (523 500 in 311).

        avarie, 3 bottles          Dr 6031 / Cr 311    6 040
        inventory, 2 missing       Dr 6031 / Cr 311    4 027
        a BL of 10 at CUMP         Dr 6031 / Cr 311   20 135   (delivery, as before)
        the client refuses 4       Dr 311  / Cr 6031   8 054   (at the cost they left at)
        inventory, 1 surplus       Dr 311  / Cr 6031   2 013

    THE DEFECT: none of the four adjustments posted anything, so 311 stayed
    above the bottles on hand. After them, 311 must equal qty x CUMP.
    """
    cump = Decimal("2013.46")
    qty = 260
    e = Entry("Stock · avarie, inventaire, refus client")
    e.debit("311", Decimal("523500")).credit("6031", Decimal("523500"))   # receptions

    def out(n):
        nonlocal qty
        v = fcfa(Decimal(n) * cump); qty -= n
        e.debit("6031", v).credit("311", v)

    def back(n):
        nonlocal qty
        v = fcfa(Decimal(n) * cump); qty += n
        e.debit("311", v).credit("6031", v)

    out(3)      # avarie
    out(2)      # manquant à l'inventaire
    out(10)     # livraison
    back(4)     # refusé par le client
    back(1)     # surplus à l'inventaire

    check_balanced(e)
    check("stock · 260 - 3 - 2 - 10 + 4 + 1 bottles left", qty, 250)
    check("stock · 311 holds the bottles left at CUMP (to the franc per line)",
          abs(e.net("311") - fcfa(Decimal(qty) * cump)) <= 3, True)
    check("stock · avarie entry is 6 040", fcfa(Decimal(3) * cump), Decimal("6040"))
    check("stock · 6031 nets the cost of what left for good (3 + 2 + 6 - 1 = 10 bottles)",
          abs(e.net("6031") + Decimal("523500") - fcfa(Decimal(10) * cump)) <= 3, True)
    return e


def scenario_supplier_remise():
    """
    A remise HOREB SARL grants on an order (no ristourne ladder, VIDEO_QA_FINDINGS
    2.4): 100 x 2 275 = 227 500, remise 7 500, so 220 000 owed. Received in two
    deliveries, 60 then 40: each is booked net, in proportion, and the two add
    up to what is owed. No 4098 line: it is not a ristourne used.
    """
    gross = Decimal("227500")
    remise = Decimal("7500")
    factor = 1 - remise / gross

    first = fcfa(Decimal("60") * Decimal("2275") * factor)
    second = fcfa(Decimal("40") * Decimal("2275") * factor)

    e = Entry("Achats · remise fournisseur, deux réceptions")
    e.debit("601", first).credit("401", first)
    e.debit("601", second).credit("401", second)

    check_balanced(e)
    check("supplier remise · 401 owes the order net of the remise", -e.net("401"), gross - remise)
    check("supplier remise · no ristourne drawn", e.net("4098"), Decimal("0"))
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


def scenario_withholding_client_cycle():
    """
    PROMETAL (VIDEO_QA_FINDINGS 8.1, 8.2, 8.11): 200 OPUR 20L at 2 275 F, no TVA
    on water, AIR 2,2 % withheld; then 4 bottles credited after delivery; then
    the transfer net of AIR.

        invoice   Dr 411035 444 990   Dr 4424 10 010   Cr 7011 455 000
        avoir     Dr 7019 9 100                        Cr 4424 200   Cr 411035 8 900
        payment   Dr 5211 436 090                      Cr 411035 436 090

    THE DEFECT: the payment booked the AIR again (Dr 4424 / Cr 411 the full
    455 000): 4424 held 20 020 and the client's account ended 10 010 in credit.
    The invoice already split the AIR; the payment clears what was transferred.
    """
    ht = Decimal("455000")
    air = fcfa(ht * Decimal("0.022"))
    av_ht = Decimal("9100")
    av_air = fcfa(av_ht * Decimal("0.022"))
    transfer = (ht - av_ht) - (air - av_air)

    e = Entry("Ventes · client qui retient l'AIR : facture, avoir, virement")
    e.debit("411035", ht - air).debit("4424", air).credit("7011", ht)
    e.debit("7019", av_ht).credit("4424", av_air).credit("411035", av_ht - av_air)
    e.debit("5211", transfer).credit("411035", transfer)

    check_balanced(e)
    check("withholding cycle · the client's own account ends at zero", e.net("411035"), Decimal("0"))
    check("withholding cycle · 4424 holds the AIR withheld, once", e.net("4424"), air - av_air)
    check("withholding cycle · the transfer is the net after avoir and AIR", transfer, Decimal("436090"))
    check("withholding cycle · the avoir shows on 7019, 7011 untouched", (e.net("7019"), -e.net("7011")), (av_ht, ht))
    check("withholding cycle · nothing on the generic 411", "411" in e.accounts(), False)
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
# DÉCLARATIONS: a payment clears the accounts the month booked (Comptabilité 3)
# ===========================================================================
def scenario_dipe_payment():
    """
    November 2026 on the filming copy (VIDEO_QA_FINDINGS 13.1, 13.2): five
    payslips credited 432 with 57 246 F (IRPP, CAC, CFC, CRTV, TDL, the employer
    CFC). The DIPE declares 63 546 F: the same plus the FNE (1 % of 630 000), which
    payslips calculated before migration 136 never accrued.

        Dr 432   57 246   what the payslips booked
        Dr 6412   6 300   the FNE not accrued: a charge of the month it is paid
        Cr 5211  63 546   CCA Bank

    THE DEFECT: the payment debited « 447 » (nothing credits it) through a query on
    columns that do not exist; the error was swallowed and the declaration turned
    « payée » with no entry and no money out of the bank.
    """
    booked_432 = Decimal("57246")
    gross = Decimal("630000")
    fne = fcfa(gross * Decimal("0.01"))
    declared = booked_432 + fne

    e = Entry("Déclarations · paiement de la DIPE")
    e.debit("432", booked_432).debit("6412", declared - booked_432).credit("5211", declared)

    check_balanced(e)
    check("DIPE · declared amount", declared, Decimal("63546"))
    check("DIPE · 432 cleared by what the payslips booked", e.net("432"), booked_432)
    check("DIPE · the un-accrued FNE is a charge", e.net("6412"), fne)
    check("DIPE · nothing on 447 or 431", ("447" in e.accounts(), "431" in e.accounts()), (False, False))
    return e


def scenario_cnps_payment():
    """
    CNPS November 2026: 4,2 % employee + 16,2 % employer on 630 000 = 128 520 F,
    exactly what the payslips credited to 433.

        Dr 433   128 520
        Cr 5211  128 520
    """
    base = Decimal("630000")
    employee = fcfa(base * Decimal("0.042"))
    employer = fcfa(base * Decimal("0.162"))
    total = employee + employer

    e = Entry("Déclarations · paiement de la CNPS")
    e.debit("433", total).credit("5211", total)

    check_balanced(e)
    check("CNPS · total is the payslips' own", total, Decimal("128520"))
    # The breakdown on the declaration adds up to it: PVID 4,2 + 4,2, PF 7 %, and
    # the accident du travail is the rest of the payroll's employer rate (5 %).
    pvid_pat = fcfa(base * Decimal("0.042"))
    pf = fcfa(base * Decimal("0.07"))
    at = employer - pvid_pat - pf
    check("CNPS · AT is the rest of the payroll's employer part", at, Decimal("31500"))
    check("CNPS · breakdown adds up", employee + pvid_pat + pf + at, total)
    return e


def scenario_air_settled_by_attestation():
    """
    AIR November 2026: turnover 455 000 less the 9 100 avoir = 445 900; 2,2 % =
    9 810, exactly what PROMETAL withheld and attested. Nothing leaves the bank:

        Dr 4471  9 810   acompte d'IS (the year's minimum de perception)
        Cr 4424  9 810   the AIR the client withheld, now used

    It used to compute on 455 000 (credit notes ignored): 200 F « à payer ».
    """
    turnover = Decimal("455000") - Decimal("9100")
    gross = fcfa(turnover * Decimal("0.022"))
    withheld = Decimal("9810")
    net = max(Decimal(0), gross - withheld)

    e = Entry("Déclarations · AIR couverte par l'attestation")
    e.debit("4471", gross).credit("4424", min(gross, withheld))

    check_balanced(e)
    check("AIR · gross on turnover net of credit notes", gross, Decimal("9810"))
    check("AIR · nothing to pay", net, Decimal("0"))
    check("AIR · 4424 emptied", e.net("4424"), -withheld)
    return e


def scenario_tva_month():
    """
    TVA when LPC is liable (« LPC assujetti à la TVA »): collectée on 4431, the
    reception's recoverable TVA on 4452, last month's credit on 4449.

        collectée 192 500, déductible 120 000, crédit reporté 30 000
        Dr 4431 192 500 / Cr 4452 120 000 / Cr 4449 30 000 / Cr 5211 42 500

    A credit month (déductible > collectée) nets to zero and carries the rest:
        collectée 50 000, déductible 80 000
        Dr 4431 50 000 / Dr 4449 30 000 / Cr 4452 80 000   (no money)

    THE DEFECT: the deductible part read a `supplier_invoices` table that does not
    exist (always 0), there was no carry-over, and the payment debited 4432.
    """
    col, ded, rep = Decimal("192500"), Decimal("120000"), Decimal("30000")
    net = max(Decimal(0), col - ded - rep)
    e = Entry("Déclarations · TVA du mois payée")
    e.debit("4431", col).credit("4452", ded).credit("4449", rep).credit("5211", net)
    check_balanced(e)
    check("TVA · net to pay", net, Decimal("42500"))
    check("TVA · 4431 cleared, never 4432", (e.net("4431"), "4432" in e.accounts()), (col, False))

    col2, ded2 = Decimal("50000"), Decimal("80000")
    carry = max(Decimal(0), ded2 - col2)
    c = Entry("Déclarations · mois de crédit de TVA")
    c.debit("4431", col2).debit("4449", carry).credit("4452", ded2)
    check_balanced(c)
    check("TVA credit month · carried to 4449", c.net("4449"), Decimal("30000"))
    check("TVA credit month · no treasury line", any(a.startswith("5") for a in c.accounts()), False)
    return e


def scenario_year_opening():
    """
    Clôture définitive (13.5): the À-nouveaux of 01/01 N+1 carry every class 1 to
    5 balance and the result on 131 / 139, and balance by construction because the
    year's entries do. Copy A, closing 2026 as a test (one IS provision on 89):

        result = −1 331 033 − 9 810 = −1 340 843  → Dr 139
    The old closing summed every entry ever (a second year doubled the first),
    left class 8 out of the result and could post the result twice (Dr 12 /
    Cr 131 at the « Affectation » phase, then again in the À-nouveaux).
    """
    balances = {  # class 1 to 5 at 31/12/2026 (debit +, credit −)
        "311": Decimal("110741"), "4098": Decimal("5864"), "4424": Decimal("9810"),
        "4452": Decimal("1433"), "4473": Decimal("149"), "5211": Decimal("372544"), "5521": Decimal("704000"),
        "401002": Decimal("-227500"), "422": Decimal("-1016688"), "432": Decimal("-54746"),
        "433": Decimal("-236640"), "471": Decimal("-1000000"), "4472": Decimal("-9810"),
    }
    # The result (credit − debit on classes 6 to 8) equals the net debit balance of
    # classes 1 to 5, since every entry balances.
    result = sum(balances.values())
    e = Entry("Clôture · À-nouveaux du 01/01/2027")
    for acc, v in balances.items():
        if v > 0: e.debit(acc, v)
        else: e.credit(acc, -v)
    if result >= 0: e.credit("131", result)
    else: e.debit("139", -result)
    check_balanced(e)
    check("À-nouveaux · the year's result (class 8 included)", result, Decimal("-1340843"))
    check("À-nouveaux · a loss goes to 139", e.net("139"), Decimal("1340843"))
    check("À-nouveaux · nothing on 12x", any(a.startswith("12") for a in e.accounts()), False)
    return e


# ===========================================================================
def main():
    scenarios = [
        scenario_goods_receipt,
        scenario_goods_receipt_exonerated,
        scenario_rebate_accrual,
        scenario_stock_entry_on_reception,
        scenario_perpetual_cycle,
        scenario_stock_adjustments,
        scenario_supplier_remise,
        scenario_purchase_line_remise,
        scenario_invoice_with_remise,
        scenario_invoice_full_fiscal_stack,
        scenario_payment_with_overpayment,
        scenario_credit_note,
        scenario_withholding_client_cycle,
        scenario_cogs_on_dispatch,
        scenario_full_cycle,
        scenario_dipe_payment,
        scenario_cnps_payment,
        scenario_air_settled_by_attestation,
        scenario_tva_month,
        scenario_year_opening,
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
