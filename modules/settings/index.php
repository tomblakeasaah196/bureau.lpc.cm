<?php
/**
 * modules/settings/index.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Administration, Sécurité & Configuration.
 *
 * Six tabs, three shapes:
 *   TABLE   Utilisateurs · Sessions · Logs d'audit      -> paginated data grid
 *   FORM    Entreprise · Préférences                    -> sectioned settings form
 *   MATRIX  Rôles (RBAC)                                -> permission checkbox grid
 *
 * SPRINT 8 — WHAT WAS WRONG BEFORE
 * --------------------------------
 * The nav had five tabs but settings-index.js only defined three configs
 * (users/sessions/audits). Clicking "Rôles (RBAC)" or "Préférences" read
 * settingsConfig[undefined] and threw before rendering anything, so the page
 * silently kept showing the previous tab's table — which is why those two
 * looked "empty and repeating the others".
 *
 * The fix is a tab registry that declares a SHAPE per tab, and a renderer per
 * shape. Adding a tab now means adding one registry entry; it can no longer
 * half-exist in the nav without a renderer.
 *
 * RBAC administration is served by api/v1/rbac_controller.php, which already
 * existed and was already complete. modules/admin/roles.php used to render a
 * second, separate UI over that same API — it now redirects here.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('admin.settings.view');

$lang = isset($_GET['lang']) && $_GET['lang'] == 'en' ? 'en' : 'fr';

// Which tabs this user may actually see. A tab the user cannot read is not
// rendered at all — better than rendering it and 403-ing on click.
// Each new permission falls back to admin.settings.view so that environments
// where migration 034's grants have not been applied still behave sanely.
$baseView = Rbac::hasPermission('admin.settings.view');
$canTabs  = [
    'users'       => $baseView,
    'roles'       => Rbac::hasPermission('admin.roles.view')   || $baseView,
    'company'     => Rbac::hasPermission('admin.company.view') || $baseView,
    'preferences' => Rbac::hasPermission('admin.prefs.view')   || $baseView,
    'sessions'    => $baseView,
    'audits'      => $baseView,
];

// Deep-link support: /modules/settings/index.php?tab=company
$initialTab = $_GET['tab'] ?? 'users';
if (!isset($canTabs[$initialTab]) || !$canTabs[$initialTab]) {
    $initialTab = 'users';
}

$tabDefs = [
    'users'       => ['icon' => 'fa-user-shield',     'fr' => 'Utilisateurs',   'en' => 'Users'],
    'roles'       => ['icon' => 'fa-key',             'fr' => 'Rôles (RBAC)',   'en' => 'Roles (RBAC)'],
    'company'     => ['icon' => 'fa-building',        'fr' => 'Entreprise',     'en' => 'Company'],
    'preferences' => ['icon' => 'fa-sliders',         'fr' => 'Préférences',    'en' => 'Preferences'],
    'sessions'    => ['icon' => 'fa-satellite-dish',  'fr' => 'Sessions',       'en' => 'Sessions'],
    'audits'      => ['icon' => 'fa-clipboard-list',  'fr' => "Logs d'Audit",   'en' => 'Audit Logs'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administration &amp; Configuration | <?= htmlspecialchars(CompanyProfile::erpName()) ?></title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/vendor/fontawesome/css/all.min.css" integrity="sha384-iw3OoTErCYJJB9mCa8LNS2hbsQ7M3C0EpIsO/H5+EGAkPGc6rk+V8i04oW/K5xq0" crossorigin="anonymous">

    <style>
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .lpc-panel { animation: fadeIn 0.25s ease; }

        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* Settings form primitives — shared by the Company and Preferences tabs
           so the two never drift apart visually. */
        .lpc-field-label { display:block; font-size:.7rem; font-weight:800; letter-spacing:.06em;
                           text-transform:uppercase; color:#6B7280; margin-bottom:.35rem; }
        .lpc-input { width:100%; background:#F9FAFB; border:1px solid #E5E7EB; border-radius:.75rem;
                     padding:.7rem .85rem; font-size:.875rem; font-weight:500; color:#111827;
                     outline:none; transition:border-color .15s, box-shadow .15s; }
        .lpc-input:focus { border-color:#005A2B; box-shadow:0 0 0 3px rgba(0,90,43,.12); background:#fff; }
        .lpc-input:disabled { background:#F3F4F6; color:#9CA3AF; cursor:not-allowed; }
        .lpc-input.is-dirty { border-color:#8CC63F; background:#F7FCEF; }
        .lpc-input.is-error { border-color:#DC2626; background:#FEF2F2; }
        .lpc-field-help { font-size:.7rem; color:#9CA3AF; margin-top:.3rem; line-height:1.35; }

        .lpc-section { background:#fff; border:1px solid #E5E7EB; border-radius:1rem;
                       box-shadow:0 1px 2px rgba(16,24,40,.04); margin-bottom:1.25rem; overflow:hidden; }
        .lpc-section-head { display:flex; align-items:center; gap:.75rem; padding:1rem 1.5rem;
                            border-bottom:1px solid #F3F4F6; background:#FCFCFD; }
        .lpc-section-body { padding:1.5rem; }

        /* Sticky save bar — a settings form long enough to scroll needs the
           save button to stay reachable. */
        .lpc-savebar { position:sticky; bottom:0; z-index:20; background:rgba(255,255,255,.92);
                       backdrop-filter:blur(8px); border:1px solid #E5E7EB;
                       padding:.9rem 1.5rem; display:flex; justify-content:space-between;
                       align-items:center; border-radius:1rem; margin-top:.5rem;
                       box-shadow:0 -2px 12px rgba(16,24,40,.06); }

        /* Logo picker */
        .lpc-logo-drop { border:2px dashed #D1D5DB; border-radius:.75rem; padding:1rem;
                         display:flex; align-items:center; gap:1rem; cursor:pointer;
                         transition:border-color .15s, background .15s; background:#F9FAFB; }
        .lpc-logo-drop:hover { border-color:#8CC63F; background:#F7FCEF; }
        .lpc-logo-drop img { max-height:48px; max-width:140px; object-fit:contain; }

        /* RBAC matrix */
        .perm-row:hover { background:#F9FAFB; }
        .lpc-matrix input[type=checkbox] { accent-color:#005A2B; width:1rem; height:1rem; cursor:pointer; }
        .lpc-role-chip { border:1px solid #E5E7EB; background:#fff; border-radius:.75rem;
                         padding:.6rem .9rem; cursor:pointer; text-align:left; transition:all .15s; }
        .lpc-role-chip:hover { border-color:#8CC63F; }
        .lpc-role-chip.active { border-color:#005A2B; background:#F7FCEF; box-shadow:0 0 0 2px rgba(0,90,43,.1); }

        /* Letterhead preview — mirrors the PDF template closely enough to be
           useful without pretending to be pixel-exact. */
        .lpc-letterhead { border:1px solid #E5E7EB; border-radius:.75rem; padding:1.5rem;
                          background:#fff; }
    </style>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>

    <?php
    $pageTitle    = 'Administration & Configuration';
    $pageSubtitle = 'Identité, sécurité, préférences & audits';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/components/admin_sidebar.php';
    require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/topbar.php';
    ?>

    <div id="lpc-shell-main">

        <nav class="lpc-tabs" id="settings-tabs" role="tablist">
            <?php foreach ($tabDefs as $key => $def): ?>
                <?php if (empty($canTabs[$key])) continue; ?>
                <button type="button"
                        onclick="LPCSettings.switchTab('<?= $key ?>')"
                        id="tab-<?= $key ?>"
                        role="tab"
                        aria-selected="<?= $key === $initialTab ? 'true' : 'false' ?>"
                        aria-controls="panel-region"
                        class="tab-link py-4 border-b-2 <?= $key === $initialTab
                            ? 'border-gray-900 text-gray-900 font-black'
                            : 'border-transparent text-gray-400 hover:text-gray-600 font-bold' ?> text-sm uppercase tracking-wider transition-all">
                    <i class="fas <?= $def['icon'] ?> mr-2"></i> <?= htmlspecialchars($def[$lang]) ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <main role="main" id="main" class="lpc-page lpc-page-col">

            <!-- KPI ribbon — populated per tab, hidden on tabs that have no KPIs -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 hidden" id="kpi-ribbon"></div>

            <!-- Toolbar — search + primary action. Hidden on form tabs. -->
            <div class="justify-between items-center mb-6 hidden" id="toolbar">
                <div class="relative w-96">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <label for="search-input" class="sr-only">Rechercher</label>
                    <input type="text" id="search-input" placeholder="Rechercher..."
                           oninput="LPCSettings.filterData()"
                           class="w-full pl-12 pr-4 py-3 bg-white border border-gray-200 rounded-xl text-sm font-medium outline-none focus:ring-2 focus:ring-gray-900 transition-shadow shadow-sm">
                </div>
                <button id="btn-primary-action" type="button" onclick="LPCSettings.openActionModal()"
                        class="bg-gray-900 hover:bg-black text-white px-6 py-3 rounded-xl font-bold text-sm shadow-md flex items-center gap-2 transition-transform active:scale-95">
                    <i class="fas fa-plus"></i> <span id="btn-action-text">Ajouter</span>
                </button>
            </div>

            <!-- The single region every renderer writes into. -->
            <div id="panel-region" class="flex-1 flex flex-col min-h-0" role="tabpanel">

                <!-- TABLE shape -->
                <div id="panel-table" class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex-1 flex-col hidden">
                    <div class="overflow-x-auto flex-1">
                        <table class="min-w-full text-left border-collapse">
                            <thead class="bg-gray-50 border-b border-gray-200 sticky top-0" id="table-head"></thead>
                            <tbody id="table-body" class="divide-y divide-gray-100 text-sm"></tbody>
                        </table>
                    </div>
                </div>

                <!-- FORM shape (Company, Preferences) -->
                <div id="panel-form" class="lpc-panel hidden"></div>

                <!-- MATRIX shape (RBAC) -->
                <div id="panel-matrix" class="lpc-panel hidden"></div>

            </div>
        </main>
    </div>

    <!-- Generic record modal (users) -->
    <div id="actionModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4 transition-opacity">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden flex flex-col">
            <div class="bg-gray-900 px-8 py-5 flex justify-between items-center text-white">
                <h3 id="modal-title" class="font-black text-lg tracking-wide">...</h3>
                <button type="button" onclick="LPCSettings.closeModal()" class="text-white/70 hover:text-white" aria-label="Fermer">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="p-8 overflow-y-auto max-h-[70vh]">
                <form id="dynamic-form" class="grid grid-cols-1 md:grid-cols-2 gap-6"></form>
            </div>
            <div class="px-8 py-5 bg-gray-50 border-t border-gray-100 flex justify-end gap-3">
                <button type="button" onclick="LPCSettings.closeModal()" class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-800">Annuler</button>
                <button type="button" onclick="LPCSettings.saveData()" class="px-8 py-2.5 bg-lpc-light hover:bg-green-600 text-white rounded-xl font-bold text-sm shadow-md">Enregistrer</button>
            </div>
        </div>
    </div>

    <!-- Hidden file input, shared by every logo slot. -->
    <input type="file" id="logo-file-input" accept="image/png,image/jpeg,image/webp" class="hidden">

    <script type="application/json" id="lpc-settings-boot"><?= json_encode([
        'initialTab' => $initialTab,
        'lang'       => $lang,
        'can'        => [
            'editUsers'   => Rbac::hasPermission('admin.settings.edit'),
            'editCompany' => Rbac::hasPermission('admin.company.edit') || Rbac::hasPermission('admin.settings.edit'),
            'editPrefs'   => Rbac::hasPermission('admin.prefs.edit')   || Rbac::hasPermission('admin.settings.edit'),
            'editRoles'   => Rbac::hasPermission('admin.roles.edit'),
            'createRoles' => Rbac::hasPermission('admin.roles.create'),
            'deleteRoles' => Rbac::hasPermission('admin.roles.delete'),
        ],
        'tabs' => array_keys(array_filter($canTabs)),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>

    <script src="<?= lpc_asset('/assets/js/modules/settings-index.js') ?>" defer></script>
</body>
</html>
