<?php
/**
 * includes/classes/CompanyProfile.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — Sprint 8 · the company's own identity, in one place.
 *
 * Before migration 034 the answer to "what is this company called?" depended on
 * which file you asked. The PDF letterhead said "Ets. La Petite Cour", the tax
 * engine said "Bureau LPC SARL", and the proposal studio said "La Petite Cour".
 * They also disagreed on the NIU. This class is the single reader.
 *
 * USAGE
 *   CompanyProfile::get()                 -> full row as an array
 *   CompanyProfile::field('legal_name')   -> one field, with a safe default
 *   CompanyProfile::displayName()         -> "La Petite Cour ETS" for documents
 *   CompanyProfile::letterhead()          -> pre-formatted block for PDF headers
 *   CompanyProfile::addressBlock()        -> multi-line postal address
 *   CompanyProfile::legalMentions()       -> the RCCM/NIU/capital footer line
 *   CompanyProfile::logo('main'|'mark'|'document')
 *
 * Everything is cached per request. A save() invalidates the cache.
 *
 * DEGRADED MODE: if migration 034 has not been run yet, get() returns a
 * defaults array rather than throwing, so no page white-screens mid-deploy.
 * -----------------------------------------------------------------------------
 */

if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    die('Direct access not permitted.');
}

final class CompanyProfile
{
    /** @var array<string,mixed>|null Per-request cache. */
    private static $cache = null;

    /** Columns the UI is allowed to write. Anything else in POST is ignored. */
    public const EDITABLE = [
        // Legal identity
        'legal_name', 'trade_name', 'legal_form', 'rccm_number', 'niu',
        'trade_register_city', 'incorporation_date', 'share_capital',
        // Fiscal
        'fiscal_regime', 'tax_office', 'cnps_employer_number',
        'activity_sector', 'activity_code',
        // Address
        'address_line1', 'address_line2', 'po_box', 'city', 'region',
        'country', 'country_code',
        // Contact
        'phone_primary', 'phone_secondary', 'whatsapp',
        'email_general', 'email_billing', 'website',
        // Banking
        'bank_name', 'bank_account_rib', 'bank_iban', 'bank_swift',
        'mobile_money_number',
        // Brand
        'erp_display_name', 'erp_tagline',
        'logo_main_path', 'logo_mark_path', 'logo_document_path',
        'brand_color_primary', 'brand_color_accent',
        // Footer
        'document_footer_fr', 'document_footer_en',
    ];

    /** Legal forms recognised in the OHADA / Cameroon context. */
    public const LEGAL_FORMS = [
        'ETS'   => 'Établissement (Ets.)',
        'SARL'  => 'Société à Responsabilité Limitée (SARL)',
        'SARLU' => 'SARL Unipersonnelle (SARLU)',
        'SA'    => 'Société Anonyme (SA)',
        'SAS'   => 'Société par Actions Simplifiée (SAS)',
        'SNC'   => 'Société en Nom Collectif (SNC)',
        'GIE'   => "Groupement d'Intérêt Économique (GIE)",
        'SCI'   => 'Société Civile Immobilière (SCI)',
        'EI'    => 'Entreprise Individuelle (EI)',
        'ONG'   => 'Organisation Non Gouvernementale (ONG)',
        'AUTRE' => 'Autre',
    ];

    public const FISCAL_REGIMES = [
        'reel'              => 'Régime du Réel',
        'simplifie'         => 'Régime Simplifié',
        'forfait'           => 'Régime du Forfait',
        'non_professionnel' => 'Non Professionnel',
    ];

