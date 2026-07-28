#!/usr/bin/env python3
"""
scripts/tools/migrate_shell_toolbar.py
------------------------------------------------------------------------------
One-shot migration: move all 24 wired module pages off their bespoke, per-page
"leftover controls" bars and onto the shared shell components defined in
assets/css/lpc-shell.css (.lpc-toolbar / .lpc-tabs / .lpc-page / body.lpc-body).

Every replacement is asserted: if a pattern is not found exactly once, the
script aborts without writing anything. Run with --check to dry-run.

    python3 scripts/tools/migrate_shell_toolbar.py --check
    python3 scripts/tools/migrate_shell_toolbar.py

Kept for the record; it is not part of the deploy pipeline.
"""
import re
import sys
import pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
CHECK = "--check" in sys.argv

PAGES = [
    "modules/accounting/tax_declarations.php",
    "modules/accounting/invoices.php",
    "modules/inventory/stock.php",
    "modules/crm/clients.php",
    "modules/dashboard/views/md_dashboard.php",
    "modules/dashboard/views/finance_dashboard.php",
    "modules/dashboard/views/ops_dashboard.php",
    "modules/sales/orders.php",
    "modules/accounting/budgets.php",
    "modules/accounting/cashflow.php",
    "modules/accounting/fixed_assets.php",
    "modules/accounting/journal_entry.php",
    "modules/accounting/ledger.php",
    "modules/accounting/reports.php",
    "modules/admin/error_monitor.php",
    "modules/admin/roles.php",
    "modules/admin/master_data.php",
    "modules/analytics/reports.php",
    "modules/fleet/vehicles.php",
    "modules/hr/payroll_finance.php",
    "modules/inventory/fiche_stock.php",
    "modules/inventory/procurement.php",
    "modules/operations/empties_collection.php",
    "modules/settings/index.php",
]

BODY = 'class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased"'

# The canonical containers.
TOOLBAR_OPEN = '<div class="lpc-toolbar">'
TABS = '<nav class="lpc-tabs">'

# ---------------------------------------------------------------------------
# 1. <body> — every page onto the same document-flow shell body.
# ---------------------------------------------------------------------------
BODY_EDITS = {p: [(re.compile(r'<body class="[^"]*">'), f"<body {BODY}>")] for p in PAGES}

# ---------------------------------------------------------------------------
# 2. Per-page container rewrites. (old_literal, new_literal, expected_count)
# ---------------------------------------------------------------------------
E = {}


def add(page, old, new, count=1):
    E.setdefault(page, []).append((old, new, count))


# Regex variant, for blocks that contain whitespace-only lines whose exact
# trailing spaces we don't want to hard-code.
RX = {}


def addrx(page, pattern, new, count=1):
    RX.setdefault(page, []).append((re.compile(pattern, re.S), new, count))


# --- tab bars -------------------------------------------------------------
NAV_A = '<nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto shadow-sm z-10">'
NAV_B = '<nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 shadow-sm">'
NAV_C = '<nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0">'

for p in [
    "modules/accounting/invoices.php",
    "modules/inventory/stock.php",
    "modules/accounting/cashflow.php",
    "modules/accounting/fixed_assets.php",
    "modules/accounting/journal_entry.php",
    "modules/accounting/ledger.php",
    "modules/fleet/vehicles.php",
    "modules/hr/payroll_finance.php",
]:
    add(p, NAV_A, TABS)

add("modules/accounting/tax_declarations.php", NAV_B, TABS)
add("modules/sales/orders.php", NAV_C, TABS)

add(
    "modules/admin/master_data.php",
    '<nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto" id="mdm-tabs"></nav>',
    '<nav class="lpc-tabs" data-lpc-async id="mdm-tabs"></nav>',
)
add(
    "modules/inventory/procurement.php",
    '<nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto" id="procurement-tabs">',
    '<nav class="lpc-tabs" id="procurement-tabs">',
)
add(
    "modules/settings/index.php",
    '<nav class="bg-white border-b border-gray-200 px-8 flex items-center gap-8 shrink-0 overflow-x-auto" id="settings-tabs">',
    '<nav class="lpc-tabs" id="settings-tabs">',
)

