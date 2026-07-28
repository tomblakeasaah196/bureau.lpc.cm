<?php
/**
 * includes/components/ops_sidebar.php
 * -----------------------------------------------------------------------------
 * Backward-compat wrapper → sidebar.php. Nothing else.
 *
 * Was a full second sidebar implementation (legacy `id="sidebar"`, hardcoded
 * operations nav list, `lg:static`) until 28 July 2026 — see the header comment
 * in admin_sidebar.php for the full history and why it had to go.
 *
 * The ops-specific nav it used to hardcode is now expressed as permissions in
 * `includes/config/nav.php` and filtered per-user by `sidebar.php`.
 * -----------------------------------------------------------------------------
 */

require __DIR__ . '/sidebar.php';
