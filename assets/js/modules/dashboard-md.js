/**
 * assets/js/modules/dashboard-md.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Tableau de Bord Exécutif (Direction Générale).
 *
 * Sprint 7H rewrite:
 *   · KPI cards mounted via LPC.kpi (see assets/js/lpc-kpi.js).
 *   · Period picker via LPC.kpi.mountPeriodPill — the same segmented pill
 *     lives on every dashboard now.
 *   · Fetch payload arrives from the honest-KPI md_dashboard_api.php:
 *     one card per KPI, each with its own value / delta / sub / sparkline.
 *     Broken queries surface as an "empty" state on THAT card — the other
 *     four continue to paint their real numbers (README §5.8).
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Page data (i18n literals hoisted from PHP into a JSON <script>).
    // ------------------------------------------------------------------
    var PAGE_DATA = {};
    try {
        var el = document.getElementById('lpc-page-data');
        if (el) PAGE_DATA = JSON.parse(el.textContent || '{}');
    } catch (e) { /* keep defaults */ }

    // ------------------------------------------------------------------
    // 1. KPI spec — the shape of the executive dashboard.
    //    IDs must match the ones returned by md_dashboard_api.php.
    // ------------------------------------------------------------------
    var KPI_SPECS = [
        {
            id: 'md-revenue', label: "Chiffre d'affaires", kind: 'fcfa', rail: 'brand',
            drill: 'revenue',
            drillCfg: {
                title: "Chiffre d'affaires — détail",
                url: '/api/v1/md_dashboard_api.php?action=drilldown_revenue',
                columns: ['Date', 'Écriture', 'Compte', 'Montant'],
                csv: true,
                deeplink: { href: '/modules/accounting/journal_entries.php?from=dashboard_md', label: 'Ouvrir le grand livre' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${LPC.fmt.date(r.date)}</td>
                        <td>${r.reference || ''}</td>
                        <td>${r.account_name || ''}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.amount)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'md-ar', label: 'Créances clients (AR)', kind: 'fcfa', rail: 'alert',
            drill: 'ar',
            drillCfg: {
                title: 'Créances clients — factures ouvertes',
                url: '/api/v1/md_dashboard_api.php?action=drilldown_ar',
                columns: ['Client', 'Facture', 'Échéance', 'Retard (j)', 'Montant'],
                csv: true,
                deeplink: { href: '/modules/accounting/invoices.php?from=dashboard_md', label: 'Ouvrir la facturation' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.client_name || ''}</td>
                        <td>${r.invoice_number || ''}</td>
                        <td>${LPC.fmt.date(r.due_date)}</td>
                        <td class="text-right">${r.days_overdue > 0 ? r.days_overdue : '—'}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.amount)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'md-margin', label: 'Marge nette', kind: 'percent', rail: 'info',
            drill: 'margin',
            drillCfg: {
                title: 'Marge nette — décomposition',
                url: '/api/v1/md_dashboard_api.php?action=drilldown_margin',
                columns: ['Compte', 'Type', 'Débit', 'Crédit', 'Solde'],
                csv: true,
                deeplink: { href: '/modules/accounting/reports.php?report=income_statement&from=dashboard_md', label: 'Compte de résultat' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.account_name || ''}</td>
                        <td>${r.type || ''}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.debit)}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.credit)}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.balance)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'md-empties', label: "Récupération d'emballages", kind: 'percent', rail: 'good',
            drill: 'empties',
            drillCfg: {
                title: "Récupération d'emballages — par chauffeur",
                url: '/api/v1/md_dashboard_api.php?action=drilldown_empties',
                columns: ['Chauffeur', 'Livrées', 'Retournées', 'Taux'],
                csv: true,
                deeplink: { href: '/modules/operations/empties_collection.php?from=dashboard_md', label: 'Voir les emballages' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.driver_name || ''}</td>
                        <td class="text-right">${LPC.fmt.int(r.delivered)}</td>
                        <td class="text-right">${LPC.fmt.int(r.returned)}</td>
                        <td class="text-right">${LPC.fmt.percent(r.rate)}</td>
                    </tr>`;
                },
            },
        },
        {
            id: 'md-cash', label: 'Cash disponible', kind: 'fcfa', rail: 'good',
            drill: 'cash',
            sparkline: false,          // stock KPI — no per-day series that makes sense on the card
            drillCfg: {
                title: 'Trésorerie — soldes par compte',
                url: '/api/v1/md_dashboard_api.php?action=drilldown_cash',
                columns: ['Compte', 'Code', 'Solde'],
                csv: true,
                deeplink: { href: '/modules/accounting/journal_entries.php?from=dashboard_md', label: 'Voir les écritures' },
                renderRow: function (r) {
                    return LPC.html`<tr>
                        <td>${r.account_name || ''}</td>
                        <td>${r.code || ''}</td>
                        <td class="text-right">${LPC.fmt.fcfa(r.balance)}</td>
                    </tr>`;
                },
            },
        },
    ];

    // ------------------------------------------------------------------
    // 2. Chart instances live at module scope so we can destroy on refetch.
    // ------------------------------------------------------------------
    var budgetChart = null;
    var agingChart  = null;

    // ------------------------------------------------------------------
    // 3. Fetch + paint.
    // ------------------------------------------------------------------
    function showError(msg) {
        var el = document.getElementById('md-error');
        var m  = document.getElementById('md-error-msg');
        if (!el) return;
        if (m && msg) m.textContent = msg;
        el.classList.remove('hidden');
    }
    function hideError() {
        var el = document.getElementById('md-error');
        if (el) el.classList.add('hidden');
    }

    function fetchDashboard(range) {
        hideError();
        LPC.kpi.setLoading('#md-kpi-grid', true);

        var url = '/api/v1/md_dashboard_api.php?start_date=' + encodeURIComponent(range.start)
                + '&end_date='   + encodeURIComponent(range.end)
                + '&prev_start=' + encodeURIComponent(range.previousStart)
                + '&prev_end='   + encodeURIComponent(range.previousEnd);

        fetch(url, { headers: { 'Accept': 'application/json' }})
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || res.status !== 'success') {
                    showError((res && res.message) || 'Réponse inattendue.');
                    return;
                }
                var payload = res.data || {};

                // ---- Cards --------------------------------------------
                (payload.cards || []).forEach(function (patch) {
                    LPC.kpi.update(patch);
                });

                // ---- Charts -------------------------------------------
                renderBudgetChart(payload.charts && payload.charts.budget_vs_actual);
                renderAgingChart(payload.charts && payload.charts.ar_aging);
            })
            .catch(function (err) {
                console.error('md_dashboard fetch failed:', err);
                showError('Connexion impossible. Vérifiez votre réseau.');
            });
    }

    // ------------------------------------------------------------------
    // 4. Charts.
    // ------------------------------------------------------------------
    function renderBudgetChart(data) {
        var canvas = document.getElementById('budgetChart');
        if (!canvas || !window.Chart) return;
        if (budgetChart) budgetChart.destroy();
        data = data || { labels: [], actuals: [], targets: [] };
        var brand      = getComputedStyle(document.documentElement).getPropertyValue('--lpc-brand').trim()       || '#005A2B';
        var brandLight = getComputedStyle(document.documentElement).getPropertyValue('--lpc-brand-light').trim() || '#8CC63F';

        budgetChart = new Chart(canvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: data.labels,
                datasets: [
                    { label: PAGE_DATA.v1 || 'Réalisé',        data: data.actuals, backgroundColor: brand,      borderRadius: 4 },
                    { label: PAGE_DATA.v2 || 'Cible mensuelle', data: data.targets, backgroundColor: brandLight, borderRadius: 4 },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 700 },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
                scales: {
                    y: { beginAtZero: true, grid: { borderDash: [4, 4] } },
                    x: { grid: { display: false } },
                },
            },
        });
    }

    function renderAgingChart(data) {
        var canvas = document.getElementById('agingChart');
        if (!canvas || !window.Chart) return;
        if (agingChart) agingChart.destroy();
        data = Array.isArray(data) ? data : [0, 0, 0];

        agingChart = new Chart(canvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['0–15 j', '16–30 j', '30 j+'],
                datasets: [{
                    data: data,
                    backgroundColor: ['#8CC63F', '#FBBF24', '#EF4444'],
                    borderWidth: 0, hoverOffset: 4,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '72%',
                animation: { animateRotate: true, duration: 800 },
                plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
            },
        });
    }

    // ------------------------------------------------------------------
    // 5. Boot.
    // ------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        // Mount cards first so the shimmer paints immediately — the fetch
        // is what fills them in.
        LPC.kpi.mount('#md-kpi-grid', KPI_SPECS.map(function (s) {
            // Strip drillCfg from the mount spec (mount only needs the outer
            // shape). The full config is looked up on 'lpc:kpi-open'.
            var m = Object.assign({}, s);
            delete m.drillCfg;
            return m;
        }));

        // The mount spec's `drill` string is opaque to the component; we
        // intercept the open event to pass the full drillCfg into openDrilldown.
        document.getElementById('md-kpi-grid').addEventListener('lpc:kpi-open', function (e) {
            e.preventDefault();
            var spec = KPI_SPECS.find(function (k) { return k.id === e.detail.id; });
            if (spec && spec.drillCfg) LPC.kpi.openDrilldown(spec.drillCfg);
        });

        // Period pill fires the initial fetch (fires lpc:period-change on mount).
        LPC.kpi.mountPeriodPill('#md-period-pill', {
            presets: ['today', '7d', 'month', 'quarter', 'ytd', 'custom'],
            default: 'ytd',
            onChange: fetchDashboard,
        });
    });
})();