# empties_collection: equal-width segmented tabs, and it was a <div>.
add(
    "modules/operations/empties_collection.php",
    '<div class="bg-white border-b border-gray-200 flex shrink-0 shadow-sm overflow-x-auto">',
    '<nav class="lpc-tabs lpc-tabs-fill">',
)
add(
    "modules/operations/empties_collection.php",
    """            <?php endif; ?>
        </div>

        <main""",
    """            <?php endif; ?>
        </nav>

        <main""",
)

# --- toolbars -------------------------------------------------------------
BAR_PLAIN = '<div class="bg-white border-b border-gray-200 px-8 py-2.5 shrink-0 shadow-sm flex items-center justify-end">'
for p in [
    "modules/accounting/ledger.php",
    "modules/fleet/vehicles.php",
    "modules/inventory/fiche_stock.php",
]:
    add(p, BAR_PLAIN, TOOLBAR_OPEN)

add(
    "modules/accounting/budgets.php",
    '<div class="bg-white border-b border-gray-200 px-8 py-2.5 shrink-0 shadow-sm flex items-center justify-end gap-4">',
    TOOLBAR_OPEN,
)
add(
    "modules/analytics/reports.php",
    '<div class="bg-white border-b border-gray-200 px-8 py-2.5 shrink-0 shadow-sm flex items-center justify-end gap-4">',
    TOOLBAR_OPEN,
)
add(
    "modules/accounting/reports.php",
    '<div class="bg-white border-b border-gray-200 px-8 py-2.5 shrink-0 shadow-sm flex items-center justify-end gap-6">',
    TOOLBAR_OPEN,
)

# The three dashboards — the one batch that shared a class string. Note the
# bg-lpc-surface token did not exist in the built CSS, so this bar rendered with
# no background at all: the cause of the "floating / clipped period selector".
DASH_BAR = '<div class="bg-lpc-surface shadow-sm px-6 py-2.5 border-b border-gray-100 shrink-0 flex items-center justify-end gap-3">'
for p in [
    "modules/dashboard/views/md_dashboard.php",
    "modules/dashboard/views/finance_dashboard.php",
    "modules/dashboard/views/ops_dashboard.php",
]:
    add(p, DASH_BAR, TOOLBAR_OPEN)

# clients.php — a <header> holding one button, with a dead border token.
add(
    "modules/crm/clients.php",
    """        <header class="bg-white border-b border-lpc-border px-4 md:px-6 py-3 flex justify-end items-center shrink-0 z-10">""",
    """        <div class="lpc-toolbar">""",
)
add(
    "modules/crm/clients.php",
    """            </button>
        </header>""",
    """            </button>
        </div>""",
)
# Orphan overlay: duplicates #lpc-sidebar-backdrop and calls a toggleSidebar()
# that no longer exists on this page.
add(
    "modules/crm/clients.php",
    """    <div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden backdrop-blur-sm transition-opacity" onclick="toggleSidebar()"></div>

""",
    "",
)

# invoices.php — the trim missed this one entirely: it still renders the user's
# name and role, duplicating the sidebar user card. Drop that, keep the alert.
addrx(
    "modules/accounting/invoices.php",
    r'        <header class="bg-white border-b border-gray-200 px-8 py-3 flex justify-end items-center shrink-0 z-20 shadow-sm glass-panel">\n'
    r'\s*\n'
    r'            <div class="flex items-center gap-6">\n'
    r'(?P<alert>                <button onclick="switchTab\(\'invoices_payments\'\)" id="global-cash-alert".*?\n                </button>)\n'
    r'.*?</div>\n            </div>\n        </header>',
    '        <div class="lpc-toolbar">\n\\g<alert>\n        </div>',
)

