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

$lang        = $lang ?? (in_array(($_GET['lang'] ?? 'fr'), ['fr','en'], true) ? ($_GET['lang'] ?? 'fr') : 'fr');

// Sprint 9: identity comes from UserProfile, not from the session mirrors.
// The session still holds user_name/avatar (UserProfile::save keeps them in
// sync) so the ~40 other call sites that read them keep working — but the
// sidebar reads the source of truth, which is what makes an edit in the
// profile modal show up here on the very next request instead of at next login.
require_once __DIR__ . '/../classes/UserProfile.php';
$__profile   = UserProfile::current();
$user_name   = $__profile['display_name'];
$user_role   = UserProfile::roleLabel() ?: ($_SESSION['user_role'] ?? 'Rôle');
$avatar      = $__profile['avatar'];
$initials    = $__profile['initials'];

$__currentPath = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

$__nav = lpc_nav_sections($lang);
?>
<aside id="lpc-sidebar"
       role="navigation"
       aria-label="<?= htmlspecialchars(__t('ui.a11y.main_nav')) ?>"
       class="fixed top-0 left-0 z-40 w-72 h-screen bg-white/5 backdrop-blur-2xl border-r border-white/10
              transform -translate-x-full lg:translate-x-0 transition-transform duration-300 flex flex-col">

    <!-- Header -->
    <?php
    // Sprint 8: the brand block used to hardcode the logo path and the
    // "Bureau ERP" subtitle, and read APP_NAME from .env — so renaming the ERP
    // meant editing a file on the server. All three now come from
    // company_profile (migration 034) and are editable at
    // Administration → Paramètres → Entreprise → Marque.
    // CompanyProfile falls back to APP_NAME if the table is not there yet.
    $lpcMark    = CompanyProfile::logo('mark');
    $lpcErpName = CompanyProfile::erpName();
    $lpcTagline = CompanyProfile::erpTagline();
    // Initials for the fallback tile when the logo file is missing.
    $lpcInitials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $lpcErpName) ?: 'ERP', 0, 3));
    ?>
    <div class="p-5 border-b border-white/10 flex items-center gap-3">
        <img src="<?= htmlspecialchars($lpcMark, ENT_QUOTES, 'UTF-8') ?>"
             onerror="this.outerHTML='<div class=\'w-10 h-10 rounded-full bg-emerald-600 grid place-items-center text-white font-bold text-xs\'><?= htmlspecialchars($lpcInitials, ENT_QUOTES, 'UTF-8') ?></div>'"
             alt="<?= htmlspecialchars($lpcErpName, ENT_QUOTES, 'UTF-8') ?>" class="w-10 h-10 rounded-full shrink-0 object-contain bg-white/10">
        <div class="min-w-0 lpc-logo-text">
            <div class="font-semibold text-white text-sm truncate"><?= htmlspecialchars($lpcErpName) ?></div>
            <?php if ($lpcTagline !== ''): ?>
                <div class="text-[10px] uppercase tracking-widest text-emerald-300 truncate"><?= htmlspecialchars($lpcTagline) ?></div>
            <?php endif; ?>
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
    <?php foreach ($__nav as $__sectionIdx => $__section):
        // lpc_nav_sections() has already dropped anything this user can't see,
        // and omits a section entirely when none of its items survive.
        $__headingId = 'lpc-nav-heading-' . $__sectionIdx;
        $__secKey    = 'sec' . $__sectionIdx;
    ?>
        <details class="lpc-nav-group" data-lpc-section="<?= htmlspecialchars($__secKey) ?>"
                 <?= $__section['collapsed'] ? '' : 'open' ?>>
            <summary id="<?= $__headingId ?>" class="lpc-nav-summary lpc-focusable">
                <span class="lpc-sidebar-heading"><?= htmlspecialchars($__section['heading']) ?></span>
                <svg class="lpc-nav-chevron" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/>
                </svg>
            </summary>
            <ul role="menu" class="space-y-0.5" aria-labelledby="<?= $__headingId ?>">
                <?php foreach ($__section['items'] as $__item):
                    $__active = ($__currentPath === strtok($__item['href'], '#'));
                ?>
                <li role="none">
                    <a href="<?= htmlspecialchars($__item['href']) ?>"
                       role="menuitem"
                       data-perm="<?= htmlspecialchars($__item['permission']) ?>"
                       <?= $__active ? 'aria-current="page"' : '' ?>
                       title="<?= htmlspecialchars($__item['label']) ?>"
                       class="lpc-focusable lpc-nav-link group flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition-all">
                        <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="<?= $__item['icon'] ?>"/>
                        </svg>
                        <span class="truncate lpc-label"><?= htmlspecialchars($__item['label']) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>
    </div>

    <!-- =====================================================================
         User card — the sticky footer.

         This used to be a static div with a logout <a> hanging off the right.
         It is now a single <button> that owns a menu, for three reasons:

           1. The logout link was a 40×40 target sitting 8px from the edge of
              the viewport, directly under where the mouse rests after
              scrolling the nav. Accidental logouts were the #1 support ping.
           2. There was nowhere to put "edit my profile" — the only route to
              your own display name was the HR module, which most roles cannot
              open at all.
           3. On the collapsed rail the card degraded to an avatar with a
              logout icon crammed beside it; now it degrades to just the
              avatar, and the menu flies out to the right.

         The menu markup is rendered server-side rather than built in JS so it
         is in the DOM for the first paint, works with JS still parsing, and is
         reachable by the command palette's DOM scan.
         ================================================================== -->
    <div class="lpc-user-card" data-lpc-usermenu>
        <button type="button"
                id="lpc-user-trigger"
                class="lpc-focusable lpc-user-trigger"
                aria-haspopup="menu"
                aria-expanded="false"
                aria-controls="lpc-user-menu"
                title="<?= htmlspecialchars($user_name) ?> — <?= $lang==='en'?'account menu':'menu du compte' ?>">
            <span class="lpc-user-avatar">
                <?php if ($avatar): ?>
                    <img src="<?= htmlspecialchars($avatar) ?>" alt="" width="40" height="40">
                <?php else: ?>
                    <span class="lpc-user-initials"><?= htmlspecialchars($initials) ?></span>
                <?php endif; ?>
                <!-- Presence dot. Static green today; the notifications poller
                     flips it to away/offline once it starts reporting idle. -->
                <span class="lpc-user-presence" data-lpc-presence="online" aria-hidden="true"></span>
            </span>
            <span class="lpc-user-text">
                <span class="lpc-user-name"><?= htmlspecialchars($user_name) ?></span>
                <span class="lpc-user-role"><?= htmlspecialchars($user_role) ?></span>
            </span>
            <svg class="lpc-user-chevron" width="16" height="16" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.5 15.75l7.5-7.5 7.5 7.5"/>
            </svg>
        </button>

        <div id="lpc-user-menu" class="lpc-user-menu" role="menu"
             aria-labelledby="lpc-user-trigger" hidden>

            <!-- Header: repeats the identity, because on the collapsed rail the
                 trigger shows only an avatar and the menu is the only place the
                 user can confirm which account they are about to act on. -->
            <div class="lpc-user-menu-head">
                <span class="lpc-user-avatar lpc-user-avatar--lg">
                    <?php if ($avatar): ?>
                        <img src="<?= htmlspecialchars($avatar) ?>" alt="" width="44" height="44">
                    <?php else: ?>
                        <span class="lpc-user-initials"><?= htmlspecialchars($initials) ?></span>
                    <?php endif; ?>
                </span>
                <span class="lpc-user-menu-id">
                    <span class="lpc-user-menu-name"><?= htmlspecialchars($user_name) ?></span>
                    <span class="lpc-user-menu-meta"><?= htmlspecialchars($__profile['email'] ?: $user_role) ?></span>
                </span>
            </div>

            <div class="lpc-user-menu-items">
                <button type="button" role="menuitem" class="lpc-user-menu-item" data-lpc-action="profile">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 1115 0v.75h-15v-.75z"/></svg>
                    <span><?= $lang === 'en' ? 'My profile' : 'Mon profil' ?></span>
                </button>

                <button type="button" role="menuitem" class="lpc-user-menu-item" data-lpc-action="password">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75M6.75 21h10.5a2.25 2.25 0 002.25-2.25v-6a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 12.75v6A2.25 2.25 0 006.75 21z"/></svg>
                    <span><?= $lang === 'en' ? 'Change password' : 'Changer le mot de passe' ?></span>
                </button>

                <div class="lpc-user-menu-sep" role="separator"></div>

                <button type="button" role="menuitem"
                        class="lpc-user-menu-item lpc-user-menu-item--danger"
                        data-lpc-action="signout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/></svg>
                    <span><?= $lang === 'en' ? 'Sign out' : 'Se déconnecter' ?></span>
                </button>
            </div>

            <!-- Quick strip: language and theme are one click from here because
                 they are the two settings people change most and neither is
                 worth opening a modal for. Both write through the same
                 profile_controller action the modal uses. -->
            <div class="lpc-user-menu-quick">
                <div class="lpc-quick-group" role="group"
                     aria-label="<?= $lang === 'en' ? 'Language' : 'Langue' ?>">
                    <button type="button" class="lpc-quick-btn" data-lpc-set-locale="fr"
                            aria-pressed="<?= $__profile['locale'] === 'fr' ? 'true' : 'false' ?>">FR</button>
                    <button type="button" class="lpc-quick-btn" data-lpc-set-locale="en"
                            aria-pressed="<?= $__profile['locale'] === 'en' ? 'true' : 'false' ?>">EN</button>
                </div>
                <div class="lpc-quick-group" role="group"
                     aria-label="<?= $lang === 'en' ? 'Theme' : 'Thème' ?>">
                    <button type="button" class="lpc-quick-btn" data-lpc-set-theme="light"
                            aria-pressed="<?= $__profile['theme'] === 'light' ? 'true' : 'false' ?>"
                            title="<?= $lang === 'en' ? 'Light' : 'Clair' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M12 3v1.5m0 15V21m9-9h-1.5m-15 0H3m15.36-6.36l-1.06 1.06M6.7 17.3l-1.06 1.06m12.72 0l-1.06-1.06M6.7 6.7L5.64 5.64M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
                    </button>
                    <button type="button" class="lpc-quick-btn" data-lpc-set-theme="dark"
                            aria-pressed="<?= $__profile['theme'] === 'dark' ? 'true' : 'false' ?>"
                            title="<?= $lang === 'en' ? 'Dark' : 'Sombre' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>
                    </button>
                    <button type="button" class="lpc-quick-btn" data-lpc-set-theme="system"
                            aria-pressed="<?= $__profile['theme'] === 'system' ? 'true' : 'false' ?>"
                            title="<?= $lang === 'en' ? 'Match system' : 'Système' ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25A2.25 2.25 0 015.25 3h13.5A2.25 2.25 0 0121 5.25z"/></svg>
                    </button>
                </div>
            </div>
        </div>
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
<?php
// lpc-dom.js is already emitted by head_assets.php — not repeated here.
// Every URL goes through lpc_asset() so it carries ?v=<mtime>; see
// includes/functions/assets.php for why bare asset paths are forbidden.
?>
<script src="<?= lpc_asset('/assets/js/lpc-rbac.js') ?>"    defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-sidebar.js') ?>" defer></script>

