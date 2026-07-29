/**
 * assets/js/modules/dashboard-sales.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — modules/dashboard/views/sales_dashboard.php.
 *
 * TWO RULES THIS FILE EXISTS TO KEEP
 * ----------------------------------
 * 1. A missing target is NOT zero. The API returns null when
 *    `performance_targets` has no row for the period, and every renderer below
 *    branches on null to print "Objectif non défini". A progress bar pinned at
 *    0 % and a progress bar with no target behind it look identical to a
 *    salesperson, and one of them is a lie. Same for the empties rate: null
 *    means "nothing ever went out", not "perfect return rate".
 *
 * 2. There is no mock-data fallback. dashboard-finance.js catches a failed
 *    fetch and renders invented figures "to preserve the UI"; that is the
 *    §5.8 failure mode with extra steps. Here a failure shows the error banner
 *    and leaves every figure blank.
 *
 * Rendering: LPC.html`…` escapes every ${…}. The only raw slots are badges this
 * file generates itself, wrapped in LPC.raw() so review can grep for them.
 * Money always goes through LPC.fmt.fcfa.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ENDPOINT = '/api/v1/sales_dashboard_controller.php';
    var EMPTY    = '—';

    var monthlyChart = null;
    var segmentChart = null;

    var MONTHS_SHORT = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin',
                        'Juil', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function $(id) { return document.getElementById(id); }

    /** null/undefined-safe percentage. Returns null when there is no denominator. */
    function pct(actual, target) {
        if (target === null || target === undefined || Number(target) <= 0) return null;
        return (Number(actual) / Number(target)) * 100;
    }

    /** Clamp a percentage to a bar width. */
    function barWidth(p) {
        if (p === null) return '0%';
        return Math.max(0, Math.min(100, p)).toFixed(1) + '%';
    }

    function setBar(el, p) { if (el) el.style.width = barWidth(p); }

    /**
     * The one place "how do we say we don't know" is decided.
     * Used by every card that depends on performance_targets.
     */
    function targetLine(actual, target, formatter) {
        if (target === null || target === undefined) {
            return 'Objectif non défini';
        }
        var p = pct(actual, target);
        return 'Objectif ' + formatter(target) +
               (p === null ? '' : ' · ' + p.toFixed(0) + ' % atteint');
    }

    function showError(msg) {
        var box = $('sd-error');
        if (!box) return;
        var span = $('sd-error-msg');
        // textContent, not innerHTML — msg originates from an API response.
        if (span) span.textContent = msg || "Le tableau de bord n'a pas pu être chargé.";
        box.classList.remove('hidden');
    }

    function hideError() {
        var box = $('sd-error');
        if (box) box.classList.add('hidden');
    }

    // -------------------------------------------------------------------------
    // KPI cards
    // -------------------------------------------------------------------------

    function renderKpis(d) {
        var k = d.kpis;

        // --- 1. CA du mois ---------------------------------------------------
        var rev = k.revenue;
        LPC.setText($('kpi-revenue'), LPC.fmt.fcfa(rev.actual));
        setBar($('kpi-revenue-bar'), pct(rev.actual, rev.target));
        LPC.setText($('kpi-revenue-sub'),
            targetLine(rev.actual, rev.target, LPC.fmt.fcfa) +
            ' · ' + rev.order_count + ' commande' + (rev.order_count === 1 ? '' : 's'));

        // --- 2. Volume 20L / 1,5L -------------------------------------------
        var v = k.volume;
        LPC.setText($('kpi-vol-20l'), LPC.fmt.int(v.actual_20l) + ' × 20L');
        setBar($('kpi-vol-20l-bar'), pct(v.actual_20l, v.target_20l));
        LPC.setText($('kpi-vol-20l-sub'), targetLine(v.actual_20l, v.target_20l, LPC.fmt.int));

        LPC.setText($('kpi-vol-15l'), LPC.fmt.int(v.actual_1_5l) + ' × 1,5L');
        setBar($('kpi-vol-15l-bar'), pct(v.actual_1_5l, v.target_1_5l));
        LPC.setText($('kpi-vol-15l-sub'), targetLine(v.actual_1_5l, v.target_1_5l, LPC.fmt.int));

        // --- 3. En attente de dispatch --------------------------------------
        var disp = k.dispatch;
        LPC.setText($('kpi-dispatch'), LPC.fmt.int(disp.count));
        var dispSub = LPC.fmt.fcfa(disp.amount) + ' à expédier';
        if (disp.oldest_date) {
            dispSub += ' · plus ancienne : ' + LPC.fmt.date(disp.oldest_date);
        }
        LPC.setText($('kpi-dispatch-sub'), disp.count > 0 ? dispSub : 'Rien en attente');

        // --- 4. Encours en retard -------------------------------------------
        var ar = k.receivables;
        LPC.setText($('kpi-overdue'), LPC.fmt.fcfa(ar.amount));
        LPC.setText($('kpi-overdue-sub'),
            ar.count > 0
                ? ar.count + ' facture' + (ar.count === 1 ? '' : 's') + ' échue' + (ar.count === 1 ? '' : 's')
                : 'Aucune facture échue');

        // --- 5. Dette d'emballages ------------------------------------------
        // rate === null means nothing has ever gone out for this book of
        // clients, so the ratio is undefined. It must not render as 0 %.
        var e = k.empties;
        var rail = $('kpi-empties-rail');
        var bar  = $('kpi-empties-bar');
        var over = (e.rate !== null && e.max_rate !== null && e.rate > e.max_rate);

        LPC.setText($('kpi-empties'), e.rate === null ? EMPTY : LPC.fmt.percent(e.rate, 1));

        if (e.rate === null) {
            setBar(bar, null);
            LPC.setText($('kpi-empties-sub'), 'Aucun emballage sorti — taux non calculable');
        } else {
            // The bar is drawn against the ceiling when there is one, so a bar
            // that fills up means "at the limit" rather than an abstract 100 %.
            setBar(bar, e.max_rate !== null ? pct(e.rate, e.max_rate) : e.rate);
            LPC.setText($('kpi-empties-sub'),
                LPC.fmt.int(e.owed) + ' vides dus sur ' + LPC.fmt.int(e.out) + ' sortis' +
                (e.max_rate === null ? ' · seuil non défini'
                                     : ' · seuil ' + LPC.fmt.percent(e.max_rate, 1)));
        }

        // Red only when we can actually prove the ceiling is breached.
        if (rail) rail.className = 'absolute top-0 left-0 w-1 h-full ' + (over ? 'bg-red-500' : 'bg-emerald-500');
        if (bar)  bar.className  = 'h-full rounded-full transition-all duration-500 ' + (over ? 'bg-red-500' : 'bg-emerald-500');
        var eNum = $('kpi-empties');
        if (eNum) eNum.className = 'text-2xl font-extrabold ' + (over ? 'text-red-600' : 'text-gray-900');
    }

    // -------------------------------------------------------------------------
    // Charts
    // -------------------------------------------------------------------------

    function renderMonthlyChart(charts) {
        var canvas = $('salesMonthlyChart');
        if (!canvas || typeof Chart === 'undefined') return;
        if (monthlyChart) monthlyChart.destroy();

        monthlyChart = new Chart(canvas.getContext('2d'), {
            data: {
                labels: MONTHS_SHORT,
                datasets: [
                    {
                        type: 'bar',
                        label: 'CA réalisé',
                        data: charts.monthly.actual,
                        backgroundColor: 'rgba(0, 90, 43, 0.85)',
                        borderRadius: 6,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Objectif',
                        // Nulls are passed through deliberately: spanGaps stays
                        // false so a month with no target draws a GAP, not a
                        // line dropping to the axis (which would read as
                        // "target = 0").
                        data: charts.monthly.target,
                        borderColor: '#8CC63F',
                        backgroundColor: '#8CC63F',
                        borderWidth: 2,
                        borderDash: [6, 4],
                        pointRadius: 3,
                        spanGaps: false,
                        tension: 0.25,
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.parsed.y === null) return ctx.dataset.label + ' : non défini';
                                return ctx.dataset.label + ' : ' + LPC.fmt.fcfa(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: function (v) { return LPC.fmt.int(v); } }
                    }
                }
            }
        });
    }

    function renderSegmentChart(charts) {
        var canvas = $('salesSegmentChart');
        if (!canvas || typeof Chart === 'undefined') return;
        if (segmentChart) segmentChart.destroy();

        var a = charts.segments.actual;
        var t = charts.segments.target;

        // "Non classé" only earns a bar when it actually holds revenue —
        // otherwise it is noise on a chart that has two real categories.
        var labels  = ['B2B', 'B2C'];
        var actuals = [a.B2B, a.B2C];
        var targets = [t.B2B, t.B2C];

        var unclassified = Number(a.NON_CLASSE || 0);
        if (unclassified > 0) {
            labels.push('Non classé');
            actuals.push(unclassified);
            targets.push(null);           // there is no target for a non-segment
        }

        var warn = $('sd-segment-warning');
        if (warn) warn.classList.toggle('hidden', unclassified <= 0);

        segmentChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Réalisé',  data: actuals, backgroundColor: 'rgba(0, 90, 43, 0.85)', borderRadius: 6 },
                    { label: 'Objectif', data: targets, backgroundColor: 'rgba(140, 198, 63, 0.55)', borderRadius: 6 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.parsed.y === null) return ctx.dataset.label + ' : non défini';
                                return ctx.dataset.label + ' : ' + LPC.fmt.fcfa(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: function (v) { return LPC.fmt.int(v); } }
                    }
                }
            }
        });
    }

    // -------------------------------------------------------------------------
    // Tables
    // -------------------------------------------------------------------------

    function renderTopClients(rows) {
        var tbody = $('sd-top-clients');
        if (!tbody) return;

        if (!rows || !rows.length) {
            tbody.innerHTML = LPC.html`
                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-400 text-sm">
                    Aucune vente sur cette période.
                </td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map(function (r) {
            return LPC.html`
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3">
                        <div class="font-semibold text-gray-900">${r.name}</div>
                        <div class="text-[11px] text-gray-400">${r.lpc_code}</div>
                    </td>
                    <td class="px-5 py-3 text-right font-bold text-gray-900">${LPC.fmt.fcfa(r.revenue)}</td>
                    <td class="px-5 py-3 text-right text-gray-600">${LPC.fmt.int(r.volume)}</td>
                    <td class="px-5 py-3 text-gray-600">${LPC.fmt.date(r.last_order_date)}</td>
                </tr>`;
        }).join('');
    }

    function renderDormant(rows) {
        var tbody = $('sd-dormant');
        if (!tbody) return;

        if (!rows || !rows.length) {
            tbody.innerHTML = LPC.html`
                <tr><td colspan="4" class="px-5 py-8 text-center text-gray-400 text-sm">
                    Aucun client dormant — tout le portefeuille a commandé dans les 30 derniers jours.
                </td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map(function (r) {
            var days = Number(r.days_since);

            // Badge markup is generated here from a numeric comparison — no API
            // string reaches it — so it is a legitimate LPC.raw() slot.
            var tone  = days >= 60 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';
            var label = days >= 60 ? '60 j+' : '30 j+';
            var badge = LPC.raw(
                '<span class="px-2 py-0.5 rounded-full text-[11px] font-bold ' + tone + '">' + label + '</span>'
            );

            // Deep link per README §5.8: client_id + client + a `from` KEY (never
            // a URL). 'dash_sales' is registered in lpc-deeplink.js ORIGINS.
            var href = '/modules/crm/clients.php?client_id=' + encodeURIComponent(r.id) +
                       '&client=' + encodeURIComponent(r.name) +
                       '&from=dash_sales';

            return LPC.html`
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-5 py-3">
                        <a href="${href}" class="font-semibold text-lpc-dark hover:underline">${r.name}</a>
                        <div class="text-[11px] text-gray-400">${r.lpc_code}${r.phone ? ' · ' + r.phone : ''}</div>
                    </td>
                    <td class="px-5 py-3">${badge} <span class="text-gray-600">${days} j</span></td>
                    <td class="px-5 py-3 text-gray-600">${LPC.fmt.date(r.last_order_date)}</td>
                    <td class="px-5 py-3 text-right text-gray-600">${LPC.fmt.fcfa(r.lifetime_revenue)}</td>
                </tr>`;
        }).join('');
    }

    // -------------------------------------------------------------------------
    // Fetch + wire-up
    // -------------------------------------------------------------------------

    function currentParams() {
        var main = $('main');
        var m = $('sd-month');
        var y = $('sd-year');
        var s = $('sd-scope');
        return {
            month: m ? m.value : (main ? main.dataset.sdMonth : ''),
            year:  y ? y.value : (main ? main.dataset.sdYear  : ''),
            scope: s ? s.value : 'me'
        };
    }

    async function load() {
        var p = currentParams();
        var qs = new URLSearchParams({
            action: 'overview',
            year:   p.year,
            month:  p.month,
            scope:  p.scope
        });

        hideError();

        try {
            var res = await fetch(ENDPOINT + '?' + qs.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin'
            });

            if (res.status === 403) {
                showError("Vous n'avez pas la permission de consulter ce tableau de bord.");
                return;
            }
            if (!res.ok) {
                showError('Le serveur a répondu ' + res.status + '.');
                return;
            }

            var payload = await res.json();
            if (!payload || payload.status !== 'success') {
                showError((payload && payload.message) || 'Réponse inattendue du serveur.');
                return;
            }

            var d = payload.data;

            var banner = $('sd-no-targets');
            if (banner) banner.classList.toggle('hidden', !!d.meta.has_targets);

            LPC.setText($('sd-chart-year'), d.meta.year);

            renderKpis(d);
            renderMonthlyChart(d.charts);
            renderSegmentChart(d.charts);
            renderTopClients(d.tables.top_clients);
            renderDormant(d.tables.dormant);

        } catch (err) {
            // Deliberately no mock fallback — see the file header.
            console.error('[dashboard-sales] load failed', err);
            showError('Impossible de joindre le serveur.');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        ['sd-month', 'sd-year', 'sd-scope'].forEach(function (id) {
            var el = $(id);
            if (el) el.addEventListener('change', load);
        });

        var dispatchCard = $('kpi-dispatch-card');
        if (dispatchCard) {
            dispatchCard.addEventListener('click', function () {
                window.location.href = '/modules/sales/orders.php#dispatch';
            });
        }

        var overdueCard = $('kpi-overdue-card');
        if (overdueCard) {
            overdueCard.addEventListener('click', function () {
                window.location.href = '/modules/accounting/invoices.php#invoices_payments';
            });
        }

        load();
    });
})();
