#!/usr/bin/env python3
"""
scripts/tools/normalize_toolbar_controls.py
------------------------------------------------------------------------------
Second migration pass: the controls *inside* the shared .lpc-toolbar were still
each styled with their own utility strings (six different renderings of what is
the same "labelled year/period filter"). This moves them onto the shared
.lpc-field / .lpc-control / .lpc-toolbar-lead house style.

Deliberately does NOT touch any element whose visibility the page JS toggles
(e.g. #custom-date-ui, which is shown/hidden by handlePeriodChange()) — those
keep their Tailwind display utilities so the cascade stays predictable.

Asserted like the first pass: aborts without writing if anything is off.

    python3 scripts/tools/normalize_toolbar_controls.py --check
"""
import sys
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
CHECK = "--check" in sys.argv

FIELD_OLD = '<div class="bg-gray-100 p-1.5 rounded-lg border border-gray-200 flex items-center shadow-inner">'
SEL = 'class="bg-white border border-gray-300 rounded text-sm font-black text-{t}-dark px-3 py-1 outline-none focus:ring-2 focus:ring-{t}-highlight"'

EDITS = {
    "modules/accounting/budgets.php": [
        (FIELD_OLD, '<div class="lpc-field">'),
        ('<label class="text-[10px] font-black text-gray-500 uppercase px-2">Exercice:</label>',
         '<label for="global_year_filter">Exercice:</label>'),
        (' ' + SEL.format(t="finance"), ''),
    ],
    "modules/accounting/ledger.php": [
        (FIELD_OLD, '<div class="lpc-field">'),
        ('<label class="text-[10px] font-black text-gray-500 uppercase px-2">Exercice:</label>',
         '<label for="global_year_filter">Exercice:</label>'),
        (' ' + SEL.format(t="rev"), ''),
    ],
    "modules/analytics/reports.php": [
        (FIELD_OLD, '<div class="lpc-field">'),
        ('<label class="text-[10px] font-black text-gray-500 uppercase px-2">Période:</label>',
         '<label for="timeframe_filter">Période:</label>'),
        (' ' + SEL.format(t="dash"), ''),
        # Context label belongs on the left, via the shared lead slot rather
        # than an ad-hoc `mr-auto md:mr-0` (which cancelled itself out at md+).
        ('<p class="text-xs text-gray-400 font-bold uppercase tracking-widest mr-auto md:mr-0">',
         '<p class="lpc-toolbar-lead text-xs text-gray-400 font-bold uppercase tracking-widest">'),
    ],
    # The three dashboards: the period <select> sat bare in the bar with its own
    # bespoke utility string. It keeps `hidden md:block` (JS + responsive rely on
    # it); only the visual styling moves to .lpc-control.
    **{
        p: [
            ('class="hidden md:block bg-white border border-gray-200 text-gray-700 text-sm rounded-lg '
             'focus:ring-2 focus:ring-lpc-light focus:border-lpc-light p-2.5 font-medium shadow-sm '
             'cursor-pointer transition-shadow' + extra + '"',
             'class="hidden md:block lpc-control"'),
        ]
        for p, extra in [
            ("modules/dashboard/views/md_dashboard.php", " hover:shadow-md"),
            ("modules/dashboard/views/finance_dashboard.php", ""),
            ("modules/dashboard/views/ops_dashboard.php", ""),
        ]
    },
    "modules/inventory/fiche_stock.php": [
        ('<div class="flex items-center gap-3 bg-gray-50 px-4 py-2 rounded-xl border border-gray-200">',
         '<div class="lpc-field">'),
        ('<span class="text-xs font-bold" id="lbl-phys">Unités (Qté)</span>',
         '<span class="lpc-field-label" id="lbl-phys">Unités (Qté)</span>'),
        ('<span class="text-xs font-bold text-gray-400" id="lbl-fin">Valeur (FCFA)</span>',
         '<span class="lpc-field-label" id="lbl-fin">Valeur (FCFA)</span>'),
    ],
}

errors, changes = [], {}
for page, edits in EDITS.items():
    path = ROOT / page
    src = original = path.read_text(encoding="utf-8")
    for old, new in edits:
        n = src.count(old)
        if n != 1:
            errors.append(f"{page}: found {n}x (want 1) -> {old[:80]!r}")
            continue
        src = src.replace(old, new, 1)
    if src != original:
        changes[page] = src

if errors:
    print("ABORTED — nothing written:\n  " + "\n  ".join(errors))
    sys.exit(1)

print(f"{len(changes)} pages {'would change' if CHECK else 'written'}")
for page in changes:
    if not CHECK:
        (ROOT / page).write_text(changes[page], encoding="utf-8")
    print("  ok   " + page)
