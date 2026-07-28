<?php
/**
 * includes/functions/navigation.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — resolves includes/config/nav.php into a ready-to-render tree.
 *
 * One resolver, two consumers: includes/components/sidebar.php and
 * includes/components/command_palette.php. They must never disagree about what
 * a user can reach or what it is called, so neither reads the config directly.
 *
 * SECURITY: the per-role profile decides presentation only — order, grouping and
 * wording. Every resolved item is still filtered through Rbac::hasPermission()
 * here, using the permission from the CATALOGUE, not from the profile. A profile
 * cannot grant anything; listing an item a user lacks permission for simply
 * drops it. Sections left with no visible items are omitted entirely.
 *
 * THE OVERLAY RULE (28 July 2026)
 * -------------------------------
 * Profiles are a PRESENTATION OVERLAY, not an allow-list. Concretely:
 *
 *   1. If you hold the permission, the item appears. No exceptions.
 *   2. The profile decides where it sits and what it is called.
 *   3. Anything you hold but the profile does not place falls into a catch-all
 *      section at the end, under its neutral catalogue name.
 *
 * Rule 3 is the important one. Profiles used to be an allow-list, which meant a
 * permission could be granted and the item still never appear — that is why the
 * admin, holding almost every permission, could not see Écritures,
 * Immobilisations or Déclarations Fiscales. It also meant every new module was
 * invisible until someone remembered to edit nav.php. Now the nav is a true
 * function of permissions, and curation only controls presentation.
 *
 * Corollary worth remembering: to hide something from a role, REVOKE THE
 * PERMISSION — do not try to hide it by leaving it out of the profile. Hiding a
 * menu item never protected a page anyway; Rbac::requirePermission() at the top
 * of each page is the actual gate.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('lpc_nav_sections')) {

    /**
     * @param string|null $lang 'fr'|'en'. Defaults to the request language.
     * @return array<int, array{heading:string, collapsed:bool, items:array<int,
     *         array{label:string, href:string, icon:string, permission:string}>}>
     */
    function lpc_nav_sections(?string $lang = null): array
    {
        static $memo = [];

        $lang = $lang ?: (in_array(($_GET['lang'] ?? 'fr'), ['fr', 'en'], true)
            ? ($_GET['lang'] ?? 'fr')
            : 'fr');
        $en = ($lang === 'en');

        $role = strtolower(trim((string) ($_SESSION['user_role'] ?? '')));
        $key  = $role . '|' . $lang;
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $cfg       = require __DIR__ . '/../config/nav.php';
        $catalogue = $cfg['catalogue'] ?? [];
        $profiles  = $cfg['profiles']  ?? [];
        $aliases   = $cfg['aliases']   ?? [];

        // Pick the presentation profile: exact role, then alias, then default.
        $profileKey = isset($profiles[$role])
            ? $role
            : ($aliases[$role] ?? null);
        if ($profileKey === null || !isset($profiles[$profileKey])) {
            $profileKey = 'default';
        }
        $profile = $profiles[$profileKey] ?? [];

        $out = [];
        foreach ($profile as $section) {
            $items = [];

            foreach (($section['items'] ?? []) as $entry) {
                $ref  = $entry['ref'] ?? null;
                $base = $ref !== null ? ($catalogue[$ref] ?? null) : null;
                if ($base === null) {
                    continue;                       // unknown ref — skip quietly
                }

                // THE gate. Always the catalogue's permission.
                if (!Rbac::hasPermission($base['permission'])) {
                    continue;
                }

                // Profile label wins; catalogue label is the fallback.
                $label = $en
                    ? ($entry['label_en'] ?? $base['label_en'] ?? $base['label_fr'] ?? '')
                    : ($entry['label_fr'] ?? $base['label_fr'] ?? '');

                $items[] = [
                    'label'      => $label,
                    'href'       => $base['href'] . ($entry['suffix'] ?? ''),
                    'icon'       => $base['icon'],
                    'permission' => $base['permission'],
                ];
            }

            if (!$items) {
                continue;                            // nothing visible — no heading
            }

            $out[] = [
                'heading'   => $en
                    ? ($section['heading_en'] ?? $section['heading_fr'] ?? '')
                    : ($section['heading_fr'] ?? ''),
                'collapsed' => (bool) ($section['collapsed'] ?? false),
                'items'     => $items,
            ];
        }

        // ---- Rule 3: auto-surface anything permitted but unplaced -------------
        // Without this, granting a permission would not be enough to make an
        // item appear, and every new module would stay invisible until someone
        // edited nav.php. The nav is a function of permissions; the profile only
        // curates presentation.
        $placed = [];
        foreach ($profile as $section) {
            foreach (($section['items'] ?? []) as $entry) {
                if (isset($entry['ref'])) { $placed[$entry['ref']] = true; }
            }
        }

        $extra = [];
        foreach ($catalogue as $ref => $base) {
            if (isset($placed[$ref])) continue;
            if (!Rbac::hasPermission($base['permission'])) continue;
            $extra[] = [
                'label'      => $en
                    ? ($base['label_en'] ?? $base['label_fr'] ?? '')
                    : ($base['label_fr'] ?? ''),
                'href'       => $base['href'],
                'icon'       => $base['icon'],
                'permission' => $base['permission'],
            ];
        }
        if ($extra) {
            $out[] = [
                'heading'   => $en ? 'Other Modules' : 'Autres Modules',
                'collapsed' => true,
                'items'     => $extra,
            ];
        }

        return $memo[$key] = $out;
    }
}
