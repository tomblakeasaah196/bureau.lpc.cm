# -*- coding: utf-8 -*-
import os
"""
Generates assets/css/lpc-theme.css — the dark-mode bridge layer.

Written as a generator rather than by hand because the tinted-status-chip
section alone is ~16 hues x 4 utilities x 3 variants. Hand-typing that is how
you get one silently wrong hex that nobody finds for a year.
"""

# Tailwind v3 reference shades we need per hue.
# base  -> the -500 used as the mix pigment for tinted surfaces
# text  -> the shade substituted for dark -600..-900 text on a dark surface
# textm -> the shade substituted for -400/-500 text (already light-ish)
HUES = {
    'red':     {'base': '#ef4444', 'text': '#fca5a5', 'textm': '#f87171'},
    'rose':    {'base': '#f43f5e', 'text': '#fda4af', 'textm': '#fb7185'},
    'pink':    {'base': '#ec4899', 'text': '#f9a8d4', 'textm': '#f472b6'},
    'orange':  {'base': '#f97316', 'text': '#fdba74', 'textm': '#fb923c'},
    'amber':   {'base': '#f59e0b', 'text': '#fcd34d', 'textm': '#fbbf24'},
    'yellow':  {'base': '#eab308', 'text': '#fde047', 'textm': '#facc15'},
    'lime':    {'base': '#84cc16', 'text': '#bef264', 'textm': '#a3e635'},
    'green':   {'base': '#22c55e', 'text': '#86efac', 'textm': '#4ade80'},
    'emerald': {'base': '#10b981', 'text': '#6ee7b7', 'textm': '#34d399'},
    'teal':    {'base': '#14b8a6', 'text': '#5eead4', 'textm': '#2dd4bf'},
    'cyan':    {'base': '#06b6d4', 'text': '#67e8f9', 'textm': '#22d3ee'},
    'sky':     {'base': '#0ea5e9', 'text': '#7dd3fc', 'textm': '#38bdf8'},
    'blue':    {'base': '#3b82f6', 'text': '#93c5fd', 'textm': '#60a5fa'},
    'indigo':  {'base': '#6366f1', 'text': '#a5b4fc', 'textm': '#818cf8'},
    'violet':  {'base': '#8b5cf6', 'text': '#c4b5fd', 'textm': '#a78bfa'},
    'purple':  {'base': '#a855f7', 'text': '#d8b4fe', 'textm': '#c084fc'},
}

DARK = 'html[data-lpc-theme="dark"]'


def esc(cls):
    """Tailwind class name -> CSS selector fragment (escape the variant colon)."""
    return cls.replace(':', r'\:')


def rules(cls, decls, variant=None):
    """
    Emit one rule for a utility, honouring the three variants the codebase
    actually uses: base, :hover, and .group:hover.
    """
    sel = '.' + esc(cls)
    if variant == 'hover':
        sel += ':hover'
    if variant == 'group-hover':
        sel = '.group:hover ' + sel
    if variant == 'focus':
        sel += ':focus'
    body = ' '.join('%s: %s;' % (p, v) for p, v in decls)
    return '%s %s { %s }\n' % (DARK, sel, body)


def emit(cls, decls):
    """Base + hover: + focus: + group-hover: forms of one utility."""
    out = rules(cls, decls)
    out += rules('hover:' + cls, decls, 'hover')
    out += rules('focus:' + cls, decls, 'focus')
    out += rules('group-hover:' + cls, decls, 'group-hover')
    return out


L = []
w = L.append

w('''/* assets/css/lpc-theme.css
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — THE DARK-MODE BRIDGE.
 *
 * GENERATED FILE. Source of truth: scripts/build_theme_css.py
 * Regenerate with:  python3 scripts/build_theme_css.py
 *
 * ---------------------------------------------------------------------------
 * WHY THIS FILE EXISTS
 * ---------------------------------------------------------------------------
 * lpc-shell.css already has a complete, correct theme system: every shell
 * surface reads a CSS variable, and html[data-lpc-theme="dark"] redefines those
 * variables. The shell chrome, cards, tables, tabs and modals invert perfectly.
 *
 * The module pages do not. They were authored before the shell existed and
 * paint themselves with literal Tailwind utilities — 1,583 occurrences of
 * `bg-white`, `text-gray-900`, `border-gray-200` and friends across 43 files,
 * plus more injected at runtime by 14 module JS files. Those utilities compile
 * to fixed hex values, so they stayed light while everything around them went
 * dark. That is the "partially dark" dark mode: a dark page background with
 * white KPI cards floating on it.
 *
 * lpc-shell.css names this in section 10.2 as a KNOWN GAP, to be closed by
 * migrating pages to var(--lpc-surface) "as they are touched". Two years of
 * being touched later, 43 files still have not been. This file closes it in one
 * move instead: under html[data-lpc-theme="dark"] ONLY, the hardcoded light
 * utilities are re-pointed at the same shell variables the migration would have
 * used. A page migrated by hand tomorrow gets the identical result; it just
 * stops needing to be.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS FILE DELIBERATELY DOES NOT DO
 * ---------------------------------------------------------------------------
 *   · It emits NOTHING in light mode. Every rule is nested under the dark
 *     attribute, so light mode renders byte-identical to before this file
 *     existed. Reverting it is deleting one <link>.
 *
 *   · It does not touch `text-white`, `bg-white/5`, `border-white/10` or any
 *     other slash-opacity utility. Those are the sidebar and topbar's own
 *     palette — the green L-frame is the brand and does not invert (shell
 *     §10.2). Auditing the six chrome components confirmed they use ONLY
 *     text-white and opacity variants, which is why an unscoped remap is safe
 *     here; §9 re-asserts the chrome anyway, so a future edit that drops a
 *     `bg-white` into the sidebar cannot punch a white hole in it.
 *
 *   · It does not restyle saturated fills (bg-blue-600, bg-red-500 …). Those
 *     are buttons and badges that already carry white text and read correctly
 *     on either background.
 *
 * ---------------------------------------------------------------------------
 * VARIANT COVERAGE
 * ---------------------------------------------------------------------------
 * Every utility below is emitted in four forms — base, hover:, focus: and
 * group-hover: — because those are the three variant prefixes the codebase
 * actually pairs with colour utilities (76 hover:bg-gray-*, 28 focus:ring-*,
 * 4 group-hover:text-*). No responsive colour variants exist in the source, so
 * none are generated.
 *
 * Specificity: html[data-lpc-theme="dark"] .foo = (0,2,1) vs Tailwind's
 * (0,1,0). It wins without !important, and stays beatable by anything more
 * specific a page legitimately needs.
 * ----------------------------------------------------------------------------
 */
''')

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   1. Extra surface steps
   ---------------------------------------------------------------------------
   The shell defines --lpc-surface and --lpc-surface-sunken. Module pages use a
   third and fourth step (bg-gray-100 / bg-gray-200 as chip and well fills, and
   bg-gray-800/900 as *deliberately dark* elements on a light page). Dark mode
   needs those to keep separating from each other, so two more steps are
   derived here rather than invented per rule.

   --lpc-surface-raised is LIGHTER than --lpc-surface on purpose: an element
   that was near-black to stand out on white has to become lighter than its
   surroundings to stand out on near-black. Mapping bg-gray-900 to another dark
   value is how "dark mode" turns a contrast-y badge into an invisible one.
   ========================================================================= */
