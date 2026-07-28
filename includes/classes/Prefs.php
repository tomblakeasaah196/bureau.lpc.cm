<?php
/**
 * includes/classes/Prefs.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 8 · typed application preferences.
 *
 * Replaces the magic numbers that were scattered through the codebase:
 *   'FAC-' . date('ym')      api/v1/invoices_controller.php:384
 *   'DEV-' . date('ym')      api/v1/create_proposal.php:46
 *   'BL-'  . date('ym')      api/v1/sales_controller.php:459
 *   const timeoutLength = 1800000   includes/components/driver_sidebar.php:70
 *   env('AUTH_MAX_ATTEMPTS_PER_15MIN', 10)   api/v1/auth.php:58
 *   0.1925 / 0.022 / 0.33    includes/classes/TaxEngine.php:58
 *
 * USAGE
 *   Prefs::get('doc_prefix_invoice')          -> 'FAC'   (cast by `kind`)
 *   Prefs::int('sec_session_timeout_min', 30) -> 30
 *   Prefs::bool('tax_tva_liable')             -> true
 *   Prefs::rate('tax_tva_rate')               -> 0.1925  (percent -> decimal)
 *   Prefs::docNumber('invoice', 42)           -> 'FAC-2607-0042'
 *   Prefs::grouped()                          -> for rendering the settings form
 *   Prefs::setMany([...], $userId)
 *
 * The whole table is loaded once per request in a single query — it is a few
 * dozen rows, and this avoids N queries scattered across a page render.
 *
 * DEGRADED MODE: if migration 034 has not run, every getter returns the caller's
 * default. Nothing throws.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class Prefs
{
    /** @var array<string,array<string,mixed>>|null pref_key => full row */
    private static $rows = null;

    /** Hard fallbacks, used when the table is unavailable. Mirrors the seeds. */
    private const FALLBACKS = [
        'doc_prefix_invoice'          => 'FAC',
        'doc_prefix_quote'            => 'DEV',
        'doc_prefix_delivery'         => 'BL',
        'doc_prefix_order'            => 'CMD',
        'doc_prefix_purchase'         => 'BC',
        'doc_prefix_credit'           => 'AV',
        'doc_number_format'           => '{PREFIX}-{YYMM}-{SEQ}',
        'doc_sequence_padding'        => '4',
        'doc_sequence_reset'          => 'yearly',
        'default_payment_terms_days'  => '30',
        'default_quote_validity_days' => '15',
        'default_currency'            => 'XAF',
        'tax_tva_rate'                => '19.25',
        'tax_air_rate'                => '2.2',
        'tax_cit_rate'                => '33',
        'tax_tva_liable'              => '1',
        'tax_cnps_group'              => 'A',
        'tax_filing_day'              => '15',
        'fiscal_year_start_month'     => '1',
        'locale_default'              => 'fr',
        'locale_timezone'             => 'Africa/Douala',
        'locale_date_format'          => 'd/m/Y',
        'locale_decimal_sep'          => ',',
        'locale_thousand_sep'         => ' ',
        'ui_page_size'                => '25',
        'sec_session_timeout_min'     => '30',
        'sec_max_login_attempts'      => '10',
        'sec_lockout_minutes'         => '15',
        'sec_password_min_length'     => '10',
        'sec_password_expiry_days'    => '0',
        'sec_require_2fa_admin'       => '0',
        'sec_audit_retention_days'    => '365',
        'notify_low_stock_threshold'  => '10',
        'notify_invoice_due_days'     => '3',
        'notify_email_enabled'        => '1',
        'ops_allow_negative_stock'    => '0',
    ];

    /** Human labels for the section headings in Settings → Préférences. */
    public const GROUP_LABELS = [
        'numbering'  => ['fr' => 'Numérotation des documents', 'en' => 'Document numbering', 'icon' => 'fa-hashtag'],
        'commercial' => ['fr' => 'Défauts commerciaux',        'en' => 'Commercial defaults', 'icon' => 'fa-file-invoice-dollar'],
        'fiscal'     => ['fr' => 'Fiscalité & taux',           'en' => 'Tax & rates',         'icon' => 'fa-percent'],
        'display'    => ['fr' => 'Localisation & affichage',   'en' => 'Locale & display',    'icon' => 'fa-globe'],
        'security'   => ['fr' => 'Politique de sécurité',      'en' => 'Security policy',     'icon' => 'fa-shield-halved'],
        'operations' => ['fr' => 'Opérations & alertes',       'en' => 'Operations & alerts', 'icon' => 'fa-bell'],
        'general'    => ['fr' => 'Général',                    'en' => 'General',             'icon' => 'fa-sliders'],
    ];

    // -------------------------------------------------------------------------
    // Loading
    // -------------------------------------------------------------------------

    /** @return array<string,array<string,mixed>> */
    private static function load(): array
    {
        if (self::$rows !== null) {
            return self::$rows;
        }

        self::$rows = [];
        try {
            $db   = Database::getInstance()->getConnection();
            $stmt = $db->query(
                "SELECT pref_key, pref_value, kind, options_json, group_key,
                        label_fr, label_en, help_fr, unit, min_value, max_value,
                        sort_order, is_locked
                   FROM app_preferences
                  WHERE is_active = 1
                  ORDER BY group_key ASC, sort_order ASC"
            );
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                self::$rows[$r['pref_key']] = $r;
            }
        } catch (Throwable $e) {
            error_log('Prefs::load() — table unavailable, using fallbacks: ' . $e->getMessage());
        }

        return self::$rows;
    }

    /** Drop the per-request cache (call after a write). */
    public static function flush(): void
    {
        self::$rows = null;
    }

    // -------------------------------------------------------------------------
    // Typed getters
    // -------------------------------------------------------------------------

    /**
     * The raw stored string for a key, resolved in strict precedence order:
     *
     *   1. the row in app_preferences        (what the admin configured)
     *   2. self::FALLBACKS                   (the seeded default from mig. 034)
     *   3. null                              (caller's default then applies)
     *
     * Getting this order wrong is subtle and expensive: an earlier revision had
     * the typed getters pass their own `$default` down into get(), where a
     * non-null default short-circuited step 2. Because str()'s default is '',
     * EVERY string preference resolved to '' whenever the table was
     * unreachable — which silently turned invoice references from
     * "FAC-2607-0042" into "-2607-0042". Hence the split: FALLBACKS is
     * consulted before the caller's default, never after.
     */
    private static function raw(string $key): ?string
    {
        $rows = self::load();

        if (isset($rows[$key])) {
            $v = $rows[$key]['pref_value'];
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
        }

        return self::FALLBACKS[$key] ?? null;
    }

    /**
     * Value cast according to its declared `kind`.
     *
     * @param  mixed $default returned only when the key is unknown everywhere
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $raw = self::raw($key);
        if ($raw === null) {
            return $default;
        }

        $rows = self::load();
        $kind = $rows[$key]['kind'] ?? 'text';

        switch ($kind) {
            case 'int':
                return (int) $raw;
            case 'decimal':
                return (float) $raw;
            case 'bool':
                return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
            default:
                return $raw;
        }
    }

    public static function str(string $key, string $default = ''): string
    {
        $raw = self::raw($key);
        return ($raw === null) ? $default : $raw;
    }

    public static function int(string $key, int $default = 0): int
    {
        $raw = self::raw($key);
        return ($raw !== null && is_numeric($raw)) ? (int) $raw : $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        $raw = self::raw($key);
        return ($raw !== null && is_numeric($raw)) ? (float) $raw : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $raw = self::raw($key);
        return ($raw === null)
            ? $default
            : in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * A percentage preference as a decimal multiplier.
     * Prefs::rate('tax_tva_rate') -> 0.1925 for a stored value of "19.25".
     */
    public static function rate(string $key, float $defaultPercent = 0.0): float
    {
        return self::float($key, $defaultPercent) / 100;
    }

    // -------------------------------------------------------------------------
    // Document numbering
    // -------------------------------------------------------------------------

    /**
     * Build a document reference from the configured prefix + format.
     *
     *   Prefs::docNumber('invoice', 42)  ->  'FAC-2607-0042'
     *
     * @param string      $docType  invoice|quote|delivery|order|purchase|credit
     * @param int|string  $sequence numeric counter, or a pre-computed hash
     * @param string|null $date     Y-m-d, defaults to today
     */
    public static function docNumber(string $docType, $sequence, ?string $date = null): string
    {
        $prefix = self::str('doc_prefix_' . $docType, strtoupper(substr($docType, 0, 3)));
        $format = self::str('doc_number_format', '{PREFIX}-{YYMM}-{SEQ}');
        $ts     = $date ? strtotime($date) : time();

        // Numeric sequences get zero-padded; anything else (a random hash from
        // the existing controllers) passes through untouched.
        $seq = is_numeric($sequence)
            ? str_pad((string) (int) $sequence, self::int('doc_sequence_padding', 4), '0', STR_PAD_LEFT)
            : (string) $sequence;

        return strtr($format, [
            '{PREFIX}' => $prefix,
            '{YYMM}'   => date('ym', $ts),
            '{YYYY}'   => date('Y', $ts),
            '{YY}'     => date('y', $ts),
            '{MM}'     => date('m', $ts),
            '{DD}'     => date('d', $ts),
            '{SEQ}'    => $seq,
        ]);
    }

    // -------------------------------------------------------------------------
    // Formatting helpers driven by the display preferences
    // -------------------------------------------------------------------------

    /** Format a number using the configured separators. */
    public static function number(float $n, int $decimals = 0): string
    {
        return number_format(
            $n,
            $decimals,
            self::str('locale_decimal_sep', ','),
            self::str('locale_thousand_sep', ' ')
        );
    }

    /** Format a date using the configured format. Accepts anything strtotime reads. */
    public static function date(?string $value): string
    {
        if (!$value) {
            return '';
        }
        $ts = strtotime($value);
        return $ts ? date(self::str('locale_date_format', 'd/m/Y'), $ts) : (string) $value;
    }

    /** Session timeout in milliseconds — what the front-end auto-logout wants. */
    public static function sessionTimeoutMs(): int
    {
        return max(1, self::int('sec_session_timeout_min', 30)) * 60 * 1000;
    }

    // -------------------------------------------------------------------------
    // Reading for the settings UI
    // -------------------------------------------------------------------------

    /**
     * All preferences, grouped and decorated for form rendering.
     *
     * @return array<int,array<string,mixed>> [{key,label,icon,items:[...]}, ...]
     */
    public static function grouped(string $lang = 'fr'): array
    {
        $rows = self::load();
        $out  = [];

        foreach ($rows as $key => $r) {
            $g = $r['group_key'] ?: 'general';

            if (!isset($out[$g])) {
                $meta     = self::GROUP_LABELS[$g] ?? ['fr' => ucfirst($g), 'en' => ucfirst($g), 'icon' => 'fa-sliders'];
                $out[$g]  = [
                    'key'   => $g,
                    'label' => $meta[$lang] ?? $meta['fr'],
                    'icon'  => $meta['icon'],
                    'items' => [],
                ];
            }

            $out[$g]['items'][] = [
                'key'       => $key,
                'value'     => $r['pref_value'],
                'kind'      => $r['kind'],
                'options'   => $r['options_json'] ? json_decode($r['options_json'], true) : null,
                'label'     => $lang === 'en' && $r['label_en'] ? $r['label_en'] : $r['label_fr'],
                'help'      => $r['help_fr'],
                'unit'      => $r['unit'],
                'min'       => $r['min_value'] !== null ? (float) $r['min_value'] : null,
                'max'       => $r['max_value'] !== null ? (float) $r['max_value'] : null,
                'is_locked' => (int) $r['is_locked'] === 1,
            ];
        }

        // Preserve the intended section order rather than insertion order.
        $order  = array_keys(self::GROUP_LABELS);
        $sorted = [];
        foreach ($order as $g) {
            if (isset($out[$g])) {
                $sorted[] = $out[$g];
                unset($out[$g]);
            }
        }
        return array_merge($sorted, array_values($out));
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * Write several preferences at once, inside a transaction.
     * Unknown keys are ignored; locked keys are refused.
     *
     * @param  array<string,mixed> $pairs
     * @return int number of preferences actually changed
     * @throws RuntimeException on validation failure
     */
    public static function setMany(array $pairs, ?int $userId = null): int
    {
        $rows = self::load();
        if (!$rows) {
            throw new RuntimeException("Table des préférences indisponible. La migration 034 a-t-elle été appliquée ?");
        }

        $db      = Database::getInstance()->getConnection();
        $changed = 0;
        $owns    = !$db->inTransaction();

        if ($owns) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare(
                "UPDATE app_preferences SET pref_value = ?, updated_by = ? WHERE pref_key = ? AND is_locked = 0"
            );

            foreach ($pairs as $key => $val) {
                if (!isset($rows[$key])) {
                    continue;                       // not a known preference
                }
                if ((int) $rows[$key]['is_locked'] === 1) {
                    continue;                       // locked — silently skip
                }

                $norm = self::normalise($rows[$key], $val);

                if ((string) $norm === (string) $rows[$key]['pref_value']) {
                    continue;                       // no-op, don't touch updated_at
                }

                $stmt->execute([$norm, $userId, $key]);
                $changed += $stmt->rowCount();
            }

            if ($owns) {
                $db->commit();
            }
        } catch (Throwable $e) {
            if ($owns && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        self::flush();
        self::syncTaxSettings();

        return $changed;
    }

    /**
     * Validate + coerce one incoming value against its declared kind/bounds.
     *
     * @param  array<string,mixed> $row
     * @param  mixed               $val
     * @throws RuntimeException
     */
    private static function normalise(array $row, $val): string
    {
        $label = $row['label_fr'] ?: $row['pref_key'];

        switch ($row['kind']) {
            case 'bool':
                return in_array(strtolower((string) $val), ['1', 'true', 'yes', 'on'], true) ? '1' : '0';

            case 'int':
            case 'decimal':
                if (!is_numeric($val)) {
                    throw new RuntimeException("« {$label} » doit être un nombre.");
                }
                $n = $row['kind'] === 'int' ? (int) $val : (float) $val;

                if ($row['min_value'] !== null && $n < (float) $row['min_value']) {
                    throw new RuntimeException("« {$label} » ne peut pas être inférieur à {$row['min_value']}.");
                }
                if ($row['max_value'] !== null && $n > (float) $row['max_value']) {
                    throw new RuntimeException("« {$label} » ne peut pas dépasser {$row['max_value']}.");
                }
                return (string) $n;

            case 'select':
                $opts = $row['options_json'] ? json_decode($row['options_json'], true) : [];
                $allowed = array_column(is_array($opts) ? $opts : [], 'value');
                if ($allowed && !in_array((string) $val, $allowed, true)) {
                    throw new RuntimeException("Valeur non autorisée pour « {$label} ».");
                }
                return (string) $val;

            case 'color':
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $val)) {
                    throw new RuntimeException("« {$label} » doit être une couleur au format #RRGGBB.");
                }
                return (string) $val;

            case 'date':
                if ((string) $val !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $val)) {
                    throw new RuntimeException("« {$label} » doit être une date valide.");
                }
                return (string) $val;

            case 'text':
                // Document prefixes end up in filenames and references — keep
                // them to characters that are safe there.
                if (strpos($row['pref_key'], 'doc_prefix_') === 0
                    && !preg_match('/^[A-Za-z0-9]{1,8}$/', (string) $val)) {
                    throw new RuntimeException("« {$label} » : 1 à 8 caractères alphanumériques, sans espace ni accent.");
                }
                return (string) $val;

            default:
                return (string) $val;
        }
    }

    /**
     * Push the fiscal preferences back into company_tax_settings so TaxEngine
     * and the tax declarations page see the same numbers. Percentages are
     * stored here, decimals there.
     */
    private static function syncTaxSettings(): void
    {
        try {
            $db  = Database::getInstance()->getConnection();
            $tid = $db->query("SELECT id FROM company_tax_settings ORDER BY id ASC LIMIT 1")->fetchColumn();
            if (!$tid) {
                return;
            }

            $db->prepare(
                "UPDATE company_tax_settings
                    SET tva_rate = ?, air_rate = ?, cit_rate = ?, tva_liable = ?,
                        cnps_group = ?, is_withholding_agent = ?, filing_day = ?,
                        fiscal_year_start_month = ?
                  WHERE id = ?"
            )->execute([
                self::rate('tax_tva_rate', 19.25),
                self::rate('tax_air_rate', 2.2),
                self::rate('tax_cit_rate', 33),
                self::bool('tax_tva_liable', true) ? 1 : 0,
                self::str('tax_cnps_group', 'A'),
                self::bool('tax_is_withholding_agent', false) ? 1 : 0,
                self::int('tax_filing_day', 15),
                self::int('fiscal_year_start_month', 1),
                $tid,
            ]);
        } catch (Throwable $e) {
            error_log('Prefs: tax-settings sync failed — ' . $e->getMessage());
        }
    }
}
