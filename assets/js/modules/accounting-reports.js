/**
 * assets/js/modules/accounting-reports.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/reports.php (Sprint 6 D2).
 *
 * Original block was ~17,863 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from the
 * Sprint 5 concurrency stream are preserved verbatim.
 *
 * Sprint 7A · D2 — added Vue Dirigeant handler + print + server-side PDF.
 * Sprint 7A · D3 — replaced the "en cours de développement" export stubs
 *                  with real bilan/résultat PDF (dompdf) + CSV downloads.
 * -----------------------------------------------------------------------------
 */
        let currentTab = 'bilan';
        let financialData = null;
        
        const fmt = (num) => LPC.fmt.int(num || 0);

        window.onload = () => { fetchFinancials(); };

        function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-fin-highlight', 'text-fin-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-fin-highlight', 'text-fin-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');
        }

        async function fetchFinancials() {
            const year = document.getElementById('report_year').value;
            // Fail-safe: never leave the tax indicator stuck on "Chargement..."
            // no matter which downstream step throws. See setTaxIndicatorError().
            const taxTextEl = document.getElementById('tax_status_text');
            try {
                // Future Backend Hook (The Mapping Engine)
                const res = await fetch(`/api/v1/financials_controller.php?action=generate_reports&year=${year}`);
                if(!res.ok) {
                    setTaxIndicatorError(`HTTP ${res.status}`);
                    return;
                }
                const result = await res.json();

                if (result.status !== 'success') {
                    setTaxIndicatorError(result.message || 'API');
                    return;
                }

                financialData = result.data || {};

                // Render each section in isolation so one bad section doesn't
                // starve the others (the earlier code let an exception in
                // renderBilan skip updateTaxIndicator, which is what left
                // "Chargement..." pinned on the toolbar).
                try { renderBilan();    } catch(e) { console.error('renderBilan',    e); }
                try { renderResultat(); } catch(e) { console.error('renderResultat', e); }
                try { updateTaxIndicator(); } catch(e) { console.error('updateTaxIndicator', e); setTaxIndicatorError('render'); }

                // Manage Close button state
                const btnClose = document.getElementById('btn_cloture');
                if(btnClose) {
                    const st = financialData.year_status || 'open';
                    const closedLike = (st === 'closed' || st === 'locked' || st === 'final_closed');
                    if(closedLike) {
                        btnClose.classList.replace('bg-rose-600', 'bg-gray-400');
                        btnClose.innerHTML = LPC.html`<i class="fas fa-lock"></i> Exercice Clôturé`;
                        btnClose.disabled = true;
                    } else {
                        btnClose.classList.replace('bg-gray-400', 'bg-rose-600');
                        btnClose.innerHTML = LPC.html`<i class="fas fa-unlock"></i> Assistant de Clôture`;
                        btnClose.disabled = false;
                    }
                }
            } catch(e) {
                console.error("Fetch error", e);
                setTaxIndicatorError('réseau');
            }
        }

        function setTaxIndicatorError(reason) {
            const el = document.getElementById('tax_status_text');
            const panel = document.getElementById('tax_indicator_panel');
            if (!el) return;
            el.className = 'text-sm font-black text-gray-500 leading-tight';
            el.innerHTML = LPC.html`Indisponible <span class="text-[9px] text-gray-400 font-bold">(${reason})</span>`;
            if (panel) panel.title = 'Simulation fiscale indisponible — vérifier /api/v1/financials_controller.php';
        }

        // ================= RENDERS ================= //
        
        function renderBilan() {
            const tbodyA = document.getElementById('tbody-actif');
            const tbodyP = document.getElementById('tbody-passif');
            tbodyA.innerHTML = ''; tbodyP.innerHTML = '';

            let totalActif = { brutN: 0, amortN: 0, netN: 0, netN_1: 0 };
            let totalPassif = { netN: 0, netN_1: 0 };

            // Render Actif
            financialData.bilan_actif.forEach(line => {
                const codeParams = `'${line.code}', '${line.name}'`;
                tbodyA.innerHTML += LPC.html`
                    <tr class="clickable-row border-b border-gray-50" onclick="openDrillDown(${codeParams})">
                        <td class="py-2 px-4 flex items-center gap-2"><i class="fas fa-search-plus text-gray-300"></i> ${line.name}</td>
                        <td class="py-2 px-4 text-right">${fmt(line.brut_N)}</td>
                        <td class="py-2 px-4 text-right text-rose-500">${line.amort_N > 0 ? '('+fmt(line.amort_N)+')' : '-'}</td>
                        <td class="py-2 px-4 text-right font-black text-emerald-700 bg-emerald-50/20">${fmt(line.net_N)}</td>
                        <td class="py-2 px-4 text-right text-gray-500 bg-gray-50">${fmt(line.net_N_1)}</td>
                    </tr>`;
                totalActif.brutN += line.brut_N; totalActif.amortN += line.amort_N;
                totalActif.netN += line.net_N; totalActif.netN_1 += line.net_N_1;
            });
            // Total Actif Row
            tbodyA.innerHTML += LPC.html`
                <tr class="row-total">
                    <td class="py-3 px-4 uppercase">Total Actif</td>
                    <td class="py-3 px-4 text-right">${fmt(totalActif.brutN)}</td>
                    <td class="py-3 px-4 text-right text-rose-300">${totalActif.amortN > 0 ? '('+fmt(totalActif.amortN)+')' : '-'}</td>
                    <td class="py-3 px-4 text-right font-black text-emerald-400">${fmt(totalActif.netN)}</td>
                    <td class="py-3 px-4 text-right">${fmt(totalActif.netN_1)}</td>
                </tr>`;

            // Render Passif
            financialData.bilan_passif.forEach(line => {
                const codeParams = `'${line.code}', '${line.name}'`;
                let netN = line.net_N; let netN_1 = line.net_N_1;
                
                // Answer 8A: Auto-inject Net Result into Passif if it's the RES_NET line
                if(line.code === 'RES_NET') {
                    netN = financialData.resultat_net_N;
                    netN_1 = financialData.resultat_net_N_1;
                }

                tbodyP.innerHTML += LPC.html`
                    <tr class="clickable-row border-b border-gray-50" onclick="openDrillDown(${codeParams})">
                        <td class="py-2 px-4 flex items-center gap-2"><i class="fas fa-search-plus text-gray-300"></i> ${line.name}</td>
                        <td class="py-2 px-4 text-right"></td><td class="py-2 px-4 text-right"></td>
                        <td class="py-2 px-4 text-right font-black text-rose-700 bg-rose-50/20">${fmt(netN)}</td>
                        <td class="py-2 px-4 text-right text-gray-500 bg-gray-50">${fmt(netN_1)}</td>
                    </tr>`;
                totalPassif.netN += netN; totalPassif.netN_1 += netN_1;
            });
            // Total Passif Row
            let diffN = totalActif.netN - totalPassif.netN;
            // LPC.raw: static markup. This is the balance-sheet tie-out icon;
            // unwrapped it printed its own <i> tag next to "Total Passif".
            let checkIcon = LPC.raw(Math.abs(diffN) < 1 ? '<i class="fas fa-check-circle text-emerald-400" title="Équilibré"></i>' : '<i class="fas fa-exclamation-triangle text-rose-500" title="Déséquilibré"></i>');
            
            tbodyP.innerHTML += LPC.html`
                <tr class="row-total">
                    <td class="py-3 px-4 uppercase">Total Passif & Capitaux ${checkIcon}</td>
                    <td class="py-3 px-4 text-right"></td><td class="py-3 px-4 text-right"></td>
                    <td class="py-3 px-4 text-right font-black text-rose-400">${fmt(totalPassif.netN)}</td>
                    <td class="py-3 px-4 text-right">${fmt(totalPassif.netN_1)}</td>
                </tr>`;
        }

        function renderResultat() {
            const tbody = document.getElementById('tbody-resultat');
            tbody.innerHTML = '';

            let currentSig = '';
            let sigTotals  = { N: 0, N_1: 0 };

            // Render Income Statement respecting SIG Cascade (OHADA SYSCOHADA)
            //
            // The SIG (Soldes Intermédiaires de Gestion) cascade groups lines by
            // sig_category (Marge Brute, Valeur Ajoutée, EBE, Résultat d'Expl, etc.).
            // Each section's subtotal must reflect ONLY that section's lines, so
            // sigTotals is reset every time we transition to a new category.
            //
            // Prior bug: sigTotals was cumulative across all sections, so each
            // rendered subtotal was actually "everything from start of file",
            // making every SIG row after the first section wrong.
            financialData.resultat.forEach(line => {
                const lineCat = line.sig_category || '';

                // Section transition: render the CURRENT section's subtotal, then reset.
                if (lineCat !== currentSig && currentSig !== '') {
                    renderSigRow(tbody, currentSig, sigTotals.N, sigTotals.N_1);
                    sigTotals = { N: 0, N_1: 0 };   // ← reset per section (bug fix)
                }
                currentSig = lineCat;

                // Variance vs. N-1
                let varPct = 0, varColor = 'text-gray-400';
                if (line.net_N_1 != 0) {
                    varPct = ((line.net_N - line.net_N_1) / Math.abs(line.net_N_1)) * 100;
                    varColor = varPct > 0 ? 'text-emerald-500' : (varPct < 0 ? 'text-rose-500' : varColor);
                }

                const codeParams = `'${line.code}', '${line.name}'`;
                const sign = line.operator === '-' ? '(-)' : '(+)';

                tbody.innerHTML += LPC.html`
                    <tr class="clickable-row hover:bg-gray-50" onclick="openDrillDown(${codeParams})">
                        <td class="py-2 px-6 pl-12 flex items-center gap-2"><span class="text-[9px] text-gray-400 w-4">${sign}</span> ${line.name}</td>
                        <td class="py-2 px-6 text-right font-bold">${fmt(line.net_N)}</td>
                        <td class="py-2 px-6 text-right text-gray-500 bg-gray-50">${fmt(line.net_N_1)}</td>
                        <td class="py-2 px-6 text-right text-[10px] font-black ${varColor}">${varPct === 0 ? '-' : (varPct > 0 ? '+'+varPct.toFixed(1) : varPct.toFixed(1))}%</td>
                    </tr>`;

                // Accumulate line into the CURRENT section's subtotal.
                if (line.operator === '+') { sigTotals.N += line.net_N; sigTotals.N_1 += line.net_N_1; }
                else                       { sigTotals.N -= line.net_N; sigTotals.N_1 -= line.net_N_1; }
            });

            // Render the LAST section's subtotal (loop only fires on transitions).
            if (currentSig !== '') {
                renderSigRow(tbody, currentSig, sigTotals.N, sigTotals.N_1);
            }

            // Final line: net result for the year (already computed server-side).
            renderSigRow(tbody, 'RÉSULTAT NET DE L\'EXERCICE', financialData.resultat_net_N, financialData.resultat_net_N_1);
        }

        function renderSigRow(tbody, name, valN, valN_1) {
            let varPct = valN_1 != 0 ? ((valN - valN_1) / Math.abs(valN_1)) * 100 : 0;
            let varColor = varPct > 0 ? 'text-emerald-600' : (varPct < 0 ? 'text-rose-600' : 'text-gray-500');

            tbody.innerHTML += LPC.html`
                <tr class="row-sig">
                    <td class="py-3 px-6 uppercase tracking-widest text-xs"><i class="fas fa-caret-right mr-2"></i> ${name.replace(/_/g, ' ')}</td>
                    <td class="py-3 px-6 text-right text-sm">${fmt(valN)}</td>
                    <td class="py-3 px-6 text-right text-sm bg-indigo-50/50">${fmt(valN_1)}</td>
                    <td class="py-3 px-6 text-right text-[10px] ${varColor}">${varPct === 0 ? '-' : (varPct > 0 ? '+'+varPct.toFixed(1) : varPct.toFixed(1))}%</td>
                </tr>`;
        }

        // ================= DRILL-DOWN & LOGIC ================= //

        async function openDrillDown(code, name) {
            const year = document.getElementById('report_year').value;
            document.getElementById('drill_title').innerHTML = LPC.html`<i class="fas fa-microscope mr-2"></i> Composition: ${name}`;
            document.getElementById('drill_year_lbl').innerText = year;
            const tbody = document.getElementById('tbody-drilldown');
            tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-12 text-center text-gray-400 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i>Recherche dans le Grand Livre...</td></tr>`;
            
            openModal('modal-drilldown');

            try {
                // API Call to fetch accounts linked to this code for this year
                const res = await fetch(`/api/v1/financials_controller.php?action=drilldown&code=${code}&year=${year}`);
                const result = await res.json();
                
                tbody.innerHTML = '';
                if(result.data.length === 0) {
                    tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-8 text-center text-gray-400 italic">Aucun mouvement trouvé pour cette rubrique.</td></tr>`;
                    return;
                }

                let totalNet = 0;
                result.data.forEach(acc => {
                    let net = parseFloat(acc.debit) - parseFloat(acc.credit);
                    // Adjust sign based on OHADA rules (e.g. revenue accounts carry credit balance)
                    if(acc.account_code.startsWith('7') || acc.account_code.startsWith('40') || acc.account_code.startsWith('1')) net = -net;
                    
                    totalNet += net;

                    tbody.innerHTML += LPC.html`
                        <tr class="border-b border-gray-50 hover:bg-gray-50">
                            <td class="py-3 px-6"><span class="font-mono text-gray-500 font-black mr-2">${acc.account_code}</span> ${acc.account_name}</td>
                            <td class="py-3 px-6 text-right text-blue-600">${fmt(acc.debit)}</td>
                            <td class="py-3 px-6 text-right text-rose-600">${fmt(acc.credit)}</td>
                            <td class="py-3 px-6 text-right font-black bg-gray-50">${fmt(net)}</td>
                        </tr>`;
                });
                
                tbody.innerHTML += LPC.html`
                    <tr class="bg-gray-100 font-black text-gray-800 uppercase tracking-widest text-[10px]">
                        <td class="py-3 px-6 text-right" colspan="3">Total Intégré au Bilan/Résultat</td>
                        <td class="py-3 px-6 text-right text-sm">${fmt(totalNet)}</td>
                    </tr>`;

            } catch(e) { tbody.innerHTML = LPC.html`<tr><td colspan="4" class="py-8 text-center text-rose-500">Erreur de chargement.</td></tr>`; }
        }

        function updateTaxIndicator() {
            // Rewritten to drive off company_tax_settings (régime + rates)
            // returned by the API rather than the old hard-coded 30% / 5.5%.
            //
            // Server contract (see api/v1/financials_controller.php → build_tax_simulation):
            //   tax_simulation: {
            //     regime:      'reel' | 'simplifie' | 'forfait' | 'non_professionnel',
            //     cit_rate:    0.30,   // IS
            //     air_rate:    0.055,  // AIR / minimum de perception on turnover
            //     min_perception_base: 12345678, // turnover class 7*
            //     is_on_profit:        3703703, // profit * cit_rate (0 if loss)
            //     is_minimum:          678913,  // turnover * air_rate  (régime réel floor)
            //     is_due:              max(profit, minimum) — whichever applies for the régime
            //     air_paid:            balance of 4471 (acompte IS / minimum de perception)
            //     air_withheld:        balance of 4424 (AIR withheld by clients)
            //     credit_reportable:   |négatif|, si en crédit
            //     label:               short human-readable rule ("Régime Réel — IS 30%")
            //   }
            const panel = document.getElementById('tax_indicator_panel');
            const textEl = document.getElementById('tax_status_text');
            if (!textEl) return;

            const ts = (financialData && financialData.tax_simulation) || null;
            if (!ts) {
                setTaxIndicatorError('config');
                return;
            }

            // Rewrite the small caption above the value with the actual regime + rate
            const caption = panel ? panel.querySelector('p') : null;
            if (caption && ts.label) caption.textContent = ts.label;

            const due = Number(ts.is_due || 0);
            const credit = Number(ts.air_paid || 0) + Number(ts.air_withheld || 0);
            const toPay = due - credit;

            textEl.className = 'text-sm font-black leading-tight';
            if (Math.abs(toPay) < 1) {
                textEl.classList.add('text-gray-700');
                textEl.innerHTML = LPC.html`Impôt couvert intégralement par acomptes/AIR.`;
            } else if (toPay > 0) {
                textEl.classList.add('text-rose-700');
                textEl.innerHTML = LPC.html`IS dû: ${fmt(due)} F — Acomptes: ${fmt(credit)} F → <span class="text-rose-600 font-black" title="Reste à Payer">Reliquat: ${fmt(toPay)} F</span>`;
            } else {
                textEl.classList.add('text-emerald-700');
                textEl.innerHTML = LPC.html`IS dû: ${fmt(due)} F — Acomptes: ${fmt(credit)} F → <span class="text-emerald-600 font-black" title="Crédit d'Impôt">Crédit reportable: ${fmt(Math.abs(toPay))} F</span>`;
            }
        }

        // ================= CLÔTURE (Answer 10B) ================= //
        async function initiateClosure() {
            const year = document.getElementById('report_year').value;
            
            // UI Check: Is the Bilan balanced?
            let totalActif = 0; let totalPassif = 0;
            financialData.bilan_actif.forEach(l => totalActif += l.net_N);
            financialData.bilan_passif.forEach(l => {
                if(l.code === 'RES_NET') totalPassif += financialData.resultat_net_N;
                else totalPassif += l.net_N;
            });
            
            // OHADA integrity: Bilan must balance exactly. Any FCFA difference is
            // a real accounting error (not rounding — FCFA has no subunit and all
            // amounts are stored as DECIMAL(15,2)).
            const bilanDelta = Math.round(totalActif - totalPassif);
            if (bilanDelta !== 0) {
                return LPC.modal.alert(`ERREUR FATALE: Le Bilan n'est pas équilibré (Écart: ${fmt(bilanDelta)} F).\n\nVérifiez :\n· Les comptes non mappés dans "Autres Actifs/Passifs"\n· Les écritures avec débit ≠ crédit (trigger MariaDB devrait bloquer)\n· Les OHADA mappings du plan comptable (chart_of_accounts.ohada_account_id)\n\nClôture impossible tant que l'écart ne sera pas résolu à 0 FCFA.`);
            }

            if(!(await LPC.modal.confirm(`⚠️ ATTENTION ⚠️\nVous êtes sur le point de CLÔTURER définitivement l'exercice ${year}.\n\nCette action va :\n1. Verrouiller toutes les écritures de ${year}.\n2. Générer le journal des A-Nouveaux pour ${parseInt(year)+1}.\n3. Solder les comptes de gestion (Classe 6 et 7).\n\nAction IRREVERSIBLE. Confirmez-vous ?`))) return;

            const btn = document.getElementById('btn_cloture');
            btn.innerHTML = LPC.html`<i class="fas fa-spinner fa-spin"></i> Clôture en cours...`; btn.disabled = true;

            try {
                const res = await fetch('/api/v1/financials_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'close_year', year: year })
                });
                const result = await res.json();
                LPC.modal.alert(result.message);
                if(result.status === 'success') location.reload(); // Hard reload to reset state
            } catch(e) { LPC.modal.alert("Erreur serveur lors de la clôture."); }
            
            btn.innerHTML = LPC.html`<i class="fas fa-lock"></i> Clôturer l'Exercice`; btn.disabled = false;
        }

        // =========================================================
        // SPRINT 7A · D3 — Bilan / Résultat PDF + CSV export
        // ---------------------------------------------------------
        // Server-side dompdf via financials_controller::export_pdf.
        // XLSX (SheetJS) is out of scope; CSV covers the "Excel"
        // requirement per D3 acceptance criteria. Full .xlsx via
        // PhpSpreadsheet remains a phase-2 follow-up.
        // =========================================================
        function exportToPDF() {
            const year = document.getElementById('report_year').value;
            const kind = (currentTab === 'bilan')      ? 'bilan'
                       : (currentTab === 'resultat')   ? 'resultat'
                       : /* dashboard tab uses its own button */ 'both';
            const url = `/api/v1/financials_controller.php?action=export_pdf&year=${encodeURIComponent(year)}&kind=${encodeURIComponent(kind)}`;
            // Server responds application/pdf as attachment → browser download.
            window.location.href = url;
        }

        function exportToExcel() {
            const year = document.getElementById('report_year').value;
            const kind = (currentTab === 'bilan')      ? 'bilan'
                       : (currentTab === 'resultat')   ? 'resultat'
                       : 'both';
            const url = `/api/v1/financials_controller.php?action=export_csv&year=${encodeURIComponent(year)}&kind=${encodeURIComponent(kind)}`;
            window.location.href = url;
        }

        // =========================================================
        // SPRINT 7A · D2 — Vue Dirigeant (executive summary) tab
        // =========================================================
        let vdCashflowChart = null;
        let vueDirigeantLoaded = false;

        // Hook the existing switchTab to lazy-load the Vue Dirigeant on first
        // display and refresh whenever the report year changes while on it.
        const _originalSwitchTab = switchTab;
        switchTab = function(tab) {
            _originalSwitchTab(tab);
            if (tab === 'dashboard') loadVueDirigeant();
        };
        const _originalFetchFinancials = fetchFinancials;
        fetchFinancials = async function() {
            await _originalFetchFinancials();
            if (currentTab === 'dashboard') { vueDirigeantLoaded = false; loadVueDirigeant(); }
        };

        async function loadVueDirigeant() {
            if (vueDirigeantLoaded) return;
            const year = document.getElementById('report_year').value;
            const loading = document.getElementById('vue_dirigeant_loading');
            const body    = document.getElementById('vue_dirigeant_body');
            if (loading) loading.classList.remove('hidden');
            if (body)    body.classList.add('hidden');

            try {
                const res = await fetch(`/api/v1/financials_controller.php?action=vue_dirigeant&year=${encodeURIComponent(year)}`,
                    { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message || 'error');
                renderVueDirigeant(result.data);
                vueDirigeantLoaded = true;
            } catch (e) {
                console.error('vue_dirigeant fetch error', e);
                if (loading) loading.innerHTML = LPC.html`<span class="text-rose-500 font-bold">Erreur de chargement de la Vue Dirigeant.</span>`;
                return;
            } finally {
                if (loading) loading.classList.add('hidden');
                if (body)    body.classList.remove('hidden');
            }
        }

        function pctFr(n, d) {
            if (n === null || n === undefined || isNaN(n)) return '—';
            return Number(n).toFixed(d === undefined ? 1 : d).replace('.', ',') + ' %';
        }

        function renderVueDirigeant(d) {
            const r = d.ratios || {};
            document.getElementById('vd_margin_gross').innerText   = pctFr(r.margin_gross_pct);
            document.getElementById('vd_margin_gross_amount').innerText = LPC.fmt.fcfa((r.revenue || 0) - (r.cogs || 0));
            document.getElementById('vd_ebe_pct').innerText        = pctFr(r.ebe_pct);
            document.getElementById('vd_ebe_value').innerText      = LPC.fmt.fcfa(r.ebe_value || 0);
            document.getElementById('vd_net_margin').innerText     = pctFr(r.net_margin_pct);
            document.getElementById('vd_net_value').innerText      = LPC.fmt.fcfa(r.net_result || 0);
            document.getElementById('vd_dso').innerText            = (r.dso_days || 0).toFixed(1).replace('.', ',') + ' j';
            document.getElementById('vd_ar_balance').innerText     = LPC.fmt.fcfa(r.ar_balance || 0);
            document.getElementById('vd_dpo').innerText            = (r.dpo_days || 0).toFixed(1).replace('.', ',') + ' j';
            document.getElementById('vd_ap_balance').innerText     = LPC.fmt.fcfa(r.ap_balance || 0);

            const cash = d.cash || {};
            document.getElementById('vd_cash_total').innerText     = LPC.fmt.fcfa(cash.total || 0);
            const cbody = document.getElementById('vd_cash_breakdown');
            cbody.innerHTML = '';
            (cash.breakdown || []).forEach(item => {
                cbody.innerHTML += LPC.html`
                    <li class="flex justify-between items-center border-b border-gray-100 pb-1">
                        <span class="text-gray-600">${item.name} <span class="text-[9px] text-gray-400 uppercase">${item.type}</span></span>
                        <span class="font-black text-gray-900">${LPC.fmt.fcfa(item.balance)}</span>
                    </li>`;
            });

            // Cashflow line
            const cf = d.cashflow_90d || {};
            const ctx = document.getElementById('vd_cashflow_chart').getContext('2d');
            if (vdCashflowChart) vdCashflowChart.destroy();
            vdCashflowChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: cf.labels || [],
                    datasets: [
                        { label: 'Encaissements', data: cf.inflow  || [], borderColor: '#059669', backgroundColor: 'rgba(16,185,129,0.10)', tension: 0.25, pointRadius: 0 },
                        { label: 'Décaissements', data: cf.outflow || [], borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.10)',  tension: 0.25, pointRadius: 0 },
                        { label: 'Net',           data: cf.net     || [], borderColor: '#3730a3', backgroundColor: 'rgba(55,48,163,0.05)',  tension: 0.25, borderDash: [4,4], pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: true, labels: { boxWidth: 10, font: { size: 10 } } } },
                    scales: {
                        x: { ticks: { autoSkip: true, maxTicksLimit: 8, font: { size: 9 } } },
                        y: { ticks: { font: { size: 9 }, callback: (v) => LPC.fmt.int(v) } }
                    }
                }
            });

            // Debtors / Suppliers
            const td = document.getElementById('vd_top_debtors');
            td.innerHTML = '';
            (d.top_debtors || []).forEach(x => {
                td.innerHTML += LPC.html`
                    <tr>
                        <td class="py-1 pr-2"><span class="font-bold text-gray-800">${x.client_name}</span> <span class="text-[9px] text-gray-400">${x.client_code}</span></td>
                        <td class="py-1 text-right font-black text-gray-900">${LPC.fmt.fcfa(x.total_due)}</td>
                    </tr>`;
            });
            if (!(d.top_debtors || []).length) td.innerHTML = LPC.html`<tr><td colspan="2" class="py-2 italic text-gray-400">Aucun débiteur.</td></tr>`;

            const ts = document.getElementById('vd_top_suppliers');
            ts.innerHTML = '';
            (d.top_suppliers || []).forEach(x => {
                ts.innerHTML += LPC.html`
                    <tr>
                        <td class="py-1 pr-2"><span class="font-bold text-gray-800">${x.supplier_name}</span> <span class="text-[9px] text-gray-400">${x.supplier_code || ''}</span></td>
                        <td class="py-1 text-right font-black text-gray-900">${LPC.fmt.fcfa(x.outstanding)}</td>
                    </tr>`;
            });
            if (!(d.top_suppliers || []).length) ts.innerHTML = LPC.html`<tr><td colspan="2" class="py-2 italic text-gray-400">Aucun fournisseur en attente.</td></tr>`;

            // Snapshot
            const veh = document.getElementById('vd_vehicles');
            veh.innerHTML = '';
            (d.snapshot?.vehicles_by_status || []).forEach(vs => {
                veh.innerHTML += LPC.html`
                    <li class="flex justify-between border-b border-gray-100 py-1">
                        <span class="text-gray-500 uppercase tracking-widest text-[10px] font-black">${vs.status}</span>
                        <span class="font-black text-gray-900">${LPC.fmt.int(vs.count)}</span>
                    </li>`;
            });
            document.getElementById('vd_payroll_month').innerText     = LPC.fmt.fcfa(d.snapshot?.payroll_month || 0);
            document.getElementById('vd_payroll_month_lbl').innerText = d.snapshot?.payroll_month_ymd || '';
            document.getElementById('vd_fuel_month').innerText        = LPC.fmt.fcfa(d.snapshot?.fuel_month || 0);
            document.getElementById('vd_fuel_month_lbl').innerText    = d.snapshot?.fuel_month_ymd || '';

            // Alerts
            document.getElementById('vd_alert_je').innerText       = LPC.fmt.int(d.alerts?.je_pending_30d || 0);
            document.getElementById('vd_alert_overdue').innerText  = LPC.fmt.int(d.alerts?.overdue_payments || 0);
            document.getElementById('vd_alert_budget').innerText   = LPC.fmt.int(d.alerts?.budgets_over_90pct || 0);
        }

        function printExecutiveSummary() {
            // Ensure the Vue Dirigeant tab is the visible one before printing.
            switchTab('dashboard');
            // Give the browser a tick to paint the tab, then invoke the OS
            // print dialog — the @media print block in this page strips
            // sidebar/nav/header and keeps only #content-dashboard.
            setTimeout(() => { try { window.print(); } catch (e) { console.error(e); } }, 100);
        }

        function exportExecutiveSummaryPDF() {
            const year = document.getElementById('report_year').value;
            const url  = `/api/v1/financials_controller.php?action=export_pdf&year=${encodeURIComponent(year)}&kind=executive`;
            window.location.href = url;
        }

        // Expose to inline onclick handlers.
        window.exportToPDF                 = exportToPDF;
        window.exportToExcel               = exportToExcel;
        window.printExecutiveSummary       = printExecutiveSummary;
        window.exportExecutiveSummaryPDF   = exportExecutiveSummaryPDF;

        // =========================================================
        // SPRINT 8B — TFT / Notes / Clôture wizard
        // ---------------------------------------------------------
        // Lazy-loads each tab on first activation and on year change.
        // =========================================================
        let tftLoaded = false, notesLoaded = false, clotureLoaded = false;
        const _origSwitchTab8B = switchTab;
        switchTab = function(tab) {
            _origSwitchTab8B(tab);
            if (tab === 'tft'     && !tftLoaded)     loadTft();
            if (tab === 'notes'   && !notesLoaded)   loadNotes();
            if (tab === 'cloture' && !clotureLoaded) loadCloture();
        };
        const _origFetchFinancials8B = fetchFinancials;
        fetchFinancials = async function() {
            await _origFetchFinancials8B();
            // Force reload of downstream tabs the next time they are shown
            tftLoaded = notesLoaded = clotureLoaded = false;
            if (currentTab === 'tft')     loadTft();
            if (currentTab === 'notes')   loadNotes();
            if (currentTab === 'cloture') loadCloture();
        };

        async function loadTft() {
            const year = document.getElementById('report_year').value;
            const tbody = document.getElementById('tbody-tft');
            const recEl = document.getElementById('tft_reconciliation');
            tbody.innerHTML = LPC.html`<tr><td colspan="3" class="py-8 text-center text-gray-400 italic"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement du TFT…</td></tr>`;
            try {
                const res = await fetch(`/api/v1/financials_controller.php?action=tft&year=${year}`);
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message || 'TFT');
                tbody.innerHTML = '';
                (result.data.lines || []).forEach(l => {
                    const isTotal = l.sign === '=' || l.activity === 'total';
                    tbody.innerHTML += LPC.html`
                        <tr class="${isTotal ? 'bg-cyan-50 font-black' : 'hover:bg-gray-50'}">
                            <td class="py-2 px-4 text-gray-400 font-mono">${l.code}</td>
                            <td class="py-2 px-4 ${isTotal ? 'uppercase tracking-widest text-cyan-900 text-[11px]' : ''}">${l.name}</td>
                            <td class="py-2 px-4 text-right ${isTotal ? 'text-cyan-900' : ''}">${fmt(l.value || 0)}</td>
                        </tr>`;
                });
                const rec = result.data.reconciled ? '✓ Rapprochement OK' : `⚠ Écart: ${fmt(result.data.delta)} F`;
                recEl.className = 'text-xs font-black ' + (result.data.reconciled ? 'text-emerald-600' : 'text-rose-600');
                recEl.textContent = rec;
                tftLoaded = true;
            } catch (e) {
                console.error('TFT', e);
                const msg = (e && e.message) ? e.message : 'inconnue';
                tbody.innerHTML = LPC.html`<tr><td colspan="3" class="py-8 text-center text-rose-500 text-xs"><i class="fas fa-exclamation-triangle mr-2"></i>Erreur de chargement du TFT — <span class="font-mono">${msg}</span></td></tr>`;
            }
        }

        async function loadNotes() {
            const year = document.getElementById('report_year').value;
            const box = document.getElementById('notes-container');
            box.innerHTML = LPC.html`<div class="text-center text-gray-400 py-12"><i class="fas fa-spinner fa-spin mr-2"></i>Génération des Notes…</div>`;
            try {
                const res = await fetch(`/api/v1/financials_controller.php?action=notes_annexes&year=${year}`);
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message || 'Notes');
                box.innerHTML = '';
                (result.data.notes || []).forEach(n => {
                    let table = `<table class="min-w-full text-xs"><thead class="bg-gray-50 text-[9px] uppercase text-gray-400 font-black tracking-widest"><tr>`;
                    n.columns.forEach(c => table += `<th class="py-2 px-3 text-left">${c}</th>`);
                    table += `</tr></thead><tbody class="divide-y divide-gray-100">`;
                    if (!n.rows.length) {
                        table += `<tr><td colspan="${n.columns.length}" class="py-3 px-3 italic text-gray-400">Aucune donnée à date.</td></tr>`;
                    } else {
                        n.rows.forEach(r => {
                            table += '<tr>';
                            r.forEach((v, i) => {
                                const isNum = typeof v === 'number';
                                table += `<td class="py-1.5 px-3 ${isNum ? 'text-right font-mono' : ''}">${isNum ? fmt(v) : (v ?? '')}</td>`;
                            });
                            table += '</tr>';
                        });
                    }
                    if (n.totals && n.totals.length) {
                        table += '<tr class="bg-gray-100 font-black">';
                        n.totals.forEach((v, i) => {
                            if (i === 0 && (v === null || typeof v !== 'number')) { table += `<td class="py-2 px-3 uppercase text-[10px] tracking-widest">${v || 'TOTAL'}</td>`; return; }
                            const isNum = typeof v === 'number';
                            table += `<td class="py-2 px-3 ${isNum ? 'text-right font-mono' : ''}">${isNum ? fmt(v) : ''}</td>`;
                        });
                        table += '</tr>';
                    }
                    table += '</tbody></table>';
                    const metaTxt = (n.meta && n.meta.note) ? `<p class="text-[10px] italic text-gray-500 mt-2">${n.meta.note}</p>` : '';
                    box.innerHTML += LPC.html`
                        <details open class="bg-white border border-gray-200 rounded-2xl shadow-sm">
                            <summary class="cursor-pointer px-5 py-3 flex justify-between items-center border-b border-gray-100">
                                <span class="font-black text-gray-800 text-sm">${n.code} — ${n.title}</span>
                                <span class="text-[10px] text-gray-400 font-bold">${n.rows.length} ligne(s)</span>
                            </summary>
                            <div class="p-4 overflow-auto">${LPC.raw(table)}${LPC.raw(metaTxt)}</div>
                        </details>`;
                });
                notesLoaded = true;
            } catch (e) {
                console.error('notes', e);
                box.innerHTML = LPC.html`<div class="text-rose-500 text-center py-6">Erreur de génération des Notes.</div>`;
            }
        }

        function exportNotesXlsx() {
            const year = document.getElementById('report_year').value;
            window.location.href = `/api/v1/financials_controller.php?action=export_notes_xlsx&year=${year}`;
        }

        async function loadCloture() {
            const year = document.getElementById('report_year').value;
            try {
                const res = await fetch(`/api/v1/financials_controller.php?action=closing_state&year=${year}`);
                const result = await res.json();
                if (result.status !== 'success') throw new Error(result.message || 'closing');
                const d = result.data;
                const badge = document.getElementById('cloture_state_badge');
                const colors = { open:'bg-gray-100 text-gray-700', inventory:'bg-blue-100 text-blue-800',
                                 adjustments:'bg-amber-100 text-amber-800', provisional_closed:'bg-orange-100 text-orange-800',
                                 locked:'bg-rose-100 text-rose-800', final_closed:'bg-slate-800 text-white' };
                const st = d.state || 'open';
                badge.className = 'text-[10px] font-black uppercase tracking-widest px-3 py-1 rounded ' + (colors[st] || 'bg-gray-100');
                badge.textContent = st.replace('_',' ');

                // Preflight
                const pfEl = document.getElementById('cloture_preflight');
                pfEl.innerHTML = '';
                (d.preflight?.checks || []).forEach(c => {
                    const icon = c.ok ? '<i class="fas fa-check-circle text-emerald-500"></i>' : '<i class="fas fa-times-circle text-rose-500"></i>';
                    pfEl.innerHTML += LPC.html`
                        <div class="flex items-center gap-3 p-3 rounded-lg border border-gray-100">
                            <span class="text-lg">${LPC.raw(icon)}</span>
                            <div class="flex-1"><div class="font-bold text-sm">${c.label}</div><div class="text-[11px] text-gray-500">${c.detail}</div></div>
                        </div>`;
                });

                // Adjustments
                const adjEl = document.getElementById('cloture_adjustments');
                adjEl.innerHTML = '';
                if (!(d.adjustments || []).length) {
                    adjEl.innerHTML = LPC.html`<tr><td colspan="6" class="py-4 px-3 text-center italic text-gray-400">Aucune écriture d'inventaire enregistrée.</td></tr>`;
                } else {
                    d.adjustments.forEach(a => {
                        const canRev = a.status === 'posted' && !a.reversal_journal_entry_id;
                        adjEl.innerHTML += LPC.html`
                            <tr class="border-t border-gray-100">
                                <td class="py-2 px-3 font-bold uppercase text-[10px] text-gray-500">${a.kind}</td>
                                <td class="py-2 px-3 font-mono text-[10px] text-gray-500">${a.target_ref || '—'}</td>
                                <td class="py-2 px-3 text-right font-mono">${fmt(a.amount)}</td>
                                <td class="py-2 px-3">${a.description}</td>
                                <td class="py-2 px-3"><span class="px-2 py-0.5 rounded text-[9px] font-black uppercase ${a.status === 'reversed' ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'}">${a.status}</span></td>
                                <td class="py-2 px-3 text-right">${canRev ? LPC.raw(`<button onclick="reverseAdjustment(${a.id})" class="text-rose-600 text-[10px] font-bold hover:underline">Extourner</button>`) : ''}</td>
                            </tr>`;
                    });
                }
                clotureLoaded = true;
            } catch (e) {
                console.error('cloture', e);
                LPC.modal.alert('Erreur de chargement de l\'assistant de clôture.');
            }
        }

        async function runClosingPhase(phase) {
            const year = document.getElementById('report_year').value;
            if (!(await LPC.modal.confirm(`Lancer la phase « ${phase} » pour l'exercice ${year} ?\n\nLes écritures OD sont horodatées au 31/12 et postées avec statut « approved ». Chaque phase est idempotente (unique par année/nature/cible) — vous pouvez la rejouer sans doublon.`))) return;
            try {
                const res = await fetch('/api/v1/financials_controller.php', {
                    method: 'POST', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: 'closing_run_phase', year, phase })
                });
                const r = await res.json();
                LPC.modal.alert(r.message);
                if (r.status === 'success') { clotureLoaded = false; loadCloture(); }
            } catch (e) { LPC.modal.alert('Erreur serveur.'); }
        }

        async function closingTransition(to) {
            const year = document.getElementById('report_year').value;
            const msg = to === 'final_closed'
                ? `⚠ CLÔTURE DÉFINITIVE de l'exercice ${year}.\n\nAction IRREVERSIBLE. Les A-Nouveaux seront générés pour ${parseInt(year)+1}. Aucune écriture ne pourra plus être postée sur ${year}. Confirmez ?`
                : `Passer l'exercice ${year} en état « ${to} » ?`;
            if (!(await LPC.modal.confirm(msg))) return;
            try {
                const res = await fetch('/api/v1/financials_controller.php', {
                    method: 'POST', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: to === 'final_closed' ? 'close_year' : 'closing_transition', year, to })
                });
                const r = await res.json();
                LPC.modal.alert(r.message);
                if (r.status === 'success') { clotureLoaded = false; loadCloture(); fetchFinancials(); }
            } catch (e) { LPC.modal.alert('Erreur serveur.'); }
        }

        async function reverseAdjustment(id) {
            if (!(await LPC.modal.confirm('Extourner cette écriture ? Une écriture de contrepassation sera postée au 01/01 N+1.'))) return;
            try {
                const res = await fetch('/api/v1/financials_controller.php', {
                    method: 'POST', headers: {'Content-Type':'application/json'},
                    body: JSON.stringify({ action: 'closing_reverse_adj', adjustment_id: id })
                });
                const r = await res.json();
                LPC.modal.alert(r.message);
                if (r.status === 'success') { clotureLoaded = false; loadCloture(); }
            } catch (e) { LPC.modal.alert('Erreur serveur.'); }
        }

        window.runClosingPhase   = runClosingPhase;
        window.closingTransition = closingTransition;
        window.reverseAdjustment = reverseAdjustment;
        window.exportNotesXlsx   = exportNotesXlsx;
