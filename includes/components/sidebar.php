<?php
/**
 * includes/components/sidebar.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Unified sidebar renderer.
 *
 * Renders one sidebar per request, filtered by the current user's RBAC
 * permissions. Backward-compat wrappers (admin_sidebar.php, driver_sidebar.php,
 * finance_sidebar.php, ops_sidebar.php) all include this file, so no existing
 * page needs to change its include statement.
 *
 * Included variables the caller may optionally set BEFORE include:
 *   $lang  ('fr'|'en'; falls back to $_GET['lang'] or 'fr')
 *
 * Requires the bootstrap to have run (Rbac::init() called).
 * -----------------------------------------------------------------------------
 */

if (!defined('LPC_BOOTSTRAPPED')) {
    require_once __DIR__ . '/../bootstrap.php';
}
Rbac::requireAuth();

$lang        = $lang ?? (in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? $_GET['lang'] : 'fr');
$user_name   = $_SESSION['user_name']    ?? 'Utilisateur';
$user_role   = $_SESSION['user_role']    ?? 'Rôle';
$avatar      = $_SESSION['avatar']       ?? null;

// Compute initials: first letter of each of the first two words (mb-safe).
$__parts    = preg_split('/\s+/', trim($user_name), 2) ?: [];
$__i1       = mb_substr($__parts[0] ?? '', 0, 1, 'UTF-8');
$__i2       = mb_substr($__parts[1] ?? ($__parts[0] ?? ''), 0, 1, 'UTF-8');
$initials   = mb_strtoupper($__i1 . $__i2, 'UTF-8');

$currentPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

$nav = require __DIR__ . '/../config/nav.php';
?>
<aside id="lpc-sidebar"
       role="navigation"
       aria-label="<?= htmlspecialchars(__t('ui.a11y.main_nav')) ?>"
       class="fixed top-0 left-0 z-40 w-72 h-screen bg-white/5 backdrop-blur-2xl border-r border-white/10
              transform -translate-x-full lg:translate-x-0 transition-transform duration-300 flex flex-col">

    <!-- Header -->
    <div class="p-5 border-b border-white/10 flex items-center gap-3">
        <img src="/assets/img/small_logo.svg"
             onerror="this.outerHTML='<div class=\'w-10 h-10 rounded-full bg-emerald-600 grid place-items-center text-white font-bold\'>LPC</div>'"
             alt="LPC" class="w-10 h-10 rounded-full shrink-0">
        <div class="min-w-0 lpc-logo-text">
            <div class="font-semibold text-white text-sm truncate"><?= htmlspecialchars(APP_NAME) ?></div>
            <div class="text-[10px] uppercase tracking-widest text-emerald-300">Bureau ERP</div>
        </div>
        <button onclick="LPC_toggleSidebar(false)"
                class="ml-auto lg:hidden text-white/60 hover:text-white p-1" aria-label="Fermer">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <button data-lpc-collapse-toggle
                aria-expanded="true"
                aria-controls="lpc-sidebar"
                title="<?= $lang==='en'?'Collapse sidebar':'Réduire le menu' ?>"
                aria-label="<?= $lang==='en'?'Collapse sidebar':'Réduire le menu' ?>"
                class="lpc-focusable hidden lg:flex ml-auto shrink-0 text-white/60 hover:text-white p-1.5 rounded-lg hover:bg-white/5">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </button>
    </div>

    <!-- Nav -->
    <div class="flex-1 overflow-y-auto py-3 px-3 space-y-4">
    <?php foreach ($nav as $sectionIdx => $section):
        // Skip section entirely if user can't see any of its items.
        $visible = array_filter($section['items'], function ($it) {
            return Rbac::hasPermission($it['permission']);
        });
        if (!$visible) continue;
        $headingId = 'lpc-nav-heading-' . $sectionIdx;
    ?>
        <div role="group" aria-labelledby="<?= $headingId ?>">
            <div id="<?= $headingId ?>"
                 class="lpc-sidebar-heading px-3 pb-1 text-[10px] uppercase tracking-widest">
                <?= htmlspecialchars($lang === 'en' ? ($section['heading_en'] ?? $section['heading_fr']) : $section['heading_fr']) ?>
            </div>
            <ul role="menu" class="space-y-0.5">
                <?php foreach ($visible as $item):
                    $label = $lang === 'en' ? ($item['label_en'] ?? $item['label_fr']) : $item['label_fr'];
                    $active = ($currentPath === $item['href']);
                ?>
                <li role="none">
                    <a href="<?= htmlspecialchars($item['href']) ?>"
                       role="menuitem"
                       data-perm="<?= htmlspecialchars($item['permission']) ?>"
                       <?= $active ? 'aria-current="page"' : '' ?>
                       title="<?= htmlspecialchars($label) ?>"
                       class="lpc-focusable lpc-nav-link group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-all
                              <?= $active
                                  ? 'bg-emerald-500/20 text-white border border-emerald-400/30'
                                  : 'text-white/70 hover:bg-white/5 hover:text-white border border-transparent' ?>">
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="<?= $item['icon'] ?>"/>
                        </svg>
                        <span class="truncate lpc-label"><?= htmlspecialchars($label) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- User card + logout -->
    <div class="lpc-user-card p-4 border-t border-white/10 flex items-center gap-3">
        <?php if ($avatar): ?>
            <img src="<?= htmlspecialchars($avatar) ?>" alt="" class="w-10 h-10 rounded-full object-cover ring-2 ring-emerald-400/40 shrink-0">
        <?php else: ?>
            <div class="w-10 h-10 rounded-full bg-emerald-500/30 text-white grid place-items-center font-semibold text-sm ring-2 ring-emerald-400/40 shrink-0">
                <?= htmlspecialchars($initials) ?>
            </div>
        <?php endif; ?>
        <div class="min-w-0 flex-1 lpc-user-text">
            <div class="text-sm font-medium text-white truncate"><?= htmlspecialchars($user_name) ?></div>
            <div class="text-[10px] uppercase tracking-widest text-white/70"><?= htmlspecialchars($user_role) ?></div>
        </div>
        <a href="/api/v1/auth.php?logout=true" class="shrink-0 text-white/70 hover:text-red-400 p-2" title="<?= $lang==='en'?'Log out':'Se déconnecter' ?>" aria-label="<?= $lang==='en'?'Log out':'Se déconnecter' ?>">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
        </a>
    </div>
</aside>

<!-- Mobile hamburger + backdrop -->
<button id="lpc-sidebar-toggle"
        onclick="LPC_toggleSidebar()"
        aria-controls="lpc-sidebar"
        aria-expanded="false"
        aria-label="<?= htmlspecialchars(__t('ui.a11y.sidebar_toggle')) ?>"
        class="lpc-focusable fixed top-3 left-3 z-30 lg:hidden bg-white/10 backdrop-blur-md border border-white/15 rounded-lg p-2 text-white">
    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div id="lpc-sidebar-backdrop"
     onclick="LPC_toggleSidebar(false)"
     class="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 hidden lg:hidden"></div>

<!-- Bootstrap the JS RBAC helper with the current user's permission set -->
<?= Rbac::jsBootstrap() ?>
<script src="/assets/js/lpc-dom.js"     defer></script>
<script src="/assets/js/lpc-rbac.js"    defer></script>
<script src="/assets/js/lpc-sidebar.js" defer></script>
<?php
// Free scope-local temporaries
unset($__parts, $__i1, $__i2, $nav, $visible, $section, $item, $label, $active, $currentPath);
?>
