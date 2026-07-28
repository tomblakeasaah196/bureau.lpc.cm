/**
 * tailwind.config.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Tailwind config.
 *
 * The `content` globs cover every PHP file that emits Tailwind classes so the
 * JIT compiler can tree-shake unused utilities. If you add a new module dir,
 * extend the `content` array.
 *
 * Build:   npm run build:css
 * Watch:   npm run watch:css      (dev only)
 * -----------------------------------------------------------------------------
 */
module.exports = {
    content: [
        './index.php',
        './public/**/*.php',
        './modules/**/*.php',
        './includes/components/**/*.php',
        './api/v1/**/*.php',           // controllers echo tiny bits of HTML in errors
        './assets/js/**/*.js',
    ],
    // Never purge these — they're built into responses that Tailwind can't see.
    safelist: [
        'hidden', 'block', 'flex', 'grid', 'lpc-hidden-by-rbac',
        { pattern: /^(bg|text|border)-(red|amber|emerald|green|blue|gray|slate)-(50|100|200|300|400|500|600|700|800|900)$/ },
        { pattern: /^(bg|text|border)-lpc-(dark|light|bg|surface|border)$/ },
        // Module accent pairs. The per-page tab JS toggles these class names at
        // runtime (e.g. assets/js/modules/accounting-budgets.js), so they must
        // survive purge even though some appear only inside a JS string.
        { pattern: /^(bg|text|border|ring)-(finance|rev|acc|pay|dash|asset|fin|treasury)-(dark|highlight)$/ },
    ],
    theme: {
        extend: {
            colors: {
                lpc: {
                    // Brand pair. Fixed hex on purpose: the green IS the brand
                    // and is identical in both themes, so binding it to a
                    // variable would only add indirection.
                    dark:    '#005A2B',
                    light:   '#8CC63F',

                    // Neutral trio. These were fixed hex — #F5F7FA / #FFFFFF /
                    // #E5E7EB — which meant `bg-lpc-surface` compiled to literal
                    // white and a card wearing it stayed white in dark mode,
                    // even though the *name* promised it followed the theme.
                    // That was the single most misleading thing in the palette:
                    // the KPI cards on the exec dashboard use bg-lpc-surface,
                    // read as theme-aware, and were not.
                    //
                    // They now defer to the shell variables, which is where the
                    // light/dark values actually live (lpc-shell.css §10.2), so
                    // the token means what it says. The fallbacks keep the old
                    // colours if this stylesheet is ever loaded without the
                    // shell — public/documents/* does exactly that.
                    //
                    // No slash-opacity utility uses these three (checked across
                    // all 87 module pages), so plain var() is safe here; the
                    // rgb(var(--x) / <alpha-value>) channel-splitting dance is
                    // not needed and would make the shell variables unusable in
                    // ordinary CSS.
                    bg:      'var(--lpc-page-bg, #F5F7FA)',
                    surface: 'var(--lpc-surface, #FFFFFF)',
                    border:  'var(--lpc-border, #E5E7EB)',
                },

                // ---------------------------------------------------------------
                // Module accent palettes.
                //
                // These token names were originally defined per page in inline
                // `tailwind.config` blocks, each with its own arbitrary colour.
                // Those blocks went away when Tailwind was self-hosted (Sprint 3)
                // but ~11 pages — and the tab-switching JS — still reference the
                // class names, so they resolved to nothing: active-tab underlines
                // and brand text silently rendered as fallback grey.
                //
                // They are deliberately ALL mapped onto the one LPC brand pair
                // rather than restoring seven unrelated colours. Every active tab
                // indicator and brand accent in the app is now the same green,
                // which is the point — one product, not seven.
                // ---------------------------------------------------------------
                finance:  { dark: '#005A2B', highlight: '#8CC63F' },  // budgets
                rev:      { dark: '#005A2B', highlight: '#8CC63F' },  // ledger
                acc:      { dark: '#005A2B', highlight: '#8CC63F' },  // journal_entry
                pay:      { dark: '#005A2B', highlight: '#8CC63F' },  // payroll_finance
                dash:     { dark: '#005A2B', highlight: '#8CC63F' },  // analytics/reports
                asset:    { dark: '#005A2B', highlight: '#8CC63F' },  // fixed_assets
                fin:      { dark: '#005A2B', highlight: '#8CC63F' },  // accounting/reports
                treasury: {
                    dark:      '#005A2B',
                    highlight: '#8CC63F',
                    light:     '#34D399',
                    alert:     '#F59E0B',
                },
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'sans-serif'],
            },
            animation: {
                blob:          'blob 7s infinite',
                'fade-in-up':  'fadeInUp 0.8s ease-out forwards',
                'slide-up':    'slideUp 0.3s ease-out',
            },
            keyframes: {
                blob: {
                    '0%':   { transform: 'translate(0px, 0px) scale(1)' },
                    '33%':  { transform: 'translate(30px, -50px) scale(1.1)' },
                    '66%':  { transform: 'translate(-20px, 20px) scale(0.9)' },
                    '100%': { transform: 'translate(0px, 0px) scale(1)' },
                },
                fadeInUp: {
                    '0%':   { opacity: '0', transform: 'translateY(20px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                slideUp: {
                    '0%':   { opacity: '0', transform: 'translateY(10px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
        },
    },
    plugins: [
        require('@tailwindcss/forms'),
        require('@tailwindcss/typography'),
    ],
};