''')
w('%s {\n' % DARK)
w('    --lpc-surface-chip:   #1C2823;\n')
w('    --lpc-surface-well:   #223029;\n')
w('    --lpc-surface-raised: #2A3A32;\n')
w('    --lpc-border-strong:  #33453B;\n')
w('}\n')

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   2. Neutral surfaces
   ========================================================================= */
''')
SURFACES = [
    ('bg-white',        'var(--lpc-surface)'),
    ('bg-lpc-surface',  'var(--lpc-surface)'),
    ('bg-lpc-bg',       'var(--lpc-page-bg)'),
    ('bg-gray-50',      'var(--lpc-surface-sunken)'),
    ('bg-slate-50',     'var(--lpc-surface-sunken)'),
    ('bg-gray-100',     'var(--lpc-surface-chip)'),
    ('bg-slate-100',    'var(--lpc-surface-chip)'),
    ('bg-gray-200',     'var(--lpc-surface-well)'),
    ('bg-gray-300',     'var(--lpc-surface-well)'),
    ('bg-gray-400',     'var(--lpc-surface-raised)'),
    # Deliberately-dark-on-light elements. See §1 on why these go lighter.
    ('bg-gray-800',     'var(--lpc-surface-raised)'),
    ('bg-gray-900',     'var(--lpc-surface-raised)'),
]
for cls, val in SURFACES:
    w(emit(cls, [('background-color', val)]))

w('''
/* ---------------------------------------------------------------------------
   Inverted islands.

   bg-gray-800 / bg-gray-900 elements were near-black boxes on a white page —
   dark toolbars, code samples, contrast-y badges. §2 maps them to
   --lpc-surface-raised, which is LIGHTER than the surrounding card so they keep
   standing out. But their internal muted text was chosen for a near-black box,
   and --lpc-ink-mute on --lpc-surface-raised measures 2.97:1 — below the 3.0
   floor, and the only neutral pairing in this file that fails.

   Darkening --lpc-surface-raised to fix it would collapse the separation from
   --lpc-surface (1.39:1 down to 1.21:1) and defeat the point of the raised step,
   so the text steps up instead: inside a raised island, mute resolves to soft.
   That measures 5.25:1 — comfortably AA — and costs nothing anywhere else,
   because the rule cannot match outside one of these islands.
   ------------------------------------------------------------------------- */
''')
for host in ['bg-gray-800', 'bg-gray-900', 'bg-gray-400']:
    w('%s .%s .text-gray-300,\n' % (DARK, host))
    w('%s .%s .text-gray-400,\n' % (DARK, host))
    w('%s .%s .text-gray-500 { color: var(--lpc-ink-soft); }\n' % (DARK, host))

w('''
/* Fractional white/near-black fills used as scrims and hover washes INSIDE the
   workspace. The slash-opacity chrome utilities are excluded (see header); what
   is left are page-level washes, and on a dark surface a white wash at 50-90%
   is a flashbang. They invert to a dark wash of the same weight. */
''')
for cls, val in [
    ('bg-white/90',    'color-mix(in srgb, var(--lpc-surface) 90%, transparent)'),
    ('bg-gray-50/80',  'color-mix(in srgb, var(--lpc-surface-sunken) 80%, transparent)'),
    ('bg-gray-50/60',  'color-mix(in srgb, var(--lpc-surface-sunken) 60%, transparent)'),
    ('bg-gray-50/50',  'color-mix(in srgb, var(--lpc-surface-sunken) 50%, transparent)'),
]:
    w(emit(cls, [('background-color', val)]))

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   3. Ink
   ---------------------------------------------------------------------------
   Tailwind's neutral ramp is collapsed onto the shell's three ink levels
   rather than inverted shade-for-shade. text-gray-900 and text-gray-800 are
   both "the primary text on this card"; giving them two slightly different
   off-whites preserves an accident of authoring, not an intent.

   text-gray-400 is the largest single utility in the codebase (556 uses) and
   is almost always a label or an icon, so it lands on --lpc-ink-mute; on white
   it was already the faintest step and it stays the faintest step.
   ========================================================================= */
