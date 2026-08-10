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

if (!function_exists('lpc_nav_build_base_sections')) {

    /**
     * Builds the (role, lang) → RBAC-filtered section list, WITHOUT applying
     * rule 4 (which section starts expanded — that depends on the current
     * URL). Split from lpc_nav_sections() so the cross-request cache can
     * store this path-independent result and rule 4 can be re-applied cheaply
     * per request.
     *
     * @return array{out:array,catalogue:array,placed:array<string,bool>}
     */
    function lpc_nav_build_base_sections(string $configPath, string $role, bool $en): array
    {
        $cfg       = require $configPath;
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
                // Resolved by rule 4 in lpc_nav_sections() once the current
                // path is known. `null` here carries "the profile did not
                // state a preference"; a bool carries the explicit wish.
                'collapsed' => array_key_exists('collapsed', $section)
                    ? (bool) $section['collapsed']
                    : null,
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

        return ['out' => $out, 'catalogue' => $catalogue, 'placed' => $placed];
    }
}

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
        // The path is part of the key because rule 4 below expands whichever
        // section holds the current page. It is constant within a request, so
        // this only ever adds one memo entry — but leaving it out would be a
        // trap for the first caller that renders two paths in one request.
        $key  = $role . '|' . $lang . '|' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        // -----------------------------------------------------------------
        // Cross-request cache for the expensive part.
        //
        // The (role, lang) → RBAC-filtered sections mapping is the slow bit —
        // it iterates the catalogue, checks each permission, resolves labels
        // and copies rows. That result does NOT depend on the current path;
        // only rule 4 below does, and rule 4 is trivial to re-apply on top.
        //
        // So we cache the pre-rule-4 shape by (role, lang), invalidated by
        // nav.php's mtime (touch it and every cache entry becomes stale on
        // its next read — no manual bust). APCu is used when available;
        // otherwise we skip caching quietly. `sec_cross_request_cache` was
        // added to Prefs specifically as the kill-switch if this ever goes
        // wrong; falling through to the uncached path is the safe default.
        // -----------------------------------------------------------------
        $configPath   = __DIR__ . '/../config/nav.php';
        $configMtime  = @filemtime($configPath) ?: 0;
        $baseCacheKey = 'lpc.nav.base.v1:' . $role . '|' . $lang . '|' . $configMtime;

        $useApcu = function_exists('apcu_fetch') && function_exists('apcu_store') && ini_get('apc.enabled');

        $baseSections = null;
        if ($useApcu) {
            $found = false;
            $cached = apcu_fetch($baseCacheKey, $found);
            if ($found && is_array($cached)) {
                $baseSections = $cached;
            }
        }

        if ($baseSections === null) {
            $baseSections = lpc_nav_build_base_sections($configPath, $role, $en);
            if ($useApcu) {
                // 1 hour TTL. The mtime already invalidates on config edits;
                // TTL is only a floor for permission-check drift and role
                // rename edge cases that could otherwise leave a stale entry
                // forever on a rarely-restarted box.
                @apcu_store($baseCacheKey, $baseSections, 3600);
            }
        }

        // $baseSections['out'] is already the (role,lang)-scoped section list
        // WITH rule-3 extras appended. It does NOT know about the current
        // path yet — rule 4 below is the only path-dependent bit, and it
        // runs on every request against this cached array. Copy so the
        // in-place mutation of `collapsed` below never leaks into the cached
        // entry (APCu serializes on write, but the fallback code path may
        // hold the same reference in-process).
        $out = $baseSections['out'];

        // ---- Rule 4: which sections start expanded ---------------------------
        //
        // This used to be per-section data, and it was backwards: 'Stratégie &
        // BI' was the ONE section carrying `collapsed => true`, so the section
        // people actually land in started shut while all five others started
        // open — a full screen of links with nothing to orient against.
        //
        // Rather than flip a flag on 29 section definitions across six role
        // profiles (and rely on whoever adds role seven remembering), the rule
        // is expressed once, here:
        //
        //   1. The section containing the page you are ON is expanded, always,
        //      even over an explicit `collapsed => true`. Landing on a page
        //      whose own section is shut is disorienting, and no configuration
        //      should be able to produce it. (This matters for the generated
        //      'Autres Modules' section, which is declared collapsed.)
        //   2. Otherwise a profile's explicit `collapsed` value wins.
        //   3. Otherwise the FIRST section is expanded and the rest collapsed.
        //      One open section is an orientation point; six is a wall.
        //
        // The user's own toggling still wins over all of this on the next page
        // — lpc-sidebar.js remembers it per browser and only writes on a real
        // user action, so these defaults apply to a first visit only.
        $currentPath = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');

        $activeIdx = null;
        foreach ($out as $i => $section) {
            foreach ($section['items'] as $item) {
                if (strtok($item['href'], '#') === $currentPath) { $activeIdx = $i; break 2; }
            }
        }

        foreach ($out as $i => &$section) {
            if ($i === $activeIdx)            { $section['collapsed'] = false; continue; }  // rule 1
            if ($section['collapsed'] !== null) continue;                                    // rule 2
            $section['collapsed'] = ($activeIdx !== null) ? true : ($i !== 0);               // rule 3
        }
        unset($section);   // break the reference — a stale one here would make
                           // the next foreach over $out overwrite the last row

        return $memo[$key] = $out;
    }
}
