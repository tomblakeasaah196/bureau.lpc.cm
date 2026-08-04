/**
 * assets/js/modules/dashboard-sales.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — "Ma Performance" scorecard, wired to LPC.kpi as of Sprint 7H.
 *
 * TWO RULES THIS FILE EXISTS TO KEEP
 * ----------------------------------
 * 1. A missing target is NOT zero. The API returns null when
 *    `performance_targets` has no row for the period; every KPI patch below
 *    branches on null so the card renders 'Objectif non défini' or drops the
 *    attainment chip. A progress bar pinned at 0 % and a progress bar with no
 *    target behind it look identical to a salesperson, and one of them is a
 *    lie. Same for the empties rate: null means "nothing ever went out", not
 *    "perfect return rate".
 *
 * 2. There is no mock-data fallback. dashboard-finance.js used to catch a
 *    failed fetch and render invented figures "to preserve the UI"; that is
 *    the §5.8 failure mode with extra steps. Here a failure shows the error
 *    banner and leaves every card empty.
 *
 * Rendering goes through LPC.kpi.update for the 5 cards and LPC.html`` for the
 * two tables (which auto-escape every ${…}). The only LPC.raw() slot is the
 * dormant-client badge — a numeric threshold produces the tone/label, so no
 * API string reaches innerHTML.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    var ENDPOINT = '/api/v1/sales_dashboard_controller.php';

    var monthlyChart = null;
    var segmentChart = null;

    var MONTHS_SHORT = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin',
                        'Juil', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

    // -------------------------------------------------------------------------
    // KPI spec — mounted once. Values arrive via LPC.kpi.update from load().
    // -------------------------------------------------------------------------
    var KPI_SPECS = [
        {
            id: 'sd-revenue', label: 'Mon CA du mois', kind: 'fcfa', rail: 'brand',
            drill: 'revenue',
            drillCfg: {
                title: 'Chiffre d\'affaires — commandes de la période',
                url:   ENDPOINT + '?action=drilldown_revenue',
                columns: ['Date', 'Commande', 'Client', 'Statut', 'Total FCFA'],
                csv: true,
                deeplink: { href: '/modules/sales/orders.php?from=dashboard_sales', label: 'Ouvrir les ventes' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${LPC.fmt.date(r.date)}</td>
                        <td>${r.reference || ''}</td>
                        <td>${r.client_name || ''}</td>
                        <td>${r.status || ''}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.total_amount)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'sd-vol-20l', label: 'Volume 20L', kind: 'int', rail: 'info',
            drill: 'vol_20l',
            drillCfg: {
                title: 'Volume 20L — par client',
                url:   ENDPOINT + '?action=drilldown_vol_20l',
                columns: ['Client', 'Commandes', 'Bouteilles 20L'],
                csv: true,
                deeplink: { href: '/modules/sales/orders.php?from=dashboard_sales', label: 'Ouvrir les ventes' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.client_name || ''}</td>
                        <td class="text-right">${LPC.fmt.int(r.order_count)}</td>
                        <td class="text-right">${LPC.fmt.int(r.quantity)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'sd-vol-15l', label: 'Volume 1,5L', kind: 'int', rail: 'info',
            drill: 'vol_15l',
            drillCfg: {
                title: 'Volume 1,5L — par client',
                url:   ENDPOINT + '?action=drilldown_vol_15l',
                columns: ['Client', 'Commandes', 'Bouteilles 1,5L'],
                csv: true,
                deeplink: { href: '/modules/sales/orders.php?from=dashboard_sales', label: 'Ouvrir les ventes' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.client_name || ''}</td>
                        <td class="text-right">${LPC.fmt.int(r.order_count)}</td>
                        <td class="text-right">${LPC.fmt.int(r.quantity)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'sd-dispatch', label: 'En attente de dispatch', kind: 'int', rail: 'warn',
            drill: 'dispatch',
            drillCfg: {
                title: 'Commandes en attente de dispatch',
                url:   ENDPOINT + '?action=drilldown_dispatch',
                columns: ['Date', 'Commande', 'Client', 'Montant', 'Statut'],
                csv: true,
                deeplink: { href: '/modules/sales/orders.php#dispatch&from=dashboard_sales', label: 'Ouvrir le dispatch' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${LPC.fmt.date(r.date)}</td>
                        <td>${r.reference || ''}</td>
                        <td>${r.client_name || ''}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.total_amount)}</td>
                        <td>${r.status || ''}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'sd-overdue', label: 'Encours en retard', kind: 'fcfa', rail: 'alert',
            drill: 'overdue',
            drillCfg: {
                title: 'Factures échues de mes clients',
                url:   ENDPOINT + '?action=drilldown_overdue',
                columns: ['Client', 'Facture', 'Échéance', 'Retard (j)', 'Solde'],
                csv: true,
                deeplink: { href: '/modules/accounting/invoices.php?status=overdue&from=dashboard_sales', label: 'Voir les factures' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.client_name || ''}</td>
                        <td>${r.invoice_number || ''}</td>
                        <td>${LPC.fmt.date(r.due_date)}</td>
                        <td class="text-right">${r.days_overdue}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.balance)}</td>
                    </tr>`;
                },
            },
        },
    ];

    // -------------------------------------------------------------------------
    // Card patch builders — transform the controller's kpis shape into
    // LPC.kpi.update patches. Every branch on `null` preserves rule #1.
    // -------------------------------------------------------------------------

    function attainmentDelta(actual, target) {
        if (target === null || target === undefined || target <= 0) return { kind: 'none' };
        var pct = (Number(actual) / Number(target)) * 100;
        return {
            kind:      'attainment',
            value:     Math.round(pct * 10) / 10,
            label:     pct.toFixed(1).replace('.', ',') + ' % de la cible',
            sentiment: pct >= 80 ? 'good' : 'neutral',
        };
    }

    function updateRevenue(k) {
        var rev = k.revenue;
        LPC.kpi.update({
            id: 'sd-revenue',
            value: rev.actual,
            delta: attainmentDelta(rev.actual, rev.target),
            sub:   rev.target === null
                     ? 'Objectif non défini · ' + rev.order_count + ' commande' + (rev.order_count === 1 ? '' : 's')
                     : 'Cible ' + LPC.fmt.fcfa(rev.target) + ' · ' + rev.order_count + ' cmd',
        });
    }

    function updateVolumes(k) {
        var v = k.volume;
        LPC.kpi.update({
            id: 'sd-vol-20l',
            value: v.actual_20l,
            delta: attainmentDelta(v.actual_20l, v.target_20l),
            sub:   v.target_20l === null
                     ? 'Objectif non défini'
                     : 'Cible ' + LPC.fmt.int(v.target_20l) + ' × 20L',
        });
        LPC.kpi.update({
            id: 'sd-vol-15l',
            value: v.actual_1_5l,
            delta: attainmentDelta(v.actual_1_5l, v.target_1_5l),
            sub:   v.target_1_5l === null
                     ? 'Objectif non défini'
                     : 'Cible ' + LPC.fmt.int(v.target_1_5l) + ' × 1,5L',
        });
    }

    function updateDispatch(k) {
        var disp = k.dispatch;
        var sub = disp.count > 0
                    ? LPC.fmt.fcfa(disp.amount) + ' à expédier'
                    : 'Rien en attente';
        if (disp.count > 0 && disp.oldest_date) {
            sub += ' · plus ancienne : ' + LPC.fmt.date(disp.oldest_date);
        }
        LPC.kpi.update({
            id: 'sd-dispatch',
            value: disp.count,
            delta: disp.count > 0
                     ? { kind: 'change', value: null, label: 'À traiter', sentiment: 'bad', direction: null }
                     : { kind: 'change', value: null, label: 'Rien à faire', sentiment: 'good', direction: null },
            sub: sub,
        });
    }

    function updateOverdue(k) {
        var ar = k.receivables;
        LPC.kpi.update({
            id: 'sd-overdue',
            value: ar.amount,
            delta: ar.count > 0
                     ? { kind: 'change', value: null, label: ar.count + ' facture' + (ar.count === 1 ? '' : 's'), sentiment: 'bad', direction: null }
                     : { kind: 'change', value: null, label: 'Aucune échue', sentiment: 'good', direction: null },
            sub: ar.count > 0
                   ? ar.count + ' facture' + (ar.count === 1 ? '' : 's') + ' échue' + (ar.count === 1 ? '' : 's')
                   : 'Aucune facture échue',
        });
    }

    // -------------------------------------------------------------------------
    // Charts — same as pre-7H. Null preserved through the target line so a
    // month with no target draws a GAP, not a line pinned to the axis.
    // -------------------------------------------------------------------------
    function renderMonthlyChart(charts) {
        var canvas = document.getElementById('salesMonthlyChart');
        if (!canvas || typeof Chart === 'undefined') return;
        if (monthlyChart) monthlyChart.destroy();
        monthlyChart = new Chart(canvas.getContext('2d'), {
            data: {
                labels: MONTHS_SHORT,
                datasets: [
                    { type: 'bar',  label: 'CA réalisé', data: charts.monthly.actual,
                      backgroundColor: 'rgba(0, 90, 43, 0.85)', borderRadius: 6, order: 2 },
                    { type: 'line', label: 'Objectif',   data: charts.monthly.target,
                      borderColor: '#8CC63F', backgroundColor: '#8CC63F',
                      borderWidth: 2, borderDash: [6, 4], pointRadius: 3,
                      spanGaps: false, tension: 0.25, order: 1 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 700 },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.parsed.y === null) return ctx.dataset.label + ' : non défini';
                                return ctx.dataset.label + ' : ' + LPC.fmt.fcfa(ctx.parsed.y);
                            },
                        },
                    },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function (v) { return LPC.fmt.int(v); } } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    function renderSegmentChart(charts) {
        var canvas = document.getElementById('salesSegmentChart');
        if (!canvas || typeof Chart === 'undefined') return;
        if (segmentChart) segmentChart.destroy();

        var a = charts.segments.actual;
        var t = charts.segments.target;

        var labels  = ['B2B', 'B2C'];
        var actuals = [a.B2B, a.B2C];
        var targets = [t.B2B, t.B2C];

        var unclassified = Number(a.NON_CLASSE || 0);
        if (unclassified > 0) {
            labels.push('Non classé');
            actuals.push(unclassified);
            targets.push(null);
        }

        var warn = document.getElementById('sd-segment-warning');
        if (warn) warn.classList.toggle('hidden', unclassified <= 0);

        segmentChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Réalisé',  data: actuals, backgroundColor: 'rgba(0, 90, 43, 0.85)',   borderRadius: 6 },
                    { label: 'Objectif', data: targets, backgroundColor: 'rgba(140, 198, 63, 0.55)', borderRadius: 6 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 700 },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                if (ctx.parsed.y === null) return ctx.dataset.label + ' : non défini';
                                return ctx.dataset.label + ' : ' + LPC.fmt.fcfa(ctx.parsed.y);
                            },
                        },
                    },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function (v) { return LPC.fmt.int(v); } } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Tables — unchanged; preserved from pre-7H.
    // -------------------------------------------------------------------------
    function renderTopClients(rows) {
        var tbody = document.getElementById('sd-top-clients');
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
        var tbody = document.getElementById('sd-dormant');
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
            var tone  = days >= 60 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700';
            var label = days >= 60 ? '60 j+' : '30 j+';
            var badge = LPC.raw(
                '<span class="px-2 py-0.5 rounded-full text-[11px] font-bold ' + tone + '">' + label + '</span>'
            );
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
        var main = document.getElementById('main');
        var m = document.getElementById('sd-month');
        var y = document.getElementById('sd-year');
        var s = document.getElementById('sd-scope');
        return {
            month: m ? m.value : (main ? main.dataset.sdMonth : ''),
            year:  y ? y.value : (main ? main.dataset.sdYear  : ''),
            scope: s ? s.value : 'me',
        };
    }

    function showError(msg) {
        var box = document.getElementById('sd-error');
        if (!box) return;
        var span = document.getElementById('sd-error-msg');
        if (span) span.textContent = msg || "Le tableau de bord n'a pas pu être chargé.";
        box.classList.remove('hidden');
    }
    function hideError() {
        var box = document.getElementById('sd-error');
        if (box) box.classList.add('hidden');
    }

    async function load() {
        var p = currentParams();
        var qs = new URLSearchParams({ action: 'overview', year: p.year, month: p.month, scope: p.scope });

        hideError();
        LPC.kpi.setLoading('#sd-kpi-grid', true);

        try {
            var res = await fetch(ENDPOINT + '?' + qs.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });

            if (res.status === 403) { showError("Vous n'avez pas la permission de consulter ce tableau de bord."); return; }
            if (!res.ok)             { showError('Le serveur a répondu ' + res.status + '.'); return; }

            var payload = await res.json();
            if (!payload || payload.status !== 'success') {
                showError((payload && payload.message) || 'Réponse inattendue du serveur.');
                return;
            }

            var d = payload.data;

            var banner = document.getElementById('sd-no-targets');
            if (banner) banner.classList.toggle('hidden', !!d.meta.has_targets);

            LPC.setText(document.getElementById('sd-chart-year'), d.meta.year);

            updateRevenue(d.kpis);
            updateVolumes(d.kpis);
            updateDispatch(d.kpis);
            updateOverdue(d.kpis);

            renderMonthlyChart(d.charts);
            renderSegmentChart(d.charts);
            renderTopClients(d.tables.top_clients);
            renderDormant(d.tables.dormant);
        } catch (err) {
            console.error('[dashboard-sales] load failed', err);
            showError('Impossible de joindre le serveur.');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        LPC.kpi.mount('#sd-kpi-grid', KPI_SPECS.map(function (s) {
            var m = Object.assign({}, s);
            delete m.drillCfg;
            return m;
        }));

        document.getElementById('sd-kpi-grid').addEventListener('lpc:kpi-open', function (e) {
            e.preventDefault();
            var spec = KPI_SPECS.find(function (k) { return k.id === e.detail.id; });
            if (spec && spec.drillCfg) {
                // Thread the current month/year/scope into the drilldown URL so
                // the modal shows the same period the cards were computed for.
                var p = currentParams();
                var cfg = Object.assign({}, spec.drillCfg);
                var sep = cfg.url.indexOf('?') === -1 ? '?' : '&';
                cfg.url = cfg.url + sep + 'year=' + encodeURIComponent(p.year) +
                                     '&month=' + encodeURIComponent(p.month) +
                                     '&scope=' + encodeURIComponent(p.scope);
                LPC.kpi.openDrilldown(cfg);
            }
        });

        ['sd-month', 'sd-year', 'sd-scope'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('change', load);
        });

        load();
    });
})();
