/**
 * assets/js/modules/accounting-budgets.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — MD-first Budget & Performance (post-migration 091).
 *
 * Rewritten in one pass. The pre-091 version was 670 lines wired to a
 * five-tab UI (Vision Globale, Lignes Budgétaires, Performance & KPI,
 * Transferts, Générateur Smart) and its dependent OHADA modal — all of
 * which were removed on the MD's request. What remains:
 *
 *   · switchTab(overview | targets | alerts) with an lpc:tabchange hook.
 *   · Three fetch/render pairs, one per tab.
 *   · saveBucketTargets() bulk write.
 *   · openMonthsModal / applyMonthsFromModal — per-bucket month overrides.
 *   · decideTransfer / renderPendingRequests — the MD's approve/reject UX.
 *   · generateReportPDF — MD-friendly one-page summary (not the dense
 *     multi-page dump the pre-091 button produced).
 *
 * Assumes window.LPC_BUDGET_MD is set by budgets.php with the flags
 * `canEditTargets`, `canApproveTransfers`, `canRequestTransfers`, `lang`.
 * =============================================================================
 */

(function () {
    'use strict';

    const flags = window.LPC_BUDGET_MD || {
        canEditTargets: false, canApproveTransfers: false,
        canRequestTransfers: false, lang: 'fr'
    };

    let currentTab = 'overview';
    let cache = {};                 // per-tab last fetch, for the PDF export
    let charts = {};
    let editState = {};             // bucket_id → { annual, monthly[12] }

    /* --------------------------------------------------------------------- */
    /* Formatting                                                            */
    /* --------------------------------------------------------------------- */

    // Full digits, thousands separator, no decimals (FCFA has no cents in
    // practice; DECIMAL(15,2) is kept for the ledger but hidden here).
    const fmtFull = (n) => {
        if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
        return Math.round(Number(n)).toLocaleString(flags.lang === 'en' ? 'en-US' : 'fr-FR');
    };

    // Millions with 1 decimal — the KPI-card and Overview format
    // (answer #5 in the scoping call).
    const fmtMillions = (n) => {
        if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
        const v = Number(n) / 1_000_000;
        const decimals = Math.abs(v) >= 100 ? 0 : 1;
        return v.toLocaleString(flags.lang === 'en' ? 'en-US' : 'fr-FR', {
            minimumFractionDigits: decimals, maximumFractionDigits: decimals
        }) + ' M';
    };

    const t = (fr, en) => flags.lang === 'en' ? en : fr;

    /* --------------------------------------------------------------------- */
    /* Tab plumbing                                                          */
    /* --------------------------------------------------------------------- */

    const VALID_TABS = ['overview', 'targets', 'alerts'];

    window.addEventListener('load', () => {
        const hash = (window.location.hash || '').replace('#', '');
        switchTab(VALID_TABS.includes(hash) ? hash : 'overview');
    });

    document.addEventListener('DOMContentLoaded', () => {
        const tablist = document.getElementById('budgets-tablist');
        if (tablist) {
            tablist.addEventListener('lpc:tabchange', (e) => {
                const tabId = e.detail && e.detail.tab && e.detail.tab.id;
                if (tabId) switchTab(tabId.replace(/^tab-/, ''));
            });
        }
    });

    function refreshAllTabs() {
        // Only the current tab needs a real re-fetch; the others are lazy.
        cache = {};
        fetchTab(currentTab);
    }
    window.refreshAllTabs = refreshAllTabs;

    async function switchTab(tab) {
        if (!VALID_TABS.includes(tab)) tab = 'overview';
        currentTab = tab;

        document.querySelectorAll('.tab-link').forEach(el => {
            el.classList.remove('border-finance-highlight', 'text-finance-dark', 'font-black');
            el.classList.add('border-transparent', 'text-gray-500', 'font-bold');
            el.setAttribute('aria-selected', 'false');
            el.tabIndex = -1;
        });
        const activeTabEl = document.getElementById(`tab-${tab}`);
        if (activeTabEl) {
            activeTabEl.classList.remove('border-transparent', 'text-gray-500', 'font-bold');
            activeTabEl.classList.add('border-finance-highlight', 'text-finance-dark', 'font-black');
            activeTabEl.setAttribute('aria-selected', 'true');
            activeTabEl.tabIndex = 0;
        }

        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        const contentEl = document.getElementById(`content-${tab}`);
        if (contentEl) contentEl.classList.add('active');

        await fetchTab(tab);
    }
    window.switchTab = switchTab;

    async function fetchTab(tab) {
        const year = document.getElementById('global_year_filter').value;
        const url  = `/api/v1/budget_controller.php?tab=${encodeURIComponent(tab)}&year=${encodeURIComponent(year)}`;
        try {
            const res = await fetch(url, { credentials: 'same-origin' });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const json = await res.json();
            if (json.status !== 'success') {
                console.error('[budgets] server error:', json.message);
                return;
            }
            cache[tab] = json.data;
            if (tab === 'overview') renderOverview(json.data);
            if (tab === 'targets')  renderTargets(json.data);
            if (tab === 'alerts')   renderAlerts(json.data);

            // Overview also renders the pending-transfer cards. Fetch them
            // opportunistically here so the MD sees them without visiting
            // another screen. (Approve/reject lives in transfer_requests
            // but the CARDS live inside Overview.)
            if (tab === 'overview' && flags.canApproveTransfers) {
                await fetchPendingRequests();
            }
        } catch (e) {
            console.error('[budgets] fetch failed:', e);
        }
    }

    /* --------------------------------------------------------------------- */
    /* OVERVIEW                                                              */
    /* --------------------------------------------------------------------- */

    function pillHtml(status) {
        if (status === 'ok')   return `<span class="lpc-pill lpc-pill-ok"><i class="fas fa-check-circle"></i>${t('Sur objectif','On track')}</span>`;
        if (status === 'warn') return `<span class="lpc-pill lpc-pill-warn"><i class="fas fa-exclamation-triangle"></i>${t('À surveiller','Watch')}</span>`;
        if (status === 'bad')  return `<span class="lpc-pill lpc-pill-bad"><i class="fas fa-fire"></i>${t('Dépassement','Over')}</span>`;
        return `<span class="lpc-pill lpc-pill-none">${t('Non défini','Not set')}</span>`;
    }

    function renderOverview(data) {
        // KPI cards
        const revPct = data.kpis.rev_target > 0
            ? Math.min(999, Math.round((data.kpis.rev_actual / data.kpis.rev_target) * 100)) : 0;
        const expPct = data.kpis.exp_target > 0
            ? Math.min(999, Math.round((data.kpis.exp_actual / data.kpis.exp_target) * 100)) : 0;

        document.getElementById('kpi_rev_actual').innerText = fmtMillions(data.kpis.rev_actual);
        document.getElementById('lbl_rev_target').innerText = fmtMillions(data.kpis.rev_target);
        document.getElementById('lbl_rev_pct').innerText    = revPct;
        setBar('bar_rev', Math.min(100, revPct));

        document.getElementById('kpi_exp_actual').innerText = fmtMillions(data.kpis.exp_actual);
        document.getElementById('lbl_exp_target').innerText = fmtMillions(data.kpis.exp_target);
        document.getElementById('lbl_exp_pct').innerText    = expPct;
        setBar('bar_exp', Math.min(100, expPct));
        // Turn red if spending is at or past pace.
        const expBar = document.getElementById('bar_exp');
        expBar.classList.toggle('bg-rose-600', expPct >= data.time_pct);
        expBar.classList.toggle('bg-rose-500', expPct <  data.time_pct);

        document.getElementById('kpi_emergency_left').innerText = fmtMillions(data.kpis.emergency_available);

        // Bucket cards — one per non-KPI line. Ordered by sort (server-side).
        const cards = document.getElementById('overview-bucket-cards');
        cards.innerHTML = '';
        data.buckets
            .filter(b => !b.is_emergency)   // emergency is already the third KPI
            .forEach(b => {
                const label = flags.lang === 'en' ? b.label_en : b.label_fr;
                const targetTxt = b.annual_target === null
                    ? `<span class="text-gray-400 italic">${t('Objectif non défini','No target')}</span>`
                    : `${fmtMillions(b.ytd_actual)} / ${fmtMillions(b.annual_target)}`;

                cards.insertAdjacentHTML('beforeend', `
                    <div class="lpc-bucket-card bg-gray-50 border border-gray-200 rounded-xl p-4 flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2 text-sm font-black text-gray-800">
                                <i class="fas ${escAttr(b.icon || 'fa-square')} text-${escAttr(b.color || 'gray')}-500" aria-hidden="true"></i>
                                <span>${esc(label)}</span>
                            </div>
                            ${pillHtml(b.status)}
                        </div>
                        <div class="text-xs text-gray-600 font-medium">${targetTxt}</div>
                    </div>
                `);
            });

        // Monthly-consumption chart. Same 12 bars as before but the labels
        // are pulled from a small local dictionary so English works too.
        const labels = flags.lang === 'en'
            ? ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
            : ['Jan','Fév','Mar','Avr','Mai','Juin','Juil','Aoû','Sep','Oct','Nov','Déc'];
        if (charts.exec) charts.exec.destroy();
        const ctx = document.getElementById('executionChart').getContext('2d');
        charts.exec = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: t('Budget mensuel','Monthly budget'), data: data.chart.budgeted, backgroundColor: '#E2E8F0', borderRadius: 4 },
                    { label: t('Dépensé','Spent'),                 data: data.chart.actuals,  backgroundColor: '#EF4444', borderRadius: 4 }
                ]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        // Compact millions on the y-axis; the tooltip below
                        // still shows the full number.
                        ticks: {
                            callback: (v) => (v === 0) ? '0' : fmtMillions(v)
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: (item) => `${item.dataset.label}: ${fmtFull(item.raw)} FCFA`
                        }
                    }
                }
            }
        });
    }

    function setBar(id, pct) {
        const bar = document.getElementById(id);
        if (!bar) return;
        bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
        bar.setAttribute('aria-valuenow', String(Math.round(pct)));
    }

    /* --------------------------------------------------------------------- */
    /* TARGETS                                                               */
    /* --------------------------------------------------------------------- */

    function renderTargets(data) {
        const body = document.getElementById('targets-rows');
        body.innerHTML = '';
        editState = {};

        data.rows.forEach(row => {
            const label = flags.lang === 'en' ? row.label_en : row.label_fr;
            const canEdit = flags.canEditTargets;

            // Populate the edit state used by saveBucketTargets/openMonthsModal.
            editState[row.id] = {
                annual:  row.annual,
                monthly: Array.isArray(row.monthly) ? row.monthly.slice() : null,
                is_revenue: row.is_revenue,
                label
            };

            const readonlyAttr = canEdit ? '' : 'readonly';

            body.insertAdjacentHTML('beforeend', `
                <tr class="hover:bg-gray-50" data-bucket-id="${row.id}">
                    <th scope="row" class="py-3 px-6 font-black text-gray-800 border-r border-gray-100 align-middle">
                        <div class="flex items-center gap-2">
                            <i class="fas ${escAttr(row.icon || 'fa-square')} text-${escAttr(row.color || 'gray')}-500" aria-hidden="true"></i>
                            <span>${esc(label)}</span>
                        </div>
                        ${canEdit ? `
                          <button type="button" onclick="openMonthsModal(${row.id})"
                                  class="text-[10px] font-bold text-lpc-dark hover:underline mt-1 flex items-center gap-1">
                              <i class="fas fa-calendar-alt" aria-hidden="true"></i>
                              ${t('Ajuster par mois','Adjust by month')}
                          </button>` : ''}
                    </th>
                    <td class="py-3 px-6 text-right border-r border-gray-100 align-middle">
                        <input type="number" min="0" step="1000" ${readonlyAttr}
                               value="${row.annual === null ? '' : Math.round(row.annual)}"
                               data-annual-for="${row.id}"
                               oninput="onAnnualInput(${row.id})"
                               class="w-40 bg-gray-50 border border-gray-200 rounded-lg p-2 text-sm text-right font-black text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light"
                               placeholder="—">
                    </td>
                    <td class="py-3 px-6 text-right font-bold border-r border-gray-100 text-gray-700 align-middle">
                        ${fmtFull(row.ytd_actual)}
                    </td>
                    <td class="py-3 px-6 text-center align-middle">${pillHtml(row.status)}</td>
                </tr>
            `);
        });
    }

    // When the MD edits the annual value, drop any custom monthly override —
    // saving with an untouched monthly array under a new annual would refuse
    // (server-side sum check), so a fresh auto-split is the safer default.
    function onAnnualInput(bucketId) {
        const el = document.querySelector(`[data-annual-for="${bucketId}"]`);
        if (!el) return;
        const v = parseFloat(el.value || '0') || 0;
        editState[bucketId].annual  = v;
        editState[bucketId].monthly = null;  // let server auto-split
    }
    window.onAnnualInput = onAnnualInput;

    async function saveBucketTargets() {
        if (!flags.canEditTargets) return;

        const year = document.getElementById('global_year_filter').value;
        const rows = Object.keys(editState).map(id => ({
            bucket_id: Number(id),
            annual:    Number(editState[id].annual || 0),
            monthly:   editState[id].monthly     // null OR 12 numbers
        }));

        const btn = document.getElementById('targets-save-btn');
        const fb  = document.getElementById('targets-feedback');
        btn.disabled = true;
        fb.textContent = t('Enregistrement…','Saving…');
        fb.className = 'text-xs font-bold text-gray-500';

        try {
            const res = await fetch('/api/v1/budget_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'save_targets',
                    fiscal_year: Number(year),
                    rows
                })
            });
            const json = await res.json();
            if (json.status === 'success') {
                fb.textContent = json.message;
                fb.className = 'text-xs font-bold text-emerald-600';
                // Re-fetch so the Statut pills refresh with the new totals.
                cache = {};
                await fetchTab('targets');
            } else {
                fb.textContent = json.message || t('Échec.','Failed.');
                fb.className = 'text-xs font-bold text-rose-600';
            }
        } catch (e) {
            console.error('[budgets] save error:', e);
            fb.textContent = t('Erreur réseau.','Network error.');
            fb.className = 'text-xs font-bold text-rose-600';
        } finally {
            btn.disabled = false;
        }
    }
    window.saveBucketTargets = saveBucketTargets;

    /* -- Per-bucket month override modal ---------------------------------- */
    let monthsModalBucketId = null;

    function openMonthsModal(bucketId) {
        if (!flags.canEditTargets) return;
        const st = editState[bucketId];
        if (!st) return;

        monthsModalBucketId = bucketId;
        document.getElementById('modal-months-title').innerText =
            `${t('Répartition mensuelle','Monthly split')} — ${st.label}`;
        document.getElementById('modal-months-annual').innerText = fmtFull(st.annual);

        // Seed the 12 inputs. If no custom monthly exists yet, prefill with
        // annual/12 so the MD can start from a sensible baseline.
        const per = Math.round((st.annual || 0) / 12);
        const monthly = st.monthly && st.monthly.length === 12
            ? st.monthly.slice()
            : Array(12).fill(per);

        const labels = flags.lang === 'en'
            ? ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']
            : ['Janv.','Févr.','Mars','Avril','Mai','Juin','Juil.','Août','Sept.','Oct.','Nov.','Déc.'];

        const container = document.getElementById('modal-months-inputs');
        container.innerHTML = '';
        monthly.forEach((v, i) => {
            container.insertAdjacentHTML('beforeend', `
                <label class="flex flex-col text-[10px] font-black uppercase tracking-widest text-gray-500">
                    ${labels[i]}
                    <input type="number" min="0" step="1000" value="${Math.round(v)}"
                           data-month-idx="${i}"
                           oninput="onMonthsSumRefresh()"
                           class="mt-1 bg-gray-50 border border-gray-200 rounded-lg p-2 text-sm text-right font-bold text-gray-900 outline-none focus:ring-2 focus:ring-lpc-light">
                </label>
            `);
        });

        onMonthsSumRefresh();
        document.getElementById('modal-months').classList.remove('hidden');
    }
    window.openMonthsModal = openMonthsModal;

    function onMonthsSumRefresh() {
        const inputs = document.querySelectorAll('#modal-months-inputs input[data-month-idx]');
        let sum = 0;
        inputs.forEach(el => { sum += parseFloat(el.value || '0') || 0; });
        const el = document.getElementById('modal-months-sum');
        el.innerText = fmtFull(sum) + ' FCFA';

        // Highlight the sum in red if it does not match the annual — the
        // server will reject it on save.
        const st = editState[monthsModalBucketId] || { annual: 0 };
        const off = Math.abs(sum - (st.annual || 0)) > 12;
        el.className = 'font-black ' + (off ? 'text-rose-600' : 'text-emerald-600');
    }
    window.onMonthsSumRefresh = onMonthsSumRefresh;

    function applyMonthsFromModal() {
        const inputs = document.querySelectorAll('#modal-months-inputs input[data-month-idx]');
        const arr = Array(12).fill(0);
        inputs.forEach(el => {
            const i = parseInt(el.dataset.monthIdx, 10);
            arr[i] = parseFloat(el.value || '0') || 0;
        });
        const sum = arr.reduce((a, b) => a + b, 0);
        const st = editState[monthsModalBucketId] || {};
        // Also snap the annual to the new sum — the MD is expressing
        // intent via the 12 cells, and a mismatch on save would be
        // rejected server-side.
        st.annual  = sum;
        st.monthly = arr;

        // Reflect the new annual back into the row input.
        const inp = document.querySelector(`[data-annual-for="${monthsModalBucketId}"]`);
        if (inp) inp.value = Math.round(sum);

        closeMonthsModal();
    }
    window.applyMonthsFromModal = applyMonthsFromModal;

    function closeMonthsModal() {
        document.getElementById('modal-months').classList.add('hidden');
        monthsModalBucketId = null;
    }
    window.closeMonthsModal = closeMonthsModal;

    /* --------------------------------------------------------------------- */
    /* ALERTS                                                                */
    /* --------------------------------------------------------------------- */

    function renderAlerts(data) {
        const body = document.getElementById('alerts-body');
        const badge = document.getElementById('alerts-count-badge');
        body.innerHTML = '';

        if (!data.alerts || data.alerts.length === 0) {
            badge.classList.add('hidden');
            body.innerHTML = `
                <div class="text-center py-12 text-emerald-600">
                    <i class="fas fa-check-circle text-4xl mb-3"></i>
                    <p class="font-black text-lg">${t('Aucune alerte.','No alerts.')}</p>
                    <p class="text-sm text-gray-500 mt-1">${t('Toutes les lignes sont dans les clous.','Every line is on track.')}</p>
                </div>`;
            return;
        }

        badge.classList.remove('hidden');
        badge.innerText = String(data.alerts.length);

        data.alerts.forEach(a => {
            const label = flags.lang === 'en' ? a.label_en : a.label_fr;
            const pct = a.annual > 0 ? Math.round((a.ytd_actual / a.annual) * 100) : 0;
            const border = a.status === 'bad' ? 'border-rose-300 bg-rose-50/40' : 'border-amber-300 bg-amber-50/40';

            body.insertAdjacentHTML('beforeend', `
                <div class="border ${border} rounded-xl p-4 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <i class="fas ${escAttr(a.icon || 'fa-square')} text-${escAttr(a.color || 'gray')}-500 text-lg" aria-hidden="true"></i>
                        <div>
                            <p class="font-black text-gray-800">${esc(label)}</p>
                            <p class="text-xs text-gray-600 mt-0.5">${fmtFull(a.ytd_actual)} / ${fmtFull(a.annual)} FCFA · ${pct}%
                              <span class="text-gray-400">· ${t('rythme','pace')} ${data.time_pct}%</span>
                            </p>
                        </div>
                    </div>
                    ${pillHtml(a.status)}
                </div>
            `);
        });
    }

    /* --------------------------------------------------------------------- */
    /* PENDING TRANSFER REQUESTS (cards on Overview)                         */
    /* --------------------------------------------------------------------- */

    async function fetchPendingRequests() {
        const year = document.getElementById('global_year_filter').value;
        try {
            const res = await fetch(`/api/v1/budget_controller.php?tab=transfer_requests&year=${encodeURIComponent(year)}`, { credentials: 'same-origin' });
            const json = await res.json();
            if (json.status === 'success') renderPendingRequests(json.data);
        } catch (e) {
            console.error('[budgets] transfer_requests fetch failed:', e);
        }
    }

    function renderPendingRequests(rows) {
        const wrap = document.getElementById('pending-transfer-requests');
        wrap.innerHTML = '';
        if (!rows || rows.length === 0) {
            wrap.classList.add('hidden');
            return;
        }
        wrap.classList.remove('hidden');
        rows.forEach(r => {
            const fromLbl = flags.lang === 'en' ? r.from_label_en : r.from_label_fr;
            const toLbl   = flags.lang === 'en' ? r.to_label_en   : r.to_label_fr;
            wrap.insertAdjacentHTML('beforeend', `
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 flex items-start gap-4">
                    <i class="fas fa-exchange-alt text-amber-600 text-xl mt-1" aria-hidden="true"></i>
                    <div class="flex-1">
                        <p class="text-sm font-black text-amber-900">
                            ${t('Demande de transfert','Transfer request')} — ${fmtFull(r.amount)} FCFA
                        </p>
                        <p class="text-xs text-amber-800 mt-1">
                            <strong>${esc(fromLbl)}</strong> → <strong>${esc(toLbl)}</strong>
                            · ${t('demandé par','requested by')} ${esc(r.requested_by_name)}
                        </p>
                        <p class="text-xs text-gray-700 mt-2 italic">« ${esc(r.reason)} »</p>
                    </div>
                    <div class="flex gap-2 shrink-0">
                        <button type="button" onclick="decideTransfer(${r.id},'rejected')"
                                class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 px-4 py-2 rounded-lg font-bold text-xs">
                            <i class="fas fa-times mr-1" aria-hidden="true"></i>${t('Refuser','Reject')}
                        </button>
                        <button type="button" onclick="decideTransfer(${r.id},'approved')"
                                class="bg-lpc-dark hover:bg-green-800 text-white px-4 py-2 rounded-lg font-bold text-xs">
                            <i class="fas fa-check mr-1" aria-hidden="true"></i>${t('Approuver','Approve')}
                        </button>
                    </div>
                </div>
            `);
        });
    }

    async function decideTransfer(reqId, decision) {
        if (!flags.canApproveTransfers) return;
        const msg = decision === 'approved'
            ? t('Approuver cette demande ?','Approve this request?')
            : t('Refuser cette demande ?','Reject this request?');
        if (window.LPC && LPC.modal && LPC.modal.confirm) {
            const ok = await LPC.modal.confirm(msg);
            if (!ok) return;
        } else if (!window.confirm(msg)) return;

        try {
            const res = await fetch('/api/v1/budget_controller.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'decide_transfer',
                    request_id: reqId,
                    decision
                })
            });
            const json = await res.json();
            if (json.status === 'success') {
                await fetchTab('overview');   // re-render KPIs and cards
            } else if (window.LPC && LPC.modal) {
                LPC.modal.alert(json.message);
            }
        } catch (e) {
            console.error('[budgets] decide error:', e);
        }
    }
    window.decideTransfer = decideTransfer;

    /* --------------------------------------------------------------------- */
    /* PDF EXPORT — one-page MD summary (answer #9)                          */
    /* --------------------------------------------------------------------- */

    async function generateReportPDF() {
        const year = document.getElementById('global_year_filter').value;
        // Make sure we have fresh overview + alerts.
        cache = {};
        await fetchTab('overview');
        const alertsRes = await fetch(`/api/v1/budget_controller.php?tab=alerts&year=${encodeURIComponent(year)}`, { credentials: 'same-origin' });
        const alertsJson = await alertsRes.json();
        const alerts = (alertsJson.status === 'success') ? (alertsJson.data.alerts || []) : [];
        const data = cache['overview'];

        // Build a small hidden container styled for one-page export. Using
        // a purpose-built layout, not html2canvas of the live page, so the
        // PDF is print-friendly regardless of what the MD had scrolled to.
        const wrap = document.createElement('div');
        wrap.style.cssText = 'position:fixed;left:-10000px;top:0;width:900px;background:white;font-family:Inter,Arial,sans-serif;color:#111;padding:32px;';
        wrap.innerHTML = `
            <h1 style="font-size:22px;font-weight:900;margin:0 0 4px 0">${t('Budget & Performance','Budget & Performance')} — ${year}</h1>
            <p style="font-size:11px;color:#6b7280;margin:0 0 20px 0">${t('Résumé Direction Générale','Managing Director Summary')} · ${new Date().toLocaleDateString(flags.lang === 'en' ? 'en-US' : 'fr-FR')}</p>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px">
                ${kpiBlock(t('Chiffre d\'affaires','Revenue'),   data.kpis.rev_actual, data.kpis.rev_target, '#059669')}
                ${kpiBlock(t('Dépenses','Expenses'),             data.kpis.exp_actual, data.kpis.exp_target, '#dc2626')}
                ${kpiBlock(t('Fonds d\'imprévus','Emergency'),   data.kpis.emergency_available, null,        '#0f172a')}
            </div>

            <h2 style="font-size:14px;font-weight:900;margin:0 0 8px 0">${t('Les 8 lignes','The 8 lines')}</h2>
            <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px">
                <thead>
                    <tr style="background:#f8fafc;text-align:left;color:#6b7280;font-size:10px;text-transform:uppercase">
                        <th style="padding:6px 8px">${t('Ligne','Line')}</th>
                        <th style="padding:6px 8px;text-align:right">${t('Réalisé YTD','Actual YTD')}</th>
                        <th style="padding:6px 8px;text-align:right">${t('Objectif','Target')}</th>
                        <th style="padding:6px 8px;text-align:center">${t('Statut','Status')}</th>
                    </tr>
                </thead>
                <tbody>
                    ${data.buckets.map(b => `
                        <tr style="border-top:1px solid #f1f5f9">
                            <td style="padding:6px 8px;font-weight:700">${esc(flags.lang === 'en' ? b.label_en : b.label_fr)}</td>
                            <td style="padding:6px 8px;text-align:right">${fmtFull(b.ytd_actual)} FCFA</td>
                            <td style="padding:6px 8px;text-align:right;color:#6b7280">${b.annual_target === null ? '—' : fmtFull(b.annual_target) + ' FCFA'}</td>
                            <td style="padding:6px 8px;text-align:center">${pillPdf(b.status)}</td>
                        </tr>`).join('')}
                </tbody>
            </table>

            ${alerts.length > 0 ? `
                <h2 style="font-size:14px;font-weight:900;margin:0 0 8px 0;color:#b91c1c">${t('Top alertes','Top alerts')}</h2>
                <ul style="margin:0 0 12px 0;padding-left:18px;font-size:12px;line-height:1.6">
                    ${alerts.slice(0, 5).map(a => `
                        <li><strong>${esc(flags.lang === 'en' ? a.label_en : a.label_fr)}</strong> — ${fmtFull(a.ytd_actual)} / ${fmtFull(a.annual)} FCFA
                        (${Math.round((a.ytd_actual / (a.annual || 1)) * 100)}%)</li>
                    `).join('')}
                </ul>
            ` : `<p style="font-size:12px;color:#059669;font-weight:700">${t('Aucune alerte — toutes les lignes sont dans les clous.','No alerts — every line is on track.')}</p>`}

            <p style="font-size:10px;color:#9ca3af;margin-top:24px">Bureau LPC ERP — ${t('généré automatiquement','auto-generated')}</p>
        `;
        document.body.appendChild(wrap);

        try {
            const canvas = await html2canvas(wrap, { scale: 2, backgroundColor: '#ffffff' });
            const img    = canvas.toDataURL('image/jpeg', 0.92);
            const pdf    = new jspdf.jsPDF('p', 'mm', 'a4');
            const pdfW   = pdf.internal.pageSize.getWidth();
            const pdfH   = (canvas.height * pdfW) / canvas.width;
            pdf.addImage(img, 'JPEG', 0, 0, pdfW, pdfH);
            pdf.save(`LPC_MD_Budget_${year}.pdf`);
        } catch (e) {
            console.error('[budgets] PDF export failed:', e);
            if (window.LPC && LPC.modal) LPC.modal.alert(t('Échec de l\'export PDF.','PDF export failed.'));
        } finally {
            wrap.remove();
        }
    }
    window.generateReportPDF = generateReportPDF;

    function kpiBlock(label, actual, target, color) {
        const pct = target && target > 0 ? Math.round((actual / target) * 100) : null;
        return `
            <div style="border:1px solid #e5e7eb;border-radius:12px;padding:12px">
                <p style="font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:0.05em;margin:0 0 4px 0;font-weight:800">${esc(label)}</p>
                <p style="font-size:20px;font-weight:900;color:${color};margin:0">${fmtFull(actual)} FCFA</p>
                ${pct !== null ? `<p style="font-size:11px;color:#6b7280;margin:4px 0 0 0">${pct}% ${t('de','of')} ${fmtFull(target)}</p>` : ''}
            </div>`;
    }

    function pillPdf(status) {
        const styles = {
            ok:   'background:#ecfdf5;color:#047857',
            warn: 'background:#fef3c7;color:#92400e',
            bad:  'background:#fee2e2;color:#b91c1c',
            '-':  'background:#f1f5f9;color:#475569'
        };
        const labels = {
            ok: t('Sur objectif','On track'),
            warn: t('À surveiller','Watch'),
            bad: t('Dépassement','Over'),
            '-': t('Non défini','Not set')
        };
        return `<span style="padding:2px 8px;border-radius:9999px;font-size:10px;font-weight:800;${styles[status] || styles['-']}">${labels[status] || labels['-']}</span>`;
    }

    /* --------------------------------------------------------------------- */
    /* Utilities                                                             */
    /* --------------------------------------------------------------------- */

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }
    function escAttr(s) {
        // Same as esc but tuned for use inside quoted attribute values.
        return esc(s).replace(/[^a-zA-Z0-9_-]/g, '');
    }
})();
