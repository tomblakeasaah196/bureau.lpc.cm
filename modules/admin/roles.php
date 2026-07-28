<?php
/**
 * modules/admin/roles.php  --  Roles & Permissions administration.
 *
 * Requires:  admin.roles.view
 * Provides:  full RBAC administration UI (list roles, create/rename/delete,
 *            edit permission matrix per role via checkbox grid grouped by module).
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('admin.roles.view');

$lang     = in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? $_GET['lang'] : 'fr';
$canEdit  = Rbac::hasPermission('admin.roles.edit');
$canCreate= Rbac::hasPermission('admin.roles.create');
$canDelete= Rbac::hasPermission('admin.roles.delete');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= __t('ui.r_les_permissions') ?> | Bureau LPC</title>
<link rel="stylesheet" href="/assets/css/tailwind.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
.glass{background:#fff;border:1px solid #E5E7EB;box-shadow:0 1px 2px rgba(16,24,40,.04)}
.chip{background:#F3F4F6;border:1px solid #E5E7EB;padding:.15rem .5rem;border-radius:9999px;font-size:.7rem;color:#374151}
.perm-row:hover{background:#F9FAFB}
input[type=checkbox]{accent-color:#005A2B;width:1rem;height:1rem}
.btn{padding:.5rem 1rem;border-radius:.5rem;font-weight:600;font-size:.85rem;cursor:pointer;transition:all .15s}
.btn-primary{background:linear-gradient(90deg,#005A2B,#8CC63F);color:#fff}
.btn-primary:hover{opacity:.92}
.btn-secondary{background:#fff;color:#374151;border:1px solid #D1D5DB}
.btn-secondary:hover{background:#F9FAFB}
.btn-danger{background:#FEF2F2;color:#B91C1C;border:1px solid #FECACA}
.btn-danger:hover{background:#FEE2E2}
.btn:disabled{opacity:.45;cursor:not-allowed}
</style>
    <?php require $_SERVER['DOCUMENT_ROOT'] . '/includes/components/head_assets.php'; ?>
    <script>(function(){try{if(localStorage.getItem('lpc.sidebar.collapsed')==='true')document.documentElement.classList.add('lpc-collapsed');}catch(e){}})();</script>
    <link rel="stylesheet" href="/assets/css/lpc-shell.css">
</head>
<body class="lpc-body bg-lpc-bg font-sans text-gray-800 antialiased">
<a href="#main" class="lpc-skip-link"><?= htmlspecialchars(__t('ui.a11y.skip_to_content')) ?></a>


<?php
require __DIR__ . '/../../includes/components/sidebar.php';
require __DIR__ . '/../../includes/components/topbar.php';
?>

<div id="lpc-shell-main">

    <div class="lpc-toolbar">
        <?php if ($canCreate): ?>
        <button onclick="openCreateRoleModal()" class="btn btn-primary">
            + <?= __t('ui.nouveau_r_le') ?>
        </button>
        <?php endif; ?>
    </div>

<main role="main" id="main" class="lpc-page">

    <div class="grid grid-cols-1 lg:grid-cols-[340px_1fr] gap-6">

        <!-- ROLES LIST -->
        <section class="glass rounded-xl p-4">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-500"><?= __t('ui.r_les') ?></h2>
                <span id="roles-count" class="chip">—</span>
            </div>
            <ul id="roles-list" class="space-y-2">
                <li class="text-gray-500 text-sm p-3">Chargement…</li>
            </ul>

            <?php if ($canEdit): ?>
            <button onclick="resetDefaults()" class="btn btn-secondary w-full mt-4 text-xs">
                ⟲ <?= __t('ui.r_initialiser_les_r_les_syst_me') ?>
            </button>
            <?php endif; ?>
        </section>

        <!-- PERMISSION MATRIX -->
        <section class="glass rounded-xl p-4">
            <div id="matrix-header" class="hidden mb-4">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900" id="matrix-role-name">—</h2>
                        <p class="text-xs text-gray-500" id="matrix-role-meta">—</p>
                    </div>
                    <div class="flex gap-2">
                        <button onclick="matrixSelectAll()"  class="btn btn-secondary text-xs"><?= __t('ui.tout') ?></button>
                        <button onclick="matrixSelectNone()" class="btn btn-secondary text-xs"><?= __t('ui.aucun') ?></button>
                        <?php if ($canEdit): ?>
                        <button id="btn-save-matrix" onclick="saveMatrix()" class="btn btn-primary text-xs"><?= __t('ui.enregistrer') ?></button>
                        <?php endif; ?>
                    </div>
                </div>
                <input id="filter-perms" type="text" placeholder="<?= __t('ui.filtrer_permissions') ?>"
                       class="mt-3 w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:border-emerald-400">
            </div>

            <div id="matrix-empty" class="text-gray-500 text-sm p-6 text-center">
                <?= __t('ui.s_lectionnez_un_r_le_gauche_pour_voir_se') ?>
            </div>

            <div id="matrix-body" class="hidden space-y-4"></div>
        </section>
    </div>

</main>
</div>

<!-- CREATE / RENAME ROLE MODAL -->
<div id="modal-role" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="glass rounded-xl p-6 w-full max-w-md">
        <h3 id="modal-role-title" class="text-lg font-semibold text-gray-900 mb-4">Nouveau rôle</h3>
        <input type="hidden" id="modal-role-id">
        <label class="text-xs uppercase tracking-wider text-gray-500">Nom du rôle</label>
        <input id="modal-role-name" type="text"
               class="mt-1 w-full bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 text-gray-900"
               placeholder="ex: warehouse_manager" maxlength="32">
        <p class="text-[11px] text-gray-500 mt-1">Lettres minuscules, chiffres, tirets, underscores. 2-32 caractères.</p>
        <div class="flex justify-end gap-2 mt-6">
            <button onclick="closeModal('modal-role')" class="btn btn-secondary">Annuler</button>
            <button onclick="submitRole()" class="btn btn-primary">Enregistrer</button>
        </div>
    </div>
</div>

<!-- TOAST -->
<div id="toast" class="fixed bottom-6 right-6 z-50 hidden px-4 py-3 rounded-lg text-sm font-medium"></div>

<script type="application/json" id="lpc-page-data"><?= json_encode(['v1' => (bool) ($canEdit),'v2' => (bool) ($canCreate),'v3' => (bool) ($canDelete),'v4' => $lang,'v5' => __t('ui.utilisateurs'),'v6' => __t('ui.tout'),'v7' => __t('ui.aucun')], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?></script>
<script src="/assets/js/modules/admin-roles.js" defer></script>
</body>
</html>
