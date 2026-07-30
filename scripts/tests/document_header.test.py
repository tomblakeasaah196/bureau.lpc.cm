#!/usr/bin/env python3
"""
scripts/tests/document_header.test.py

Guards the shared letterhead and the quotation's structure.

WHAT WENT WRONG BEFORE
  Each PDF template carried its own letterhead and they disagreed. The invoice
  printed a hardcoded "NIU: M123456789012A | RC: DLA/2026/B/1234" — both
  placeholders — to real customers, while DEV-2607-841B showed its legal
  identity only in a 6 pt page footer, split across two different pages, and
  never showed a NIU at all. Two documents from the same company that a reader
  could not have matched.

  The quote also had no tax treatment anywhere: one figure, "MONTANT TOTAL
  MENSUEL ESTIMÉ (FCFA) : 100 000", with nothing saying HT or TTC. And it was
  a bitmap — pdftotext extracted 4 characters from 4 pages.

Run:  python3 scripts/tests/document_header.test.py
"""
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
HDR = (ROOT / 'includes/pdf_templates/document_header.php').read_text(encoding='utf-8')
PDF = (ROOT / 'includes/functions/document_pdf.php').read_text(encoding='utf-8')
TPL = (ROOT / 'includes/functions/proposal_template.php').read_text(encoding='utf-8')
QJS = (ROOT / 'assets/js/modules/documents-quote.js').read_text(encoding='utf-8')

failures = []


def check(label, ok, detail=''):
    print(f"  {'PASS' if ok else 'FAIL'}  {label}{'' if ok else f' — {detail}'}")
    if not ok:
        failures.append(label)


def decomment(src):
    """Drop PHP comments.

    Needed because these files document the very strings the checks below ban —
    the header's docblock quotes the old hardcoded "NIU: M123456789012A" and
    explains why margin:auto is not used. Scanning raw source matches the
    explanation and reports the bug it prevents.
    """
    src = re.sub(r'/\*.*?\*/', '', src, flags=re.S)
    return re.sub(r'^\s*//.*$', '', src, flags=re.M)


def body(fn_name):
    """The source of one PHP function, up to the next top-level function."""
    i = PDF.index(f'function {fn_name}')
    nxt = PDF.find('\nfunction ', i + 1)
    return PDF[i:nxt if nxt != -1 else len(PDF)]


invoice = body('lpc_render_invoice_pdf_html')
quote = body('lpc_render_quote_pdf_html')

print('─── one header, both documents ───')

check('the shared partial exists', 'function lpc_document_header' in HDR)
check('invoice calls it', 'lpc_document_header(' in invoice)

# Sprint 10: the quote DELIBERATELY stopped calling the shared partial. The
# approved redesign needs a one-line reference/date/validity bar directly
# under the logo and RCCM/NIU in the footer only — neither is expressible
# through lpc_document_header()'s API (a right-aligned meta CARD, identity
# folded into the body). Rather than bend the shared partial to fit one
# caller, the quote now has its own header/footer markup. The invariant this
# section actually guards — every legal-identity fact comes from
# CompanyProfile, never a hardcoded literal — still has to hold, just checked
# positively (quote DOES read $lh) instead of via the call it no longer makes.
check('quote no longer calls the shared partial (Sprint 10 redesign, by design)',
      'lpc_document_header(' not in decomment(quote),
      'decomment() matters here — the migration comment explaining the removal names the old call')
check('quote prints its own title instead',
      '>Devis<' in quote or "'>Devis'" in quote)
check('quote\'s own header still sources the logo/name from CompanyProfile, not a literal',
      "$lh['name']" in quote and '$lhLogo' in quote)

def passes_title(src, title):
    """Tolerant of the alignment padding in an aligned => array."""
    return re.search(r"'title'\s*=>\s*'" + title + r"'", src) is not None


check('invoice title still goes through the shared partial',
      passes_title(invoice, 'Facture'))

# Invoice must not build its own letterhead — it still goes entirely through
# the shared partial. Quote is the opposite now: its custom footer carries
# the RCCM/NIU/address block itself (see the module docblock for why it moved
# out of the header), so it is EXPECTED to reference $lh directly — the check
# for quote is therefore the positive form: those facts must still come from
# $lh, never be re-typed.
check('invoice has no private letterhead',
      "$lh['mentions']" not in invoice and "$lh['address']" not in invoice,
      'invoice is assembling its own identity block again')