''')
INK = [
    ('text-gray-900',  'var(--lpc-ink)'),
    ('text-gray-800',  'var(--lpc-ink)'),
    ('text-gray-700',  'var(--lpc-ink)'),
    ('text-gray-600',  'var(--lpc-ink-soft)'),
    ('text-gray-500',  'var(--lpc-ink-soft)'),
    ('text-gray-400',  'var(--lpc-ink-mute)'),
    ('text-gray-300',  'var(--lpc-ink-mute)'),
    ('text-slate-900', 'var(--lpc-ink)'),
    ('text-slate-800', 'var(--lpc-ink)'),
    ('text-slate-700', 'var(--lpc-ink)'),
    ('text-slate-600', 'var(--lpc-ink-soft)'),
    ('text-slate-500', 'var(--lpc-ink-soft)'),
    ('text-slate-400', 'var(--lpc-ink-mute)'),
    ('text-lpc-dark',  'var(--lpc-accent-on-light)'),
]
for cls, val in INK:
    w(emit(cls, [('color', val)]))

w('''
/* text-black is only ever "maximum contrast against the page". That is
   --lpc-ink here, not #000 on #0F1512. */
''')
w(emit('text-black', [('color', 'var(--lpc-ink)')]))

w('''
/* Placeholders track the mute step so an empty input does not read as a
   filled one. */
''')
w('%s ::placeholder { color: var(--lpc-ink-mute); opacity: 1; }\n' % DARK)

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   4. Borders, dividers and rings
   ---------------------------------------------------------------------------
   Same collapse as the ink ramp: gray-100/200/300 are one hairline as far as
   intent goes. `divide-*` is included because Tailwind implements it as
   border-color on the non-first child, and 46 divide-gray-100 rows would
   otherwise stay light-grey and stripe every dark table.
   ========================================================================= */
''')
BORDERS = [
    ('border-gray-50',  'var(--lpc-border)'),
    ('border-gray-100', 'var(--lpc-border)'),
    ('border-gray-200', 'var(--lpc-border)'),
    ('border-gray-300', 'var(--lpc-border-strong)'),
    ('border-gray-400', 'var(--lpc-border-strong)'),
    ('border-gray-700', 'var(--lpc-border-strong)'),
    ('border-gray-800', 'var(--lpc-border-strong)'),
    ('border-gray-900', 'var(--lpc-border-strong)'),
    ('border-slate-200','var(--lpc-border)'),
    ('border-lpc-border','var(--lpc-border)'),
    ('border-white',    'var(--lpc-border)'),
]
for cls, val in BORDERS:
    w(emit(cls, [('border-color', val)]))

for cls, val in [
    ('divide-gray-50',  'var(--lpc-border)'),
    ('divide-gray-100', 'var(--lpc-border)'),
    ('divide-gray-200', 'var(--lpc-border)'),
]:
    w('%s .%s > :not([hidden]) ~ :not([hidden]) { border-color: %s; }\n'
      % (DARK, esc(cls), val))

w('''
/* Focus rings. ring-gray-900 / ring-gray-800 were "a dark ring on a light
   field"; on a dark field they vanish, so they take the accent instead — which
   is also what the shell's own focus treatment uses. */
''')
for cls in ['ring-gray-900', 'ring-gray-800', 'ring-gray-300', 'ring-gray-200']:
    w(emit(cls, [('--tw-ring-color', 'var(--lpc-accent-on-light)')]))

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   5. Tinted status chips
   ---------------------------------------------------------------------------
   The pattern all over the app is a -50 or -100 tinted pill with -700/-800
   text: "Action requise" in red, "Performance globale" in blue, "Objectif: 80%"
   in green. Those tints are 95%-lightness colours — on a dark card each one is
   a bright white-ish rectangle, which is the most visible half of the bug in
   the screenshot.

   Each is rebuilt rather than remapped to a neutral, because the colour is
   load-bearing: red means action required. The tint becomes the same hue mixed
   INTO the card surface at low strength, and the text moves up the ramp to the
   -300/-400 shade so it clears 4.5:1 against that tint.

   Saturated fills (-500 and darker) are untouched: they already carry white
   text and work on either background.
   ========================================================================= */
''')
for hue in sorted(HUES):
    c = HUES[hue]
    w('/* --- %s ------------------------------------------------------- */\n' % hue)
    w(emit('bg-%s-50' % hue, [
        ('background-color',
         'color-mix(in srgb, %s 14%%, var(--lpc-surface))' % c['base'])]))
    w(emit('bg-%s-100' % hue, [
        ('background-color',
         'color-mix(in srgb, %s 22%%, var(--lpc-surface))' % c['base'])]))
    w(emit('bg-%s-200' % hue, [
        ('background-color',
         'color-mix(in srgb, %s 30%%, var(--lpc-surface))' % c['base'])]))
    for shade in ('50', '100', '200', '300'):
        w(emit('border-%s-%s' % (hue, shade), [
            ('border-color',
             'color-mix(in srgb, %s 38%%, var(--lpc-border))' % c['base'])]))
    for shade in ('600', '700', '800', '900'):
        w(emit('text-%s-%s' % (hue, shade), [('color', c['text'])]))
    for shade in ('400', '500'):
        w(emit('text-%s-%s' % (hue, shade), [('color', c['textm'])]))
    w('\n')

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   6. Form controls
   ---------------------------------------------------------------------------
   @tailwindcss/forms sets background-color:#fff and border-color:#6b7280 in a
   BASE layer, on element selectors rather than classes. A page that never
   writes `bg-white` on its <input> still gets a white input, so remapping the
   utility in §2 is not enough — the element rules have to be answered directly.
   ========================================================================= */
''')
CONTROLS = [
    '[type="text"]', '[type="email"]', '[type="url"]', '[type="password"]',
    '[type="number"]', '[type="date"]', '[type="datetime-local"]',
    '[type="month"]', '[type="search"]', '[type="tel"]', '[type="time"]',
    '[type="week"]', '[multiple]', 'textarea', 'select',
]
# Each member of the list gets its own DARK prefix. Writing this as one
# pre-joined string would prefix only the FIRST selector — the other fourteen
# would apply in light mode too, painting every input on the app dark-surface
# in both themes. That is exactly the bug the leak check in the header guards
# against, and it is why this is built from a list rather than typed out.
w(',\n'.join('%s %s' % (DARK, c) for c in CONTROLS))
w(' {\n')
w('    background-color: var(--lpc-surface-sunken);\n')
w('    border-color: var(--lpc-border-strong);\n')
w('    color: var(--lpc-ink);\n')
w('}\n')
w(',\n'.join('%s %s:focus' % (DARK, c) for c in CONTROLS))
w(' {\n')
w('    border-color: var(--lpc-accent-on-light);\n')
w('    --tw-ring-color: var(--lpc-accent-on-light);\n')
w('}\n')

