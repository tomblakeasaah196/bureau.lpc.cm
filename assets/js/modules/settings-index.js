/**
 * assets/js/modules/settings-index.js
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Administration, Sécurité & Configuration.
 *
 * SPRINT 8 REWRITE
 * ----------------
 * The previous version defined `settingsConfig` for three tabs (users, sessions,
 * audits) but the page rendered five tab buttons. Clicking "Rôles (RBAC)" or
 * "Préférences" evaluated `settingsConfig[tabKey].kpis` where the config was
 * undefined, threw a TypeError inside switchTab(), and returned before touching
 * the DOM — leaving the previous tab's table on screen. That is exactly the
 * "empty pages that repeat the other tabs" symptom.
 *
 * This rewrite makes that class of bug impossible: every tab declares a SHAPE,
 * and switchTab() refuses to run for a tab with no registry entry, saying so
 * out loud instead of failing silently.
 *
 *   SHAPE 'table'   users · sessions · audits    -> data grid + KPI ribbon
 *   SHAPE 'form'    company · preferences        -> sectioned form, server-described
 *   SHAPE 'matrix'  roles                        -> RBAC permission grid
 *
 * The form tabs are rendered from a layout the SERVER sends. Adding a field to
 * the company sheet means adding a column and one line to the $layout array in
 * settings_controller.php — no JS change. This is deliberate: the previous
 * design duplicated column definitions between PHP and JS and they drifted.
 *
 * CSRF: assets/js/lpc-rbac.js monkey-patches window.fetch to attach the
 * X-CSRF-Token header to same-origin requests, so no token plumbing here.
 * -----------------------------------------------------------------------------
 */
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // 0. BOOT CONFIG
    // -------------------------------------------------------------------------
    const bootEl = document.getElementById('lpc-settings-boot');
    const BOOT = bootEl
        ? JSON.parse(bootEl.textContent)
        : { initialTab: 'users', lang: 'fr', can: {}, tabs: [] };
    const CAN = BOOT.can || {};
    const API = '/api/v1/settings_controller.php';
    const RBAC_API = '/api/v1/rbac_controller.php';

    // Module state
    let currentTab = BOOT.initialTab || 'users';
    let moduleData = [];      // rows for the active table tab
    let formState = null;     // { kind, original, dirty:Map, canEdit }
    let matrixState = null;   // { roles, activeRoleId, dirty }
    let rolesCache = null;    // role list for the user modal dropdown

    // -------------------------------------------------------------------------
    // 1. TAB REGISTRY
    //    One entry per tab button rendered by index.php. A tab button with no
    //    entry here is a bug, and switchTab() will say so rather than no-op.
    // -------------------------------------------------------------------------
    const TABS = {
        users: {
            shape: 'table',
            title: 'Utilisateurs Système',
            api: 'users',
            showActionBtn: true,
            btnText: 'Nouvel Utilisateur',
            kpis: [
                { id: 'kpi1', label: 'Total Comptes',  icon: 'fa-users',      color: 'text-blue-600',  bg: 'bg-blue-50' },
                { id: 'kpi2', label: 'Comptes Actifs', icon: 'fa-user-check', color: 'text-green-600', bg: 'bg-green-50' },
                { id: 'kpi3', label: 'Verrouillés',    icon: 'fa-user-lock',  color: 'text-red-600',   bg: 'bg-red-50' }
            ],
            columns: [
                { key: 'employee_code', label: 'Code',     render: v => `<span class="font-bold text-gray-500">${esc(v)}</span>` },
                { key: 'first_name',    label: 'Identité', render: (v, row) => `<span class="font-black text-gray-900">${esc(v)} ${esc(row.last_name)}</span>` },
                { key: 'role_name',     label: 'Rôle',     render: v => `<span class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-[10px] uppercase font-bold tracking-wider border border-gray-200">${esc(v || 'NON ASSIGNÉ')}</span>` },
                { key: 'status',        label: 'Accès',    render: v => v === 'active'
                    ? '<span class="text-green-500 text-xs font-bold"><i class="fas fa-check-circle mr-1"></i>Actif</span>'
                    : '<span class="text-red-500 text-xs font-bold"><i class="fas fa-lock mr-1"></i>Bloqué</span>' }
            ]
        },

        sessions: {
            shape: 'table',
            title: 'Sessions & Sécurité',
            api: 'sessions',
            showActionBtn: false,
            kpis: [
                { id: 'kpi1', label: 'Connectés (Actuels)',   icon: 'fa-signal',           color: 'text-emerald-500', bg: 'bg-emerald-50' },
                { id: 'kpi2', label: 'Échecs Mdp (24h)',      icon: 'fa-fingerprint',      color: 'text-amber-500',   bg: 'bg-amber-50' },
                { id: 'kpi3', label: 'Tentatives Intrusions', icon: 'fa-skull-crossbones', color: 'text-red-600',     bg: 'bg-red-50' }
            ],
            columns: [
                { key: 'created_at',       label: 'Horodatage',         render: v => `<span class="text-xs font-bold text-gray-600">${fmtDateTime(v)}</span>` },
                { key: 'login_identifier', label: 'Identifiant / Code', render: v => `<span class="font-black text-gray-900">${esc(v)}</span>` },
                { key: 'ip_address',       label: 'Adresse IP',         render: v => `<span class="text-xs font-mono text-gray-500 bg-gray-100 px-2 py-1 rounded border border-gray-200">${esc(v)}</span>` },
                { key: 'login_status',     label: 'Statut', render: v => {
                    const dict = {
                        'success':         '<span class="text-green-500 font-bold"><i class="fas fa-check mr-1"></i>Succès</span>',
                        'failed_password': '<span class="text-amber-500 font-bold"><i class="fas fa-times mr-1"></i>Mot de passe erroné</span>',
                        'user_not_found':  '<span class="text-red-500 font-bold"><i class="fas fa-user-ninja mr-1"></i>Inconnu (Intrusion)</span>',
                        'account_locked':  '<span class="text-red-600 font-bold"><i class="fas fa-ban mr-1"></i>Compte Bloqué</span>'
                    };
                    return dict[v] || esc(v);
                }},
                { key: 'logout_time', label: 'État Session', render: (v, row) => {
                    if (row.login_status !== 'success') return '-';
                    return v
                        ? `<span class="text-gray-400 text-xs font-medium">Déconnecté à ${fmtTime(v)}</span>`
                        : '<span class="text-emerald-500 text-xs font-bold"><i class="fas fa-circle text-[8px] mr-1"></i>En Ligne</span>';
                }}
            ]
        },

        audits: {
            shape: 'table',
            title: "Logs d'Audit",
            api: 'audits',
            showActionBtn: false,
            kpis: [
                { id: 'kpi1', label: 'Total Actions (24h)', icon: 'fa-database',  color: 'text-indigo-600', bg: 'bg-indigo-50' },
                { id: 'kpi2', label: 'Données Modifiées',   icon: 'fa-edit',      color: 'text-amber-600',  bg: 'bg-amber-50' },
                { id: 'kpi3', label: 'Données Supprimées',  icon: 'fa-trash-alt', color: 'text-red-600',    bg: 'bg-red-50' }
            ],
            columns: [
                { key: 'created_at', label: 'Horodatage',  render: v => `<span class="text-xs font-bold text-gray-500">${fmtDateTime(v)}</span>` },
                { key: 'first_name', label: 'Utilisateur', render: (v, row) => `<span class="font-bold text-gray-900">${esc(v || 'Système')} ${esc(row.last_name || '')}</span>` },
                { key: 'action',     label: 'Action', render: v => {
                    const color = v === 'DELETE' ? 'bg-red-100 text-red-700'
                                : v === 'UPDATE' ? 'bg-amber-100 text-amber-700'
                                : 'bg-blue-100 text-blue-700';
                    return `<span class="px-2 py-1 ${color} rounded text-[10px] font-black uppercase tracking-widest">${esc(v)}</span>`;
                }},
                { key: 'table_name', label: 'Table Impactée', render: (v, row) => `<span class="text-sm font-medium text-gray-600">${esc(v)} (ID: ${esc(row.record_id)})</span>` }
            ]
        },

        company:     { shape: 'form',   title: "Identité de l'entreprise", api: 'company' },
        preferences: { shape: 'form',   title: 'Préférences système',      api: 'preferences' },
        roles:       { shape: 'matrix', title: 'Rôles & Permissions' }
    };

    // -------------------------------------------------------------------------
    // 2. SMALL HELPERS
    // -------------------------------------------------------------------------

    /** HTML-escape. We build strings in loops, so escaping is explicit. */
    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtDateTime(v) {
        if (!v) return '';
        const d = new Date(String(v).replace(' ', 'T'));
        return isNaN(d.getTime()) ? esc(v) : d.toLocaleString('fr-FR');
    }

    function fmtTime(v) {
        if (!v) return '';
        const d = new Date(String(v).replace(' ', 'T'));
        return isNaN(d.getTime()) ? esc(v) : d.toLocaleTimeString('fr-FR');
    }

    function $(id) { return document.getElementById(id); }

    function show(el, on) {
        if (!el) return;
        el.classList.toggle('hidden', !on);
        // #panel-table and #toolbar are flex containers; `hidden` alone loses to
        // Tailwind's `flex`, so toggle the display class alongside it.
        if (el.id === 'panel-table' || el.id === 'toolbar') {
            el.classList.toggle('flex', !!on);
        }
    }

    function toast(msg, kind) {
        if (window.LPC && LPC.modal && LPC.modal.alert) {
            LPC.modal.alert(msg);
        } else {
            window.alert(msg);
        }
        if (kind === 'error') console.error('[settings]', msg);
    }

    async function confirmAsync(msg) {
        if (window.LPC && LPC.modal && LPC.modal.confirm) {
            return await LPC.modal.confirm(msg);
        }
        return window.confirm(msg);
    }

    async function promptAsync(msg, opts) {
        if (window.LPC && LPC.modal && LPC.modal.prompt) {
            return await LPC.modal.prompt(msg, opts);
        }
        return window.prompt(msg, (opts && opts.defaultValue) || '');
    }

    /** fetch + JSON + uniform error surface. */
    async function apiGet(url) {
        const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const body = await res.json().catch(() => ({ status: 'error', message: `Réponse illisible (HTTP ${res.status})` }));
        if (!res.ok || body.status !== 'success') {
            throw new Error(body.message || `HTTP ${res.status}`);
        }
        return body.data;
    }

    async function apiPost(url, formData) {
        const res = await fetch(url, { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } });
        const body = await res.json().catch(() => ({ status: 'error', message: `Réponse illisible (HTTP ${res.status})` }));
        if (!res.ok || body.status !== 'success') {
            throw new Error(body.message || `HTTP ${res.status}`);
        }
        return body;
    }

    // -------------------------------------------------------------------------
    // 3. TAB ROUTER
    // -------------------------------------------------------------------------

    async function switchTab(tabKey) {
        const config = TABS[tabKey];

        // The bug this rewrite exists to prevent: a nav button with no renderer.
        // Fail loudly instead of leaving the previous tab's content on screen.
        if (!config) {
            console.error(`[settings] No registry entry for tab "${tabKey}".`);
            hideAllPanels();
            renderError(`Onglet « ${tabKey} » non implémenté. Signalez cette erreur à l'administrateur.`);
            return;
        }

        // Warn before discarding unsaved edits.
        if (formState && formState.dirty.size > 0) {
            const n = formState.dirty.size;
            const ok = await confirmAsync(`Vous avez ${n} modification${n > 1 ? 's' : ''} non enregistrée${n > 1 ? 's' : ''}. Quitter cet onglet les abandonnera. Continuer ?`);
            if (!ok) return;
        }
        if (matrixState && matrixState.dirty) {
            const ok = await confirmAsync('Les permissions modifiées ne sont pas enregistrées. Continuer ?');
            if (!ok) return;
        }

        currentTab = tabKey;
        formState = null;
        matrixState = null;

        paintTabBar(tabKey);
        hideAllPanels();

        // Keep the URL in step so the tab is linkable and survives a refresh.
        try {
            const u = new URL(window.location.href);
            u.searchParams.set('tab', tabKey);
            window.history.replaceState({}, '', u);
        } catch (e) { /* non-fatal */ }

        switch (config.shape) {
            case 'table':  return renderTableTab(config);
            case 'form':   return renderFormTab(config);
            case 'matrix': return renderMatrixTab(config);
            default:
                renderError(`Forme d'onglet inconnue : ${config.shape}`);
        }
    }

    /** switchTab without the unsaved-changes prompt (used after a successful save). */
    async function switchTabForce(tabKey) {
        formState = null;
        matrixState = null;
        return switchTab(tabKey);
    }

    function paintTabBar(tabKey) {
        document.querySelectorAll('.tab-link').forEach(el => {
            el.classList.remove('border-gray-900', 'text-gray-900', 'font-black');
            el.classList.add('border-transparent', 'text-gray-400', 'font-bold');
            el.setAttribute('aria-selected', 'false');
        });
        const active = $(`tab-${tabKey}`);
        if (active) {
            active.classList.remove('border-transparent', 'text-gray-400', 'font-bold');
            active.classList.add('border-gray-900', 'text-gray-900', 'font-black');
            active.setAttribute('aria-selected', 'true');
        }
    }

    function hideAllPanels() {
        show($('panel-table'), false);
        show($('panel-form'), false);
        show($('panel-matrix'), false);
        show($('toolbar'), false);
        show($('kpi-ribbon'), false);
    }

    function renderError(msg) {
        const p = $('panel-form');
        if (!p) return;
        p.innerHTML = `
            <div class="bg-red-50 border border-red-200 rounded-2xl p-8 text-center">
                <i class="fas fa-triangle-exclamation text-3xl text-red-500 mb-3"></i>
                <p class="font-bold text-red-800">${esc(msg)}</p>
            </div>`;
        show(p, true);
    }

    // -------------------------------------------------------------------------
    // 4. SHAPE: TABLE  (users, sessions, audits)
    // -------------------------------------------------------------------------

    async function renderTableTab(config) {
        show($('panel-table'), true);
        show($('toolbar'), true);
        show($('kpi-ribbon'), true);

        const btn = $('btn-primary-action');
        if (btn) {
            if (config.showActionBtn && CAN.editUsers) {
                btn.classList.remove('hidden');
                const label = $('btn-action-text');
                if (label) label.innerText = config.btnText;
            } else {
                btn.classList.add('hidden');
            }
        }

        const search = $('search-input');
        if (search) search.value = '';

        // KPI skeletons
        const ribbon = $('kpi-ribbon');
        ribbon.innerHTML = config.kpis.map(kpi => `
            <div class="bg-white p-5 rounded-2xl border border-gray-200 shadow-sm flex items-center justify-between">
                <div>
                    <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">${esc(kpi.label)}</p>
                    <h3 class="text-2xl font-black text-gray-900 mt-1" id="val-${kpi.id}">...</h3>
                </div>
                <div class="w-12 h-12 ${kpi.bg} ${kpi.color} rounded-xl flex items-center justify-center text-xl">
                    <i class="fas ${kpi.icon}"></i>
                </div>
            </div>`).join('');

        // Header
        $('table-head').innerHTML = '<tr>'
            + config.columns.map(c => `<th class="py-4 px-6 text-[10px] uppercase text-gray-400 font-black tracking-widest">${esc(c.label)}</th>`).join('')
            + '<th class="py-4 px-6 text-right text-[10px] uppercase text-gray-400 font-black tracking-widest">Actions</th></tr>';

        await fetchTabData();
    }

    async function fetchTabData() {
        const config = TABS[currentTab];
        if (!config || config.shape !== 'table') return;

        const tbody = $('table-body');
        tbody.innerHTML = '<tr><td colspan="10" class="py-8 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement sécurisé des données...</td></tr>';

        try {
            const data = await apiGet(`${API}?tab=${encodeURIComponent(config.api)}`);
            moduleData = data.table || [];

            if (data.kpis) {
                config.kpis.forEach(k => {
                    const el = $(`val-${k.id}`);
                    if (el) el.innerText = (data.kpis[k.id] !== undefined) ? data.kpis[k.id] : '-';
                });
            }
            renderTable(moduleData);
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="10" class="py-8 text-center text-red-500 font-bold">Erreur de chargement : ${esc(err.message)}</td></tr>`;
        }
    }

    function renderTable(rows) {
        const tbody = $('table-body');
        const config = TABS[currentTab];
        if (!tbody || !config || config.shape !== 'table') return;

        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="10" class="py-8 text-center text-gray-400 font-medium">Aucun enregistrement trouvé.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(row => {
            let tr = '<tr class="hover:bg-gray-50 transition-colors">';
            config.columns.forEach(col => {
                tr += `<td class="py-4 px-6 border-b border-gray-50">${col.render(row[col.key], row)}</td>`;
            });

            let actions = '<span class="text-gray-300">-</span>';
            if (currentTab === 'users' && CAN.editUsers) {
                const isLocked = row.status !== 'active';
                actions = `
                    <button type="button" onclick="LPCSettings.openActionModal(${row.id})" class="text-gray-400 hover:text-gray-900 p-2 transition-colors" title="Modifier l'utilisateur" aria-label="Modifier l'utilisateur"><i class="fas fa-edit"></i></button>
                    <button type="button" onclick="LPCSettings.toggleUserStatus(${row.id}, '${esc(row.status)}')" class="${isLocked ? 'text-red-500 hover:text-red-700' : 'text-green-500 hover:text-green-700'} p-2 transition-colors" title="${isLocked ? 'Déverrouiller le compte' : 'Verrouiller le compte'}" aria-label="${isLocked ? 'Déverrouiller le compte' : 'Verrouiller le compte'}">
                        <i class="fas ${isLocked ? 'fa-lock' : 'fa-unlock'}"></i>
                    </button>`;
            } else if (currentTab === 'sessions' && row.login_status === 'success' && !row.logout_time && CAN.editUsers) {
                actions = `<button type="button" onclick="LPCSettings.killSession(${row.id})" title="Forcer la déconnexion immédiate" class="bg-red-50 text-red-600 text-[10px] font-bold uppercase px-3 py-1.5 rounded-lg hover:bg-red-600 hover:text-white transition-colors border border-red-200 shadow-sm"><i class="fas fa-power-off mr-1"></i> Kill Session</button>`;
            }

            return tr + `<td class="py-4 px-6 border-b border-gray-50 text-right whitespace-nowrap">${actions}</td></tr>`;
        }).join('');
    }

    function filterData() {
        const input = $('search-input');
        if (!input) return;
        const q = input.value.toLowerCase();
        renderTable(moduleData.filter(i => Object.values(i).some(v => String(v).toLowerCase().includes(q))));
    }

    // -------------------------------------------------------------------------
    // 5. SHAPE: FORM  (company, preferences)
    //    Both render from a server-supplied description. The two payload shapes
    //    are normalised into one {sections:[{label,icon,help,fields:[]}]} here.
    // -------------------------------------------------------------------------

    async function renderFormTab(config) {
        const panel = $('panel-form');
        show(panel, true);
        panel.innerHTML = '<div class="py-16 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement de la configuration...</div>';

        let data;
        try {
            data = await apiGet(`${API}?tab=${encodeURIComponent(config.api)}&lang=${encodeURIComponent(BOOT.lang)}`);
        } catch (err) {
            renderError(`Impossible de charger « ${config.title} » : ${err.message}`);
            return;
        }

        const isCompany = config.api === 'company';
        const canEdit = isCompany ? !!CAN.editCompany : !!CAN.editPrefs;

        // --- Normalise both payload shapes into one section list --------------
        let sections, values;

        if (isCompany) {
            values = data.profile || {};
            sections = (data.layout || []).map(sec => ({
                key: sec.key,
                label: sec.label,
                icon: sec.icon,
                help: sec.help,
                fields: (sec.fields || []).map(f => ({
                    name: f.name,
                    label: f.label,
                    type: f.type,
                    help: f.help,
                    col: f.col || 1,
                    required: !!f.required,
                    placeholder: f.placeholder || '',
                    options: f.options || null,
                    slot: f.slot || null
                }))
            }));
        } else {
            values = {};
            sections = (data.groups || []).map(g => {
                const fields = (g.items || []).map(it => {
                    values[it.key] = it.value;
                    return {
                        name: it.key,
                        label: it.label,
                        // Map the storage `kind` onto a form control type.
                        type: ({
                            int: 'number', decimal: 'number', bool: 'checkbox',
                            select: 'select', textarea: 'textarea', color: 'color', date: 'date'
                        })[it.kind] || 'text',
                        help: it.help,
                        unit: it.unit,
                        min: it.min,
                        max: it.max,
                        step: it.kind === 'decimal' ? 'any' : (it.kind === 'int' ? '1' : null),
                        col: (it.kind === 'textarea') ? 2 : 1,
                        // Prefs options arrive as [{value,label}], company as {k:v}.
                        options: Array.isArray(it.options)
                            ? it.options.reduce((acc, o) => { acc[o.value] = o.label; return acc; }, {})
                            : null,
                        disabled: !!it.is_locked,
                        lockedNote: it.is_locked ? 'Verrouillé — modifiable uniquement par migration.' : null
                    };
                });
                return { key: g.key, label: g.label, icon: g.icon, fields: fields };
            });
        }

        formState = {
            kind: config.api,
            original: Object.assign({}, values),
            dirty: new Map(),
            canEdit: canEdit
        };

        // --- Build markup -----------------------------------------------------
        let html = '';

        if (!canEdit) {
            html += banner('info', 'fa-eye', "Lecture seule — vous n'avez pas la permission de modifier ces paramètres.");
        }

        if (data.warning) {
            html += banner('warn', 'fa-triangle-exclamation', esc(data.warning));
        }

        // Completeness warning: these fields are legally required on a
        // Cameroonian invoice, and shipping without them is a real exposure.
        if (isCompany && data.completeness && data.completeness.missing && data.completeness.missing.length) {
            const labels = {
                legal_name: 'Raison sociale', rccm_number: 'Numéro RCCM', niu: 'NIU',
                city: 'Ville', phone_primary: 'Téléphone', email_general: 'Email'
            };
            const missing = data.completeness.missing.map(m => labels[m] || m).join(', ');
            html += banner('warn', 'fa-file-circle-exclamation',
                `Champs obligatoires manquants pour vos documents commerciaux : <strong>${esc(missing)}</strong>. `
                + 'Le RCCM et le NIU doivent figurer sur toute facture émise au Cameroun.');
        }

        if (isCompany && data.letterhead) {
            html += letterheadPreview(data.letterhead);
        }

        sections.forEach(sec => {
            html += `
                <section class="lpc-section">
                    <header class="lpc-section-head">
                        <div class="w-9 h-9 rounded-lg bg-lpc-dark/5 text-lpc-dark flex items-center justify-center">
                            <i class="fas ${esc(sec.icon || 'fa-sliders')}"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-sm uppercase tracking-wider text-gray-900">${esc(sec.label)}</h3>
                            ${sec.help ? `<p class="text-xs text-gray-400 mt-0.5 max-w-3xl">${esc(sec.help)}</p>` : ''}
                        </div>
                    </header>
                    <div class="lpc-section-body grid grid-cols-1 md:grid-cols-2 gap-5">
                        ${sec.fields.map(f => renderField(f, values[f.name], canEdit)).join('')}
                    </div>
                </section>`;
        });

        html += `
            <div class="lpc-savebar">
                <div class="text-xs font-bold text-gray-500" id="form-dirty-count">Aucune modification.</div>
                <div class="flex gap-3">
                    <button type="button" onclick="LPCSettings.resetForm()" id="form-reset-btn" disabled
                            class="px-5 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800 disabled:opacity-40">
                        Annuler
                    </button>
                    <button type="button" onclick="LPCSettings.saveForm()" id="form-save-btn" disabled
                            class="px-8 py-2.5 bg-lpc-dark hover:bg-green-800 text-white rounded-xl font-bold text-sm shadow-md disabled:opacity-40 disabled:cursor-not-allowed">
                        <i class="fas fa-floppy-disk mr-2"></i>Enregistrer
                    </button>
                </div>
            </div>`;

        panel.innerHTML = html;
        wireFormEvents(panel, canEdit);
    }

    function banner(kind, icon, htmlMsg) {
        const styles = {
            info: 'bg-blue-50 border-blue-200 text-blue-800',
            warn: 'bg-amber-50 border-amber-200 text-amber-900',
            err:  'bg-red-50 border-red-200 text-red-800'
        };
        return `<div class="border rounded-xl px-5 py-4 mb-5 flex items-start gap-3 text-sm ${styles[kind] || styles.info}">
                    <i class="fas ${icon} mt-0.5"></i><div>${htmlMsg}</div>
                </div>`;
    }

    /** Live preview of the PDF letterhead built from the current profile. */
    function letterheadPreview(lh) {
        return `
            <section class="lpc-section">
                <header class="lpc-section-head">
                    <div class="w-9 h-9 rounded-lg bg-lpc-dark/5 text-lpc-dark flex items-center justify-center">
                        <i class="fas fa-eye"></i>
                    </div>
                    <div>
                        <h3 class="font-black text-sm uppercase tracking-wider text-gray-900">Aperçu de l'en-tête</h3>
                        <p class="text-xs text-gray-400 mt-0.5">Ce bloc apparaîtra en haut de chaque facture, devis et bon de livraison.</p>
                    </div>
                </header>
                <div class="lpc-section-body">
                    <div class="lpc-letterhead">
                        <div class="flex justify-between items-start gap-6">
                            <div class="flex items-start gap-4">
                                <img src="${esc(lh.logo)}" alt="" style="max-height:56px;max-width:150px;object-fit:contain"
                                     onerror="this.style.display='none'">
                                <div>
                                    <div class="font-black text-lg" style="color:${esc(lh.color)}">${esc(lh.name)}</div>
                                    <div class="text-xs text-gray-500 mt-1 leading-relaxed">
                                        ${esc(lh.address)}<br>
                                        ${esc(lh.contact)}<br>
                                        ${esc(lh.mentions)}
                                    </div>
                                </div>
                            </div>
                            <div class="text-right shrink-0">
                                <div class="font-black text-base" style="color:${esc(lh.color)}">FACTURE</div>
                                <div class="text-sm font-bold text-gray-700">N° FAC-2607-0042</div>
                                <div class="text-xs text-gray-500">Date : 28/07/2026</div>
                            </div>
                        </div>
                        <div class="border-t mt-4 pt-2 text-[10px] text-gray-400">${esc(lh.footer)}</div>
                    </div>
                    <p class="text-xs text-gray-400 mt-3">
                        <i class="fas fa-circle-info mr-1"></i>
                        Aperçu indicatif. Enregistrez pour voir vos modifications s'y refléter.
                    </p>
                </div>
            </section>`;
    }

    /** One form control, chosen by field type. */
    function renderField(f, value, canEdit) {
        const span = f.col === 2 ? 'md:col-span-2' : '';
        const dis = (!canEdit || f.disabled) ? 'disabled' : '';
        const v = (value === null || value === undefined) ? '' : String(value);
        let control;

        switch (f.type) {
            case 'select': {
                const opts = f.options || {};
                control = `<select class="lpc-input" data-field="${esc(f.name)}" ${dis}>
                    ${Object.keys(opts).map(k =>
                        `<option value="${esc(k)}" ${String(k) === v ? 'selected' : ''}>${esc(opts[k])}</option>`
                    ).join('')}
                </select>`;
                break;
            }
            case 'checkbox': {
                const on = (v === '1' || v === 'true' || v === 'on');
                control = `
                    <label class="flex items-center gap-3 cursor-pointer select-none">
                        <input type="checkbox" data-field="${esc(f.name)}" ${on ? 'checked' : ''} ${dis}
                               class="w-5 h-5 rounded border-gray-300" style="accent-color:#005A2B">
                        <span class="text-sm font-medium text-gray-700">${on ? 'Activé' : 'Désactivé'}</span>
                    </label>`;
                break;
            }
            case 'textarea':
                control = `<textarea class="lpc-input" rows="3" data-field="${esc(f.name)}" ${dis}
                                     placeholder="${esc(f.placeholder || '')}">${esc(v)}</textarea>`;
                break;

            case 'color':
                control = `
                    <div class="flex items-center gap-3">
                        <input type="color" data-field="${esc(f.name)}" value="${esc(v || '#005A2B')}" ${dis}
                               class="w-12 h-11 rounded-lg border border-gray-200 cursor-pointer bg-white p-1">
                        <span class="text-xs font-mono text-gray-500" data-color-echo="${esc(f.name)}">${esc(v)}</span>
                    </div>`;
                break;

            case 'logo':
                control = `
                    <div class="lpc-logo-drop" onclick="LPCSettings.pickLogo('${esc(f.slot)}','${esc(f.name)}')"
                         role="button" tabindex="0">
                        ${v ? `<img src="${esc(v)}" alt="" onerror="this.style.display='none'">`
                            : '<div class="w-16 h-12 bg-gray-100 rounded flex items-center justify-center text-gray-300"><i class="fas fa-image"></i></div>'}
                        <div class="min-w-0 flex-1">
                            <div class="text-xs font-bold text-gray-700">${v ? 'Remplacer' : 'Téléverser'}</div>
                            <div class="text-[11px] text-gray-400 truncate">${esc(v || 'PNG, JPEG ou WebP · 2 Mo max')}</div>
                        </div>
                        <i class="fas fa-upload text-gray-400"></i>
                    </div>
                    <input type="hidden" data-field="${esc(f.name)}" value="${esc(v)}">`;
                break;

            case 'number':
                control = `<div class="relative">
                    <input type="number" class="lpc-input ${f.unit ? 'pr-16' : ''}" data-field="${esc(f.name)}"
                           value="${esc(v)}" ${dis}
                           ${(f.min !== null && f.min !== undefined) ? `min="${esc(f.min)}"` : ''}
                           ${(f.max !== null && f.max !== undefined) ? `max="${esc(f.max)}"` : ''}
                           ${f.step ? `step="${esc(f.step)}"` : ''}
                           placeholder="${esc(f.placeholder || '')}">
                    ${f.unit ? `<span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-gray-400">${esc(f.unit)}</span>` : ''}
                </div>`;
                break;

            default:
                control = `<input type="${esc(f.type || 'text')}" class="lpc-input" data-field="${esc(f.name)}"
                                  value="${esc(v)}" ${dis} placeholder="${esc(f.placeholder || '')}">`;
        }

        const helpText = f.lockedNote || f.help;

        return `
            <div class="${span}">
                <label class="lpc-field-label">
                    ${esc(f.label)}${f.required ? ' <span class="text-red-500">*</span>' : ''}
                </label>
                ${control}
                ${helpText ? `<p class="lpc-field-help">${esc(helpText)}</p>` : ''}
            </div>`;
    }

    /** Dirty tracking: enables the save bar only when something actually changed. */
    function wireFormEvents(panel, canEdit) {
        if (!canEdit) return;

        panel.querySelectorAll('[data-field]').forEach(el => {
            const name = el.getAttribute('data-field');

            const handler = () => {
                const val = (el.type === 'checkbox') ? (el.checked ? '1' : '0') : el.value;
                const orig = formState.original[name];
                const origStr = (orig === null || orig === undefined) ? '' : String(orig);

                if (String(val) === origStr) {
                    formState.dirty.delete(name);
                    el.classList.remove('is-dirty');
                } else {
                    formState.dirty.set(name, val);
                    el.classList.add('is-dirty');
                }

                // Checkbox label + colour echo follow the control.
                if (el.type === 'checkbox') {
                    const span = el.parentElement.querySelector('span');
                    if (span) span.textContent = el.checked ? 'Activé' : 'Désactivé';
                }
                if (el.type === 'color') {
                    const echo = panel.querySelector(`[data-color-echo="${name}"]`);
                    if (echo) echo.textContent = el.value;
                }

                paintSaveBar();
            };

            el.addEventListener('input', handler);
            el.addEventListener('change', handler);
        });
    }

    function paintSaveBar() {
        const n = formState ? formState.dirty.size : 0;
        const label = $('form-dirty-count');
        const save = $('form-save-btn');
        const reset = $('form-reset-btn');

        if (label) {
            label.textContent = (n === 0)
                ? 'Aucune modification.'
                : `${n} modification${n > 1 ? 's' : ''} non enregistrée${n > 1 ? 's' : ''}.`;
            label.className = (n === 0)
                ? 'text-xs font-bold text-gray-500'
                : 'text-xs font-black text-lpc-dark';
        }
        if (save) save.disabled = (n === 0) || !formState.canEdit;
        if (reset) reset.disabled = (n === 0);
    }

    async function saveForm() {
        if (!formState || formState.dirty.size === 0) return;

        const fd = new FormData();
        const isCompany = formState.kind === 'company';

        formState.dirty.forEach((val, key) => {
            fd.append(isCompany ? key : `prefs[${key}]`, val);
        });

        const btn = $('form-save-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...'; }

        try {
            const res = await apiPost(`${API}?action=${isCompany ? 'save_company' : 'save_preferences'}`, fd);
            toast(res.message || 'Enregistré.');
            // Re-render from the server so the letterhead preview and any
            // server-side normalisation are reflected.
            await switchTabForce(currentTab);
        } catch (err) {
            toast(`Échec de l'enregistrement : ${err.message}`, 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-floppy-disk mr-2"></i>Enregistrer'; }
        }
    }

    function resetForm() {
        if (!formState) return;
        formState.dirty.clear();
        switchTabForce(currentTab);
    }

    // --- Logo upload ---------------------------------------------------------

    function pickLogo(slot, fieldName) {
        if (!formState || !formState.canEdit) return;
        const input = $('logo-file-input');
        input.value = '';
        input.onchange = () => uploadLogo(input.files[0], slot, fieldName);
        input.click();
    }

    async function uploadLogo(file, slot) {
        if (!file) return;

        // Client-side guard mirroring the server cap, so the user gets an
        // instant answer instead of waiting on a doomed 2 MB round trip.
        if (file.size > 2 * 1024 * 1024) {
            toast('Fichier trop volumineux (2 Mo maximum).', 'error');
            return;
        }

        const fd = new FormData();
        fd.append('logo', file);
        fd.append('slot', slot);

        try {
            const res = await apiPost(`${API}?action=upload_logo`, fd);
            toast(res.message || 'Logo téléversé.');
            await switchTabForce(currentTab);
        } catch (err) {
            toast(`Échec du téléversement : ${err.message}`, 'error');
        }
    }

    // -------------------------------------------------------------------------
    // 6. SHAPE: MATRIX  (RBAC)
    //    Consumes api/v1/rbac_controller.php, which already implemented the whole
    //    surface. modules/admin/roles.php rendered a second UI over this same
    //    API; that page now redirects here.
    // -------------------------------------------------------------------------

    async function renderMatrixTab() {
        const panel = $('panel-matrix');
        show(panel, true);
        panel.innerHTML = '<div class="py-16 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement des rôles...</div>';

        let roles;
        try {
            roles = await apiGet(`${RBAC_API}?action=list_roles`);
        } catch (err) {
            renderError(`Impossible de charger les rôles : ${err.message}`);
            return;
        }

        matrixState = {
            roles: roles,
            activeRoleId: roles.length ? roles[0].id : null,
            dirty: false
        };

        panel.innerHTML = `
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-5">
                <aside class="lg:col-span-1">
                    <div class="lpc-section mb-0">
                        <header class="lpc-section-head">
                            <div class="w-9 h-9 rounded-lg bg-lpc-dark/5 text-lpc-dark flex items-center justify-center">
                                <i class="fas fa-user-tag"></i>
                            </div>
                            <h3 class="font-black text-sm uppercase tracking-wider text-gray-900">Rôles</h3>
                        </header>
                        <div class="p-4 flex flex-col gap-2" id="role-list"></div>
                        ${CAN.createRoles ? `
                        <div class="px-4 pb-4">
                            <button type="button" onclick="LPCSettings.createRole()"
                                    class="w-full py-2.5 border-2 border-dashed border-gray-200 rounded-xl text-xs font-bold text-gray-400 hover:border-lpc-light hover:text-lpc-dark transition-colors">
                                <i class="fas fa-plus mr-1"></i> Nouveau rôle
                            </button>
                        </div>` : ''}
                    </div>
                </aside>
                <div class="lg:col-span-3" id="perm-panel"></div>
            </div>`;

        paintRoleList();
        if (matrixState.activeRoleId) await loadRolePermissions(matrixState.activeRoleId);
    }

    function paintRoleList() {
        const list = $('role-list');
        if (!list) return;
        list.innerHTML = matrixState.roles.map(r => `
            <button type="button" onclick="LPCSettings.selectRole(${r.id})"
                    class="lpc-role-chip ${r.id === matrixState.activeRoleId ? 'active' : ''}">
                <div class="font-black text-sm text-gray-900 capitalize">${esc(r.name)}</div>
                <div class="text-[11px] text-gray-400 mt-0.5">
                    ${esc(r.user_count)} utilisateur(s) · ${esc(r.perm_count)} permission(s)
                </div>
            </button>`).join('');
    }

    async function selectRole(roleId) {
        if (matrixState.dirty) {
            const ok = await confirmAsync('Permissions non enregistrées. Changer de rôle les abandonnera. Continuer ?');
            if (!ok) return;
        }
        matrixState.activeRoleId = roleId;
        matrixState.dirty = false;
        paintRoleList();
        await loadRolePermissions(roleId);
    }

    async function loadRolePermissions(roleId) {
        const panel = $('perm-panel');
        panel.innerHTML = '<div class="py-16 text-center text-gray-400 animate-pulse font-bold text-sm">Chargement des permissions...</div>';

        let data;
        try {
            data = await apiGet(`${RBAC_API}?action=get_role_permissions&role_id=${encodeURIComponent(roleId)}`);
        } catch (err) {
            panel.innerHTML = `<div class="lpc-section p-8 text-center text-red-500 font-bold">${esc(err.message)}</div>`;
            return;
        }

        // The admin role IS editable — new permissions added by later
        // migrations aren't auto-granted to admin, so users need to be able to
        // toggle them on. To avoid permanent lockout, the server refuses any
        // save that would strip admin.roles.edit / admin.roles.view / admin
        // .settings.view from the admin role (see rbac_controller.php's
        // set_role_permissions).
        const isAdminRole = String(data.role.name || '').toLowerCase() === 'admin';
        const canEdit = !!CAN.editRoles;
        const modules = Object.keys(data.permissions).sort();
        const totalPerms = Object.keys(data.permissions)
            .reduce((n, k) => n + data.permissions[k].length, 0);

        let html = '';

        if (isAdminRole && canEdit) {
            html += banner('warn', 'fa-triangle-exclamation',
                'Vous modifiez le rôle <strong>admin</strong>. Par conception, il devrait détenir toutes les permissions. '
                + 'Les permissions <code>admin.roles.edit</code>, <code>admin.roles.view</code> et <code>admin.settings.view</code> '
                + 'sont protégées côté serveur pour éviter tout verrouillage.');
        } else if (!CAN.editRoles) {
            html += banner('info', 'fa-eye', 'Lecture seule — permission <code>admin.roles.edit</code> requise.');
        }

        html += `
            <div class="lpc-section">
                <header class="lpc-section-head justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg bg-lpc-dark/5 text-lpc-dark flex items-center justify-center">
                            <i class="fas fa-key"></i>
                        </div>
                        <div>
                            <h3 class="font-black text-sm uppercase tracking-wider text-gray-900 capitalize">${esc(data.role.name)}</h3>
                            <p class="text-xs text-gray-400 mt-0.5">
                                <span id="perm-count">${data.granted.length}</span> permission(s) accordée(s) sur ${totalPerms}
                            </p>
                        </div>
                    </div>
                    ${(canEdit && CAN.deleteRoles) ? `
                        <button type="button" onclick="LPCSettings.deleteRole(${data.role.id}, '${esc(data.role.name)}')"
                                class="text-xs font-bold text-red-600 bg-red-50 border border-red-200 px-3 py-2 rounded-lg hover:bg-red-100">
                            <i class="fas fa-trash-alt mr-1"></i> Supprimer
                        </button>` : ''}
                </header>
                <div class="lpc-matrix">`;

        modules.forEach(mod => {
            const perms = data.permissions[mod];
            const grantedInMod = perms.filter(p => p.granted).length;
            html += `
                <div class="border-b border-gray-100 last:border-0">
                    <div class="flex items-center justify-between px-6 py-3 bg-gray-50/60">
                        <div class="font-black text-[11px] uppercase tracking-widest text-gray-500">
                            <i class="fas fa-folder mr-2 text-gray-300"></i>${esc(mod)}
                            <span class="ml-2 font-bold text-gray-400" data-mod-count="${esc(mod)}">(${grantedInMod}/${perms.length})</span>
                        </div>
                        ${canEdit ? `
                            <button type="button" onclick="LPCSettings.toggleModule('${esc(mod)}')"
                                    class="text-[11px] font-bold text-lpc-dark hover:underline">
                                Tout cocher / décocher
                            </button>` : ''}
                    </div>
                    ${perms.map(p => `
                        <label class="perm-row flex items-start gap-3 px-6 py-2.5 cursor-pointer">
                            <input type="checkbox" class="mt-0.5" data-perm-id="${p.id}" data-perm-mod="${esc(mod)}"
                                   ${p.granted ? 'checked' : ''} ${canEdit ? '' : 'disabled'}>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-gray-800 font-mono">${esc(p.name)}</span>
                                ${p.description ? `<span class="block text-xs text-gray-400">${esc(p.description)}</span>` : ''}
                            </span>
                        </label>`).join('')}
                </div>`;
        });

        html += '</div>';

        if (canEdit) {
            html += `
                <div class="lpc-savebar" style="border-radius:0 0 1rem 1rem;box-shadow:none">
                    <div class="text-xs font-bold text-gray-500" id="matrix-dirty">Aucune modification.</div>
                    <button type="button" onclick="LPCSettings.saveRolePermissions()" id="matrix-save-btn" disabled
                            class="px-8 py-2.5 bg-lpc-dark hover:bg-green-800 text-white rounded-xl font-bold text-sm shadow-md disabled:opacity-40">
                        <i class="fas fa-floppy-disk mr-2"></i>Enregistrer les permissions
                    </button>
                </div>`;
        }

        html += '</div>';
        panel.innerHTML = html;

        if (canEdit) {
            panel.querySelectorAll('[data-perm-id]').forEach(cb => {
                cb.addEventListener('change', markMatrixDirty);
            });
        }
    }

    function markMatrixDirty() {
        matrixState.dirty = true;
        refreshMatrixCounts();
        const btn = $('matrix-save-btn');
        const lbl = $('matrix-dirty');
        if (btn) btn.disabled = false;
        if (lbl) {
            lbl.textContent = 'Modifications non enregistrées.';
            lbl.className = 'text-xs font-black text-lpc-dark';
        }
    }

    function refreshMatrixCounts() {
        const panel = $('perm-panel');
        const all = panel.querySelectorAll('[data-perm-id]');
        let granted = 0;
        const byMod = {};

        all.forEach(cb => {
            const mod = cb.getAttribute('data-perm-mod');
            byMod[mod] = byMod[mod] || { on: 0, total: 0 };
            byMod[mod].total++;
            if (cb.checked) { granted++; byMod[mod].on++; }
        });

        const countEl = $('perm-count');
        if (countEl) countEl.textContent = granted;

        Object.keys(byMod).forEach(mod => {
            const el = panel.querySelector(`[data-mod-count="${mod}"]`);
            if (el) el.textContent = `(${byMod[mod].on}/${byMod[mod].total})`;
        });
    }

    function toggleModule(mod) {
        const boxes = $('perm-panel').querySelectorAll(`[data-perm-mod="${mod}"]`);
        const allOn = Array.prototype.every.call(boxes, b => b.checked);
        boxes.forEach(b => { b.checked = !allOn; });
        markMatrixDirty();
    }

    async function saveRolePermissions() {
        const ids = [];
        $('perm-panel').querySelectorAll('[data-perm-id]').forEach(cb => {
            if (cb.checked) ids.push(parseInt(cb.getAttribute('data-perm-id'), 10));
        });

        const fd = new FormData();
        fd.append('action', 'set_role_permissions');
        fd.append('role_id', matrixState.activeRoleId);
        ids.forEach(id => fd.append('permissions[]', id));

        const btn = $('matrix-save-btn');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Enregistrement...'; }

        try {
            await apiPost(`${RBAC_API}?action=set_role_permissions`, fd);
            matrixState.dirty = false;
            toast('Permissions mises à jour. Les utilisateurs concernés verront le changement à leur prochaine connexion.');
            await renderMatrixTab();
        } catch (err) {
            toast(`Échec : ${err.message}`, 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-floppy-disk mr-2"></i>Enregistrer les permissions'; }
        }
    }

    async function createRole() {
        const name = await promptAsync('Nom du nouveau rôle (minuscules, sans espace — ex. « superviseur ») :', {
            title: 'Nouveau rôle',
            placeholder: 'superviseur'
        });
        if (!name) return;

        const fd = new FormData();
        fd.append('action', 'create_role');
        fd.append('name', name.trim().toLowerCase());

        try {
            await apiPost(`${RBAC_API}?action=create_role`, fd);
            toast(`Rôle « ${name} » créé. Aucune permission ne lui est accordée par défaut.`);
            await renderMatrixTab();
        } catch (err) {
            toast(`Échec : ${err.message}`, 'error');
        }
    }

    async function deleteRole(id, name) {
        const ok = await confirmAsync(`Supprimer le rôle « ${name} » ? Les utilisateurs qui le portent perdront tous leurs accès.`);
        if (!ok) return;

        const fd = new FormData();
        fd.append('action', 'delete_role');
        fd.append('id', id);

        try {
            await apiPost(`${RBAC_API}?action=delete_role`, fd);
            toast('Rôle supprimé.');
            await renderMatrixTab();
        } catch (err) {
            toast(`Échec : ${err.message}`, 'error');
        }
    }

    // -------------------------------------------------------------------------
    // 7. USER MODAL  (table tab writes)
    // -------------------------------------------------------------------------

    async function openActionModal(id) {
        const form = $('dynamic-form');
        form.innerHTML = '';
        if (currentTab !== 'users') return;

        $('modal-title').innerText = id ? 'Modifier Utilisateur' : 'Nouvel Utilisateur';
        const user = id ? (moduleData.find(u => u.id == id) || {}) : {};

        // Roles come from the RBAC API rather than the hardcoded 1-4 list the
        // previous version shipped. That list silently broke as soon as anyone
        // created a custom role — migration 029 already added a fifth ('sales'),
        // so editing a sales user through this modal reassigned them to Admin.
        if (!rolesCache) {
            try {
                rolesCache = await apiGet(`${RBAC_API}?action=list_roles`);
            } catch (e) {
                rolesCache = [];
            }
        }

        const roleOpts = rolesCache.length
            ? rolesCache.map(r => {
                const sel = String(user.role_name || '').toLowerCase() === String(r.name).toLowerCase();
                return `<option value="${r.id}" ${sel ? 'selected' : ''}>${esc(r.name)}</option>`;
              }).join('')
            : '<option value="">— aucun rôle disponible —</option>';

        form.innerHTML = `
            <input type="hidden" name="id" value="${esc(id || '')}">
            <div>
                <label class="lpc-field-label">Code Employé *</label>
                <input type="text" name="employee_code" value="${esc(user.employee_code || '')}" required class="lpc-input font-bold">
            </div>
            <div>
                <label class="lpc-field-label">Email *</label>
                <input type="email" name="email" value="${esc(user.email || '')}" required class="lpc-input">
            </div>
            <div>
                <label class="lpc-field-label">Prénom *</label>
                <input type="text" name="first_name" value="${esc(user.first_name || '')}" required class="lpc-input">
            </div>
            <div>
                <label class="lpc-field-label">Nom *</label>
                <input type="text" name="last_name" value="${esc(user.last_name || '')}" required class="lpc-input">
            </div>
            <div>
                <label class="lpc-field-label">Rôle Système *</label>
                <select name="role_id" required class="lpc-input font-bold">${roleOpts}</select>
                <p class="lpc-field-help">Les permissions de chaque rôle se règlent dans l'onglet Rôles (RBAC).</p>
            </div>
            <div>
                <label class="lpc-field-label">Mot de passe ${id ? '(laisser vide pour conserver)' : '*'}</label>
                <input type="password" name="password" ${id ? '' : 'required'} placeholder="••••••••" class="lpc-input">
            </div>`;

        $('actionModal').classList.remove('hidden');
    }

    function closeModal() { $('actionModal').classList.add('hidden'); }

    async function saveData() {
        const formElement = $('dynamic-form');
        if (!formElement.reportValidity()) return;

        try {
            await apiPost(`${API}?action=save_${currentTab}`, new FormData(formElement));
            closeModal();
            fetchTabData();
        } catch (err) {
            toast(`Erreur d'enregistrement : ${err.message}`, 'error');
        }
    }

    async function toggleUserStatus(id, currentStatus) {
        const newStatus = currentStatus === 'active' ? 'inactive' : 'active';
        const actionText = newStatus === 'inactive' ? 'VERROUILLER ce compte' : 'DÉVERROUILLER ce compte';

        const ok = await confirmAsync(`Voulez-vous vraiment ${actionText} ? L'utilisateur sera immédiatement affecté.`);
        if (!ok) return;

        const fd = new FormData();
        fd.append('id', id);
        fd.append('status', newStatus);

        try {
            await apiPost(`${API}?action=toggle_user`, fd);
            fetchTabData();
        } catch (err) {
            toast(`Erreur : ${err.message}`, 'error');
        }
    }

    async function killSession(sessionId) {
        const ok = await confirmAsync('Voulez-vous vraiment forcer la déconnexion de cet utilisateur immédiatement ?');
        if (!ok) return;

        const fd = new FormData();
        fd.append('id', sessionId);

        try {
            await apiPost(`${API}?action=kill_session`, fd);
            fetchTabData();
        } catch (err) {
            toast(`Erreur : ${err.message}`, 'error');
        }
    }

    // -------------------------------------------------------------------------
    // 8. PUBLIC SURFACE + BOOT
    //    Exposed as one namespaced object rather than a dozen globals, so the
    //    inline onclick handlers in index.php have exactly one thing to bind to.
    // -------------------------------------------------------------------------

    window.LPCSettings = {
        switchTab: switchTab,
        filterData: filterData,
        openActionModal: openActionModal,
        closeModal: closeModal,
        saveData: saveData,
        toggleUserStatus: toggleUserStatus,
        killSession: killSession,
        saveForm: saveForm,
        resetForm: resetForm,
        pickLogo: pickLogo,
        selectRole: selectRole,
        toggleModule: toggleModule,
        saveRolePermissions: saveRolePermissions,
        createRole: createRole,
        deleteRole: deleteRole
    };

    // Guard against losing edits to a browser navigation.
    window.addEventListener('beforeunload', e => {
        const dirty = (formState && formState.dirty.size > 0) || (matrixState && matrixState.dirty);
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    // The script is deferred, so DOMContentLoaded may already have fired by the
    // time we run. Cover both cases, but only boot once.
    let booted = false;
    function boot() {
        if (booted) return;
        booted = true;
        switchTab(BOOT.initialTab || 'users');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