check('quote\'s footer sources RCCM/NIU/address from $lh, not a literal',
      "$lh['mentions']" in quote and "$lh['address']" in quote,
      'quote is hardcoding identity facts instead of reading CompanyProfile')

check('identity comes from CompanyProfile',
      'CompanyProfile::letterhead' in HDR)
check('no hardcoded NIU or RC anywhere in the templates',
      not re.search(r'(NIU|RC)\s*[:=]\s*[\'"]?[MP]\d{6,}', decomment(PDF) + decomment(HDR)),
      'a placeholder identifier is back in the source')
check('RCCM/NIU still appear exactly once on the quote (footer only, not also in the header)',
      len(re.findall(r"\$lh\['mentions'\]", quote)) == 1,
      'legal mentions are printed more than once — the header/footer duplication this redesign removed')

print('\n─── the header band is one table row ───')
# Logo and meta must be two cells of ONE row; floats would let the taller hang.
check('logo and meta are sibling cells',
      re.search(r'<tr>\s*<td class="lpc-hd-logo">[\s\S]*?<td class="lpc-hd-meta">', HDR) is not None)
check('meta box right-aligned with a spacer cell, not margin:auto',
      'margin-left: auto' not in decomment(HDR),
      'dompdf does not honour margin:auto on a nested table')

print('\n─── quotation: price carries its tax treatment ───')

check('quote renders the fiscal ladder', "$p('tbl_subtotal')" in quote and "$p('tbl_tva')" in quote)
check('exemption basis printed when TVA is nil',
      "tva_exemption" in quote and "tbl_exempt_default" in TPL)
check('the ladder mirrors the invoice keys',
      all(k in quote for k in ("$t['subtotal']", "$t['tax']", "$t['grand_total']")))
check('total in words', "$t['words']" in quote and 'tbl_words' in TPL)

print('\n─── quotation: contract essentials ───')

check('validity is a date, not a day count',
      'valid_until' in quote and 'meta_valid_until' in TPL)
check('revision is tracked and printed', "$doc['revision']" in quote)
check('prospect NIU / RCCM printed when known',
      "$client['niu']" in quote and "$client['rccm']" in quote)
check('consigne schedule referenced', 'sec_consigne_title' in TPL and "$consigne" in quote)
check('delivery zone and transfer of risk present',
      'sec_delivery_risk' in TPL and "$p('sec_delivery_risk')" in quote)
check('annexes referenced', 'sec_annex_title' in TPL and "$annexes" in quote)

# The one-pager prints neither the six articles nor the consigne and annex
# tables, so the clause that incorporates them by reference is what keeps a
# signed one-pager a whole contract. Losing it would be silent and expensive.
check('terms incorporated by reference',
      "$p('onepager_terms_ref')" in quote and 'onepager_terms_ref' in TPL)

# Copy must stay editable in the Proposal Studio, not be re-hardcoded.
hardcoded = re.findall(r'>\s*(Exonéré de TVA|Offre valable|Barème des Consignes)', quote)
check('new copy goes through lpc_proposal_text()', not hardcoded, str(hardcoded))

print('\n─── the two devis deliverables stay distinct ───')

# The regression this section exists to catch: one commit pointed #btn-download
# at ?pdf=1, so both buttons produced the same file and the WYSIWYG export was
# gone. Neither button may serve the other's document.
#
#   #btn-download -> html2canvas over the four .a4-page blocks (raster, WYSIWYG)
#   #btn-offer    -> ?pdf=1, dompdf's one-page offre commerciale (vector text)
QUOTE_PHP = (ROOT / 'public/documents/quote.php').read_text(encoding='utf-8')

check('quote dispatches to its own template', "if ($type === 'quote')" in PDF)
check('the HTML proposal is still four pages', QUOTE_PHP.count('<div class="a4-page">') == 4)
check('btn-download captures the .a4-page blocks',
      "querySelectorAll('.a4-page')" in QJS and 'html2canvas(' in QJS)