# accounting/reports.php — the nav mixed tabs (left) with export actions
# (right). Actions belong in the toolbar; the nav becomes tabs only.
add(
    "modules/accounting/reports.php",
    """        <nav class="bg-white border-b border-gray-200 px-8 flex items-center justify-between shrink-0 overflow-x-auto shadow-sm z-10">
            <div class="flex gap-8">
                <button""",
    """        <nav class="lpc-tabs">
                <button""",
)
addrx(
    "modules/accounting/reports.php",
    r'                    <i class="fas fa-tachometer-alt mr-2"></i> Vue Dirigeant \(Managerial\)\n'
    r'                </button>\n'
    r'            </div>\n'
    r'\s*\n'
    r'            <div class="flex gap-3 py-2">',
    '                    <i class="fas fa-tachometer-alt mr-2"></i> Vue Dirigeant (Managerial)\n'
    '                </button>\n'
    '        </nav>\n\n'
    '        <div class="lpc-toolbar">',
)
add(
    "modules/accounting/reports.php",
    """                <?php endif; ?>
            </div>
        </nav>

        <main""",
    """                <?php endif; ?>
        </div>

        <main""",
)

# ---------------------------------------------------------------------------
# 3. <main> — one wrapper class, one padding rhythm, one background.
# ---------------------------------------------------------------------------
MAIN = {
    "modules/accounting/tax_declarations.php": ('<main class="flex-1 overflow-y-auto p-8">', '<main role="main" id="main" class="lpc-page">'),
    "modules/accounting/invoices.php": ('class="flex-1 overflow-hidden p-8 flex flex-col relative bg-slate-50/50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/inventory/stock.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/crm/clients.php": ('class="flex-1 overflow-y-auto p-4 md:p-6"', 'class="lpc-page"'),
    "modules/dashboard/views/md_dashboard.php": ('class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8"', 'class="lpc-page"'),
    "modules/dashboard/views/finance_dashboard.php": ('class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8"', 'class="lpc-page"'),
    "modules/dashboard/views/ops_dashboard.php": ('class="flex-1 overflow-y-auto p-4 md:p-6 lg:p-8"', 'class="lpc-page"'),
    "modules/sales/orders.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col"', 'class="lpc-page lpc-page-col"'),
    "modules/accounting/budgets.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50/80"', 'class="lpc-page lpc-page-col relative"'),
    "modules/accounting/cashflow.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/accounting/fixed_assets.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/accounting/journal_entry.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/accounting/ledger.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/accounting/reports.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/admin/error_monitor.php": ('class="p-6 lg:p-8 overflow-y-auto"', 'class="lpc-page"'),
    "modules/admin/roles.php": ('class="p-6 lg:p-8 overflow-y-auto"', 'class="lpc-page"'),
    "modules/admin/master_data.php": ('class="flex-1 overflow-y-auto p-8 bg-[#F9FAFB]"', 'class="lpc-page"'),
    "modules/analytics/reports.php": ('class="flex-1 overflow-y-auto p-8 relative bg-slate-50"', 'class="lpc-page relative"'),
    "modules/fleet/vehicles.php": ('class="flex-1 overflow-hidden p-8 flex flex-col relative bg-slate-50/50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/hr/payroll_finance.php": ('class="flex-1 overflow-y-auto p-8 flex flex-col relative bg-slate-50"', 'class="lpc-page lpc-page-col relative"'),
    "modules/inventory/fiche_stock.php": ('class="p-8 space-y-6"', 'class="lpc-page space-y-6"'),
    "modules/inventory/procurement.php": ('class="flex-1 overflow-y-auto p-8 bg-[#F3F4F6] flex flex-col"', 'class="lpc-page lpc-page-col"'),
    "modules/operations/empties_collection.php": ('class="flex-1 overflow-y-auto p-4 md:p-8 relative"', 'class="lpc-page relative"'),
    "modules/settings/index.php": ('class="flex-1 overflow-y-auto p-8 bg-[#F9FAFB] flex flex-col"', 'class="lpc-page lpc-page-col"'),
}
for p, (old, new) in MAIN.items():
    add(p, old, new)

# ---------------------------------------------------------------------------
# 4. roles.php / error_monitor.php — dark theme -> the shared light theme,
#    and drop the in-page <h1> that duplicated the shared topbar title.
# ---------------------------------------------------------------------------
LIGHT_STYLE_ROLES = """<style>
.glass{background:#fff;border:1px solid #E5E7EB;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.chip{background:#F3F4F6;border:1px solid #E5E7EB;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;color:#374151}
.perm-row:hover{background:#F9FAFB}
input[type=checkbox]{accent-color:#005A2B;width:1rem;height:1rem}
.btn{padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.85rem;cursor:pointer;transition:all .15s}
.btn-primary{background:linear-gradient(90deg,#005A2B,#8CC63F);color:#fff}
.btn-primary:hover{opacity:.92}
.btn-secondary{background:#fff;color:#374151;border:1px solid #D1D5DB}
.btn-secondary:hover{background:#F9FAFB}
.btn-danger{background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA}
.btn-danger:hover{background:#FEE2E2}
.btn:disabled{opacity:.45;cursor:not-allowed}
</style>"""

add(
    "modules/admin/roles.php",
    """<style>
body{background:#051A0F;color:#eee;font-family:Inter,sans-serif;min-height:100vh}
.glass{background:rgba(255,255,255,.05);backdrop-filter:blur(16px);border:1px solid rgba(255,255,255,.1)}
.chip{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);padding:.15rem .5rem;border-radius:9999px;font-size:.7rem}
.perm-row:hover{background:rgba(255,255,255,.03)}
input[type=checkbox]{accent-color:#8CC63F;width:1rem;height:1rem}
.btn{padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.85rem;cursor:pointer;transition:all .15s}
.btn-primary{background:linear-gradient(90deg,#005A2B,#8CC63F);color:white}
.btn-primary:hover{opacity:.92}
.btn-secondary{background:rgba(255,255,255,.08);color:#eee;border:1px solid rgba(255,255,255,.15)}
.btn-secondary:hover{background:rgba(255,255,255,.14)}
.btn-danger{background:rgba(239,68,68,.15);color:#fca5a5;border:1px solid rgba(239,68,68,.3)}
.btn-danger:hover{background:rgba(239,68,68,.25)}
.btn:disabled{opacity:.4;cursor:not-allowed}
</style>""",
    LIGHT_STYLE_ROLES,
)

# roles.php: header -> shared toolbar, above <main>.
add(
    "modules/admin/roles.php",
    """<div id="lpc-shell-main">
<main role="main" id="main" class="lpc-page">

    <header class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-white"><?= __t('ui.r_les_permissions') ?></h1>
            <p class="text-white/60 text-sm mt-1">
                <?= __t('ui.cr_ez_des_r_les_personnalis_s_et_contr_l') ?>
            </p>
        </div>
        <?php if ($canCreate): ?>
        <button onclick="openCreateRoleModal()" class="btn btn-primary">
            + <?= __t('ui.nouveau_r_le') ?>
        </button>
        <?php endif; ?>
    </header>
""",
    """<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <?php if ($canCreate): ?>
        <button onclick="openCreateRoleModal()" class="btn btn-primary">
            + <?= __t('ui.nouveau_r_le') ?>
        </button>
        <?php endif; ?>
    </div>

<main role="main" id="main" class="lpc-page">
""",
)

add(
    "modules/admin/error_monitor.php",
    """<div id="lpc-shell-main">
<main role="main" id="main" class="lpc-page">

<header class="flex items-start justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-semibold text-white">
            <?= __t('ui.journal_d_erreurs') ?>
        </h1>
        <p class="text-white/60 text-sm mt-1 max-w-2xl">
            <?= __t('ui.error_monitor.intro') ?>
        </p>
    </div>
    <div class="flex items-center gap-2">
        <form method="get" class="flex items-center gap-2">
            <label class="text-xs text-white/60">
                <?= __t('ui.fen_tre') ?>
                <select name="bytes" onchange="this.form.submit()" class="ml-1 bg-white/5 border border-white/10 rounded px-2 py-1 text-xs">
                    <?php foreach ([16384, 65536, 262144, 1048576, 4194304] as $b): ?>
                        <option value="<?= $b ?>" <?= $b === $bytes ? 'selected' : '' ?>>
                            <?= $b >= 1048576 ? ($b / 1048576) . ' MB' : ($b / 1024) . ' KB' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
        <a href="?do=download&amp;bytes=<?= (int) $bytes ?>" class="btn btn-secondary">
            ⬇ <?= __t('ui.t_l_charger') ?>
        </a>
        <button onclick="location.reload()" class="btn btn-primary">
            ⟲ <?= __t('ui.actualiser') ?>
        </button>
    </div>
</header>
""",
    """<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <p class="lpc-toolbar-lead text-xs text-gray-500 max-w-2xl truncate"><?= __t('ui.error_monitor.intro') ?></p>
        <form method="get" class="lpc-field">
            <label for="em-bytes" class="lpc-field-label"><?= __t('ui.fen_tre') ?></label>
            <select id="em-bytes" name="bytes" onchange="this.form.submit()">
                <?php foreach ([16384, 65536, 262144, 1048576, 4194304] as $b): ?>
                    <option value="<?= $b ?>" <?= $b === $bytes ? 'selected' : '' ?>>
                        <?= $b >= 1048576 ? ($b / 1048576) . ' MB' : ($b / 1024) . ' KB' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <a href="?do=download&amp;bytes=<?= (int) $bytes ?>" class="btn btn-secondary">
            ⬇ <?= __t('ui.t_l_charger') ?>
        </a>
        <button onclick="location.reload()" class="btn btn-primary">
            ⟲ <?= __t('ui.actualiser') ?>
        </button>
    </div>

<main role="main" id="main" class="lpc-page">
""",
)

# Remaining dark-theme utility classes in those two files.
DARK_TO_LIGHT = [
    ("text-white/40", "text-gray-400"),
    ("text-white/50", "text-gray-400"),
    ("text-white/60", "text-gray-500"),
    ("text-white/70", "text-gray-500"),
    ("text-white/80", "text-gray-600"),
    ("hover:bg-white/10", "hover:bg-gray-100"),
    ("hover:bg-white/5", "hover:bg-gray-50"),
    ("bg-white/10", "bg-gray-100"),
    ("bg-white/5", "bg-gray-50"),
    ("border-white/15", "border-gray-200"),
    ("border-white/10", "border-gray-200"),
    ("divide-white/10", "divide-gray-200"),
    ("text-amber-200", "text-amber-700"),
    ("text-emerald-200", "text-emerald-700"),
    ("text-rose-200", "text-rose-700"),
    ("text-red-200", "text-red-700"),
    ("text-white", "text-gray-900"),
]

# ---------------------------------------------------------------------------
# Apply
# ---------------------------------------------------------------------------
errors, changes = [], {}

for page in PAGES:
    path = ROOT / page
    if not path.is_file():
        errors.append(f"{page}: MISSING")
        continue
    src = original = path.read_text(encoding="utf-8")

    for rx, new in BODY_EDITS.get(page, []):
        src, n = rx.subn(new, src, count=1)
        if n != 1:
            errors.append(f"{page}: <body> not matched")

    for old, new, count in E.get(page, []):
        found = src.count(old)
        if found != count:
            errors.append(f"{page}: expected {count}x, found {found}x -> {old[:88]!r}")
            continue
        src = src.replace(old, new, count)

    for rx, new, count in RX.get(page, []):
        src, n = rx.subn(new, src, count=count)
        if n != count:
            errors.append(f"{page}: regex expected {count}x, matched {n}x -> {rx.pattern[:88]!r}")

    if page in ("modules/admin/roles.php", "modules/admin/error_monitor.php"):
        for old, new in DARK_TO_LIGHT:
            src = src.replace(old, new)

    if src != original:
        changes[page] = src

if errors:
    print("ABORTED — nothing written:\n  " + "\n  ".join(errors))
    sys.exit(1)

print(f"{len(changes)}/{len(PAGES)} pages would change" if CHECK else f"writing {len(changes)} pages")
if not CHECK:
    for page, src in changes.items():
        (ROOT / page).write_text(src, encoding="utf-8")
for page in PAGES:
    print(("  ok   " if page in changes else "  SKIP ") + page)