w('''
/* The forms plugin draws the <select> chevron as an inline SVG data URI with a
   hardcoded #6b7280 stroke, baked into a background-image. It is not a colour
   property, so nothing above reaches it — at 4.6:1 on the old white field it
   was fine and on the dark field it is nearly gone. Redrawn here in the mute
   ink colour. */
''')
CHEVRON = ("url(\"data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' "
           "fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236F8378' "
           "stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' "
           "d='M6 8l4 4 4-4'/%3e%3c/svg%3e\")")
w('%s select:not([multiple]):not([size]) { background-image: %s; }\n'
  % (DARK, CHEVRON))

w('''
/* Native date/time pickers render their calendar glyph as a dark bitmap. The
   filter is the only hook browsers expose for it. color-scheme:dark (set by
   the shell) handles the popup itself. */
''')
w('%s ::-webkit-calendar-picker-indicator { filter: invert(1) opacity(.65); }\n' % DARK)

w('''
/* Chrome's autofill paints an opaque yellow field with an inset shadow that
   ignores background-color. The long inset shadow is the standard way to
   repaint it, and -webkit-text-fill-color the only way to recolour its text. */
''')
w('%s input:-webkit-autofill,\n' % DARK)
w('%s input:-webkit-autofill:hover,\n' % DARK)
w('%s input:-webkit-autofill:focus {\n' % DARK)
w('    -webkit-box-shadow: 0 0 0 1000px var(--lpc-surface-sunken) inset;\n')
w('    -webkit-text-fill-color: var(--lpc-ink);\n')
w('    caret-color: var(--lpc-ink);\n')
w('}\n')

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   7. Elevation
   ---------------------------------------------------------------------------
   Tailwind's shadows are black at 10-25% alpha: on a near-black page they are
   invisible, so every card loses its edge and the layout goes flat. Dark UIs
   conventionally carry elevation with a lighter surface plus a hairline rather
   than a drop shadow, so the shadow is deepened AND a top highlight is added.
   ========================================================================= */
''')
for cls, val in [
    ('shadow-sm',   '0 1px 2px rgba(0,0,0,.45)'),
    ('shadow',      '0 1px 3px rgba(0,0,0,.5), 0 1px 2px rgba(0,0,0,.4)'),
    ('shadow-md',   '0 4px 8px -2px rgba(0,0,0,.55), 0 2px 4px -2px rgba(0,0,0,.45)'),
    ('shadow-lg',   '0 10px 20px -4px rgba(0,0,0,.6), 0 4px 8px -4px rgba(0,0,0,.5)'),
    ('shadow-xl',   '0 20px 32px -8px rgba(0,0,0,.65), 0 8px 12px -6px rgba(0,0,0,.5)'),
    ('shadow-2xl',  '0 26px 56px -12px rgba(0,0,0,.72)'),
]:
    w(emit(cls, [('--tw-shadow', val), ('box-shadow', val)]))

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   8. Modal scrims
   ---------------------------------------------------------------------------
   Modals are direct children of <body>, OUTSIDE #lpc-shell-main, which is why
   this file is not simply scoped to the workspace container. Their panels are
   `bg-white` and are already handled by §2; the scrims are darkened further so
   a dark modal still separates from the dark page behind it.
   ========================================================================= */
''')
for cls, val in [
    ('bg-gray-900/50', 'rgba(0, 0, 0, .62)'),
    ('bg-gray-900/60', 'rgba(0, 0, 0, .68)'),
    ('bg-gray-900/70', 'rgba(0, 0, 0, .74)'),
    ('bg-gray-900/80', 'rgba(0, 0, 0, .80)'),
    ('bg-gray-900/90', 'rgba(0, 0, 0, .88)'),
    ('bg-black/50',    'rgba(0, 0, 0, .62)'),
]:
    w(emit(cls, [('background-color', val)]))

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   9. Chrome exclusion — the last word
   ---------------------------------------------------------------------------
   The green L-frame does not invert. Today it uses only text-white and
   slash-opacity fills, none of which this file touches, so this section is
   currently a no-op. It is here so it STAYS a no-op: the day someone drops a
   plain `bg-white` divider into the sidebar, §2 would otherwise repaint it
   --lpc-surface and punch a hole in the brand frame. Three extra ID-specificity
   rules are cheaper than that bug.
   ========================================================================= */
''')
for host in ['#lpc-sidebar', '#lpc-topbar']:
    w('%s %s .bg-white  { background-color: #fff; }\n' % (DARK, host))
    w('%s %s .text-white { color: #fff; }\n' % (DARK, host))
    w('%s %s [class*="border-white"] { border-color: rgba(255,255,255,.12); }\n'
      % (DARK, host))

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   10. Typography plugin
   ---------------------------------------------------------------------------
   .prose hardcodes near-black headings and body text. The plugin ships
   `prose-invert` for exactly this, but it has to be applied per element in the
   markup; pointing the variables at the shell ink applies it everywhere.
   ========================================================================= */
''')
w('%s .prose {\n' % DARK)
for v in ['body', 'headings', 'lead', 'links', 'bold', 'counters', 'bullets',
          'quotes', 'quote-borders', 'captions', 'code', 'pre-code',
          'th-borders', 'td-borders']:
    if v in ('quote-borders', 'th-borders', 'td-borders', 'bullets'):
        w('    --tw-prose-%s: var(--lpc-border);\n' % v)
    elif v in ('headings', 'bold', 'quotes', 'code'):
        w('    --tw-prose-%s: var(--lpc-ink);\n' % v)
    elif v == 'links':
        w('    --tw-prose-links: var(--lpc-accent-on-light);\n')
    else:
        w('    --tw-prose-%s: var(--lpc-ink-soft);\n' % v)
