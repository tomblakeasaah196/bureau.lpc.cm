/**
 * assets/js/lpc-kpi.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — the shared KPI card + period pill + drilldown modal library.
 * Introduced Sprint 7H (dashboards revamp).
 *
 * ONE COMPONENT, FIVE DASHBOARDS
 * ------------------------------
 * Before Sprint 7H every dashboard's KPI cards were bespoke: three different
 * card templates, three different loading states, four different drilldown
 * treatments, five different period pickers. This file collapses all of that
 * into one API. A dashboard now says:
 *
 *   LPC.kpi.mount('#lpc-kpi-grid', [ { id, label, deeplink, drill, … }, … ])
 *
 * and populates values with:
 *
 *   LPC.kpi.update({ id, value, valueLabel, delta, sparkline, sub, empty })
 *
 * WHAT THIS FILE OWNS
 * -------------------
 *   · LPC.kpi.mount(gridSelector, cards)     render the KPI grid from a spec
 *   · LPC.kpi.update(patch)                  update one card in place
 *   · LPC.kpi.setLoading(gridSelector, on)   flip every card into shimmer
 *   · LPC.kpi.formatCompact(n)               '1.2 M' / '850 k' / '850'
 *   · LPC.kpi.mountPeriodPill(el, opts)      render the segmented period picker
 *   · LPC.kpi.openDrilldown(cfg)             open the standardised drill modal
 *   · Emits `lpc:period-change` on the pill container when the user picks one.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 *   · It does not know anything about a specific dashboard. Each dashboard's
 *     JS module still owns fetch, chart rendering, and its own KPI list.
 *   · It does not compute deltas. The API returns them per KPI so the "vs what"
 *     rule (previous period vs target) lives with the query that produced the
 *     number — README §5.8, honest-KPI rule.
 *
 * DEPENDENCIES
 * ------------
 *   · LPC.html / LPC.raw / LPC.fmt      (lpc-dom.js — head_assets)
 *   · LPC.modal.custom                  (lpc-modal.js — head_assets)
 *   · LPC.paginator.attach              (lpc-paginator.js — page-loaded when used)
 *   · Chart                             (chart.umd.min.js — page-loaded when used)
 *
 * The paginator and Chart imports are page-local (they are heavy and not every
 * page needs them). This library defers the imports until openDrilldown() /
 * sparkline() run so a page that only uses cards without drilldowns costs
 * nothing extra.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';
    if (!window.LPC) window.LPC = {};
    if (window.LPC.kpi) return;

    // ------------------------------------------------------------------
    // Local formatting — big-number-friendly compact form.
    // 1_234_567 → "1,2 M" ; 12_345 → "12,3 k" ; 950 → "950".
    // The full number stays available via `title` on the value node.
    // ------------------------------------------------------------------
    function formatCompact(n) {
        var v = Number(n);
        if (!Number.isFinite(v)) return '—';
        var abs = Math.abs(v);
        var out;
        if (abs >= 1e9) {
            out = (v / 1e9).toFixed(1).replace(/\.0$/, '') + ' Md';
        } else if (abs >= 1e6) {
            out = (v / 1e6).toFixed(1).replace(/\.0$/, '') + ' M';
        } else if (abs >= 1e3) {
            out = (v / 1e3).toFixed(1).replace(/\.0$/, '') + ' k';
        } else {
            out = Math.round(v).toString();
        }
        // Use French comma for the decimal separator: matches the rest of the UI.
        return out.replace('.', ',');
    }

    // Suffix helper for FCFA cards. Compact + " F" reads correctly in French:
    // "1,2 M F" fits inside 260 px; "1 234 567 FCFA" does not.
    function formatFcfaCompact(n) {
        var s = formatCompact(n);
        if (s === '—') return s;
        return s + ' F';
    }

    // Verbose FCFA for the `title` tooltip. Reuses LPC.fmt.fcfa if present.
    function formatFcfaFull(n) {
        if (window.LPC && window.LPC.fmt && typeof LPC.fmt.fcfa === 'function') {
            return LPC.fmt.fcfa(n);
        }
        var v = Number(n) || 0;
        return Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' FCFA';
    }

    function formatPercent(n, decimals) {
        var v = Number(n);
        if (!Number.isFinite(v)) return '—';
        var d = decimals == null ? 1 : decimals;
        return v.toFixed(d).replace('.', ',') + ' %';
    }

    // ------------------------------------------------------------------
    // Icon helper — tiny inline Lucide-style stroke SVGs.
    // We embed the ~6 icons we actually use rather than adding a runtime
    // dependency; matches how the shell already inlines its own icons.
    // ------------------------------------------------------------------
    var ICONS = {
        arrowUp:      '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
        arrowDown:    '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12l7 7 7-7"/></svg>',
        arrowRight:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 5l7 7-7 7"/></svg>',
        target:       '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>',
        alert:        '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"/></svg>',
        caret:        '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>',
        download:     '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>',
    };

    // ------------------------------------------------------------------
    // Motion honour — same attribute the shell uses.
    // ------------------------------------------------------------------
    function motionReduced() {
        return document.documentElement.getAttribute('data-lpc-reduce-motion') === '1';
    }

    // ------------------------------------------------------------------
    // countUp — animate 0 → target over ~700 ms with ease-out.
    // The `format` callback runs on every frame so the display stays in
    // whatever unit the KPI needs (FCFA compact / percent / plain int).
    // Short-circuits under reduce-motion.
    // ------------------------------------------------------------------
    function countUp(el, from, to, opts) {
        opts = opts || {};
        var format = opts.format || function (v) { return String(Math.round(v)); };
        var duration = opts.duration || 700;

        if (motionReduced()) {
            el.textContent = format(to);
            return;
        }
        var start = performance.now();
        function tick(now) {
            var t = Math.min(1, (now - start) / duration);
            // easeOutCubic
            var e = 1 - Math.pow(1 - t, 3);
            var v = from + (to - from) * e;
            el.textContent = format(v);
            if (t < 1) requestAnimationFrame(tick);
            else el.textContent = format(to);
        }
        requestAnimationFrame(tick);
    }

    // ------------------------------------------------------------------
    // Card rendering
    // ------------------------------------------------------------------
    var REGISTRY = new Map(); // id → { root, spec, sparklineChart }

    /**
     * Render the KPI grid from a spec.
     * @param {string|Element} target selector or element for the .lpc-kpi-grid container
     * @param {Array<Object>}  cards  card specs
     *
     * Card spec:
     *   {
     *     id:        'md-revenue',           // unique key, used by update()
     *     label:     "Chiffre d'affaires",   // uppercase caption
     *     kind:      'fcfa' | 'int' | 'percent'   // formatter for numbers
     *     rail:      'brand' | 'warn' | 'info' | 'good' | 'alert',
     *     drill:     'revenue',              // KPI key passed to openDrilldown
     *     deeplink:  { href, label }         // shown in the drilldown modal
     *   }
     *
     * Values arrive via LPC.kpi.update() once the API responds.
     */
    function mount(target, cards) {
        var grid = typeof target === 'string' ? document.querySelector(target) : target;
        if (!grid) return null;

        grid.classList.add('lpc-kpi-grid');
        grid.innerHTML = '';                    // idempotent — re-mount replaces

        cards.forEach(function (spec) {
            var isButton = !!spec.drill;
            var tag = isButton ? 'button' : 'article';
            var railAttr = spec.rail ? ' data-rail="' + spec.rail + '"' : '';
            var typeAttr = isButton ? ' type="button"' : '';

            var html =
                '<' + tag + ' class="lpc-kpi-card is-loading" data-kpi-id="' + spec.id + '"' + typeAttr + railAttr +
                    (isButton ? ' aria-label="' + escapeAttr(spec.label + ' — ouvrir le détail') + '"' : '') +
                    '>' +
                    '<p class="lpc-kpi-card__label">' + escapeHtml(spec.label) + '</p>' +
                    '<div class="lpc-kpi-card__value-row">' +
                        '<p class="lpc-kpi-card__value" data-role="value">…</p>' +
                        '<span class="lpc-kpi-card__delta" data-role="delta" data-sentiment="neutral" aria-hidden="true"></span>' +
                    '</div>' +
                    '<p class="lpc-kpi-card__sub" data-role="sub">Calcul en cours…</p>' +
                    (spec.sparkline !== false
                        ? '<div class="lpc-kpi-card__spark" data-role="spark" aria-hidden="true"><canvas></canvas></div>'
                        : '') +
                    (isButton ? '<span class="lpc-kpi-card__go" aria-hidden="true">' + ICONS.arrowRight + '</span>' : '') +
                '</' + tag + '>';
            grid.insertAdjacentHTML('beforeend', html);

            var root = grid.lastElementChild;
            REGISTRY.set(spec.id, { root: root, spec: spec, sparkChart: null });

            if (isButton) {
                root.addEventListener('click', function () {
                    // Fire an event first so a page can override before the
                    // library opens its default modal (useful for the
                    // once-in-a-blue-moon KPI that wants a bespoke drilldown).
                    var ev = new CustomEvent('lpc:kpi-open', { bubbles: true, cancelable: true, detail: { id: spec.id, spec: spec } });
                    if (root.dispatchEvent(ev)) {
                        openDrilldown(Object.assign({}, spec, { title: spec.label }));
                    }
                });
            }
        });

        // Trigger the enter animation on the next frame so the layout has
        // committed. Remove the class after the last card lands.
        if (!motionReduced()) {
            requestAnimationFrame(function () {
                grid.classList.add('is-entering');
                var totalMs = 60 * cards.length + 420;
                setTimeout(function () { grid.classList.remove('is-entering'); }, totalMs);
            });
        }
        return grid;
    }

    /**
     * Update one card in place with a value payload from the API.
     * @param {Object} patch
     *   {
     *     id:            'md-revenue',                  required
     *     value:         12345678,                      required (number, or null → empty state)
     *     valueLabel:    '12 345 678 FCFA',             optional; the tooltip / a11y text
     *     kind:          'fcfa'|'int'|'percent'|'text', formatter override
     *     text:          '12,3 k F',                    if kind === 'text' — takes literal display string
     *     empty:         'Objectif non défini',         optional; render as empty state with this label
     *     delta: {
     *       kind:        'change' | 'attainment' | 'none',
     *       value:       12.3,       // % (change) or % of target (attainment)
     *       label:       '82 % de la cible' | '+12,3 % vs mois préc.',
     *       sentiment:   'good' | 'bad' | 'neutral',
     *       direction:   'up' | 'down' | null,
     *     },
     *     sub:           "Cible: 60 000 000 F",
     *     sparkline:     [ n, n, n, … ],                array of numeric values
     *     sparklineLabels: ['Jan', 'Fév', …],           optional
     *   }
     */
    function update(patch) {
        var entry = REGISTRY.get(patch.id);
        if (!entry) return;
        var root = entry.root;
        var spec = entry.spec;

        root.classList.remove('is-loading');

        var valueEl = root.querySelector('[data-role="value"]');
        var deltaEl = root.querySelector('[data-role="delta"]');
        var subEl   = root.querySelector('[data-role="sub"]');
        var sparkEl = root.querySelector('[data-role="spark"]');

        // --- Value ------------------------------------------------------
        if (patch.empty) {
            valueEl.textContent = patch.empty;
            valueEl.classList.add('is-empty');
            valueEl.removeAttribute('title');
        } else {
            valueEl.classList.remove('is-empty');
            var kind = patch.kind || spec.kind || 'int';
            var display, tooltip;

            if (kind === 'text') {
                display = String(patch.text != null ? patch.text : (patch.value == null ? '—' : patch.value));
                tooltip = patch.valueLabel || display;
                valueEl.textContent = display;
                valueEl.title = tooltip;
            } else if (patch.value == null || !Number.isFinite(Number(patch.value))) {
                valueEl.textContent = '—';
                valueEl.removeAttribute('title');
            } else {
                var target = Number(patch.value);
                var format;
                if (kind === 'fcfa') {
                    format = formatFcfaCompact;
                    tooltip = formatFcfaFull(target);
                } else if (kind === 'percent') {
                    format = function (v) { return formatPercent(v, 1); };
                    tooltip = formatPercent(target, 1);
                } else {
                    format = function (v) {
                        if (window.LPC && LPC.fmt && LPC.fmt.int) return LPC.fmt.int(v);
                        return Math.round(v).toLocaleString('fr-FR');
                    };
                    tooltip = format(target);
                }
                valueEl.title = patch.valueLabel || tooltip;
                countUp(valueEl, 0, target, { format: format });
            }
        }

        // --- Delta chip -------------------------------------------------
        if (deltaEl) {
            if (patch.empty || !patch.delta || patch.delta.kind === 'none') {
                deltaEl.style.display = 'none';
                deltaEl.setAttribute('aria-hidden', 'true');
            } else {
                var d = patch.delta;
                deltaEl.style.display = '';
                deltaEl.removeAttribute('aria-hidden');
                deltaEl.dataset.sentiment = d.sentiment || 'neutral';
                deltaEl.dataset.kind      = d.kind      || 'change';

                var arrow = '';
                if (d.direction === 'up')   arrow = ICONS.arrowUp;
                else if (d.direction === 'down') arrow = ICONS.arrowDown;
                else if (d.kind === 'attainment') arrow = ICONS.target;

                var text = d.label != null
                    ? d.label
                    : (d.value != null ? (d.value > 0 ? '+' : '') + formatPercent(d.value, 1) : '');

                deltaEl.innerHTML = arrow + '<span></span>';
                deltaEl.querySelector('span').textContent = text;
            }
        }

        // --- Sub-line ---------------------------------------------------
        if (subEl) {
            subEl.textContent = patch.sub || '';
            if (!patch.sub) subEl.style.display = 'none';
            else            subEl.style.display = '';
        }

        // --- Sparkline --------------------------------------------------
        if (sparkEl && spec.sparkline !== false) {
            if (Array.isArray(patch.sparkline) && patch.sparkline.length > 1) {
                drawSparkline(sparkEl.querySelector('canvas'), patch.sparkline, {
                    color: colorForRail(spec.rail),
                    entryId: patch.id
                });
            } else {
                // No data → hide the slot rather than render a straight line.
                sparkEl.style.display = 'none';
            }
        }
    }

    /** Colour for the sparkline stroke, matching the card's rail. */
    function colorForRail(rail) {
        switch (rail) {
            case 'warn':  return '#F59E0B';
            case 'info':  return '#3B82F6';
            case 'good':  return '#10B981';
            case 'alert': return '#EF4444';
            default:      return getComputedStyle(document.documentElement).getPropertyValue('--lpc-brand').trim() || '#005A2B';
        }
    }

    /** Toggle the whole grid into shimmer while re-fetching. */
    function setLoading(target, on) {
        var grid = typeof target === 'string' ? document.querySelector(target) : target;
        if (!grid) return;
        grid.querySelectorAll('.lpc-kpi-card').forEach(function (card) {
            if (on) card.classList.add('is-loading');
            else    card.classList.remove('is-loading');
        });
    }

    // ------------------------------------------------------------------
    // Sparkline — a lightweight canvas polyline. Uses Chart.js if it's
    // already loaded on the page (matches the theme automatically); falls
    // back to a hand-drawn polyline for the many pages that don't ship a
    // full Chart.js just for a 40px sparkline.
    // ------------------------------------------------------------------
    function drawSparkline(canvas, values, opts) {
        opts = opts || {};
        // Prefer the raw canvas render — Chart.js is overkill at 40 px and
        // its animation adds motion we already stage via card-enter.
        if (motionReduced() || !window.Chart) {
            return drawSparklineRaw(canvas, values, opts);
        }
        return drawSparklineRaw(canvas, values, opts);
    }

    function drawSparklineRaw(canvas, values, opts) {
        if (!canvas || !canvas.getContext) return;

        // HiDPI: draw at devicePixelRatio and scale back down via CSS.
        var dpr = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        var w = Math.max(1, Math.floor(rect.width  * dpr));
        var h = Math.max(1, Math.floor(rect.height * dpr));
        canvas.width  = w;
        canvas.height = h;
        var ctx = canvas.getContext('2d');
        ctx.clearRect(0, 0, w, h);

        var pad = 2 * dpr;
        var min = Math.min.apply(null, values);
        var max = Math.max.apply(null, values);
        var range = max - min || 1;

        var stepX = (w - pad * 2) / (values.length - 1);
        var points = values.map(function (v, i) {
            var x = pad + i * stepX;
            var y = pad + (h - pad * 2) * (1 - (v - min) / range);
            return [x, y];
        });

        // Area fill under the line — subtle, brand-tinted.
        var color = opts.color || '#005A2B';
        var grad = ctx.createLinearGradient(0, 0, 0, h);
        grad.addColorStop(0, hexAlpha(color, 0.22));
        grad.addColorStop(1, hexAlpha(color, 0));

        ctx.beginPath();
        points.forEach(function (p, i) {
            if (i === 0) ctx.moveTo(p[0], p[1]);
            else ctx.lineTo(p[0], p[1]);
        });
        ctx.lineTo(points[points.length - 1][0], h - pad);
        ctx.lineTo(points[0][0], h - pad);
        ctx.closePath();
        ctx.fillStyle = grad;
        ctx.fill();

        // Line on top.
        ctx.beginPath();
        points.forEach(function (p, i) {
            if (i === 0) ctx.moveTo(p[0], p[1]);
            else ctx.lineTo(p[0], p[1]);
        });
        ctx.lineWidth = 1.6 * dpr;
        ctx.strokeStyle = color;
        ctx.lineJoin = 'round';
        ctx.stroke();

        // End dot.
        var last = points[points.length - 1];
        ctx.beginPath();
        ctx.arc(last[0], last[1], 2.4 * dpr, 0, Math.PI * 2);
        ctx.fillStyle = color;
        ctx.fill();
    }

    function hexAlpha(hex, a) {
        var h = String(hex || '').trim();
        if (h.startsWith('#') && (h.length === 7 || h.length === 4)) {
            if (h.length === 4) h = '#' + h[1] + h[1] + h[2] + h[2] + h[3] + h[3];
            var r = parseInt(h.slice(1, 3), 16);
            var g = parseInt(h.slice(3, 5), 16);
            var b = parseInt(h.slice(5, 7), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
        }
        // Fallback: rely on the browser to accept color-mix.
        return h;
    }

    // ------------------------------------------------------------------
    // Period pill
    // ------------------------------------------------------------------
    // Preset -> (start, end, previousStart, previousEnd, label) helpers.
    // All dates returned as YYYY-MM-DD strings for the controllers.
    function iso(d) { return d.toISOString().slice(0, 10); }
    function dayShift(d, days) { var x = new Date(d); x.setDate(x.getDate() + days); return x; }
    function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
    function startOfQuarter(d) {
        var q = Math.floor(d.getMonth() / 3);
        return new Date(d.getFullYear(), q * 3, 1);
    }
    function endOfMonth(d) { return new Date(d.getFullYear(), d.getMonth() + 1, 0); }

    function rangeFor(preset) {
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var start, end = today, prevStart, prevEnd, label;
        switch (preset) {
            case 'today': {
                start = today;
                prevStart = dayShift(today, -1);
                prevEnd   = dayShift(today, -1);
                label = "Aujourd'hui";
                break;
            }
            case '7d': {
                start = dayShift(today, -6);
                prevStart = dayShift(start, -7);
                prevEnd   = dayShift(start, -1);
                label = '7 derniers jours';
                break;
            }
            case 'month': {
                start = startOfMonth(today);
                var prevMonthEnd = dayShift(start, -1);
                prevStart = startOfMonth(prevMonthEnd);
                prevEnd   = prevMonthEnd;
                label = 'Ce mois';
                break;
            }
            case 'quarter': {
                start = startOfQuarter(today);
                var prevQEnd = dayShift(start, -1);
                prevStart = startOfQuarter(prevQEnd);
                prevEnd   = prevQEnd;
                label = 'Ce trimestre';
                break;
            }
            case 'ytd': {
                start = new Date(today.getFullYear(), 0, 1);
                // "Same day of YTD last year": the controller wants the same
                // number of days into the year, ending at day-of-year - 1.
                var dayOfYear = Math.floor((today - start) / 86400000);
                prevStart = new Date(today.getFullYear() - 1, 0, 1);
                prevEnd   = dayShift(prevStart, dayOfYear);
                label = "Année en cours";
                break;
            }
            default:
                return null;
        }
        return {
            preset:      preset,
            label:       label,
            start:       iso(start),
            end:         iso(end),
            previousStart: iso(prevStart),
            previousEnd:   iso(prevEnd),
        };
    }

    /**
     * Render the segmented period picker into `el`.
     *
     *   const pill = LPC.kpi.mountPeriodPill('#lpc-period', {
     *       presets: ['today','7d','month','quarter','ytd','custom'],
     *       default: 'ytd',
     *       onChange: (range) => fetchDashboard(range)
     *   });
     *
     * The picker also dispatches an `lpc:period-change` event on its own
     * container so a dashboard can `addEventListener` instead of passing a
     * callback — same shape as `detail = range`.
     */
    function mountPeriodPill(target, opts) {
        opts = opts || {};
        var host = typeof target === 'string' ? document.querySelector(target) : target;
        if (!host) return null;

        var presets = opts.presets || ['today', '7d', 'month', 'quarter', 'ytd', 'custom'];
        var defaultPreset = opts.default || 'ytd';

        var labels = {
            today:   "Aujourd'hui",
            '7d':    '7 jours',
            month:   'Ce mois',
            quarter: 'Trimestre',
            ytd:     'YTD',
        };

        var segsHtml = presets.map(function (p) {
            if (p === 'custom') {
                return '<button type="button" class="lpc-period-pill__seg lpc-period-pill__seg--custom" data-preset="custom">Personnalisé ' + ICONS.caret + '</button>';
            }
            return '<button type="button" class="lpc-period-pill__seg" data-preset="' + p + '">' + labels[p] + '</button>';
        }).join('');

        host.classList.add('lpc-period-pill');
        host.innerHTML = segsHtml + '<div class="lpc-period-pop" role="dialog" aria-label="Période personnalisée">' +
            '<label for="lpc-period-start">Du</label>' +
            '<input type="date" id="lpc-period-start">' +
            '<label for="lpc-period-end">Au</label>' +
            '<input type="date" id="lpc-period-end">' +
            '<div class="lpc-period-pop__actions">' +
                '<button type="button" data-role="cancel">Annuler</button>' +
                '<button type="button" class="is-primary" data-role="apply">Appliquer</button>' +
            '</div>' +
        '</div>';

        var pop = host.querySelector('.lpc-period-pop');
        var startI = pop.querySelector('#lpc-period-start');
        var endI   = pop.querySelector('#lpc-period-end');
        var current = null;

        function activate(preset) {
            host.querySelectorAll('.lpc-period-pill__seg').forEach(function (s) {
                s.classList.toggle('is-active', s.dataset.preset === preset);
            });
        }
        function emit(range) {
            current = range;
            if (typeof opts.onChange === 'function') opts.onChange(range);
            host.dispatchEvent(new CustomEvent('lpc:period-change', { detail: range, bubbles: true }));
        }

        host.querySelectorAll('.lpc-period-pill__seg').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var preset = btn.dataset.preset;
                if (preset === 'custom') {
                    pop.classList.toggle('is-open');
                    e.stopPropagation();
                    return;
                }
                pop.classList.remove('is-open');
                activate(preset);
                emit(rangeFor(preset));
            });
        });

        pop.querySelector('[data-role="cancel"]').addEventListener('click', function () {
            pop.classList.remove('is-open');
        });
        pop.querySelector('[data-role="apply"]').addEventListener('click', function () {
            var s = startI.value, e = endI.value;
            if (!s || !e) return;
            if (s > e) { var t = s; s = e; e = t; }
            pop.classList.remove('is-open');
            activate('custom');
            // Previous-period baseline for a custom range is the immediately
            // preceding same-length window (README §5.8, delta rule).
            var days = Math.round((new Date(e) - new Date(s)) / 86400000);
            var prevEnd = new Date(new Date(s).getTime() - 86400000);
            var prevStart = new Date(prevEnd.getTime() - days * 86400000);
            emit({
                preset:        'custom',
                label:         'Du ' + s + ' au ' + e,
                start:         s,
                end:           e,
                previousStart: iso(prevStart),
                previousEnd:   iso(prevEnd),
            });
        });

        // Click-outside closes the popover.
        document.addEventListener('click', function (e) {
            if (!host.contains(e.target)) pop.classList.remove('is-open');
        });

        // Fire the initial default so the dashboard starts with data.
        activate(defaultPreset);
        emit(rangeFor(defaultPreset));

        return {
            get current() { return current; },
            setPreset: function (p) {
                if (p === 'custom') return;
                activate(p);
                emit(rangeFor(p));
            }
        };
    }

    // ------------------------------------------------------------------
    // Drilldown modal — the LPC.modal.custom body every KPI opens.
    // ------------------------------------------------------------------
    /**
     * Open the standardised KPI drilldown.
     *
     *   LPC.kpi.openDrilldown({
     *       title:     'Chiffre d'affaires',
     *       url:       '/api/v1/md_dashboard_api.php?action=drilldown_revenue',
     *       // Optional trend series shown as a small chart above the table.
     *       trend:     { url: same, dataPath: 'data.trend', totalLabel: '12,3 M F' },
     *       columns:   ['Client', 'Montant', 'Date'],
     *       renderRow: (row) => LPC.html`<tr>…</tr>`,
     *       csv:       true,                             // enable CSV export button
     *       deeplink:  { href: '/modules/accounting/invoices.php?from=dashboard_md', label: 'Voir la facturation' }
     *   });
     */
    function openDrilldown(cfg) {
        if (!window.LPC || !LPC.modal || typeof LPC.modal.custom !== 'function') {
            console.warn('[LPC.kpi] LPC.modal.custom unavailable — cannot open drilldown.');
            return;
        }
        if (!window.LPC.paginator || typeof LPC.paginator.attach !== 'function') {
            console.warn('[LPC.kpi] LPC.paginator.attach unavailable — include lpc-paginator.js on the page.');
        }

        var uid = 'lpc-kpi-drill-' + Math.random().toString(36).slice(2, 8);
        var trendId  = uid + '-trend';
        var searchId = uid + '-search';
        var tbodyId  = uid + '-tbody';
        var csvId    = uid + '-csv';

        var headers = (cfg.columns || []).map(function (c) {
            return '<th>' + escapeHtml(c) + '</th>';
        }).join('');

        var trendHtml = '';
        if (cfg.trend) {
            trendHtml =
                '<div class="lpc-kpi-drill__trend">' +
                    '<div class="lpc-kpi-drill__trend-head">' +
                        '<span>Évolution de la période</span>' +
                        '<strong>' + escapeHtml(cfg.trend.totalLabel || '') + '</strong>' +
                    '</div>' +
                    '<div class="lpc-kpi-drill__trend-chart" id="' + trendId + '"><canvas></canvas></div>' +
                '</div>';
        }

        var toolsHtml = '';
        if (cfg.search !== false || cfg.csv) {
            toolsHtml = '<div class="lpc-kpi-drill__tools">';
            if (cfg.search !== false) {
                toolsHtml += '<input type="search" id="' + searchId + '" placeholder="Rechercher…" aria-label="Filtrer">';
            }
            if (cfg.csv) {
                toolsHtml += '<button type="button" class="lpc-kpi-drill__csv" id="' + csvId + '">' + ICONS.download + '<span>Exporter CSV</span></button>';
            }
            toolsHtml += '</div>';
        }

        var linkHtml = '';
        if (cfg.deeplink && cfg.deeplink.href) {
            linkHtml =
                '<a class="lpc-kpi-drill__deeplink" href="' + escapeAttr(cfg.deeplink.href) + '">' +
                    '<span>' + escapeHtml(cfg.deeplink.label || 'Voir tout') + '</span>' + ICONS.arrowRight +
                '</a>';
        }

        var body =
            '<div class="lpc-kpi-drill">' +
                trendHtml +
                toolsHtml +
                '<div class="lpc-kpi-drill__table">' +
                    '<table>' +
                        '<thead><tr>' + headers + '</tr></thead>' +
                        '<tbody id="' + tbodyId + '"></tbody>' +
                    '</table>' +
                '</div>' +
                linkHtml +
            '</div>';

        var modalPromise = LPC.modal.custom({
            title: cfg.title || 'Détail',
            bodyHtml: body,
            buttons: [{ label: 'Fermer', value: null, primary: true }],
            dismissable: true
        });

        var tbody = document.getElementById(tbodyId);
        if (tbody && cfg.url && window.LPC && LPC.paginator) {
            LPC.paginator.attach(tbody, {
                url:          cfg.url,
                dataPath:     cfg.dataPath     || 'data.rows',
                envelopePath: cfg.envelopePath || 'data.pagination',
                renderRow:    cfg.renderRow    || function (row) { return LPC.html`<tr><td>${JSON.stringify(row)}</td></tr>`; },
                searchInput:  document.getElementById(searchId),
                pageSize:     cfg.pageSize || 25,
                colspan:      (cfg.columns || []).length,
                emptyMsg:     cfg.emptyMsg || 'Aucun résultat pour cette période.',
            });
        }

        if (cfg.csv) {
            var csvBtn = document.getElementById(csvId);
            var searchIn = document.getElementById(searchId);
            csvBtn && csvBtn.addEventListener('click', function () {
                var url = new URL(cfg.url, window.location.origin);
                url.searchParams.set('format', 'csv');
                var q = searchIn && searchIn.value ? searchIn.value.trim() : '';
                if (q) url.searchParams.set('q', q);
                // Download in a hidden iframe so the modal stays open.
                var iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = url.toString();
                document.body.appendChild(iframe);
                setTimeout(function () { try { iframe.remove(); } catch (e) {} }, 20000);
            });
        }

        // Trend chart. Uses Chart.js if present; falls back to the raw
        // sparkline otherwise.
        if (cfg.trend && cfg.trend.data) {
            requestAnimationFrame(function () {
                var host = document.getElementById(trendId);
                if (!host) return;
                var canvas = host.querySelector('canvas');
                drawSparklineRaw(canvas, cfg.trend.data, { color: cfg.trend.color });
            });
        }

        return modalPromise;
    }

    // ------------------------------------------------------------------
    // HTML-escape helpers (mirror lpc-dom.js; kept local so lpc-kpi.js
    // works even if lpc-dom.js is not on the page for some future callsite).
    // ------------------------------------------------------------------
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function escapeAttr(s) { return escapeHtml(s); }

    // ------------------------------------------------------------------
    // Public surface
    // ------------------------------------------------------------------
    window.LPC.kpi = {
        mount:            mount,
        update:           update,
        setLoading:       setLoading,
        formatCompact:    formatCompact,
        formatFcfaCompact: formatFcfaCompact,
        formatFcfaFull:   formatFcfaFull,
        formatPercent:    formatPercent,
        mountPeriodPill:  mountPeriodPill,
        rangeFor:         rangeFor,
        openDrilldown:    openDrilldown,
        countUp:          countUp,
        drawSparkline:    drawSparklineRaw,
        __loaded: true
    };
})();
