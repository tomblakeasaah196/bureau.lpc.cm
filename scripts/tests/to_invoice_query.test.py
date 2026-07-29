#!/usr/bin/env python3
"""
scripts/tests/to_invoice_query.test.py

Cross-file guards for the "BL non facturés" list, which moved from an
unbounded client-side table to a server-sorted, server-paged one.

What broke before, and what each check prevents:

  · The DATE and CLIENT headings carried `cursor-pointer` and a `fa-sort` icon
    but no click handler. They looked sortable for months and were not. So:
    every clickable heading must call toInvoiceSort with a key, and every key
    it sends must exist in the PHP whitelist — otherwise the click silently
    falls back to the default sort, which looks exactly like the old bug.

  · The sort column is interpolated into ORDER BY. It must therefore come from
    a lookup table and never from the request, so this asserts the whitelist is
    a literal map and that $_GET['sort'] is only ever used as a key into it.

  · Paging without a tiebreaker lets a row appear on two pages when many share
    a date, which in a batch-selection UI means invoicing the same BL twice.

Run:  python3 scripts/tests/to_invoice_query.test.py
"""
import pathlib
import re
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
PHP = (ROOT / 'api/v1/invoices_controller.php').read_text(encoding='utf-8')
TPL = (ROOT / 'modules/accounting/invoices.php').read_text(encoding='utf-8')
JS = (ROOT / 'assets/js/modules/accounting-invoices.js').read_text(encoding='utf-8')

failures = []


def check(label, ok, detail=''):
    print(f"  {'PASS' if ok else 'FAIL'}  {label}{'' if ok else f' — {detail}'}")
    if not ok:
        failures.append(label)


print('─── sort keys agree across template and controller ───')

m = re.search(r'\$SORTABLE\s*=\s*\[(.*?)\];', PHP, re.S)
check('$SORTABLE whitelist exists in the controller', m is not None)
allowed = set(re.findall(r"'([\w]+)'\s*=>", m.group(1))) if m else set()

# Every heading that looks clickable must actually be wired.
sortable_ths = re.findall(r'<th([^>]*\bclass="[^"]*\bth-sort\b[^"]*"[^>]*)>', TPL)
clickable_ths = re.findall(r'<th([^>]*\bcursor-pointer\b[^>]*)>', TPL)

ui_keys = set(re.findall(r'toInvoiceSort\(\s*[\'"](\w+)[\'"]\s*\)', TPL))
data_keys = set(re.findall(r'data-sort="(\w+)"', TPL))

check('headings marked sortable have a handler', len(sortable_ths) == len(ui_keys),
      f'{len(sortable_ths)} th.th-sort vs {len(ui_keys)} toInvoiceSort() calls')
check('no heading looks clickable without being wired',
      len(clickable_ths) >= len(sortable_ths) and len(sortable_ths) > 0,
      'a cursor-pointer th with no toInvoiceSort() is the original bug')
check('data-sort matches the onclick keys', ui_keys == data_keys,
      f'onclick={sorted(ui_keys)} data-sort={sorted(data_keys)}')
check('every UI sort key is server-whitelisted', ui_keys <= allowed,
      f'unknown to PHP: {sorted(ui_keys - allowed)}')

print('\n─── the ORDER BY column can never come from input ───')

check('sort key is only ever a lookup into $SORTABLE',
      re.search(r"\$SORTABLE\[\$sort_key\]\s*\?\?", PHP) is not None)
check('no raw $_GET reaches ORDER BY',
      not re.search(r'ORDER BY[^"\']*\$_GET', PHP),
      'request data is being interpolated into the sort')
check('direction is normalised to ASC/DESC',
      re.search(r"===\s*'DESC'\s*\?\s*'DESC'\s*:\s*'ASC'", PHP) is not None)

print('\n─── paging ───')

check('to_invoice is paginated', 'invoices.read.to_invoice' in PHP)
check('page size defaults to 50', re.search(r"_GET\['per_page'\]\s*\?\?\s*50", PHP) is not None)
check('COUNT is DISTINCT over the delivery join',
      'COUNT(DISTINCT d.id)' in PHP,
      'the LEFT JOIN on invoice_deliveries can otherwise inflate the total')
check('ORDER BY carries a stable tiebreaker',
      re.search(r'ORDER BY \{\$sort_col\} \{\$dir\}, d\.id ASC', PHP) is not None,
      'rows sharing a date can otherwise repeat across pages')

print('\n─── client filter ───')

check('client_id is bound, not interpolated',
      "':client_id'" in PHP and 'd.client_id = :client_id' in PHP)
check('filter dropdown is populated from the server', 'filter_clients' in PHP and 'filter_clients' in JS)
check('changing client resets a cross-client batch',
      re.search(r'toInvoiceSetClient[\s\S]{0,600}resetBatching\(\)', JS) is not None,
      'an invoice cannot span two clients')

print('\n─── selection survives paging ───')

check('bl_ref is stored on the batch entry',
      re.search(r'selectedDeliveries\.push\(\{\s*id,\s*subtotal,\s*bl_ref', JS) is not None)
check('the modal no longer looks bl_ref up in the rendered page',
      'globalData.to_invoice.deliveries.find' not in JS,
      'that lookup returns undefined once the row is on another page')

print('\nAll assertions passed.' if not failures else f'\n{len(failures)} assertion(s) FAILED.')
sys.exit(0 if not failures else 1)
