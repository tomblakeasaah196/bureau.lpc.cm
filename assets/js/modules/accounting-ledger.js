/**
 * assets/js/modules/accounting-ledger.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — extracted from modules/accounting/ledger.php (Sprint 6 D2).
 *
 * Original block was ~15,787 chars inline. Moved here so the CSP
 * `script-src` can drop 'unsafe-inline'. Any SP5-tagged lines from
 * the Sprint 5 concurrency stream are preserved verbatim.
 * -----------------------------------------------------------------------------
 */
        let currentTab = 'balance';
        let globalAccounts = []; // Stores the Chart for the GL Dropdown
        
        const fmt = (num) => LPC.fmt.int(num || 0);

        window.onload = () => { switchTab('balance'); };

        function refreshAllTabs() { fetchTabData(currentTab); }

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
                const res = await fetch(`/api/v1/review_controller.php?action=read&tab=${tab}&year=${year}`);
                if(!res.ok) return;
                const result = await res.json();

                if (result.status === 'success') {
                    if(tab === 'balance') renderBalance(result.data.balance);
                    if(tab === 'tiers') renderTiers(result.data.tiers);
                    if(tab === 'grandlivre') {
                        // Populate dropdown only once if empty
                        if(document.getElementById('gl_account_select').options.length <= 1) {
                            populateGLDropdown(result.data.accounts);
                        }
                    }
                }
            } catch(e) { console.error("Fetch error", e); }
        }

        // ================= TAB 1: BALANCE GÉNÉRALE ================= //
        function renderBalance(data) {
            const tbody = document.getElementById('tbody-balance');
            tbody.innerHTML = '';
            
            // Iterate over Classes (1 to 9)
            Object.keys(data).forEach(classNum => {
                const cls = data[classNum];
                
                // Add Class Header
                tbody.innerHTML += LPC.html`
                    <tr class="row-class">
                        <td class="py-2 px-4">CLASSE ${classNum}</td>
                        <td colspan="6"></td>
                    </tr>`;
                
                // Iterate over Masters (e.g., 411)
                Object.keys(cls.masters).forEach(masterCode => {
                    const master = cls.masters[masterCode];
                    
                    // Add Master Header
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
                    
                    // Iterate over Auxiliary Accounts (e.g., 411001)
                    master.aux.forEach(aux => {
                        // Is it a zero row?
                        const isZero = (aux.ouv_d == 0 && aux.ouv_c == 0 && aux.mvt_d == 0 && aux.mvt_c == 0 && aux.clo_d == 0 && aux.clo_c == 0);
                        const rowClass = `row-aux ${isZero ? 'zero-row' : ''}`;
                        
                        // Anomaly detection (Answer 10A) - e.g., Class 5 (Asset) ending in Credit
                        let anomalyClass = '';
                        let iconHtml = '';
                        if ((classNum == '2' || classNum == '3' || classNum == '4' || classNum == '5') && aux.clo_c > 0 && aux.clo_d == 0) {
                            if(masterCode !== '401' && masterCode !== '422' && masterCode !== '431' && masterCode !== '443') {
                                anomalyClass = 'anomaly-text bg-red-50';
                                iconHtml = `<i class="fas fa-exclamation-triangle mr-1" title="Anomalie: Solde créditeur sur compte d'actif"></i>`;
                            }
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
        function renderTiers(data) {
            const tbody = document.getElementById('tbody-tiers');
            tbody.innerHTML = '';
            
            if(data.length === 0) return tbody.innerHTML = LPC.html`<tr><td colspan="6" class="py-8 text-center text-gray-400 italic">Aucun mouvement sur les tiers.</td></tr>`;

            data.forEach(t => {
                // LPC.raw: fmt() emits digits and spaces only. Unwrapped, the
                // opening balance column rendered its own <span> as text.
                let initial = t.solde_ouv > 0 ? LPC.raw(`<span class="text-emerald-600">D ${fmt(t.solde_ouv)}</span>`) : (t.solde_ouv < 0 ? LPC.raw(`<span class="text-rose-600">C ${fmt(Math.abs(t.solde_ouv))}</span>`) : '-');
                
                tbody.innerHTML += LPC.html`
                    <tr class="hover:bg-gray-50 border-b border-gray-100">
                        <td class="py-3 px-6"><span class="font-mono text-gray-500 mr-2">${t.code}</span> <span class="font-bold text-gray-800">${t.name}</span></td>
                        <td class="py-3 px-6 text-right text-[10px] font-black">${initial}</td>
                        <td class="py-3 px-6 text-right text-gray-500">${t.mvt_d > 0 ? fmt(t.mvt_d) : '-'}</td>
                        <td class="py-3 px-6 text-right text-gray-500">${t.mvt_c > 0 ? fmt(t.mvt_c) : '-'}</td>
                        <td class="py-3 px-6 text-right font-black text-indigo-700 bg-indigo-50/30">${t.solde_clo > 0 ? fmt(t.solde_clo) : '-'}</td>
                        <td class="py-3 px-6 text-right font-black text-rose-700 bg-rose-50/30">${t.solde_clo < 0 ? fmt(Math.abs(t.solde_clo)) : '-'}</td>
                    </tr>`;
            });
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
            const tbody = document.getElementById('tbody-gl');
            
            if(!accId) {
                tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-gray-400 font-bold italic">Veuillez sélectionner un compte ci-dessus.</td></tr>`;
                document.getElementById('gl_current_balance').innerText = '0 F';
                return;
            }

            tbody.innerHTML = LPC.html`<tr><td colspan="8" class="py-12 text-center text-gray-400 font-bold"><i class="fas fa-spinner fa-spin mr-2"></i>Chargement...</td></tr>`;

            try {
                const res = await fetch(`/api/v1/review_controller.php?action=get_grand_livre&account_id=${accId}&year=${year}`);
                const result = await res.json();
                
                if(result.status === 'success') {
                    tbody.innerHTML = '';
                    let runningBalance = 0; // Negative means credit

                    // 1. Render Opening Balance Row
                    runningBalance += result.data.opening_balance;
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

                    // 2. Render Movements
                    if(result.data.lines.length === 0) {
                        tbody.innerHTML += LPC.html`<tr><td colspan="8" class="py-4 text-center text-gray-400 italic">Aucun mouvement durant cet exercice.</td></tr>`;
                    } else {
                        result.data.lines.forEach(l => {
                            runningBalance += (parseFloat(l.debit) - parseFloat(l.credit));
                            let soldeClass = runningBalance >= 0 ? 'text-emerald-700' : 'text-rose-700';
                            let soldePrefix = runningBalance >= 0 ? 'D ' : 'C ';

                            // Interactive Link (Answer 6B) - Clicking Reference theoretically opens the entry
                            let refHtml = LPC.html`<a href="#" onclick="LPC.modal.alert('Ouverture de la pièce: ${l.reference}')" class="text-rev-highlight hover:underline font-bold">${l.reference}</a>`;

                            tbody.innerHTML += LPC.html`
                                <tr class="hover:bg-gray-50 border-b border-gray-100">
                                    <td class="py-2 px-4">${l.date}</td>
                                    <td class="py-2 px-4 font-black text-gray-400">${l.journal_code}</td>
                                    <td class="py-2 px-4 text-[10px]">${LPC.raw(refHtml)}</td>
                                    <td class="py-2 px-4 truncate max-w-[200px] text-gray-600" title="${l.description}">${l.description}</td>
                                    <td class="py-2 px-4 text-center">
                                        <input type="text" maxlength="2" value="${l.lettrage || ''}" onchange="saveLettrage(${l.id}, this.value)" class="lettrage-input bg-amber-50 border border-amber-200 rounded text-amber-800 outline-none focus:ring-1 focus:ring-amber-500">
                                    </td>
                                    <td class="py-2 px-4 text-right">${l.debit > 0 ? fmt(l.debit) : '-'}</td>
                                    <td class="py-2 px-4 text-right">${l.credit > 0 ? fmt(l.credit) : '-'}</td>
                                    <td class="py-2 px-4 text-right font-black ${soldeClass} bg-gray-50">${soldePrefix}${fmt(Math.abs(runningBalance))}</td>
                                </tr>`;
                        });
                    }

                    // Update Top Balance Display
                    let finalClass = runningBalance >= 0 ? 'text-emerald-600' : 'text-rose-600';
                    let finalPrefix = runningBalance >= 0 ? 'Débiteur ' : 'Créditeur ';
                    document.getElementById('gl_current_balance').innerHTML = LPC.html`<span class="${finalClass}">${finalPrefix}${fmt(Math.abs(runningBalance))} F</span>`;

                }
            } catch(e) { console.error(e); }
        }

        // Lettrage API (Answer 7A)
        async function saveLettrage(lineId, letter) {
            try {
                await fetch('/api/v1/review_controller.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ action: 'save_lettrage', line_id: lineId, lettrage: letter.toUpperCase() })
                });
                // Background save, no alert needed to keep flow fast
            } catch(e) { console.error("Lettrage save failed"); }
        }

        // Simple CSV Exporter (Answer 8A)
        function exportCSV(tableId, filename) {
            let csv = [];
            const rows = document.querySelectorAll(`#${tableId} tr:not(.zero-row)`); // Ignore hidden zeros
            
            for (let i = 0; i < rows.length; i++) {
                let row = [], cols = rows[i].querySelectorAll("td, th");
                for (let j = 0; j < cols.length; j++) {
                    // Clean inner text (remove newlines, extra spaces)
                    let data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ").trim();
                    row.push('"' + data + '"');
                }
                csv.push(row.join(","));
            }
            
            const csvFile = new Blob(["\ufeff" + csv.join("\n")], {type: "text/csv;charset=utf-8;"});
            const link = document.createElement("a");
            link.download = `${filename}_LPC_${document.getElementById('global_year_filter').value}.csv`;
            link.href = window.URL.createObjectURL(csvFile);
            link.style.display = "none";
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

    