    /**
     * The active company row. Returns defaults (never null) so callers can
     * always index into it.
     *
     * @return array<string,mixed>
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        try {
            $db  = Database::getInstance()->getConnection();
            $row = $db->query(
                "SELECT * FROM company_profile WHERE is_active = 1 ORDER BY id ASC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // Table missing (migration 034 not yet applied) or DB hiccup.
            // Log once and fall through to defaults — a broken letterhead is
            // better than a fatal on every page of the app.
            error_log('CompanyProfile::get() — falling back to defaults: ' . $e->getMessage());
            $row = false;
        }

        self::$cache = $row ?: self::defaults();
        return self::$cache;
    }

    /** Drop the per-request cache (call after a write). */
    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * One field with a fallback.
     *
     * @param  string $key
     * @param  mixed  $default
     * @return mixed
     */
    public static function field(string $key, $default = '')
    {
        $p = self::get();
        $v = $p[$key] ?? null;
        return ($v === null || $v === '') ? $default : $v;
    }

    /**
     * The name to print on a document: trade name if one is set, otherwise the
     * registered name, suffixed with the legal form.
     *
     * "La Petite Cour ETS" · "Bureau LPC SARL"
     */
    public static function displayName(): string
    {
        $p    = self::get();
        $name = trim((string)($p['trade_name'] ?: $p['legal_name']));
        $form = (string)($p['legal_form'] ?? '');

        // Don't duplicate the form if the name already ends with it
        // ("Ets. La Petite Cour ETS" reads badly).
        if ($form !== '' && $form !== 'AUTRE'
            && stripos($name, $form) === false) {
            $name .= ' ' . $form;
        }
        return $name;
    }

    /** The registered name exactly as filed, for contracts and tax forms. */
    public static function legalName(): string
    {
        return (string) self::field('legal_name', 'Entreprise');
    }

    /**
     * Postal address as an array of non-empty lines. Caller joins with <br> or
     * "\n" as appropriate.
     *
     * @return string[]
     */
    public static function addressLines(): array
    {
        $p = self::get();

        $cityLine = trim(implode(' ', array_filter([
            (string)($p['city'] ?? ''),
            (string)($p['region'] ?? ''),
        ])));

        $lines = [
            (string)($p['address_line1'] ?? ''),
            (string)($p['address_line2'] ?? ''),
            (string)($p['po_box'] ?? ''),
            $cityLine,
            (string)($p['country'] ?? ''),
        ];

        return array_values(array_filter(array_map('trim', $lines), static function ($l) {
            return $l !== '';
        }));
    }

    /** Single-line address, comma separated. */
    public static function addressBlock(string $sep = ', '): string
    {
        return implode($sep, self::addressLines());
    }

    /** Contact line: "Tél. +237 696 291 800 · info@lpc.cm · www.lpc.cm" */
    public static function contactLine(): string
    {
        $p     = self::get();
        $parts = [];

        $phones = array_filter([
            (string)($p['phone_primary'] ?? ''),
            (string)($p['phone_secondary'] ?? ''),
        ]);
        if ($phones) {
            $parts[] = 'Tél. ' . implode(' / ', $phones);
        }
        if (!empty($p['email_general'])) {
            $parts[] = (string) $p['email_general'];
        }
        if (!empty($p['website'])) {
            $parts[] = (string) $p['website'];
        }

        return implode(' · ', $parts);
    }

    /**
     * The statutory identifiers line. On a Cameroonian invoice the RCCM and the
     * NIU are mandatory; share capital is mandatory for SARL/SA/SAS.
     *
     * "RC/DLA/2019/A/A/687 · NIU M123456789012A · Capital 1 000 000 FCFA"
     */
    public static function legalMentions(): string
    {
        $p     = self::get();
        $parts = [];

        if (!empty($p['rccm_number'])) {
            $parts[] = 'RCCM ' . $p['rccm_number'];
        }
        if (!empty($p['niu'])) {
            $parts[] = 'NIU ' . $p['niu'];
        }
        if (!empty($p['share_capital']) && (float) $p['share_capital'] > 0) {
            $parts[] = 'Capital social ' . number_format((float) $p['share_capital'], 0, ',', ' ') . ' FCFA';
        }
        if (!empty($p['tax_office'])) {
            $parts[] = (string) $p['tax_office'];
        }

        return implode(' · ', $parts);
    }

