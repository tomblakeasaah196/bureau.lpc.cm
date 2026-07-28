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

        return $memo[$key] = $out;
    }
}