w('    --tw-prose-pre-bg: var(--lpc-surface-sunken);\n')
w('}\n')

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   11. Media
   ---------------------------------------------------------------------------
   Logos and signatures in this app are dark artwork on a transparent or white
   ground — invisible on a dark card. Rather than ship a second asset set, the
   two known cases opt in with .lpc-invert-on-dark. Photographs and uploaded
   documents must NOT be filtered, which is why this is opt-in by class and not
   a blanket img rule.
   ========================================================================= */
''')
w('%s .lpc-invert-on-dark { filter: invert(1) hue-rotate(180deg); }\n' % DARK)

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   12. Hand-written components in src/input.css
   ---------------------------------------------------------------------------
   These are not Tailwind utilities, so nothing above reaches them: they are
   component classes compiled INTO tailwind.css from assets/css/src/input.css,
   with literal hex values from before the theme existed.

   lpc-shell.css §10.8 already fixed exactly one of them — .lpc-modal — but
   scoped to `:has(.lpc-account)`, i.e. the account dialog only, because at the
   time that was the only modal anyone had checked in dark mode. Every OTHER
   .lpc-modal in the app (confirm dialogs, prompts, the whole LPC.modal API)
   kept its #fff. The scope is widened to all of them here; the account rule in
   the shell stays where it is and simply becomes redundant rather than wrong.
   ========================================================================= */
''')
w('%s .lpc-modal {\n' % DARK)
w('    background: var(--lpc-surface);\n')
w('    color: var(--lpc-ink);\n')
w('}\n')
w('%s .lpc-modal-title { color: var(--lpc-ink); }\n' % DARK)
w('%s .lpc-modal-body  { color: var(--lpc-ink-soft); }\n' % DARK)
w('%s .lpc-modal-label { color: var(--lpc-ink-soft); }\n' % DARK)
w('%s .lpc-modal-input {\n' % DARK)
w('    background: var(--lpc-surface-sunken);\n')
w('    border-color: var(--lpc-border-strong);\n')
w('    color: var(--lpc-ink);\n')
w('}\n')
w('%s .lpc-modal-btn--secondary {\n' % DARK)
w('    background: var(--lpc-surface-chip);\n')
w('    color: var(--lpc-ink);\n')
w('    border-color: var(--lpc-border-strong);\n')
w('}\n')
w('%s .lpc-modal-btn--secondary:hover { background: var(--lpc-surface-well); }\n' % DARK)

w('''
/* The <dialog> backdrop and the non-<dialog> fallback overlay are one visual
   element expressed twice, so they are darkened together or the two modal code
   paths stop matching. */
''')
w('%s .lpc-modal-dialog::backdrop,\n' % DARK)
w('%s .lpc-modal-fallback .lpc-modal-backdrop { background: rgba(0, 0, 0, .68); }\n' % DARK)

w('''
/* .seamless-input is the inline-editable table cell in sales/orders and
   invoicing. Its hover/focus wash is #F9FAFB — a white flash on a dark row,
   on the one control the user is actively typing into. */
''')
w('%s .seamless-input:focus,\n' % DARK)
w('%s .seamless-input:hover { background: var(--lpc-surface-well); }\n' % DARK)

w('''
/* .reconciled-row carries !important in input.css, so this has to as well.
   The mint-green fill becomes a low-strength green tint on the row surface;
   the 0.8 opacity that made "reconciled" read as receded on white makes it
   read as unreadable on dark, so the receding is done with ink colour
   instead and the opacity is dropped. */
''')
w('%s .reconciled-row {\n' % DARK)
w('    background-color: color-mix(in srgb, #10b981 16%, var(--lpc-surface)) !important;\n')
w('    color: #6ee7b7 !important;\n')
w('    opacity: 1;\n')
w('}\n')

w('''
/* .scrollbar-thin thumbs are #cbd5e1 — a light grey bar on a dark track. */
''')
w('%s .scrollbar-thin::-webkit-scrollbar-thumb { background: var(--lpc-surface-raised); }\n' % DARK)

