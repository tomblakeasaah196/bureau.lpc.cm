<?php
// includes/functions/helpers.php
//
// NOTE (Sprint 6 D4): the __t() translation stub previously lived here has
// been replaced by the real i18n loader in includes/functions/i18n.php,
// which bootstrap.php includes on every request. Anything that used to
// require this file still gets __t() for free.
//
// This file now contains only the legacy sanitize_input() helper, kept so
// older call sites don't break. Prefer htmlspecialchars(..., ENT_QUOTES,
// 'UTF-8') directly in new code.

// Ensure the real i18n loader is available even if this file is included
// before bootstrap ran (e.g. tests, standalone scripts).
if (!function_exists('__t')) {
    require_once __DIR__ . '/i18n.php';
}

// Sanitize user input to prevent XSS (Cross-Site Scripting).
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
        return $data;
    }
}