<?php
// The user menu's own state. Emitted as application/json rather than as an
// inline assignment so no user-controlled string (display name, bio) can ever
// break out of a <script> body — the JSON parser, not the HTML parser, reads
// it. Same technique as #lpc-bootstrap-data in head_assets.php.
?>
<script type="application/json" id="lpc-user-data"><?= json_encode(
    UserProfile::jsPayload(),
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP
) ?></script>
<?php
// The avatar picker compresses in the browser before upload. Loaded here
// rather than in head_assets.php because the account menu is the only shell
// component that needs it — pages with their own file inputs already require
// it themselves, and the double-include guard inside makes that safe.
?>
<script src="<?= lpc_asset('/assets/js/lpc-image-compress.js') ?>" defer></script>
<script src="<?= lpc_asset('/assets/js/lpc-usermenu.js') ?>" defer></script>
<?php
// The collapse-to-icons toggle lives in lpc-shell.js. It used to be included
// per-page, which meant it was present on only some pages (dead toggle on the
// rest) and would have double-bound its click handler had a page included it
// twice — two handlers = two toggles per click = no visible change. Loading it
// here, from the one component that renders the toggle button, makes it exactly
// once per page by construction. Guarded so a stray per-page tag is a no-op.
if (!defined('LPC_SHELL_JS_EMITTED')) {
    define('LPC_SHELL_JS_EMITTED', true);
    echo '<script src="' . lpc_asset('/assets/js/lpc-shell.js') . '" defer></script>' . "\n";
}
?>
<?php
// Free scope-local temporaries
// Only this component's own __-prefixed temporaries. Never unset a generic
// name here: an include shares the caller's scope and would wipe the page's var.
unset($__profile, $__nav, $__section, $__item, $__active, $__currentPath, $__sectionIdx, $__headingId, $__secKey);
?>
