<?php
// RBAC + env bootstrap (loads .env, DB, session cookie hardening, Rbac).
require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('crm.clients.view');
$lang = lpc_i18n_current_lang();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __t('ui.base_clients_devis'); ?> | LPC ERP</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    
    <style>
        .modal { transition: opacity 0.3s ease, visibility 0.3s ease; }
        .modal-content { transition: transform 0.3s ease; }
        .modal.hidden { opacity: 0; visibility: hidden; pointer-events: none; }
        .modal:not(.hidden) .modal-content { transform: scale(1); }
        .modal.hidden .modal-content { transform: scale(0.95); }
        /* Custom scrollbar for the modal */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background-color: #cbd5e1; border-radius: 4px; }

        /* KPI cards are buttons now. They must LOOK clickable, or nobody will
           discover the drill-down — the cards sat there inert for months. */
        .lpc-kpi-card {
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
        }
        .lpc-kpi-card:hover {
            transform: translateY(-2px);
            border-color: var(--lpc-brand, #005A2B);
            box-shadow: 0 10px 20px -10px rgba(0, 52, 26, .35);
        }
        .lpc-kpi-card:focus-visible {
            outline: 2px solid var(--lpc-brand, #005A2B);
            outline-offset: 3px;
        }
        .lpc-kpi-card .lpc-kpi-hint {
            font-size: .625rem; font-weight: 700; letter-spacing: .06em;
            text-transform: uppercase; color: #9ca3af; margin-top: .35rem;
            opacity: 0; transition: opacity .15s ease;
        }
        .lpc-kpi-card:hover .lpc-kpi-hint,
        .lpc-kpi-card:focus-visible .lpc-kpi-hint { opacity: 1; }

        /* Drill-down modal */
        .kpi-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; width: 100%; text-align: left;
            padding: .7rem .9rem; border-radius: .6rem;
            border: 1px solid transparent; background: transparent;
            transition: background .12s, border-color .12s;
        }
        .kpi-row + .kpi-row { margin-top: .15rem; }
        /* Hover surface goes through the shell tokens so it inverts in dark
           mode. Border was #e5e7eb on #f9fafb — 1.18:1, which is why hover
           looked flat; --lpc-border is 3+ on either surface. */
        a.kpi-row:hover { background: var(--lpc-surface-sunken); border-color: var(--lpc-border); }
        .kpi-row-name  { font-weight: 700; color: var(--lpc-ink); font-size: .875rem; }
        .kpi-row-meta  { font-size: .6875rem; color: var(--lpc-ink-soft); margin-top: .1rem; }
        .kpi-row-value { font-weight: 800; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .kpi-overdue   { color: var(--lpc-status-danger-fg); }
        .kpi-bar       { height: 4px; border-radius: 999px; background: var(--lpc-border); margin-top: .4rem; overflow: hidden; }
        .kpi-bar > i   { display: block; height: 100%; background: var(--lpc-accent-on-light); }
        .kpi-stat      { display: flex; justify-content: space-between; padding: .45rem 0;
                         border-bottom: 1px dashed var(--lpc-border); font-size: .8125rem; }
        .kpi-stat:last-child { border-bottom: 0; }
        /* Was max-h-[60vh] — an arbitrary Tailwind value, and those are
           compiled at build time. It was never in assets/css/tailwind.css, so
           the modal body would have grown unbounded on a long debtor list and
           pushed its own Fermer button off-screen. §5.5. */
        .kpi-modal-scroll { max-height: 60vh; overflow-y: auto; }

        /* ---- Devis tab ---------------------------------------------------
           Written as plain CSS, not Tailwind arbitrary values: those compile
           at build time and are not in assets/css/tailwind.css, the same trap
           documented above for max-h-[60vh]. */
        .lpc-tab {
            border-color: transparent;
            color: #6b7280;
        }
        .lpc-tab:hover { color: #111827; }
        .lpc-tab[aria-selected="true"] {
            color: var(--lpc-brand, #005A2B);
            border-color: var(--lpc-brand, #005A2B);
        }

        .devis-chip {
            font-size: .6875rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; padding: .35rem .7rem; border-radius: 999px;
            border: 1px solid #e5e7eb; background: #fff; color: #6b7280;
            transition: background .12s, color .12s, border-color .12s;
        }
        .devis-chip:hover { border-color: #d1d5db; color: #111827; }
        .devis-chip.is-active {
            background: var(--lpc-brand, #005A2B);
            border-color: var(--lpc-brand, #005A2B);
            color: #fff;
        }

        .devis-badge {
            display: inline-flex; align-items: center; gap: .25rem;
            padding: .2rem .55rem; border-radius: 999px;
            font-size: .625rem; font-weight: 800; text-transform: uppercase;
            letter-spacing: .04em; white-space: nowrap;
        }
        /* Devis badges use the shared status palette. Before this rewrite
           the same "success" role was #ecfdf5/#047857 here and #dcfce7/#166534
           in Accounting; consolidating on --lpc-status-* means a green pill
           looks identical in every module. */
        .devis-badge-sent    { background: var(--lpc-status-info-bg);    color: var(--lpc-status-info-fg);    }
        .devis-badge-signed  { background: var(--lpc-status-success-bg); color: var(--lpc-status-success-fg); }
        .devis-badge-expired { background: var(--lpc-status-danger-bg);  color: var(--lpc-status-danger-fg);  }
        /* "Visé LPC" — an INTERNAL attestation, shown alongside the real
           status rather than instead of it. Deliberately not green: the
           client has not agreed to anything yet, and a green badge here
           would read as a won deal on a sales review. Slate, so it reads
           as metadata next to the status it accompanies. */
        .devis-badge-visa    { background: var(--lpc-status-neutral-bg); color: var(--lpc-status-neutral-fg); margin-left: .3rem; }
        /* Signed, then edited. Amber rather than green: the signature exists
           but no longer matches the figures, and that is a discrepancy to
           investigate, not a clean sign-off. */
        .devis-badge-stale   { background: var(--lpc-status-warning-bg); color: var(--lpc-status-warning-fg); }

        .devis-modal-scroll { max-height: 62vh; overflow-y: auto; }

        /* A read-only line: pre-migration-056 devis carry no product_id, so
           the picker cannot preselect anything and the line is shown as the
           frozen text it is. */
        .devis-line-locked {
            background: var(--lpc-surface-sunken); color: var(--lpc-ink-soft);
        }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


    <?php
    $pageTitle    = __t('ui.base_clients_devis');
    $pageSubtitle = __t('ui.g_rez_vos_clients_b2b_b2c_et_g_n_rez_des');
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <div class="lpc-toolbar">
            <button onclick="openNewClientModal()" class="bg-lpc-dark hover:bg-[#004722] text-white px-4 md:px-5 py-2.5 rounded-xl font-bold shadow-md transition-all flex items-center shrink-0">
                <svg class="w-5 h-5 md:mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                <span class="hidden md:inline"><?php echo __t('ui.nouveau_client'); ?></span>
            </button>

            <?php if (Rbac::hasPermission('crm.proposals.template')): ?>
            <a href="/modules/crm/proposal_studio.php"
               class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-lpc-dark hover:border-lpc-dark flex items-center justify-center shrink-0 transition-all lpc-focusable"
               title="Studio Proposition — modifier le modèle de devis"
               aria-label="Studio Proposition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </a>
            <?php endif; ?>

            <!-- Corbeille toggle — hidden client-side (data-perm-any) unless the
                 user holds restore and/or purge; server re-checks on every API
                 call regardless. See includes/classes/ClientTrash.php. -->
            <button type="button" onclick="toggleCorbeille()" id="corbeille-toggle-btn"
                    data-perm-any="crm.clients.restore,crm.clients.purge"
                    class="w-11 h-11 rounded-xl border border-lpc-border bg-white text-gray-500 hover:text-red-600 hover:border-red-300 flex items-center justify-center shrink-0 transition-all lpc-focusable"
                    title="Corbeille — clients supprimés" aria-label="Corbeille">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
            </button>

            <?php
            // Help for THIS page. Renders nothing if no article is anchored to
            // 'crm.clients' or the reader may not see it, so it is safe to
            // deploy ahead of the content migration. README §5.5: page-specific,
            // therefore it belongs in this toolbar and not in the topbar.
            echo lpc_help_link('crm.clients', $lang);
            ?>
        </div>

        <main role="main" id="main" class="lpc-page">

        <?php
        // Devis tab visibility. crm.proposals.view was defined back in
        // migration 001 and, until this tab, no page consumed it — the
        // répertoire is what it was always for. Rendered server-side rather
        // than hidden with data-perm so a user without the permission never
        // receives the markup at all; fetch_proposals.php re-checks anyway.
        $canViewProposals = Rbac::hasPermission('crm.proposals.view');
        ?>

        <?php if ($canViewProposals): ?>
        <!-- Clients | Devis. Two views over the same page: the client base as
             it always was, and the quote history that previously existed
             nowhere. Tabs rather than a separate page because a rep moves
             between "who is this client" and "what did we quote them"
             constantly, and the toolbar (new client, studio, corbeille, help)
             serves both. -->
        <div class="mb-6 border-b border-lpc-border" role="tablist" aria-label="Vues CRM">
            <button type="button" id="tab-btn-clients" role="tab" aria-selected="true"
                    aria-controls="tab-panel-clients" onclick="switchCrmTab('clients')"
                    class="lpc-tab px-5 py-3 text-sm font-bold border-b-2 -mb-px transition-colors">
                <?php echo __t('ui.r_pertoire_clients'); ?>
            </button>
            <button type="button" id="tab-btn-devis" role="tab" aria-selected="false"
                    aria-controls="tab-panel-devis" onclick="switchCrmTab('devis')"
                    class="lpc-tab px-5 py-3 text-sm font-bold border-b-2 -mb-px transition-colors">
                Devis
                <span id="tab-devis-count"
                      class="ml-1.5 px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[10px] font-black align-middle"></span>
            </button>
        </div>
        <?php endif; ?>

        <div id="tab-panel-clients" role="tabpanel" aria-labelledby="tab-btn-clients">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <button type="button" class="lpc-kpi-card bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between w-full text-left" data-metric="clients" aria-haspopup="dialog">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?php echo __t('ui.total_clients_actifs'); ?></p>
                    <h3 class="text-2xl font-black text-gray-900 mt-1" id="kpi-clients">...</h3>
                    <p class="lpc-kpi-hint"><?= htmlspecialchars(__t('ui.x.voir_la_repartition')) ?></p>
                </div>
                <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
            </button>
            <button type="button" class="lpc-kpi-card bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between w-full text-left" data-metric="ar" aria-haspopup="dialog">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?php echo __t('ui.dette_financi_re_ar'); ?></p>
                    <h3 class="text-2xl font-black text-red-600 mt-1" id="kpi-ar">...</h3>
                    <p class="lpc-kpi-hint"><?= htmlspecialchars(__t('ui.x.voir_le_detail')) ?></p>
                </div>
                <div class="w-12 h-12 bg-red-50 text-red-600 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
            </button>
            <button type="button" class="lpc-kpi-card bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between w-full text-left" data-metric="empties" aria-haspopup="dialog">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider"><?php echo __t('ui.dette_emballages_actifs'); ?></p>
                    <h3 class="text-2xl font-black text-amber-600 mt-1" id="kpi-bottles">...</h3>
                    <p class="lpc-kpi-hint"><?= htmlspecialchars(__t('ui.x.voir_le_detail')) ?></p>
                </div>
                <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
            </button>
        </div>

        <div class="bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-lpc-border bg-gray-50 flex justify-between items-center">
                <h2 class="font-bold text-gray-800"><?php echo __t('ui.r_pertoire_clients'); ?></h2>
                <input type="text" id="search-client" onkeyup="filterClients()" placeholder="<?php echo __t('ui.rechercher_un_client'); ?>" class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none w-64 transition-all">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-white border-b border-lpc-border text-xs uppercase text-gray-500 font-bold">
                        <tr>
                            <th class="py-4 px-6"><?= htmlspecialchars(__t('ui.x.client_societe')) ?></th>
                            <th class="py-4 px-6"><?= htmlspecialchars(__t('ui.x.contact_zone')) ?></th>
                            <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.dette_financiere')) ?></th>
                            <th class="py-4 px-6 text-center"><?= htmlspecialchars(__t('ui.x.dette_emballages')) ?></th>
                            <th class="py-4 px-6 text-right"><?= htmlspecialchars(__t('ui.x.actions')) ?></th>
                        </tr>
                    </thead>
                    <tbody id="clients-tbody" class="divide-y divide-gray-100">
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="py-4 px-6">
                                <div class="font-extrabold text-gray-900 text-base">Prometal S.A.</div>
                                <div class="text-xs text-lpc-light font-bold uppercase tracking-wide"><?= htmlspecialchars(__t('ui.x.corporate_b2b')) ?></div>
                            </td>
                            <td class="py-4 px-6">
                                <div class="text-sm font-medium text-gray-800">Jean-Paul Etou</div>
                                <div class="text-xs text-gray-500">Zone Industrielle, Bassa</div>
                            </td>
                            <td class="py-4 px-6 text-right">
                                <div class="text-sm font-bold text-red-600">450,000 FCFA</div>
                                <div class="text-xs text-gray-400"><?= htmlspecialchars(__t('ui.x.plafond_2m_fcfa')) ?></div>
                            </td>
                            <td class="py-4 px-6 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                    15 Vides
                                </span>
                            </td>
                            <td class="py-4 px-6 text-right space-x-2">
                                <button onclick="openProposalModal(1, 'Prometal S.A.')" class="bg-lpc-light hover:bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-bold shadow-sm transition-colors inline-flex items-center">
                                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 012 2z"></path></svg>
                                    Devis
                                </button>
                                <button class="text-gray-400 hover:text-lpc-dark transition-colors px-2 py-1.5">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Corbeille — hidden by default, toggled by toggleCorbeille(). Lists
             soft-deleted clients (deleted_at NOT NULL) with a countdown to
             auto-purge, a Restore action and a "Supprimer définitivement" action.
             Populated by loadDeletedClients() in crm-clients.js. -->
        <div id="corbeille-section" class="hidden bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden mt-6">
            <div class="px-6 py-4 border-b border-lpc-border bg-red-50/50 flex justify-between items-center">
                <div>
                    <h2 class="font-bold text-gray-800">Corbeille</h2>
                    <p class="text-xs text-gray-500 mt-0.5">Les clients ici sont supprimés définitivement après le délai indiqué, sauf restauration.</p>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-white border-b border-lpc-border text-xs uppercase text-gray-500 font-bold">
                        <tr>
                            <th class="py-4 px-6">Client</th>
                            <th class="py-4 px-6">Supprimé par</th>
                            <th class="py-4 px-6">Données réaffectées à</th>
                            <th class="py-4 px-6 text-center">Purge automatique</th>
                            <th class="py-4 px-6 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="corbeille-tbody" class="divide-y divide-gray-100"></tbody>
                </table>
            </div>
        </div>
        </div><!-- /#tab-panel-clients -->

        <?php if ($canViewProposals): ?>
        <!-- =====================================================================
             DEVIS — the quote history.
             Everything here is rendered by crm-devis.js from
             /api/v1/fetch_proposals.php. Server-side paging (25/rows), so the
             markup is a shell: the KPIs, the filter bar, an empty tbody and a
             pager.
             ===================================================================== -->
        <div id="tab-panel-devis" class="hidden" role="tabpanel" aria-labelledby="tab-btn-devis">

            <!-- The three KPIs. They describe the FILTERED set, not the whole
                 table — narrow to one rep and they narrow with you. Cards that
                 ignored the filters would contradict the rows underneath and
                 read as a bug. Not clickable (unlike the client KPIs): the
                 drill-down here IS the table below them. -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                <div class="bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Devis envoyés</p>
                        <h3 class="text-2xl font-black text-gray-900 mt-1" id="kpi-devis-sent">...</h3>
                        <p class="text-[11px] text-gray-400 mt-1" id="kpi-devis-sent-sub">&nbsp;</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-50 text-blue-600 rounded-full flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    </div>
                </div>

                <div class="bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Devis signés</p>
                        <h3 class="text-2xl font-black text-green-600 mt-1" id="kpi-devis-signed">...</h3>
                        <p class="text-[11px] text-gray-400 mt-1" id="kpi-devis-signed-sub">&nbsp;</p>
                    </div>
                    <div class="w-12 h-12 bg-green-50 text-green-600 rounded-full flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path></svg>
                    </div>
                </div>

                <!-- Pipeline deliberately EXCLUDES expired unsigned devis. An
                     expired quote is a follow-up task, not money in play, and
                     folding it in inflates the figure a sales review reads. -->
                <div class="bg-white p-5 rounded-2xl border border-lpc-border shadow-sm flex items-center justify-between">
                    <div>
                        <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">En attente de signature</p>
                        <h3 class="text-2xl font-black text-amber-600 mt-1" id="kpi-devis-pipeline">...</h3>
                        <p class="text-[11px] text-gray-400 mt-1" id="kpi-devis-pipeline-sub">&nbsp;</p>
                    </div>
                    <div class="w-12 h-12 bg-amber-50 text-amber-600 rounded-full flex items-center justify-center shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl border border-lpc-border shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-lpc-border bg-gray-50">
                    <div class="flex flex-wrap justify-between items-center gap-3">
                        <h2 class="font-bold text-gray-800">Répertoire des devis</h2>
                        <input type="text" id="devis-search"
                               placeholder="Rechercher une référence ou un client…"
                               class="border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none w-full md:w-72 transition-all">
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-3">
                        <!-- Status chips. data-status is read by the JS; the
                             active one carries .is-active, styled below. -->
                        <div class="flex gap-1" id="devis-status-chips">
                            <button type="button" class="devis-chip is-active" data-status="all">Tous</button>
                            <button type="button" class="devis-chip" data-status="sent">Envoyés</button>
                            <button type="button" class="devis-chip" data-status="signed">Signés</button>
                            <button type="button" class="devis-chip" data-status="expired">Expirés</button>
                        </div>

                        <span class="mx-1 h-5 w-px bg-gray-200 hidden md:inline-block"></span>

                        <label class="text-[11px] font-bold text-gray-400 uppercase">Du</label>
                        <input type="date" id="devis-from" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-lpc-light outline-none">
                        <label class="text-[11px] font-bold text-gray-400 uppercase">Au</label>
                        <input type="date" id="devis-to" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs focus:ring-2 focus:ring-lpc-light outline-none">

                        <select id="devis-rep" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs bg-white focus:ring-2 focus:ring-lpc-light outline-none">
                            <option value="">Tous les commerciaux</option>
                        </select>

                        <select id="devis-sort" class="border border-gray-300 rounded-lg px-2 py-1.5 text-xs bg-white focus:ring-2 focus:ring-lpc-light outline-none">
                            <option value="date:desc">Plus récents</option>
                            <option value="date:asc">Plus anciens</option>
                            <option value="amount:desc">Montant ↓</option>
                            <option value="amount:asc">Montant ↑</option>
                            <option value="client:asc">Client A–Z</option>
                            <option value="reference:desc">Référence</option>
                        </select>

                        <button type="button" onclick="resetDevisFilters()"
                                class="text-xs font-bold text-gray-400 hover:text-lpc-dark px-2 py-1.5 transition-colors">
                            Réinitialiser
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead class="bg-white border-b border-lpc-border text-xs uppercase text-gray-500 font-bold">
                            <tr>
                                <th class="py-4 px-6">Référence</th>
                                <th class="py-4 px-6">Client</th>
                                <th class="py-4 px-6">Date</th>
                                <th class="py-4 px-6 text-right">Montant</th>
                                <th class="py-4 px-6">Statut</th>
                                <!-- One column, two meanings, decided per row:
                                     an unsigned devis shows its commercial and
                                     its expiry (what you chase); a signed one
                                     shows who signed and when (what you cite).
                                     Two separate columns would leave whichever
                                     half doesn't apply blank on every row. -->
                                <th class="py-4 px-6">Suivi</th>
                                <th class="py-4 px-6 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="devis-tbody" class="divide-y divide-gray-100">
                            <tr><td colspan="7" class="py-10 text-center text-gray-400 text-sm italic">Chargement…</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="px-6 py-3 border-t border-lpc-border bg-gray-50 flex items-center justify-between">
                    <p class="text-xs text-gray-500" id="devis-pager-label"></p>
                    <div class="flex gap-2">
                        <button type="button" id="devis-prev" onclick="devisPage(-1)"
                                class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-bold text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Précédent
                        </button>
                        <button type="button" id="devis-next" onclick="devisPage(1)"
                                class="px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-xs font-bold text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                            Suivant
                        </button>
                    </div>
                </div>
            </div>
        </div><!-- /#tab-panel-devis -->
        <?php endif; ?>
    </main>
    </div>

    <!-- KPI drill-down. Rendered by crm-clients.js; see openKpiModal(). -->
    <div id="kpiModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm px-4"
         role="dialog" aria-modal="true" aria-labelledby="kpi-modal-title">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden border border-gray-100">
            <div class="bg-lpc-dark px-6 py-4 flex justify-between items-center text-white">
                <div>
                    <h3 class="font-bold text-lg" id="kpi-modal-title"><?= htmlspecialchars(__t('ui.x.detail')) ?></h3>
                    <p class="text-xs text-white/70 mt-0.5" id="kpi-modal-total"></p>
                </div>
                <button type="button" onclick="closeModal('kpiModal')" class="text-white/70 hover:text-white" aria-label="Fermer">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-5 kpi-modal-scroll custom-scrollbar" id="kpi-modal-body">
                <p class="text-sm text-gray-400 text-center py-8"><?= htmlspecialchars(__t('ui.account.loading')) ?></p>
            </div>
            <div class="px-5 py-3 bg-gray-50 border-t border-gray-200 flex justify-between items-center">
                <p class="text-[11px] text-gray-400" id="kpi-modal-foot"></p>
                <button type="button" onclick="closeModal('kpiModal')" class="px-4 py-2 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-200 transition-colors"><?= htmlspecialchars(__t('ui.common.close')) ?></button>
            </div>
        </div>
    </div>

    <div id="clientModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden flex flex-col max-h-full">
            <div class="bg-lpc-dark px-6 py-4 flex justify-between items-center text-white shrink-0">
                <h3 class="font-bold text-lg"><?php echo __t('ui.ajouter_un_client'); ?></h3>
                <button onclick="closeModal('clientModal')" class="text-white/70 hover:text-white"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg></button>
            </div>
            <div class="p-6 overflow-y-auto custom-scrollbar">
                <form id="form-add-client" class="space-y-4">
    <input type="hidden" id="edit_client_id" value="">
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.societe_nom_complet')) ?></label>
            <input type="text" id="new_client_name" required class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.type_de_client')) ?></label>
            <select id="new_client_type" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none bg-white">
                <option value="B2B"><?= htmlspecialchars(__t('ui.x.corporate_b2b_2')) ?></option>
                <option value="B2C"><?= htmlspecialchars(__t('ui.x.individuel_b2c')) ?></option>
                <option value="Distributor"><?= htmlspecialchars(__t('ui.x.distributeur')) ?></option>
            </select>
        </div>

        <div id="status-field-wrapper" class="hidden">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.statut_du_client')) ?></label>
            <select id="new_client_status" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none bg-white font-bold">
                <option value="prospect"><?= htmlspecialchars(__t('ui.x.prospect')) ?></option>
                <option value="active" class="text-green-600"><?= htmlspecialchars(__t('ui.account.pin_on')) ?></option>
                <option value="inactive" class="text-red-600"><?= htmlspecialchars(__t('ui.x.inactif')) ?></option>
            </select>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.personne_de_contact')) ?></label>
            <input type="text" id="new_client_contact" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.telephone')) ?></label>
            <input type="tel" id="new_client_phone" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        
        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.email_professionnel')) ?></label>
            <input type="email" id="new_client_email" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>

        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.adresse_complete')) ?></label>
            <input type="text" id="new_client_address" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.numero_contribuable_niu')) ?></label>
            <input type="text" id="new_client_niu" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>
        <div>
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.registre_de_commerce_rccm')) ?></label>
            <input type="text" id="new_client_rc" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none">
        </div>

        <div class="md:col-span-2">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1"><?= htmlspecialchars(__t('ui.x.plafond_de_credit_fcfa')) ?></label>
            <input type="number" id="new_client_credit" value="0" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-bold text-gray-900">
        </div>

        <!-- ============================================================
             Retenue AIR à la source (Cameroun — art. 149 CGI, LF 2026).
             Cochez si le client (Prometal, SONARA, PMUC, entités
             habilitées par l'Arrêté DGI n°00001) prélève l'AIR sur nos
             factures. Le taux dépend de leur régime fiscal.
             Une fois activé, invoices_controller.php:793 lit ces champs
             à l'émission et scinde automatiquement la facture en
             (net_payable + air_amount) — plus rien à saisir au paiement.
             ============================================================ -->
        <div class="md:col-span-2 border-t border-gray-200 pt-4 mt-2">
            <label class="flex items-start gap-3 cursor-pointer group">
                <input type="checkbox" id="new_client_is_wa" onchange="document.getElementById('wa_rate_wrapper').classList.toggle('hidden', !this.checked)" class="mt-0.5 w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500 cursor-pointer">
                <span>
                    <span class="block text-sm font-bold text-gray-900">Client retient l'AIR à la source</span>
                    <span class="block text-xs text-gray-500 mt-0.5">Le client prélève l'acompte d'impôt sur revenu directement sur nos factures et le reverse à la DGI. Une attestation mensuelle est requise pour créditer la retenue.</span>
                </span>
            </label>
        </div>

        <div id="wa_rate_wrapper" class="md:col-span-2 hidden">
            <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Taux de retenue AIR</label>
            <select id="new_client_wa_rate" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-lpc-light outline-none bg-white font-bold">
                <option value="0.022">2,2 % (régime réel)</option>
                <option value="0.055">5,5 % (régime simplifié)</option>
                <option value="0.10">10 % (agents commerciaux non-salariés)</option>
                <option value="0.15">15 % (secteur informel / non-immatriculés)</option>
            </select>
            <p class="text-[10px] text-gray-500 mt-1 italic">Taux légaux selon la Loi de Finances 2026 (en vigueur depuis le 17 déc. 2025).</p>
        </div>
    </div>
</form>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-100 flex justify-end gap-3 shrink-0">
                <button onclick="closeModal('clientModal')" class="px-5 py-2 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-200 transition-colors"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                <button onclick="submitClient()" class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-lpc-dark hover:bg-[#004722] shadow-md transition-colors"><?= htmlspecialchars(__t('ui.x.enregistrer_le_client')) ?></button>
            </div>
        </div>
    </div>

    <div id="proposalModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-2 md:p-6">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-full md:h-auto md:max-h-full overflow-hidden flex flex-col border border-gray-200">
            
            <div class="bg-white border-b border-gray-100 px-6 py-4 flex justify-between items-center shrink-0">
                <div>
                    <h3 class="font-extrabold text-xl text-gray-900 flex items-center">
                        <svg class="w-6 h-6 mr-2 text-lpc-light" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 012 2z"></path></svg>
                        Générer un Devis
                    </h3>
                    <p class="text-sm text-gray-500 font-medium mt-0.5"><?= htmlspecialchars(__t('ui.x.pour')) ?> <span id="prop_client_name" class="font-bold text-lpc-dark"><?= htmlspecialchars(__t('ui.x.selectionnez_un_client')) ?></span></p>
                </div>
                <button onclick="closeModal('proposalModal')" class="p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 rounded-full transition-colors">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-gray-50/50">
                <form id="form-proposal" class="space-y-8">
                    <input type="hidden" id="prop_client_id" value="">

                    <div>
                        <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-3 border-b border-gray-200 pb-2"><?= htmlspecialchars(__t('ui.x.1_metadonnees_sla_logistique')) ?></h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.langue_du_document')) ?></label>
                                <select id="prop_lang" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="fr"><?= htmlspecialchars(__t('ui.x.francais_fr')) ?></option>
                                    <option value="en">English (EN)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.validite_du_devis')) ?></label>
                                <select id="prop_validity" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="15"><?= htmlspecialchars(__t('ui.x.15_jours')) ?></option>
                                    <option value="30" selected><?= htmlspecialchars(__t('ui.x.30_jours')) ?></option>
                                    <option value="60"><?= htmlspecialchars(__t('ui.x.60_jours')) ?></option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.frequence_de_livraison')) ?></label>
                                <select id="prop_frequency" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="Hebdomadaire"><?= htmlspecialchars(__t('ui.x.hebdomadaire_weekly')) ?></option>
                                    <option value="Bi-Mensuel"><?= htmlspecialchars(__t('ui.x.bi_mensuel_bi_weekly')) ?></option>
                                    <option value="Mensuel"><?= htmlspecialchars(__t('ui.x.mensuel_monthly')) ?></option>
                                    <option value="Sur Demande"><?= htmlspecialchars(__t('ui.x.sur_demande_on_demand')) ?></option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.stock_tampon_semaines')) ?></label>
                                <input type="number" id="prop_buffer" value="2" min="0" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-bold">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.conditions_de_paiement')) ?></label>
                                <select id="prop_payment" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="Paiement à la Livraison"><?= htmlspecialchars(__t('ui.x.paiement_a_la_livraison')) ?></option>
                                    <option value="Net 15 Jours"><?= htmlspecialchars(__t('ui.x.net_15_jours')) ?></option>
                                    <option value="Net 30 Jours" selected><?= htmlspecialchars(__t('ui.x.net_30_jours')) ?></option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-gray-700 mb-1"><?= htmlspecialchars(__t('ui.x.politique_des_emballages')) ?></label>
                                <select id="prop_empties" class="w-full bg-white border border-gray-300 rounded-xl px-4 py-2.5 text-sm focus:ring-2 focus:ring-lpc-light outline-none font-medium">
                                    <option value="Échange 1-pour-1 (100%)"><?= htmlspecialchars(__t('ui.x.echange_1_pour_1_100')) ?></option>
                                    <option value="Retour Minimum Exigé (90%)"><?= htmlspecialchars(__t('ui.x.retour_minimum_exige_90')) ?></option>
                                    <option value="Retour Minimum Exigé (80%)"><?= htmlspecialchars(__t('ui.x.retour_minimum_exige_80')) ?></option>
                                    <option value="Retour Minimum Exigé (70%)"><?= htmlspecialchars(__t('ui.x.retour_minimum_exige_70')) ?></option>
                                    <option value="Consigne Payée (Facturée)"><?= htmlspecialchars(__t('ui.x.consigne_payee_facturee')) ?></option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="flex justify-between items-end mb-3 border-b border-gray-200 pb-2">
                            <h4 class="text-xs font-black text-gray-400 uppercase tracking-widest"><?= htmlspecialchars(__t('ui.x.2_offre_commerciale_lignes')) ?></h4>
                            <button type="button" onclick="addProposalRow()" class="text-lpc-light hover:text-green-600 text-xs font-bold flex items-center uppercase tracking-wide transition-colors">
                                + Ajouter une ligne
                            </button>
                        </div>
                        
                        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden shadow-sm">
                            <table class="w-full text-left">
                                <thead class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 font-bold">
                                    <tr>
                                        <th class="py-3 px-4 w-1/2"><?= htmlspecialchars(__t('ui.x.produit')) ?></th>
                                        <th class="py-3 px-4 w-24 text-center"><?= htmlspecialchars(__t('ui.x.qte')) ?></th>
                                        <th class="py-3 px-4 text-right"><?= htmlspecialchars(__t('ui.x.prix_unitaire')) ?></th>
                                        <th class="py-3 px-4 w-10"></th>
                                    </tr>
                                </thead>
                                <!-- Starts EMPTY. openProposalModal() stamps the first row from the
                                     <template> below.

                                     It used to carry a hardcoded first row that addProposalRow() then
                                     cloned with cloneNode(true). That cannot survive the picker: once
                                     the row is upgraded, cloning it copies the picker's rendered DOM —
                                     a wrapper div and a dead hidden input — with none of the JS state
                                     that makes it a picker. The clone would look right and select
                                     nothing. Stamping every row from pristine markup means row 1 and
                                     row 9 go through exactly the same code path. -->
                                <tbody id="proposal-items-tbody" class="divide-y divide-gray-100"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Row template. Cloned by addProposalRow() for every line, including
                         the first. The <select> inside is upgraded to a searchable picker
                         by lpc-product-picker.js the moment the clone lands in the DOM
                         (its MutationObserver watches for the attribute), and the picker
                         leaves behind a hidden input carrying the .prod-id class — so
                         submitProposal()'s row.querySelector('.prod-id').value is
                         unchanged and still returns a real product id.

                         Content inside <template> is inert: not rendered, not upgraded,
                         no request fired. -->
                    <template id="proposal-row-template">
                        <tr class="item-row">
                            <td class="p-2 align-top">
                                <select class="prod-id" data-lpc-product-picker="sell"
                                        aria-label="<?= htmlspecialchars(__t('ui.x.produit')) ?>"></select>
                            </td>
                            <td class="p-2 align-top">
                                <input type="number" class="prod-qty w-full border border-gray-300 rounded-lg p-2 text-sm text-center font-bold focus:ring-2 focus:ring-lpc-light outline-none" value="1" min="1"
                                       aria-label="<?= htmlspecialchars(__t('ui.x.qte')) ?>">
                            </td>
                            <td class="p-2 align-top">
                                <input type="number" class="prod-price w-full border border-gray-300 rounded-lg p-2 text-sm text-right font-bold focus:ring-2 focus:ring-lpc-light outline-none" value="0" min="0"
                                       aria-label="<?= htmlspecialchars(__t('ui.x.prix_unitaire')) ?>">
                            </td>
                            <td class="p-2 text-center align-top">
                                <button type="button" onclick="removeProposalRow(this)" class="text-red-300 hover:text-red-500 p-1"
                                        aria-label="<?= htmlspecialchars(__t('ui.x.supprimer_la_ligne')) ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </td>
                        </tr>
                    </template>

                </form>
            </div>
            
            <div class="bg-white px-6 py-4 border-t border-gray-200 flex justify-between items-center shrink-0">
                <div class="text-xs text-gray-500 font-medium"><i class="fas fa-lock text-gray-400 mr-1"></i> <?= htmlspecialchars(__t('ui.x.ce_devis_sera_securise_par_un_token_uniq')) ?></div>
                <div class="flex gap-3">
                    <button onclick="closeModal('proposalModal')" class="px-5 py-2.5 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-100 transition-colors"><?= htmlspecialchars(__t('ui.x.annuler')) ?></button>
                    <button onclick="submitProposal()" class="px-6 py-2.5 rounded-xl text-sm font-extrabold text-white bg-lpc-light hover:bg-green-600 shadow-lg shadow-lpc-light/20 transition-all flex items-center">
                        Créer & Obtenir le Lien
                        <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete-to-corbeille modal. A target client is mandatory: this client's
         orders/deliveries/payments/invoices/wallet/empties ledger all carry
         ON DELETE RESTRICT to clients (migration 006), so before this client
         can ever be purged, those rows must belong to someone else. See
         includes/classes/ClientTrash.php for exactly how each table merges. -->
    <div id="deleteClientModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50 backdrop-blur-sm p-4">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="bg-red-600 px-6 py-4 flex justify-between items-center text-white">
                <h3 class="font-bold text-lg">Déplacer vers la corbeille</h3>
                <button onclick="closeModal('deleteClientModal')" class="text-white/70 hover:text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <p class="text-sm text-gray-600">
                    Vous êtes sur le point de supprimer <strong id="delete_client_name" class="text-gray-900"></strong>.
                    Ses commandes, factures, paiements, portefeuille et emballages doivent d'abord être
                    réaffectés à un autre client — cette réaffectation est <strong>définitive</strong>,
                    même si vous restaurez ce client plus tard depuis la corbeille.
                </p>
                <input type="hidden" id="delete_client_id" value="">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Réaffecter les données vers</label>
                    <select id="delete_reassign_to" class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:ring-2 focus:ring-red-400 outline-none bg-white">
                        <option value="">— Sélectionnez un client —</option>
                    </select>
                </div>
                <p class="text-xs text-gray-400">
                    Le client sera déplacé vers la corbeille et supprimé définitivement dans
                    <span id="delete_retention_days">30</span> jours, sauf restauration.
                </p>
            </div>
            <div class="bg-gray-50 px-6 py-4 border-t border-gray-100 flex justify-end gap-3">
                <button onclick="closeModal('deleteClientModal')" class="px-5 py-2 rounded-xl text-sm font-bold text-gray-600 hover:bg-gray-200 transition-colors">Annuler</button>
                <button onclick="submitDeleteClient()" class="px-5 py-2 rounded-xl text-sm font-bold text-white bg-red-600 hover:bg-red-700 shadow-md transition-colors">Déplacer vers la corbeille</button>
            </div>
        </div>
    </div>

    <?php if ($canViewProposals): ?>
    <!-- =========================================================================
         Devis detail modal. One modal, two modes, decided by the server:

           editable  → the terms and the lines are inputs, footer offers Save.
           locked    → everything is text, footer offers "Nouveau devis à
                       partir de celui-ci" plus the signature proof block.

         proposal_detail.php returns `editable`; update_proposal.php re-derives
         the same rule inside its transaction, so this is presentation only and
         a stale tab cannot be used to edit a devis someone signed meanwhile.
         ========================================================================= -->
    <div id="devisModal" class="modal hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-2 md:p-6"
         role="dialog" aria-modal="true" aria-labelledby="devis-modal-title">
        <div class="modal-content bg-white rounded-2xl shadow-2xl w-full max-w-4xl h-full md:h-auto md:max-h-full overflow-hidden flex flex-col border border-gray-200">

            <div class="bg-white border-b border-gray-100 px-6 py-4 flex justify-between items-start shrink-0">
                <div>
                    <h3 class="font-extrabold text-xl text-gray-900 flex items-center gap-2" id="devis-modal-title">
                        <span id="devis-modal-ref">—</span>
                        <span id="devis-modal-badge"></span>
                    </h3>
                    <p class="text-sm text-gray-500 font-medium mt-0.5" id="devis-modal-sub"></p>
                </div>
                <button type="button" onclick="closeModal('devisModal')" class="p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 rounded-full transition-colors" aria-label="Fermer">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <div class="p-6 overflow-y-auto custom-scrollbar flex-1 bg-gray-50/50 devis-modal-scroll" id="devis-modal-body">
                <p class="text-sm text-gray-400 text-center py-10"><?= htmlspecialchars(__t('ui.account.loading')) ?></p>
            </div>

            <div class="bg-white px-6 py-4 border-t border-gray-200 flex flex-wrap justify-between items-center gap-3 shrink-0">
                <div class="flex gap-2" id="devis-modal-share"></div>
                <div class="flex gap-3" id="devis-modal-actions"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="<?= lpc_asset('/assets/js/modules/crm-clients.js') ?>" defer></script>
    <?php if ($canViewProposals): ?>
    <!-- Loaded after crm-clients.js: openProposalModal()/addProposalRow() live
         there and the duplicate flow calls straight into them rather than
         reimplementing the create form. -->
    <script src="<?= lpc_asset('/assets/js/modules/crm-devis.js') ?>" defer></script>
    <?php endif; ?>
</body>
</html>