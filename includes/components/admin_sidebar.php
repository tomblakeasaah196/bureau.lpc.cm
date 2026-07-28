<?php
/**
 * includes/components/admin_sidebar.php
 * -----------------------------------------------------------------------------
 * Backward-compat wrapper → sidebar.php. Nothing else.
 *
 * HISTORY / WHY THIS FILE IS NOW EMPTY OF MARKUP
 * ----------------------------------------------
 * The README claimed since Sprint 1 that this was already "a thin wrapper
 * around sidebar.php". It was not. Until 28 July 2026 it contained a complete
 * second sidebar implementation — 187 lines, `id="sidebar"` (not
 * `#lpc-sidebar`), a hardcoded nav list that ignored `nav.php` and RBAC, and
 * its own duplicated 30-minute auto-logout script.
 *
 * That meant 22 of the 24 shell-wired pages were rendering the LEGACY sidebar,
 * so none of the shared shell CSS (`#lpc-sidebar`), the collapse-to-icons
 * toggle, or the permission-driven nav ever applied to them.
 *
 * It also carried `lg:static`, so on desktop it sat in normal flow as a flex
 * child of the old `<body class="flex h-screen">`. When Sprint 7C moved the
 * shell to document flow, that static sidebar dropped into block flow and
 * pushed every page's content down below it — the "blank page, then the
 * sidebar scrolls away, then content starts at the bottom" bug.
 *
 * Everything it did that was worth keeping already exists on the canonical
 * path: `sidebar.php` renders the nav from `includes/config/nav.php` filtered
 * by RBAC and marks the active item server-side with `aria-current`, and
 * `assets/js/lpc-sidebar.js` owns both the mobile toggle and the inactivity
 * auto-logout (routed through `LPC.modal`, not a raw `alert()`).
 *
 * New pages should include `sidebar.php` directly. This file exists only so
 * existing `require .../admin_sidebar.php` lines keep working.
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/sidebar.php';