# ---------------------------------------------------------------------------
w('''
/* ===========================================================================
   13. Help centre
   ---------------------------------------------------------------------------
   lpc-help.css is ~90% variable-driven already, which is why the help drawer
   is nearly right in dark mode. What is left is the decorative tints its
   author wrote as literals because no variable expressed "faint brand-green
   wash": the section headers, the callouts and the badges. Those are the
   patches that stay light when the drawer is opened over a dark page.
   ========================================================================= */
''')
w('%s .lpc-help-btn:hover,\n' % DARK)
w('%s .lpc-help-card-icon { background: color-mix(in srgb, var(--lpc-accent-on-light) 16%%, var(--lpc-surface)); }\n' % DARK)
w('%s .lpc-help-row:hover { background: var(--lpc-surface-sunken); }\n' % DARK)
w('%s .lpc-help-prose code {\n' % DARK)
w('    background: var(--lpc-surface-chip);\n')
w('    border-color: var(--lpc-border-strong);\n')
w('    color: var(--lpc-ink);\n')
w('}\n')
w('%s .lpc-help-fallback {\n' % DARK)
w('    background: color-mix(in srgb, #f59e0b 16%, var(--lpc-surface));\n')
w('    border-color: color-mix(in srgb, #f59e0b 45%, var(--lpc-border));\n')
w('    color: #fcd34d;\n')
w('}\n')
w('%s .lpc-help-badge--on  { background: color-mix(in srgb, #10b981 18%%, var(--lpc-surface)); color: #6ee7b7; }\n' % DARK)
w('%s .lpc-help-badge--off { background: var(--lpc-surface-chip); color: var(--lpc-ink-soft); }\n' % DARK)
w('%s .lpc-help-badge--perm { background: color-mix(in srgb, #3b82f6 18%%, var(--lpc-surface)); color: #93c5fd; }\n' % DARK)
w('%s .lpc-help-callout--note    { background: var(--lpc-surface-chip); border-color: #64748B; color: var(--lpc-ink); }\n' % DARK)
w('%s .lpc-help-callout--tip     { background: color-mix(in srgb, var(--lpc-accent-on-light) 14%%, var(--lpc-surface)); color: var(--lpc-ink); }\n' % DARK)
w('%s .lpc-help-callout--warning { background: color-mix(in srgb, #f59e0b 16%%, var(--lpc-surface)); border-color: #D97706; color: #fcd34d; }\n' % DARK)
w('%s .lpc-help-callout--danger  { background: color-mix(in srgb, #ef4444 16%%, var(--lpc-surface)); border-color: #DC2626; color: #fca5a5; }\n' % DARK)
w('''
/* The skeleton shimmer is a light-grey gradient; on a dark panel it reads as
   content that has finished loading rather than content that is still on its
   way, which is the one thing a skeleton must not do. */
''')
w('%s .lpc-help-skeleton { background: linear-gradient(90deg, var(--lpc-surface-chip) 25%%, var(--lpc-surface-well) 50%%, var(--lpc-surface-chip) 75%%); }\n' % DARK)


# =============================================================================
# 14. Page-local <style> blocks.
# -----------------------------------------------------------------------------
# Sections 1-13 remap Tailwind utilities and the shared component classes. That
# is not the whole app.
#
# Around twenty module pages also define their OWN component classes in an
# inline <style> block in <head> — .lpc-section, .lpc-input, .lpc-role-chip,
# .lpc-savebar, .perm-row, .st-paid, .row-master and ~100 more — with literal
# light colours. No utility remap can reach a class that Tailwind never
# generated, so those pages kept white cards and near-black label text on a dark
# workspace. Worse than untouched, in fact: §3 correctly turned their body text
# light, and light text on a still-white .lpc-section is less readable than the
# original. That is the "some pages are still bad" case.
#
# Enumerating those ~100 declarations by hand here would fix today's pages and
# rot the moment someone adds a <style> block to page twenty-one. So they are
# READ from the source instead: this section scans the pages, parses their
# blocks, and derives a dark counterpart for every colour declaration it finds.
# Re-running the build re-scans, which means new page CSS is covered by
# construction rather than by remembering to come back here.
#
# Specificity, not load order, is what makes these win: a page rule like
# `.lpc-input:focus` is (0,2,0) and the generated `html[data-lpc-theme="dark"]
# .lpc-input:focus` is (0,3,1). That holds even for a page that puts its <style>
# after head_assets.php (none do today, but it costs nothing to be order-proof).
# =============================================================================
import colorsys
import glob
import re

APP_ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')

# Pages whose <style> blocks must NOT be themed.
#   pdf_templates / documents / document_pdf : rendered onto white paper
#   auth pages + index.php                   : the dark glass login design,
#                                              which is already dark and is not
#                                              part of the light/dark workspace
#   print_audit                              : print-only view
SKIP_PATHS = (
    'includes/pdf_templates/',
    'public/documents/',
    'public/auth/',
    'includes/functions/document_pdf.php',
    'modules/inventory/print_audit.php',
    'includes/components/head_assets.php',
    'index.php',
)

# Brand colours map to the accent variables rather than to a surface/ink step,
# so a page that hardcoded the brand still follows a user's accent choice.
BRAND = {
    '#005a2b': 'var(--lpc-accent-on-light)',
    '#8cc63f': 'var(--lpc-accent-on-dark)',
    '#00341a': 'var(--lpc-chrome-top)',
}


def parse_hex(h):
    h = h.lstrip('#')
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    if len(h) == 8:
        h = h[:6]
    if len(h) != 6:
        return None
    return tuple(int(h[i:i + 2], 16) / 255.0 for i in (0, 2, 4))


def hsl(rgb):
    r, g, b = rgb
    h, l, s = colorsys.rgb_to_hls(r, g, b)
    return h * 360, s, l


# Tailwind's neutral ramps, verbatim. These are listed rather than detected
# because no cheap numeric test separates them from the pale status washes the
# app also uses: slate-800 #1e293b and blue-100 #dbeafe have almost identical
# chroma, and HLS saturation is useless here — it approaches 1.0 for anything
# near white, so #F9FAFB (gray-50) reads as MORE saturated than #fef3c7
# (amber-100). Reading a literal list is exact where a heuristic is not, and
# these values have not changed across Tailwind 2, 3 or 4.
NEUTRAL_HEXES = {
    # gray
    '#f9fafb', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af', '#6b7280',
    '#4b5563', '#374151', '#1f2937', '#111827', '#030712',
    # slate
    '#f8fafc', '#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b',
    '#475569', '#334155', '#1e293b', '#0f172a', '#020617',
    # zinc / neutral / stone (present in a couple of pages)
    '#fafafa', '#f4f4f5', '#e4e4e7', '#d4d4d8', '#a1a1aa', '#71717a',
    '#52525b', '#3f3f46', '#27272a', '#18181b',
    '#f5f5f5', '#e5e5e5', '#d4d4d4', '#a3a3a3', '#737373', '#525252',
    '#404040', '#262626', '#171717',
    '#f5f5f4', '#e7e5e4', '#d6d3d1', '#a8a29e', '#78716c', '#57534e',
    '#44403c', '#292524', '#1c1917',
    # plain black/white
    '#ffffff', '#000000',
}


