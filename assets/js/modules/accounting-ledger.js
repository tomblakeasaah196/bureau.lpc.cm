/**
 * assets/js/modules/accounting-ledger.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Révision Comptable (Balance à 6 colonnes, Tiers, Grand Livre).
 *
 * Post-audit (migration 080) additions:
 *   - Loads the exercise dropdown from /api/v1/review_controller?action=get_years
 *     rather than hardcoding a fixed pair.
 *   - Renders the mandatory OHADA "Total Général" row in <tfoot> for both
 *     Balance à 6 colonnes and Balance des Tiers, with a visible balanced /
 *     unbalanced badge.
 *   - Adds an unlettered / lettered / all filter on the Grand Livre.
 *   - Adds a lettrage-integrity chip row so an accountant sees at a glance
 *     which letters on an account are balanced (green) or open (amber).
 *   - Adds a one-click auto-lettrage that matches unlettered debits and
 *     credits of equal amount on the current account.
 *   - Removes the fake "Ouverture de la pièce" modal alert — the reference
 *     column is now a plain, copyable identifier until a real source-drawer
 *     ships.
 *
 * Sprint-5 concurrency-safety notes carried over verbatim.
 * -----------------------------------------------------------------------------
 */
        let currentTab = 'balance';
        let globalAccounts = []; // Stores the Chart for the GL Dropdown

        const fmt = (num) => LPC.fmt.int(num || 0);

        window.onload = async () => {
            await populateYearFilter();
            switchTab('balance');
        };

        function refreshAllTabs() { fetchTabData(currentTab); }

        // ================= YEAR DROPDOWN (dynamic) ================= //
        async function populateYearFilter() {
            const sel = document.getElementById('global_year_filter');
            try {
                const res = await fetch('/api/v1/review_controller.php?action=get_years');
                const result = await res.json();
                if (result.status !== 'success') return;
                const years = result.data.years || [];
                if (!years.length) return;

                const currentYear = new Date().getFullYear();
                sel.innerHTML = '';
                years.forEach(y => {
                    const yr = Number(y.year);
                    const badge = y.status === 'closed' ? ' · Clôturé' : '';
                    sel.innerHTML += LPC.html`<option value="${yr}" ${yr === currentYear ? 'selected' : ''}>${yr}${badge}</option>`;
                });
            } catch(e) { /* keep the hardcoded fallback option */ }
        }

        async function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab-link').forEach(el => {
                el.classList.remove('border-rev-highlight', 'text-rev-dark', 'font-black');
                el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            });
            document.getElementById(`tab-${tab}`).classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            document.getElementById(`tab-${tab}`).classList.add('border-rev-highlight', 'text-rev-dark', 'font-black');

            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById(`content-${tab}`).classList.add('active');

            await fetchTabData(tab);
        }

        async function fetchTabData(tab) {
            const year = document.getElementById('global_year_filter').value;
            try {
                const res = await fetch(`/api/v1/review_controller.php?tab=${tab}&year=${year}`);
                if(!res.ok) return;
                const result = await res.json();

                if (result.status === 'success') {
                    if(tab === 'balance') {
                        renderBalance(result.data.balance, result.data.grand, result.data.balanced);
                    }
                    if(tab === 'tiers') {
                        renderTiers(result.data.tiers, result.data.totals);
                    }
                    if(tab === 'grandlivre') {
                        if(document.getElementById('gl_account_select').options.length <= 1) {
                            populateGLDropdown(result.data.accounts);
                        }
                    }
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        // ================= TAB 1: BALANCE GÉNÉRALE ================= //
        function renderBalance(data, grand, balanced) {
            const tbody = document.getElementById('tbody-balance');
            const tfoot = document.getElementById('tfoot-balance');
            tbody.innerHTML = '';
            tfoot.innerHTML = '';

            // Iterate over Classes (1 to 9)
            Object.keys(data).sort().forEach(classNum => {
                const cls = data[classNum];

                // Class header + class subtotal row
                tbody.innerHTML += LPC.html`
                    <tr class="row-class">
                        <td class="py-2 px-4">CLASSE ${classNum}</td>
                        <td class="py-2 px-4 text-right text-[10px]">${fmt(cls.ouv_d)}</td>
                        <td class="py-2 px-4 text-right text-[10px]">${fmt(cls.ouv_c)}</td>
                        <td class="py-2 px-4 text-right text-[10px]">${fmt(cls.mvt_d)}</td>
                        <td class="py-2 px-4 text-right text-[10px]">${fmt(cls.mvt_c)}</td>
                        <td class="py-2 px-4 text-right text-[10px]">${fmt(cls.clo_d)}</td>
                        <td class="py-2 px-4 text-right text-[10px]">${fmt(cls.clo_c)}</td>
                    </tr>`;

                Object.keys(cls.masters).forEach(masterCode => {
                    const master = cls.masters[masterCode];

                    tbody.innerHTML += LPC.html`
                        <tr class="row-master">
                            <td class="py-2 px-4 pl-8">${masterCode} - ${master.name}</td>
                            <td class="py-2 px-4 text-right text-[10px]">${fmt(master.ouv_d)}</td>
                            <td class="py-2 px-4 text-right text-[10px]">${fmt(master.ouv_c)}</td>
                            <td class="py-2 px-4 text-right text-[10px]">${fmt(master.mvt_d)}</td>
                            <td class="py-2 px-4 text-right text-[10px]">${fmt(master.mvt_c)}</td>
                            <td class="py-2 px-4 text-right text-[10px]">${fmt(master.clo_d)}</td>
                            <td class="py-2 px-4 text-right text-[10px]">${fmt(master.clo_c)}</td>
                        </tr>`;

                    master.aux.forEach(aux => {
                        const isZero = (aux.ouv_d == 0 && aux.ouv_c == 0 && aux.mvt_d == 0 && aux.mvt_c == 0 && aux.clo_d == 0 && aux.clo_c == 0);
                        const rowClass = `row-aux ${isZero ? 'zero-row' : ''}`;

                        // Anomaly detection driven by class + master. A credit
                        // balance on an asset (class 2, 3, 5) or debit balance
                        // on a class 7 (produits) is flagged. Class 4 is
                        // ambiguous per SYSCOHADA: 40x/41x/42x/43x/44x split
                        // between debiteur (411, 425, 445 recuperable…) and
                        // crediteur (401, 422, 431, 443, 447…), so we only
                        // flag class 4 when both a credit balance appears on
                        // a code whose master is expected debiteur (411, 445).
                        let anomalyClass = '';
                        let iconHtml = '';
                        const expectedDebitMasters = ['411', '412', '413', '414', '425', '445', '4451', '4452'];
                        const isAssetClass = (classNum === '2' || classNum === '3' || classNum === '5');
                        const isRevenueClass = (classNum === '7');
                        const isReceivableMaster = expectedDebitMasters.includes(masterCode);
                        if (isAssetClass && aux.clo_c > 0 && aux.clo_d == 0) {
                            anomalyClass = 'anomaly-text bg-red-50';
                            iconHtml = `<i class="fas fa-exclamation-triangle mr-1" title="Anomalie: Solde créditeur sur compte d'actif"></i>`;
                        } else if (isReceivableMaster && aux.clo_c > 0 && aux.clo_d == 0) {
                            anomalyClass = 'anomaly-text bg-red-50';
                            iconHtml = `<i class="fas fa-exclamation-triangle mr-1" title="Anomalie: Solde créditeur sur compte débiteur attendu"></i>`;
                        } else if (isRevenueClass && aux.clo_d > 0 && aux.clo_c == 0) {
                            anomalyClass = 'anomaly-text bg-red-50';
                            iconHtml = `<i class="fas fa-exclamation-triangle mr-1" title="Anomalie: Solde débiteur sur compte de produits"></i>`;
                        }

                        tbody.innerHTML += LPC.html`
                            <tr class="${rowClass} ${anomalyClass}">
                                <td class="py-2 px-4 pl-12"><span class="font-mono text-gray-500 mr-2">${aux.code}</span> ${LPC.raw(iconHtml)}${aux.name}</td>
                                <td class="py-2 px-4 text-right">${aux.ouv_d > 0 ? fmt(aux.ouv_d) : '-'}</td>
                                <td class="py-2 px-4 text-right">${aux.ouv_c > 0 ? fmt(aux.ouv_c) : '-'}</td>
                                <td class="py-2 px-4 text-right">${aux.mvt_d > 0 ? fmt(aux.mvt_d) : '-'}</td>
                                <td class="py-2 px-4 text-right">${aux.mvt_c > 0 ? fmt(aux.mvt_c) : '-'}</td>
                                <td class="py-2 px-4 text-right font-black">${aux.clo_d > 0 ? fmt(aux.clo_d) : '-'}</td>
                                <td class="py-2 px-4 text-right font-black">${aux.clo_c > 0 ? fmt(aux.clo_c) : '-'}</td>
                            </tr>`;
                    });
                });
            });

            // OHADA-mandatory Total Général — sacred double-entry check row.
            if (grand) {
                const badgeClass = balanced ? 'bg-emerald-500 text-white' : 'bg-red-500 text-white';
                const badgeLabel = balanced ? 'ÉQUILIBRÉE' : 'DÉSÉQUILIBRÉE';
                tfoot.innerHTML = LPC.html`
                    <tr>
                        <td class="py-3 px-4">
                            TOTAL GÉNÉRAL
                            <span class="ml-3 px-2 py-0.5 rounded ${badgeClass} text-[9px] tracking-wider">${badgeLabel}</span>
                        </td>
                        <td class="py-3 px-4 text-right">${fmt(grand.ouv_d)}</td>
                        <td class="py-3 px-4 text-right">${fmt(grand.ouv_c)}</td>
                        <td class="py-3 px-4 text-right">${fmt(grand.mvt_d)}</td>
                        <td class="py-3 px-4 text-right">${fmt(grand.mvt_c)}</td>
                        <td class="py-3 px-4 text-right">${fmt(grand.clo_d)}</td>
                        <td class="py-3 px-4 text-right">${fmt(grand.clo_c)}</td>
                    </tr>`;
            }
        }

        // Toggle Zeros Switch Logic
        function toggleZeroRows() {
            const tbody = document.getElementById('tbody-balance');
            const chk = document.getElementById('toggle_zeros');
            const bg = document.getElementById('toggle_bg');
            const dot = document.getElementById('toggle_dot');

            if(chk.checked) {
                tbody.classList.add('show-zeros');
                bg.classList.replace('bg-gray-200', 'bg-rev-highlight');
                dot.style.transform = 'translateX(100%)';
            } else {
                tbody.classList.remove('show-zeros');
                bg.classList.replace('bg-rev-highlight', 'bg-gray-200');
                dot.style.transform = 'translateX(0)';
            }
        }

        // ================= TAB 2: BALANCE DES TIERS ================= //
        function renderTiers(data, totals) {
            const tbody = document.getElementById('tbody-tiers');
            const tfoot = document.getElementById('tfoot-tiers');
            tbody.innerHTML = '';
            tfoot.innerHTML = '';

            if(data.length === 0) { tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 italic">Aucun mouvement sur les tiers.</td></tr>`; return; }

            data.forEach(t => {
                let initial = t.solde_ouv > 0 ? LPC.raw(`<span class="text-emerald-600">D ${fmt(t.solde_ouv)}</span>`) : (t.solde_ouv < 0 ? LPC.raw(`<span class="text-rose-600">C ${fmt(Math.abs(t.solde_ouv))}</span>`) : '-');

                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td class="py-3 px-6">
                            <span class="font-mono text-gray-500 mr-2">${t.code}</span>
                            <span class="font-bold text-gray-800">${t.name}</span>
                            <span class="ml-2 text-[9px] font-black text-gray-400 uppercase tracking-widest">${t.master_code}</span>
                        </td>
                        <td class="py-3 px-6 text-right text-[10px] font-black">${initial}</td>
                        <td class="py-3 px-6 text-right text-gray-500">${t.mvt_d > 0 ? fmt(t.mvt_d) : '-'}</td>
                        <td class="py-3 px-6 text-right text-gray-500">${t.mvt_c > 0 ? fmt(t.mvt_c) : '-'}</td>
                        <td class="py-3 px-6 text-right font-black text-indigo-700 bg-indigo-50/30">${t.solde_clo > 0 ? fmt(t.solde_clo) : '-'}</td>
                        <td class="py-3 px-6 text-right font-black text-rose-700 bg-rose-50/30">${t.solde_clo < 0 ? fmt(Math.abs(t.solde_clo)) : '-'}</td>
                    </tr>`;
            });

            if (totals) {
                tfoot.innerHTML = LPC.html`
                    <tr>
                        <td class="py-3 px-6" colspan="4">TOTAUX TIERS</td>
                        <td class="py-3 px-6 text-right">${fmt(totals.debiteur)}</td>
                        <td class="py-3 px-6 text-right">${fmt(totals.crediteur)}</td>
                    </tr>`;
            }
        }

        // ================= TAB 3: GRAND LIVRE ================= //
        function populateGLDropdown(accounts) {
            const sel = document.getElementById('gl_account_select');
            accounts.forEach(a => {
                sel.innerHTML += LPC.html`<option value="${a.id}">[${a.code}] ${a.name}</option>`;
            });
        }

        async function fetchGrandLivre() {
            const accId = document.getElementById('gl_account_select').value;
            const year = document.getElementById('global_year_filter').value;
            const filter = document.getElementById('gl_lettrage_filter').value;
            const tbody = document.getElementById('tbody-gl');
            const lettersBox = document.getElementById('gl_letters_summary');

            if(!accId) {
                tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-gray-400 font-bold italic">Veuillez sélectionner un compte ci-dessus.</td></tr>`;
                document.getElementById('gl_current_balance').innerText = '0 F';
                lettersBox.classList.add('hidden');
                return;
            }

            tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-gray-400 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement...</td></tr>`;

            try {
                const res = await fetch(`/api/v1/review_controller.php?action=get_grand_livre&account_id=${accId}&year=${year}&lettrage_filter=${encodeURIComponent(filter)}`);
                const result = await res.json();

                if(result.status !== 'success') {
                    tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-8 text-center text-rose-600 font-bold">${result.message || 'Erreur.'}</td></tr>`;
                    return;
                }

                tbody.innerHTML = '';
                let runningBalance = result.data.opening_balance;

                // 1. Opening balance row
                let soldeOuvClass = runningBalance >= 0 ? 'text-emerald-700' : 'text-rose-700';
                let soldeOuvPrefix = runningBalance >= 0 ? 'D ' : 'C ';

                tbody.innerHTML += LPC.html`
                    <tr class="bg-blue-50/50 border-b border-blue-100 font-bold text-gray-700">
                        <td class="py-3 px-4">01/01/${year}</td>
                        <td class="py-3 px-4">AN</td>
                        <td class="py-3 px-4">A-NOUVEAUX</td>
                        <td class="py-3 px-4 italic">Solde d'Ouverture Reporté</td>
                        <td class="py-3 px-4 text-center">-</td>
                        <td class="py-3 px-4 text-right">${result.data.opening_balance > 0 ? fmt(result.data.opening_balance) : '-'}</td>
                        <td class="py-3 px-4 text-right">${result.data.opening_balance < 0 ? fmt(Math.abs(result.data.opening_balance)) : '-'}</td>
                        <td class="py-3 px-4 text-right font-black ${soldeOuvClass} bg-gray-100">${soldeOuvPrefix}${fmt(Math.abs(runningBalance))}</td>
                    </tr>`;

                // 2. Movements
                if(result.data.lines.length === 0) {
                    tbody.innerHTML += LPC.html`<tr><td colspan="8" class="py-4 text-center text-gray-400 italic">Aucun mouvement pour ce filtre.</td></tr>`;
                } else {
                    result.data.lines.forEach(l => {
                        runningBalance += (parseFloat(l.debit) - parseFloat(l.credit));
                        let soldeClass = runningBalance >= 0 ? 'text-emerald-700' : 'text-rose-700';
                        let soldePrefix = runningBalance >= 0 ? 'D ' : 'C ';

                        // Reference cell — plain text with a title showing the
                        // source document family. No more fake modal alert.
                        const srcTitle = l.source_type ? `Source: ${l.source_type} #${l.source_id}` : '';
                        let refHtml = LPC.html`<span class="font-mono text-gray-600" title="${srcTitle}">${l.reference}</span>`;

                        tbody.innerHTML += LPC.html`
                            <tr class="hover:bg-gray-50 border-b border-gray-100">
                                <td class="py-2 px-4">${l.date}</td>
                                <td class="py-2 px-4 font-black text-gray-400">${l.journal_code}</td>
                                <td class="py-2 px-4 text-[10px]">${LPC.raw(refHtml)}</td>
                                <td class="py-2 px-4 truncate max-w-[200px] text-gray-600" title="${l.description}">${l.description}</td>
                                <td class="py-2 px-4 text-center">
                                    <input type="text" maxlength="3" value="${l.lettrage || ''}" onchange="saveLettrage(${l.id}, this.value)" class="lettrage-input bg-amber-50 border border-amber-200 rounded text-amber-800 outline-none focus:ring-1 focus:ring-amber-500">
                                </td>
                                <td class="py-2 px-4 text-right">${l.debit > 0 ? fmt(l.debit) : '-'}</td>
                                <td class="py-2 px-4 text-right">${l.credit > 0 ? fmt(l.credit) : '-'}</td>
                                <td class="py-2 px-4 text-right font-black ${soldeClass} bg-gray-50">${soldePrefix}${fmt(Math.abs(runningBalance))}</td>
                            </tr>`;
                    });
                }

                // 3. Lettrage integrity chips
                renderLettersSummary(result.data.letters || {});

                // 4. Top balance
                let finalClass = runningBalance >= 0 ? 'text-emerald-600' : 'text-rose-600';
                let finalPrefix = runningBalance >= 0 ? 'Débiteur ' : 'Créditeur ';
                document.getElementById('gl_current_balance').innerHTML = LPC.html`<span class="${finalClass}">${finalPrefix}${fmt(Math.abs(runningBalance))} F</span>`;

            } catch(e) { console.error(e); }
        }

        function renderLettersSummary(letters) {
            const box = document.getElementById('gl_letters_summary');
            const chipsHost = document.getElementById('gl_letters_chips');
            const keys = Object.keys(letters);
            if (keys.length === 0) { box.classList.add('hidden'); return; }
            box.classList.remove('hidden');

            chipsHost.innerHTML = '';
            keys.sort().forEach(k => {
                const info = letters[k];
                const okClass  = info.balanced
                    ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                    : 'bg-amber-50 border-amber-300 text-amber-900';
                const status = info.balanced ? '✓ équilibrée' : `Δ ${fmt(Math.abs(info.sum_d - info.sum_c))}`;
                chipsHost.innerHTML += LPC.html`
                    <span class="px-3 py-1.5 rounded-full border ${okClass} text-[10px] font-black uppercase tracking-widest">
                        ${k} — Dr ${fmt(info.sum_d)} · Cr ${fmt(info.sum_c)} · ${status}
                    </span>`;
            });
        }

        // Lettrage API — validation surfaced to user via modal, not silent.
        async function saveLettrage(lineId, letter) {
            try {
                const res = await fetch('/api/v1/review_controller.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': LPC.csrf ? LPC.csrf.token() : ''},
                    body: JSON.stringify({ action: 'save_lettrage', line_id: lineId, lettrage: (letter || '').toUpperCase() })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    // Reload GL so the letters-summary reflects the change.
                    fetchGrandLivre();
                } else {
                    LPC.modal.alert(result.message || 'Le lettrage n\'a pas pu être enregistré.');
                }
            } catch(e) { console.error("Lettrage save failed", e); }
        }

        async function runAutoLettrage() {
            const accId = document.getElementById('gl_account_select').value;
            const year  = document.getElementById('global_year_filter').value;
            if (!accId) { LPC.modal.alert('Choisissez d\'abord un compte.'); return; }

            const confirmed = await LPC.modal.confirm(
                'Lettrage automatique',
                'Le module va rechercher des paires débit/crédit de même montant sur ce compte et leur assigner une lettre. Continuer ?'
            );
            if (!confirmed) return;

            try {
                const res = await fetch('/api/v1/review_controller.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': LPC.csrf ? LPC.csrf.token() : ''},
                    body: JSON.stringify({ action: 'auto_lettrage', account_id: Number(accId), year: Number(year) })
                });
                const result = await res.json();
                if (result.status === 'success') {
                    LPC.modal.alert(`Lettrage terminé : ${result.data.count} paire(s) trouvée(s).`);
                    fetchGrandLivre();
                } else {
                    LPC.modal.alert(result.message || 'Lettrage impossible.');
                }
            } catch(e) { console.error("Auto-lettrage failed", e); }
        }

        // CSV Exporter — clean copy of the DOM sans hidden zeros.
        function exportCSV(tableId, filename) {
            let csv = [];
            const rows = document.querySelectorAll(`#${tableId} tr:not(.zero-row)`);

            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length; j++) {
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                    row.push('"' + data.replace(/"/g, '""') + '"');
                }
                csv.push(row.join(","));
            }

            const csvFile = new Blob(["﻿" + csv.join("\n")], {type: "text/csv;charset=utf-8;"});
            const link = document.createElement("a");
            link.download = `${filename}_LPC_${document.getElementById('global_year_filter').value}.csv`;
            link.href = window.URL.createObjectURL(csvFile);
            link.style.display = "none";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