    /**
     * Everything a PDF letterhead needs, pre-formatted.
     *
     * @return array{name:string,legal_name:string,address:string,contact:string,mentions:string,logo:string,color:string,footer:string}
     */
    public static function letterhead(string $lang = 'fr'): array
    {
        $p = self::get();

        $footer = $lang === 'en'
            ? (string)($p['document_footer_en'] ?? '')
            : (string)($p['document_footer_fr'] ?? '');

        // Fall back to a computed footer if none was configured.
        if (trim($footer) === '') {
            $footer = trim(self::displayName() . ' · ' . self::legalMentions() . ' · ' . self::addressBlock());
        }

        return [
            'name'       => self::displayName(),
            'legal_name' => self::legalName(),
            'address'    => self::addressBlock(),
            'contact'    => self::contactLine(),
            'mentions'   => self::legalMentions(),
            'logo'       => self::logo('document'),
            'color'      => (string)($p['brand_color_primary'] ?? '#005A2B'),
            'accent'     => (string)($p['brand_color_accent'] ?? '#8CC63F'),
            'footer'     => $footer,
        ];
    }

    /**
     * Bank details block for the payment section of an invoice.
     * Returns [] when nothing is configured, so the caller can skip the block.
     *
     * @return array<string,string>
     */
    public static function bankDetails(): array
    {
        $p   = self::get();
        $out = [];

        if (!empty($p['bank_name']))           $out['Banque']        = (string) $p['bank_name'];
        if (!empty($p['bank_account_rib']))    $out['RIB']           = (string) $p['bank_account_rib'];
        if (!empty($p['bank_iban']))           $out['IBAN']          = (string) $p['bank_iban'];
        if (!empty($p['bank_swift']))          $out['SWIFT']         = (string) $p['bank_swift'];
        if (!empty($p['mobile_money_number'])) $out['Mobile Money']  = (string) $p['mobile_money_number'];

        return $out;
    }

    /**
     * A logo path. `document` falls back to `main`, `main` falls back to the
     * shipped SVG — so a PDF never renders a broken image.
     *
     * @param string $which  'main' | 'mark' | 'document'
     */
    public static function logo(string $which = 'main'): string
    {
        $p = self::get();

        switch ($which) {
            case 'mark':
                return (string)($p['logo_mark_path'] ?: '/assets/img/small_logo.svg');
            case 'document':
                return (string)($p['logo_document_path']
                    ?: $p['logo_main_path']
                    ?: '/assets/img/full_logo.svg');
            case 'main':
            default:
                return (string)($p['logo_main_path'] ?: '/assets/img/full_logo.svg');
        }
    }