def is_neutral(literal, rgb):
    """
    True when a colour carries no meaning beyond its lightness.

    Two tests, in order: the Tailwind neutral ramps by exact value, then raw
    chroma (max-min) for the handful of bespoke greys the pages invented —
    #FCFCFD, #E6EAEE and similar. Chroma is used rather than HLS saturation
    because it does not distort near the ends of the lightness range.
    """
    if literal.lower() in NEUTRAL_HEXES:
        return True
    return (max(rgb) - min(rgb)) <= 0.02


def map_colour(prop, literal):
    """
    One literal colour -> the shell variable (or derived tint) that expresses
    the same INTENT on a dark surface.

    Everything keys off lightness and saturation rather than a lookup table,
    because the pages did not share a palette — #F9FAFB, #f8fafc, #f1f5f9 and
    #FCFCFD all mean "one step down from the card" and all have to land on
    --lpc-surface-sunken without anyone maintaining a list of them.
    """
    key = literal.lower()
    if key in BRAND:
        return BRAND[key]

    rgb = parse_hex(literal)
    if rgb is None:
        return None
    hue, sat, lum = hsl(rgb)

    is_bg = prop in ('background', 'background-color')
    is_border = prop.startswith('border')
    is_text = prop in ('color', 'accent-color')

    # --- near-neutral -------------------------------------------------------
    if is_neutral(literal, rgb):
        if is_bg:
            # Pure white and near-white are NOT the same step, and collapsing
            # them onto --lpc-surface is what flattens these pages: .lpc-input
            # (#F9FAFB) sitting inside .lpc-section (#fff) is a field inset into
            # a card, and if both resolve to the card colour the field stops
            # looking like a field. Only literal white is the card.
            if lum >= 0.995:
                return 'var(--lpc-surface)'
            if lum >= 0.90:
                return 'var(--lpc-surface-sunken)'
            if lum >= 0.78:
                return 'var(--lpc-surface-chip)'
            if lum >= 0.55:
                return 'var(--lpc-surface-well)'
            if lum <= 0.35:
                # Already dark on a light page: an intentional contrast island.
                # It has to become LIGHTER than its surroundings to keep
                # standing out — same reasoning as §1.
                return 'var(--lpc-surface-raised)'
            return 'var(--lpc-surface-well)'
        if is_border:
            return 'var(--lpc-border)' if lum >= 0.75 else 'var(--lpc-border-strong)'
        if is_text:
            if lum >= 0.90:
                return None          # white-on-accent text stays white
            if lum <= 0.35:
                return 'var(--lpc-ink)'
            if lum <= 0.60:
                return 'var(--lpc-ink-soft)'
            return 'var(--lpc-ink-mute)'
        return None

    # --- saturated ----------------------------------------------------------
    # Status colours. The hue carries meaning (red = overdue, green = paid), so
    # it is preserved and only the lightness is re-pitched for a dark surface.
    if is_bg:
        if lum >= 0.85:
            # A pale status wash -> the same hue mixed into the card.
            base = colorsys.hls_to_rgb(hue / 360.0, 0.55, max(sat, 0.65))
            hexs = '#%02x%02x%02x' % tuple(int(round(c * 255)) for c in base)
            return 'color-mix(in srgb, %s 18%%, var(--lpc-surface))' % hexs
        return None                  # saturated fills already read on dark
    if is_border:
        base = colorsys.hls_to_rgb(hue / 360.0, 0.55, max(sat, 0.65))
        hexs = '#%02x%02x%02x' % tuple(int(round(c * 255)) for c in base)
        return 'color-mix(in srgb, %s 42%%, var(--lpc-border))' % hexs
    if is_text:
        if lum >= 0.72:
            return None              # already light enough
        # Dark status text -> the same hue at a lightness that clears AA on the
        # tint above.
        out = colorsys.hls_to_rgb(hue / 360.0, 0.78, min(max(sat, 0.55), 0.95))
        return '#%02x%02x%02x' % tuple(int(round(c * 255)) for c in out)
    return None


def map_rgb_functions(prop, value):
    """
    Map rgb()/rgba() literals, preserving alpha.

    Translucent fills are how the pages build scrims, glass panels and the
    sticky save bar, and they cannot go through map_colour() unchanged because
    the result has to stay translucent. The alpha is re-expressed as a
    color-mix against `transparent`, which is the only way to blend a CSS
    variable to a partial alpha without splitting it into channel components.

    Fully-black translucent values (shadows, scrims) are left alone: they are
    already correct on a dark surface, and lightening them would remove the
    separation they exist to create.
    """
    out = []
    for m in re.finditer(r'rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)',
                         value):
        literal = m.group(0)
        r, g, b = (float(m.group(i)) / 255.0 for i in (1, 2, 3))
        alpha = float(m.group(4)) if m.group(4) is not None else 1.0

        if max(r, g, b) <= 0.15:
            continue                      # black scrim / shadow — already right

        mapped = map_colour(prop, '#%02x%02x%02x'
                            % tuple(int(round(c * 255)) for c in (r, g, b)))
        if not mapped:
            continue
        if alpha >= 0.999:
            out.append((literal, mapped))
        else:
            out.append((literal, 'color-mix(in srgb, %s %d%%, transparent)'
                        % (mapped, int(round(alpha * 100)))))
    return out


def strip_noise(block):
    """Comments and @-blocks out. Both contain braces and would be parsed as
    rules; @media print in particular must never be themed."""
    block = re.sub(r'/\*.*?\*/', '', block, flags=re.S)
    # Remove @keyframes / @media / @supports bodies (one level of nesting).
    block = re.sub(r'@[a-z-]+[^{]*\{(?:[^{}]|\{[^{}]*\})*\}', '', block, flags=re.S)
    return block


