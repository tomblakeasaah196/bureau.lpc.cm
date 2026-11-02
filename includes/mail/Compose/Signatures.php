<?php

namespace LPC\Mail\Compose;

use LPC\Mail\Clock;
use LPC\Mail\Db;
use LPC\Mail\MailError;
use LPC\Mail\MailSettings;
use LPC\Mail\People;
use Throwable;

/**
 * Email signatures (owner decision 12): one LPC company design, built from bureau's HR record (name, job
 * title, service, phone) plus the LPC logo and the company's details. A person edits only their own numbers
 * (mail_signatures); admins edit the company part (mail_settings `signature.company`, over the company
 * profile's own values). Added when the message is built (MessageBuilder), never inside the editor:
 * 'full' on new mail and forwards, 'slim' on replies, or 'none'.
 *
 * The address shown is the mailbox the message goes from: on a shared mailbox, the person and the shared
 * address (decision 7: From is the mailbox, who wrote it shows in their signature).
 *
 * Mail apps ignore style sheets and SVG and many drop CSS layout, so the design is tables, inline styles and
 * literal colours (LPC dark and leaf green, from the company profile), a PNG logo at three times its size
 * with width and height set (sharp on phones, right size in Outlook), and a plain-text twin.
 */
final class Signatures
{
    public const STYLES = ['full' => 'Complète', 'slim' => 'Courte', 'none' => 'Aucune'];

    /** Digits, spaces, brackets, dots and dashes, with an optional + in front */
    public const PHONE = '/^\+?[0-9][0-9 ().\-]{4,22}[0-9]$/';

    private const FONT = "font-family:'Segoe UI','Helvetica Neue',Helvetica,Arial,sans-serif;";
    private const INK = '#13261B';
    private const SOFT = '#4F5D55';
    private const MUTED = '#6B7A72';
    private const LINE = '#E1EADF';

    private static array $people = [];
    private static ?array $profile = null;
    private static ?bool $hasDepartment = null;

    // ------------------------------------------------------------------ company

    /**
     * The company part as the signature shows it.
     *
     * @return array{name: string, tagline: string, address: string, phone: string, website: string, logo_url: string,
     *               show_logo: bool, show_legal: bool, legal: string, brand: string, accent: string, custom: bool}
     */
    public static function company(): array
    {
        $defaults = self::companyDefaults();
        $saved = MailSettings::get('signature.company');
        if (!is_array($saved)) {
            return $defaults + ['custom' => false];
        }
        $out = $defaults;
        foreach (['name', 'tagline', 'address', 'phone', 'website'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = trim((string) $saved[$k]);
            }
        }
        // A custom logo replaces bureau's LPC logo; none given keeps it (hiding it is show_logo)
        if (trim((string) ($saved['logo_url'] ?? '')) !== '') {
            $out['logo_url'] = trim((string) $saved['logo_url']);
        }
        foreach (['show_logo', 'show_legal'] as $k) {
            if (array_key_exists($k, $saved)) {
                $out[$k] = (bool) $saved[$k];
            }
        }
        if ($out['name'] === '') {
            $out['name'] = $defaults['name'];
        }

        return $out + ['custom' => true];
    }

