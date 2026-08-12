<?php
/**
 * includes/functions/password_policy.php
 * -----------------------------------------------------------------------------
 * Bureau LPC ERP — ONE definition of what makes a password acceptable.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * Before Sprint 14 the rule was written down in four places that disagreed:
 *
 *   · api/v1/password_controller.php  — a regex hardcoding a minimum of 8
 *   · auth-password_manager.js        — a JS checklist hardcoding 8
 *   · password_reset.php's inline JS  — another checklist hardcoding 8
 *   · app_preferences.sec_password_min_length — set to 10, read by nobody
 *
 * So the admin-facing preference was decorative: an admin who raised the
 * minimum to 14 changed nothing at all, and the UI kept promising 8. Worse,
 * settings_controller.php's user-creation path applied NO policy whatsoever —
 * an admin could set a colleague's password to "a".
 *
 * Everything now reads lpc_password_policy(). The browser checklist is
 * rendered FROM the same array (lpc_password_policy_js()), so the rules a user
 * sees are by construction the rules the server enforces.
 *
 * WHY MINIMUM LENGTH IS THE ONLY CONFIGURABLE PART
 * ------------------------------------------------
 * `sec_password_min_length` already exists, is already exposed in Paramètres →
 * Préférences, and is already documented. The character-class requirements are
 * fixed because making them configurable invites someone to switch them off,
 * and because NIST SP 800-63B's actual advice is that length carries the
 * weight anyway — the classes here are a floor, not a security theatre dial.
 *
 * SESSION HANDLING IS NOT THIS FILE'S JOB
 * ---------------------------------------
 * Callers do the DB write and the session invalidation. This file answers one
 * question — "is this password acceptable, and if not, why not, in the user's
 * language" — and answers it identically everywhere.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('lpc_password_min_length')) {
    /**
     * The configured minimum, clamped to something defensible.
     *
     * Clamped because app_preferences is admin-editable and migration 034
     * declares min_value 8 / max_value 64 — but a hand-edited row, or a
     * pre-034 database, can hold anything. A minimum of 0 would silently
     * disable the whole policy, which is exactly the kind of failure that
     * should be impossible rather than merely unlikely.
     */
    function lpc_password_min_length(): int
    {
        $n = class_exists('Prefs') ? Prefs::int('sec_password_min_length', 10) : 10;
        return max(8, min(64, $n));
    }
}

if (!function_exists('lpc_password_policy')) {
    /**
     * The rule set, as data.
     *
     * Each entry: id (matches the DOM id the checklist renders),
     * regex (or null for the length rule), and a bilingual label.
     *
     * @return array<int, array{id:string, min?:int, regex:?string, fr:string, en:string}>
     */
    function lpc_password_policy(): array
    {
        $min = lpc_password_min_length();

        return [
            [
                'id'    => 'len',
                'min'   => $min,
                'regex' => null,
                'fr'    => "Au moins {$min} caractères",
                'en'    => "At least {$min} characters",
            ],
            [
                'id'    => 'cap',
                'regex' => '/[A-Z]/',
                'fr'    => 'Une majuscule',
                'en'    => 'One uppercase letter',
            ],
            [
                'id'    => 'low',
                'regex' => '/[a-z]/',
                'fr'    => 'Une minuscule',
                'en'    => 'One lowercase letter',
            ],
            [
                'id'    => 'num',
                'regex' => '/\d/',
                'fr'    => 'Un chiffre',
                'en'    => 'One digit',
            ],
            [
                'id'    => 'spec',
                'regex' => '/[^A-Za-z0-9]/',
                'fr'    => 'Un caractère spécial',
                'en'    => 'One special character',
            ],
        ];
    }
}

if (!function_exists('lpc_password_check')) {
    /**
     * Validate a candidate password.
     *
     * @return array{ok:bool, failed:string[], message:string}
     *         `failed` holds rule ids; `message` is a ready-to-show sentence
     *         in the requested language listing what is missing.
     *
     * The special-character class is `[^A-Za-z0-9]`, deliberately broader than
     * the old controller's `[!@#$%^&*(),.?":{}|<>]`. That list excluded the
     * hyphen, underscore, space, and every accented character — so a French
     * speaker's entirely reasonable "Café-du-Marché-2026" was rejected as
     * having no special character, with no explanation of which characters
     * would count.
     */
    function lpc_password_check(string $password, string $lang = 'fr'): array
    {
        $lang   = ($lang === 'en') ? 'en' : 'fr';
        $failed = [];
        $labels = [];

        foreach (lpc_password_policy() as $rule) {
            $ok = ($rule['regex'] === null)
                ? (mb_strlen($password) >= (int) $rule['min'])
                : (bool) preg_match($rule['regex'], $password);

            if (!$ok) {
                $failed[]  = $rule['id'];
                $labels[]  = mb_strtolower($rule[$lang]);
            }
        }

        if (!$failed) {
            return ['ok' => true, 'failed' => [], 'message' => ''];
        }

        $intro = ($lang === 'en')
            ? 'Password too weak. It still needs: '
            : 'Mot de passe trop faible. Il manque : ';

        return [
            'ok'      => false,
            'failed'  => $failed,
            'message' => $intro . implode(', ', $labels) . '.',
        ];
    }
}

if (!function_exists('lpc_password_hash')) {
    /**
     * Hash a password the one way this application hashes passwords.
     *
     * PASSWORD_BCRYPT with the cost from .env, NOT PASSWORD_DEFAULT.
     * settings_controller.php used PASSWORD_DEFAULT while
     * password_controller.php used BCRYPT — harmless today because
     * PASSWORD_DEFAULT still resolves to bcrypt, and a live landmine for the
     * PHP release where it becomes argon2id: password_verify() would keep
     * working, but the two paths would be writing different hash formats and
     * ignoring the configured cost.
     */
    function lpc_password_hash(string $password): string
    {
        $cost = (int) (function_exists('env') ? env('BCRYPT_COST', 12) : 12);
        $cost = max(10, min(15, $cost));

        return password_hash($password, PASSWORD_BCRYPT, ['cost' => $cost]);
    }
}

if (!function_exists('lpc_password_policy_js')) {
    /**
     * Publish the policy to the browser as window.LPC.pwPolicy.
     *
     * Emitted by the two pages that show a live rules checklist. The JS
     * renders the list from this payload, so raising sec_password_min_length
     * in Paramètres updates the checklist, the error messages and the server
     * enforcement together — there is no second place to remember.
     */
    function lpc_password_policy_js(string $lang = 'fr'): string
    {
        $lang  = ($lang === 'en') ? 'en' : 'fr';
        $rules = [];

        foreach (lpc_password_policy() as $rule) {
            $rules[] = [
                'id'    => $rule['id'],
                'min'   => $rule['min']   ?? null,
                // JS needs a source string, not a PHP pattern: strip the
                // delimiters so `new RegExp()` can consume it.
                'regex' => $rule['regex'] ? trim($rule['regex'], '/') : null,
                'label' => $rule[$lang],
            ];
        }

        return '<script>window.LPC=window.LPC||{};window.LPC.pwPolicy='
             . json_encode($rules, JSON_UNESCAPED_UNICODE) . ';</script>';
    }
}
