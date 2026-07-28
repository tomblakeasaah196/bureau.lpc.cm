<?php
/**
 * modules/admin/roles.php  --  RETIRED (Sprint 8). Redirects to the Settings
 *                              module's RBAC tab.
 *
 * WHY
 * ---
 * This page rendered a full role/permission matrix UI. So did (or rather, was
 * supposed to) the "Rôles (RBAC)" tab in modules/settings/index.php. Both drew
 * on the same backend — api/v1/rbac_controller.php — but only this page had a
 * working front-end, while the settings tab had a nav button and no renderer,
 * which is why it appeared blank.
 *
 * Rather than keep two UIs over one API and let them drift, the matrix now
 * lives in one place: Administration → Paramètres → Rôles (RBAC). This file
 * stays as a redirect so existing bookmarks, the command palette, help-centre
 * deep links and any hardcoded hrefs elsewhere in the app keep working.
 *
 * The permission gate is preserved: a user without admin.roles.view should get
 * the standard 403 here, not a redirect into a page that will refuse them.
 *
 * Safe to delete once no inbound links remain — grep for 'admin/roles.php'.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
Rbac::requirePermission('admin.roles.view');

// Preserve the language selection across the redirect.
$lang = lpc_i18n_current_lang();

// 301 would be cached by browsers forever, which makes this hard to undo if we
// later decide the standalone page should come back. 302 keeps the option open.
header('Location: /modules/settings/index.php?tab=roles&lang=' . urlencode($lang), true, 302);
exit;