    /**
     * Absolute filesystem path for a logo, for dompdf (which cannot resolve
     * web-root-relative URLs). Returns '' if the file is not on disk.
     */
    public static function logoFsPath(string $which = 'document'): string
    {
        $rel = self::logo($which);
        if ($rel === '' || preg_match('#^https?://#i', $rel)) {
            return '';
        }
        $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2), '/');
        $abs  = $root . '/' . ltrim($rel, '/');
        return is_readable($abs) ? $abs : '';
    }

    /** The name shown in the app shell (sidebar, browser title). */
    public static function erpName(): string
    {
        // Falls back to the APP_NAME constant so the shell still renders if the
        // profile row is missing.
        return (string) self::field('erp_display_name', defined('APP_NAME') ? APP_NAME : 'ERP');
    }

    public static function erpTagline(): string
    {
        return (string) self::field('erp_tagline', '');
    }

    /**
     * Persist a partial update. Only keys in self::EDITABLE are written.
     * Returns the number of columns actually changed.
     *
     * Also mirrors the fiscal + identity fields into company_tax_settings so
     * TaxEngine and the existing tax_declarations page stay consistent without
     * having to be rewritten.
     *
     * @param  array<string,mixed> $data
     * @throws RuntimeException on validation failure
     */
    public static function save(array $data, ?int $userId = null): int
    {
        $clean = [];
        foreach (self::EDITABLE as $col) {
            if (array_key_exists($col, $data)) {
                $clean[$col] = is_string($data[$col]) ? trim($data[$col]) : $data[$col];
            }
        }
        if (!$clean) {
            return 0;
        }

        self::validate($clean);

        // Normalise empty strings on nullable/typed columns to NULL so MySQL
        // doesn't reject '' for DATE / DECIMAL.
        foreach (['incorporation_date', 'share_capital'] as $nullable) {
            if (array_key_exists($nullable, $clean) && $clean[$nullable] === '') {
                $clean[$nullable] = null;
            }
        }

        $db = Database::getInstance()->getConnection();

        // Ensure a row exists (fresh install, migration backfill skipped).
        $id = $db->query("SELECT id FROM company_profile WHERE is_active = 1 ORDER BY id ASC LIMIT 1")
                 ->fetchColumn();

        if (!$id) {
            $db->prepare("INSERT INTO company_profile (legal_name, is_active) VALUES (?, 1)")
               ->execute([$clean['legal_name'] ?? 'Entreprise']);
            $id = (int) $db->lastInsertId();
        }

        $sets   = [];
        $params = [];
        foreach ($clean as $col => $val) {
            $sets[]   = "`{$col}` = ?";
            $params[] = $val;
        }
        $sets[]   = '`updated_by` = ?';
        $params[] = self::resolveActor($userId, $db);
        $params[] = $id;

        $sql  = 'UPDATE company_profile SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        self::mirrorToTaxSettings($clean, $db);
        self::flush();

        return count($clean);
    }

    /**
     * Keep company_tax_settings (migration 020) in step. That table still
     * drives TaxEngine; rather than rewrite every consumer we write both.
     * company_profile is authoritative — this is a one-way mirror.
     *
     * @param array<string,mixed> $clean
     */
    private static function mirrorToTaxSettings(array $clean, PDO $db): void
    {
        $map = [
            'legal_name'    => 'legal_name',
            'niu'           => 'niu',
            'fiscal_regime' => 'tax_regime',
            'tax_office'    => 'tax_office',
        ];

        $sets   = [];
        $params = [];
        foreach ($map as $src => $dst) {
            if (array_key_exists($src, $clean)) {
                $sets[]   = "`{$dst}` = ?";
                $params[] = $clean[$src];
            }
        }
        if (!$sets) {
            return;
        }

        try {
            $tid = $db->query("SELECT id FROM company_tax_settings ORDER BY id ASC LIMIT 1")->fetchColumn();
            if ($tid) {
                $params[] = $tid;
                $db->prepare('UPDATE company_tax_settings SET ' . implode(', ', $sets) . ' WHERE id = ?')
                   ->execute($params);
            }
        } catch (Throwable $e) {
            // A failed mirror must not fail the primary save. The profile is
            // the source of truth; the tax table is a convenience copy.
            error_log('CompanyProfile: tax-settings mirror failed — ' . $e->getMessage());
        }
    }

    /**
     * Resolve the acting user id for the `updated_by` audit column.
     *
     * `updated_by` carries a FK to users(id). If a session outlives the user
     * row it points at — an admin deleted mid-session, or a seeded/CLI context
     * passing an id that was never real — writing it raises a 1452 integrity
     * error and the whole save fails with a 500. Losing the attribution on an
     * audit column is a far smaller problem than refusing the edit, so an
     * unknown actor degrades to NULL.
     */
    private static function resolveActor(?int $userId, PDO $db): ?int
    {
        if ($userId === null || $userId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $found = $stmt->fetchColumn();
            return $found ? (int) $found : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed> $d
     * @throws RuntimeException
     */
    private static function validate(array $d): void
    {
        if (array_key_exists('legal_name', $d) && trim((string) $d['legal_name']) === '') {
            throw new RuntimeException('La raison sociale est obligatoire.');
        }

        if (!empty($d['legal_form']) && !isset(self::LEGAL_FORMS[$d['legal_form']])) {
            throw new RuntimeException('Forme juridique invalide.');
        }

        if (!empty($d['fiscal_regime']) && !isset(self::FISCAL_REGIMES[$d['fiscal_regime']])) {
            throw new RuntimeException('Régime fiscal invalide.');
        }

        // Cameroon NIU: 14 characters, letter + 12 digits + letter.
        // Warn-shaped rather than strict — some legacy numbers differ — but we
        // do reject anything obviously not an identifier.
        if (!empty($d['niu']) && !preg_match('/^[A-Za-z0-9\-\/ ]{6,30}$/', (string) $d['niu'])) {
            throw new RuntimeException('Le NIU contient des caractères invalides.');
        }

        if (!empty($d['rccm_number']) && !preg_match('#^[A-Za-z0-9\-\/\. ]{4,60}$#', (string) $d['rccm_number'])) {
            throw new RuntimeException('Le numéro RCCM contient des caractères invalides.');
        }

        foreach (['email_general', 'email_billing'] as $ef) {
            if (!empty($d[$ef]) && !filter_var($d[$ef], FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("Adresse email invalide : {$d[$ef]}");
            }
        }

        if (!empty($d['website'])
            && !filter_var($d['website'], FILTER_VALIDATE_URL)
            && !preg_match('#^[\w\.\-]+\.[a-z]{2,}$#i', (string) $d['website'])) {
            throw new RuntimeException('Adresse du site web invalide.');
        }

        foreach (['brand_color_primary', 'brand_color_accent'] as $cf) {
            if (!empty($d[$cf]) && !preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $d[$cf])) {
                throw new RuntimeException('Couleur invalide (format attendu : #RRGGBB).');
            }
        }

        if (!empty($d['incorporation_date'])
            && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['incorporation_date'])) {
            throw new RuntimeException('Date de création invalide.');
        }

        if (isset($d['share_capital']) && $d['share_capital'] !== null && $d['share_capital'] !== ''
            && (!is_numeric($d['share_capital']) || (float) $d['share_capital'] < 0)) {
            throw new RuntimeException('Le capital social doit être un nombre positif.');
        }

        if (!empty($d['country_code']) && !preg_match('/^[A-Za-z]{2}$/', (string) $d['country_code'])) {
            throw new RuntimeException('Le code pays doit comporter 2 lettres (ex. CM).');
        }
    }

    /**
     * Used when the table is missing or empty. Deliberately generic — if you
     * see "Entreprise" on a document, the profile has not been filled in.
     *
     * @return array<string,mixed>
     */
    private static function defaults(): array
    {
        return [
            'id'                  => 0,
            'legal_name'          => defined('APP_NAME') ? APP_NAME : 'Entreprise',
            'trade_name'          => '',
            'legal_form'          => 'SARL',
            'rccm_number'         => '',
            'niu'                 => '',
            'trade_register_city' => '',
            'incorporation_date'  => null,
            'share_capital'       => null,
            'fiscal_regime'       => 'reel',
            'tax_office'          => '',
            'cnps_employer_number'=> '',
            'activity_sector'     => '',
            'activity_code'       => '',
            'address_line1'       => '',
            'address_line2'       => '',
            'po_box'              => '',
            'city'                => 'Douala',
            'region'              => 'Littoral',
            'country'             => 'Cameroun',
            'country_code'        => 'CM',
            'phone_primary'       => '',
            'phone_secondary'     => '',
            'whatsapp'            => '',
            'email_general'       => '',
            'email_billing'       => '',
            'website'             => '',
            'bank_name'           => '',
            'bank_account_rib'    => '',
            'bank_iban'           => '',
            'bank_swift'          => '',
            'mobile_money_number' => '',
            'erp_display_name'    => defined('APP_NAME') ? APP_NAME : 'ERP',
            'erp_tagline'         => '',
            'logo_main_path'      => '/assets/img/full_logo.svg',
            'logo_mark_path'      => '/assets/img/small_logo.svg',
            'logo_document_path'  => '',
            'brand_color_primary' => '#005A2B',
            'brand_color_accent'  => '#8CC63F',
            'document_footer_fr'  => '',
            'document_footer_en'  => '',
            'is_active'           => 1,
        ];
    }
}
