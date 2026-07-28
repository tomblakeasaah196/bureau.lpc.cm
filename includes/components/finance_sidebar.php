<?php
/**
 * includes/components/finance_sidebar.php
 * -----------------------------------------------------------------------------
 * Backward-compat wrapper → sidebar.php. Nothing else.
 *
 * Was a full second sidebar implementation (legacy `id="sidebar"`, hardcoded
 * finance nav list, `lg:static`) until 28 July 2026 — see the header comment in
 * admin_sidebar.php for the full history and why it had to go.
 *
 * The finance-specific nav it used to hardcode is now expressed as permissions
 * in `includes/config/nav.php`: an accountant sees the finance sections and not
 * the others because `sidebar.php` filters every item through
 * `Rbac::hasPermission()` and skips any section whose items are all hidden.
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/sidebar.php';