    /** What the company profile (Administration → Entreprise) says, before any change made here */
    public static function companyDefaults(): array
    {
        $p = self::profile();
        $join = fn (array $parts, string $sep = ' · ') => implode($sep, array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== '')));
        $phones = preg_split('#\s*/\s*#', (string) ($p['phone_primary'] ?? '')) ?: [];
        $legal = $join([
            !empty($p['rccm_number']) ? 'RCCM ' . $p['rccm_number'] : '',
            !empty($p['niu']) ? 'NIU ' . $p['niu'] : '',
        ]);
        $logo = (string) ComposeConfig::get('logo_url', '');

        return [
            'name' => trim((string) (($p['trade_name'] ?? '') ?: ($p['legal_name'] ?? '') ?: 'La Petite Cour')),
            'tagline' => trim((string) (($p['activity_sector'] ?? '') ?: ($p['erp_tagline'] ?? '') ?: 'Distribution agroalimentaire')),
            'address' => $join([
                (string) ($p['address_line1'] ?? ''),
                (string) ($p['po_box'] ?? ''),
                $join([(string) ($p['city'] ?? ''), (string) ($p['country'] ?? '')], ', '),
            ]),
            'phone' => trim((string) ($phones[0] ?? '')),
            'website' => self::website((string) ($p['website'] ?? '')),
            'logo_url' => preg_match('#^https?://#i', $logo) ? $logo : '',
            'show_logo' => true,
            'show_legal' => false,
            'legal' => $legal,
            'brand' => self::hex($p['brand_color_primary'] ?? null, '#005A2B'),
            'accent' => self::hex($p['brand_color_accent'] ?? null, '#8CC63F'),
        ];
    }

    /**
     * Admins save the company part (checked by the caller: MailAccess::canAddShared). Throws MailError on a
     * field. @return array the company part as it now shows
     */
    public static function setCompany(array $values, int $byUserId): array
    {
        $data = self::checkCompany($values);
        MailSettings::set('signature.company', $data, $byUserId);

        return self::company();
    }

    /** Back to the company profile's values */
    public static function resetCompany(): void
    {
        MailSettings::forget('signature.company');
    }

    /** The company values from a form, checked and tidied. Throws MailError on the first wrong field. */
    public static function checkCompany(array $values): array
    {
        $str = fn (string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($values[$k] ?? ''))), 0, $max);
        $data = [
            'name' => $str('name', 80),
            'tagline' => $str('tagline', 120),
            'address' => $str('address', 160),
            'phone' => $str('phone', 30),
            'website' => $str('website', 120),
            'logo_url' => $str('logo_url', 300),
            'show_logo' => filter_var($values['show_logo'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'show_legal' => filter_var($values['show_legal'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
        if ($data['name'] === '') {
            throw MailError::field('name', 'Saisissez le nom de l’entreprise tel que les clients le connaissent.');
        }
        if ($data['phone'] !== '' && !preg_match(self::PHONE, $data['phone'])) {
            throw MailError::field('phone', 'Saisissez le numéro avec des chiffres et des espaces, par exemple +237 696 291 800.');
        }
        if ($data['website'] !== '') {
            $site = self::website($data['website']);
            if ($site === '') {
                throw MailError::field('website', 'Saisissez l’adresse du site, par exemple https://lpc.cm.');
            }
            $data['website'] = $site;
        }
        if ($data['logo_url'] !== '' && (!preg_match('#^https://[^\s"<>]+$#i', $data['logo_url']) || !filter_var($data['logo_url'], FILTER_VALIDATE_URL))) {
            throw MailError::field('logo_url', 'Saisissez l’adresse complète du logo, en https:// (une image PNG ou JPG).');
        }

        return $data;
    }

    // ------------------------------------------------------------------ person

    /** @return array{phone: ?string, mobile: ?string, saved: bool} the person's own numbers */
    public static function numbers(int $userId): array
    {
        $row = Db::one('SELECT phone, mobile FROM mail_signatures WHERE user_id = ?', [$userId]);

        return ['phone' => $row['phone'] ?? null, 'mobile' => $row['mobile'] ?? null, 'saved' => $row !== null];
    }

    /** A person changes their own numbers (blank = not shown). Throws MailError on a field. */
    public static function setNumbers(int $userId, ?string $phone, ?string $mobile): void
    {
        $data = self::checkNumbers(['phone' => $phone, 'mobile' => $mobile]);
        $now = Clock::sql();
        Db::exec(
            'INSERT INTO mail_signatures (user_id, phone, mobile, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE phone = VALUES(phone), mobile = VALUES(mobile), updated_at = VALUES(updated_at)',
            [$userId, $data['phone'], $data['mobile'], $now, $now],
        );
        unset(self::$people[$userId]);
    }

    /** @return array{phone: ?string, mobile: ?string} */
    public static function checkNumbers(array $values): array
    {
        $out = [];
        foreach (['phone' => 'le numéro de bureau', 'mobile' => 'le numéro de mobile'] as $k => $what) {
            $v = trim(preg_replace('/\s+/u', ' ', (string) ($values[$k] ?? '')));
            if ($v !== '' && !preg_match(self::PHONE, $v)) {
                throw MailError::field($k, "Saisissez {$what} avec des chiffres et des espaces, par exemple +237 696 291 800.");
            }
            $out[$k] = $v !== '' ? $v : null;
        }

        return $out;
    }

    /**
     * What bureau knows about the person for the signature: name, job title and service from the HR record,
     * numbers from mail_signatures (the HR record's phone as mobile until they set their own).
     *
     * @return array{name: string, title: ?string, department: ?string, phone: ?string, mobile: ?string, hr_phone: ?string}
     */
    public static function person(int $userId): array
    {
        if (isset(self::$people[$userId])) {
            return self::$people[$userId];
        }
        $p = People::find($userId);
        self::$hasDepartment ??= (bool) Db::value(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'employees' AND column_name = 'department'",
        );
        $hr = Db::one(
            'SELECT e.job_title, e.personal_phone' . (self::$hasDepartment ? ', e.department' : ', NULL AS department') . '
               FROM users u LEFT JOIN employees e ON e.id = u.employee_id WHERE u.id = ?',
            [$userId],
        ) ?? [];
        $own = self::numbers($userId);
        $hrPhone = self::blank($hr['personal_phone'] ?? null);

        return self::$people[$userId] = [
            'name' => $p['name'] ?? 'Une personne',
            'title' => self::blank($hr['job_title'] ?? null),
            'department' => self::blank($hr['department'] ?? null),
            'phone' => $own['saved'] ? $own['phone'] : null,
            'mobile' => $own['saved'] ? $own['mobile'] : $hrPhone,
            'hr_phone' => $hrPhone,
        ];
    }

    // ------------------------------------------------------------------ rendering

    /** The signature as HTML, ready to add below a message ('' for 'none') */
    public static function html(int $userId, array $account, string $style = 'full'): string
    {
        return self::render(self::person($userId), self::company(), (string) $account['email'], $style);
    }

    /** The same signature as plain text ('' for 'none'); starts with the "-- " line mail apps recognise */
    public static function text(int $userId, array $account, string $style = 'full'): string
    {
        return self::renderText(self::person($userId), self::company(), (string) $account['email'], $style);
    }

    /** @param array $person as person() @param array $company as company() */
    public static function render(array $person, array $company, string $email, string $style = 'full'): string
    {
        if ($style === 'none' || !isset(self::STYLES[$style])) {
            return '';
        }
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $brand = self::hex($company['brand'] ?? null, '#005A2B');
        $accent = self::hex($company['accent'] ?? null, '#8CC63F');
        $f = self::FONT;
        $table = 'role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;mso-table-lspace:0pt;mso-table-rspace:0pt;';
        $role = implode(' · ', array_filter([$person['title'] ?? null, $person['department'] ?? null]));
        $link = fn (string $href, string $text, string $color) => '<a href="' . $e($href) . '" style="color:' . $color . ';text-decoration:none;">' . $e($text) . '</a>';
        $tel = fn (string $n) => $link('tel:' . preg_replace('/[^0-9+]/', '', $n), $n, self::SOFT);
        $mail = $link('mailto:' . $email, $email, $brand);
        $site = $company['website'] !== '' ? $link($company['website'], self::siteLabel($company['website']), $brand) : '';

        if ($style === 'slim') {
            $line2 = array_filter([
                '<span style="color:' . $brand . ';font-weight:600;">' . $e($company['name']) . '</span>',
                !empty($person['phone']) ? $tel($person['phone']) : null,
                !empty($person['mobile']) ? $tel($person['mobile']) : null,
                $mail,
            ]);

            return '<table ' . $table . 'margin-top:20px;"><tr>'
                . '<td style="' . $f . 'border-left:3px solid ' . $accent . ';padding:1px 0 1px 12px;font-size:13px;line-height:20px;color:' . self::SOFT . ';mso-line-height-rule:exactly;">'
                . '<span style="color:' . self::INK . ';font-weight:700;">' . $e($person['name']) . '</span>'
                . ($role !== '' ? '<span style="color:' . self::MUTED . ';"> · ' . $e($role) . '</span>' : '')
                . '<br>' . implode('<span style="color:' . self::MUTED . ';"> · </span>', $line2)
                . '</td></tr></table>';
        }

        // Contact rows: a small brand-coloured label, then the value
        $rows = [];
        if (!empty($person['phone'])) {
            $rows[] = ['Tél.', $tel($person['phone'])];
        }
        if (!empty($person['mobile'])) {
            $rows[] = ['Mobile', $tel($person['mobile'])];
        }
        if (empty($person['phone']) && empty($person['mobile']) && $company['phone'] !== '') {
            $rows[] = ['Tél.', $tel($company['phone'])];
        }
        $rows[] = ['E-mail', $mail];
        if ($site !== '') {
            $rows[] = ['Web', $site];
        }
        $contact = '';
        foreach ($rows as [$label, $value]) {
            $contact .= '<tr>'
                . '<td valign="top" style="' . $f . 'padding:0 10px 3px 0;font-size:10px;line-height:19px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:' . $brand . ';white-space:nowrap;mso-line-height-rule:exactly;">' . $e($label) . '</td>'
                . '<td valign="top" style="' . $f . 'padding:0 0 3px;font-size:13px;line-height:19px;color:' . self::SOFT . ';mso-line-height-rule:exactly;">' . $value . '</td>'
                . '</tr>';
        }

        // The leaf rule under the name: LPC dark green, then leaf green, as in the logo
        $rule = '<table ' . $table . 'margin:9px 0 10px;"><tr>'
            . '<td width="22" height="3" bgcolor="' . $brand . '" style="width:22px;height:3px;font-size:0;line-height:0;background:' . $brand . ';">&nbsp;</td>'
            . '<td width="44" height="3" bgcolor="' . $accent . '" style="width:44px;height:3px;font-size:0;line-height:0;background:' . $accent . ';">&nbsp;</td>'
            . '</tr></table>';

        $text = '<div style="' . $f . 'font-size:17px;line-height:22px;font-weight:700;color:' . self::INK . ';letter-spacing:-0.1px;mso-line-height-rule:exactly;">' . $e($person['name']) . '</div>'
            . ($role !== '' ? '<div style="' . $f . 'font-size:11px;line-height:17px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:' . $brand . ';padding-top:2px;mso-line-height-rule:exactly;">' . $e($role) . '</div>' : '')
            . $rule
            . '<table ' . $table . '">' . $contact . '</table>';

        $logo = '';
        if ($company['show_logo'] && $company['logo_url'] !== '') {
            $img = '<img src="' . $e($company['logo_url']) . '" width="76" height="76" alt="' . $e($company['name']) . '" style="display:block;width:76px;height:76px;border:0;outline:none;text-decoration:none;' . $f . 'font-size:12px;line-height:16px;font-weight:700;color:' . $brand . ';">';
            $logo = '<td valign="top" width="76" style="width:76px;padding:2px 18px 0 0;">'
                . ($company['website'] !== '' ? '<a href="' . $e($company['website']) . '" style="text-decoration:none;">' . $img . '</a>' : $img)
                . '</td>';
        }
        $textCell = '<td valign="top" style="' . $f . ($logo !== '' ? 'border-left:1px solid ' . self::LINE . ';padding:0 0 0 18px;' : 'padding:0;') . '">' . $text . '</td>';

        // The company line: its name set in small capitals, what it does, where it is (and the legal line, if chosen)
        $foot = '<span style="font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:' . $brand . ';">' . $e($company['name']) . '</span>';
        if ($company['tagline'] !== '') {
            $foot .= '<span style="color:' . self::MUTED . ';">&nbsp;&nbsp;·&nbsp;&nbsp;' . $e($company['tagline']) . '</span>';
        }
        if ($company['address'] !== '') {
            $foot .= '<br><span style="color:' . self::MUTED . ';">' . $e($company['address']) . '</span>';
        }
        if (!empty($company['show_legal']) && ($company['legal'] ?? '') !== '') {
            $foot .= '<br><span style="color:#8A968F;font-size:10px;">' . $e($company['legal']) . '</span>';
        }

        return '<table ' . $table . 'margin-top:24px;">'
            . '<tr>' . $logo . $textCell . '</tr>'
            . '<tr><td colspan="' . ($logo !== '' ? 2 : 1) . '" style="padding:14px 0 0;">'
            . '<table ' . $table . 'width:100%;"><tr><td height="1" bgcolor="' . self::LINE . '" style="height:1px;font-size:0;line-height:0;background:' . self::LINE . ';">&nbsp;</td></tr></table>'
            . '<div style="' . $f . 'font-size:11px;line-height:17px;padding-top:9px;color:' . self::MUTED . ';mso-line-height-rule:exactly;">' . $foot . '</div>'
            . '</td></tr></table>';
    }

    public static function renderText(array $person, array $company, string $email, string $style = 'full'): string
    {
        if ($style === 'none' || !isset(self::STYLES[$style])) {
            return '';
        }
        $role = implode(' · ', array_filter([$person['title'] ?? null, $person['department'] ?? null]));
        $numbers = array_filter([
            !empty($person['phone']) ? 'Tél. ' . $person['phone'] : null,
            !empty($person['mobile']) ? 'Mobile ' . $person['mobile'] : null,
        ]);
        if ($style === 'slim') {
            return "-- \n" . $person['name'] . ($role !== '' ? " · {$role}" : '') . "\n"
                . implode(' · ', array_filter([$company['name'], ...array_map(fn ($n) => preg_replace('/^(Tél\.|Mobile) /', '', $n), $numbers), $email]));
        }
        if (!$numbers && $company['phone'] !== '') {
            $numbers = ['Tél. ' . $company['phone']];
        }
        $lines = array_filter([
            $person['name'],
            $role ?: null,
            '',
            implode(' · ', $numbers) ?: null,
            implode(' · ', array_filter([$email, $company['website'] !== '' ? self::siteLabel($company['website']) : null])),
            '',
            $company['name'] . ($company['tagline'] !== '' ? ' · ' . $company['tagline'] : ''),
            $company['address'] ?: null,
            !empty($company['show_legal']) && ($company['legal'] ?? '') !== '' ? $company['legal'] : null,
        ], fn ($l) => $l !== null);

        return "-- \n" . trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /** For tests and the preview after a change */
    public static function flush(): void
    {
        self::$people = [];
        self::$profile = null;
        self::$hasDepartment = null;
    }

    // ------------------------------------------------------------------ helpers

    /** The company profile row (migration 034), read with Mail's own connection; [] when it isn't there */
    private static function profile(): array
    {
        if (self::$profile !== null) {
            return self::$profile;
        }
        try {
            $row = Db::one('SELECT * FROM company_profile WHERE is_active = 1 ORDER BY id LIMIT 1');
        } catch (Throwable) {
            $row = null;   // the tests' small database has no company_profile
        }

        return self::$profile = $row ?? [];
    }

    /** "lpc.cm" → "https://lpc.cm"; '' when it isn't a web address */
    private static function website(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) && !preg_match('/[\s"<>]/', $value) ? rtrim($value, '/') : '';
    }

    private static function siteLabel(string $url): string
    {
        return preg_replace('~^https?://(www\.)?~i', '', rtrim($url, '/'));
    }

    private static function hex(mixed $value, string $default): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) ? strtoupper($value) : $default;
    }

    private static function blank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
