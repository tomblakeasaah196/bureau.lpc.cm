<?php
/**
 * includes/classes/UserProfile.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 9 · the current user's own identity + preferences.
 *
 * THE ONE RULE
 *   Nothing outside this class reads employee_profiles for the CURRENT user.
 *   The shell needs display name, avatar, initials, locale, theme, accent and
 *   density on literally every page render, and before this class those came
 *   from five different places that quietly disagreed:
 *
 *     $_SESSION['user_name']   set once at login (api/v1/auth.php:138) and
 *                              never refreshed — editing your name did nothing
 *                              until you logged out and back in.
 *     $_SESSION['avatar']      same, except mdm_controller.php:251 patched it
 *                              in-place for the ONE case where you edited
 *                              yourself through the HR module.
 *     $_SESSION['lang']        i18n.php, cookie-backed, per-device.
 *     localStorage             sidebar collapse, per-device.
 *     nothing at all           theme, accent, density.
 *
 *   Now: one row, loaded once per request, written through one method that
 *   also refreshes the session mirror. Session keys are kept in sync rather
 *   than removed, because ~40 call sites read $_SESSION['user_name'] directly
 *   and rewriting them all is a separate change.
 *
 * PUBLIC API
 *   UserProfile::current()          array — the whole resolved profile
 *   UserProfile::displayName()      string — never empty
 *   UserProfile::initials()         string — 1-2 chars, mb-safe
 *   UserProfile::avatar()           ?string — web path, or null
 *   UserProfile::locale()           'fr'|'en' — user > session > app default
 *   UserProfile::theme()            'system'|'light'|'dark'
 *   UserProfile::accent()           'brand'|'teal'|'indigo'|'amber'|'rose'
 *   UserProfile::density()          'comfortable'|'compact'
 *   UserProfile::sidebarCollapsed() bool
 *   UserProfile::reduceMotion()     bool
 *   UserProfile::hasPin()           bool
 *   UserProfile::save(array $f)     array — writes, re-syncs session, returns
 *                                   the fresh profile. Throws on bad input.
 *   UserProfile::htmlAttrs()        string — data-* attributes for <html>
 *   UserProfile::jsPayload()        array — what the browser needs
 *   UserProfile::pref($k, $d)       mixed — long-tail key from user_prefs
 *   UserProfile::setPref($k, $v)    void
 *
 * DEGRADED MODE
 *   If migration 036 has not run, every getter falls back to the session/app
 *   defaults and save() throws a friendly message instead of a PDOException.
 *   A partial deploy must not white-screen the sidebar.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class UserProfile
{
    /** @var array<string,mixed>|null Resolved profile for this request. */
    private static $cache = null;

    /** @var bool|null Has migration 036 run? Probed once. */
    private static $ready = null;

    /** Allowed enum values, mirrored from migration 036 so bad input is
     *  rejected in PHP with a readable message instead of by MySQL strict
     *  mode with "Data truncated for column 'accent'". */
    public const THEMES    = ['system', 'light', 'dark'];
    public const ACCENTS   = ['brand', 'teal', 'indigo', 'amber', 'rose'];
    public const DENSITIES = ['comfortable', 'compact'];
    public const LOCALES   = ['fr', 'en'];

    // =========================================================================
    // Loading
    // =========================================================================

    private static function db(): ?PDO
    {
        try { return Database::getInstance()->getConnection(); }
        catch (Throwable $e) { error_log('UserProfile db: ' . $e->getMessage()); return null; }
    }

    /**
     * True once the migration-036 columns exist. Probed with a single
     * information_schema query, then memoised — cheaper than catching a
     * PDOException on every page render, and it lets callers show
     * "run the migration" instead of a 500.
     */
    public static function ready(): bool
    {
        if (self::$ready !== null) return self::$ready;
        $db = self::db();
        if (!$db) return self::$ready = false;
        try {
            $q = $db->query("
                SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name   = 'employee_profiles'
                   AND column_name  = 'display_name'
            ");
            return self::$ready = ((int) $q->fetchColumn() === 1);
        } catch (Throwable $e) {
            return self::$ready = false;
        }
    }

    /**
     * The resolved profile for the logged-in user. Always returns a complete
     * array — missing DB row, missing migration and logged-out all produce
     * sensible values so no template has to null-check.
     */
    public static function current(bool $fresh = false): array
    {
        if (!$fresh && self::$cache !== null) return self::$cache;

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $row    = [];

        if ($userId > 0 && self::ready()) {
            $db = self::db();
            if ($db) {
                try {
                    $st = $db->prepare("
                        SELECT u.id AS user_id, u.first_name, u.last_name, u.email,
                               u.employee_code, u.password_changed_at, u.must_reset_password,
                               r.name AS role_name,
                               ep.display_name, ep.pronouns, ep.about, ep.phone, ep.job_title,
                               ep.avatar, ep.locale, ep.timezone, ep.theme, ep.accent,
                               ep.density, ep.sidebar_collapsed, ep.reduce_motion,
                               ep.landing_page, ep.login_pin_set_at, ep.profile_updated_at,
                               (ep.login_pin_hash IS NOT NULL) AS has_pin
                          FROM users u
                     LEFT JOIN roles r              ON r.id = u.role_id
                     LEFT JOIN employee_profiles ep ON ep.user_id = u.id
                         WHERE u.id = ?
                         LIMIT 1
                    ");
                    $st->execute([$userId]);
                    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                } catch (Throwable $e) {
                    error_log('UserProfile::current: ' . $e->getMessage());
                }
            }
        }

        // ---- Resolve, layering DB over session over app default --------------
        $legalName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        if ($legalName === '') $legalName = (string) ($_SESSION['user_name'] ?? 'Utilisateur');

        $display = trim((string) ($row['display_name'] ?? ''));
        if ($display === '') $display = $legalName;

        $appLocale = class_exists('Prefs') ? Prefs::str('locale_default', 'fr') : 'fr';
        $locale    = $row['locale'] ?? null;
        if (!in_array($locale, self::LOCALES, true)) {
            $locale = $_SESSION['lang'] ?? null;
            if (!in_array($locale, self::LOCALES, true)) {
                $locale = in_array($appLocale, self::LOCALES, true) ? $appLocale : 'fr';
            }
        }

        $profile = [
            'user_id'        => $userId,
            'employee_code'  => (string) ($row['employee_code'] ?? ($_SESSION['employee_code'] ?? '')),
            'email'          => (string) ($row['email'] ?? ''),
            'legal_name'     => $legalName,
            'display_name'   => $display,
            'pronouns'       => (string) ($row['pronouns'] ?? ''),
            'about'          => (string) ($row['about'] ?? ''),
            'phone'          => (string) ($row['phone'] ?? ''),
            'job_title'      => (string) ($row['job_title'] ?? ''),
            'role_name'      => (string) ($row['role_name'] ?? ($_SESSION['user_role'] ?? '')),
            'avatar'         => self::normaliseAvatar($row['avatar'] ?? ($_SESSION['avatar'] ?? null)),
            'initials'       => self::computeInitials($display),
            'locale'         => $locale,
            'timezone'       => (string) ($row['timezone'] ?? ''),
            // 'light', not 'system' — see the note on the theme column in
            // migration 036. An unset preference must render the app exactly
            // as it rendered before this feature existed.
            'theme'          => self::pick($row['theme'] ?? null, self::THEMES, 'light'),
            'accent'         => self::pick($row['accent'] ?? null, self::ACCENTS, 'brand'),
            'density'        => self::pick($row['density'] ?? null, self::DENSITIES, 'comfortable'),
            'sidebar_collapsed' => (bool) ($row['sidebar_collapsed'] ?? false),
            'reduce_motion'  => (bool) ($row['reduce_motion'] ?? false),
            'landing_page'   => (string) ($row['landing_page'] ?? ''),
            'has_pin'        => (bool) ($row['has_pin'] ?? false),
            'pin_set_at'     => $row['login_pin_set_at'] ?? null,
            'password_changed_at' => $row['password_changed_at'] ?? null,
            'profile_updated_at'  => $row['profile_updated_at'] ?? null,
            'ready'          => self::ready(),
        ];

        return self::$cache = $profile;
    }

    private static function pick($value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? (string) $value : $default;
    }

    /**
     * Uploads::saveUploaded stores a filesystem-ish path; older rows may hold
     * a bare filename. Normalise both to a web path under /uploads/ so the
     * template can print it straight into src="".
     */
    private static function normaliseAvatar($raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        if (preg_match('#^(https?:)?//#i', $raw)) return $raw;   // absolute URL
        if ($raw[0] === '/') return $raw;                        // already web-rooted
        return '/uploads/' . ltrim($raw, '/');
    }

    /**
     * First letter of each of the first two words, uppercased.
     * mb_* throughout: "Émile Ébodé" must give "ÉÉ", not two mojibake bytes.
     */
    public static function computeInitials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];
        $a = mb_substr($parts[0] ?? '', 0, 1, 'UTF-8');
        $b = mb_substr($parts[1] ?? '', 0, 1, 'UTF-8');
        $out = mb_strtoupper($a . $b, 'UTF-8');
        return $out !== '' ? $out : '?';
    }

    // =========================================================================
    // Convenience getters — what templates actually call
    // =========================================================================

    public static function displayName(): string   { return self::current()['display_name']; }
    public static function initials(): string      { return self::current()['initials']; }
    public static function avatar(): ?string       { return self::current()['avatar']; }
    public static function locale(): string        { return self::current()['locale']; }
    public static function theme(): string         { return self::current()['theme']; }
    public static function accent(): string        { return self::current()['accent']; }
    public static function density(): string       { return self::current()['density']; }
    public static function sidebarCollapsed(): bool{ return self::current()['sidebar_collapsed']; }
    public static function reduceMotion(): bool    { return self::current()['reduce_motion']; }
    public static function hasPin(): bool          { return self::current()['has_pin']; }
    public static function roleLabel(): string
    {
        $p = self::current();
        return $p['job_title'] !== '' ? $p['job_title'] : ucfirst($p['role_name']);
    }

    // =========================================================================
    // Writing
    // =========================================================================

    /**
     * Persist a partial set of fields for the current user.
     *
     * Only whitelisted keys are considered — a caller cannot smuggle
     * `login_pin_hash` or `user_id` through $_POST, because they are not in
     * the map. Enum fields are validated in PHP so the user gets
     * "Thème inconnu" rather than a MySQL truncation error.
     *
     * @param array<string,mixed> $fields
     * @return array The freshly reloaded profile.
     * @throws RuntimeException on validation failure or missing migration.
     */
    public static function save(array $fields): array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) throw new RuntimeException('Session invalide.');
        if (!self::ready()) {
            throw new RuntimeException("Le profil utilisateur n'est pas encore disponible (migration 036 en attente).");
        }

        $set = [];

        if (array_key_exists('display_name', $fields)) {
            $v = trim((string) $fields['display_name']);
            // Empty is legal and meaningful: it means "go back to my HR name".
            if ($v !== '') {
                if (mb_strlen($v, 'UTF-8') < 2)   throw new RuntimeException('Le nom affiché est trop court.');
                if (mb_strlen($v, 'UTF-8') > 120) throw new RuntimeException('Le nom affiché est trop long (120 max).');
                // Strip control chars; a name with a newline breaks every table layout.
                $v = preg_replace('/[\p{C}]+/u', '', $v);
            }
            $set['display_name'] = ($v === '') ? null : $v;
        }

        if (array_key_exists('pronouns', $fields)) {
            $set['pronouns'] = self::trimOrNull($fields['pronouns'], 40, 'Pronoms');
        }
        if (array_key_exists('about', $fields)) {
            $set['about'] = self::trimOrNull($fields['about'], 280, 'Bio');
        }
        if (array_key_exists('phone', $fields)) {
            $v = trim((string) $fields['phone']);
            if ($v !== '' && !preg_match('/^[0-9+()\s.-]{6,40}$/', $v)) {
                throw new RuntimeException('Numéro de téléphone invalide.');
            }
            $set['phone'] = ($v === '') ? null : $v;
        }

        if (array_key_exists('locale', $fields)) {
            $v = (string) $fields['locale'];
            if (!in_array($v, self::LOCALES, true)) throw new RuntimeException('Langue inconnue.');
            $set['locale'] = $v;
        }
        if (array_key_exists('timezone', $fields)) {
            $v = trim((string) $fields['timezone']);
            if ($v !== '' && !in_array($v, timezone_identifiers_list(), true)) {
                throw new RuntimeException('Fuseau horaire inconnu.');
            }
            $set['timezone'] = ($v === '') ? null : $v;
        }
        if (array_key_exists('theme', $fields)) {
            $v = (string) $fields['theme'];
            if (!in_array($v, self::THEMES, true)) throw new RuntimeException('Thème inconnu.');
            $set['theme'] = $v;
        }
        if (array_key_exists('accent', $fields)) {
            $v = (string) $fields['accent'];
            if (!in_array($v, self::ACCENTS, true)) throw new RuntimeException('Couleur d\'accent inconnue.');
            $set['accent'] = $v;
        }
        if (array_key_exists('density', $fields)) {
            $v = (string) $fields['density'];
            if (!in_array($v, self::DENSITIES, true)) throw new RuntimeException('Densité inconnue.');
            $set['density'] = $v;
        }
        if (array_key_exists('sidebar_collapsed', $fields)) {
            $set['sidebar_collapsed'] = self::truthy($fields['sidebar_collapsed']) ? 1 : 0;
        }
        if (array_key_exists('reduce_motion', $fields)) {
            $set['reduce_motion'] = self::truthy($fields['reduce_motion']) ? 1 : 0;
        }
        if (array_key_exists('landing_page', $fields)) {
            $set['landing_page'] = self::validateLanding((string) $fields['landing_page']);
        }
        if (array_key_exists('avatar', $fields)) {
            // Only ever set by profile_controller after Uploads::saveUploaded
            // has vetted the bytes. Never reachable from raw $_POST because the
            // controller builds this array itself for the upload path.
            $set['avatar'] = $fields['avatar'] === null ? null : (string) $fields['avatar'];
        }

        if (!$set) return self::current(true);

        $db = self::db();
        if (!$db) throw new RuntimeException('Base de données indisponible.');

        self::upsert($db, $userId, $set);

        // ---- Re-sync the session mirrors -----------------------------------
        // Everything that reads $_SESSION['user_name'] / ['avatar'] / ['lang']
        // now sees the change on the very next request, without a re-login.
        self::$cache = null;
        $fresh = self::current(true);
        $_SESSION['user_name'] = $fresh['display_name'];
        $_SESSION['avatar']    = $fresh['avatar'];
        if (function_exists('lpc_i18n_set_lang')) {
            lpc_i18n_set_lang($fresh['locale']);
        } else {
            $_SESSION['lang'] = $fresh['locale'];
        }

        return $fresh;
    }

    /**
     * Write columns onto the user's employee_profiles row, creating it only if
     * it genuinely does not exist.
     *
     * WHY NOT `INSERT ... ON DUPLICATE KEY UPDATE`
     * -------------------------------------------
     * That was the first implementation and it was wrong. MySQL validates the
     * INSERT row BEFORE it discovers the duplicate key, so under the strict
     * mode this install runs, an upsert that supplies only `user_id` and
     * `display_name` fails with
     *
     *     SQLSTATE[HY000]: 1364 Field 'base_salary' doesn't have a default value
     *
     * — even though the row already exists and the UPDATE branch would never
     * have touched base_salary. employee_profiles carries HR columns
     * (base_salary, hire_date) that are NOT NULL without defaults, and a
     * self-service profile edit has no business inventing values for them.
     *
     * So: UPDATE first, which only ever names the columns being changed and
     * therefore cannot trip on an HR column. Only when no row exists do we
     * INSERT, and that path asks information_schema which columns actually
     * require a value on this install and fills each with a type-appropriate
     * zero rather than hardcoding a guess about the schema.
     */
    private static function upsert(PDO $db, int $userId, array $set): void
    {
        $cols = array_keys($set);

        // ---- 1. The overwhelmingly common path: the row exists. -------------
        $assign = implode(', ', array_map(static fn($c) => "`$c` = :$c", $cols));
        $st = $db->prepare("
            UPDATE employee_profiles
               SET $assign, profile_updated_at = NOW()
             WHERE user_id = :user_id
        ");
        $params = ['user_id' => $userId];
        foreach ($set as $k => $v) $params[$k] = $v;
        $st->execute($params);

        // rowCount() is 0 both when the row is missing AND when the values were
        // already identical, so it cannot be used to decide. Ask directly.
        $exists = $db->prepare("SELECT 1 FROM employee_profiles WHERE user_id = ? LIMIT 1");
        $exists->execute([$userId]);
        if ($exists->fetchColumn()) return;

        // ---- 2. No row. Build an INSERT that satisfies this install's schema.
        $insert = $set;
        foreach (self::requiredColumns($db) as $col => $type) {
            if ($col === 'user_id' || array_key_exists($col, $insert)) continue;
            $insert[$col] = self::zeroFor($type);
        }
        $insert['user_id'] = $userId;

        $names  = array_keys($insert);
        $fields = '`' . implode('`, `', $names) . '`, profile_updated_at';
        $holes  = implode(', ', array_map(static fn($c) => ':' . $c, $names)) . ', NOW()';

        $db->prepare("INSERT INTO employee_profiles ($fields) VALUES ($holes)")
           ->execute($insert);
    }

    /**
     * Columns on employee_profiles that are NOT NULL, have no default, and are
     * not auto-generated — i.e. the ones an INSERT is obliged to name.
     *
     * @return array<string,string> column => DATA_TYPE
     */
    private static function requiredColumns(PDO $db): array
    {
        static $memo = null;
        if ($memo !== null) return $memo;
        $memo = [];
        try {
            $st = $db->query("
                SELECT COLUMN_NAME, DATA_TYPE
                  FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME   = 'employee_profiles'
                   AND IS_NULLABLE  = 'NO'
                   AND COLUMN_DEFAULT IS NULL
                   AND EXTRA NOT LIKE '%auto_increment%'
                   AND EXTRA NOT LIKE '%GENERATED%'
            ");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $memo[$r['COLUMN_NAME']] = strtolower($r['DATA_TYPE']);
            }
        } catch (Throwable $e) {
            error_log('UserProfile::requiredColumns: ' . $e->getMessage());
        }
        return $memo;
    }

    /**
     * A harmless placeholder for a required column this class knows nothing
     * about. Deliberately a zero/empty value and never a plausible-looking one:
     * a salary that reads 0 is obviously unset, whereas a salary invented as
     * 150000 would quietly become payroll input.
     */
    private static function zeroFor(string $type)
    {
        switch ($type) {
            case 'int': case 'bigint': case 'smallint': case 'tinyint': case 'mediumint':
            case 'decimal': case 'float': case 'double':
                return 0;
            case 'date':      return '1970-01-01';
            case 'datetime':  case 'timestamp': return '1970-01-01 00:00:01';
            case 'time':      return '00:00:00';
            case 'year':      return 1970;
            default:          return '';
        }
    }

    /**
     * Store (or clear, with null) the quick-login PIN hash.
     *
     * Lives here rather than in the controller purely so it shares
     * upsert()'s strict-mode-safe write path. The hashing itself stays in the
     * controller, next to the password verification that gates it — this class
     * never sees a plaintext PIN.
     */
    public static function setPinHash(?string $hash): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) throw new RuntimeException('Session invalide.');
        $db = self::db();
        if (!$db) throw new RuntimeException('Base de données indisponible.');

        self::upsert($db, $userId, [
            'login_pin_hash'   => $hash,
            'login_pin_set_at' => $hash === null ? null : date('Y-m-d H:i:s'),
        ]);
        self::$cache = null;
    }

    private static function trimOrNull($raw, int $max, string $label): ?string
    {
        $v = trim((string) $raw);
        if ($v === '') return null;
        if (mb_strlen($v, 'UTF-8') > $max) throw new RuntimeException("$label : $max caractères maximum.");
        return preg_replace('/[\p{C}]+/u', ' ', $v);
    }

    private static function truthy($v): bool
    {
        return in_array($v, [1, '1', true, 'true', 'on', 'yes'], true);
    }

    /**
     * A landing page must be somewhere the user can actually reach, or they
     * would lock themselves into a permanent 403 on every login. Validated
     * against the same nav resolver the sidebar uses, so the allow-list can
     * never drift from the menu.
     */
    private static function validateLanding(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') return null;
        if (!function_exists('lpc_nav_sections')) return null;

        $allowed = [];
        foreach (lpc_nav_sections(self::locale()) as $section) {
            foreach ($section['items'] as $item) {
                $allowed[] = strtok($item['href'], '#');
            }
        }
        if (!in_array($path, $allowed, true)) {
            throw new RuntimeException("Cette page ne peut pas être votre page d'accueil.");
        }
        return $path;
    }

    // =========================================================================
    // The long tail — user_prefs
    // =========================================================================

    /** @var array<string,string>|null */
    private static $prefs = null;

    private static function loadPrefs(): array
    {
        if (self::$prefs !== null) return self::$prefs;
        self::$prefs = [];
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) return self::$prefs;
        $db = self::db();
        if (!$db) return self::$prefs;
        try {
            $st = $db->prepare("SELECT pref_key, pref_value FROM user_prefs WHERE user_id = ?");
            $st->execute([$userId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                self::$prefs[$r['pref_key']] = $r['pref_value'];
            }
        } catch (Throwable $e) {
            // Table absent (migration pending) — every caller gets its default.
        }
        return self::$prefs;
    }

    /** @return mixed */
    public static function pref(string $key, $default = null)
    {
        $all = self::loadPrefs();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function setPref(string $key, $value): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0 || $key === '') return;
        $db = self::db();
        if (!$db) return;
        try {
            $db->prepare("
                INSERT INTO user_prefs (user_id, pref_key, pref_value)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE pref_value = VALUES(pref_value)
            ")->execute([$userId, $key, is_scalar($value) ? (string) $value : json_encode($value)]);
            self::$prefs[$key] = is_scalar($value) ? (string) $value : json_encode($value);
        } catch (Throwable $e) {
            error_log('UserProfile::setPref: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Handing state to the browser
    // =========================================================================

    /**
     * Attributes for the <html> element. Printed server-side so the correct
     * theme/accent/density is present in the very first byte of markup — no
     * flash of the wrong surface while a script runs.
     *
     * `theme=system` is resolved in the browser (matchMedia), so it is emitted
     * as-is and lpc-usermenu.js swaps in the concrete value on load.
     */
    public static function htmlAttrs(): string
    {
        $p = self::current();
        $bits = [
            'lang="' . htmlspecialchars($p['locale'], ENT_QUOTES) . '"',
            'data-lpc-theme="' . htmlspecialchars($p['theme'], ENT_QUOTES) . '"',
            'data-lpc-accent="' . htmlspecialchars($p['accent'], ENT_QUOTES) . '"',
            'data-lpc-density="' . htmlspecialchars($p['density'], ENT_QUOTES) . '"',
        ];
        if ($p['reduce_motion']) $bits[] = 'data-lpc-reduce-motion="1"';
        return implode(' ', $bits);
    }

    /**
     * What lpc-usermenu.js needs. This payload is inlined into HTML and ends up
     * in the DOM, so it carries no secrets: no password material, and no pin
     * metadata beyond a boolean.
     *
     * `employeeCode` IS included, and that is deliberate. It used to be the
     * sign-in identifier, which is why an older version of this comment called
     * it sensitive and claimed it was excluded — the claim was already false
     * when it was written, since the key below has always shipped. Since
     * migration 105 the matricule is a read-only payroll reference that people
     * are expected to quote, and the account panel displays it as such. The
     * credential is the email address plus a password, and no password field
     * is exposed here.
     */
    public static function jsPayload(): array
    {
        $p = self::current();
        return [
            'displayName' => $p['display_name'],
            'legalName'   => $p['legal_name'],
            'initials'    => $p['initials'],
            'avatar'      => $p['avatar'],
            'roleLabel'   => self::roleLabel(),
            'email'       => $p['email'],
            'phone'       => $p['phone'],
            'pronouns'    => $p['pronouns'],
            'about'       => $p['about'],
            'jobTitle'    => $p['job_title'],
            'employeeCode'=> $p['employee_code'],
            'locale'      => $p['locale'],
            'timezone'    => $p['timezone'],
            'theme'       => $p['theme'],
            'accent'      => $p['accent'],
            'density'     => $p['density'],
            'reduceMotion'=> $p['reduce_motion'],
            'landingPage' => $p['landing_page'],
            'hasPin'      => $p['has_pin'],
            'ready'       => $p['ready'],
        ];
    }
}