def scan_page_styles():
    """-> ordered list of (selector, [(prop, mapped_value)]) plus a source map."""
    found = []
    seen = set()
    sources = {}

    paths = []
    for root in ('modules', 'includes', 'public'):
        paths += glob.glob(os.path.join(APP_ROOT, root, '**', '*.php'), recursive=True)
    paths.append(os.path.join(APP_ROOT, 'index.php'))

    for path in sorted(paths):
        rel = os.path.relpath(path, APP_ROOT).replace('\\', '/')
        if any(rel == s or rel.startswith(s) for s in SKIP_PATHS):
            continue
        try:
            src = open(path, encoding='utf-8', errors='replace').read()
        except OSError:
            continue

        for block in re.findall(r'<style[^>]*>(.*?)</style>', src, re.S):
            for m in re.finditer(r'([^{}]+)\{([^{}]*)\}', strip_noise(block)):
                selector, body = m.group(1).strip(), m.group(2)
                if not selector or selector.startswith('@'):
                    continue
                # Percentage keyframe steps that survived, and PHP that leaked
                # into a selector position.
                if re.match(r'^[\d.]+%$', selector) or '<?' in selector:
                    continue

                decls = []
                for prop, val in re.findall(r'([a-z-]+)\s*:\s*([^;]+)', body):
                    prop = prop.strip()
                    val = val.strip()
                    if prop not in ('color', 'background', 'background-color',
                                    'border', 'border-color', 'border-top',
                                    'border-bottom', 'border-left', 'border-right',
                                    'accent-color'):
                        continue
                    if 'gradient' in val or '!important' in val:
                        # Gradients are brand artwork; !important here is only
                        # .reconciled-row, handled explicitly in §12.
                        continue
                    out = val
                    changed = False

                    # rgb()/rgba() first, because .lpc-savebar and .glass-panel
                    # write their fill as rgba(255,255,255,.92) — a hex-only
                    # scan leaves the sticky save bar and the glass panels
                    # white on a dark page, which is exactly where the eye goes.
                    for lit, mapped in map_rgb_functions(prop, val):
                        out = out.replace(lit, mapped)
                        changed = True

                    for lit in re.findall(r'#[0-9a-fA-F]{3,8}', out):
                        mapped = map_colour(prop, lit)
                        if mapped:
                            out = out.replace(lit, mapped)
                            changed = True

                    if changed:
                        decls.append((prop, out))

                if not decls:
                    continue
                for sel in [s.strip() for s in selector.split(',') if s.strip()]:
                    key = (sel, tuple(decls))
                    if key in seen:
                        continue
                    seen.add(key)
                    found.append((sel, decls))
                    sources.setdefault(sel, rel)
    return found, sources


page_rules, page_sources = scan_page_styles()

w('''
/* ===========================================================================
   14. Page-local component classes  (GENERATED BY SCANNING THE PAGES)
   ---------------------------------------------------------------------------
   Derived from the inline <style> blocks of the module pages — see
   scripts/build_theme_css.py, scan_page_styles(). Do not hand-edit: fix the
   colour in the page, or the mapping in map_colour(), and rebuild.

   Colours are mapped by lightness and saturation rather than from a lookup
   table, because these pages never shared a palette. #F9FAFB, #f8fafc, #f1f5f9
   and #FCFCFD all mean "one step below the card"; all four land on
   --lpc-surface-sunken without anyone maintaining a list. Saturated status
   colours keep their hue (red still means overdue) and only get re-pitched for
   a dark background.
   ========================================================================= */
''')
w('/* %d rules generated from %d page selectors. */\n'
  % (len(page_rules), len(page_sources)))

_last_src = None
for sel, decls in page_rules:
    src = page_sources.get(sel)
    if src != _last_src:
        w('\n/* --- %s --- */\n' % src)
        _last_src = src
    body = ' '.join('%s: %s;' % (p, v) for p, v in decls)
    w('%s %s { %s }\n' % (DARK, sel, body))

w('''
/* Printed output is always on white paper, so the dark theme is dropped
   wholesale for print rather than fixed rule by rule. */
@media print {
    html[data-lpc-theme="dark"] { color-scheme: light; }
}
''')


# =============================================================================
# Self-check: NOTHING may leak into light mode.
# -----------------------------------------------------------------------------
# The whole safety argument for this file is "it is inert unless the dark
# attribute is present", and that argument is only as good as every single
# selector honouring it. One comma-separated group where the prefix was applied
# to the first selector and not the rest is enough to break it silently — light
# mode would pick up dark colours with no error anywhere, and the group most
# likely to be written that way is the form-control list in §6, which is fifteen
# selectors long.
#
# So it is asserted here rather than trusted. This runs on every regeneration
# and refuses to write a file that would change light mode.
# =============================================================================
def check_no_light_mode_leak(css):
    import re

    # Strip comments first: they contain commas, braces and the word "dark",
    # and would otherwise be parsed as selectors.
    body = re.sub(r'/\*.*?\*/', '', css, flags=re.S)

    # Everything inside @media print is intentionally unprefixed.
    body = re.sub(r'@media print\s*\{.*?\n\}', '', body, flags=re.S)

    offenders = []
    for block in re.finditer(r'([^{}]+)\{[^{}]*\}', body):
        selector_group = block.group(1).strip()
        if not selector_group or selector_group.startswith('@'):
            continue
        for sel in selector_group.split(','):
            sel = sel.strip()
            if sel and '[data-lpc-theme="dark"]' not in sel:
                offenders.append(sel)

    if offenders:
        raise SystemExit(
            'REFUSING TO WRITE: %d selector(s) would apply in LIGHT mode.\n'
            'Every rule in lpc-theme.css must be scoped to the dark attribute.\n'
            'First offenders:\n  %s'
            % (len(offenders), '\n  '.join(offenders[:8]))
        )
    return True


css_out = ''.join(L)
check_no_light_mode_leak(css_out)

open(os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'assets', 'css', 'lpc-theme.css'), 'w').write(css_out)
print("bytes: %d  ·  light-mode leak check: PASS" % len(css_out))