# decomment(): the comment above generatePDF() names ?pdf=1 to explain what the
# other button does and what the regression was. Both are worth keeping in the
# source, so scan code only. Same reason as decomment()'s docstring.
check('btn-download does not fetch the one-pager',
      'pdf=1' not in decomment(QJS),
      'the capture button is serving dompdf again — that is the regression')
check('btn-offer links to ?pdf=1',
      'pdf=1' in QUOTE_PHP and 'id="btn-offer"' in QUOTE_PHP)

print('\n─── the one-pager is one page ───')

# A page break here is a bug, not a layout choice: see the height budget in the
# docblock of lpc_render_quote_pdf_html(). decomment() first, because that
# docblock and the CSS comment both mention the property they forbid.
quote_code = decomment(quote)
check('no page break anywhere in the template',
      'page-break-before' not in quote_code and 'page-break-after' not in quote_code,
      'the one-pager has grown a second page again')
check('the priced rows are clamped',
      '$max_rows' in quote_code and '$spill' in quote_code,
      'an unbounded item loop will silently overflow to page 2')
check('the clamped tail is summed, not dropped',
      '$spill_total' in quote_code and "$p('onepager_more_lines')" in quote_code,
      'a truncated quotation whose lines do not add up to its total')
check('nothing left of the 4-page replica',
      not any(k in quote_code for k in ("$p('cover_title')", "$p('sec1_p1')",
                                        "$p('sec2_intro')", 'class="band"')),
      'the cover and profile prose are back in the PDF')
# cover_summary_* belonged to the 4-page dompdf replica's "En bref" box and is
# removed by migration 046. A key nobody reads is worse than no key: an admin
# edits it in the Studio and the document does not change.
check('no orphaned template keys', 'cover_summary' not in decomment(TPL),
      'cover_summary_* is defined but rendered nowhere')

print('\n─── internal signature + QR verification (Sprint 10) ───')

SIG_CLASS = (ROOT / 'includes/classes/DocumentSignature.php').read_text(encoding='utf-8')
SIG_CTRL  = (ROOT / 'api/v1/proposal_signature_controller.php').read_text(encoding='utf-8')
VERIFY    = (ROOT / 'public/verify.php').read_text(encoding='utf-8')
HTACCESS  = (ROOT / '.htaccess').read_text(encoding='utf-8')

check('quote looks up an active signature for the CURRENT figures',
      'DocumentSignature::getActive(' in decomment(quote))
check('quote never trusts a stale hash — getActive takes $doc, not just an id',
      re.search(r"DocumentSignature::getActive\(\s*'quote'\s*,[^,]+,\s*\$doc\s*\)", decomment(quote)) is not None)
check('quote renders a QR (or its text fallback) only when actually signed',
      'lpc_qr_or_fallback(' in quote)
check('the signer name/role printed are the ones stored on the signature row, not request input',
      "$signature['signer_name']" in quote and "$signature['signer_role']" in quote)

check('signing requires its own permission, not just proposal-view/create',
      "Rbac::requirePermission('crm.proposals.sign')" in SIG_CTRL)
check('sign/revoke are CSRF-protected',
      SIG_CTRL.count('Csrf::requireValid()') >= 2)
check('the signer identity comes from the session-resolved profile, never $_POST',
      'UserProfile::displayName()' in SIG_CLASS and 'UserProfile::roleLabel()' in SIG_CLASS,
      'a caller must not be able to sign as an arbitrary name by typing it into a form')
check('a signature is revoked, never deleted',
      'DELETE FROM document_signatures' not in SIG_CLASS and 'revoked_at' in SIG_CLASS)

check('the public verify page keys off verify_token only, never the document access token',
      'verifyByToken(' in VERIFY)
check('verify.php distinguishes revoked from unknown rather than 404ing both the same way',
      "'revoked'" in VERIFY and "'unknown'" in VERIFY)
check('the pretty /verify/{token} route is wired in .htaccess',
      re.search(r'\^verify/.*public/verify\.php', HTACCESS) is not None)

print('\nAll assertions passed.' if not failures else f'\n{len(failures)} assertion(s) FAILED.')
sys.exit(0 if not failures else 1)
