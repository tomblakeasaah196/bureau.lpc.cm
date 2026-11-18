<?php
/**
 * scripts/admin/admin_2fa_off.php — the way back in if the emailed sign-in code
 * cannot be received (mail down, mailbox lost): turns Paramètres → « Imposer la
 * 2FA aux administrateurs » off. Run on the server: php scripts/admin/admin_2fa_off.php
 * (includes/functions/admin_2fa.php, the owner's decision 24).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../includes/config/env.php';
$db = new PDO('mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME', '') . ';charset=utf8mb4',
              env('DB_USER'), env('DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$n = $db->exec("UPDATE app_preferences SET pref_value = '0' WHERE pref_key = 'sec_require_2fa_admin'");
echo "2FA for administrators: off ({$n} row updated). Administrators sign in with their password alone.\n";
