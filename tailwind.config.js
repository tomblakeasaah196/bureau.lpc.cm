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
        { pattern: /^(bg|text|border)-lpc-(dark|light|bg)$/ },
    ],
    theme: {
        extend: {
            colors: {
                lpc: {
                    dark:  '#005A2B',
                    light: '#8CC63F',
                    bg:    '#F8FAFC',
                },
                treasury: {
                    dark:  '#047857',
                    light: '#34D399',
                    alert: '#F59E0B',
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
